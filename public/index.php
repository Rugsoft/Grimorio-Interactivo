<?php

/**
 * index.php — Front Controller de la API REST del Grimorio Interactivo.
 *
 * Tarea 1.5 (TASKS-01): punto de entrada único del backend.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro sin frameworks; el servidor web
 *     apunta a public/ y este script despacha todo.
 *   - AGENTS.md 2.1: arquitectura MVC ligera orientada a API REST.
 *   - AGENTS.md 6.1: nunca exponer excepciones PDO ni trazas; 500 controlado.
 *   - Artículo V: identificadores en inglés, documentación en castellano.
 *
 * Estructura: el registro de rutas vive en buildRouter() para que sea
 * verificable desde CLI sin emitir cabeceras; el bloque inferior solo se
 * ejecuta cuando PHP arranca este archivo directamente (SAPI web).
 */

declare(strict_types=1);

/**
 * Bootstrap de despliegue (deploy/infinityfree/env.php): materializa el DSN
 * de la base en hostings compartidos sin variables de entorno. En el
 * sandbox de InfinityFree el `auto_prepend_file` está MONOPOLIZADO por el
 * servidor (php_admin_value hacia /var/www/errors/override.php), así que
 * el canal .htaccess/.user.ini es inert allí; este require lo sustituye.
 *
 * Guardia: en desarrollo local el fichero NO existe (vive solo en
 * deploy/ y en la copia subida a public/ del hosting), así que el require
 * se omite sin efecto. Si el hosting lo eliminara, la API seguiría
 * funcionando con el fallback de Connection.php.
 */
if (is_file(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

/**
 * Cargador de clases nativo (Artículo I: sin Composer ni autoloader externo).
 * Convención del proyecto: el prefijo Grimorio\ se mapea al directorio src/
 * (Grimorio\Core\Router -> src/Core/Router.php), con separadores aptos para
 * Windows y Unix.
 */
spl_autoload_register(static function (string $className): void {
    // Solo clases del namespace del proyecto.
    if (!str_starts_with($className, 'Grimorio\\')) {
        return;
    }

    $relativeClassPath = str_replace('\\', '/', substr($className, strlen('Grimorio\\')));
    $classFile = dirname(__DIR__) . '/src/' . $relativeClassPath . '.php';

    if (is_file($classFile)) {
        require_once $classFile;
    }
});

use Grimorio\Controllers\ClanController;
use Grimorio\Controllers\GrimoireController;
use Grimorio\Controllers\LineageController;
use Grimorio\Controllers\MasterDeliberationController;
use Grimorio\Controllers\ModerationController;
use Grimorio\Controllers\SovereignAdminController;
use Grimorio\Controllers\DominionController;
use Grimorio\Controllers\ElementalMatrixController;
use Grimorio\Services\ElementalMatrixService;
use Grimorio\Services\LineageSynergyService;
use Grimorio\Services\WeeklyDominionService;
use Grimorio\Controllers\PortalController;
use Grimorio\Controllers\SpellController;
use Grimorio\Controllers\AuthController;
use Grimorio\Controllers\AuditController;
use Grimorio\Controllers\LineageOathController;
use Grimorio\Controllers\SpellCreatorController;
use Grimorio\Controllers\VestibuleController;
use Grimorio\Controllers\UserPanelController;
use Grimorio\Repositories\UserPanelRepository;
use Grimorio\Services\AvatarService;
use Grimorio\Services\AuthService;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\Router;
use Grimorio\Core\SessionManager;
use Grimorio\Middleware\AuthMiddleware;
use Grimorio\Middleware\LineageOathMiddleware;
use Grimorio\Database\Connection;
use Grimorio\Repositories\LineageOathRepository;
use Grimorio\Repositories\ImperialDecreeRepository;
use Grimorio\Repositories\ObjectionVerdictRepository;
use Grimorio\Repositories\SpellReviewRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;
use Grimorio\Services\ClanVestibuleService;
use Grimorio\Services\ConstitutionalEthicsValidator;
use Grimorio\Services\LineageCatalogService;
use Grimorio\Services\LineageOathService;
use Grimorio\Services\GrimoireQueryService;
use Grimorio\Services\GrimoireCollectionService;
use Grimorio\Repositories\GrimoireCollectionRepository;
use Grimorio\Controllers\GrimoireCollectionController;
use Grimorio\Services\MasterDeliberationService;
use Grimorio\Services\ModerationWorkflowService;
use Grimorio\Services\SovereignAdminService;
use Grimorio\Services\SpellDiscoveryService;
use Grimorio\Services\SpellManagementService;

/**
 * Construye y registra todas las rutas de la API (plan técnico, sección 3).
 */
function buildRouter(): Router
{
    $router = new Router();

    // Cableado de dependencias mínimo (sin contenedor: Dogma Vanilla).
    $connection       = Connection::getInstance();
    $discoveryService = new SpellDiscoveryService($connection);
    $portalController = new PortalController($discoveryService);
    // El controlador del catálogo recibe también el canal de estado del
    // adepto (SPEC-11, RF-04.0, hallazgo H8): el listado embebe
    // `adeptState` para que la tarjeta nazca con su gesto del tomo.
    $spellController  = new SpellController($discoveryService, new GrimoireQueryService($connection->getPdo()));
    // Gobierno de hermandades (SPEC-07, Tareas 2.4 y 3.2): el servicio se
    // cablea con la Bitácora pública para que fundaciones, expulsiones,
    // renuncias y disoluciones queden inscritas (RNF-04).
    $clanController   = new ClanController(
        $connection,
        new ClanService($connection->getPdo(), new AuditService($connection->getPdo()), new LineageSynergyService()),
        $discoveryService,
    );

    // Vestíbulo de las Hermandades (SPEC-10, Tarea 3.4): el sobre único,
    // la retirada, el veredicto contemplado y el contador del rótulo.
    $vestibuleController = new VestibuleController(
        new ClanVestibuleService($connection->getPdo()),
        new ClanService($connection->getPdo(), new AuditService($connection->getPdo()), new LineageSynergyService()),
    );

    // Pila de autenticación (SPEC-03): gestor de sesiones y rate limiter
    // alineados sobre el PDO del front controller (Connection::getPdo()).
    $sessionManager  = new SessionManager($connection->getPdo());
    $rateLimiter     = new RateLimiter($connection->getPdo());
    $authController  = new AuthController($connection->getPdo(), $sessionManager, $rateLimiter);
    $authMiddleware  = new AuthMiddleware($connection->getPdo(), $sessionManager);

    // Taller de Hechizos (SPEC-04): el cálculo es público y sin estado
    // (simulación en vivo del creador); las rutas de borradores y
    // transiciones (Tareas 4.2/4.3) exigen sesión autenticada.
    $spellCreatorController = new SpellCreatorController(null, new SpellManagementService($connection->getPdo()));

    // Simulador de Grimorio (SPEC-05): catálogo del Tomo Arcano con
    // segmentación canónica/ensayos (el modo essays exige sesión, Tarea 1.3).
    // SPEC-11 (Tarea 3.3): el servicio de consulta ya porta la tercera vía
    // mode=collection (tomo personal enriquecido) y el estado embebido del
    // adepto (adeptState) — ambos canales viven en el mismo listado canónico.
    $grimoireController = new GrimoireController(new GrimoireQueryService($connection->getPdo()));
    $elementalMatrixController = new ElementalMatrixController(new ElementalMatrixService());

    // Salón de los Linajes (SPEC-07): el canon de los 8 Linajes es un dato
    // en memoria del servicio (Tarea 2.2), de lectura pública y sin estado.
    $lineageController = new LineageController(new LineageSynergyService());

    // Dominio Semanal (SPEC-07, Tareas 2.5 y 3.3): el Salón del Dominio y el
    // corte dominical. La clave del cron vive en GRIMORIO_CRON_SECRET (el
    // controlador la lee del entorno); sin ella, el cierre falla cerrado.
    $dominionController = new DominionController(
        new WeeklyDominionService(
            $connection->getPdo(),
            new AuditService($connection->getPdo()),
            new LineageSynergyService(),
        ),
    );

    // Cónclave de moderación (SPEC-08, Tarea 3.1): el flujo de dos pasos y el
    // Atrio de Pruebas. `ModerationWorkflowService` es la ÚNICA autoridad del
    // ciclo de vida, del cupo de tres y del letargo —se construye sobre el
    // mismo PDO y sobre el canal único de la Bitácora—; el repositorio del
    // expediente sirve, en lectura, el catálogo público del Atrio.
    $moderationController = new ModerationController(
        new ModerationWorkflowService($connection->getPdo()),
        new SpellReviewRepository($connection->getPdo()),
    );

    // Torre de Deliberación (SPEC-08, Tarea 3.2): la firma, la retractación y el
    // dictamen, más la cola con el veredicto del Artículo III ya resuelto por su
    // autoridad (`ConstitutionalEthicsValidator`, que COMPONE el veto de linaje
    // de SPEC-07 en lugar de reimplementarlo).
    $masterDeliberationController = new MasterDeliberationController(
        new MasterDeliberationService($connection->getPdo()),
        new SpellReviewRepository($connection->getPdo()),
        new ConstitutionalEthicsValidator($connection->getPdo()),
        new ObjectionVerdictRepository($connection->getPdo()),
    );

    // Cónclave Supremo y letargo arcano (SPEC-08, Tarea 3.3): los tres decretos
    // de la Firma Soberana y el barrido de caducidad. El sello del cron vive en
    // GRIMORIO_CRON_SECRET; sin él, el barrido FALLA CERRADO.
    $sovereignAdminController = new SovereignAdminController(
        new SovereignAdminService($connection->getPdo()),
        new ModerationWorkflowService($connection->getPdo()),
        new ImperialDecreeRepository($connection->getPdo(), new AuditService($connection->getPdo())),
    );

    // Bitácora de Auditoría Arcana (SPEC-03, Tarea 3.4): consulta pública y
    // paginada de los veredictos de moderación y gobierno (RF-08.2).
    $auditController = new AuditController(new AuditService($connection->getPdo()));

    // --- Rutas de la API (base /api/v1) ---
    $router->addRoute('GET', '/api/v1/portal/featured', fn (Request $request): Response => $portalController->featured($request));
    $router->addRoute('GET', '/api/v1/spells', fn (Request $request): Response => $spellController->index($request));
    // IMPORTANTE: la ruta literal de borradores se registra ANTES que el
    // patrón /spells/{slug}; registrarse después haría que elRouter la
    // sombra con slug='drafts' (404 SCROLL_LOST_IN_AETHER observado).
    $router->addRoute('GET', '/api/v1/spells/drafts', fn (Request $request): Response => $spellCreatorController->listDrafts($request));
    $router->addRoute('GET', '/api/v1/spells/{slug}', fn (Request $request, array $routeParams): Response => $spellController->show($request, $routeParams));
    $router->addRoute('GET', '/api/v1/clans/preview', fn (Request $request): Response => $clanController->preview($request));

    // --- Rutas del gobierno de hermandades (SPEC-07, plan Endpoints 1-9) ---
    // IMPORTANTE: las rutas literales (`/clans`, `/clans/preview`) se registran
    // ANTES que los patrones con parámetro; los subcaminos (`/applications`,
    // `/leave`, `/expel/{userId}`, `/transfer-leadership`) no colisionan con
    // `/clans/{id}` porque su grupo nombrado excluye la barra.
    $router->addRoute('GET', '/api/v1/clans', fn (Request $request): Response => $clanController->index($request));
    // Vestíbulo de las Hermandades (SPEC-10, Tarea 3.4): estado derivado,
    // inventario consolidado, retirada, veredicto contemplado y contador del
    // rótulo. Las rutas LITERALES (`/clans/vestibule`, `/clans/verdicts/
    // unread-count`, `/clans/applications/{appId}/verdict-acknowledge`) se
    // registran ANTES de los patrones con parámetro (`{id}`, `{appId}`) para
    // que el enrutador —que discierne por orden de registro— no las sombra.
    $router->addRoute('GET', '/api/v1/clans/vestibule', fn (Request $request): Response => $vestibuleController->show($request));
    $router->addRoute('GET', '/api/v1/clans/verdicts/unread-count', fn (Request $request): Response => $vestibuleController->unreadCount($request));
    $router->addRoute('POST', '/api/v1/clans/applications/{appId}/verdict-acknowledge', fn (Request $request, array $routeParams): Response => $vestibuleController->acknowledgeVerdict($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/applications/{appId}/withdraw', fn (Request $request, array $routeParams): Response => $vestibuleController->withdraw($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans', fn (Request $request): Response => $clanController->store($request));
    $router->addRoute('GET', '/api/v1/clans/{id}', fn (Request $request, array $routeParams): Response => $clanController->show($request, $routeParams));
    // Legado Ancestral de la casa (Tarea 6.4, plan Endpoint 13): conjuros
    // ratificados que pertenecen perpetuamente a su clan de origen, incluso
    // si la casa yace disuelta. Lectura pública.
    $router->addRoute('GET', '/api/v1/clans/{id}/spells', fn (Request $request, array $routeParams): Response => $clanController->spells($request, $routeParams));
    $router->addRoute('PATCH', '/api/v1/clans/{id}', fn (Request $request, array $routeParams): Response => $clanController->update($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/applications', fn (Request $request, array $routeParams): Response => $clanController->apply($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/applications/{appId}/resolve', fn (Request $request, array $routeParams): Response => $clanController->resolveApplication($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/leave', fn (Request $request, array $routeParams): Response => $clanController->leave($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/expel/{userId}', fn (Request $request, array $routeParams): Response => $clanController->expel($request, $routeParams));
    $router->addRoute('POST', '/api/v1/clans/{id}/transfer-leadership', fn (Request $request, array $routeParams): Response => $clanController->transferLeadership($request, $routeParams));

    // --- Rutas del Salón de los Linajes (SPEC-07, plan Endpoint 10) ---
    $router->addRoute('GET', '/api/v1/lineages', fn (Request $request): Response => $lineageController->index($request));

    // --- Rutas del Dominio Semanal (SPEC-07, plan Endpoints 11-12) ---
    // El Salón es de lectura pública; el corte dominical exige el sello del
    // custodio en la cabecera X-Arcane-Cron-Secret.
    $router->addRoute('GET', '/api/v1/dominion/leaderboard', fn (Request $request): Response => $dominionController->leaderboard($request));    $router->addRoute('POST', '/api/v1/dominion/cron-cycle-close', fn (Request $request): Response => $dominionController->closeCycle($request));
    // Endpoint 14 (SPEC-07, Tarea 7.1): la gloria que el Simulador devenga al
    // ejecutar una reacción de combo elemental (RF-03.2).
    $router->addRoute('POST', '/api/v1/dominion/simulator-combo', fn (Request $request): Response => $dominionController->awardSimulatorCombo($request));

    // --- Rutas del Simulador de Grimorio (SPEC-05, plan Endpoints 1-2) ---
    $router->addRoute('GET', '/api/v1/grimoire/spells', fn (Request $request): Response => $grimoireController->listSpells($request));
    $router->addRoute('GET', '/api/v1/grimoire/spells/{id}', fn (Request $request, array $routeParams): Response => $grimoireController->showSpell($request, $routeParams));

    // --- Rutas del Tomo Personal (SPEC-11, plan §2.2, Tarea 4.1) ---
    // La lectura, el sellado y la retirada viven en el controlador de
    // colección; la puerta del elogio (SPEC-07 intacto) en /praise. La
    // Bitácora y el Dominio comparten canal con el resto del santuario.
    $grimoireAuditService = new AuditService($connection->getPdo());
    $grimoireDominionService = new WeeklyDominionService(
        $connection->getPdo(),
        $grimoireAuditService,
        new LineageSynergyService(),
    );
    $grimoireCollectionController = new GrimoireCollectionController(
        new GrimoireCollectionService(
            new GrimoireCollectionRepository($connection->getPdo()),
            $grimoireAuditService,
            $connection->getPdo()
        ),
        new GrimoireQueryService($connection->getPdo()),
        $grimoireDominionService,
        $grimoireAuditService
    );
    $router->addRoute('GET', '/api/v1/grimoire/collection', fn (Request $request): Response => $grimoireCollectionController->listCollection($request));
    $router->addRoute('POST', '/api/v1/grimoire/collection', fn (Request $request): Response => $grimoireCollectionController->collectSpell($request));
    $router->addRoute('DELETE', '/api/v1/grimoire/collection/{spellId}', fn (Request $request, array $routeParams): Response => $grimoireCollectionController->discardSpell($request, $routeParams));
    $router->addRoute('POST', '/api/v1/grimoire/praise', fn (Request $request): Response => $grimoireCollectionController->praiseSpell($request));

    // Panel del Adepto (SPEC-12): la morada privada de la identidad. El
    // servicio de la efigie vive junto al PDO del front controller y su
    // raíz de almacenamiento respeta la disposición de storage/. El
    // custodio de la frase de paso comparte el PDO y el gestor de
    // vínculos del santuario (SPEC-03) — la sesión actual sobrevive al
    // acto porque el dueño está presente (plan §5.3).
    $avatarService = new AvatarService(
        $connection->getPdo(),
        new UserPanelRepository($connection->getPdo()),
        dirname(__DIR__) . '/storage/avatars',
    );
    $userPanelController = new UserPanelController(
        new UserPanelRepository($connection->getPdo()),
        $avatarService,
        null,
        new AuthService($connection->getPdo(), $sessionManager),
    );
    $router->addRoute('GET', '/api/v1/panel', fn (Request $request): Response => $userPanelController->show($request));
    $router->addRoute('GET', '/api/v1/panel/avatars', fn (Request $request): Response => $userPanelController->avatarCatalog($request));
    $router->addRoute('POST', '/api/v1/panel/avatar', fn (Request $request): Response => $userPanelController->chooseAvatar($request));
    $router->addRoute('DELETE', '/api/v1/panel/avatar', fn (Request $request): Response => $userPanelController->removeAvatar($request));
    $router->addRoute('POST', '/api/v1/panel/passphrase', fn (Request $request): Response => $userPanelController->changePassphrase($request));
    $router->addRoute('GET', '/api/v1/panel/ledger', fn (Request $request): Response => $userPanelController->ledger($request));

    // --- Rutas de la Matriz Elemental (SPEC-06, plan Endpoints 1-3) ---
    $router->addRoute('GET', '/api/v1/elements/matrix', fn (Request $request): Response => $elementalMatrixController->getMatrix($request));
    $router->addRoute('GET', '/api/v1/elements/reactions/{element}', fn (Request $request, array $routeParams): Response => $elementalMatrixController->getElementReactions($request, $routeParams));
    $router->addRoute('POST', '/api/v1/elements/resolve-combo', fn (Request $request): Response => $elementalMatrixController->resolveCombo($request));

    // --- Rutas del Taller de Hechizos (SPEC-04, plan Endpoints 1-3) ---
    // El cálculo es público y sin estado; el ciclo de vida de borradores
    // exige sesión autenticada (SpellManagementService sobre el PDO real).
    $router->addRoute('POST', '/api/v1/spells/calculate', fn (Request $request): Response => $spellCreatorController->calculate($request));
    $router->addRoute('POST', '/api/v1/spells/drafts', fn (Request $request): Response => $spellCreatorController->createDraft($request));
    $router->addRoute('PUT', '/api/v1/spells/drafts/{id}', fn (Request $request, array $routeParams): Response => $spellCreatorController->updateDraft($request, $routeParams));
    $router->addRoute('DELETE', '/api/v1/spells/drafts/{id}', fn (Request $request, array $routeParams): Response => $spellCreatorController->deleteDraft($request, $routeParams));
    $router->addRoute('POST', '/api/v1/spells/publish/{id}', fn (Request $request, array $routeParams): Response => $spellCreatorController->publishSpell($request, $routeParams));
    $router->addRoute('PUT', '/api/v1/spells/experimental/{id}', fn (Request $request, array $routeParams): Response => $spellCreatorController->updateExperimental($request, $routeParams));
    $router->addRoute('POST', '/api/v1/spells/variant/{id}', fn (Request $request, array $routeParams): Response => $spellCreatorController->createVariant($request, $routeParams));

    // --- Rutas del flujo de dos pasos y del Atrio (SPEC-08, plan Endpoints 1-4) ---
    // El Atrio es de lectura pública; las tres transiciones exigen vínculo
    // arcano (401) y el rango y el cupo los dicta el servicio (403/409).
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/submit', fn (Request $request, array $routeParams): Response => $moderationController->submit($request, $routeParams));
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/withdraw', fn (Request $request, array $routeParams): Response => $moderationController->withdraw($request, $routeParams));
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/reopen', fn (Request $request, array $routeParams): Response => $moderationController->reopen($request, $routeParams));
    $router->addRoute('GET', '/api/v1/moderation/experimental', fn (Request $request): Response => $moderationController->experimental($request));

    // --- Rutas de la Torre de Deliberación (SPEC-08, plan Endpoints 5-8) ---
    // La cola es exclusiva de Maestros y Administrador Supremo; las tres
    // acciones exigen el rango `master` y el veto ético lo dicta el servicio.
    $router->addRoute('GET', '/api/v1/moderation/queue', fn (Request $request): Response => $masterDeliberationController->queue($request));
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/sign', fn (Request $request, array $routeParams): Response => $masterDeliberationController->sign($request, $routeParams));
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/retract', fn (Request $request, array $routeParams): Response => $masterDeliberationController->retract($request, $routeParams));
    $router->addRoute('POST', '/api/v1/moderation/spells/{id}/object', fn (Request $request, array $routeParams): Response => $masterDeliberationController->object($request, $routeParams));

    // --- Rutas del Cónclave Supremo y del letargo (SPEC-08, plan Endpoints 9-12) ---
    // Los tres decretos exigen el rango `supremeAdmin`; el barrido de caducidad
    // lo invoca el planificador con el sello del custodio, jamas una sesion.
    $router->addRoute('POST', '/api/v1/moderation/sovereign/validate', fn (Request $request): Response => $sovereignAdminController->validate($request));
    $router->addRoute('POST', '/api/v1/moderation/sovereign/rescue', fn (Request $request): Response => $sovereignAdminController->rescue($request));
    $router->addRoute('POST', '/api/v1/moderation/sovereign/archive', fn (Request $request): Response => $sovereignAdminController->archive($request));
    $router->addRoute('POST', '/api/v1/moderation/cron-check-expiry', fn (Request $request): Response => $sovereignAdminController->cronCheckExpiry($request));

    // --- Rutas de autenticación (SPEC-03, plan 2.2, Endpoints 1-5 + renuncia RF-09.1) ---
    $router->addRoute('POST', '/api/v1/auth/consecrate', fn (Request $request): Response => $authController->consecrate($request));
    $router->addRoute('POST', '/api/v1/auth/bind', fn (Request $request): Response => $authController->bind($request));
    $router->addRoute('POST', '/api/v1/auth/dissolve', fn (Request $request): Response => $authController->dissolve($request));
    $router->addRoute('POST', '/api/v1/auth/dissolve-all', fn (Request $request): Response => $authController->dissolveAll($request));
    $router->addRoute('GET', '/api/v1/auth/session', fn (Request $request): Response => $authController->session($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/request', fn (Request $request): Response => $authController->recoveryRequest($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/reset', fn (Request $request): Response => $authController->recoveryReset($request));
    $router->addRoute('POST', '/api/v1/auth/renounce-account', fn (Request $request): Response => $authController->renounceAccount($request));

    // --- Rutas de la Bitácora de Auditoría (SPEC-03, plan Endpoint 6) ---
    // Consulta pública y paginada de decisiones solemnes, firmas, vetos y decretos.
    $router->addRoute('GET', '/api/v1/audit/log', fn (Request $request): Response => $auditController->log($request));

    // --- Rutas del Juramento de Linaje (SPEC-09, Tarea 2.6) ---
    // La ceremonia bloqueante del primer acceso: canon ceremonial, sellado
    // del juramento y registro de la ruta retenida del interceptor. La
    // retención de SUSTANCIA de las demás rutas la ejerce
    // LineageOathMiddleware antes del despacho (ver más abajo).
    $lineageOathRepository = new LineageOathRepository($connection->getPdo());
    $lineageOathController = new LineageOathController(
        new LineageCatalogService($lineageOathRepository),
        new LineageOathService($lineageOathRepository, new AuditService($connection->getPdo())),
        // La ruta retenida se consume de la FILA del vínculo (SPEC-09,
        // enmienda de la Tarea 9.2 de SPEC-11).
        new SessionManager($connection->getPdo()),
    );
    $router->addRoute('GET', '/api/v1/lineage/oath-catalog', fn (Request $request): Response => $lineageOathController->oathCatalog($request));
    $router->addRoute('POST', '/api/v1/lineage/oath', fn (Request $request): Response => $lineageOathController->sealOath($request));
    $router->addRoute('POST', '/api/v1/lineage/retained-route', fn (Request $request): Response => $lineageOathController->retainRoute($request));

    return $router;
}

// --- Servido de estáticos bajo el servidor nativo (php -S) ---
// El router script intercepta TODAS las peticiones; si el recurso existe
// como archivo estático de public/ (main.js, CSS, imágenes), se le cede
// al servidor para que lo sirva con su Content-Type nativo. El flujo SPA
// de la Tarea 6.1 depende de esto. Con Apache/Nginx esta guarda no aplica.
if (PHP_SAPI === 'cli-server') {
    $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $staticFile = __DIR__ . $requestPath;

    // La raíz '/' entrega el shell de la SPA. IMPORTANTE: se devuelve true
    // (respuesta ya servida por este script). Con false el servidor nativo
    // intentaría resolver '/' por su cuenta y recargaría index.php,
    // redeclarando buildRouter() (fatal error observado en php -S).
    if ($requestPath === '/' && is_file(__DIR__ . '/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        return true;
    }

    if (is_file($staticFile)) {
        return false;
    }
}

// --- Ejecución solo bajo SAPI web (CLI carga el archivo para verificar buildRouter) ---
if (PHP_SAPI !== 'cli') {
    try {
        $request = Request::fromGlobals();
        // Resolución del vínculo activo (SPEC-03): la cookie de sesión se
        // materializa en User antes del despacho; sin esto toda petición
        // HTTP llegaba anónima y los endpoints protegidos daban 401.
        $authMiddleware = new AuthMiddleware(Connection::getInstance()->getPdo(), new SessionManager(Connection::getInstance()->getPdo()));
        $authMiddleware->injectContext($request);

        // Cadena de protección del portal (SPEC-09, Tarea 2.6):
        // AuthMiddleware → LineageOathMiddleware. La retención de
        // sustancia deniega al peregrino toda ruta de gestión con
        // 403 LINEAGE_OATH_REQUIRED ANTES del despacho, reteniendo su
        // ruta solicitada en la sesión (RF-01.3, RF-05.1, RF-05.3).
        // El RbacMiddleware por-ruta sigue actuando dentro del despacho
        // para la jerarquía fina (SPEC-03, RF-05).
        $lineageOathMiddleware = new LineageOathMiddleware(
            new LineageOathRepository(Connection::getInstance()->getPdo()),
            // La retención se persiste en el vínculo activo (SPEC-09,
            // enmienda de la Tarea 9.2 de SPEC-11): sobrevive a la petición.
            new SessionManager(Connection::getInstance()->getPdo())
        );
        $oathGuardResponse = $lineageOathMiddleware->guard($request);
        if ($oathGuardResponse !== null) {
            $oathGuardResponse->send();
            return;
        }

        $router  = buildRouter();
        $response = $router->dispatch($request);
        $response->send();
    } catch (Throwable $unexpectedError) {
        // Bitácora del servidor: el detalle técnico queda para los custodios,
        // jamás viaja al cliente (AGENTS.md 6.1: sin trazas en producción).
        error_log('[Grimorio] Excepción no controlada: ' . $unexpectedError);

        // Última muralla: 500 controlado, sin trazas ni detalles internos.
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo (string) json_encode([
            'success' => false,
            'error'   => [
                'code'           => 'MANA_STREAM_INTERRUPTED',
                'message'        => 'La corriente de maná se ha interrumpido. Los custodios fueron avisados.',
                'recoveryAction' => 'RETRY',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}

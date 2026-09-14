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
use Grimorio\Controllers\DominionController;
use Grimorio\Controllers\ElementalMatrixController;
use Grimorio\Services\ElementalMatrixService;
use Grimorio\Services\LineageSynergyService;
use Grimorio\Services\WeeklyDominionService;
use Grimorio\Controllers\PortalController;
use Grimorio\Controllers\SpellController;
use Grimorio\Controllers\AuthController;
use Grimorio\Controllers\SpellCreatorController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\Router;
use Grimorio\Core\SessionManager;
use Grimorio\Middleware\AuthMiddleware;
use Grimorio\Database\Connection;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;
use Grimorio\Services\GrimoireQueryService;
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
    $spellController  = new SpellController($discoveryService);
    // Gobierno de hermandades (SPEC-07, Tareas 2.4 y 3.2): el servicio se
    // cablea con la Bitácora pública para que fundaciones, expulsiones,
    // renuncias y disoluciones queden inscritas (RNF-04).
    $clanController   = new ClanController(
        $connection,
        new ClanService($connection->getPdo(), new AuditService($connection->getPdo()), new LineageSynergyService()),
        $discoveryService,
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
    $router->addRoute('GET', '/api/v1/dominion/leaderboard', fn (Request $request): Response => $dominionController->leaderboard($request));
    $router->addRoute('POST', '/api/v1/dominion/cron-cycle-close', fn (Request $request): Response => $dominionController->closeCycle($request));

    // --- Rutas del Simulador de Grimorio (SPEC-05, plan Endpoints 1-2) ---
    $router->addRoute('GET', '/api/v1/grimoire/spells', fn (Request $request): Response => $grimoireController->listSpells($request));
    $router->addRoute('GET', '/api/v1/grimoire/spells/{id}', fn (Request $request, array $routeParams): Response => $grimoireController->showSpell($request, $routeParams));

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

    // --- Rutas de autenticación (SPEC-03, plan 2.2, Endpoints 1-5 + renuncia RF-09.1) ---
    $router->addRoute('POST', '/api/v1/auth/consecrate', fn (Request $request): Response => $authController->consecrate($request));
    $router->addRoute('POST', '/api/v1/auth/bind', fn (Request $request): Response => $authController->bind($request));
    $router->addRoute('POST', '/api/v1/auth/dissolve', fn (Request $request): Response => $authController->dissolve($request));
    $router->addRoute('POST', '/api/v1/auth/dissolve-all', fn (Request $request): Response => $authController->dissolveAll($request));
    $router->addRoute('GET', '/api/v1/auth/session', fn (Request $request): Response => $authController->session($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/request', fn (Request $request): Response => $authController->recoveryRequest($request));
    $router->addRoute('POST', '/api/v1/auth/recovery/reset', fn (Request $request): Response => $authController->recoveryReset($request));
    $router->addRoute('POST', '/api/v1/auth/renounce-account', fn (Request $request): Response => $authController->renounceAccount($request));

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

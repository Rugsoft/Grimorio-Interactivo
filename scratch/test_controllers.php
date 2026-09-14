<?php

/**
 * Script de verificación de la TAREA 1.5 — Controladores REST y Front Controller.
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación que verifica.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Las peticiones HTTP a /api/v1/portal/featured, /api/v1/spells,
 *   /api/v1/spells/{slug} y /api/v1/clans/preview devuelven las estructuras
 *   JSON estipuladas en el plan técnico.
 *
 * Dos niveles de verificación:
 *   A) Componente: los controladores se invocan directamente con Request tipadas.
 *   B) E2E HTTP real: se lanza el servidor nativo `php -S` con public/index.php
 *      (lanzado aparte por el orquestador; este script se centra en el nivel A).
 *
 * Uso: php scratch/test_controllers.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Router.php';
require_once __DIR__ . '/../src/Database/Connection.php';
require_once __DIR__ . '/../src/Models/Spell.php';
require_once __DIR__ . '/../src/Services/SpellDiscoveryService.php';
require_once __DIR__ . '/../src/Controllers/PortalController.php';
require_once __DIR__ . '/../src/Controllers/SpellController.php';
require_once __DIR__ . '/../src/Controllers/ClanController.php';
// Autoload nativo del proyecto: el gobierno de hermandades (Tarea 3.2 de
// TASKS-07) arrastra repositorios y servicios propios que no se enumeran aquí.
require_once __DIR__ . '/../public/index.php';

use Grimorio\Controllers\ClanController;
use Grimorio\Controllers\PortalController;
use Grimorio\Controllers\SpellController;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\Router;
use Grimorio\Database\Connection;
use Grimorio\Services\ClanService;
use Grimorio\Services\SpellDiscoveryService;

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}\n";
    }
}

/**
 * Decodifica el cuerpo de una Response y retorna [payload, assertionOk].
 */
function decodeResponse(Response $response): array
{
    $payload = json_decode($response->getBody(), true);

    return [is_array($payload) ? $payload : null, is_array($payload)];
}

// --- Preparación: base en memoria con génesis + 1 validado de usuario ---
Connection::resetInstance();
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
$pdo = Connection::getInstance()->getPdo();
// El Singleton ya auto-materializa schema.sql + seeds.sql en SQLite de
// desarrollo: solo se siembra manualmente si el plano está vacío.
$bootstrapReady = (int) $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spells'"
)->fetchColumn();
if ($bootstrapReady === 0) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));
}
$pdo->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, circle, math_fingerprint, clan_id, summary, description,
                         components_verbal, components_somatic, components_material,
                         damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                         has_verbal, has_somatic, has_material,
                         status, validation_signatures_count, signatures_count, is_genesis_sample, created_at, updated_at, validated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'spl_user_1', 'llamas-de-frieren', 'Llamas de Frieren', 'usr_custodio_primordial', 'evocation', 45, 4,
    str_repeat('f', 64), 'cln_primordial',
    'Proyecta una ráfaga continua de fuego purificador.',
    'Concentra el maná ambiental en la palma del lanzador.',
    'Ignis Caelestis Dissolvens', 'Palma extendida', 'Ceniza de sauce',
    30, 0, 0, 'none', 'medium', 'sphere', 'instant',
    1, 1, 0,
    'validated', 3, 3, 0, '2026-09-01T00:00:00Z', '2026-09-10T14:30:00Z', '2026-09-10T14:30:00Z',
]);

$discoveryService = new SpellDiscoveryService(Connection::getInstance());
$portalController = new PortalController($discoveryService);
$spellController  = new SpellController($discoveryService);
$clanController   = new ClanController(
    Connection::getInstance(),
    new ClanService(Connection::getInstance()->getPdo()),
    $discoveryService,
);

echo "== VERIFICACION TAREA 1.5: Controladores REST y Front Controller ==\n\n";

// --- FASE 1: GET /api/v1/portal/featured (RF-01) ---
echo "FASE 1: PortalController::featured()\n";
$featuredResponse = $portalController->featured(new Request('GET', '/api/v1/portal/featured'));
[$featuredPayload, $featuredIsJson] = decodeResponse($featuredResponse);

assertCondition($featuredResponse->getStatusCode() === 200, "GET /portal/featured responde 200 OK");
assertCondition($featuredIsJson, "El cuerpo es JSON válido decodificable");
assertCondition(
    ($featuredPayload['success'] ?? null) === true && is_array($featuredPayload['data'] ?? null),
    "Estructura de sobre estándar { success, data }"
);
assertCondition(count($featuredPayload['data'] ?? []) === 3, "La galería contiene exactamente 3 destacados");

$firstFeatured = $featuredPayload['data'][0] ?? [];
$featuredContractKeys = ['id', 'slug', 'name', 'magicSchool', 'magicSchoolLabel', 'manaCost', 'clanId', 'clanName', 'summary', 'status', 'isGenesisSample', 'validatedAt'];
$missingKeys = array_diff($featuredContractKeys, array_keys($firstFeatured));
assertCondition($missingKeys === [], "Cada destacado porta el contrato SpellSummaryDto del plan 2.1 (camelCase) — faltan: " . implode(',', $missingKeys));

// --- FASE 2: GET /api/v1/spells (RF-03) ---
echo "\nFASE 2: SpellController::index()\n";
$catalogResponse = $spellController->index(new Request('GET', '/api/v1/spells', ['limit' => '2']));
[$catalogPayload, $catalogIsJson] = decodeResponse($catalogResponse);

assertCondition($catalogResponse->getStatusCode() === 200, "GET /spells responde 200 OK");
assertCondition($catalogIsJson, "El cuerpo del catálogo es JSON válido");
assertCondition(
    is_array($catalogPayload['data']['items'] ?? null) && isset($catalogPayload['data']['hasMore']),
    "Contrato de colección paginada { items, hasMore } (RF-03.7)"
);
assertCondition(count($catalogPayload['data']['items'] ?? []) === 2, "El parámetro limit=2 se aplica desde el query string");

// Filtros del query string acumulativos (RF-03.3/3.5/3.6).
$filteredResponse = $spellController->index(new Request('GET', '/api/v1/spells', ['query' => 'ignicion', 'maxMana' => '20']));
[$filteredPayload] = decodeResponse($filteredResponse);
$filteredSlugs = array_map(static fn (array $item): string => $item['slug'], $filteredPayload['data']['items'] ?? []);
assertCondition(
    $filteredSlugs === ['chispa-de-ignicion'],
    "Filtro combinado query='ignicion' + maxMana=20 retorna solo la Chispa (búsqueda sin tilde)"
);

// Parámetros inválidos → 400 Bad Request con contrato de error místico.
$invalidManaResponse = $spellController->index(new Request('GET', '/api/v1/spells', ['maxMana' => 'mucho']));
assertCondition($invalidManaResponse->getStatusCode() === 400, "maxMana no numérico produce 400 Bad Request");
[$invalidManaPayload, $invalidManaIsJson] = decodeResponse($invalidManaResponse);
assertCondition(
    $invalidManaIsJson && ($invalidManaPayload['success'] ?? true) === false && isset($invalidManaPayload['error']['code'], $invalidManaPayload['error']['message']),
    "El 400 usa el contrato de error { success: false, error: { code, message } }"
);

$negativeOffsetResponse = $spellController->index(new Request('GET', '/api/v1/spells', ['offset' => '-5']));
assertCondition($negativeOffsetResponse->getStatusCode() === 400, "offset negativo produce 400 Bad Request");

// Truncado de búsqueda a 100 caracteres (RF-03.4) sin fallo.
$longQuery = str_repeat('a', 250);
$longQueryResponse = $spellController->index(new Request('GET', '/api/v1/spells', ['query' => $longQuery]));
assertCondition($longQueryResponse->getStatusCode() === 200, "Query de 250 caracteres se trunca a 100 sin error (RF-03.4)");

// --- FASE 3: GET /api/v1/spells/{slug} (RF-04, RF-06.2) ---
echo "\nFASE 3: SpellController::show()\n";
$detailResponse = $spellController->show(new Request('GET', '/api/v1/spells/llamas-de-frieren'), ['slug' => 'llamas-de-frieren']);
[$detailPayload, $detailIsJson] = decodeResponse($detailResponse);

assertCondition($detailResponse->getStatusCode() === 200, "GET /spells/{slug} existente responde 200 OK");
assertCondition($detailIsJson && ($detailPayload['success'] ?? null) === true, "El cuerpo de la ficha es JSON válido");
$detailContractKeys = array_merge($featuredContractKeys, ['description', 'components', 'validationSignaturesCount']);
$missingDetailKeys = array_diff($detailContractKeys, array_keys($detailPayload['data'] ?? []));
assertCondition($missingDetailKeys === [], "La ficha porta el contrato SpellDetailDto del plan 2.2 — faltan: " . implode(',', $missingDetailKeys));
assertCondition(
    (($detailPayload['data']['components']['verbal'] ?? '') !== ''),
    "Los componentes arcanos viajan dentro de la ficha"
);

// Pergamino desterrado → 404 con contrato místico completo del plan 2.4.
$lostResponse = $spellController->show(new Request('GET', '/api/v1/spells/pergamino-inexistente'), ['slug' => 'pergamino-inexistente']);
assertCondition($lostResponse->getStatusCode() === 404, "GET /spells/{slug} inexistente responde 404 Not Found");
[$lostPayload, $lostIsJson] = decodeResponse($lostResponse);
assertCondition(
    $lostIsJson
    && ($lostPayload['error']['code'] ?? '') === 'SCROLL_LOST_IN_AETHER'
    && ($lostPayload['error']['recoveryAction'] ?? '') === 'RETURN_TO_LIBRARY',
    "El 404 usa el contrato SCROLL_LOST_IN_AETHER + recoveryAction del plan 2.4"
);

// --- FASE 4: GET /api/v1/clans/preview (RF-02.2) ---
echo "\nFASE 4: ClanController::preview()\n";

// Un solo contador de gloria (Tarea 2.6, TASKS-07): `domainPoints` se sirve
// del contador semanal canónico de SPEC-07. Se acredita gloria real para
// comprobar que el contrato transporta VALORES, no meras claves.
$pdo->exec("UPDATE clans SET weekly_points = 240 WHERE id = 'cln_primordial'");

$clansResponse = $clanController->preview(new Request('GET', '/api/v1/clans/preview'));
[$clansPayload, $clansIsJson] = decodeResponse($clansResponse);

assertCondition($clansResponse->getStatusCode() === 200, "GET /clans/preview responde 200 OK (lectura pública sin sesión)");
assertCondition($clansIsJson && is_array($clansPayload['data'] ?? null), "El cuerpo de linajes es JSON válido");
$firstClan = $clansPayload['data'][0] ?? [];
assertCondition(
    isset($firstClan['id'], $firstClan['slug'], $firstClan['name'], $firstClan['domainPoints']),
    "Cada linaje porta { id, slug, name, domainPoints } en camelCase (Salón de Linajes)"
);
assertCondition(
    ($firstClan['domainPoints'] ?? null) === 240,
    "domainPoints transporta el valor del contador semanal canónico (240 PDA)"
);

// --- FASE 5: Front Controller — registro y despacho de las 4 rutas ---
echo "\nFASE 5: public/index.php (Front Controller)\n";
$indexPath = __DIR__ . '/../public/index.php';
assertCondition(file_exists($indexPath), "Existe public/index.php como punto de entrada único");

// El Front Controller debe exponer buildRouter() para verificar el registro sin emitir cabeceras en CLI.
if (file_exists($indexPath) && function_exists('buildRouter') === false) {
    // Carga protegida: el front controller solo ejecuta el despacho si se le ordena.
    require_once $indexPath;
}
assertCondition(function_exists('buildRouter'), "buildRouter() registra las rutas de la API (verificable en CLI)");

if (function_exists('buildRouter')) {
    $router = buildRouter();
    assertCondition($router instanceof Router, "buildRouter() retorna el Router del núcleo");

    $routedFeatured = $router->dispatch(new Request('GET', '/api/v1/portal/featured'));
    assertCondition($routedFeatured->getStatusCode() === 200, "El router despacha /portal/featured al controlador (200)");

    $routedLost = $router->dispatch(new Request('GET', '/api/v1/spells/no-existe'));
    assertCondition($routedLost->getStatusCode() === 404, "El router despacha /spells/{slug} desconocido al 404 místico");

    $routedNotFoundRoute = $router->dispatch(new Request('GET', '/api/v1/otra-cosa'));
    assertCondition($routedNotFoundRoute->getStatusCode() === 404, "Cualquier otra ruta cae en el 404 del router");
}

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.5 cumple su criterio 'Hecho cuando' (nivel componente).\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

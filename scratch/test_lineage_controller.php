<?php

/**
 * test_lineage_controller.php — Arnés TDD de la Tarea 3.1 (TASKS-07).
 *
 * Verifica el LineageController por HTTP real (despacho por el Router nativo
 * del proyecto), sin base de datos: el canon de los ocho Linajes es un dato
 * en memoria del servicio (Tarea 2.2).
 *
 *   [1] GET /api/v1/lineages → 200, Content-Type JSON, sobre {success,
 *       data, count} y EXACTAMENTE 8 fichas canónicas.
 *   [2] Las ocho claves canónicas en el orden del canon (RF-02.1), cada una
 *       con su título ceremonial en castellano y su afinidad rectora.
 *   [3] Heráldica íntegra por linaje (RF-02.2): glifo rúnico, estandarte
 *       #rrggbb y marco heráldico propios, todos distintos entre sí.
 *   [4] Neutralidad de la forja (RF-02.3 / Artículo II): ninguna ficha
 *       porta magnitud de maná, coste o descuento alguna.
 *   [5] Verbos y rutas ajenas: POST → 405, ruta desconocida → 404.
 *   [6] La ruta REAL registrada en public/index.php (buildRouter) despacha
 *       el Endpoint 10 con los 8 linajes del canon.
 *   [7] Cero advertencias de PHP y cero dependencias externas.
 *
 * Criterio «Hecho cuando» (Tarea 3.1): la petición GET /api/v1/lineages
 * responde con HTTP 200 y una lista JSON de exactamente 8 linajes canónicos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router nativos; cero
 *     librerías y cero empaquetadores.
 *   - Artículo V: identificadores camelCase; leyendas en noble castellano.
 *
 * Uso: php scratch/test_lineage_controller.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/Dto/LineageDto.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Router.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Controllers/LineageController.php';

use Grimorio\Controllers\LineageController;
use Grimorio\Core\Request;
use Grimorio\Core\Router;
use Grimorio\Services\LineageSynergyService;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var list<string> */
$warnings = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "{$message} (en {$file}:{$line})";
    return true;
});

function assert_truthy(bool $condition, string $label): void
{
    global $assertionsPassed, $assertionsFailed, $failures;
    if ($condition) {
        $assertionsPassed++;
        echo "  [OK]  {$label}\n";
        return;
    }
    $assertionsFailed++;
    $failures[] = $label;
    echo "  [FALLA] {$label}\n";
}

/**
 * Despacha una petición por el Router real del proyecto.
 *
 * @param array<string, string> $queryParams
 * @param array<string, string> $headers
 */
function dispatch(Router $router, string $method, string $uri, array $queryParams = [], array $headers = []): object
{
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $request = new Request($method, $path, $queryParams, $headers);

    return $router->dispatch($request);
}

/** Sobre JSON decodificado del cuerpo de una Response. */
function decodePayload(object $response): array
{
    $decoded = json_decode($response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
}

/** El canon esperado de la spec, en su orden litúrgico (RF-02.1). */
const CANONICAL_LINEAGES = [
    ['primordialFlame', 'Linaje de la Llama Primordial', 'fire'],
    ['celestialTides', 'Linaje de las Mareas Celestiales', 'water'],
    ['eternalTempest', 'Linaje de la Tempestad Eterna', 'lightning'],
    ['worldRoots', 'Linaje de las Raíces del Mundo', 'earth'],
    ['dawnWinds', 'Linaje de los Vientos del Alba', 'wind'],
    ['solarCrown', 'Linaje de la Corona Solar', 'light'],
    ['abyssalShadows', 'Linaje de las Sombras Abisales', 'darkness'],
    ['aetherWeavers', 'Linaje de los Tejedores del Éter', 'pureArcane'],
];

echo "== ARNÉS TDD — CONTROLADOR REST DE LOS LINAJES CANÓNICOS (Tarea 3.1, SPEC-07) ==\n";

// --- Router real con la ruta del contrato (fase roja: la clase no existe) ---
$router = new Router();
$lineageController = new LineageController(new LineageSynergyService());
$router->addRoute('GET', '/api/v1/lineages', fn ($request) => $lineageController->index($request));

// ---------------------------------------------------------------------------
echo "\n[FASE 1] GET /api/v1/lineages — el sobre del Endpoint 10\n";
// ---------------------------------------------------------------------------

$response = dispatch($router, 'GET', '/api/v1/lineages');
assert_truthy($response->getStatusCode() === 200, 'GET /api/v1/lineages responde 200 (fase roja si la clase no existe)');
assert_truthy(
    str_contains((string) $response->getHeader('Content-Type'), 'application/json'),
    'El Content-Type es application/json; charset=utf-8',
);

$payload = decodePayload($response);
assert_truthy(($payload['success'] ?? null) === true, 'El sobre porta success: true');
assert_truthy(
    isset($payload['data']) && is_array($payload['data']) && count($payload['data']) === 8,
    'El canon expone EXACTAMENTE 8 linajes canónicos',
);
assert_truthy(($payload['count'] ?? null) === 8, 'El sobre declara count: 8');
assert_truthy(
    (bool) array_is_list($payload['data']),
    'data es una lista JSON (array), no un objeto asociativo',
);

// ---------------------------------------------------------------------------
echo "\n[FASE 2] Las ocho claves canónicas y su título ceremonial (RF-02.1)\n";
// ---------------------------------------------------------------------------

$data = $payload['data'];
$ids = array_map(static fn (array $lineage): string => (string) ($lineage['id'] ?? ''), $data);
assert_truthy(
    $ids === array_column(CANONICAL_LINEAGES, 0),
    'Las 8 claves canónicas viajan en inglés camelCase y en el orden del canon',
);
assert_truthy(count(array_unique($ids)) === 8, 'Ninguna clave canónica se repite');

foreach (CANONICAL_LINEAGES as $index => [$expectedId, $expectedName, $expectedElement]) {
    $lineage = $data[$index] ?? [];
    assert_truthy(
        ($lineage['name'] ?? '') === $expectedName,
        "El linaje «{$expectedId}» porta su título ceremonial en castellano: {$expectedName}",
    );
    assert_truthy(
        ($lineage['rulingElement'] ?? '') === $expectedElement,
        "El linaje «{$expectedId}» declara su afinidad rectora «{$expectedElement}»",
    );
}

// ---------------------------------------------------------------------------
echo "\n[FASE 3] Heráldica distintiva de cada estandarte (RF-02.2)\n";
// ---------------------------------------------------------------------------

$glyphs = [];
$banners = [];
$frames = [];
foreach ($data as $lineage) {
    $lineageId = (string) ($lineage['id'] ?? '');
    $glyph = (string) ($lineage['glyph'] ?? '');
    $banner = (string) ($lineage['bannerColor'] ?? '');
    $frame = (string) ($lineage['heraldicFrame'] ?? '');

    $glyphs[] = $glyph;
    $banners[] = $banner;
    $frames[] = $frame;

    assert_truthy($glyph !== '', "El linaje «{$lineageId}» porta glifo rúnico ancestral");
    assert_truthy(
        preg_match('/^#[0-9a-f]{6}$/', $banner) === 1,
        "El estandarte de «{$lineageId}» se declara en notación heráldica #rrggbb",
    );
    assert_truthy($frame !== '', "El linaje «{$lineageId}» porta marco heráldico distintivo");
    assert_truthy(
        trim((string) ($lineage['description'] ?? '')) !== '',
        "El linaje «{$lineageId}» porta su prosa mitológica en castellano (RNF-03)",
    );
}

assert_truthy(count(array_unique($glyphs)) === 8, 'Los 8 glifos rúnicos son distintos entre sí (RF-02.2)');
assert_truthy(count(array_unique($banners)) === 8, 'Los 8 colores de estandarte son distintos entre sí (RF-02.2)');
assert_truthy(count(array_unique($frames)) === 8, 'Los 8 marcos heráldicos son distintos entre sí (RF-02.2)');

// ---------------------------------------------------------------------------
echo "\n[FASE 4] Neutralidad de la forja — la ficha jamás porta maná (RF-02.3 / Art. II)\n";
// ---------------------------------------------------------------------------

$forbiddenFragments = ['mana', 'cost', 'discount', 'surcharge', 'price'];
foreach ($data as $lineage) {
    $lineageId = (string) ($lineage['id'] ?? '');
    $offendingKeys = [];
    foreach (array_keys($lineage) as $key) {
        $loweredKey = strtolower((string) $key);
        foreach ($forbiddenFragments as $fragment) {
            if (str_contains($loweredKey, $fragment)) {
                $offendingKeys[] = (string) $key;
            }
        }
    }

    assert_truthy(
        $offendingKeys === [],
        "La ficha de «{$lineageId}» no porta clave alguna de maná, coste ni descuento (Artículo II)",
    );
}

$canonicalKeys = ['id', 'name', 'rulingElement', 'glyph', 'bannerColor', 'heraldicFrame', 'description'];
assert_truthy(
    array_keys($data[0] ?? []) === $canonicalKeys,
    'La ficha declara EXACTAMENTE las 7 claves del contrato del plan (sin campos de maná)',
);

// ---------------------------------------------------------------------------
echo "\n[FASE 5] Lectura pública: verbos ajenos y rutas desconocidas\n";
// ---------------------------------------------------------------------------

$wrongVerb = dispatch($router, 'POST', '/api/v1/lineages');
assert_truthy($wrongVerb->getStatusCode() === 405, 'POST sobre /api/v1/lineages responde 405 Method Not Allowed');

$unknownRoute = dispatch($router, 'GET', '/api/v1/lineage');
assert_truthy($unknownRoute->getStatusCode() === 404, 'Una ruta desconocida responde 404');

$withQuery = dispatch($router, 'GET', '/api/v1/lineages?filter=fire');
assert_truthy(
    $withQuery->getStatusCode() === 200 && count(decodePayload($withQuery)['data'] ?? []) === 8,
    'Un parámetro de consulta ajeno no altera el canon: la lista sigue siendo de 8',
);

// ---------------------------------------------------------------------------
echo "\n[FASE 6] La ruta REAL del Front Controller despacha el Endpoint 10\n";
// ---------------------------------------------------------------------------

// Se carga el punto de entrada único del backend: en SAPI CLI no despacha,
// solo define buildRouter() — así se verifica el REGISTRO real de la ruta y
// no únicamente un router de conveniencia montado por este arnés.
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/public/index.php';
assert_truthy(function_exists('buildRouter'), 'El Front Controller expone buildRouter() para su verificación en CLI');

$frontRouter = buildRouter();
$routedResponse = $frontRouter->dispatch(new Request('GET', '/api/v1/lineages'));
$routedPayload = decodePayload($routedResponse);
assert_truthy(
    $routedResponse->getStatusCode() === 200,
    'GET /api/v1/lineages registrado en public/index.php responde 200',
);
assert_truthy(
    count($routedPayload['data'] ?? []) === 8,
    'La ruta real sirve los 8 linajes canónicos (criterio «Hecho cuando») sin base de datos',
);
assert_truthy(
    array_column($routedPayload['data'] ?? [], 'id') === array_column(CANONICAL_LINEAGES, 0),
    'El canon servido por la ruta real coincide, clave a clave, con el de la spec',
);

// ---------------------------------------------------------------------------
echo "\n[FASE 7] Dogma Vanilla y cero advertencias\n";
// ---------------------------------------------------------------------------

foreach ([
    'LineageController' => __DIR__ . '/../src/Controllers/LineageController.php',
    'LineageSynergyService' => __DIR__ . '/../src/Services/LineageSynergyService.php',
] as $classLabel => $filePath) {
    $source = (string) file_get_contents($filePath);
    $usesExternalAutoload = str_contains($source, 'vendor/autoload')
        || str_contains($source, 'Composer')
        || str_contains($source, 'node_modules');
    assert_truthy(!$usesExternalAutoload, "{$classLabel} no invoca dependencia externa alguna (Artículo I)");
}

$reflection = new ReflectionClass(LineageController::class);
assert_truthy(
    $reflection->getParentClass() === false,
    'El LineageController no hereda de framework HTTP alguno',
);
assert_truthy($reflection->isFinal(), 'El LineageController es final (frontera cerrada del canon)');

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/LineageController.php');
$declareOffset = (int) array_search('declare(strict_types=1);', file(__DIR__ . '/../src/Controllers/LineageController.php') ?: [], true);
assert_truthy($declareOffset <= 39, 'declare(strict_types=1); vive en las primeras 40 líneas del fichero');

assert_truthy($warnings === [], 'Cero advertencias de PHP en toda la batería');
if ($warnings !== []) {
    foreach ($warnings as $warning) {
        echo "    ⚠ {$warning}\n";
    }
}

// ---------------------------------------------------------------------------
echo "\n════════════════════════════════════════════════════════════════════\n";
echo "  Asertos superados: {$assertionsPassed} · fallidos: {$assertionsFailed}\n";
if ($assertionsFailed > 0) {
    echo "  Fallos:\n";
    foreach ($failures as $failure) {
        echo "    - {$failure}\n";
    }
    echo "\n  RESULTADO: DENEGADO — la Tarea 3.1 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "  RESULTADO: EXITO — La Tarea 3.1 cumple su criterio 'Hecho cuando'.\n";
exit(0);

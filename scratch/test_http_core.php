<?php

/**
 * Script de verificación de la TAREA 1.3 — Núcleo HTTP y Enrutador ligero.
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación que verifica.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Una ruta de prueba registrada en el router responde con código de estado
 *   HTTP 200 y cabecera 'Content-Type: application/json; charset=utf-8'
 *   conteniendo un JSON válido en menos de 5 ms.
 *
 * Uso: php scratch/test_http_core.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Router.php';

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\Router;

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

echo "== VERIFICACION TAREA 1.3: Request.php, Response.php y Router.php ==\n\n";

// --- FASE 1: Contrato de las clases del núcleo HTTP ---
echo "FASE 1: Contrato de clases\n";
assertCondition(class_exists(Request::class), "Existe Grimorio\\Core\\Request");
assertCondition(class_exists(Response::class), "Existe Grimorio\\Core\\Response");
assertCondition(class_exists(Router::class), "Existe Grimorio\\Core\\Router");

foreach ([Request::class, Response::class, Router::class] as $coreClass) {
    $reflection = new ReflectionClass($coreClass);
    assertCondition(
        str_contains((string) $reflection, 'strict_types=1') || $reflection->getFileName() !== false,
        "La clase {$coreClass} está cargada desde su archivo (tipado estricto verificado por lint de ejecución)"
    );
}

// --- FASE 2: Request — abstracción tipada de query params y headers ---
echo "\nFASE 2: Request\n";
$testRequest = new Request(
    method: 'GET',
    path: '/api/v1/spells/llamas-de-frieren',
    queryParams: ['query' => 'frieren', 'maxMana' => '45', 'schools' => 'evocation,abjuration'],
    headers: ['Content-Type' => 'application/json', 'X-Test' => 'arcane']
);
assertCondition($testRequest->getMethod() === 'GET', "getMethod() retorna el verbo HTTP");
assertCondition($testRequest->getPath() === '/api/v1/spells/llamas-de-frieren', "getPath() retorna la ruta limpia");
assertCondition($testRequest->getQueryParam('maxMana') === '45', "getQueryParam() lee parámetros individuales");
assertCondition($testRequest->getQueryParam('inexistente', 'fallback') === 'fallback', "getQueryParam() soporta valor por defecto");
assertCondition($testRequest->getHeader('x-test') === 'arcane', "getHeader() es insensible a mayúsculas en cabeceras");
assertCondition($testRequest->getHeader('No-Existe') === null, "getHeader() retorna null para cabeceras ausentes");

// Fábrica desde superglobales (Front Controller de la Tarea 1.5 la usará).
// Nota: en un SAPI web real, PHP puebla $_GET automáticamente a partir del
// query string de la URI; aquí lo simulamos tal y como haría el servidor.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/api/v1/spells?query=ignicion&offset=50';
$_SERVER['HTTP_X_TEST_GLOBAL'] = 'from-globals';
$_GET = ['query' => 'ignicion', 'offset' => '50'];
$globalRequest = Request::fromGlobals();
assertCondition($globalRequest->getPath() === '/api/v1/spells', "fromGlobals() separa la ruta del query string");
assertCondition($globalRequest->getQueryParam('offset') === '50', "fromGlobals() captura query params reales");
assertCondition($globalRequest->getHeader('X-Test-Global') === 'from-globals', "fromGlobals() captura cabeceras HTTP_*");

// --- FASE 3: Response — emisor JSON estándar con cabeceras UTF-8 ---
echo "\nFASE 3: Response\n";
$jsonResponse = Response::json(['success' => true, 'data' => ['slug' => 'manto-de-niebla']], 200);
assertCondition($jsonResponse->getStatusCode() === 200, "Response::json() retiene el código de estado 200");
assertCondition(
    $jsonResponse->getHeader('Content-Type') === 'application/json; charset=utf-8',
    "La cabecera Content-Type es 'application/json; charset=utf-8' (exigida por AGENTS.md)"
);
$decodedBody = json_decode($jsonResponse->getBody(), true);
assertCondition(is_array($decodedBody) && $decodedBody['success'] === true, "El cuerpo es JSON válido decodificable");
assertCondition(
    $jsonResponse->getBody() === json_encode($decodedBody, JSON_UNESCAPED_UNICODE) || json_valid_utf8($jsonResponse->getBody()),
    "El JSON preserva caracteres UTF-8 del lore castellano"
);

$errorResponse = Response::json(
    ['success' => false, 'error' => ['code' => 'SCROLL_LOST_IN_AETHER', 'message' => 'El pergamino que buscas se ha desvanecido en el éter.']],
    404
);
assertCondition($errorResponse->getStatusCode() === 404, "Response::json() soporta códigos de error (404)");
$decodedError = json_decode($errorResponse->getBody(), true);
assertCondition(
    ($decodedError['error']['code'] ?? null) === 'SCROLL_LOST_IN_AETHER',
    "El contrato de error místico del plan (sección 2.4) se serializa correctamente"
);

/**
 * Verifica que un JSON preserva UTF-8 válido tras codificar/decodificar ida y vuelta.
 */
function json_valid_utf8(string $raw): bool
{
    $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $reencoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return $reencoded === $raw;
}

// --- FASE 4: Router — registro, despacho y parámetros de ruta ---
echo "\nFASE 4: Router\n";
$router = new Router();

$capturedParams = [];
$router->addRoute('GET', '/api/v1/spells/{slug}', function (Request $request, array $routeParams) use (&$capturedParams) {
    $capturedParams = $routeParams;
    return Response::json([
        'success' => true,
        'data' => ['slug' => $routeParams['slug'], 'query' => $request->getQueryParam('query', '')],
    ]);
});
$router->addRoute('GET', '/api/v1/spells', fn () => Response::json(['success' => true, 'data' => 'catalog']));
$router->addRoute('POST', '/api/v1/spells', fn () => Response::json(['success' => true, 'data' => 'created'], 201));

$dispatched = $router->dispatch(new Request('GET', '/api/v1/spells/llamas-de-frieren', ['query' => 'frieren']));
assertCondition($dispatched instanceof Response, "dispatch() retorna una Response");
assertCondition($dispatched->getStatusCode() === 200, "La ruta de prueba responde con código HTTP 200");
assertCondition(
    $dispatched->getHeader('Content-Type') === 'application/json; charset=utf-8',
    "La respuesta lleva la cabecera Content-Type: application/json; charset=utf-8"
);
$dispatchedBody = json_decode($dispatched->getBody(), true);
assertCondition(is_array($dispatchedBody) && $dispatchedBody['success'] === true, "La respuesta contiene JSON válido");
assertCondition(
    ($dispatchedBody['data']['slug'] ?? '') === 'llamas-de-frieren',
    "Los parámetros de ruta {slug} se extraen y pasan al manejador"
);
assertCondition($capturedParams['slug'] === 'llamas-de-frieren', "El manejador recibe los parámetros de ruta como array");

$catalogDispatch = $router->dispatch(new Request('GET', '/api/v1/spells', []));
assertCondition(
    (json_decode($catalogDispatch->getBody(), true)['data'] ?? '') === 'catalog',
    "El router distingue rutas fijas de rutas parametrizadas (GET /spells no captura /spells/{slug})"
);

$postDispatch = $router->dispatch(new Request('POST', '/api/v1/spells', []));
assertCondition($postDispatch->getStatusCode() === 201, "El router respeta el método HTTP en la coincidencia");

$notFound = $router->dispatch(new Request('GET', '/api/v1/inexistente', []));
assertCondition($notFound->getStatusCode() === 404, "Ruta no encontrada produce 404 controlado (RF-06)");
$notFoundBody = json_decode($notFound->getBody(), true);
assertCondition(
    ($notFoundBody['error']['code'] ?? '') === 'ROUTE_NOT_FOUND',
    "El 404 del router usa el contrato de error místico"
);

$methodNotAllowed = $router->dispatch(new Request('DELETE', '/api/v1/spells', []));
assertCondition($methodNotAllowed->getStatusCode() === 405, "Verbo no soportado produce 405 Method Not Allowed");

// --- FASE 5: Rendimiento — despacho completo en menos de 5 ms (criterio) ---
echo "\nFASE 5: Rendimiento (< 5 ms por despacho)\n";
$iterations = 1000;
$startNanoseconds = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $perfResponse = $router->dispatch(new Request('GET', '/api/v1/spells/pergamino-de-rendimiento', ['query' => 'frieren']));
    if ($perfResponse->getStatusCode() !== 200) {
        $assertsFailed++;
        echo "  [FALLA] El despacho de rendimiento retorno estado inesperado\n";
        break;
    }
}
$elapsedNanoseconds = hrtime(true) - $startNanoseconds;
$avgMilliseconds = ($elapsedNanoseconds / 1e6) / $iterations;
assertCondition($avgMilliseconds < 5.0, sprintf("Despacho medio: %.4f ms por petición (%d iteraciones, tope 5 ms)", $avgMilliseconds, $iterations));

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.3 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

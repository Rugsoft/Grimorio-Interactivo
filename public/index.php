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
use Grimorio\Controllers\PortalController;
use Grimorio\Controllers\SpellController;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Core\Router;
use Grimorio\Database\Connection;
use Grimorio\Services\SpellDiscoveryService;

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
    $clanController   = new ClanController($connection);

    // --- Rutas de la API (base /api/v1) ---
    $router->addRoute('GET', '/api/v1/portal/featured', fn (Request $request): Response => $portalController->featured($request));
    $router->addRoute('GET', '/api/v1/spells', fn (Request $request): Response => $spellController->index($request));
    $router->addRoute('GET', '/api/v1/spells/{slug}', fn (Request $request, array $routeParams): Response => $spellController->show($request, $routeParams));
    $router->addRoute('GET', '/api/v1/clans/preview', fn (Request $request): Response => $clanController->preview($request));

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

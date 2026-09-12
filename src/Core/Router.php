<?php

/**
 * Router.php — Despachador de rutas REST ligero del Grimorio Interactivo.
 *
 * Tarea 1.3 (TASKS-01): enrutador frontal sin dependencias externas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): regex nativas PHP, sin nikic/fast-route ni frameworks.
 *   - Plan técnico (Decisión 4): despachador de menos de 100 líneas lógicas,
 *     con arranque ultrarrápido (< 5 ms por petición).
 *
 * Diseño:
 *   - Registro de rutas con placeholders '{param}' convertidos a regex nombradas.
 *   - Coincidencia por método HTTP + ruta exacta tras normalizar placeholders.
 *   - 404 con contrato de error místico y 405 cuando la ruta existe pero el verbo no.
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Registro y despacho de rutas de la API REST.
 */
final class Router
{
    /** Mapa de rutas registradas: método HTTP => [regex => manejador]. */
    private array $routes = [];

    /**
     * Registra una ruta con placeholders '{param}'.
     *
     * @param callable(Request, array<string,string>): Response $handler
     */
    public function addRoute(string $method, string $pathPattern, callable $handler): void
    {
        $regex = $this->buildRouteRegex($pathPattern);
        $this->routes[strtoupper($method)][$regex] = $handler;
    }

    /**
     * Despacha la petición al manejador coincidente.
     *
     * @return Response Respuesta emitible (incluye 404/405 controlados).
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = $request->getPath();

        // Rutas registradas para el verbo de la petición.
        $candidateRoutes = $this->routes[$method] ?? [];

        foreach ($candidateRoutes as $routeRegex => $handler) {
            $matches = [];
            if (preg_match($routeRegex, $path, $matches) === 1) {
                // Solo los grupos nombrados interesan al manejador.
                $routeParams = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return call_user_func($handler, $request, $routeParams);
            }
        }

        // La ruta existe con otro verbo: 405 Method Not Allowed (AGENTS.md: códigos HTTP correctos).
        foreach ($this->routes as $registeredMethod => $registeredHandlers) {
            if ($registeredMethod === $method) {
                continue;
            }
            foreach (array_keys($registeredHandlers) as $routeRegex) {
                if (preg_match($routeRegex, $path) === 1) {
                    return Response::json([
                        'success' => false,
                        'error'   => [
                            'code'    => 'METHOD_NOT_ALLOWED',
                            'message' => 'El conjuro no admite esa forma de invocación.',
                        ],
                    ], 405);
                }
            }
        }

        // Ninguna ruta coincide: 404 con contrato de error controlado (RF-06).
        return Response::json([
            'success' => false,
            'error'   => [
                'code'    => 'ROUTE_NOT_FOUND',
                'message' => 'Ningún sendero arcano conduce a ese destino.',
            ],
        ], 404);
    }

    /**
     * Convierte un patrón '/api/v1/spells/{slug}' en regex anclada con grupos nombrados.
     */
    private function buildRouteRegex(string $pathPattern): string
    {
        // Escapado de caracteres regex residuales y sustitución de placeholders.
        $quotedPattern = preg_quote($pathPattern, '/');

        // preg_quote escapa las llaves: '{slug}' queda como '\{slug\}'; se sustituye por grupo nombrado.
        $regexWithParams = preg_replace(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            '(?<$1>[^\/]+)',
            $quotedPattern
        );

        // Anclaje total: la ruta debe coincidir de principio a fin.
        return '/^' . $regexWithParams . '$/';
    }
}

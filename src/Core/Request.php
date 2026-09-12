<?php

/**
 * Request.php — Abstracción tipada de la petición HTTP entrante.
 *
 * Tarea 1.3 (TASKS-01): componente del núcleo HTTP ligero.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin frameworks HTTP externos.
 *   - Artículo V: identificadores en inglés camelCase, documentación en castellano.
 *
 * Diseño:
 *   - Inmutable: una vez construida, la petición no se muta (evita estados ocultos).
 *   - `fromGlobals()` permite al Front Controller (public/index.php, Tarea 1.5)
 *     capturar el mundo real de $_SERVER sin acoplar el resto del código a superglobales.
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Encapsula método HTTP, ruta limpia, parámetros de consulta y cabeceras.
 */
final class Request
{
    /** Verbo HTTP (GET, POST, PUT, DELETE...). */
    private string $method;

    /** Ruta de la petición sin query string (ej. '/api/v1/spells/llamas-de-frieren'). */
    private string $path;

    /** Parámetros de consulta ya parseados (clave => valor textual). */
    private array $queryParams;

    /** Cabeceras normalizadas a Clave-Pascal (insensibles a mayúsculas al consultar). */
    private array $headers;

    /**
     * @param array<string, string> $queryParams Parámetros de consulta.
     * @param array<string, string> $headers     Cabeceras con nombre original.
     */
    public function __construct(
        string $method,
        string $path,
        array $queryParams = [],
        array $headers = []
    ) {
        $this->method      = strtoupper($method);
        $this->path        = $path;
        $this->queryParams = $queryParams;
        $this->headers     = $headers;
    }

    /**
     * Fábrica desde las superglobales PHP: pensada para el Front Controller.
     * Separa la ruta del query string y normaliza las cabeceras HTTP_*.
     */
    public static function fromGlobals(): self
    {
        // Método y URI crudos desde el servidor web.
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // Separación limpia entre ruta y query string (lo que va tras '?').
        $questionMarkPosition = strpos($rawUri, '?');
        $path = $questionMarkPosition === false ? $rawUri : substr($rawUri, 0, $questionMarkPosition);

        // PHP ya parsea el query string en $_GET con codificación estándar.
        $queryParams = $_GET;

        // Recolección de cabeceras: HTTP_<NOMBRE> en $_SERVER (HTTP_X_TEST -> X-Test).
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = (string) $value;
            }
        }

        return new self($method, $path, $queryParams, $headers);
    }

    /** Retorna el verbo HTTP en mayúsculas. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** Retorna la ruta limpia sin query string. */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Retorna un parámetro de consulta concreto o el valor por defecto dado.
     * Defensa contra inyección de arrays vía query string (ej. '?id[]=1'):
     * solo se aceptan valores escalares textuales.
     */
    public function getQueryParam(string $name, ?string $defaultValue = null): ?string
    {
        if (!isset($this->queryParams[$name]) || is_array($this->queryParams[$name])) {
            return $defaultValue;
        }

        return (string) $this->queryParams[$name];
    }

    /**
     * Retorna todos los parámetros de consulta (copia defensiva).
     *
     * @return array<string, string>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Retorna una cabecera por nombre, insensible a mayúsculas, o null si no existe.
     */
    public function getHeader(string $name): ?string
    {
        // Normalización case-insensitive sin depender del formato de origen.
        foreach ($this->headers as $headerName => $headerValue) {
            if (strtolower($headerName) === strtolower($name)) {
                return $headerValue;
            }
        }

        return null;
    }
}

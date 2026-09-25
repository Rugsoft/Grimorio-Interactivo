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

use Grimorio\Models\User;

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

    /** Usuario activo inyectado por el AuthMiddleware (Tarea 3.1, SPEC-03).
     *  Null = visitante anónimo; el RbacMiddleware lo trata como reader. */
    private ?User $user = null;

    /** Vínculo activo (id de `user_sessions`) inyectado por el AuthMiddleware.
     *  Null = sin sesión: no hay dónde retener la ruta del juramento (SPEC-09). */
    private ?string $activeSessionId = null;

    /** Cuerpo crudo de la petición (null = leer de php://input al vuelo).
     *  La SAPI CLI no admite escritura en php://input, de modo que los
     *  arneses de verificación inyectan aquí el cuerpo simulado; bajo
     *  SAPI web el flujo es el nativo del mundo HTTP real. */
    private ?string $rawBody = null;

    /**
     * @param array<string, string> $queryParams Parámetros de consulta.
     * @param array<string, string> $headers     Cabeceras con nombre original.
     * @param string|null         $rawBody      Cuerpo crudo inyectable (solo pruebas).
     */
    public function __construct(
        string $method,
        string $path,
        array $queryParams = [],
        array $headers = [],
        ?string $rawBody = null
    ) {
        $this->method      = strtoupper($method);
        $this->path        = $path;
        $this->queryParams = $queryParams;
        $this->headers     = $headers;
        $this->rawBody     = $rawBody;
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

        // Soporte de subdirectorios (Apache / XAMPP / proxies inversos):
        // si SCRIPT_NAME apunta a un script PHP en una subcarpeta (ej. /sub/public/index.php),
        // se extrae el prefijo de carpeta para que el Router reciba rutas limpias (/api/v1/...).
        // En servidores empotrados (php -S con enrutador), SCRIPT_NAME refleja la URI solicitada
        // y no termina en .php, por lo que no debe amputarse el sendero.
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if (str_ends_with(strtolower($scriptName), '.php')) {
            $baseDir = str_replace('\\', '/', dirname($scriptName));
            if ($baseDir !== '/' && $baseDir !== '.' && $baseDir !== '' && str_starts_with($path, $baseDir)) {
                $path = substr($path, strlen($baseDir));
                if (!str_starts_with($path, '/')) {
                    $path = '/' . $path;
                }
            }
        }

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

    /**
     * Inyecta el usuario activo resuelto por el AuthMiddleware (SPEC-03).
     * Único punto de mutación permitido de la petición: ocurre antes del
     * despacho del router y nunca después.
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /** Usuario activo de la petición (null = visitante anónimo, RF-02). */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Inyecta el vínculo activo resuelto por el AuthMiddleware: la ruta
     * retenida del juramento vive en la FILA de la sesión (SPEC-09,
     * enmienda de la Tarea 9.2 de SPEC-11).
     */
    public function setActiveSessionId(string $sessionId): void
    {
        $this->activeSessionId = $sessionId;
    }

    /** Vínculo activo de la petición (null = visitante anónimo). */
    public function getActiveSessionId(): ?string
    {
        return $this->activeSessionId;
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

    /**
     * Lee y decodifica el cuerpo JSON de la petición (endpoints mutables,
     * plan 2.2 de SPEC-03). Devuelve null si el cuerpo está ausente,
     * corrupto o no es un objeto/arraigado JSON válido: el controlador
     * traducirá null en un 400 controlado sin explosión interna.
     *
     * @return array<string, mixed>|null Payload decodificado o null si es inválido.
     */
    public function getJsonBody(): ?array
    {
        $rawBody = $this->rawBody ?? file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '' || trim($rawBody) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // Solo objetos JSON con claves textuales son contratos válidos
        // (defensa contra payloads escalares o listas anónimas).
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lee la cookie de sesión cruda que porta el cliente (RF-02.1).
     * Consulta primero la cabecera Cookie (determinista en pruebas) y
     * cae en la superglobal $_COOKIE, que es la vía del mundo HTTP real.
     */
    public function getCookie(string $name): ?string
    {
        $cookieHeader = $this->getHeader('Cookie');
        if ($cookieHeader !== null) {
            foreach (explode(';', $cookieHeader) as $cookiePair) {
                $pairParts = explode('=', trim($cookiePair), 2);
                if (count($pairParts) === 2 && $pairParts[0] === $name) {
                    return $pairParts[1];
                }
            }
        }

        $superglobalValue = $_COOKIE[$name] ?? null;

        return is_string($superglobalValue) && $superglobalValue !== '' ? $superglobalValue : null;
    }

    /**
     * Procedencia del cliente (RF-03.2): IP directa o el primer salto del
     * encadenado X-Forwarded-For cuando hay proxy de por medio. El valor
     * jamás se confía para nada más que para el registro de intentos.
     */
    public function getClientIp(): string
    {
        $forwardedFor = $this->getHeader('X-Forwarded-For');
        if ($forwardedFor !== null && $forwardedFor !== '') {
            $firstHop = trim(explode(',', $forwardedFor)[0]);
            if ($firstHop !== '') {
                return $firstHop;
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

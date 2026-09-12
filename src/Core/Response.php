<?php

/**
 * Response.php — Emisor estándar de respuestas JSON del Grimorio Interactivo.
 *
 * Tarea 1.3 (TASKS-01): componente del núcleo HTTP ligero.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin frameworks HTTP externos.
 *   - AGENTS.md 2.1: respuestas JSON estándar 'Content-Type: application/json; charset=utf-8'.
 *   - AGENTS.md 6.1: nunca exponer trazas internas; contratos de error controlados.
 *
 * Diseño:
 *   - Inmutable: la Response se construye con estado y cuerpo definitivos.
 *   - Fábrica `Response::json()` centraliza la serialización UTF-8 correcta
 *     (JSON_UNESCAPED_UNICODE para preservar el lore en castellano).
 *   - `send()` emite cabeceras y cuerpo hacia el cliente (uso exclusivo del Front Controller).
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Representa una respuesta HTTP lista para ser emitida.
 */
final class Response
{
    /** Cabecera de contenido exigida por el estándar del proyecto. */
    private const JSON_CONTENT_TYPE = 'application/json; charset=utf-8';

    /** Código de estado HTTP (200, 400, 404, 500...). */
    private int $statusCode;

    /** Cuerpo ya serializado, listo para emitir. */
    private string $body;

    /** Cabeceras adicionales (nombre => valor). */
    private array $headers;

    /**
     * @param array<string, string> $headers Cabeceras adicionales opcionales.
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body       = $body;
        $this->headers    = $headers + ['Content-Type' => self::JSON_CONTENT_TYPE];
    }

    /**
     * Fábrica canónica de respuestas JSON del proyecto.
     *
     * @param mixed $payload Datos serializables a JSON (arrays del contrato del plan).
     */
    public static function json(mixed $payload, int $statusCode = 200): self
    {
        // JSON_UNESCAPED_UNICODE preserva tildes y eñes del lore castellano.
        // JSON_PRESERVE_ZERO_FRACTION evita corrupción de números en contratos futuros.
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        // json_encode puede fallar (recursión, UTF-8 malformado): respuesta controlada.
        if ($body === false) {
            $body = (string) json_encode([
                'success' => false,
                'error'   => [
                    'code'    => 'INTERNAL_SERIALIZATION_FAILED',
                    'message' => 'El pergamino no pudo transcribirse al formato de intercambio.',
                ],
            ], JSON_UNESCAPED_UNICODE);
            $statusCode = 500;
        }

        return new self($statusCode, $body);
    }

    /** Retorna el código de estado HTTP. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Retorna el cuerpo serializado. */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Retorna una cabecera por nombre (insensible a mayúsculas) o null.
     */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $headerName => $headerValue) {
            if (strtolower($headerName) === strtolower($name)) {
                return $headerValue;
            }
        }

        return null;
    }

    /**
     * Emite la respuesta hacia el cliente (cabeceras + cuerpo).
     * Uso exclusivo del Front Controller; los controladores retornan Response.
     */
    public function send(): void
    {
        // Bandera de estado HTTP sin cuerpo (FastCGI friendly).
        http_response_code($this->statusCode);

        foreach ($this->headers as $headerName => $headerValue) {
            header("{$headerName}: {$headerValue}");
        }

        echo $this->body;
    }
}

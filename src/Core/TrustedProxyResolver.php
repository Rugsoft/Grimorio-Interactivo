<?php

/**
 * TrustedProxyResolver.php — Resolvedor de procedencia con proxies confiables.
 *
 * Tarea 2.2 (TASKS-15): materializa el contrato conceptual ratificado en
 * PLAN-15 §3.1:
 *
 *     resolveClientIp(remoteAddress, forwardedChain, trustedProxyAddresses)
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): filter_var nativo, sin librerías de red.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 *
 * Seguridad (SPEC-15 RF-02, RF-03; RNF-01 no confianza implícita):
 *   - El par conectado (`REMOTE_ADDR`) validado es la única autoridad
 *     por defecto: la lista de proxies confiables nace VACÍA (política
 *     ratificada en la Tarea 0.3 de TASKS-15 con la evidencia del hosting).
 *   - Las cabeceras reenviadas SOLO se leen cuando el par conectado es un
     * proxy declarado confiable; nunca un cliente directo elige la clave
     * del limitador enviando una cabecera (RF-03.3/03.4, §8 caso 3).
 *   - Las entradas inválidas de la lista NO agregan confianza (PLAN-15 §4).
 *   - Una cadena malformada tras un proxy confiable se RECHAZA en bloque
 *     (error cerrado): manda el par conectado validado, jamás un salto
 *     dudoso (§8 caso 4).
 *   - Sin IP válida alguna, la procedencia es `0.0.0.0`: valor controlado
 *     y estable, compatible con `login_attempts.ip_address` (PLAN-15 §3.1,
 *     regla 5).
 */

declare(strict_types=1);

namespace Grimorio\Core;

/**
 * Resolución pura y comprobable de la procedencia efectiva del cliente.
 */
final class TrustedProxyResolver
{
    /** Procedencia controlada cuando no existe una dirección válida (regla 5). */
    private const FALLBACK_IP = '0.0.0.0';

    /**
     * Resuelve la IP de cliente efectiva conforme al contrato del plan.
     *
     * @param string      $remoteAddress         Par conectado ($_SERVER['REMOTE_ADDR']).
     * @param string|null $forwardedChain        Cadena reenviada cruda (p. ej. X-Forwarded-For) o null.
     * @param array<int|string, mixed> $trustedProxyAddresses Direcciones exactas de proxies confiables declarados.
     */
    public static function resolveClientIp(string $remoteAddress, ?string $forwardedChain, array $trustedProxyAddresses): string
    {
        // Regla 1: el par conectado DEBE ser una IP válida (IPv4 o IPv6).
        if (!self::isValidIp($remoteAddress)) {
            return self::FALLBACK_IP;
        }

        // Regla 2 (PLAN-15 §4): solo las entradas de la lista que sean IPs
        // válidas agregan confianza; tipos inesperados, vacíos, hostnames
        // o direcciones imposibles se descartan en silencio.
        $trusted = [];
        foreach ($trustedProxyAddresses as $entry) {
            if (is_string($entry) && self::isValidIp($entry)) {
                $trusted[$entry] = true;
            }
        }

        // Regla 2 del contrato: si el par conectado no es un proxy
        // confiable declarado, la cadena reenviada se IGNORA por completo
        // (el cliente no puede elegir su propia procedencia).
        if (!isset($trusted[$remoteAddress])) {
            return $remoteAddress;
        }

        // Regla 3: el par ES confiable. La cadena se interpreta desde el
        // extremo conectado (derecha) hacia el cliente: cada salto se
        // valida y los saltos confiables se descartan; el primer salto no
        // confiable validado es la procedencia contractual.
        $hops = array_map('trim', explode(',', (string) $forwardedChain));
        for ($hopIndex = count($hops) - 1; $hopIndex >= 0; $hopIndex--) {
            $hop = $hops[$hopIndex];

            // Error cerrado: un hueco o un salto inválido invalidan la
            // cadena entera (conservador: manda el par conectado validado,
            // jamás un valor dudoso elegible por el cliente).
            if ($hop === '' || !self::isValidIp($hop)) {
                return $remoteAddress;
            }

            if (!isset($trusted[$hop])) {
                return $hop;
            }
        }

        // Todos los saltos son confiables (o la cadena está vacía): sin un
        // salto no confiable que atribuir, la procedencia es el propio par.
        return $remoteAddress;
    }

    /**
     * Valida que el valor sea una IP IPv4/IPv6 textual (sin puerto, sin
     * hostname, sin rangos CIDR: el plan prohíbe parsers implícitos).
     */
    private static function isValidIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}

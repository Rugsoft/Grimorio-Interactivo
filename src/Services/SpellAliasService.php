<?php

/**
 * SpellAliasService.php — Presentación pública de los alias de autoría.
 *
 * Tarea 3.3 (TASKS-15): materializa la enmienda RF-09.3 de SPEC-03 —
 * el seudónimo público «Erudito Ancestral (Legado Anónimo)» es COMÚN y
 * compartido por todas las cuentas renunciadas; las identidades internas
 * son únicas no colisionables (users.alias UNIQUE) y jamás se exhiben.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): transformación PHP pura de cadenas.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 *
 * Contrato (RF-09.3):
 *   - Ante la comunidad, TODA autoría legada se exhibe bajo el único
 *     rótulo común, sin distinción de origen ni sufijo técnico.
 *   - La función es pura y determinista: el mismo alias interno produce
 *     siempre el mismo rótulo público, y un alias normal (no renunciado)
 *     atraviesa sin transformación.
 */

declare(strict_types=1);

namespace Grimorio\Services;

/**
 * Normalizador de alias de autoría para la superficie pública.
 */
final class SpellAliasService
{
    /** Rótulo público común de las cuentas renunciadas (RF-09.3). */
    public const PUBLIC_LEGACY_LABEL = 'Erudito Ancestral';

    /** Etiqueta solemne que acompaña al rótulo en la exhibición pública. */
    public const PUBLIC_LEGACY_SUFFIX = '(Legado Anónimo)';

    /** Prefijo del alias técnico interno que AuthService graba al renunciar. */
    public const INTERNAL_LEGACY_PREFIX = 'Erudito Ancestral · ';

    /**
     * Devuelve el alias listo para la exhibición pública.
     *
     * Un alias técnico de renuncia (prefijo reservado) se presenta como el
     * rótulo común completo; cualquier otro alias atraviesa intacto.
     * Pura: sin I/O, sin estado, determinista.
     */
    public static function displayAlias(string $storedAlias): string
    {
        if (str_starts_with($storedAlias, self::INTERNAL_LEGACY_PREFIX)) {
            return self::PUBLIC_LEGACY_LABEL . ' ' . self::PUBLIC_LEGACY_SUFFIX;
        }

        return $storedAlias;
    }
}

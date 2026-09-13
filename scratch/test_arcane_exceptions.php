<?php

/**
 * test_arcane_exceptions.php — Arnés TDD de la Tarea 2.1 (TASKS-04).
 *
 * Verifica las excepciones de dominio arcanas:
 *   - ArcaneOverloadException: Sobrecarga Arcana (código HTTP 400,
 *     código canónico ARCANE_OVERLOAD, maná calculado portado).
 *   - SpellImmutableException: inviolabilidad de conjuros validados
 *     (código HTTP 403, código canónico SPELL_IMMUTABLE).
 *
 * Criterio «Hecho cuando» (Tarea 2.1): lanzar ArcaneOverloadException
 * encapsula el mensaje ceremonial de exceso de maná y el código canónico
 * de respuesta ARCANE_OVERLOAD.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo II: el techo de 200 de maná se defiende con la excepción.
 *   - Artículo III: la inmutabilidad del validado se defiende con la otra.
 *   - Artículo IV: los mensajes canónicos solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase, documentación en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';

use Grimorio\Exceptions\ArcaneOverloadException;
use Grimorio\Exceptions\SpellImmutableException;

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne del arnés.
 */
function assertArcane(bool $condition, string $legend): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$legend}\n";
        return;
    }
    $assertsFailed++;
    echo "  FALLA {$legend}\n";
}

/**
 * Ejecuta una función y reporta la excepción capturada (o null).
 */
function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

echo "=== Tarea 2.1 (TASKS-04): excepciones de dominio arcanas ===\n\n";

// =====================================================================
// [0] Superficie: jerarquía y códigos canónicos.
// =====================================================================
echo "[0] Superficie — jerarquía de excepciones\n";

assertArcane(class_exists(ArcaneOverloadException::class), 'La clase ArcaneOverloadException existe');
assertArcane(class_exists(SpellImmutableException::class), 'La clase SpellImmutableException existe');
assertArcane(is_subclass_of(ArcaneOverloadException::class, RuntimeException::class), 'ArcaneOverloadException hereda de RuntimeException (dominio, no sintaxis)');
assertArcane(is_subclass_of(SpellImmutableException::class, RuntimeException::class), 'SpellImmutableException hereda de RuntimeException (dominio, no sintaxis)');

$overloadReflection = new ReflectionClass(ArcaneOverloadException::class);
$immutableReflection = new ReflectionClass(SpellImmutableException::class);

$overloadHttpCode = $overloadReflection->hasConstant('HTTP_STATUS_CODE') ? $overloadReflection->getConstant('HTTP_STATUS_CODE') : null;
$immutableHttpCode = $immutableReflection->hasConstant('HTTP_STATUS_CODE') ? $immutableReflection->getConstant('HTTP_STATUS_CODE') : null;
assertArcane($overloadHttpCode === 400, 'ArcaneOverloadException porta el código HTTP 400 (Tarea 2.1)');
assertArcane($immutableHttpCode === 403, 'SpellImmutableException porta el código HTTP 403 (Tarea 2.1)');

$overloadErrorCode = $overloadReflection->hasConstant('ERROR_CODE') ? $overloadReflection->getConstant('ERROR_CODE') : null;
$immutableErrorCode = $immutableReflection->hasConstant('ERROR_CODE') ? $immutableReflection->getConstant('ERROR_CODE') : null;
assertArcane($overloadErrorCode === 'ARCANE_OVERLOAD', 'El código canónico de sobrecarga es ARCANE_OVERLOAD');
assertArcane($immutableErrorCode === 'SPELL_IMMUTABLE', 'El código canónico de inviolabilidad es SPELL_IMMUTABLE');

// =====================================================================
// [1] Sobrecarga Arcana: mensaje ceremonial y maná calculado portado.
// =====================================================================
echo "\n[1] ArcaneOverloadException — Sobrecarga Arcana (RF-03.2)\n";

// El mensaje ceremonial EXACTO del RF-03.2 de la spec (sección 3.1/03.2).
$ceremonialLegend = 'La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).';

$defaultException = catchException(static fn () => throw ArcaneOverloadException::forMana(240));
assertArcane($defaultException instanceof ArcaneOverloadException, 'La fábrica forMana() lanza la excepción de sobrecarga');
assertArcane(
    $defaultException !== null && $defaultException->getMessage() === $ceremonialLegend,
    'El mensaje por defecto es el ceremonial EXACTO de la spec'
);
assertArcane(
    $defaultException !== null && $defaultException->getCalculatedMana() === 240,
    'La excepción porta el maná calculado (240) para el campo calculatedMana del contrato'
);
assertArcane(
    $defaultException !== null && $defaultException->getHttpStatusCode() === 400,
    'El código HTTP viaja en la instancia (400)'
);
assertArcane(
    $defaultException !== null && $defaultException->getErrorCode() === 'ARCANE_OVERLOAD',
    'El código canónico viaja en la instancia (ARCANE_OVERLOAD)'
);

// El maná del contrato del plan (sección 2.2, ejemplo de 400).
$contractException = catchException(static fn () => throw ArcaneOverloadException::forMana(240));
$contractPayload = $contractException !== null ? $contractException->toPayload() : [];
assertArcane(
    is_array($contractPayload)
    && ($contractPayload['error']['code'] ?? null) === 'ARCANE_OVERLOAD'
    && ($contractPayload['error']['calculatedMana'] ?? null) === 240
    && ($contractPayload['success'] ?? null) === false,
    'toPayload() genera el cuerpo de error del contrato del plan (error.calculatedMana)'
);

// Sobrecarga en el umbral exacto: 201 (criterio de la Tarea 2.3).
$thresholdException = catchException(static fn () => throw ArcaneOverloadException::forMana(201));
assertArcane(
    $thresholdException !== null && $thresholdException->getCalculatedMana() === 201,
    'El umbral de la Sobrecarga (201) porta su maná calculado'
);

// Mensaje personalizado: el constructor lo admite sin romper el contrato.
$customException = catchException(static fn () => throw new ArcaneOverloadException('Leyenda ad hoc del arnés.', 199));
assertArcane(
    $customException !== null && $customException->getMessage() === 'Leyenda ad hoc del arnés.',
    'El constructor admite leyendas personalizadas sin romper el contrato'
);

// =====================================================================
// [2] SpellImmutableException — inviolabilidad del validado (RF-06.2).
// =====================================================================
echo "\n[2] SpellImmutableException — patrimonio inmutable (RF-06.2)\n";

$immutableException = catchException(static fn () => throw SpellImmutableException::forValidatedSpell('spl_genesis_01'));
assertArcane($immutableException instanceof SpellImmutableException, 'La fábrica forValidatedSpell() lanza la excepción de inviolabilidad');
assertArcane(
    $immutableException !== null
    && str_contains($immutableException->getMessage(), 'patrimonio inmutable')
    && str_contains($immutableException->getMessage(), 'no puede alterarse'),
    'El mensaje porta la solemnidad de la spec (patrimonio inmutable, no puede alterarse)'
);
assertArcane(
    $immutableException !== null && $immutableException->getHttpStatusCode() === 403,
    'El código HTTP viaja en la instancia (403)'
);
assertArcane(
    $immutableException !== null && $immutableException->getErrorCode() === 'SPELL_IMMUTABLE',
    'El código canónico viaja en la instancia (SPELL_IMMUTABLE)'
);

$immutablePayload = $immutableException !== null ? $immutableException->toPayload() : [];
assertArcane(
    is_array($immutablePayload)
    && ($immutablePayload['error']['code'] ?? null) === 'SPELL_IMMUTABLE'
    && ($immutablePayload['success'] ?? null) === false,
    'toPayload() genera el cuerpo de error canónico del proyecto'
);

// =====================================================================
// [3] Contrato de error del proyecto (AGENTS.md 6.1): éxito/estructura.
// =====================================================================
echo "\n[3] Contrato de error del proyecto (AGENTS.md 6.1)\n";

$payload = $defaultException !== null ? $defaultException->toPayload() : [];
assertArcane(
    is_array($payload) && array_keys($payload) === ['success', 'error'],
    'El sobre de error porta success + error (estructura canónica)'
);
assertArcane(
    is_array($payload) && array_keys($payload['error'] ?? []) === ['code', 'message', 'calculatedMana'],
    'El objeto error porta code, message y calculatedMana (contrato del plan 2.2)'
);

$immutablePayloadKeys = is_array($immutablePayload) ? array_keys($immutablePayload['error'] ?? []) : [];
assertArcane(
    $immutablePayloadKeys === ['code', 'message', 'recoveryAction'],
    'El error de inviolabilidad porta code, message y recoveryAction (AGENTS.md 6.1)'
);

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

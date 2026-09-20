<?php

declare(strict_types=1);

/**
 * test_clan_governance_codes.php — Verificación de la Tarea 2.1 de TASKS-10.
 *
 * Valida las cuatro fábricas canónicas nuevas de `ClanGovernanceException`
 * (SPEC-10) contra el «Hecho cuando» de la tarea: cada fábrica emite su
 * código canónico, el HTTP 403 y la leyenda EXACTA del Anexo A del plan
 * (ratificado), junto con el recoveryAction del contrato.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP puro, sin librerías.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en noble
 *     castellano.
 *
 * Uso: php scratch/test_clan_governance_codes.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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

echo "== VERIFICACION TAREA 2.1: Cuatro codigos canonicos del Vestibulo ==\n\n";

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/src/Exceptions/ClanGovernanceException.php';

use Grimorio\Exceptions\ClanGovernanceException;

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie de la excepción\n";
$source = (string) file_get_contents($projectRoot . '/src/Exceptions/ClanGovernanceException.php');
assertCondition(str_contains($source, 'function clanLineageMismatch('), 'Existe la fábrica clanLineageMismatch()');
assertCondition(str_contains($source, 'function clanLoyaltyBound('), 'Existe la fábrica clanLoyaltyBound()');
assertCondition(str_contains($source, 'function adminLineageRequired('), 'Existe la fábrica adminLineageRequired()');
assertCondition(str_contains($source, 'function applicationHouseClosed('), 'Existe la fábrica applicationHouseClosed()');
assertCondition(str_contains($source, 'declare(strict_types=1);'), 'Tipado estricto (Artículo I)');

// --- FASE 1: CLAN_LINEAGE_MISMATCH (Anexo A 1) ---
echo "\nFASE 1: CLAN_LINEAGE_MISMATCH — el estandarte de otra sangre\n";
$e = ClanGovernanceException::clanLineageMismatch();
assertCondition($e->errorCode === 'CLAN_LINEAGE_MISMATCH', 'Emite el código canónico CLAN_LINEAGE_MISMATCH');
assertCondition($e->httpStatus === 403, 'Responde HTTP 403');
assertCondition(
    $e->getMessage() === 'Ese estandarte porta otro linaje: tu juramento te ata a las casas de tu propia sangre.',
    'Leyenda EXACTA del Anexo A (1) del plan'
);
assertCondition($e->recoveryAction === 'CHOOSE_OWN_LINEAGE_CLAN', 'recoveryAction del contrato');
assertCondition(str_contains($e->getMessage(), 'porta'), 'La leyenda ratificada usa «porta» (no el «ruega» del borrador)');

// --- FASE 2: CLAN_LOYALTY_BOUND (Anexo A 2) ---
echo "\nFASE 2: CLAN_LOYALTY_BOUND — la lealtad empeñada\n";
$e = ClanGovernanceException::clanLoyaltyBound('Mareas de Aether');
assertCondition($e->errorCode === 'CLAN_LOYALTY_BOUND', 'Emite el código canónico CLAN_LOYALTY_BOUND');
assertCondition($e->httpStatus === 403, 'Responde HTTP 403');
assertCondition(
    $e->getMessage() === 'Tu lealtad ya está empeñada en Mareas de Aether: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo sus puertas.',
    'Leyenda EXACTA del Anexo A (2) con la casa interpolada'
);
assertCondition($e->recoveryAction === 'HONOR_CURRENT_OATH', 'recoveryAction del contrato');
assertCondition(!str_contains($e->getMessage(), 'la partida'), 'La leyenda ratificada elimina la polisemia de «la partida»');

// --- FASE 3: ADMIN_LINEAGE_REQUIRED (Anexo A 3) ---
echo "\nFASE 3: ADMIN_LINEAGE_REQUIRED — el Privilegio Fundacional\n";
$e = ClanGovernanceException::adminLineageRequired();
assertCondition($e->errorCode === 'ADMIN_LINEAGE_REQUIRED', 'Emite el código canónico ADMIN_LINEAGE_REQUIRED');
assertCondition($e->httpStatus === 403, 'Responde HTTP 403');
assertCondition(
    $e->getMessage() === 'El Privilegio Fundacional te exime del juramento; sin linaje jurado no hay hermandades que contemplar.',
    'Leyenda EXACTA del Anexo A (3) del plan'
);
assertCondition($e->recoveryAction === 'VIEW_PUBLIC_HALL', 'recoveryAction del contrato');
assertCondition($e->errorCode !== 'LINEAGE_OATH_REQUIRED', 'NO confunde al peregrino de SPEC-09: el Supremo está exento de la retención');

// --- FASE 4: APPLICATION_HOUSE_CLOSED (Anexo A 4) ---
echo "\nFASE 4: APPLICATION_HOUSE_CLOSED — la palabra ya pronunciada\n";
$e = ClanGovernanceException::applicationHouseClosed();
assertCondition($e->errorCode === 'APPLICATION_HOUSE_CLOSED', 'Emite el código canónico APPLICATION_HOUSE_CLOSED');
assertCondition($e->httpStatus === 403, 'Responde HTTP 403');
assertCondition(
    $e->getMessage() === 'Ya pronunciaste tu palabra ante esta casa: rechazada o retirada, quedó clausurada para ti. Otras puertas aguardan.',
    'Leyenda EXACTA del Anexo A (4) del plan'
);
assertCondition($e->recoveryAction === 'CHOOSE_ANOTHER_CLAN', 'recoveryAction del contrato');
assertCondition($e->errorCode !== 'APPLICATION_ALREADY_PENDING', 'NO confunde clausura perpetua con petición viva (discernimiento plan §1.3)');

// --- FASE 5: el sobre REST (AGENTS.md 6.1) ---
echo "\nFASE 5: El sobre de error está listo para Response::json()\n";
$payload = ClanGovernanceException::clanLoyaltyBound('Casa Prueba')->toPayload();
assertCondition($payload['success'] === false, 'El sobre declara success=false');
assertCondition($payload['error']['code'] === 'CLAN_LOYALTY_BOUND', 'El sobre porta el código canónico');
assertCondition(str_contains($payload['error']['message'], 'Casa Prueba'), 'El sobre porta la leyenda con la casa');
assertCondition(isset($payload['error']['recoveryAction']), 'El sobre porta el recoveryAction');

// --- FASE 6: regresión de los códigos hermanos ---
echo "\nFASE 6: Los códigos existentes quedan intactos (enmienda declarada)\n";
$legacy = ClanGovernanceException::alreadyAffiliated();
assertCondition($legacy->errorCode === 'ALREADY_AFFILIATED' && $legacy->httpStatus === 409, 'ALREADY_AFFILIATED permanece canónico (409) para la vía de fundación');
$convalescent = ClanGovernanceException::convalescenceActive();
assertCondition($convalescent->errorCode === 'CONVALESCENCE_ACTIVE' && $convalescent->httpStatus === 403, 'CONVALESCENCE_ACTIVE permanece intacto (RF-03.5)');

// --- Veredicto ---
echo "\n=============================\n";
echo "Asertos: {$assertsPassed} PASA / {$assertsFailed} FALLOS\n";
if ($assertsFailed > 0) {
    echo "Los códigos canónicos del Vestíbulo quedan ROJOS: no cumplen aún su contrato.\n";
    exit(1);
}
echo "Las cuatro fábricas emiten su código, su 403 y la leyenda exacta del Anexo A ratificado.\n";
exit(0);

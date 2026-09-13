<?php

/**
 * test_spell_antifraud.php — Arnés TDD de la Tarea 3.3 (TASKS-04).
 *
 * Verifica updateExperimental(User, string, SpellCreateDto): el antifraude
 * de firmas por huella matemática (RF-05.3, RF-05.4, RNF-03, Art. III):
 *   - Cambio MATEMÁTICO (huella distinta): firmas → 0, huella y maná
 *     recalculados, evento en audit_log (RESET_SIGNATURES_MATH_CHANGE).
 *   - Cambio NARRATIVO (huella idéntica): firmas INTACTAS, evento en
 *     audit_log (UPDATE_DESCRIPTION_INTACT_SIGNATURES).
 *   - Validado: rechazo estricto (SpellImmutableException, 403).
 *
 * Criterio «Hecho cuando» (Tarea 3.3): alterar el alcance de un conjuro
 * experimental con 2 firmas devuelve sus firmas a 0, mientras que
 * corregir una falta de ortografía mantiene las 2 firmas registrando el
 * evento en audit_log.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria.
 *   - Artículo II: el maná recalculado es siempre el del backend.
 *   - Artículo III: la huella neutraliza el fraude por sustitución de
 *     conjuros tras obtener firmas de Maestros.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\SpellImmutableException;
use Grimorio\Models\User;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

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

function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

/**
 * DTO de creación con parámetros cuantitativos configurables.
 */
function forgeCreateDto(string $name, int $damage = 30, string $rangeType = 'medium'): SpellCreateDto
{
    return new SpellCreateDto(
        name: $name,
        elementalAffinity: 'fire',
        magicSchool: 'evocation',
        castingTime: 'action',
        description: 'Conjuro del arnés antifraude.',
        calculationInput: new SpellCalculationInputDto(
            damage: $damage,
            healing: 0,
            barrier: 0,
            crowdControlType: 'none',
            rangeType: $rangeType,
            areaType: 'sphere',
            durationType: 'instant',
            hasVerbal: true,
            hasSomatic: true,
            hasMaterial: false,
        ),
    );
}

/**
 * Fija firmas y huella directamente en BD (simula firmas de Maestros).
 */
function forceSignatures(PDO $pdo, string $spellId, int $count): void
{
    $pdo->prepare('UPDATE spells SET signatures_count = :count WHERE id = :id')
        ->execute([':count' => $count, ':id' => $spellId]);
}

function fetchRow(PDO $pdo, string $spellId): array
{
    $statement = $pdo->prepare('SELECT status, mana_cost, circle, math_fingerprint, signatures_count FROM spells WHERE id = :id');
    $statement->execute([':id' => $spellId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

echo "=== Tarea 3.3 (TASKS-04): antifraude de firmas por huella matemática ===\n\n";

// =====================================================================
// Escenario: SQLite en memoria con el esquema real.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-13T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', 0, '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_autor', 'AutorExperimental', 'autor@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User('usr_autor', 'AutorExperimental', 'autor@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);

// El servicio porta su propio AuditService (bitácora real).
$service = new SpellManagementService($pdo);

// =====================================================================
// [0] Superficie (fase roja): el método existe.
// =====================================================================
echo "[0] Superficie\n";
assertArcane(
    method_exists(SpellManagementService::class, 'updateExperimental'),
    'SpellManagementService expone updateExperimental()'
);

// =====================================================================
// [1] Fraude matemático: cambio de alcance con 2 firmas → reseteo a 0.
// =====================================================================
echo "\n[1] Fraude matemático: alcance medium → long con 2 firmas (RF-05.3)\n";

// Ciclo: draft → experimental (el borrador queda fuera de la cuota).
$fraudDraft = $service->createDraft($author, forgeCreateDto('Esfera Vulnerable', damage: 30));
$fraudId = $fraudDraft['id'];
$service->publishToExperimental($author, $fraudId);

// Dos Maestros firman; la huella persistida es la del medium/sphere.
forceSignatures($pdo, $fraudId, 2);
$rowBefore = fetchRow($pdo, $fraudId);
$fingerprintBefore = (string) $rowBefore['math_fingerprint'];
assertArcane((int) $rowBefore['signatures_count'] === 2, 'El conjuro experimental porta 2 firmas de Maestros antes del fraude');

// EL FRAUDE: alterar el alcance (medium → long) tras obtener las firmas.
$mathUpdate = $service->updateExperimental($author, $fraudId, forgeCreateDto('Esfera Vulnerable', damage: 30, rangeType: 'long'));
$rowAfterFraud = fetchRow($pdo, $fraudId);

assertArcane((int) $rowAfterFraud['signatures_count'] === 0, 'Las firmas se restablecen a 0 ante un cambio matemático (antifraude)');
assertArcane((string) $rowAfterFraud['math_fingerprint'] !== $fingerprintBefore, 'La huella matemática se actualiza al nuevo parámetro');
assertArcane((string) $rowAfterFraud['math_fingerprint'] === hash('sha256', '30:0:0:none:long:sphere:instant:1:1:0'), 'La nueva huella corresponde a los parámetros fraudulentos ya legitimados');
assertArcane((int) $rowAfterFraud['mana_cost'] === 58, 'El maná se recalcula con el nuevo alcance (30 × 1.5 × 1.6 ≈ 72 − 20% = 57.6 → 58)');

// El evento de reseteo queda en la bitácora inmutable.
$resetEvent = $pdo->query(
    "SELECT action_type, justification FROM audit_log WHERE target_entity_id = '{$fraudId}' AND action_type = 'RESET_SIGNATURES_MATH_CHANGE'"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(is_array($resetEvent), 'El evento RESET_SIGNATURES_MATH_CHANGE queda registrado en audit_log');
assertArcane(
    is_array($resetEvent) && str_contains((string) $resetEvent['justification'], '0/3'),
    'La justificación porta el reseteo de firmas a 0/3'
);

// =====================================================================
// [2] Cambio narrativo: ortografía corregida con 2 firmas → intactas.
// =====================================================================
echo "\n[2] Cambio narrativo: corrección ortográfica con 2 firmas (RF-05.4)\n";

// Se re-firman 2 Maestros sobre el conjuro ya rese Laureado.
forceSignatures($pdo, $fraudId, 2);
$fingerprintStable = (string) fetchRow($pdo, $fraudId)['math_fingerprint'];

// La CORRECCIÓN: solo cambia la descripción (los 10 parámetros matemáticos
// son idénticos → huella idéntica → firmas intactas).
$narrativeDto = new SpellCreateDto(
    name: 'Esfera Vulnerable',
    elementalAffinity: 'fire',
    magicSchool: 'evocation',
    castingTime: 'action',
    description: 'Conjuro del arnés antifraude, ahora con la ortografía corregida.',
    calculationInput: new SpellCalculationInputDto(
        damage: 30, healing: 0, barrier: 0, crowdControlType: 'none',
        rangeType: 'long', areaType: 'sphere', durationType: 'instant',
        hasVerbal: true, hasSomatic: true, hasMaterial: false,
    ),
);
$narrativeUpdate = $service->updateExperimental($author, $fraudId, $narrativeDto);
$rowAfterNarrative = fetchRow($pdo, $fraudId);

assertArcane((int) $rowAfterNarrative['signatures_count'] === 2, 'Las 2 firmas se MANTIENEN ante un cambio meramente narrativo');
assertArcane((string) $rowAfterNarrative['math_fingerprint'] === $fingerprintStable, 'La huella matemática permanece intacta (misma matemática)');
assertArcane($narrativeUpdate['signaturesReset'] === false, 'La respuesta declara signaturesReset = false (sin reseteo)');

$narrativeEvent = $pdo->query(
    "SELECT action_type FROM audit_log WHERE target_entity_id = '{$fraudId}' AND action_type = 'UPDATE_DESCRIPTION_INTACT_SIGNATURES'"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(is_array($narrativeEvent), 'El evento UPDATE_DESCRIPTION_INTACT_SIGNATURES queda registrado en audit_log');

// =====================================================================
// [3] La respuesta distingue ambos caminos (contrato del plan, Endpoint 5).
// =====================================================================
echo "\n[3] Contrato del Endpoint 5: signaturesReset en la respuesta\n";

// Otro fraude matemático retorna signaturesReset = true.
forceSignatures($pdo, $fraudId, 1);
$resetResponse = $service->updateExperimental($author, $fraudId, forgeCreateDto('Esfera Vulnerable', damage: 35, rangeType: 'long'));
assertArcane($resetResponse['signaturesReset'] === true, 'El cambio matemático retorna signaturesReset = true');
assertArcane((int) fetchRow($pdo, $fraudId)['signatures_count'] === 0, 'Las firmas quedan a 0 tras el segundo fraude detectado');

// =====================================================================
// [4] Inviolabilidad: el validado no se toca (RF-06.2, Tarea 3.4 anticipo).
// =====================================================================
echo "\n[4] Inviolabilidad del validado (RF-06.2)\n";

$validatedId = 'spl_validado_arnes';
$pdo->prepare(
    "INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost, circle, math_fingerprint, clan_id, summary,
                         damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                         has_verbal, has_somatic, has_material, status, signatures_count, created_at, updated_at)
     VALUES (:id, 'validado-arnes', 'Validado del Arnés', 'usr_autor', 'evocation', 48, 3, :fingerprint, 'cln_astral', 'Resumen.',
             30, 0, 0, 'none', 'medium', 'sphere', 'instant', 1, 1, 0, 'validated', 3, '{$now}', '{$now}')"
)->execute([':id' => $validatedId, ':fingerprint' => str_repeat('c', 64)]);

$immutableAttempt = catchException(
    static fn () => $service->updateExperimental($author, $validatedId, forgeCreateDto('Validado del Arnés'))
);
assertArcane($immutableAttempt instanceof SpellImmutableException, 'Editar un conjuro validado lanza SpellImmutableException (403)');
$rowValidated = fetchRow($pdo, $validatedId);
assertArcane((int) $rowValidated['signatures_count'] === 3 && $rowValidated['status'] === 'validated', 'El validado permanece intocable (3 firmas, estado validated)');

// =====================================================================
// [5] Titularidad e inexistencia.
// =====================================================================
echo "\n[5] Titularidad e inexistencia\n";

$foreignUser = new User('usr_ajeno', 'AjenoExperimental', 'ajeno@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);
$foreignAttempt = catchException(
    static fn () => $service->updateExperimental($foreignUser, $fraudId, forgeCreateDto('Robo de Esfera'))
);
assertArcane($foreignAttempt instanceof RuntimeException, 'Un autor ajeno no puede actualizar el experimental ajeno');

$ghostAttempt = catchException(
    static fn () => $service->updateExperimental($author, 'spl_fantasma', forgeCreateDto('Fantasma'))
);
assertArcane($ghostAttempt instanceof RuntimeException, 'Actualizar un identificador inexistente se rechaza');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

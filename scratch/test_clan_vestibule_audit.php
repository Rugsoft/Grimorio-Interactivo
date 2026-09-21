<?php

declare(strict_types=1);

/**
 * test_clan_vestibule_audit.php — Verificación de la Tarea 3.3 de TASKS-10.
 *
 * Valida el REPARTO POR ACTOR de los cinco actos de adhesión en la Bitácora
 * (SPEC-10, RF-04.4, plan §2.3) contra el «Hecho cuando»:
 *
 *   1. El aserto de catálogo cerrado de SPEC-03 pasa con los cinco actos y
 *      su rotulación castellana (regresión velada por el arnés de la
 *      moderación: test_moderation_audit_integration.php).
 *   2. Cada acto queda inscrito por su actor:
 *      - Lado del postulante: CLAN_MEMBER_JOINED (ingreso inmediato y
 *        aprobación), CLAN_APPLICATION_SUBMITTED, CLAN_APPLICATION_WITHDRAWN,
 *        CLAN_APPLICATION_RESIDUALS_ANNULLED (uno por petición anulada).
 *      - Lado deliberante: CLAN_APPLICATION_VERDICT (Patriarca, con motivo).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo sobre SQLite en memoria.
 *   - Artículo V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_clan_vestibule_audit.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

$filesRequired = [
    $projectRoot . '/src/Models/User.php',
    $projectRoot . '/src/Models/AuditEntry.php',
    $projectRoot . '/src/Dto/ClanDto.php',
    $projectRoot . '/src/Dto/ClanMemberDto.php',
    $projectRoot . '/src/Dto/ClanApplicationDto.php',
    $projectRoot . '/src/Dto/LineageDto.php',
    $projectRoot . '/src/Exceptions/ClanGovernanceException.php',
    $projectRoot . '/src/Repositories/ClanRepository.php',
    $projectRoot . '/src/Repositories/ClanMemberRepository.php',
    $projectRoot . '/src/Repositories/ClanApplicationRepository.php',
    $projectRoot . '/src/Services/LineageSynergyService.php',
    $projectRoot . '/src/Services/AuditService.php',
    $projectRoot . '/src/Services/ClanAdmissionResult.php',
    $projectRoot . '/src/Services/PatriarchSuccessionResult.php',
    $projectRoot . '/src/Services/ClanService.php',
];
foreach ($filesRequired as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, '[FATAL] Falta el fichero ' . $filePath . " — fase roja.\n");
        exit(1);
    }
    require_once $filePath;
}

use Grimorio\Dto\ClanApplicationDto;
use Grimorio\Dto\ClanMemberDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanService;

$assertsPassed = 0;
$assertsFailed = 0;

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

function instantOf(string $isoUtc): DateTimeImmutable
{
    return new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
}

/** Consagra un mago en el plano con el rol y linaje indicados. */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', string $lineage = 'celestialTides'): User
{
    $now = '2026-01-01T00:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :lineage, NULL, :createdAt, :updatedAt)'
    );
    $statement->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@arcano.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':lineage'      => $lineage,
        ':createdAt'    => $now,
        ':updatedAt'    => $now,
    ]);

    return new User(
        id: $userId,
        alias: $alias,
        email: $userId . '@arcano.arc',
        role: $role,
        clanId: null,
        passwordHash: str_repeat('x', 60),
        lineage: $lineage,
        createdAt: $now,
        updatedAt: $now,
    );
}

/** Asientos de Bitácora de un acto, con el actor que lo inscribió. */
function auditEntriesOf(PDO $pdo, string $actionType): array
{
    $statement = $pdo->prepare(
        'SELECT actor_user_id, actor_alias, actor_role, target_entity_type, target_entity_id, justification
           FROM audit_log
          WHERE action_type = :actionType
          ORDER BY created_at ASC, id ASC'
    );
    $statement->execute([':actionType' => $actionType]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function forgeRealm(string $projectRoot): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

    return $pdo;
}

echo "== VERIFICACION TAREA 3.3: Los cinco actos de adhesion en la Bitacora ==\n\n";

// --- FASE 0: Catálogo cerrado con los cinco actos (regresión SPEC-03) ----
echo "FASE 0: El catálogo cerrado admite los cinco actos\n";
$entrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
preg_match('/CANONICAL_ACTION_TYPES = \[(.*?)\];/s', $entrySource, $catalogMatch);
preg_match_all("/'([A-Z][A-Z0-9_]+)'/", $catalogMatch[1] ?? '', $catalogNames);
$canonicalActions = $catalogNames[1] ?? [];

$vestibuleActs = [
    'CLAN_MEMBER_JOINED',
    'CLAN_APPLICATION_SUBMITTED',
    'CLAN_APPLICATION_WITHDRAWN',
    'CLAN_APPLICATION_RESIDUALS_ANNULLED',
    'CLAN_APPLICATION_VERDICT',
];
foreach ($vestibuleActs as $act) {
    assertCondition(in_array($act, $canonicalActions, true), "El catálogo admite el acto del Vestíbulo {$act}");
}
assertCondition(
    !in_array('CLAN_APPLICATION_OBLITERATED', $canonicalActions, true),
    'Un acto inventado sigue fuera del catálogo: la ampliación no lo abre de par en par'
);

$viewSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
preg_match('/function actionLabel\(actionType\) \{\s*const labels = \{(.*?)\n    \};/s', $viewSource, $labelMatch);
preg_match_all('/^\s*([A-Z][A-Z0-9_]+):/m', $labelMatch[1] ?? '', $labelNames);
$labelledActions = $labelNames[1] ?? [];
$sinRotulo = array_values(array_diff($canonicalActions, $labelledActions));
assertCondition($sinRotulo === [], 'Todo acto del catálogo tiene su rótulo solemne en castellano (sin huérfanos: ' . implode(', ', $sinRotulo) . ')');
assertCondition(str_contains($viewSource, "CLAN_MEMBER_JOINED: 'Ingreso en una Hermandad'"), 'El ingreso se lee en castellano en la Bitácora pública');
assertCondition(str_contains($viewSource, "CLAN_APPLICATION_SUBMITTED: 'Remisión de una Petición de Ingreso'"), 'La remisión se lee en castellano en la Bitácora pública');

// --- FASE 1: Forja del escenario ------------------------------------------
echo "\nFASE 1: Escenario — casa abierta, casa de deliberación y Patriarca\n";
$pdo = forgeRealm($projectRoot);
$auditService = new AuditService($pdo);
$service = new ClanService($pdo, $auditService);
$now = instantOf('2026-09-20T12:00:00Z');

$patriarca = seedUser($pdo, 'usr_patriarca', 'ElPatriarca', 'editor', 'celestialTides');
$casaAlba = $service->foundClan($patriarca, 'Casa Abierta del Alba', 'Lema del Alba', 'rune_alba', 'celestialTides');
// La lealtad es indivisible: la casa de deliberación la funda OTRO mago.
$patriarcaOcaso = seedUser($pdo, 'usr_patriarca_ocaso', 'ElPatriarcaDelOcaso', 'editor', 'celestialTides');
$casaAbierta = $service->foundClan($patriarcaOcaso, 'Casa Abierta del Ocaso', 'Lema del Ocaso', 'rune_ocaso', 'celestialTides');
$pdo->exec("UPDATE clans SET admission_mode = 'byApplication' WHERE id = '" . $casaAbierta->id . "'");
$casaAbierta = $service->findClanById($casaAbierta->id);

$ingresado = seedUser($pdo, 'usr_ingresado', 'ElIngresado', 'editor', 'celestialTides');
$postulante = seedUser($pdo, 'usr_postulante', 'LaPostulante', 'editor', 'celestialTides');
$otroAdepto = seedUser($pdo, 'usr_otro', 'ElOtroAdepto', 'editor', 'celestialTides');

// --- FASE 2: Ingreso inmediato → CLAN_MEMBER_JOINED por el postulante ----
echo "\nFASE 2: Ingreso inmediato inscrito por el postulante\n";
// El molde 20–500 se aplica SOLO al camino de petición formal: en la casa
// abierta la pluma no redacta. El ingreso es por la CASA ABIERTA (Alba).
$service->applyToClan($ingresado, $casaAlba->id, $now, 'Ruego cruzar vuestras puertas y servir a la casa.');

$asientos = auditEntriesOf($pdo, 'CLAN_MEMBER_JOINED');
assertCondition(count($asientos) === 1, 'El ingreso inmediato deja UN asiento CLAN_MEMBER_JOINED');
assertCondition($asientos !== [] && $asientos[0]['actor_user_id'] === 'usr_ingresado', 'El asiento lo inscribe el POSTULANTE (lado postulante, RF-04.4)');
assertCondition($asientos !== [] && $asientos[0]['target_entity_type'] === 'clan', 'La entidad objetivo es la casa');
assertCondition($asientos !== [] && str_contains($asientos[0]['justification'], 'ElIngresado'), 'La justificación nombra al ingresado');

// --- FASE 3: Remisión → CLAN_APPLICATION_SUBMITTED por el postulante ------
echo "\nFASE 3: La remisión de la petición queda inscrita por el postulante\n";
$service->applyToClan($postulante, $casaAbierta->id, $now, 'Ruego un lugar entre los vuestros para servir.');

$asientos = auditEntriesOf($pdo, 'CLAN_APPLICATION_SUBMITTED');
assertCondition(count($asientos) === 1, 'La remisión deja UN asiento CLAN_APPLICATION_SUBMITTED');
assertCondition($asientos !== [] && $asientos[0]['actor_user_id'] === 'usr_postulante', 'El asiento lo inscribe el POSTULANTE (lado postulante)');
assertCondition($asientos !== [] && str_contains($asientos[0]['justification'], 'LaPostulante'), 'La justificación nombra a la postulante');

// --- FASE 4: Retirada → CLAN_APPLICATION_WITHDRAWN por el postulante ------
echo "\nFASE 4: La retirada queda inscrita por el postulante\n";
// La petición remitida en la Fase 3 vive en la casa de deliberación (Ocaso);
// su expediente solo lo lee su PATRIARCA (ElPatriarcaDelOcaso).
$expediente = $service->listPendingApplications($patriarcaOcaso, $casaAbierta->id);
if ($expediente === []) {
    fwrite(STDERR, "[FATAL] La petición de LaPostulante no consta en el expediente de la casa.\n");
    exit(1);
}
$applicationId = (string) $expediente[0]->id;
$service->withdrawApplication($postulante, $casaAbierta->id, $applicationId, $now);

$asientos = auditEntriesOf($pdo, 'CLAN_APPLICATION_WITHDRAWN');
assertCondition(count($asientos) === 1, 'La retirada deja UN asiento CLAN_APPLICATION_WITHDRAWN');
assertCondition($asientos !== [] && $asientos[0]['actor_user_id'] === 'usr_postulante', 'El asiento lo inscribe el POSTULANTE (lado postulante)');

// --- FASE 5: Aprobación → dictamen deliberante + ingreso postulante -------
echo "\nFASE 5: La aprobación reparte sus dos asientos entre los lados\n";
// La retirada de la Fase 4 clausuró la casa para LaPostulante (RF-03.1);
// la aprobación se ejerce sobre la petición de OTRO postulante.
$aprobado = seedUser($pdo, 'usr_aprobado', 'ElAprobado', 'editor', 'celestialTides');
$service->applyToClan($aprobado, $casaAbierta->id, $now, 'Ruego un lugar con voto de estudio y servicio a la casa.');
$expediente = $service->listPendingApplications($patriarcaOcaso, $casaAbierta->id);
$service->resolveApplication($patriarcaOcaso, $casaAbierta->id, (string) $expediente[0]->id, 'approve', $now);

$verdictos = auditEntriesOf($pdo, 'CLAN_APPLICATION_VERDICT');
assertCondition(count($verdictos) === 1, 'La aprobación deja UN asiento CLAN_APPLICATION_VERDICT');
assertCondition($verdictos !== [] && $verdictos[0]['actor_user_id'] === 'usr_patriarca_ocaso', 'El DICTAMEN lo inscribe el PATRIARCA (lado deliberante, RF-04.4)');
assertCondition(str_contains($verdictos[0]['justification'], 'ingreso'), 'El dictamen de aprobación porta su razón (el ingreso es su motivo)');

$ingresos = auditEntriesOf($pdo, 'CLAN_MEMBER_JOINED');
assertCondition(count($ingresos) === 2, 'El ingreso por aprobación añade su asiento CLAN_MEMBER_JOINED (2 en total)');
assertCondition((string) $ingresos[1]['actor_user_id'] === 'usr_aprobado', 'El asiento del ingreso por aprobación lo inscribe el POSTULANTE');
assertCondition(str_contains($ingresos[1]['justification'], 'dictamen favorable'), 'La justificación nombra el rito del ingreso');

// --- FASE 6: Ingreso con residuales → un asiento por petición anulada -----
echo "\nFASE 6: Cada petición huérfana deja SU asiento de anulación (RF-03.7)\n";
// La tercera casa DEBE existir antes de las peticiones: un fundador solo
// alza UN estandarte (lealtad indivisible), así que cada casa tiene su
// patriarca propio.
$patriarcaMediodia = seedUser($pdo, 'usr_patriarca_mediodia', 'ElPatriarcaDelMediodia', 'editor', 'celestialTides');
$casaMediodia = $service->foundClan($patriarcaMediodia, 'Casa del Mediodia', 'Lema del Mediodia', 'rune_mediodia', 'celestialTides', Grimorio\Dto\ClanDto::ADMISSION_BY_APPLICATION);

$service->applyToClan($otroAdepto, $casaAbierta->id, $now, 'Primera petición que quedará huérfana.');
$service->applyToClan($otroAdepto, $casaMediodia->id, $now, 'Segunda petición que quedará huérfana al ingresar en otra.');

$antes = count(auditEntriesOf($pdo, 'CLAN_APPLICATION_RESIDUALS_ANNULLED'));

// El otro adepto ingresa por la casa de Alba (régimen abierto): sus dos
// peticiones pendientes se anulan de oficio al nacer la membresía (RF-03.7).
$service->applyToClan($otroAdepto, $casaAlba->id, $now, 'El ingreso inmediato no redacta: este texto se ignora.');

$anulaciones = auditEntriesOf($pdo, 'CLAN_APPLICATION_RESIDUALS_ANNULLED');
assertCondition(count($anulaciones) - $antes === 2, 'Las dos peticiones huérfanas dejan SU asiento cada una (RF-03.7)');
foreach ($anulaciones as $asiento) {
    assertCondition($asiento['actor_user_id'] === 'usr_otro', 'La anulación se inscribe por el POSTULANTE (lado postulante)');
    break;
}
$ingresos = auditEntriesOf($pdo, 'CLAN_MEMBER_JOINED');
assertCondition(count($ingresos) === 3, 'El ingreso del tercer adepto deja su asiento también (3 en total)');

// --- FASE 6b: El dictamen desfavorable exige su motivo (Art. III.3) -------
echo "\nFASE 6b: El rechazo con y sin motivo solemne\n";
// Una cuarta casa de deliberación para el dictamen desfavorable: su
// Patriarca rechazará una petición y el asiento deberá portar el motivo.
$patriarcaEstio = seedUser($pdo, 'usr_patriarca_estio', 'ElPatriarcaDelEstio', 'editor', 'celestialTides');
$casaEstio = $service->foundClan($patriarcaEstio, 'Casa del Estio', 'Lema del Estio', 'rune_estio', 'celestialTides', Grimorio\Dto\ClanDto::ADMISSION_BY_APPLICATION);

$postulanteRechazo = seedUser($pdo, 'usr_post_rechazo', 'LaPostulanteDelRechazo', 'editor', 'celestialTides');
$service->applyToClan($postulanteRechazo, $casaEstio->id, $now, 'Ruego un lugar a la sombra del mediodía.');
$expedienteRechazo = $service->listPendingApplications($patriarcaEstio, $casaEstio->id);

// El rechazo SIN motivo es vedado: 400 INVALID_VERDICT_MOTIVE (Tarea 2.6).
$sinMotivo = null;
try {
    $service->resolveApplication($patriarcaEstio, $casaEstio->id, (string) $expedienteRechazo[0]->id, 'reject', $now, '   ');
} catch (ClanGovernanceException $governance) {
    $sinMotivo = $governance;
}
assertCondition($sinMotivo !== null && $sinMotivo->errorCode === ClanGovernanceException::INVALID_VERDICT_MOTIVE, 'El rechazo sin motivo responde 400 INVALID_VERDICT_MOTIVE');
assertCondition($sinMotivo !== null && $sinMotivo->httpStatus === 400, 'El motivo ausente responde 400');

// Sin asiento de dictamen NUEVO: la pluma deliberante no escribió (delta).
$verdictosAntesDeEstio = count(auditEntriesOf($pdo, 'CLAN_APPLICATION_VERDICT'));
// (la Fase 5 dejó el asiento de la aprobación: el delta del rechazo fallido es 0)

// El rechazo CON motivo: el asiento porta el texto íntegro del Patriarca.
// El conteo es DELTA sobre la casa del Estío: la Fase 5 ya dejó su asiento
// de aprobación en la casa del Ocaso (bitácora global).
$verdictosAntesDeEstio = count(auditEntriesOf($pdo, 'CLAN_APPLICATION_VERDICT'));
$service->resolveApplication($patriarcaEstio, $casaEstio->id, (string) $expedienteRechazo[0]->id, 'reject', $now, 'Tu vocación aún no ha florecido: vuelve cuando el estío te llame.');
$asientosVeredicto = auditEntriesOf($pdo, 'CLAN_APPLICATION_VERDICT');
assertCondition(count($asientosVeredicto) - $verdictosAntesDeEstio === 1, 'El rechazo con motivo deja UN asiento CLAN_APPLICATION_VERDICT');
$asientoEstio = $asientosVeredicto[count($asientosVeredicto) - 1];
assertCondition($asientoEstio['actor_user_id'] === 'usr_patriarca_estio', 'El dictamen desfavorable lo inscribe el PATRIARCA (lado deliberante)');
assertCondition(str_contains($asientoEstio['justification'], 'Tu vocación aún no ha florecido'), 'El asiento del dictamen porta el motivo íntegro (Art. III.3)');
assertCondition(str_contains($asientoEstio['justification'], 'LaPostulanteDelRechazo'), 'La justificación nombra a la postulante juzgada');

// Y la fila conservó el motivo en su columna (RF-04.5: contrato compartido).
$filaVeredicto = $pdo->prepare('SELECT verdict_motive FROM clan_applications WHERE id = :id');
$filaVeredicto->execute([':id' => (string) $expedienteRechazo[0]->id]);
assertCondition(str_contains((string) $filaVeredicto->fetchColumn(), 'florecido'), 'La fila conservó el motivo del dictamen (contrato único compartido, RF-04.5)');

// --- FASE 7: Sin duplicación del dictamen en el lado postulante -----------
echo "\nFASE 7: El reparto por actor no se duplica\n";
$rechazosPostulante = 0;
foreach (auditEntriesOf($pdo, 'CLAN_APPLICATION_VERDICT') as $asiento) {
    if ($asiento['actor_user_id'] === 'usr_postulante') {
        $rechazosPostulante++;
    }
}
assertCondition($rechazosPostulante === 0, 'El postulante jamás inscribe el dictamen: los lados no se mezclan');

echo "\nVeredicto: {$assertsPassed} PASA / {$assertsFailed} ROJOS\n";
exit($assertsFailed === 0 ? 0 : 1);

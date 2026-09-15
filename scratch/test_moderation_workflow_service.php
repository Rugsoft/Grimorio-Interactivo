<?php

declare(strict_types=1);

/**
 * test_moderation_workflow_service.php — Verificación de la Tarea 2.3 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Un 4º envío simultáneo es rechazado, retirar a borrador revoca todas las
 *    firmas previas, y `reopenAsDraft` devuelve el conjuro a `draft` liberando
 *    el cupo del autor.»
 *
 * Estrategia: se levanta una base SQLite efímera —en el directorio temporal del
 * sistema, jamás dentro del repositorio— con la secuencia canónica y se ejerce
 * el ciclo de vida completo sobre el expediente real. El cerrojo del cupo se
 * prueba con un SEGUNDO escritor que retiene el bloqueo de la fila del autor;
 * la atomicidad, sellando la bitácora con un disparador temporal.
 *
 * Fases:
 *   [0]  Superficie del servicio y de su excepción de dominio.
 *   [1]  El cupo de tres obras y su liberación (RF-01.2, RF-01.5, RNF-04).
 *   [2]  Elevación a deliberación con la huella del backend (RF-01.2, Art. II).
 *   [3]  Retirada a la libreta con anulación irrevocable de avales (RF-01.3).
 *   [4]  Re-apertura de una obra vetada con su dictamen a la vista (RF-01.4).
 *   [5]  Caducidad por letargo de noventa días (RF-01.6).
 *   [6]  Inmutabilidad de lo evaluado y guardián de edición (RF-01.3).
 *   [7]  Atomicidad y cerrojo del cupo ante escritores simultáneos.
 *   [8]  Auditoría estática, cruce de catálogos y Dogma Vanilla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_moderation_workflow_service.php
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

/** ¿Lanza este cierre de excepción? Devuelve la clase y el mensaje, o null. */
function captureError(callable $operation): ?string
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure::class . ': ' . $failure->getMessage();
    }
}

/**
 * Captura el VEREDICTO de una operación del flujo: su código canónico, su
 * estado HTTP y su leyenda. Es la forma de comprobar el contrato sin leer
 * mensajes de prosa.
 *
 * @return array{code: string, status: int, message: string}|null
 */
function captureVerdict(callable $operation): ?array
{
    try {
        $operation();

        return null;
    } catch (Grimorio\Exceptions\ModerationWorkflowException $verdict) {
        return [
            'code'    => $verdict->errorCode,
            'status'  => $verdict->httpStatus,
            'message' => $verdict->getMessage(),
        ];
    } catch (Throwable $failure) {
        return ['code' => $failure::class, 'status' => 0, 'message' => $failure->getMessage()];
    }
}

echo "== VERIFICACION TAREA 2.3: Flujo de estados y cupos de la Torre ==\n\n";

$projectRoot = dirname(__DIR__);
$servicePath = $projectRoot . '/src/Services/ModerationWorkflowService.php';
$exceptionPath = $projectRoot . '/src/Exceptions/ModerationWorkflowException.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del servicio y de su excepcion\n";
assertCondition(file_exists($servicePath), 'Existe src/Services/ModerationWorkflowService.php');
assertCondition(file_exists($exceptionPath), 'Existe src/Exceptions/ModerationWorkflowException.php');

if (!file_exists($servicePath) || !file_exists($exceptionPath)) {
    echo "\nRESULTADO: FALLO — faltan los ficheros de la Tarea 2.3 (fase roja del TDD).\n";
    exit(1);
}

$serviceSource = (string) file_get_contents($servicePath);
$serviceHead = implode('', array_slice(file($servicePath, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
assertCondition(str_contains($serviceHead, 'declare(strict_types=1);'), 'Declara strict_types en las primeras 40 lineas (AGENTS.md)');
assertCondition(str_contains($serviceSource, 'namespace Grimorio\\Services;'), 'Habita el espacio de nombres Grimorio\\Services');
assertCondition(
    preg_match('#^use (?!Grimorio)[A-Z]#m', $serviceSource) === 1
    && preg_match('#https?://#', $serviceSource) !== 1,
    'Solo depende de la biblioteca estandar de PHP: cero dependencias externas (Articulo I)'
);

foreach ([
    'submitToModeration',
    'withdrawToDraft',
    'reopenAsDraft',
    'checkExpiryCron',
] as $methodName) {
    assertCondition(str_contains($serviceSource, "function {$methodName}("), "Publica la operacion canonica {$methodName}()");
}
assertCondition(
    str_contains($serviceSource, 'MAX_CONCURRENT_REVIEWS = 3') && str_contains($serviceSource, 'STALE_REVIEW_DAYS = 90'),
    'Publica el cupo de tres y el letargo de noventa dias como fuente unica del canon'
);

$exceptionSource = (string) file_get_contents($exceptionPath);
foreach ([
    'TOWER_CAPACITY_EXCEEDED',
    'SPELL_NOT_IN_DRAFT',
    'SPELL_NOT_UNDER_REVIEW',
    'SPELL_NOT_REJECTED',
    'INSUFFICIENT_RANK',
    'CONVALESCENCE_ACTIVE',
    'UNDER_REVIEW_IMMUTABLE',
    'SPELL_AWAITING_REOPEN',
] as $errorCode) {
    assertCondition(str_contains($exceptionSource, $errorCode), "El contrato declara el codigo {$errorCode}");
}

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Exceptions/ModerationWorkflowException.php';
require_once $projectRoot . '/src/Exceptions/SpellNotFoundException.php';
require_once $projectRoot . '/src/Exceptions/SpellImmutableException.php';
require_once $projectRoot . '/src/Exceptions/DraftQuotaExceededException.php';
require_once $projectRoot . '/src/Dto/SpellCalculationInputDto.php';
require_once $projectRoot . '/src/Dto/SpellCalculationResultDto.php';
require_once $projectRoot . '/src/Dto/SpellCreateDto.php';
require_once $projectRoot . '/src/Dto/SpellReviewDto.php';
require_once $projectRoot . '/src/Dto/MasterSignatureDto.php';
require_once $projectRoot . '/src/Dto/ObjectionVerdictDto.php';
require_once $projectRoot . '/src/Services/SpellBalanceService.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Services/ClanEthicsValidator.php';
require_once $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
require_once $projectRoot . '/src/Repositories/ObjectionVerdictRepository.php';
require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Services/SpellManagementService.php';
require_once $servicePath;

$serviceClass = 'Grimorio\\Services\\ModerationWorkflowService';
$exceptionClass = 'Grimorio\\Exceptions\\ModerationWorkflowException';
$reviewClass = 'Grimorio\\Repositories\\SpellReviewRepository';

$databasePath = sys_get_temp_dir() . '/grimorio_moderation_workflow_' . getmypid() . '.sqlite';
@unlink($databasePath);
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 1);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

/** @var ModerationWorkflowService $service */
$service = new $serviceClass($pdo);
$balanceService = new Grimorio\Services\SpellBalanceService();

$NOW = new DateTimeImmutable('2026-09-15T10:00:00Z');
$NOW_UTC = '2026-09-15T10:00:00Z';
$FINGERPRINT = str_repeat('a', 64);

/**
 * Parámetros cuantitativos canónicos de las obras de prueba. Una obra sin
 * efectos carece de forma arcana (el motor lo rechaza), así que todas las
 * forjadas aquí comparten esta forma, y la misma se usa para calcular la
 * huella que el backend DEBE sellar.
 */
$DAMAGE = 20;
$RANGE = 'short';
$HAS_VERBAL = true;

$canonicalInput = new Grimorio\Dto\SpellCalculationInputDto(
    damage: $DAMAGE,
    healing: 0,
    barrier: 0,
    crowdControlType: 'none',
    rangeType: $RANGE,
    areaType: 'singleTarget',
    durationType: 'instant',
    hasVerbal: $HAS_VERBAL,
    hasSomatic: false,
    hasMaterial: false,
);
$canonicalCalculation = $balanceService->calculate($canonicalInput);
$canonicalFingerprint = $balanceService->computeMathFingerprint($canonicalInput);

/** Inscribe un mago con su rango tecnico. */
$forgeUser = static function (PDO $pdo, string $userId, string $alias, string $role, ?string $clanId, string $createdAt): void {
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :hash, :role, :clanId, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $userId,
        ':alias'     => $alias,
        ':email'     => $userId . '@arcano.arc',
        ':hash'      => 'x',
        ':role'      => $role,
        ':clanId'    => $clanId,
        ':createdAt' => $createdAt,
    ]);
};

/** Inscribe un conjuro con sus parametros cuantitativos por omision. */
$forgeSpell = static function (
    PDO $pdo,
    string $spellId,
    string $authorId,
    string $clanId,
    string $status,
    int $manaCost,
    string $fingerprint,
    string $createdAt,
) use ($DAMAGE, $RANGE, $HAS_VERBAL): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, :manaCost, 1,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, :status, 0,
                 :damage, :rangeType, :hasVerbal)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':affinity'    => 'fire',
        ':manaCost'    => $manaCost,
        ':fingerprint' => $fingerprint,
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba del flujo de moderacion.',
        ':createdAt'   => $createdAt,
        ':status'      => $status,
        ':damage'      => $DAMAGE,
        ':rangeType'   => $RANGE,
        ':hasVerbal'   => $HAS_VERBAL ? 1 : 0,
    ]);
};

/** Inscribe un expediente de moderacion y sincroniza el espejo. */
$forgeReview = static function (
    PDO $pdo,
    string $reviewId,
    string $spellId,
    string $authorId,
    string $status,
    int $count,
    ?string $clanId,
    ?string $submittedAt,
) use ($FINGERPRINT): void {
    $statement = $pdo->prepare(
        'INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at, rejected_at)
         VALUES (:id, :spellId, :authorId, :clanId, :status, :count, :fingerprint, :submittedAt, :rejectedAt)'
    );
    $statement->execute([
        ':id'          => $reviewId,
        ':spellId'     => $spellId,
        ':authorId'    => $authorId,
        ':clanId'      => $clanId,
        ':status'      => $status,
        ':count'       => $count,
        ':fingerprint' => $FINGERPRINT,
        ':submittedAt' => $submittedAt,
        ':rejectedAt'  => $status === 'rejected' ? $submittedAt : null,
    ]);

    $mirror = $pdo->prepare('UPDATE spells SET status = :status, signatures_count = :count WHERE id = :spellId');
    $mirror->execute([':status' => $status, ':count' => $count, ':spellId' => $spellId]);
};

/** Estampa una firma de Maestro. */
$forgeSignature = static function (PDO $pdo, string $signatureId, string $spellId, string $masterId, ?string $clanId, string $signedAt, int $isRevoked = 0): void {
    $statement = $pdo->prepare(
        'INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at, is_revoked)
         VALUES (:id, :spellId, :masterId, :clanId, NULL, :signedAt, :isRevoked)'
    );
    $statement->execute([
        ':id'       => $signatureId,
        ':spellId'  => $spellId,
        ':masterId' => $masterId,
        ':clanId'   => $clanId,
        ':signedAt' => $signedAt,
        ':isRevoked' => $isRevoked,
    ]);
};

/** Lee un unico valor escalar de la base. */
$scalar = static function (PDO $pdo, string $sql, array $parameters = []): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
};

/** Cuenta entradas de la bitacora por acto y objetivo. */
$auditCount = static function (PDO $pdo, string $actionType, string $spellId) use ($scalar): int {
    return (int) $scalar(
        $pdo,
        'SELECT COUNT(*) FROM audit_log WHERE action_type = :actionType AND target_entity_id = :spellId',
        [':actionType' => $actionType, ':spellId' => $spellId],
    );
};

$CLAN_ROOT = 'cln_primordial';
$CLAN_TEMPEST = 'cln_tempestad';

$notice = $pdo->prepare(
    'INSERT INTO clans (id, slug, name, created_at, coat_of_arms, last_activity_at, updated_at)
     VALUES (:id, :slug, :name, :createdAt, :arms, :createdAt, :createdAt)'
);
$notice->execute([
    ':id'        => $CLAN_TEMPEST,
    ':slug'      => 'tempestad-eterna',
    ':name'      => 'Tempestad Eterna',
    ':createdAt' => '2026-01-02T00:00:00Z',
    ':arms'      => 'rune_tempestad',
]);

$forgeUser($pdo, 'usr_autora', 'Autora Primordial', 'editor', $CLAN_ROOT, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_lector', 'Lector Anonimo', 'reader', null, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_convaleciente', 'Editora Convaleciente', 'editor', null, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_ajena', 'Editora de la Tempestad', 'editor', $CLAN_TEMPEST, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_magistrado', 'Maestro Magistrado', 'master', null, '2026-01-03T00:00:00Z');

// La Editora Convaleciente purga sus catorce días: la pluma calla (plan 2.2).
$convalescence = $pdo->prepare(
    'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
     VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :expiresAt)'
);
$convalescence->execute([
    ':id'        => 'clm_convaleciente',
    ':clanId'    => $CLAN_TEMPEST,
    ':userId'    => 'usr_convaleciente',
    ':role'      => 'adept',
    ':joinedAt'  => '2026-02-01T00:00:00Z',
    ':leftAt'    => '2026-09-10T10:00:00Z',
    ':expiresAt' => '2026-09-24T10:00:00Z',
]);

// --- FASE 1: El cupo de tres obras y su liberacion ---
echo "\nFASE 1: El cupo de tres obras y su liberacion (RF-01.2, RF-01.5, RNF-04)\n";
assertCondition($service->remainingCapacity('usr_autora') === 3, 'Sin obras en la Torre, el aforo del autor esta entero');
assertCondition($service->hasReviewCapacity('usr_autora') === true, 'El autor puede elevar su primera plegaria');

$forgeSpell($pdo, 'spl_borrador', 'usr_autora', $CLAN_ROOT, 'draft', 200, $FINGERPRINT, $NOW_UTC);

$submission = $service->submitToModeration('spl_borrador', 'usr_autora', $NOW);
assertCondition($submission->status === 'experimental', 'La obra elevada pasa a deliberacion (RF-01.2)');
assertCondition($submission->signaturesCount === 0 && $submission->signaturesIndicator() === '0/3', 'El contador de firmas arranca en 0/3');
assertCondition($submission->originClanId === $CLAN_ROOT, 'El expediente conserva el clan patrimonial de la obra');
assertCondition($service->remainingCapacity('usr_autora') === 2, 'La cupo del autor se consume obra a obra');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_borrador'") === 'experimental', 'El espejo del conjuro sigue a la autoridad al elevarse');

// Tres obras en deliberacion colman la Torre.
$forgeSpell($pdo, 'spl_dos', 'usr_autora', $CLAN_ROOT, 'experimental', 30, $FINGERPRINT, $NOW_UTC);
$forgeSpell($pdo, 'spl_tres', 'usr_autora', $CLAN_ROOT, 'experimental', 30, $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_dos', 'spl_dos', 'usr_autora', 'experimental', 0, $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_tres', 'spl_tres', 'usr_autora', 'experimental', 0, $CLAN_ROOT, $NOW_UTC);
assertCondition($service->remainingCapacity('usr_autora') === 0, 'Tres obras en deliberacion agotan el aforo');
assertCondition($service->hasReviewCapacity('usr_autora') === false, 'La Torre ya no admite una cuarta obra de ese autor');

$forgeSpell($pdo, 'spl_cuarta', 'usr_autora', $CLAN_ROOT, 'draft', 20, $FINGERPRINT, $NOW_UTC);
$rejection = captureVerdict(static fn () => $service->submitToModeration('spl_cuarta', 'usr_autora', $NOW));
assertCondition(($rejection['code'] ?? '') === 'TOWER_CAPACITY_EXCEEDED', 'El cuarto envio es rechazado con el codigo del contrato');
assertCondition(($rejection['status'] ?? 0) === 409, 'El cupo colmado responde 409 Conflict (plan 2.2)');
assertCondition(str_contains((string) ($rejection['message'] ?? ''), 'La Torre de Moderación ya custodia 3'), 'La leyenda ceremonial nombra el cupo de tres (RF-01.2)');
assertCondition((int) $scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_cuarta'") === 0, 'El rechazo no dejo expediente alguno');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_cuarta'") === 'draft', 'La obra rechazada permanece en la libreta, intacta');

// RF-01.5: el estado `rejected` libera el cupo de inmediato.
$pdo->prepare("UPDATE spell_reviews SET status = 'rejected' WHERE spell_id = 'spl_dos'")->execute();
assertCondition($service->remainingCapacity('usr_autora') === 1, 'Una obra vetada libera su plaza de inmediato (RF-01.5)');
$secondSubmission = $service->submitToModeration('spl_cuarta', 'usr_autora', $NOW);
assertCondition($secondSubmission->status === 'experimental', 'Con plaza libre, la misma obra se eleva sin estorbo');
assertCondition($service->remainingCapacity('usr_autora') === 0, 'La plaza liberada vuelve a ocuparse');

// La obra consagrada tampoco consume cupo: el cupo cuenta SOLO deliberacion.
$pdo->prepare("UPDATE spell_reviews SET status = 'validated', signatures_count = 3 WHERE spell_id = 'spl_tres'")->execute();
assertCondition($service->remainingCapacity('usr_autora') === 1, 'Una consagracion libera su plaza: solo la deliberacion ocupa cupo (RF-01.5)');

// --- FASE 2: Elevacion con la huella del backend (Art. II) ---
echo "\nFASE 2: Elevacion con la huella y el mana del backend (Art. II)\n";
$forgeSpell($pdo, 'spl_amanada', 'usr_autora', $CLAN_ROOT, 'draft', 200, $FINGERPRINT, $NOW_UTC);
$published = $service->submitToModeration('spl_amanada', 'usr_autora', $NOW);
assertCondition(
    (int) $scalar($pdo, "SELECT mana_cost FROM spells WHERE id = 'spl_amanada'") === $canonicalCalculation->finalManaCost,
    'El mana publicado es el que dicta el motor, no el que traia el borrador (Art. II)'
);
assertCondition($published->mathFingerprint === $canonicalFingerprint, 'La huella sellada es la del backend, ciega al cliente');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_amanada'") === 0, 'El espejo del conjuro nace en cero firmas');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_amanada'") === 'experimental', 'El espejo sigue a la autoridad (Tarea 1.5)');
assertCondition($auditCount($pdo, 'MODERATION_SUBMITTED', 'spl_amanada') === 1, 'La elevacion queda inscrita en la Bitacora (RF-06.1)');
assertCondition((string) $scalar($pdo, "SELECT submitted_at FROM spell_reviews WHERE spell_id = 'spl_amanada'") === $NOW_UTC, 'El instante de entrada es el inyectado');

$doubleSubmission = captureVerdict(static fn () => $service->submitToModeration('spl_amanada', 'usr_autora', $NOW));
assertCondition(($doubleSubmission['code'] ?? '') === 'SPELL_NOT_IN_DRAFT', 'Una obra ya en deliberacion no se eleva dos veces');
assertCondition(($doubleSubmission['status'] ?? 0) === 400, 'El reenvio imposible responde 400 Bad Request');
$forgeSpell($pdo, 'spl_ajena', 'usr_ajena', $CLAN_TEMPEST, 'draft', 20, $FINGERPRINT, $NOW_UTC);
assertCondition(
    str_contains((string) captureError(static fn () => $service->submitToModeration('spl_ajena', 'usr_autora', $NOW)), 'SpellNotFoundException'),
    'Un conjuro ajeno se responde como inexistente (404, Art. III)'
);
$readerVerdict = captureVerdict(static fn () => $service->submitToModeration('spl_ajena', 'usr_lector', $NOW));
assertCondition(($readerVerdict['code'] ?? '') === 'INSUFFICIENT_RANK', 'El lector anonimo no eleva plegarias a la Torre (RF-01.2)');
assertCondition(($readerVerdict['status'] ?? 0) === 403, 'El rango insuficiente responde 403 Forbidden');
$convalescentVerdict = captureVerdict(static fn () => $service->submitToModeration('spl_ajena', 'usr_convaleciente', $NOW));
assertCondition(($convalescentVerdict['code'] ?? '') === 'CONVALESCENCE_ACTIVE', 'En convalecencia arcana la pluma calla');
assertCondition(($convalescentVerdict['status'] ?? 0) === 403, 'La convalecencia responde 403 Forbidden');
assertCondition(
    captureError(static fn () => $service->submitToModeration('spl_ajena', 'usr_fantasma', $NOW)) !== null,
    'Un mago ajeno a los anales no puede elevar obra alguna'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'MODERATION_SUBMITTED' AND target_entity_id = 'spl_ajena'") === 0,
    'Ningun rechazo dejo memoria en la bitacora'
);

// --- FASE 3: Retirada a la libreta con anulacion de avales (RF-01.3) ---
echo "\nFASE 3: Retirada a la libreta y anulacion irrevocable de avales (RF-01.3)\n";
$forgeUser($pdo, 'usr_retirada', 'Autora que se Retira', 'editor', $CLAN_TEMPEST, '2026-01-04T00:00:00Z');
$forgeUser($pdo, 'usr_magistrado_dos', 'Segundo Maestro', 'master', $CLAN_TEMPEST, '2026-01-05T00:00:00Z');
$forgeSpell($pdo, 'spl_retirada', 'usr_retirada', $CLAN_TEMPEST, 'draft', 25, $FINGERPRINT, $NOW_UTC);
$service->submitToModeration('spl_retirada', 'usr_retirada', $NOW);

$forgeSignature($pdo, 'sig_uno', 'spl_retirada', 'usr_magistrado', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_dos', 'spl_retirada', 'usr_magistrado_dos', $CLAN_TEMPEST, $NOW_UTC);
$pdo->prepare("UPDATE spell_reviews SET signatures_count = 2 WHERE spell_id = 'spl_retirada'")->execute();

$withdrawn = $service->withdrawToDraft('spl_retirada', 'usr_retirada', $NOW);
assertCondition($withdrawn->status === 'draft', 'La obra retirada vuelve a la libreta de su autor (RF-01.3)');
assertCondition($withdrawn->signaturesCount === 0, 'Los avales previos quedan anulados: el contador vuelve a cero');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_retirada' AND is_revoked = 0") === 0,
    'Ninguna firma sobrevive a la version que juzgaba (RF-01.3)'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_retirada' AND revocation_reason = 'author_withdrawn'") === 2,
    'Las dos firmas caen con el motivo canonico author_withdrawn'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_retirada' AND revoked_at = '{$NOW_UTC}'") === 2,
    'La retirada queda fechada para ambas firmas'
);
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_retirada'") === 'draft', 'El espejo del conjuro vuelve a borrador');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_retirada'") === 0, 'El espejo del contador vuelve a cero');
assertCondition((string) $scalar($pdo, "SELECT reopened_at FROM spell_reviews WHERE spell_id = 'spl_retirada'") === $NOW_UTC, 'El expediente marca el regreso a la libreta');
assertCondition($auditCount($pdo, 'MODERATION_WITHDRAWN', 'spl_retirada') === 1, 'La retirada queda inscrita en la Bitacora');

$forgeSpell($pdo, 'spl_consagrada', 'usr_retirada', $CLAN_TEMPEST, 'validated', 25, $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_consagrada', 'spl_consagrada', 'usr_retirada', 'validated', 3, $CLAN_TEMPEST, $NOW_UTC);
$immutable = captureVerdict(static fn () => $service->withdrawToDraft('spl_consagrada', 'usr_retirada', $NOW));
assertCondition(($immutable['code'] ?? '') === 'SPELL_NOT_UNDER_REVIEW', 'Una obra consagrada no se retira: su consagracion es irrevocable (RF-02.7)');
assertCondition(($immutable['status'] ?? 0) === 409, 'La retirada imposible responde 409 Conflict (plan 2.2)');
assertCondition(
    str_contains((string) ($immutable['message'] ?? ''), 'validated'),
    'La leyenda nombra el estado que impide la retirada'
);
$forgeSpell($pdo, 'spl_borrador_dos', 'usr_retirada', $CLAN_TEMPEST, 'draft', 25, $FINGERPRINT, $NOW_UTC);
assertCondition(
    ($captured = captureVerdict(static fn () => $service->withdrawToDraft('spl_borrador_dos', 'usr_retirada', $NOW))) !== null
    && ($captured['code'] ?? '') === 'SPELL_NOT_UNDER_REVIEW',
    'Un borrador no se retira: jamas entro a deliberacion'
);
assertCondition(
    str_contains((string) captureError(static fn () => $service->withdrawToDraft('spl_retirada', 'usr_ajena', $NOW)), 'SpellNotFoundException'),
    'Un autor ajeno no retira mi obra (404, Art. III)'
);
assertCondition((int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'MODERATION_WITHDRAWN'") === 1, 'Solo la retirada licita dejo memoria');

// --- FASE 4: Re-apertura de una obra vetada (RF-01.4) ---
echo "\nFASE 4: Re-apertura de una obra vetada con su dictamen a la vista (RF-01.4)\n";
$forgeUser($pdo, 'usr_vetada', 'Autora Vetada', 'editor', $CLAN_ROOT, '2026-01-06T00:00:00Z');
$forgeSpell($pdo, 'spl_vetada', 'usr_vetada', $CLAN_ROOT, 'rejected', 25, $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_vetada', 'spl_vetada', 'usr_vetada', 'rejected', 0, $CLAN_ROOT, '2026-08-01T10:00:00Z');
$objectionLength = $pdo->prepare(
    'INSERT INTO objection_verdicts (id, spell_id, master_id, objection_reason, objected_at)
     VALUES (:id, :spellId, :masterId, :reason, :objectedAt)'
);
$objectionLength->execute([
    ':id'        => 'obj_vetada',
    ':spellId'   => 'spl_vetada',
    ':masterId'  => 'usr_magistrado',
    ':reason'    => 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.',
    ':objectedAt' => '2026-08-02T10:00:00Z',
]);

assertCondition(
    $service->objectionArchiveFor('spl_vetada')?->reasonLength() === 84,
    'El dictamen anterior viaja integro, con sus tildes y su puntuacion (RF-06.2)'
);
$reopened = $service->reopenAsDraft('spl_vetada', 'usr_vetada', $NOW);
assertCondition($reopened->status === 'draft', 'La obra vetada se reabre como borrador (RF-01.4)');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_vetada'") === 'draft', 'El espejo del conjuro admite de nuevo la edicion');
assertCondition((string) $scalar($pdo, "SELECT reopened_at FROM spell_reviews WHERE spell_id = 'spl_vetada'") === $NOW_UTC, 'La re-apertura queda fechada en el expediente');
assertCondition($auditCount($pdo, 'MODERATION_REOPENED', 'spl_vetada') === 1, 'La re-apertura queda inscrita en la Bitacora');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_vetada'") === 1,
    'El dictamen jamas se borra al reabrir (RNF-01)'
);
assertCondition(
    $service->objectionArchiveFor('spl_vetada')?->objectionReason === 'La descripcion lirica presenta anacronismos manifiestos que vulneran el Velo Arcano.',
    'El dictamen sigue a la vista despues de la re-apertura (RF-01.4)'
);
assertCondition($service->objectionArchiveFor('spl_borrador_dos') === null, 'Una obra jamas objetada no tiene dictamen que exhibir');
assertCondition($service->hasReviewCapacity('usr_vetada') === true, 'La re-apertura devuelve la obra a la libreta sin consumir cupo');
assertCondition(
    ($capturedReopen = captureVerdict(static fn () => $service->reopenAsDraft('spl_vetada', 'usr_vetada', $NOW))) !== null
    && ($capturedReopen['code'] ?? '') === 'SPELL_NOT_REJECTED' && ($capturedReopen['status'] ?? 0) === 400,
    'Una obra ya reabierta no se reabre dos veces (400)'
);
assertCondition(
    str_contains((string) captureError(static fn () => $service->reopenAsDraft('spl_vetada', 'usr_ajena', $NOW)), 'SpellNotFoundException'),
    'Un autor ajeno no reabre mi obra (404)'
);

// --- FASE 5: Caducidad por letargo de noventa dias (RF-01.6) ---
echo "\nFASE 5: Caducidad por letargo de noventa dias (RF-01.6)\n";
$forgeUser($pdo, 'usr_letargo', 'Autora del Letargo', 'editor', $CLAN_ROOT, '2026-01-07T00:00:00Z');
$forgeSpell($pdo, 'spl_letargo', 'usr_letargo', $CLAN_ROOT, 'experimental', 25, $FINGERPRINT, '2026-06-01T00:00:00Z');
$forgeReview($pdo, 'rev_letargo', 'spl_letargo', 'usr_letargo', 'experimental', 1, $CLAN_ROOT, '2026-06-01T00:00:00Z');
$forgeSignature($pdo, 'sig_letargo', 'spl_letargo', 'usr_magistrado', null, '2026-06-05T00:00:00Z');
$forgeSpell($pdo, 'spl_fresca', 'usr_letargo', $CLAN_ROOT, 'experimental', 25, $FINGERPRINT, '2026-09-01T00:00:00Z');
$forgeReview($pdo, 'rev_fresca', 'spl_fresca', 'usr_letargo', 'experimental', 0, $CLAN_ROOT, '2026-09-01T00:00:00Z');
$forgeSpell($pdo, 'spl_resonante', 'usr_letargo', $CLAN_ROOT, 'experimental', 25, $FINGERPRINT, '2026-05-01T00:00:00Z');
$forgeReview($pdo, 'rev_resonante', 'spl_resonante', 'usr_letargo', 'experimental', 1, $CLAN_ROOT, '2026-05-01T00:00:00Z');
$forgeSignature($pdo, 'sig_resonante', 'spl_resonante', 'usr_magistrado_dos', $CLAN_TEMPEST, '2026-09-10T00:00:00Z');

assertCondition($service->remainingCapacity('usr_letargo') === 0, 'La Autora del Letargo tiene la Torre colmada');
$expired = $service->checkExpiryCron($NOW);
$expiredSpellIds = array_map(static fn ($dto): string => $dto->spellId, $expired);
assertCondition($expiredSpellIds === ['spl_letargo'], 'Solo caduca la obra sin nueva resonancia en noventa dias');
assertCondition($expired[0]->status === 'rejected', 'La obra en letargo pasa a vetada con la leyenda del Letargo Arcano (RF-01.6)');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_letargo'") === 'rejected', 'El espejo del conjuro sigue a la caducidad');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_letargo'") === 0, 'La obra caducada no conserva avales: un aval solo vive en deliberacion');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_letargo' AND revocation_reason = 'review_expired'") === 1,
    'El aval viejo cae con el motivo ceremonial review_expired'
);
assertCondition($auditCount($pdo, 'MODERATION_EXPIRED', 'spl_letargo') === 1, 'La caducidad queda inscrita en la Bitacora');
$expiryJustification = (string) $scalar(
    $pdo,
    "SELECT justification FROM audit_log WHERE action_type = 'MODERATION_EXPIRED' AND target_entity_id = 'spl_letargo'",
);
assertCondition(str_contains($expiryJustification, 'Letargo Arcano por Falta de Resonancia Colegiada'), 'La leyenda solemne de la caducidad es la que nombra la especificacion');
assertCondition($service->remainingCapacity('usr_letargo') === 1, 'La caducidad libera el cupo de su autor (RF-01.6)');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_fresca'") === 'experimental', 'La obra con reloj fresco sigue en deliberacion');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_resonante'") === 1, 'Una obra con firma reciente no caduca: la resonancia reinicia el reloj');
assertCondition($service->checkExpiryCron($NOW) === [], 'Un segundo barrido no caduca lo ya caducado ni lo que respira');

// --- FASE 6: Inmutabilidad de lo evaluado (RF-01.3) ---
echo "\nFASE 6: Guardian de edicion y los cinco estados (RF-01.3)\n";
assertCondition(
    captureError(static fn () => $service->assertSpellIsEditableInDraft('spl_borrador_dos')) === null,
    'El borrador privado admite la edicion plena del autor'
);
$underReview = captureVerdict(static fn () => $service->assertSpellIsEditableInDraft('spl_fresca'));
assertCondition(($underReview['code'] ?? '') === 'UNDER_REVIEW_IMMUTABLE', 'Lo evaluado es inmutable: la obra en deliberacion no se edita (RF-01.3)');
assertCondition(($underReview['status'] ?? 0) === 403, 'La edicion vedada responde 403 Forbidden');
$awaiting = captureVerdict(static fn () => $service->assertSpellIsEditableInDraft('spl_letargo'));
assertCondition(($awaiting['code'] ?? '') === 'SPELL_AWAITING_REOPEN', 'La obra vetada aguarda su re-apertura antes de admitir enmienda (RF-01.4)');
assertCondition(
    str_contains((string) captureError(static fn () => $service->assertSpellIsEditableInDraft('spl_consagrada')), 'SpellImmutableException'),
    'La obra consagrada es patrimonio inmutable (RF-06.2)'
);
$forgeSpell($pdo, 'spl_desterrada', 'usr_retirada', $CLAN_TEMPEST, 'archived', 25, $FINGERPRINT, $NOW_UTC);
assertCondition(
    str_contains((string) captureError(static fn () => $service->assertSpellIsEditableInDraft('spl_desterrada')), 'SpellImmutableException'),
    'La obra desterrada tampoco se enmienda (RF-04.4)'
);
assertCondition(
    str_contains((string) captureError(static fn () => $service->assertSpellIsEditableInDraft('spl_inexistente')), 'SpellNotFoundException'),
    'Un conjuro inexistente no se declara editable'
);

// --- FASE 7: Atomicidad y cerrojo del cupo ---
echo "\nFASE 7: Atomicidad y cerrojo ante escritores simultaneos\n";
$forgeUser($pdo, 'usr_atomica', 'Autora Atomica', 'editor', $CLAN_ROOT, '2026-01-08T00:00:00Z');
$forgeSpell($pdo, 'spl_atomica', 'usr_atomica', $CLAN_ROOT, 'draft', 25, $FINGERPRINT, $NOW_UTC);

$pdo->exec("CREATE TRIGGER trg_sella_bitacora BEFORE INSERT ON audit_log
             WHEN NEW.target_entity_id = 'spl_atomica'
             BEGIN SELECT RAISE(ABORT, 'bitacora sellada por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->submitToModeration('spl_atomica', 'usr_atomica', $NOW)) !== null,
    'La elevacion fracasa cuando su memoria no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_bitacora');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_atomica'") === 0,
    'Sin memoria no hay elevacion: el expediente NO nace a medias (RNF-02)'
);
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_atomica'") === 'draft', 'La obra sigue en la libreta: el gesto se deshizo entero');
assertCondition((int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = 'spl_atomica'") === 0, 'No hay memoria huerfana de una elevacion que no ocurrio');
$conclusive = $service->submitToModeration('spl_atomica', 'usr_atomica', $NOW);
assertCondition($conclusive->status === 'experimental', 'Retirado el sello, la misma elevacion se consuma sin estorbo');

// El cerrojo: un segundo escritor retiene el bloqueo de la fila del autor
// mientras eleva la ultima obra que cabe. El servicio no puede colarse, y
// cuando el bloqueo se libera cuenta las plazas REALES, no las suyas.
$forgeUser($pdo, 'usr_serial', 'Autora Serial', 'editor', $CLAN_ROOT, '2026-01-09T00:00:00Z');
foreach (['spl_serial_uno', 'spl_serial_dos', 'spl_serial_tres'] as $serialId) {
    $forgeSpell($pdo, $serialId, 'usr_serial', $CLAN_ROOT, 'draft', 25, $FINGERPRINT, $NOW_UTC);
}
$service->submitToModeration('spl_serial_uno', 'usr_serial', $NOW);
$service->submitToModeration('spl_serial_dos', 'usr_serial', $NOW);
assertCondition($service->remainingCapacity('usr_serial') === 1, 'Queda una plaza para la Autora Serial');

$rivalWriter = new PDO('sqlite:' . $databasePath);
$rivalWriter->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rivalWriter->setAttribute(PDO::ATTR_TIMEOUT, 1);
$rivalWriter->exec('PRAGMA foreign_keys = ON;');
$rivalWriter->beginTransaction();
$lock = $rivalWriter->prepare('UPDATE users SET updated_at = updated_at WHERE id = :userId');
$lock->execute([':userId' => 'usr_serial']);
$rivalCount = $rivalWriter->prepare('SELECT COUNT(*) FROM spell_reviews WHERE author_id = :authorId AND status = :status');
$rivalCount->execute([':authorId' => 'usr_serial', ':status' => 'experimental']);
assertCondition((int) $rivalCount->fetchColumn() === 2, 'El escritor rival cuenta dos plazas ocupadas antes de elevar la tercera');
$rivalInsert = $rivalWriter->prepare(
    "INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at)
     VALUES ('rev_rival', 'spl_serial_tres', 'usr_serial', :clanId, 'experimental', 0, :fingerprint, :submittedAt)"
);
$rivalInsert->execute([':clanId' => $CLAN_ROOT, ':fingerprint' => $FINGERPRINT, ':submittedAt' => $NOW_UTC]);

assertCondition(
    captureError(static fn () => $service->submitToModeration('spl_atomica', 'usr_serial', $NOW)) !== null,
    'Con el bloqueo tomado, la elevacion simultanea NO se cuela (el guardia serializa)'
);
$rivalWriter->commit();
$rivalWriter = null;
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE author_id = 'usr_serial' AND status = 'experimental'") === 3,
    'El escritor rival consumio la ultima plaza: la Torre custodia exactamente tres'
);
$serialVerdict = captureVerdict(static fn () => $service->submitToModeration('spl_serial_tres', 'usr_serial', $NOW));
assertCondition(($serialVerdict['code'] ?? '') === 'TOWER_CAPACITY_EXCEEDED', 'Consumada la tercera plaza, el cupo rechaza la cuarta obra');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE author_id = 'usr_serial' AND status = 'experimental'") === 3,
    'El cupo es INFRANQUEABLE: jamas llego a cuatro (RNF-04)'
);

// --- FASE 8: Auditoria estatica, catalogos y Dogma Vanilla ---
echo "\nFASE 8: Auditoria estatica y cruce de catalogos\n";
preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $serviceSource, $literals);
$sqlLiterals = array_filter(
    $literals[1],
    static fn (string $literal): bool => preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|FROM|WHERE|ORDER BY|JOIN)\b/', $literal) === 1
);
assertCondition(
    array_values(array_filter($sqlLiterals, static fn (string $literal): bool => str_contains($literal, '$'))) === [],
    'Ningun literal SQL interpola una variable (parameter binding)'
);
assertCondition(
    substr_count($serviceSource, '->prepare(') >= 4
    && !str_contains($serviceSource, '$this->pdo->exec(')
    && !str_contains($serviceSource, '$this->pdo->query('),
    'Todo acceso al motor pasa por sentencias preparadas: sin consultas ni ejecuciones directas'
);
assertCondition(
    str_contains($serviceSource, 'publishDraftWithinTransaction'),
    'La publicacion DELEGA en el nucleo de SpellManagementService: un solo flujo y un solo coste'
);
assertCondition(
    !str_contains($serviceSource, 'time()') && !str_contains($serviceSource, 'date('),
    'No lee el reloj del sistema: el instante se inyecta (RNF-01)'
);
assertCondition(
    preg_match('/[\x{00e1}\x{00e9}\x{00ed}\x{00f3}\x{00fa}\x{00f1}\x{00bf}\x{00a1}]/u', $serviceSource) === 1,
    'La narrativa y los comentarios van en noble castellano (RNF-03, Articulo V)'
);
assertCondition(
    ($serviceClass)::revocationReasons() === Grimorio\Dto\MasterSignatureDto::CANONICAL_REVOCATION_REASONS
    && ($serviceClass)::revocationReasons() === Grimorio\Repositories\MasterSignatureRepository::CANONICAL_REVOCATION_REASONS,
    'El catalogo de motivos de revocacion es uno solo: DTO, repositorio y servicio no divergen'
);
assertCondition(in_array('review_expired', ($serviceClass)::revocationReasons(), true), 'El letargo estrena su motivo ceremonial en el catalogo');
assertCondition((( $serviceClass)::workflowCanon()['maxConcurrentReviews'] === 3), 'La interfaz recibe el cupo desde la fuente unica del canon');
assertCondition(str_contains((( $serviceClass)::workflowCanon()['expiryLegend']), 'Letargo Arcano'), 'La leyenda del letargo viaja a la interfaz');

$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
foreach (['MODERATION_SUBMITTED', 'MODERATION_WITHDRAWN', 'MODERATION_REOPENED', 'MODERATION_EXPIRED'] as $act) {
    assertCondition(str_contains($auditEntrySource, "'{$act}'"), "El acto {$act} figura en el catalogo cerrado de AuditEntry");
}
$auditViewSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
foreach (['MODERATION_SUBMITTED', 'MODERATION_WITHDRAWN', 'MODERATION_REOPENED', 'MODERATION_EXPIRED'] as $act) {
    assertCondition(str_contains($auditViewSource, $act . ':'), "El acto {$act} tiene su rotulo castellano en la Bitacora publica");
}

// --- VEREDICTO ---
unset($service, $balanceService, $rivalWriter);
$pdo = null;
gc_collect_cycles();
@unlink($databasePath);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_workflow.sqlite'),
    'El arnes jamas escribe la base efimera dentro del repositorio'
);

echo "\n== RESULTADO: {$assertsPassed} asertos pasados, {$assertsFailed} fallidos ==\n";
if ($assertsFailed === 0) {
    echo "TAREA 2.3 VERIFICADA: el cuarto envio simultaneo es rechazado por un cupo serializado,\n";
    echo "la retirada a borrador anula todos los avales previos, la re-apertura devuelve la obra\n";
    echo "a la libreta con su dictamen a la vista, y el letargo caduca la obra tras noventa dias.\n";
    exit(0);
}

echo "TAREA 2.3 NO VERIFICADA: revisense los asertos en rojo.\n";
exit(1);

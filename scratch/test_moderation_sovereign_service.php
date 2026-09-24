<?php

declare(strict_types=1);

/**
 * test_moderation_sovereign_service.php — Verificación de la Tarea 2.5 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Se rechaza cualquier intento de validar borradores en `draft` o conjuros
 *    del propio clan del Administrador, y el rescate a `experimental` reinicia
 *    las firmas en 0/3.»
 *
 * Estrategia: se levanta una base SQLite efímera —en el directorio temporal del
 * sistema, jamás dentro del repositorio— con la secuencia canónica, y se ejerce
 * la potestad soberana sobre expedientes reales con la gloria de SPEC-07 ya
 * cableada: un PDA mal acreditado, mal deducido o mal contado sale en rojo.
 * El CIERRE DOMINICAL de la deducción se prueba con el cierre de verdad
 * —`closeWeeklyCycle()`—, no simulando el pliegue a mano.
 *
 * Fases:
 *   [0]  Superficie del servicio y de su contrato de errores.
 *   [1]  La Firma Soberana instantánea y la inviolabilidad de `draft` (RF-04.1).
 *   [2]  Veto del propio estandarte y de la propia pluma (RF-04.2, RF-03.2).
 *   [3]  Rescate de una obra vetada: a 0/3 y directo al Tomo (RF-04.3).
 *   [4]  Destierro póstumo y deducción retroactiva de la gloria (RF-04.4).
 *   [5]  Atomicidad del decreto y de su efecto (RF-04.5, Art. III.3).
 *   [6]  Auditoría estática, cruce de catálogos y Dogma Vanilla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_moderation_sovereign_service.php
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

/** ¿Lanza excepción este cierre? Devuelve la clase y el mensaje, o null. */
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
 * Captura el VEREDICTO de una operación: su código canónico, su estado HTTP y
 * su leyenda. Es la forma de comprobar el contrato sin leer mensajes de prosa.
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

echo "== VERIFICACION TAREA 2.5: Potestad soberana del Administrador Supremo ==\n\n";

$projectRoot = dirname(__DIR__);
$servicePath = $projectRoot . '/src/Services/SovereignAdminService.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del servicio y de su contrato de errores\n";
assertCondition(file_exists($servicePath), 'Existe src/Services/SovereignAdminService.php');

if (!file_exists($servicePath)) {
    echo "\nRESULTADO: DENEGADO — falta el servicio de la Tarea 2.5 (fase roja del TDD).\n";
    exit(1);
}

$serviceSource = (string) file_get_contents($servicePath);
$serviceLines = file($servicePath, FILE_IGNORE_NEW_LINES) ?: [];
$serviceHead = implode('', array_slice($serviceLines, 0, 40));
assertCondition(str_contains($serviceHead, 'declare(strict_types=1);'), 'Declara strict_types en las primeras 40 lineas (AGENTS.md)');
assertCondition(str_contains($serviceSource, 'namespace Grimorio\\Services;'), 'Habita el espacio de nombres Grimorio\\Services');
assertCondition(
    preg_match('#^use (?!Grimorio)[A-Z]#m', $serviceSource) === 1
    && preg_match('#https?://#', $serviceSource) !== 1,
    'Solo depende de la biblioteca estandar de PHP: cero dependencias externas (Articulo I)'
);

foreach (['executeSovereignValidation', 'executeSovereignRescue', 'executeSovereignArchive', 'sovereignCanon'] as $methodName) {
    assertCondition(str_contains($serviceSource, "function {$methodName}("), "Publica la operacion canonica {$methodName}()");
}
assertCondition(
    str_contains($serviceSource, "SOVEREIGN_ROLE = 'supremeAdmin'"),
    'Declara el rango soberano como constante canonica (RF-04)'
);

$exceptionSource = (string) file_get_contents($projectRoot . '/src/Exceptions/ModerationWorkflowException.php');
foreach ([
    'INSUFFICIENT_SOVEREIGN_RANK',
    'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL',
    'CANNOT_SOVEREIGN_RESCUE_NON_REJECTED',
    'CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED',
    'SOVEREIGN_RESCUE_INVALID_TARGET',
    'SELF_VALIDATION_PROHIBITED',
    'SOVEREIGN_OWN_CLAN_VETO',
    'IMPERIAL_DECREE_TOO_SHORT',
] as $errorCode) {
    assertCondition(str_contains($exceptionSource, $errorCode), "El contrato declara el codigo {$errorCode}");
}

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Exceptions/ModerationWorkflowException.php';
require_once $projectRoot . '/src/Exceptions/SpellNotFoundException.php';
require_once $projectRoot . '/src/Exceptions/ClanGovernanceException.php';
require_once $projectRoot . '/src/Dto/SpellReviewDto.php';
require_once $projectRoot . '/src/Dto/MasterSignatureDto.php';
require_once $projectRoot . '/src/Dto/ObjectionVerdictDto.php';
require_once $projectRoot . '/src/Dto/DominionAwardDto.php';
require_once $projectRoot . '/src/Dto/DominionReversalDto.php';
require_once $projectRoot . '/src/Dto/LineageDto.php';
require_once $projectRoot . '/src/Dto/ClanDto.php';
require_once $projectRoot . '/src/Dto/ClanMemberDto.php';
require_once $projectRoot . '/src/Dto/WeeklyCycleDto.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): se carga antes del
// servicio, como hace el autoloader del front controller.
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Services/ClanEthicsValidator.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Repositories/ClanRepository.php';
require_once $projectRoot . '/src/Repositories/WeeklyCycleRepository.php';
require_once $projectRoot . '/src/Services/WeeklyDominionService.php';
require_once $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
require_once $projectRoot . '/src/Repositories/ObjectionVerdictRepository.php';
require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Repositories/ImperialDecreeRepository.php';
require_once $servicePath;

$serviceClass = 'Grimorio\\Services\\SovereignAdminService';
$decreeClass = 'Grimorio\\Repositories\\ImperialDecreeRepository';
$reversalClass = 'Grimorio\\Dto\\DominionReversalDto';

$databasePath = sys_get_temp_dir() . '/grimorio_sovereign_' . getmypid() . '.sqlite';
@unlink($databasePath);
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 1);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

/** @var SovereignAdminService $service */
$service = new $serviceClass($pdo);
$dominionService = new Grimorio\Services\WeeklyDominionService($pdo, new Grimorio\Services\AuditService($pdo));

$NOW = new DateTimeImmutable('2026-09-15T10:00:00Z');
$NOW_UTC = '2026-09-15T10:00:00Z';
$FINGERPRINT = str_repeat('a', 64);

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

/** Inscribe una hermandad con su linaje rector. */
$forgeClan = static function (PDO $pdo, string $clanId, string $lineage, string $name, string $status = 'active'): void {
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode, status,
                            weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt, :arms, :lineage, :mode, :status, 0, 0, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $clanId,
        ':slug'      => strtolower(str_replace('_', '-', $clanId)),
        ':name'      => $name,
        ':motto'     => 'Lema de prueba del arnes.',
        ':createdAt' => '2026-01-01T00:00:00Z',
        ':arms'      => 'rune_' . $clanId,
        ':lineage'   => $lineage,
        ':mode'      => 'open',
        ':status'    => $status,
    ]);
};

/** Inscribe una membresia activa. */
$forgeMembership = static function (PDO $pdo, string $memberId, string $clanId, string $userId, string $joinedAt): void {
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, \'adept\', :joinedAt, NULL, NULL)'
    );
    $statement->execute([':id' => $memberId, ':clanId' => $clanId, ':userId' => $userId, ':joinedAt' => $joinedAt]);
};

/** Inscribe un conjuro con su forma arcana minima. */
$forgeSpell = static function (
    PDO $pdo,
    string $spellId,
    string $authorId,
    string $clanId,
    string $status,
    string $fingerprint,
    string $createdAt,
    int $circle = 1,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, \'evocation\', \'fire\', 100, :circle,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, :status, 0,
                 20, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':circle'      => $circle,
        ':fingerprint' => $fingerprint,
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba de la potestad soberana.',
        ':createdAt'   => $createdAt,
        ':status'      => $status,
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
    string $clanId,
) use ($FINGERPRINT, $NOW_UTC): void {
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
        ':submittedAt' => $NOW_UTC,
        ':rejectedAt'  => $status === 'rejected' ? $NOW_UTC : null,
    ]);

    $mirror = $pdo->prepare('UPDATE spells SET status = :status, signatures_count = :count WHERE id = :spellId');
    $mirror->execute([':status' => $status, ':count' => $count, ':spellId' => $spellId]);
};

/** Estampa una firma de Maestro. */
$forgeSignature = static function (PDO $pdo, string $signatureId, string $spellId, string $masterId, ?string $clanId, string $signedAt): void {
    $statement = $pdo->prepare(
        'INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at, is_revoked)
         VALUES (:id, :spellId, :masterId, :clanId, NULL, :signedAt, 0)'
    );
    $statement->execute([
        ':id'       => $signatureId,
        ':spellId'  => $spellId,
        ':masterId' => $masterId,
        ':clanId'   => $clanId,
        ':signedAt' => $signedAt,
    ]);
};

/** Lee un unico valor escalar de la base. */
$scalar = static function (PDO $pdo, string $sql, array $parameters = []): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
};

/** Cuenta entradas de la bitacora por acto y objetivo. */
$auditCount = static function (PDO $pdo, string $actionType, string $targetId) use ($scalar): int {
    return (int) $scalar(
        $pdo,
        'SELECT COUNT(*) FROM audit_log WHERE action_type = :actionType AND target_entity_id = :targetId',
        [':actionType' => $actionType, ':targetId' => $targetId],
    );
};

/** Cuenta decretos de un conjuro. */
$decreeCount = static function (PDO $pdo, string $spellId) use ($scalar): int {
    return (int) $scalar($pdo, 'SELECT COUNT(*) FROM sovereign_decrees WHERE spell_id = :spellId', [':spellId' => $spellId]);
};

// --------------------------------------------------------------------------
// Semillas: hermandades, autores, soberanos y maestros.
// --------------------------------------------------------------------------
$CLAN_ORIGEN = 'cln_aurora';
$CLAN_AJENO = 'cln_tempestad';

$forgeClan($pdo, $CLAN_ORIGEN, 'solarCrown', 'Aurora del Sol Naciente');
$forgeClan($pdo, $CLAN_AJENO, 'eternalTempest', 'Tempestad Eterna');

$forgeUser($pdo, 'usr_autora', 'Autora de la Aurora', 'editor', $CLAN_ORIGEN, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_lector', 'Lector Anonimo', 'reader', null, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_maestro', 'Maestro del Conclave', 'master', null, '2026-01-02T00:00:00Z');

// El Cónclave Supremo, en sus tres semblanzas: ermitaño, con linaje ajeno y
// con linaje propio a la obra juzgada. La tercera es la del veto.
$forgeUser($pdo, 'usr_supremo', 'Administrador Supremo', 'supremeAdmin', null, '2026-01-03T00:00:00Z');
$forgeUser($pdo, 'usr_supremo_ajeno', 'Supremo de la Tempestad', 'supremeAdmin', $CLAN_AJENO, '2026-01-03T00:00:00Z');
$forgeUser($pdo, 'usr_supremo_aurora', 'Supremo de la Aurora', 'supremeAdmin', $CLAN_ORIGEN, '2026-01-03T00:00:00Z');
$forgeMembership($pdo, 'clm_supremo_ajeno', $CLAN_AJENO, 'usr_supremo_ajeno', '2026-02-01T00:00:00Z');
$forgeMembership($pdo, 'clm_supremo_aurora', $CLAN_ORIGEN, 'usr_supremo_aurora', '2026-02-01T00:00:00Z');

$EDICTO = 'Por mandato del Cónclave Supremo, esta obra es consagrada de oficio por su excepcional equilibrio arcano.';

// --- FASE 1: La Firma Soberana instantanea (RF-04.1) ---
echo "\nFASE 1: La Firma Soberana y la inviolabilidad de los borradores (RF-04.1)\n";
$forgeSpell($pdo, 'spl_soberana', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC, 3);
$forgeReview($pdo, 'rev_soberana', 'spl_soberana', 'usr_autora', 'experimental', 2, $CLAN_ORIGEN);
$forgeSignature($pdo, 'sig_uno', 'spl_soberana', 'usr_maestro', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_dos', 'spl_soberana', 'usr_lector', $CLAN_AJENO, $NOW_UTC);

$validated = $service->executeSovereignValidation('spl_soberana', 'usr_supremo', $EDICTO, $NOW);
assertCondition($validated->status === 'validated', 'La Firma Soberana eleva la obra de deliberacion al Tomo (RF-04.1)');
assertCondition($validated->signaturesCount === 2, 'El contador se CONSERVA: el soberano no inventa un tercer aval');
assertCondition($validated->signaturesIndicator() === '2/3', 'La obra consagrada de oficio declara sus DOS avales reales');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_soberana'") === 'validated',
    'El espejo del conjuro sigue a la autoridad (Tarea 1.5)'
);
assertCondition(
    (string) $scalar($pdo, "SELECT validated_at FROM spells WHERE id = 'spl_soberana'") === $NOW_UTC,
    'La consagracion de oficio queda FECHADA en el espejo para el Libro de Oro'
);
assertCondition($decreeCount($pdo, 'spl_soberana') === 1, 'El acto dejo su Decreto Imperial (RF-04.5)');
assertCondition(
    (string) $scalar($pdo, "SELECT decree_type FROM sovereign_decrees WHERE spell_id = 'spl_soberana'") === 'sovereignValidation',
    'El decreto es del tipo canonico de la Firma Soberana'
);
assertCondition(
    (string) $scalar($pdo, "SELECT imperial_decree_text FROM sovereign_decrees WHERE spell_id = 'spl_soberana'") === $EDICTO,
    'El edicto se conserva INTEGRO en el libro de decretos'
);
assertCondition($auditCount($pdo, 'SOVEREIGN_VALIDATION', 'spl_soberana') === 1, 'El acto se inscribe en la Bitacora (Art. III.3)');
assertCondition(
    (string) $scalar($pdo, "SELECT justification FROM audit_log WHERE action_type = 'SOVEREIGN_VALIDATION' AND target_entity_id = 'spl_soberana'") === $EDICTO,
    'La Bitacora publica publica el edicto caracter por caracter'
);
$weekly = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$awarded = (int) $scalar($pdo, "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_soberana'");
assertCondition($awarded === 160, 'La gloria del Circulo III es 100 + 3 x 20 (RF-03.1 de SPEC-07)');
assertCondition($weekly === $awarded, 'La gloria se acredita al linaje ORIGINARIO de la obra');
assertCondition(
    (int) $scalar($pdo, "SELECT historical_points FROM clans WHERE id = :clanId", [':clanId' => $CLAN_ORIGEN]) === 0,
    'Durante la contienda solo se acredita el marcador semanal'
);

// El borrador privado es INVIOLABLE, incluso para el Cónclave Supremo.
$forgeSpell($pdo, 'spl_borrador', 'usr_autora', $CLAN_ORIGEN, 'draft', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_borrador', 'spl_borrador', 'usr_autora', 'draft', 0, $CLAN_ORIGEN);
$draftVerdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_borrador', 'usr_supremo', $EDICTO, $NOW));
assertCondition(($draftVerdict['code'] ?? '') === 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL', 'El borrador privado queda fuera de la potestad soberana (RF-04.1, spec 7.5)');
assertCondition(($draftVerdict['status'] ?? 0) === 400, 'La firma sobre un borrador responde 400 Bad Request');
assertCondition($decreeCount($pdo, 'spl_borrador') === 0, 'El rechazo no dejo decreto alguno');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_borrador'") === 'draft', 'La obra privada permanece intacta en su libreta');

$alreadyValidated = captureVerdict(static fn () => $service->executeSovereignValidation('spl_soberana', 'usr_supremo', $EDICTO, $NOW));
assertCondition(($alreadyValidated['code'] ?? '') === 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL', 'Lo ya consagrado no se consagra de nuevo');
assertCondition($decreeCount($pdo, 'spl_soberana') === 1, 'La segunda firma rechazada no inscribio un segundo decreto');

$forgeSpell($pdo, 'spl_vetada', 'usr_autora', $CLAN_ORIGEN, 'rejected', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_vetada', 'spl_vetada', 'usr_autora', 'rejected', 0, $CLAN_ORIGEN);
$rejectedVerdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_vetada', 'usr_supremo', $EDICTO, $NOW));
assertCondition(($rejectedVerdict['code'] ?? '') === 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL', 'Una obra vetada tampoco admite Firma Soberana: su via es el rescate (RF-04.3)');

$rankVerdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_vetada', 'usr_maestro', $EDICTO, $NOW));
assertCondition(($rankVerdict['code'] ?? '') === 'INSUFFICIENT_SOVEREIGN_RANK', 'El Maestro no decreta: cada potestad tiene su protocolo (RF-04)');
assertCondition(($rankVerdict['status'] ?? 0) === 403, 'El rango insuficiente responde 403 Forbidden');
$readerVerdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_vetada', 'usr_lector', $EDICTO, $NOW));
assertCondition(($readerVerdict['code'] ?? '') === 'INSUFFICIENT_SOVEREIGN_RANK', 'El lector anonimo tampoco decreta');
assertCondition(
    str_contains((string) captureError(static fn () => $service->executeSovereignValidation('spl_inexistente', 'usr_supremo', $EDICTO, $NOW)), 'SpellNotFoundException'),
    'Un conjuro sin expediente no admite decreto'
);

// El Edicto Imperial: umbral de veinte caracteres (RF-04.5).
$forgeSpell($pdo, 'spl_edicto', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_edicto', 'spl_edicto', 'usr_autora', 'experimental', 1, $CLAN_ORIGEN);
$shortEdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_edicto', 'usr_supremo', str_repeat('e', 19), $NOW));
assertCondition(($shortEdict['code'] ?? '') === 'IMPERIAL_DECREE_TOO_SHORT', 'El edicto de diecinueve caracteres es rechazado (RF-04.5)');
assertCondition(($shortEdict['status'] ?? 0) === 422, 'El edicto breve responde 422 Unprocessable Entity');
$blankEdict = captureVerdict(static fn () => $service->executeSovereignValidation('spl_edicto', 'usr_supremo', str_repeat(' ', 40), $NOW));
assertCondition(($blankEdict['code'] ?? '') === 'IMPERIAL_DECREE_TOO_SHORT', 'Cuarenta espacios no son un edicto');
assertCondition($decreeCount($pdo, 'spl_edicto') === 0, 'Ningun edicto breve dejo decreto inscrito');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_edicto'") === 'experimental',
    'La obra siguio en deliberacion: el edicto breve no consuma nada'
);
// El edicto justo en el umbral: veinte caracteres exactos, ni uno menos.
$exactEdict = 'El Conclave decreta:';
assertCondition(mb_strlen($exactEdict) === 20, 'El edicto de control del arnes mide veinte caracteres exactos');
assertCondition(
    $service->executeSovereignValidation('spl_edicto', 'usr_supremo', $exactEdict, $NOW)->status === 'validated',
    'El edicto de EXACTAMENTE veinte caracteres se admite: el umbral es inclusivo'
);

// --- FASE 2: Veto del propio estandarte y de la propia pluma (RF-04.2, RF-03.2) ---
echo "\nFASE 2: Veto del propio estandarte y de la propia pluma (Art. III)\n";
$forgeSpell($pdo, 'spl_aurora', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_aurora', 'spl_aurora', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);

$ownClan = captureVerdict(static fn () => $service->executeSovereignValidation('spl_aurora', 'usr_supremo_aurora', $EDICTO, $NOW));
assertCondition(($ownClan['code'] ?? '') === 'SOVEREIGN_OWN_CLAN_VETO', 'El Supremo no firma las obras de su propio estandarte (RF-04.2)');
assertCondition(($ownClan['status'] ?? 0) === 403, 'El veto del propio linaje responde 403 Forbidden');
assertCondition(
    str_contains((string) ($ownClan['message'] ?? ''), 'tres Maestros independientes'),
    'El bloqueo ceremonial remite al juicio imparcial de tres Maestros ajenos (RF-04.2)'
);
assertCondition($decreeCount($pdo, 'spl_aurora') === 0, 'El veto no dejo decreto inscrito');
assertCondition((string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_aurora'") === 'experimental', 'La obra veto queda en deliberacion, intacta');

$foreignAdmin = $service->executeSovereignValidation('spl_aurora', 'usr_supremo_ajeno', $EDICTO, $NOW);
assertCondition($foreignAdmin->status === 'validated', 'Un Supremo de linaje AJENO sí puede consagrarla: el veto es del propio estandarte');

// El veto del linaje se lee del HISTORIAL DE MEMBRESÍA, no del espejo.
$forgeSpell($pdo, 'spl_espejo', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_espejo', 'spl_espejo', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);
// El espejo `users.clan_id` miente: dice que el Supremo ermitaño milita en la
// Aurora, pero el historial de membresía no registra afiliación alguna.
$pdo->prepare("UPDATE users SET clan_id = :clanId WHERE id = 'usr_supremo'")->execute([':clanId' => $CLAN_ORIGEN]);
$mirrorIsNotAuthority = $service->executeSovereignValidation('spl_espejo', 'usr_supremo', $EDICTO, $NOW);
assertCondition(
    $mirrorIsNotAuthority->status === 'validated',
    'La autoridad del linaje es el historial de membresia: el espejo users.clan_id no veta'
);

// La pluma propia: ni con la potestad suprema (RF-03.2).
$forgeUser($pdo, 'usr_supremo_autor', 'Supremo Autor', 'supremeAdmin', null, '2026-01-04T00:00:00Z');
$forgeSpell($pdo, 'spl_propia', 'usr_supremo_autor', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_propia', 'spl_propia', 'usr_supremo_autor', 'experimental', 0, $CLAN_ORIGEN);
$ownPen = captureVerdict(static fn () => $service->executeSovereignValidation('spl_propia', 'usr_supremo_autor', $EDICTO, $NOW));
assertCondition(($ownPen['code'] ?? '') === 'SELF_VALIDATION_PROHIBITED', 'La potestad suprema no absuelve la propia pluma (RF-03.2)');
assertCondition(($ownPen['status'] ?? 0) === 403, 'La auto-consagracion responde 403 Forbidden');
assertCondition($decreeCount($pdo, 'spl_propia') === 0, 'La propia pluma no dejo decreto');

// Y el veto alcanza TAMBIEN el rescate y el destierro: la omision del acto
// tambien favorece a la propia casa, y el articulo lo previene.
$forgeSpell($pdo, 'spl_propia_vetada', 'usr_supremo_autor', $CLAN_ORIGEN, 'rejected', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_propia_vetada', 'spl_propia_vetada', 'usr_supremo_autor', 'rejected', 0, $CLAN_ORIGEN);
$ownPenRescue = captureVerdict(static fn () => $service->executeSovereignRescue('spl_propia_vetada', 'usr_supremo_autor', 'experimental', $EDICTO, $NOW));
assertCondition(($ownPenRescue['code'] ?? '') === 'SELF_VALIDATION_PROHIBITED', 'Ni el rescate alcanza la propia pluma');
$ownClanArchive = captureVerdict(static fn () => $service->executeSovereignArchive('spl_espejo', 'usr_supremo_aurora', $EDICTO, false, $NOW));
assertCondition(($ownClanArchive['code'] ?? '') === 'SOVEREIGN_OWN_CLAN_VETO', 'Ni el destierro alcanza el propio estandarte: perdonar la propia casa es el abuso que el articulo previene');
$ownClanRescue = captureVerdict(static fn () => $service->executeSovereignRescue('spl_vetada', 'usr_supremo_aurora', 'experimental', $EDICTO, $NOW));
assertCondition(($ownClanRescue['code'] ?? '') === 'SOVEREIGN_OWN_CLAN_VETO', 'Tampoco el rescate alcanza el propio estandarte');
assertCondition($decreeCount($pdo, 'spl_propia_vetada') === 0 && $decreeCount($pdo, 'spl_vetada') === 0, 'Ningun veto dejo decreto inscrito');

// --- FASE 3: Rescate de una obra vetada (RF-04.3) ---
echo "\nFASE 3: Rescate de una obra vetada (RF-04.3)\n";
$invalidTarget = captureVerdict(static fn () => $service->executeSovereignRescue('spl_vetada', 'usr_supremo', 'draft', $EDICTO, $NOW));
assertCondition(($invalidTarget['code'] ?? '') === 'SOVEREIGN_RESCUE_INVALID_TARGET', 'El rescate solo admite dos destinos canonicos (RF-04.3)');
assertCondition(($invalidTarget['status'] ?? 0) === 400, 'El destino invalido responde 400 Bad Request');
$archivedTarget = captureVerdict(static fn () => $service->executeSovereignRescue('spl_vetada', 'usr_supremo', 'archived', $EDICTO, $NOW));
assertCondition(($archivedTarget['code'] ?? '') === 'SOVEREIGN_RESCUE_INVALID_TARGET', 'El destierro no es un destino de rescate');

// Un rescate defensivo: la obra vetada arrastra una firma viva que ninguna
// version admisible podria sostener.
$forgeSignature($pdo, 'sig_colada', 'spl_vetada', 'usr_maestro', null, $NOW_UTC);
$pdo->prepare("UPDATE spell_reviews SET signatures_count = 1 WHERE spell_id = 'spl_vetada'")->execute();

$rescued = $service->executeSovereignRescue('spl_vetada', 'usr_supremo', 'experimental', $EDICTO, $NOW);
assertCondition($rescued->status === 'experimental', 'El rescate restituye la obra a la deliberacion (RF-04.3)');
assertCondition($rescued->signaturesCount === 0, 'El rescate REINICIA el contador en 0/3');
assertCondition($rescued->signaturesIndicator() === '0/3', 'El indicador de la obra rescatada viaja en 0/3');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_vetada' AND is_revoked = 0") === 0,
    'Ninguna firma sobrevive a la version declarada inadmisible'
);
assertCondition(
    (string) $scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE id = 'sig_colada'") === 'review_rejected',
    'La firma colada cae con el motivo canonico de la obra rechazada'
);
assertCondition(
    (string) $scalar($pdo, "SELECT submitted_at FROM spell_reviews WHERE spell_id = 'spl_vetada'") === $NOW_UTC,
    'La vuelta a la deliberacion queda sellada en el expediente (submitted_at)'
);
assertCondition(
    (string) $scalar($pdo, "SELECT rejected_at FROM spell_reviews WHERE spell_id = 'spl_vetada'") === $NOW_UTC,
    'El veto anterior no se borra: el expediente conserva su memoria'
);
assertCondition(
    (string) $scalar($pdo, "SELECT decree_type FROM sovereign_decrees WHERE spell_id = 'spl_vetada'") === 'rescueToExperimental',
    'El decreto es del tipo canonico del rescate a deliberacion'
);
assertCondition($auditCount($pdo, 'SOVEREIGN_RESCUE', 'spl_vetada') === 1, 'El rescate se inscribe en la Bitacora como SOVEREIGN_RESCUE');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_vetada'") === 0,
    'Una obra devuelta a deliberacion NO acredita gloria alguna (RF-05.3)'
);

$notRejected = captureVerdict(static fn () => $service->executeSovereignRescue('spl_aurora', 'usr_supremo', 'experimental', $EDICTO, $NOW));
assertCondition(($notRejected['code'] ?? '') === 'CANNOT_SOVEREIGN_RESCUE_NON_REJECTED', 'Solo se rescata lo vetado (RF-04.3)');

// El rescate DIRECTO al Tomo: consagra y acredita la gloria negada.
$weeklyBefore = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$forgeSpell($pdo, 'spl_rescatada', 'usr_autora', $CLAN_ORIGEN, 'rejected', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_rescatada', 'spl_rescatada', 'usr_autora', 'rejected', 0, $CLAN_ORIGEN);
$directToTome = $service->executeSovereignRescue('spl_rescatada', 'usr_supremo', 'validated', $EDICTO, $NOW);
assertCondition($directToTome->status === 'validated', 'El rescate puede consagrar de oficio una obra vetada (RF-04.3)');
assertCondition(
    (string) $scalar($pdo, "SELECT decree_type FROM sovereign_decrees WHERE spell_id = 'spl_rescatada'") === 'rescueToValidated',
    'El decreto declara el rescate directo al Tomo'
);
$weeklyAfter = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
assertCondition(
    $weeklyAfter - $weeklyBefore === (int) $scalar($pdo, "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_rescatada'"),
    'La consagracion de oficio acredita la gloria al linaje originario (RF-04.3, RF-05.3)'
);
assertCondition($auditCount($pdo, 'SOVEREIGN_RESCUE', 'spl_rescatada') === 1, 'El rescate directo deja su memoria en la Bitacora');

// --- FASE 4: Destierro postumo y deduccion de gloria (RF-04.4) ---
echo "\nFASE 4: Destierro postumo y deduccion retroactiva (RF-04.4)\n";
$notValidatedArchive = captureVerdict(static fn () => $service->executeSovereignArchive('spl_vetada', 'usr_supremo', $EDICTO, true, $NOW));
assertCondition(($notValidatedArchive['code'] ?? '') === 'CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED', 'Solo se destierra lo que fue consagrado (RF-04.4)');
assertCondition(($notValidatedArchive['status'] ?? 0) === 400, 'El destierro imposible responde 400 Bad Request');
assertCondition($decreeCount($pdo, 'spl_vetada') === 1, 'El destierro imposible no inscribio decreto');

// Destierro SIN deducción: la gloria permanece en la casa.
$gloryBefore = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$forgiven = $service->executeSovereignArchive('spl_soberana', 'usr_supremo', $EDICTO, false, $NOW);
assertCondition($forgiven->status === 'archived', 'La obra consagrada queda desterrada del canon (RF-04.4)');
assertCondition($forgiven->signaturesCount === 0, 'El destierro anula los avales que juzgaron la version fraudulenta');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_soberana' AND is_revoked = 0") === 0,
    'Ningun aval sobrevive al destierro soberano'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_soberana' AND revocation_reason = 'sovereign_archive'") === 2,
    'Los avales caen con el motivo canonico del destierro soberano'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_soberana'") === 'archived',
    'El espejo del conjuro queda desterrado'
);
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $gloryBefore,
    'Sin orden de deduccion, la gloria de la casa no se toca'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED'") === 0,
    'Sin deduccion no hay acto de deduccion en la Bitacora'
);

// Destierro CON deducción: la gloria vuelve atrás, exactamente.
$forgeSpell($pdo, 'spl_fraude', 'usr_autora', $CLAN_ORIGEN, 'validated', $FINGERPRINT, $NOW_UTC, 4);
$forgeReview($pdo, 'rev_fraude', 'spl_fraude', 'usr_autora', 'validated', 3, $CLAN_ORIGEN);
$forgeSignature($pdo, 'sig_fraude_uno', 'spl_fraude', 'usr_maestro', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_fraude_dos', 'spl_fraude', 'usr_supremo', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_fraude_tres', 'spl_fraude', 'usr_supremo_ajeno', $CLAN_AJENO, $NOW_UTC);
$dominionService->awardValidatedSpell('spl_fraude', $NOW);

$weeklyBeforeFraud = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$fraudGlory = (int) $scalar($pdo, "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_fraude'");
$archivedFraud = $service->executeSovereignArchive('spl_fraude', 'usr_supremo', $EDICTO, true, $NOW);
assertCondition($archivedFraud->status === 'archived', 'El fraude manifiesto queda desterrado (RF-04.4)');
assertCondition($fraudGlory === 180, 'La gloria del Circulo IV es 100 + 4 x 20 (RF-03.1 de SPEC-07)');
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $weeklyBeforeFraud - $fraudGlory,
    'La gloria se deduce RETROACTIVAMENTE del marcador que la sostiene (RF-04.4)'
);
assertCondition(
    (int) $scalar($pdo, "SELECT historical_points FROM clans WHERE id = :clanId", [':clanId' => $CLAN_ORIGEN]) === 0,
    'El haber perpetuo no se toca cuando la gloria aun disputaba la semana'
);
assertCondition($decreeCount($pdo, 'spl_fraude') === 1, 'El destierro del fraude dejo su Decreto Imperial');
assertCondition($auditCount($pdo, 'SOVEREIGN_ARCHIVE', 'spl_fraude') === 1, 'El destierro se inscribe como SOVEREIGN_ARCHIVE');
$deductionJustification = (string) $scalar(
    $pdo,
    "SELECT justification FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED' AND target_entity_id = :clanId",
    [':clanId' => $CLAN_ORIGEN],
);
assertCondition(
    str_contains($deductionJustification, (string) $fraudGlory) && str_contains($deductionJustification, 'spl_fraude'),
    'La Bitacora publica la ARITMETICA exacta de la deduccion (Art. III.3)'
);
assertCondition(
    (string) $scalar($pdo, "SELECT target_entity_type FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED' LIMIT 1") === 'clan',
    'El acto de deduccion apunta a la hermandad que pierde la gloria'
);

// El libro de MERITOS sigue siendo un diario de meritos: la sentencia no lo
// ensucia con un asiento de signo torcido (su CHECK exige importes positivos).
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE action_type NOT IN ('spellValidated', 'communityFavorite')") === 0,
    'El libro conserva SOLO meritos: la sentencia vive en la Bitacora, no como asiento del diario'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_fraude'") === 1,
    'El merito originario del conjuro fraudulento sigue en el libro: el diario no se reescribe'
);

// --- FASE 4b: La deducción tras el cierre dominical ---
echo "\nFASE 4b: La deduccion despues del pliegue dominical (RF-04.4)\n";
$forgeSpell($pdo, 'spl_plegada', 'usr_autora', $CLAN_ORIGEN, 'validated', $FINGERPRINT, $NOW_UTC, 2);
$forgeReview($pdo, 'rev_plegada', 'spl_plegada', 'usr_autora', 'validated', 3, $CLAN_ORIGEN);
$dominionService->awardValidatedSpell('spl_plegada', $NOW);
$plegadaGlory = (int) $scalar($pdo, "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_plegada'");
assertCondition($plegadaGlory === 140, 'La gloria del Circulo II es 100 + 2 x 20');

// El cierre dominical de verdad: el marcador semanal se pliega sobre el haber
// perpetuo y se reinicia a cero.
$sunday = new DateTimeImmutable('2026-09-20T23:59:59Z');
$closed = $dominionService->closeWeeklyCycle($sunday);
assertCondition($closed !== null, 'El cierre dominical se consuma y corona a un Clan Regente');
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === 0,
    'El cierre reinicia el marcador semanal de la casa a cero'
);
$historicalAfterFold = (int) $scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
assertCondition($historicalAfterFold > 0, 'La gloria semanal quedo plegada sobre el haber perpetuo (RF-04.3 de SPEC-07)');

$foldedArchive = $service->executeSovereignArchive('spl_plegada', 'usr_supremo', $EDICTO, true, new DateTimeImmutable('2026-09-21T10:00:00Z'));
assertCondition($foldedArchive->status === 'archived', 'La obra plegada tambien puede ser desterrada');
assertCondition(
    (int) $scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $historicalAfterFold - $plegadaGlory,
    'Deducida DESPUES del pliegue, la gloria sale del haber perpetuo (RF-04.4)'
);
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === 0,
    'El marcador semanal ya plegado no vuelve a moverse'
);
$foldedJustification = (string) $scalar(
    $pdo,
    "SELECT justification FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED' AND target_entity_id = :clanId ORDER BY id DESC LIMIT 1",
    [':clanId' => $CLAN_ORIGEN],
);
assertCondition(
    str_contains($foldedJustification, 'haber perpetuo'),
    'La Bitacora nombra el contador del que la gloria salio'
);

// Una casa desterrada conserva su Herencia Ancestral, y alli se le deduce.
$forgeClan($pdo, 'cln_disuelt', 'worldRoots', 'Raices Disueltas', 'archived');
$forgeUser($pdo, 'usr_ermitana', 'Autora Disuelta', 'editor', null, '2026-01-05T00:00:00Z');
$forgeSpell($pdo, 'spl_ancestral', 'usr_ermitana', 'cln_disuelt', 'validated', $FINGERPRINT, $NOW_UTC, 1);
$forgeReview($pdo, 'rev_ancestral', 'spl_ancestral', 'usr_ermitana', 'validated', 3, 'cln_disuelt');
$dominionService->awardValidatedSpell('spl_ancestral', new DateTimeImmutable('2026-09-21T10:00:00Z'));
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => 'cln_disuelt']) === 0,
    'La casa disuelta no disputa la contienda semanal'
);
$ancestralGlory = (int) $scalar($pdo, "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_ancestral'");
assertCondition(
    (int) $scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => 'cln_disuelt']) === $ancestralGlory,
    'La gloria de la casa disuelta se inscribe como Herencia Ancestral (RF-03.7)'
);
$ancestralArchive = $service->executeSovereignArchive('spl_ancestral', 'usr_supremo', $EDICTO, true, new DateTimeImmutable('2026-09-22T10:00:00Z'));
assertCondition($ancestralArchive->status === 'archived', 'La Herencia Ancestral tambien responde por el fraude de su obra');
assertCondition(
    (int) $scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => 'cln_disuelt']) === 0,
    'Deducida la gloria, la casa disuelta queda a cero sin quedar en numeros rojos'
);
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => 'cln_disuelt']) === 0,
    'El marcador semanal de la casa disuelta jamas baja de cero'
);

// Una obra que nunca pago gloria no deja acto de deduccion alguno.
$deductionsSoFar = (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED'");
$forgeSpell($pdo, 'spl_sinmerito', 'usr_autora', $CLAN_ORIGEN, 'validated', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_sinmerito', 'spl_sinmerito', 'usr_autora', 'validated', 3, $CLAN_ORIGEN);
$noMeritArchive = $service->executeSovereignArchive('spl_sinmerito', 'usr_supremo', $EDICTO, true, $NOW);
assertCondition($noMeritArchive->status === 'archived', 'La obra sin merito acreditado tambien se destierra');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SOVEREIGN_POINTS_DEDUCTED'") === $deductionsSoFar,
    'Sin gloria que revocar no se inscribe acto de deduccion: no ocurrio nada que contar'
);
assertCondition($decreeCount($pdo, 'spl_sinmerito') === 1, 'El destierro dejo su decreto aunque no hubiera gloria que deducir');

// --- FASE 5: Atomicidad (RF-04.5, Art. III.3) ---
echo "\nFASE 5: Atomicidad del decreto y de su efecto\n";
$forgeSpell($pdo, 'spl_atomica', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC, 2);
$forgeReview($pdo, 'rev_atomica', 'spl_atomica', 'usr_autora', 'experimental', 1, $CLAN_ORIGEN);
$gloryAtAtomicity = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);

$pdo->exec("CREATE TRIGGER trg_sella_decreto BEFORE INSERT ON audit_log
             WHEN NEW.target_entity_id = 'spl_atomica'
             BEGIN SELECT RAISE(ABORT, 'decreto sellado por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->executeSovereignValidation('spl_atomica', 'usr_supremo', $EDICTO, $NOW)) !== null,
    'La Firma Soberana fracasa cuando su edicto no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_decreto');
assertCondition($decreeCount($pdo, 'spl_atomica') === 0, 'Sin memoria no hay decreto: nada se inscribio a medias (RF-04.5)');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_atomica'") === 'experimental',
    'El expediente siguio en deliberacion: la transicion se deshizo'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_atomica'") === 'experimental',
    'El espejo del conjuro no quedo adelantado'
);
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $gloryAtAtomicity,
    'La gloria no se acredito sobre una firma que no cuajo'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_atomica'") === 0,
    'No quedo merito huerfano de la consagracion que no ocurrio'
);
$conclusive = $service->executeSovereignValidation('spl_atomica', 'usr_supremo', $EDICTO, $NOW);
assertCondition($conclusive->status === 'validated', 'Retirado el sello, el mismo decreto se consuma sin estorbo');

// El sello sobre la DEDUCCION: el destierro y su efecto son un solo gesto.
$forgeSpell($pdo, 'spl_atomica_dos', 'usr_autora', $CLAN_ORIGEN, 'validated', $FINGERPRINT, $NOW_UTC, 1);
$forgeReview($pdo, 'rev_atomica_dos', 'spl_atomica_dos', 'usr_autora', 'validated', 3, $CLAN_ORIGEN);
$forgeSignature($pdo, 'sig_atomica_uno', 'spl_atomica_dos', 'usr_maestro', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_atomica_dos', 'spl_atomica_dos', 'usr_supremo', null, $NOW_UTC);
$forgeSignature($pdo, 'sig_atomica_tres', 'spl_atomica_dos', 'usr_supremo_ajeno', $CLAN_AJENO, $NOW_UTC);
$dominionService->awardValidatedSpell('spl_atomica_dos', $NOW);
$gloryBeforeDeduction = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$pdo->exec("CREATE TRIGGER trg_sella_deduccion BEFORE INSERT ON audit_log
             WHEN NEW.action_type = 'SOVEREIGN_POINTS_DEDUCTED' AND NEW.target_entity_id = '{$CLAN_ORIGEN}'
             BEGIN SELECT RAISE(ABORT, 'deduccion sellada por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->executeSovereignArchive('spl_atomica_dos', 'usr_supremo', $EDICTO, true, $NOW)) !== null,
    'El destierro fracasa cuando su deduccion no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_deduccion');
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $gloryBeforeDeduction,
    'La gloria no se dedujo sobre un destierro que no cuajo: el gesto se deshizo entero'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_atomica_dos'") === 'validated',
    'El expediente volvio a consagrado'
);
assertCondition($decreeCount($pdo, 'spl_atomica_dos') === 0, 'No quedo decreto huerfano del destierro fallido');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_atomica_dos' AND is_revoked = 0") === 3,
    'Los tres avales sobrevivieron: el destierro que no cuajo no los anulo'
);
$conclusiveArchive = $service->executeSovereignArchive('spl_atomica_dos', 'usr_supremo', $EDICTO, true, $NOW);
assertCondition($conclusiveArchive->status === 'archived', 'Retirado el sello, el mismo destierro se consuma');

// --- FASE 6: Auditoria estatica y cruce de catalogos ---
echo "\nFASE 6: Auditoria estatica y cruce de catalogos\n";
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
    str_contains($serviceSource, '->prepare(')
    && !str_contains($serviceSource, '$this->pdo->exec(')
    && !str_contains($serviceSource, '$this->pdo->query('),
    'Todo acceso al motor pasa por sentencias preparadas'
);
assertCondition(
    !str_contains($serviceSource, 'UPDATE spells') && !str_contains($serviceSource, 'UPDATE spell_reviews')
    && !str_contains($serviceSource, 'INSERT INTO audit_log'),
    'El servicio NO escribe el espejo ni la bitacora: delega en el unico escritor de cada uno'
);
assertCondition(
    str_contains($serviceSource, 'findAndLockById('),
    'El decreto abre transaccion y TOMA el bloqueo del expediente antes de leer (RNF-02)'
);
assertCondition(
    str_contains($serviceSource, 'insertDecree(') && str_contains($serviceSource, 'recordAction('),
    'El decreto y su memoria viajan por los canales unicos de las Tareas 1.4 y 1.3 de SPEC-03'
);
// Prohibición quirúrgica de reloj del sistema (RNF-01): llamadas reales
// de función, no subcadenas casuales ("loadCandid**date(**" contiene
// literalmente "date("). También se veta la época clásica de
// DateTime en constructores sin zona explícita.
$clockCalls = preg_match_all('/(?<![\w$])(time|mktime|gmmktime|strtotime)\s*\(/', $serviceSource)
    + preg_match_all('/(?<![\w$\\])date\s*\(/', $serviceSource)
    + preg_match_all('/new\s+\\?DateTime(Immutable)?\s*\(\s*(?!\'now\'|"now")/', $serviceSource);
assertCondition(
    $clockCalls === 0,
    'No lee el reloj del sistema: el instante se inyecta (RNF-01)'
);
assertCondition(
    preg_match('/[\x{00e1}\x{00e9}\x{00ed}\x{00f3}\x{00fa}\x{00f1}\x{00bf}\x{00a1}]/u', $serviceSource) === 1,
    'La narrativa y los comentarios van en noble castellano (RNF-03, Articulo V)'
);
assertCondition(
    ($serviceClass)::SOVEREIGN_ROLE === 'supremeAdmin',
    'El rango soberano es el que declara la especificacion (RF-04)'
);
assertCondition(
    ($serviceClass)::MIN_IMPERIAL_DECREE_LENGTH === ($decreeClass)::MIN_IMPERIAL_DECREE_LENGTH
    && ($serviceClass)::MIN_IMPERIAL_DECREE_LENGTH === 20,
    'El umbral del Edicto Imperial es uno solo: servicio y repositorio no divergen (RF-04.5)'
);
assertCondition(
    ($serviceClass)::CANONICAL_RESCUE_TARGETS === ['experimental', 'validated'],
    'El rescate solo admite los dos destinos que la especificacion declara (RF-04.3)'
);
assertCondition(
    ($serviceClass)::sovereignCanon()['decreeTargetType'] === 'spell'
    && in_array('spell', ['spell', 'clan', 'user'], true),
    'El decreto apunta al conjuro y el acto de deduccion a la hermandad'
);
assertCondition(
    ($reversalClass)::COUNTER_WEEKLY === 'weekly' && ($reversalClass)::COUNTER_HISTORICAL === 'historical',
    'El recibo declara los dos contadores canonicos de la gloria'
);

$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
foreach (['SOVEREIGN_VALIDATION', 'SOVEREIGN_RESCUE', 'SOVEREIGN_ARCHIVE', 'SOVEREIGN_POINTS_DEDUCTED'] as $act) {
    assertCondition(str_contains($auditEntrySource, "'{$act}'"), "El acto {$act} figura en el catalogo cerrado de AuditEntry");
}
$auditViewSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
foreach (['SOVEREIGN_VALIDATION', 'SOVEREIGN_RESCUE', 'SOVEREIGN_ARCHIVE', 'SOVEREIGN_POINTS_DEDUCTED'] as $act) {
    assertCondition(str_contains($auditViewSource, $act . ':'), "El acto {$act} tiene su rotulo castellano en la Bitacora publica");
}
assertCondition(
    str_contains((string) file_get_contents($projectRoot . '/src/Repositories/ImperialDecreeRepository.php'), 'CANONICAL_DECREE_TYPES')
    && str_contains(
        (string) file_get_contents($projectRoot . '/src/Repositories/ImperialDecreeRepository.php'),
        'TYPE_REVOKE_AND_ARCHIVE',
    ),
    'Los cuatro decretos canonicos viven en el repositorio de la Tarea 1.4: el servicio no los reimplementa'
);
assertCondition(
    str_contains((string) file_get_contents($projectRoot . '/src/Services/WeeklyDominionService.php'), 'revokeValidatedSpellGlory'),
    'La deduccion retroactiva vive JUNTO al otorgamiento: una sola aritmetica de la gloria (RF-04.4)'
);

// --- VEREDICTO ---
unset($service, $dominionService);
$pdo = null;
gc_collect_cycles();
@unlink($databasePath);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_sovereign.sqlite'),
    'El arnes jamas escribe la base efimera dentro del repositorio'
);

echo "\n== RESULTADO: {$assertsPassed} asertos pasados, {$assertsFailed} fallidos ==\n";
if ($assertsFailed === 0) {
    echo "TAREA 2.5 VERIFICADA: la Firma Soberana consagra la obra conservando sus avales reales,\n";
    echo "los borradores privados y las obras del propio estandarte quedan fuera de toda potestad,\n";
    echo "el rescate devuelve la obra vetada a 0/3, y el destierro anula sus avales y deduce\n";
    echo "retroactivamente la gloria del linaje sin dejarle jamas numeros rojos.\n";
    exit(0);
}

echo "TAREA 2.5 NO VERIFICADA: revisense los asertos en rojo.\n";
exit(1);

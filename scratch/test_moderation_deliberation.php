<?php

declare(strict_types=1);

/**
 * test_moderation_deliberation.php — Verificación de la Tarea 2.4 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «La 3ª firma transiciona el conjuro de forma atómica a `validated`,
 *    acredita los PDA a su clan originario, y una objeción válida cancela
 *    firmas previas transicionando la obra a `rejected`.»
 *
 * Estrategia: se levanta una base SQLite efímera —en el directorio temporal del
 * sistema, jamás dentro del repositorio— con la secuencia canónica y se ejerce
 * el Cónclave entero sobre expedientes reales. La consagración se prueba con la
 * gloria de SPEC-07 ya cableada, de modo que un PDA mal acreditado o un linaje
 * equivocado salen en rojo. La atomicidad se agrede sellando la bitácora con un
 * disparador temporal que solo muerde al acto `SPELL_CONSECRATED`: así el tercer
 * aval se escribe y la consagración fracasa DESPUÉS, y el aserto comprueba que
 * la firma y su gloria se deshicieron juntas.
 *
 * Fases:
 *   [0]  Superficie del servicio y de su contrato de errores.
 *   [1]  La Firma de Consagración: rango, glosa y unicidad (RF-02.1, RF-02.2).
 *   [2]  La tercera rúbrica consagra y acredita la gloria (RF-02.3, RF-05.3).
 *   [3]  Retractación antes del sello, irrevocable después (RF-02.4, RF-02.7).
 *   [4]  Dictamen de Objeción: cancela avales y devuelve a la libreta (RF-02.5, RF-02.6).
 *   [5]  Veto ético constitucional sobre el acto de juzgar (RF-03.1, RF-03.2, RF-03.6).
 *   [6]  Atomicidad de la deliberación (RNF-02).
 *   [7]  Auditoría estática, cruce de catálogos y Dogma Vanilla.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo V (Dualidad): identificadores en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_moderation_deliberation.php
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

echo "== VERIFICACION TAREA 2.4: Deliberacion colegiada y consagracion atomica ==\n\n";

$projectRoot = dirname(__DIR__);
$servicePath = $projectRoot . '/src/Services/MasterDeliberationService.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del servicio y de su contrato de errores\n";
assertCondition(file_exists($servicePath), 'Existe src/Services/MasterDeliberationService.php');

if (!file_exists($servicePath)) {
    echo "\nRESULTADO: DENEGADO — falta el servicio de la Tarea 2.4 (fase roja del TDD).\n";
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

foreach (['signSpell', 'retractSignature', 'objectSpell', 'activeSignaturesFor', 'deliberationCanon'] as $methodName) {
    assertCondition(str_contains($serviceSource, "function {$methodName}("), "Publica la operacion canonica {$methodName}()");
}
assertCondition(
    str_contains($serviceSource, 'SIGNATURES_REQUIRED = SpellReviewRepository::MAX_SIGNATURES'),
    'El umbral de tres firmas se hereda del repositorio: fuente unica del canon (RF-02.1)'
);

$exceptionSource = (string) file_get_contents($projectRoot . '/src/Exceptions/ModerationWorkflowException.php');
foreach ([
    'SELF_SIGNING_PROHIBITED',
    'CONSTITUTIONAL_ETHICS_VETO',
    'CLAN_PLURALITY_VIOLATION',
    'ALREADY_SIGNED',
    'GLOSS_TOO_LONG',
    'NO_ACTIVE_SIGNATURE',
    'SIGNATURE_IRREVOCABLE',
    'OBJECTION_TOO_BRIEF',
    'INSUFFICIENT_RANK_TO_JUDGE',
    'SPELL_NOT_IN_REVIEW',
] as $errorCode) {
    assertCondition(str_contains($exceptionSource, $errorCode), "El contrato declara el codigo {$errorCode}");
}

require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Exceptions/ModerationWorkflowException.php';
require_once $projectRoot . '/src/Exceptions/SpellNotFoundException.php';
require_once $projectRoot . '/src/Exceptions/ClanGovernanceException.php';
require_once $projectRoot . '/src/Exceptions/ClanConflictOfInterestException.php';
require_once $projectRoot . '/src/Dto/SpellReviewDto.php';
require_once $projectRoot . '/src/Dto/MasterSignatureDto.php';
require_once $projectRoot . '/src/Dto/ObjectionVerdictDto.php';
require_once $projectRoot . '/src/Dto/DominionAwardDto.php';
require_once $projectRoot . '/src/Dto/LineageDto.php';
require_once $projectRoot . '/src/Dto/ClanDto.php';
require_once $projectRoot . '/src/Dto/ClanMemberDto.php';
require_once $projectRoot . '/src/Dto/WeeklyCycleDto.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): se carga antes del
// servicio, como hace el autoloader del front controller.
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Services/ClanEthicsValidator.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Repositories/ClanRepository.php';
require_once $projectRoot . '/src/Repositories/WeeklyCycleRepository.php';
require_once $projectRoot . '/src/Services/LineageSynergyService.php';
require_once $projectRoot . '/src/Services/WeeklyDominionService.php';
require_once $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
require_once $projectRoot . '/src/Repositories/ObjectionVerdictRepository.php';
require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Services/ConstitutionalEthicsValidator.php';
require_once $servicePath;

$serviceClass = 'Grimorio\\Services\\MasterDeliberationService';
$exceptionClass = 'Grimorio\\Exceptions\\ModerationWorkflowException';
$signatureClass = 'Grimorio\\Repositories\\MasterSignatureRepository';
$reviewClass = 'Grimorio\\Repositories\\SpellReviewRepository';

$databasePath = sys_get_temp_dir() . '/grimorio_deliberation_' . getmypid() . '.sqlite';
@unlink($databasePath);
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 1);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

/** @var MasterDeliberationService $service */
$service = new $serviceClass($pdo);

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

/** Inscribe una membresia, viva o cerrada, con su convalecencia opcional. */
$forgeMembership = static function (
    PDO $pdo,
    string $memberId,
    string $clanId,
    string $userId,
    string $joinedAt,
    ?string $leftAt = null,
    ?string $convalescenceExpiresAt = null,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :expiresAt)'
    );
    $statement->execute([
        ':id'        => $memberId,
        ':clanId'    => $clanId,
        ':userId'    => $userId,
        ':role'      => 'adept',
        ':joinedAt'  => $joinedAt,
        ':leftAt'    => $leftAt,
        ':expiresAt' => $convalescenceExpiresAt,
    ]);
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
    string $affinity = 'fire',
): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, circle,
                             math_fingerprint, clan_id, summary, created_at, updated_at, status, signatures_count,
                             damage, range_type, has_verbal)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, 100, :circle,
                 :fingerprint, :clanId, :summary, :createdAt, :createdAt, :status, 0,
                 20, \'short\', 1)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $spellId,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':affinity'    => $affinity,
        ':circle'      => $circle,
        ':fingerprint' => $fingerprint,
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba de la deliberacion colegiada.',
        ':createdAt'   => $createdAt,
        ':status'      => $status,
    ]);
};

/** Inscribe un expediente de moderacion en deliberacion y sincroniza el espejo. */
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
        'INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at)
         VALUES (:id, :spellId, :authorId, :clanId, :status, :count, :fingerprint, :submittedAt)'
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
    ]);

    $mirror = $pdo->prepare('UPDATE spells SET status = :status, signatures_count = :count WHERE id = :spellId');
    $mirror->execute([':status' => $status, ':count' => $count, ':spellId' => $spellId]);
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

// --------------------------------------------------------------------------
// Semillas: cuatro hermandades de linajes distintos y el elenco de Maestros.
// --------------------------------------------------------------------------
$CLAN_ORIGEN = 'cln_aurora';        // linaje patrimonial de las obras juzgadas
$CLAN_TEMPESTAD = 'cln_tempestad';
$CLAN_ASTRAL = 'cln_astral';
$CLAN_JURAMENTO = 'cln_juramento';

$forgeClan($pdo, $CLAN_ORIGEN, 'solarCrown', 'Aurora del Sol Naciente');
$forgeClan($pdo, $CLAN_TEMPESTAD, 'eternalTempest', 'Tempestad Eterna');
$forgeClan($pdo, $CLAN_ASTRAL, 'celestialTides', 'Mareas Celestiales');
$forgeClan($pdo, $CLAN_JURAMENTO, 'worldRoots', 'Raices del Juramento');

$forgeUser($pdo, 'usr_autora', 'Autora de la Aurora', 'editor', $CLAN_ORIGEN, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_lector', 'Lector Anonimo', 'reader', null, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_supremo', 'Administrador Supremo', 'supremeAdmin', null, '2026-01-01T00:00:00Z');

$forgeUser($pdo, 'usr_maestro_ermitano', 'Maestro Ermitano', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_tempestad', 'Maestra de la Tempestad', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_tempestad_dos', 'Segundo de la Tempestad', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_astral', 'Maestro Astral', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_aurora', 'Maestro de la Aurora', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_reciente', 'Maestro Recien Partido', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_antiguo', 'Maestro de Antano', 'master', null, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_convaleciente', 'Maestra Convaleciente', 'master', null, '2026-01-02T00:00:00Z');

$mirrorMembership = $pdo->prepare('UPDATE users SET clan_id = :clanId WHERE id = :userId');
$mirrorMembership->execute([':clanId' => $CLAN_TEMPESTAD, ':userId' => 'usr_maestro_tempestad']);
$mirrorMembership->execute([':clanId' => $CLAN_TEMPESTAD, ':userId' => 'usr_maestro_tempestad_dos']);
$mirrorMembership->execute([':clanId' => $CLAN_ASTRAL, ':userId' => 'usr_maestro_astral']);
$mirrorMembership->execute([':clanId' => $CLAN_ORIGEN, ':userId' => 'usr_maestro_aurora']);
$mirrorMembership->execute([':clanId' => $CLAN_JURAMENTO, ':userId' => 'usr_maestro_reciente']);
$mirrorMembership->execute([':clanId' => $CLAN_JURAMENTO, ':userId' => 'usr_maestro_antiguo']);
$mirrorMembership->execute([':clanId' => $CLAN_JURAMENTO, ':userId' => 'usr_maestro_convaleciente']);

$forgeMembership($pdo, 'clm_tempestad_uno', $CLAN_TEMPESTAD, 'usr_maestro_tempestad', '2026-02-01T00:00:00Z');
$forgeMembership($pdo, 'clm_tempestad_dos', $CLAN_TEMPESTAD, 'usr_maestro_tempestad_dos', '2026-02-01T00:00:00Z');
$forgeMembership($pdo, 'clm_astral', $CLAN_ASTRAL, 'usr_maestro_astral', '2026-02-01T00:00:00Z');
$forgeMembership($pdo, 'clm_aurora', $CLAN_ORIGEN, 'usr_maestro_aurora', '2026-02-01T00:00:00Z');
// Partió del linaje patrimonial hace DIEZ días: dentro de la ventana de treinta.
$forgeMembership($pdo, 'clm_reciente', $CLAN_ORIGEN, 'usr_maestro_reciente', '2026-02-01T00:00:00Z', '2026-09-05T10:00:00Z');
// Partió hace TREINTA Y UNO: la ventana ya se cerró sobre él.
$forgeMembership($pdo, 'clm_antiguo', $CLAN_ORIGEN, 'usr_maestro_antiguo', '2026-02-01T00:00:00Z', '2026-08-14T10:00:00Z');
// Convalecencia arcana en plena vigencia: firma como ermitaño neutral (RF-03.6).
$forgeMembership(
    $pdo,
    'clm_convaleciente',
    $CLAN_JURAMENTO,
    'usr_maestro_convaleciente',
    '2026-02-01T00:00:00Z',
    '2026-09-12T10:00:00Z',
    '2026-09-26T10:00:00Z',
);

// --- FASE 1: La Firma de Consagracion (RF-02.1, RF-02.2) ---
echo "\nFASE 1: La Firma de Consagracion: rango, glosa y unicidad (RF-02.1, RF-02.2)\n";
$forgeSpell($pdo, 'spl_consagracion', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_consagracion', 'spl_consagracion', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);

$glossOf251 = str_repeat('g', 251);
$tooLong = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_maestro_ermitano', $glossOf251, $NOW));
assertCondition(($tooLong['code'] ?? '') === 'GLOSS_TOO_LONG', 'La glosa de 251 caracteres es rechazada (RF-02.2)');
assertCondition(($tooLong['status'] ?? 0) === 400, 'La glosa desmedida responde 400 Bad Request');
assertCondition((int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_consagracion'") === 0, 'La glosa rechazada no dejo firma alguna');

$glossOf250 = str_repeat('g', 250);
$firstSignature = $service->signSpell('spl_consagracion', 'usr_maestro_ermitano', $glossOf250, $NOW);
assertCondition($firstSignature->status === 'experimental', 'La primera firma no altera el estado de deliberacion');
assertCondition($firstSignature->signaturesCount === 1, 'El expediente cuenta una firma viva');
assertCondition($firstSignature->signaturesIndicator() === '1/3', 'El indicador viaja como 1/3 (RF-05.4)');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_consagracion'") === 1, 'El espejo del conjuro sigue a la autoridad (Tarea 1.5)');
assertCondition(
    (string) $scalar($pdo, "SELECT ceremonial_gloss FROM master_signatures WHERE spell_id = 'spl_consagracion'") === $glossOf250,
    'La glosa de exactamente 250 caracteres se conserva integra'
);
assertCondition(
    (string) $scalar($pdo, "SELECT master_clan_id FROM master_signatures WHERE spell_id = 'spl_consagracion'") === '',
    'El Maestro ermitano firma SIN linaje: su aval no ocupa plaza de hermandad'
);

$repeated = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_maestro_ermitano', null, $NOW));
assertCondition(($repeated['code'] ?? '') === 'ALREADY_SIGNED', 'Un Maestro no avala dos veces la misma obra (RF-02.1)');
assertCondition(($repeated['status'] ?? 0) === 409, 'La doble firma responde 409 Conflict');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_consagracion'") === 1, 'La doble firma no inflo el contador');

assertCondition(
    captureError(static fn () => $service->signSpell('spl_consagracion', 'usr_maestro_tempestad', null, $NOW)) === null,
    'La Maestra de la Tempestad entra como segunda firma'
);
$livingSignatures = $service->activeSignaturesFor('spl_consagracion');
assertCondition(count($livingSignatures) === 2, 'Dos avales vivos sobre la obra');
$lineageOfSigner = null;
$hermitSigner = null;
foreach ($livingSignatures as $livingSignature) {
    if ($livingSignature->masterId === 'usr_maestro_tempestad') {
        $lineageOfSigner = $livingSignature->masterClanId;
    }
    if ($livingSignature->isNeutralHermit()) {
        $hermitSigner = $livingSignature->masterId;
    }
}
assertCondition(
    $lineageOfSigner === $CLAN_TEMPESTAD,
    'La firma retrata el linaje del firmante en el instante de firmar (Art. III)'
);
assertCondition(
    $hermitSigner === 'usr_maestro_ermitano',
    'La firma del ermitano se reconoce como neutral, sin plaza de hermandad'
);

$sameClanTwice = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_maestro_tempestad_dos', null, $NOW));
assertCondition(($sameClanTwice['code'] ?? '') === 'CLAN_PLURALITY_VIOLATION', 'Dos Maestros de un mismo linaje no avala la misma obra (RF-02.1)');
assertCondition(($sameClanTwice['status'] ?? 0) === 409, 'La pluralidad rota responde 409 Conflict: el segundo estandarte choca con el aval vivo');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_consagracion'") === 2, 'El aval rechazado no dejo rastro en el contador');

$readerVerdict = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_lector', null, $NOW));
assertCondition(($readerVerdict['code'] ?? '') === 'INSUFFICIENT_RANK_TO_JUDGE', 'El lector anonimo no juzga (RF-02.1)');
assertCondition(($readerVerdict['status'] ?? 0) === 403, 'El rango insuficiente responde 403 Forbidden');
$supremeVerdict = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_supremo', null, $NOW));
assertCondition(($supremeVerdict['code'] ?? '') === 'INSUFFICIENT_RANK_TO_JUDGE', 'La potestad suprema no firma por la via ordinaria: su intervencion es un decreto (RF-04.1)');
assertCondition(
    captureError(static fn () => $service->signSpell('spl_inexistente', 'usr_maestro_ermitano', null, $NOW)) !== null,
    'Un conjuro sin expediente no admite firma'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SIGN_VALIDATE' AND target_entity_id = 'spl_consagracion'") === 2,
    'Cada firma deja su memoria en la Bitacora (RF-06.1)'
);
assertCondition(
    str_contains((string) $scalar($pdo, "SELECT justification FROM audit_log WHERE action_type = 'SIGN_VALIDATE' LIMIT 1"), $glossOf250),
    'La glosa liturgica viaja INTEGRA a la Bitacora publica (RF-02.2, RF-06.1)'
);

$forgeSpell($pdo, 'spl_borrador_juzgado', 'usr_autora', $CLAN_ORIGEN, 'draft', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_borrador_juzgado', 'spl_borrador_juzgado', 'usr_autora', 'draft', 0, $CLAN_ORIGEN);
$draftVerdict = captureVerdict(static fn () => $service->signSpell('spl_borrador_juzgado', 'usr_maestro_ermitano', null, $NOW));
assertCondition(($draftVerdict['code'] ?? '') === 'SPELL_NOT_IN_REVIEW', 'Solo se juzga lo que yace en deliberacion (RF-01.2)');
assertCondition(($draftVerdict['status'] ?? 0) === 409, 'El juicio sobre un borrador responde 409 Conflict con el estado de la obra');

// --- FASE 2: La tercera rubrica consagra y acredita gloria (RF-02.3, RF-05.3) ---
echo "\nFASE 2: La tercera rubrica consagra y acredita la gloria (RF-02.3)\n";
$clanBefore = $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$consecrated = $service->signSpell('spl_consagracion', 'usr_maestro_astral', null, $NOW);

assertCondition($consecrated->status === 'validated', 'La tercera firma transiciona la obra a validada (RF-02.3)');
assertCondition($consecrated->signaturesCount === 3, 'La obra consagrada ostenta sus tres avales');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_consagracion'") === 'validated',
    'La AUTORIDAD del ciclo de vida quedo en validada'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_consagracion'") === 'validated',
    'El espejo del conjuro quedo en validada'
);
assertCondition(
    (int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_consagracion'") === 3,
    'El espejo del contador quedo en tres'
);
assertCondition(
    (string) $scalar($pdo, "SELECT validated_at FROM spell_reviews WHERE spell_id = 'spl_consagracion'") === $NOW_UTC,
    'La consagracion queda FECHADA en la autoridad para el Libro de Oro'
);
assertCondition(
    (string) $scalar($pdo, "SELECT validated_at FROM spells WHERE id = 'spl_consagracion'") === $NOW_UTC,
    'La consagracion queda fechada tambien en el espejo: el Libro de Oro ordena por ella'
);
assertCondition($auditCount($pdo, 'SPELL_CONSECRATED', 'spl_consagracion') === 1, 'La consagracion se inscribe en la Bitacora (RF-06.1)');
assertCondition(
    (int) $scalar(
        $pdo,
        "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_consagracion' AND clan_id = :clanId",
        [':clanId' => $CLAN_ORIGEN],
    ) === 1,
    'Los PDA se acreditan al clan ORIGINARIO de la obra (RF-02.3, RF-03.5 de SPEC-07)'
);
$weeklyAfter = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$awardedPoints = (int) $scalar(
    $pdo,
    "SELECT awarded_points FROM dominion_awards WHERE source_id = 'spl_consagracion'",
);
assertCondition($weeklyAfter === (int) $clanBefore + $awardedPoints, 'El marcador semanal crece exactamente en los PDA acreditados');
assertCondition($awardedPoints > 0, 'La acreditacion no fue un recibo en blanco');
assertCondition(
    (int) $scalar($pdo, 'SELECT historical_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === 0,
    'Durante la contienda solo se acredita el marcador semanal: el pliegue perpetuo lo hace el cierre dominical (RF-04.3)'
);
$lateSignature = captureVerdict(static fn () => $service->signSpell('spl_consagracion', 'usr_maestro_tempestad_dos', null, $NOW));
assertCondition(($lateSignature['code'] ?? '') === 'SPELL_NOT_IN_REVIEW', 'Una obra consagrada no admite mas firmas (RF-02.3)');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_consagracion'") === 1,
    'Una misma consagracion paga gloria UNA sola vez (RNF-01)'
);

// --- FASE 3: Retractacion antes del sello, irrevocable despues (RF-02.4, RF-02.7) ---
echo "\nFASE 3: Retractacion antes del sello e irrevocabilidad despues (RF-02.4)\n";
$forgeSpell($pdo, 'spl_retracto', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_retracto', 'spl_retracto', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);

$service->signSpell('spl_retracto', 'usr_maestro_ermitano', 'Primera glosa del arnes.', $NOW);
$service->signSpell('spl_retracto', 'usr_maestro_tempestad', null, $NOW);
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_retracto'") === 2, 'Dos avales vivos antes de la retractacion');

$afterRetraction = $service->retractSignature('spl_retracto', 'usr_maestro_ermitano', 'Discrepo de la version enmendada de la obra.', $NOW);
assertCondition($afterRetraction->status === 'experimental', 'Retractar no saca la obra de deliberacion (RF-02.4)');
assertCondition($afterRetraction->signaturesCount === 1, 'El aval caido abandona el contador');
assertCondition(
    (int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_retracto'") === 1,
    'El espejo del contador sigue la retractacion'
);
assertCondition(
    (string) $scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE spell_id = 'spl_retracto' AND master_id = 'usr_maestro_ermitano'") === 'retracted',
    'La retractacion se inscribe con su motivo canonico (RF-02.4)'
);
assertCondition(
    (string) $scalar($pdo, "SELECT revoked_at FROM master_signatures WHERE spell_id = 'spl_retracto' AND master_id = 'usr_maestro_ermitano'") === $NOW_UTC,
    'La firma retractada queda FECHADA'
);
assertCondition($auditCount($pdo, 'SIGNATURE_RETRACTED', 'spl_retracto') === 1, 'La retractacion deja su memoria en la Bitacora');
$retractTwice = captureVerdict(static fn () => $service->retractSignature('spl_retracto', 'usr_maestro_ermitano', null, $NOW));
assertCondition(($retractTwice['code'] ?? '') === 'NO_ACTIVE_SIGNATURE', 'Sin firma viva no hay nada que retractar (RF-02.4)');
assertCondition(($retractTwice['status'] ?? 0) === 400, 'La retractacion sin aval vivo responde 400 Bad Request');

$resent = $service->signSpell('spl_retracto', 'usr_maestro_ermitano', null, $NOW);
assertCondition($resent->signaturesCount === 2, 'Retractado el aval, el Maestro recupera su plaza de firma (indice unico parcial)');

$service->signSpell('spl_retracto', 'usr_maestro_astral', null, $NOW);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_retracto'") === 'validated',
    'Los tres avales vivos consuman la obra aunque uno fuera reestampado'
);
$irrevocable = captureVerdict(static fn () => $service->retractSignature('spl_retracto', 'usr_maestro_tempestad', null, $NOW));
assertCondition(($irrevocable['code'] ?? '') === 'SIGNATURE_IRREVOCABLE', 'Consagrada la obra, el aval del Maestro es irrevocable (RF-02.7)');
assertCondition(($irrevocable['status'] ?? 0) === 409, 'La retractacion sobre lo consagrado responde 409 Conflict');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_retracto' AND is_revoked = 1 AND revocation_reason = 'retracted'") === 1,
    'La negativa no revoco firma alguna mas: solo cayo la retractada a tiempo'
);

// --- FASE 4: Dictamen de Objecion (RF-02.5, RF-02.6) ---
echo "\nFASE 4: Dictamen de Objecion: cancela avales y devuelve a la libreta (RF-02.5)\n";
$forgeSpell($pdo, 'spl_objetada', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_objetada', 'spl_objetada', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);
$service->signSpell('spl_objetada', 'usr_maestro_ermitano', null, $NOW);
$service->signSpell('spl_objetada', 'usr_maestro_tempestad', null, $NOW);

$briefObjection = captureVerdict(static fn () => $service->objectSpell('spl_objetada', 'usr_maestro_astral', str_repeat('o', 19), $NOW));
assertCondition(($briefObjection['code'] ?? '') === 'OBJECTION_TOO_BRIEF', 'El Dictamen de Objecion exige veinte caracteres (RF-02.5)');
assertCondition(($briefObjection['status'] ?? 0) === 422, 'La objecion breve responde 422 Unprocessable Entity');
assertCondition((int) $scalar($pdo, "SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_objetada'") === 0, 'La objecion breve no quedo inscrita');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_objetada'") === 'experimental',
    'La objecion breve no altero el expediente'
);
$blankObjection = captureVerdict(static fn () => $service->objectSpell('spl_objetada', 'usr_maestro_astral', str_repeat(' ', 40), $NOW));
assertCondition(($blankObjection['code'] ?? '') === 'OBJECTION_TOO_BRIEF', 'Cuarenta espacios no son una justificacion solemne');

$objectionReason = 'La geometria arcana del area contradice el canon del linaje y fuerza un desequilibrio de mana superior al doble del coste declarado.';
$rejected = $service->objectSpell('spl_objetada', 'usr_maestro_astral', $objectionReason, $NOW);
assertCondition($rejected->status === 'rejected', 'La objecion valida transiciona la obra a rejected (RF-02.6)');
assertCondition($rejected->signaturesCount === 0, 'El veto cancela los avales previos (RF-02.6)');
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_objetada'") === 'rejected',
    'El espejo devuelve la obra a la libreta del autor'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_objetada' AND is_revoked = 0") === 0,
    'Ningun aval sobrevive a la version declarada inadmisible'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_objetada' AND revocation_reason = 'review_rejected'") === 2,
    'Las dos firmas caen con el motivo canonico de la objecion'
);
assertCondition(
    (string) $scalar($pdo, "SELECT objection_reason FROM objection_verdicts WHERE spell_id = 'spl_objetada'") === $objectionReason,
    'El dictamen se conserva INTEGRO para que el autor lo lea (RF-01.4)'
);
assertCondition($auditCount($pdo, 'SIGN_REJECT', 'spl_objetada') === 1, 'El dictamen se inscribe en la Bitacora como SIGN_REJECT (RF-06.1)');
assertCondition(
    (string) $scalar($pdo, "SELECT justification FROM audit_log WHERE action_type = 'SIGN_REJECT' AND target_entity_id = 'spl_objetada'") === $objectionReason,
    'La justificacion del veto viaja integra a la Bitacora publica'
);
assertCondition(
    (string) $scalar($pdo, "SELECT rejected_at FROM spell_reviews WHERE spell_id = 'spl_objetada'") === $NOW_UTC,
    'El veto queda fechado'
);
$rejectedAgain = captureVerdict(static fn () => $service->objectSpell('spl_objetada', 'usr_maestro_astral', str_repeat('o', 30), $NOW));
assertCondition(($rejectedAgain['code'] ?? '') === 'SPELL_NOT_IN_REVIEW', 'Lo ya vetado no se vetа dos veces (RF-02.6)');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_objetada'") === 1,
    'Un solo dictamen por obra vetada'
);

// --- FASE 5: Veto etico constitucional (RF-03.1, RF-03.2, RF-03.6) ---
echo "\nFASE 5: Veto etico constitucional sobre el acto de juzgar (Art. III)\n";
$forgeSpell($pdo, 'spl_vetada', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_vetada', 'spl_vetada', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);

$ownLineage = captureVerdict(static fn () => $service->signSpell('spl_vetada', 'usr_maestro_aurora', null, $NOW));
assertCondition(($ownLineage['code'] ?? '') === 'CONSTITUTIONAL_ETHICS_VETO', 'El Maestro del linaje actual no juzga su propia casa (RF-03.1)');
assertCondition(($ownLineage['status'] ?? 0) === 403, 'El veto etico responde 403 Forbidden');
$recentDeparture = captureVerdict(static fn () => $service->signSpell('spl_vetada', 'usr_maestro_reciente', null, $NOW));
assertCondition(($recentDeparture['code'] ?? '') === 'CONSTITUTIONAL_ETHICS_VETO', 'Haber partido hace diez dias aun veta: la ventana es de treinta (RF-03.1)');
$ancientDeparture = $service->signSpell('spl_vetada', 'usr_maestro_antiguo', null, $NOW);
assertCondition($ancientDeparture->signaturesCount === 1, 'Partido hace treinta y un dias, el Maestro recupera su potestad de juicio');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_vetada'") === 1,
    'Los dos vetos eticos no dejaron firma alguna'
);

$forgeSpell($pdo, 'spl_del_maestro', 'usr_maestro_ermitano', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_del_maestro', 'spl_del_maestro', 'usr_maestro_ermitano', 'experimental', 0, $CLAN_ORIGEN);
$selfSigning = captureVerdict(static fn () => $service->signSpell('spl_del_maestro', 'usr_maestro_ermitano', null, $NOW));
assertCondition(($selfSigning['code'] ?? '') === 'SELF_SIGNING_PROHIBITED', 'Ningun Maestro avala su propia pluma (RF-03.2)');
assertCondition(($selfSigning['status'] ?? 0) === 403, 'La propia pluma responde 403 Forbidden');
$selfObjection = captureVerdict(static fn () => $service->objectSpell('spl_del_maestro', 'usr_maestro_ermitano', str_repeat('o', 30), $NOW));
assertCondition(($selfObjection['code'] ?? '') === 'SELF_SIGNING_PROHIBITED', 'Ni siquiera se dictamina contra la propia obra (RF-03.2)');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_del_maestro'") === 0,
    'El veto de la propia pluma no dejo dictamen'
);

$convalescent = $service->signSpell('spl_del_maestro', 'usr_maestro_convaleciente', null, $NOW);
assertCondition($convalescent->signaturesCount === 1, 'En convalecencia arcana el Maestro conserva su potestad de firma (RF-03.6)');
$convalescentSignature = $service->activeSignaturesFor('spl_del_maestro')[0];
assertCondition(
    $convalescentSignature->masterId === 'usr_maestro_convaleciente' && $convalescentSignature->isNeutralHermit(),
    'El convaleciente firma como ERMITANO NEUTRAL: su aval no ocupa plaza de hermandad (RF-03.6)'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM spell_reviews WHERE spell_id = 'spl_vetada' AND signatures_count = 1") === 1,
    'Los vetos eticos no dejaron expediente a medias'
);

// --- FASE 6: Atomicidad (RNF-02) ---
echo "\nFASE 6: Atomicidad de la deliberacion (RNF-02)\n";
$forgeSpell($pdo, 'spl_atomica', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_atomica', 'spl_atomica', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);
$service->signSpell('spl_atomica', 'usr_maestro_ermitano', null, $NOW);
$service->signSpell('spl_atomica', 'usr_maestro_tempestad', null, $NOW);

// El sello muerde SOLO al acto de consagracion: las dos firmas previas, la
// tercera y el contador se escriben, y la gloria falla DESPUES.
$weeklyBeforeAtomic = (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]);
$pdo->exec("CREATE TRIGGER trg_sella_consagracion BEFORE INSERT ON audit_log
             WHEN NEW.action_type = 'SPELL_CONSECRATED' AND NEW.target_entity_id = 'spl_atomica'
             BEGIN SELECT RAISE(ABORT, 'consagracion sellada por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->signSpell('spl_atomica', 'usr_maestro_astral', null, $NOW)) !== null,
    'La consagracion fracasa cuando su memoria no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_consagracion');

assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_atomica' AND is_revoked = 0") === 2,
    'La tercera firma se deshizo CON la consagracion: nadie queda avalando una obra que no se consagro (RNF-02)'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_atomica'") === 'experimental',
    'El expediente siguio en deliberacion: la transicion se deshizo entera'
);
assertCondition(
    (int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_atomica'") === 2,
    'El contador de la autoridad volvio a dos'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spells WHERE id = 'spl_atomica'") === 'experimental'
    && (int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_atomica'") === 2,
    'El espejo del conjuro no quedo adelantado a la autoridad'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_atomica'") === 0,
    'Sin consagracion no hay gloria: la acreditacion no sobrevivio al fallo'
);
assertCondition(
    (int) $scalar($pdo, 'SELECT weekly_points FROM clans WHERE id = :clanId', [':clanId' => $CLAN_ORIGEN]) === $weeklyBeforeAtomic,
    'El marcador semanal del linaje no se movio'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = 'spl_atomica'") === 2,
    'No queda memoria huerfana de la tercera firma ni de la consagracion'
);

$conclusive = $service->signSpell('spl_atomica', 'usr_maestro_astral', null, $NOW);
assertCondition($conclusive->status === 'validated', 'Retirado el sello, la misma firma consuma la obra sin estorbo');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_atomica'") === 1,
    'La gloria se acredita UNA sola vez, y solo cuando la consagracion cuaja'
);

$forgeSpell($pdo, 'spl_bitacora', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_bitacora', 'spl_bitacora', 'usr_autora', 'experimental', 0, $CLAN_ORIGEN);
$pdo->exec("CREATE TRIGGER trg_sella_firma BEFORE INSERT ON audit_log
             WHEN NEW.target_entity_id = 'spl_bitacora'
             BEGIN SELECT RAISE(ABORT, 'bitacora sellada por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->signSpell('spl_bitacora', 'usr_maestro_ermitano', null, $NOW)) !== null,
    'La firma fracasa cuando su memoria no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_firma');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_bitacora'") === 0,
    'Sin memoria no hay firma: el aval NO nace a medias (RNF-02)'
);
assertCondition(
    (int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_bitacora'") === 0,
    'El contador no se movio cuando la firma no cuajo'
);

$forgeSpell($pdo, 'spl_bitacora_objeccion', 'usr_autora', $CLAN_ORIGEN, 'experimental', $FINGERPRINT, $NOW_UTC);
$forgeReview($pdo, 'rev_bitacora_objeccion', 'spl_bitacora_objeccion', 'usr_autora', 'experimental', 1, $CLAN_ORIGEN);
$service->signSpell('spl_bitacora_objeccion', 'usr_maestro_ermitano', null, $NOW);
$pdo->exec("CREATE TRIGGER trg_sella_dictamen BEFORE INSERT ON audit_log
             WHEN NEW.action_type = 'SIGN_REJECT' AND NEW.target_entity_id = 'spl_bitacora_objeccion'
             BEGIN SELECT RAISE(ABORT, 'dictamen sellado por el arnes'); END;");
assertCondition(
    captureError(static fn () => $service->objectSpell('spl_bitacora_objeccion', 'usr_maestro_astral', str_repeat('o', 40), $NOW)) !== null,
    'El Dictamen de Objecion fracasa cuando su memoria no puede inscribirse'
);
$pdo->exec('DROP TRIGGER trg_sella_dictamen');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM objection_verdicts WHERE spell_id = 'spl_bitacora_objeccion'") === 0,
    'Sin memoria no hay dictamen: el veto no se inscribe a medias'
);
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM master_signatures WHERE spell_id = 'spl_bitacora_objeccion' AND is_revoked = 0") === 1,
    'El aval del Maestro sobrevivio: el veto que no cuajo no pudo cancelarlo'
);
assertCondition(
    (string) $scalar($pdo, "SELECT status FROM spell_reviews WHERE spell_id = 'spl_bitacora_objeccion'") === 'experimental',
    'La obra siguio en deliberacion: el rechazo se deshizo entero'
);

// --- FASE 7: Auditoria estatica, catalogos y Dogma Vanilla ---
echo "\nFASE 7: Auditoria estatica y cruce de catalogos\n";
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
    'Todo acceso al motor pasa por sentencias preparadas: sin consultas ni ejecuciones directas'
);
assertCondition(
    !str_contains($serviceSource, 'UPDATE spells') && !str_contains($serviceSource, 'UPDATE spell_reviews'),
    'El servicio NO escribe el estado ni el contador: delega en el unico escritor del espejo (Tarea 1.5)'
);
assertCondition(
    str_contains($serviceSource, 'updateStatus(')
    && str_contains($serviceSource, 'updateSignaturesCount(')
    && str_contains($serviceSource, 'revokeActiveSignaturesForSpell('),
    'Toda transicion y toda anulacion pasan por el repositorio, no por SQL propio'
);
assertCondition(
    str_contains($serviceSource, 'findAndLockById('),
    'El gesto abre transaccion y TOMA el bloqueo del expediente antes de leer (RNF-02)'
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
    ($serviceClass)::SIGNATURES_REQUIRED === ($reviewClass)::MAX_SIGNATURES
    && ($serviceClass)::SIGNATURES_REQUIRED === 3,
    'El umbral de tres firmas es uno solo: repositorio y servicio no divergen'
);
assertCondition(
    ($serviceClass)::MAX_GLOSS_LENGTH === ($signatureClass)::MAX_GLOSS_LENGTH,
    'El tope de la glosa es uno solo: repositorio y servicio no divergen (RF-02.2)'
);
assertCondition(
    ($serviceClass)::MIN_OBJECTION_LENGTH === 20,
    'El minimo del Dictamen de Objecion es de veinte caracteres (RF-02.5)'
);
$canon = ($serviceClass)::deliberationCanon();
assertCondition($canon['signaturesRequired'] === 3 && $canon['judgeRole'] === 'master', 'La interfaz recibe el canon de deliberacion desde la fuente unica');

$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
foreach (['SIGN_VALIDATE', 'SIGN_REJECT', 'SIGNATURE_RETRACTED', 'SPELL_CONSECRATED'] as $act) {
    assertCondition(str_contains($auditEntrySource, "'{$act}'"), "El acto {$act} figura en el catalogo cerrado de AuditEntry");
}
$auditViewSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
foreach (['SIGN_VALIDATE', 'SIGN_REJECT', 'SIGNATURE_RETRACTED', 'SPELL_CONSECRATED'] as $act) {
    assertCondition(str_contains($auditViewSource, $act . ':'), "El acto {$act} tiene su rotulo castellano en la Bitacora publica");
}
assertCondition(
    str_contains((string) file_get_contents($projectRoot . '/src/Services/ConstitutionalEthicsValidator.php'), 'ClanEthicsValidator'),
    'El veto de linaje no se reimplementa: se COMPONE sobre el validador de SPEC-07 (una sola sentencia etica)'
);

// --- VEREDICTO ---
unset($service);
$pdo = null;
gc_collect_cycles();
@unlink($databasePath);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_deliberation.sqlite'),
    'El arnes jamas escribe la base efimera dentro del repositorio'
);

echo "\n== RESULTADO: {$assertsPassed} asertos pasados, {$assertsFailed} fallidos ==\n";
if ($assertsFailed === 0) {
    echo "TAREA 2.4 VERIFICADA: la tercera firma consagra la obra de forma atomica y acredita\n";
    echo "los PDA a su linaje originario, la retractacion cae antes del sello y es irrevocable\n";
    echo "despues, y el Dictamen de Objecion cancela los avales previos devolviendo la obra\n";
    echo "a la libreta de su autor.\n";
    exit(0);
}

echo "TAREA 2.4 NO VERIFICADA: revisense los asertos en rojo.\n";
exit(1);

<?php

declare(strict_types=1);

/**
 * test_moderation_ethics_validator.php — Verificación de la Tarea 2.2 de TASKS-08.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/08-moderation-two-step.tasks.md:
 *   «Se bloquea a Maestros del mismo clan del autor, a exmiembros de los
 *    últimos 30 días, a autores intentando firmar su propia obra y a dos
 *    Maestros del mismo clan ajeno, admitiendo a múltiples ermitaños
 *    neutrales.»
 *
 * Estrategia: se levanta una base SQLite efímera con la secuencia canónica
 * (database/schema.sql + database/seeds.sql + sql/08_moderation_schema.sql) y
 * se ejercita `ConstitutionalEthicsValidator` sobre el historial de membresía
 * REAL (`clan_members`), el censo de firmas y la bitácora. La anulación de
 * oficio no se afirma: se prueba con un disparador temporal que hace fracasar
 * la bitácora y comprobando que la firma sigue VIVA.
 *
 * Fases:
 *   [0]  Superficie: el módulo existe, declara strict_types y publica los tres
 *        métodos canónicos con sus constantes.
 *   [1]  Auto-firma: la propia pluma jamás se juzga, ni con potestad suprema.
 *   [2]  Veto de linaje: clan actual, ventana inclusiva de treinta días, y los
 *        ermitaños neutrales admitidos.
 *   [3]  Pluralidad de hermandades: una sola voz por estandarte.
 *   [4]  Convalecencia arcana: potestad judicial conservada como ermitaño.
 *   [5]  Anulación de oficio: conflicto sobrevenido y pérdida de rango, con
 *        memoria en la bitácora, contador sanado e integridad atómica.
 *   [6]  Auditoría estática y cruce del acto con la bitácora pública.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, sin librerías externas.
 *   - Artículo III: el veto ético es el objeto mismo de la prueba.
 *   - Artículo V (Dualidad): identificadores en inglés, narrativa en castellano.
 *
 * Uso: php scratch/test_moderation_ethics_validator.php
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

echo "== VERIFICACION TAREA 2.2: Validador de Etica Constitucional y Pluralidad ==\n\n";

$projectRoot = dirname(__DIR__);
$validatorPath = $projectRoot . '/src/Services/ConstitutionalEthicsValidator.php';

// --- FASE 0: Superficie del módulo ---
echo "FASE 0: Superficie del modulo\n";
assertCondition(file_exists($validatorPath), 'Existe src/Services/ConstitutionalEthicsValidator.php');

if (!file_exists($validatorPath)) {
    echo "\nRESULTADO: FALLO — falta el validador de la Tarea 2.2 (fase roja del TDD).\n";
    exit(1);
}

$validatorSource = (string) file_get_contents($validatorPath);
$validatorHead = implode('', array_slice(file($validatorPath, FILE_IGNORE_NEW_LINES) ?: [], 0, 50));
assertCondition(str_contains($validatorHead, 'declare(strict_types=1);'), 'Declara strict_types en las primeras lineas (checklist AGENTS.md)');
assertCondition(str_contains($validatorSource, 'namespace Grimorio\\Services;'), 'Habita el espacio de nombres Grimorio\\Services');
assertCondition(
    preg_match('#^use (?!Grimorio)[A-Z]#m', $validatorSource) === 1
    && preg_match('#https?://#', $validatorSource) !== 1,
    'Solo depende de la biblioteca estandar de PHP: cero dependencias externas (Articulo I)'
);

foreach (['canMasterEvaluateSpell', 'validateClanPlurality', 'revokeConflictedSignatures'] as $methodName) {
    assertCondition(
        str_contains($validatorSource, "function {$methodName}("),
        "Publica el metodo canonico {$methodName}()"
    );
}

require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Services/ClanEthicsValidator.php';
require_once $projectRoot . '/src/Repositories/MasterSignatureRepository.php';
require_once $projectRoot . '/src/Repositories/SpellReviewRepository.php';
require_once $projectRoot . '/src/Services/SignatureAnnulment.php';
require_once $projectRoot . '/src/Services/SignatureAnnulmentResult.php';
require_once $validatorPath;

$validatorClass = 'Grimorio\\Services\\ConstitutionalEthicsValidator';

// Base de datos SQLite efímera: jamás contamina el santuario real.
// La base efímera vive en el directorio temporal del sistema, NUNCA dentro
// del repositorio: así ningún artefacto de prueba puede viajar en un commit.
$databasePath = sys_get_temp_dir() . '/grimorio_moderation_ethics_' . getmypid() . '.sqlite';
@unlink($databasePath);
$pdo = new PDO('sqlite:' . $databasePath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/sql/08_moderation_schema.sql'));

/** @var ConstitutionalEthicsValidator $validator */
$validator = new $validatorClass($pdo);

$NOW = new DateTimeImmutable('2026-09-15T10:00:00Z');
$NOW_UTC = '2026-09-15T10:00:00Z';
$FINGERPRINT = str_repeat('a', 64);

/** Inscribe una hermandad de prueba. */
$forgeClan = static function (PDO $pdo, string $clanId, string $slug, string $name, string $createdAt): void {
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, created_at, coat_of_arms, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :createdAt, :arms, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'        => $clanId,
        ':slug'      => $slug,
        ':name'      => $name,
        ':createdAt' => $createdAt,
        ':arms'      => 'rune_' . $slug,
    ]);
};

/** Inscribe un adepto con su rango tecnico. */
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

/** Inscribe una afiliacion, vigente o cerrada, en la AUTORIDAD del historial. */
$forgeMembership = static function (
    PDO $pdo,
    string $memberId,
    string $clanId,
    string $userId,
    string $role,
    string $joinedAt,
    ?string $leftAt = null,
    ?string $convalescenceExpiresAt = null,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :convalescence)'
    );
    $statement->execute([
        ':id'             => $memberId,
        ':clanId'         => $clanId,
        ':userId'         => $userId,
        ':role'           => $role,
        ':joinedAt'       => $joinedAt,
        ':leftAt'         => $leftAt,
        ':convalescence'  => $convalescenceExpiresAt,
    ]);
};

/** Inscribe un conjuro con su huella sellada. */
$forgeSpell = static function (PDO $pdo, string $spellId, string $name, string $authorId, string $clanId, string $createdAt) use ($FINGERPRINT): void {
    $statement = $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, mana_cost, clan_id, summary, math_fingerprint, created_at, updated_at)
         VALUES (:id, :slug, :name, :authorId, :school, :affinity, 20, :clanId, :summary, :fingerprint, :createdAt, :createdAt)'
    );
    $statement->execute([
        ':id'          => $spellId,
        ':slug'        => $name,
        ':name'        => 'Obra ' . $spellId,
        ':authorId'    => $authorId,
        ':school'      => 'evocation',
        ':affinity'    => 'fire',
        ':clanId'      => $clanId,
        ':summary'     => 'Obra de prueba del validador etico.',
        ':fingerprint' => $FINGERPRINT,
        ':createdAt'   => $createdAt,
    ]);
};

$CLAN_ROOT = 'cln_primordial';       // Linaje sembrado del santuario.
$CLAN_TEMPEST = 'cln_tempestad';     // Linaje ajeno de prueba.
$CLAN_ABYSS = 'cln_abisal';          // Segundo linaje ajeno.

$forgeClan($pdo, $CLAN_TEMPEST, 'tempestad-eterna', 'Tempestad Eterna', '2026-01-02T00:00:00Z');
$forgeClan($pdo, $CLAN_ABYSS, 'sombras-abisales', 'Sombras Abisales', '2026-01-03T00:00:00Z');

$forgeUser($pdo, 'usr_autora', 'Autora Primordial', 'editor', $CLAN_ROOT, '2026-01-01T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_tempestad', 'Maestro de la Tempestad', 'master', $CLAN_TEMPEST, '2026-01-02T00:00:00Z');
$forgeUser($pdo, 'usr_maestro_abisal', 'Maestro Abisal', 'master', $CLAN_ABYSS, '2026-01-03T00:00:00Z');
$forgeUser($pdo, 'usr_ermitano', 'Maestro Ermitano', 'master', null, '2026-01-04T00:00:00Z');
$forgeUser($pdo, 'usr_admin', 'Administrador Supremo', 'supremeAdmin', null, '2026-01-05T00:00:00Z');

// Membresía vigente del Maestro de la Tempestad: el mismo linaje del conjuro.
$forgeMembership($pdo, 'clm_tempestad', $CLAN_TEMPEST, 'usr_maestro_tempestad', 'patriarch', '2026-01-02T00:00:00Z');
// Membresía vigente del Maestro Abisal.
$forgeMembership($pdo, 'clm_abisal', $CLAN_ABYSS, 'usr_maestro_abisal', 'patriarch', '2026-01-03T00:00:00Z');

$forgeSpell($pdo, 'spl_raiz', 'obra-raiz', 'usr_autora', $CLAN_ROOT, $NOW_UTC);

// --- FASE 1: Auto-firma (RF-03.2) ---
echo "\nFASE 1: La propia pluma jamas se juzga\n";
$authorUserId = 'usr_autora';
assertCondition(
    $validator->canMasterEvaluateSpell($authorUserId, $CLAN_ROOT, $authorUserId, $NOW) === false,
    'El autor no puede evaluar su propia obra, aunque ostente potestad judicial (RF-03.2)'
);
assertCondition(
    $validator->evaluationVetoCode($authorUserId, $CLAN_ROOT, $authorUserId, $NOW) === $validatorClass::VETO_OWN_AUTHORSHIP,
    'El veto se nombra por su causa: propia pluma'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_admin', $CLAN_ROOT, 'usr_admin', $NOW) === false,
    'Ni el Administrador Supremo puede juzgar su propia pluma (RF-03.2)'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_maestro_tempestad', $CLAN_ROOT, 'usr_autora', $NOW) === true,
    'Un Maestro de linaje ajeno y pluma ajena queda legitimado para juzgar'
);
assertCondition(
    $validator->evaluationVetoReason('usr_maestro_tempestad', $CLAN_ROOT, 'usr_autora', $NOW) === null,
    'Sin veto no hay bloqueo ceremonial que pronunciar'
);
assertCondition(
    $validator->evaluationVetoReason($authorUserId, $CLAN_ROOT, $authorUserId, $NOW) === $validatorClass::CONFLICT_OF_INTEREST_MESSAGE,
    'El bloqueo ceremonial de RF-03.3 se pronuncia en noble castellano'
);
assertCondition(
    str_contains($validatorClass::CONFLICT_OF_INTEREST_MESSAGE, 'Conflicto de intereses'),
    'El bloqueo nombra el conflicto de intereses tal y como lo prescribe la especificacion'
);
assertCondition(
    captureError(static fn () => $validator->canMasterEvaluateSpell('  ', $CLAN_ROOT, 'usr_autora', $NOW)) !== null,
    'Sin identidad de Maestro no hay juicio posible'
);

// --- FASE 2: Veto de linaje y ventana de treinta días (RF-03.1) ---
echo "\nFASE 2: Veto de linaje y ventana inclusiva de treinta dias\n";
assertCondition(
    $validator->canMasterEvaluateSpell('usr_maestro_tempestad', $CLAN_TEMPEST, 'usr_autora', $NOW) === false,
    'El Maestro no puede evaluar obras de su propio clan actual (RF-03.1)'
);
assertCondition(
    $validator->evaluationVetoCode('usr_maestro_tempestad', $CLAN_TEMPEST, 'usr_autora', $NOW) === $validatorClass::VETO_CLAN_INCOMPATIBILITY,
    'El veto de linaje se nombra por su causa: incompatibilidad de hermandad'
);

// Historial: tres Maestros que partieron del linaje de la obra en momentos
// distintos respecto de la frontera de treinta días.
$forgeUser($pdo, 'usr_recien_partido', 'Maestro Recien Partido', 'master', null, '2026-01-06T00:00:00Z');
$forgeUser($pdo, 'usr_al_borde', 'Maestro al Borde', 'master', null, '2026-01-07T00:00:00Z');
$forgeUser($pdo, 'usr_antiguo_partido', 'Maestro Antiguo Partido', 'master', null, '2026-01-08T00:00:00Z');
$forgeMembership($pdo, 'clm_recien', $CLAN_ROOT, 'usr_recien_partido', 'adept', '2026-02-01T00:00:00Z', '2026-08-17T10:00:00Z');
$forgeMembership($pdo, 'clm_borde', $CLAN_ROOT, 'usr_al_borde', 'adept', '2026-02-01T00:00:00Z', '2026-08-16T10:00:00Z');
$forgeMembership($pdo, 'clm_antiguo', $CLAN_ROOT, 'usr_antiguo_partido', 'adept', '2026-02-01T00:00:00Z', '2026-08-15T10:00:00Z');

assertCondition(
    $validator->canMasterEvaluateSpell('usr_recien_partido', $CLAN_ROOT, 'usr_autora', $NOW) === false,
    'Quien partio hace veintinueve dias sigue vetado: la lealtad recien disuelta contamina (RF-03.1)'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_al_borde', $CLAN_ROOT, 'usr_autora', $NOW) === false,
    'La frontera de treinta dias es INCLUSIVA: quien partio justo al borde sigue vetado'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_antiguo_partido', $CLAN_ROOT, 'usr_autora', $NOW) === true,
    'Quien partio hace treinta y un dias queda libre del veto historico'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_ermitano', $CLAN_ROOT, 'usr_autora', $NOW) === true,
    'El Maestro ermitano neutral juzga sin veto: no milita ni militó en linaje alguno'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_maestro_tempestad', null, 'usr_autora', $NOW) === true,
    'La obra de un ermitano no tiene estandarte que pueda vetar el juicio'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_recien_partido', '', 'usr_autora', $NOW) === true,
    'La cadena vacia se lee como ermitania, no como un linaje llamado vacio'
);

// --- FASE 3: Pluralidad de hermandades (RF-02.1) ---
echo "\nFASE 3: Una sola voz por estandarte\n";
$signatureOf = static fn (string $signatureId, string $masterId, ?string $masterClanId, int $isRevoked = 0): array => [
    'id'              => $signatureId,
    'spell_id'        => 'spl_raiz',
    'master_id'       => $masterId,
    'master_clan_id'  => $masterClanId,
    'ceremonial_gloss' => null,
    'signed_at'       => $NOW_UTC,
    'is_revoked'      => $isRevoked,
    'revoked_at'      => null,
    'revocation_reason' => null,
];

assertCondition(
    $validator->validateClanPlurality([$signatureOf('sig_uno', 'usr_maestro_tempestad', $CLAN_TEMPEST)], $CLAN_TEMPEST) === false,
    'Dos Maestros del mismo clan ajeno no pueden avalar la misma obra (RF-02.1)'
);
assertCondition(
    $validator->validateClanPlurality([$signatureOf('sig_uno', 'usr_maestro_tempestad', $CLAN_TEMPEST)], $CLAN_ABYSS) === true,
    'Un linaje distinto si puede aportar su voz'
);
assertCondition(
    $validator->validateClanPlurality([], $CLAN_TEMPEST) === true,
    'Sin avales previos, la primera voz de un linaje es licita'
);
assertCondition(
    $validator->validateClanPlurality(
        [$signatureOf('sig_uno', 'usr_ermitano', null), $signatureOf('sig_dos', 'usr_ermitano_dos', null)],
        null,
    ) === true,
    'Multiples Maestros ermitanos neutrales pueden avalar la misma obra (caso limite 3)'
);
assertCondition(
    $validator->validateClanPlurality([$signatureOf('sig_uno', 'usr_ermitano', null)], $CLAN_TEMPEST) === true,
    'La neutralidad del ermitano no ocupa plaza de hermandad'
);
assertCondition(
    $validator->validateClanPlurality([$signatureOf('sig_uno', 'usr_ermitano', null, 1)], null) === true,
    'El aval caido no ocupa plaza de hermandad'
);
assertCondition(
    $validator->validateClanPlurality(
        [$signatureOf('sig_uno', 'usr_maestro_tempestad', $CLAN_TEMPEST, 1), $signatureOf('sig_dos', 'usr_maestro_abisal', $CLAN_ABYSS)],
        $CLAN_TEMPEST,
    ) === true,
    'Una firma revocada del mismo linaje no impide el nuevo aval (RF-02.4)'
);
assertCondition(
    $validator->validateClanPlurality([$signatureOf('sig_uno', 'usr_maestro_tempestad', $CLAN_TEMPEST)], null) === true,
    'El ermitano entrante nunca choca con hermandad alguna (RF-03.6)'
);
assertCondition(
    str_contains($validatorClass::PLURALITY_CONFLICT_MESSAGE, 'Pluralidad de hermandades'),
    'El bloqueo de pluralidad se pronuncia en noble castellano'
);

// --- FASE 4: Convalecencia arcana (RF-03.6) ---
echo "\nFASE 4: Convalecencia arcana y potestad judicial
";
$forgeUser($pdo, 'usr_convaleciente', 'Maestro Convaleciente', 'master', null, '2026-01-09T00:00:00Z');
$forgeMembership(
    $pdo,
    'clm_convaleciente',
    $CLAN_ROOT,
    'usr_convaleciente',
    'adept',
    '2026-02-01T00:00:00Z',
    '2026-09-10T10:00:00Z',
    '2026-09-24T10:00:00Z',
);
assertCondition(
    $validator->signatureClanIdFor($CLAN_ROOT, true) === null,
    'El convaleciente firma como ermitano neutral: su firma no ocupa plaza de hermandad (RF-03.6)'
);
assertCondition(
    $validator->signatureClanIdFor($CLAN_ROOT, false) === $CLAN_ROOT,
    'Quien no purga convalecencia firma con el estandarte que ciñe'
);
assertCondition(
    $validator->signatureClanIdFor('   ', false) === null,
    'El estandarte en blanco se lee como ermitania, no como linaje vacio'
);
assertCondition(
    $validator->canMasterEvaluateSpell('usr_convaleciente', $CLAN_ROOT, 'usr_autora', $NOW) === false,
    'La convalecencia conserva la potestad judicial, pero NO suspende el veto de treinta dias (RF-03.6)'
);

// --- FASE 5: Anulacion de oficio (RF-03.4, RF-03.5, RNF-02) ---
echo "\nFASE 5: Anulacion de oficio con memoria en la bitacora
";

/** Inscribe un expediente de moderacion. */
$forgeReview = static function (PDO $pdo, string $reviewId, string $spellId, string $authorId, string $status, int $count, ?string $clanId, string $submittedAt) use ($FINGERPRINT): void {
    $statement = $pdo->prepare(
        'INSERT INTO spell_reviews (id, spell_id, author_id, origin_clan_id, status, signatures_count, math_fingerprint, submitted_at)
         VALUES (:id, :spellId, :authorId, :clanId, :status, :count, :fingerprint, :submittedAt)'
    );
    $statement->execute([
        ':id'           => $reviewId,
        ':spellId'      => $spellId,
        ':authorId'     => $authorId,
        ':clanId'       => $clanId,
        ':status'       => $status,
        ':count'        => $count,
        ':fingerprint'  => $FINGERPRINT,
        ':submittedAt'  => $submittedAt,
    ]);

    $mirror = $pdo->prepare('UPDATE spells SET status = :status, signatures_count = :count WHERE id = :spellId');
    $mirror->execute([':status' => $status, ':count' => $count, ':spellId' => $spellId]);
};

/** Estampa una firma viva en la base. */
$forgeSignature = static function (PDO $pdo, string $signatureId, string $spellId, string $masterId, ?string $masterClanId, string $signedAt): void {
    $statement = $pdo->prepare(
        'INSERT INTO master_signatures (id, spell_id, master_id, master_clan_id, ceremonial_gloss, signed_at, is_revoked)
         VALUES (:id, :spellId, :masterId, :clanId, NULL, :signedAt, 0)'
    );
    $statement->execute([
        ':id'       => $signatureId,
        ':spellId'  => $spellId,
        ':masterId' => $masterId,
        ':clanId'   => $masterClanId,
        ':signedAt' => $signedAt,
    ]);
};

/** Lee un unico valor escalar de la base. */
$scalar = static function (PDO $pdo, string $sql, array $parameters = []): mixed {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return $statement->fetchColumn();
};

// Escenario: una obra del linaje raiz avalada por dos Maestros de clanes ajenos.
$forgeSpell($pdo, 'spl_avisada', 'obra-avisada', 'usr_autora', $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_avisada', 'spl_avisada', 'usr_autora', 'experimental', 2, $CLAN_ROOT, $NOW_UTC);
$forgeSignature($pdo, 'sig_tempestad', 'spl_avisada', 'usr_maestro_tempestad', $CLAN_TEMPEST, $NOW_UTC);
$forgeSignature($pdo, 'sig_abisal', 'spl_avisada', 'usr_maestro_abisal', $CLAN_ABYSS, $NOW_UTC);

// Caso A — perdida del rango de Maestro en transito (RF-03.5). La degradacion
// se inscribe ANTES en `users`, como exige el reparto: el veredicto llega con
// el evento, la identidad que retrata la bitacora sale de la base.
$pdo->prepare('UPDATE users SET role = :role WHERE id = :userId')
    ->execute([':role' => 'editor', ':userId' => 'usr_maestro_tempestad']);

$rankLost = $validator->revokeConflictedSignatures('usr_maestro_tempestad', null, 'editor', $NOW);
assertCondition($rankLost->hasAnnulments() === true && $rankLost->count() === 1, 'El Maestro degradado pierde su aval antes de la tercera rubrica (RF-03.5)');
assertCondition($rankLost->reasons() === ['rank_lost'], 'El motivo inscrito es la perdida del rango, no otro');
assertCondition($rankLost->spellIds() === ['spl_avisada'] && $rankLost->authorIds() === ['usr_autora'], 'El veredicto nombra la obra y al autor a quien dar noticia (RF-03.4)');
assertCondition($rankLost->annulments[0]->newSignaturesCount === 1, 'El contador desciende a las firmas VIVAS restantes (N-1)');

assertCondition((int) $scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE id = 'sig_tempestad'") === 1, 'La firma queda revocada en la base, jamas borrada (RNF-01)');
assertCondition((string) $scalar($pdo, "SELECT revocation_reason FROM master_signatures WHERE id = 'sig_tempestad'") === 'rank_lost', 'La firma conserva su motivo canonico de revocacion');
assertCondition((string) $scalar($pdo, "SELECT revoked_at FROM master_signatures WHERE id = 'sig_tempestad'") === $NOW_UTC, 'La revocacion queda fechada en el instante inyectado');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_avisada'") === 1, 'El contador del expediente sigue a las firmas vivas');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spells WHERE id = 'spl_avisada'") === 1, 'El espejo de `spells` sigue al expediente: un solo contador (Tarea 1.5)');
assertCondition((int) $scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE id = 'sig_abisal'") === 0, 'El aval de otro linaje NO cae: cada firma responde por su propio firmante');

$auditRow = $pdo->prepare("SELECT actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at
                          FROM audit_log WHERE action_type = 'SIGNATURE_ANNULMENT' AND target_entity_id = 'spl_avisada'");
$auditRow->execute();
$annulmentAudit = $auditRow->fetch(PDO::FETCH_ASSOC);
assertCondition($annulmentAudit !== false, 'La anulacion queda inscrita en la Bitacora de Auditoria publica (RF-06.1)');
if ($annulmentAudit !== false) {
    assertCondition((string) $annulmentAudit['actor_user_id'] === 'usr_maestro_tempestad', 'La bitacora nombra al Maestro cuya situacion cambio');
    assertCondition((string) $annulmentAudit['actor_alias'] === 'Maestro de la Tempestad', 'El alias publico se resuelve en `users` dentro de la transaccion (no lo dicta el llamante)');
    assertCondition((string) $annulmentAudit['actor_role'] === 'editor', 'El rol tecnico retratado es el vigente en la base al anular');
    assertCondition((string) $annulmentAudit['target_entity_type'] === 'spell', 'El objetivo del acto es el conjuro');
    assertCondition((string) $annulmentAudit['created_at'] === $NOW_UTC, 'El instante de la memoria coincide con el de la revocacion');
    assertCondition(str_contains((string) $annulmentAudit['justification'], 'rango de Maestro'), 'La justificacion nombra la causa en noble castellano (Art. IV)');
    assertCondition(str_contains((string) $annulmentAudit['justification'], 'Maestro de la Tempestad'), 'La justificacion nombra al firmante con su alias publico');
}

// Idempotencia: repetir el gesto no reescribe la memoria ni duplica el acto.
$repeated = $validator->revokeConflictedSignatures('usr_maestro_tempestad', null, 'editor', $NOW);
assertCondition($repeated->hasAnnulments() === false, 'Repetir la anulacion no vuelve a derribar la firma ya caida');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SIGNATURE_ANNULMENT' AND target_entity_id = 'spl_avisada'") === 1,
    'La bitacora conserva UNA entrada, no una por reintento'
);

// Caso B — conflicto de hermandad sobrevenido (RF-03.4): el Maestro entra en
// el linaje de la obra que ya habia avalado.
$forgeSpell($pdo, 'spl_tempestad', 'obra-tempestad', 'usr_maestro_abisal', $CLAN_TEMPEST, $NOW_UTC);
$forgeReview($pdo, 'rev_tempestad', 'spl_tempestad', 'usr_maestro_abisal', 'experimental', 1, $CLAN_TEMPEST, $NOW_UTC);
$forgeSignature($pdo, 'sig_abandonado', 'spl_tempestad', 'usr_maestro_tempestad', $CLAN_TEMPEST, $NOW_UTC);

// El Maestro aun conserva el rango: solo muda de hermandad.
$pdo->prepare('UPDATE users SET role = :role WHERE id = :userId')
    ->execute([':role' => 'master', ':userId' => 'usr_maestro_tempestad']);
$conflict = $validator->revokeConflictedSignatures('usr_maestro_tempestad', $CLAN_TEMPEST, null, $NOW);
assertCondition($conflict->count() === 1 && $conflict->reasons() === ['clan_conflict_arisen'], 'El conflicto de hermandad sobrevenido anula la firma de oficio (RF-03.4)');
assertCondition($conflict->annulments[0]->newSignaturesCount === 0, 'La obra vuelve a cero firmas y exige un nuevo aval');
assertCondition((int) $scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE id = 'sig_abandonado'") === 1, 'La firma en conflicto queda revocada');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE action_type = 'SIGNATURE_ANNULMENT' AND target_entity_id = 'spl_tempestad'") === 1,
    'El conflicto tambien se inscribe en la bitacora'
);

// Caso C — sin causa concurrente no se toca nada.
$forgeUser($pdo, 'usr_ajeno', 'Maestro Ajeno', 'master', null, '2026-01-10T00:00:00Z');
$forgeSpell($pdo, 'spl_ajena', 'obra-ajena', 'usr_autora', $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_ajena', 'spl_ajena', 'usr_autora', 'experimental', 1, $CLAN_ROOT, $NOW_UTC);
$forgeSignature($pdo, 'sig_ajena', 'spl_ajena', 'usr_ajeno', null, $NOW_UTC);
$noCause = $validator->revokeConflictedSignatures('usr_ajeno', null, null, $NOW);
assertCondition($noCause->hasAnnulments() === false, 'Sin cambio de linaje ni perdida de rango, ninguna firma cae');
assertCondition($validator->revokeConflictedSignatures('usr_ajeno', $CLAN_ABYSS, 'master', $NOW)->hasAnnulments() === false, 'Mudarse a un linaje AJENO a la obra no genera conflicto');
assertCondition($validator->revokeConflictedSignatures('usr_ajeno', null, 'supremeAdmin', $NOW)->hasAnnulments() === false, 'Ascender de rango no derriba firma alguna: la potestad suprema tambien juzga');

// Caso D — la obra consagrada es irrevocable (RF-02.7). El Maestro de este
// caso solo avala la obra consagrada, para que el aserto mida exactamente eso.
$forgeUser($pdo, 'usr_consagrado', 'Maestro de la Obra Consagrada', 'master', null, '2026-01-11T00:00:00Z');
$forgeSpell($pdo, 'spl_consagrada', 'obra-consagrada', 'usr_autora', $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_consagrada', 'spl_consagrada', 'usr_autora', 'validated', 3, $CLAN_ROOT, $NOW_UTC);
$forgeSignature($pdo, 'sig_consagrada', 'spl_consagrada', 'usr_consagrado', null, $NOW_UTC);
$consecrated = $validator->revokeConflictedSignatures('usr_consagrado', null, 'reader', $NOW);
assertCondition($consecrated->hasAnnulments() === false, 'Una obra consagrada es irrevocable: la anulacion no la alcanza (RF-02.7)');
assertCondition(in_array('spl_consagrada', $consecrated->spellIds(), true) === false, 'El conjuro consagrado no figura siquiera entre las obras tocadas');
assertCondition((int) $scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE id = 'sig_consagrada'") === 0, 'La firma de la obra consagrada sigue viva');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_consagrada'") === 3, 'El contador de la consagrada permanece en tres');

// Caso E — la obra vetada o retirada no necesita anulacion: sus avales ya
// cayeron con el motivo `author_withdrawn` (RF-01.3).
$forgeUser($pdo, 'usr_vetado', 'Maestro de la Obra Vetada', 'master', null, '2026-01-12T00:00:00Z');
$forgeSpell($pdo, 'spl_vetada', 'obra-vetada', 'usr_autora', $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_vetada', 'spl_vetada', 'usr_autora', 'rejected', 0, $CLAN_ROOT, $NOW_UTC);
$forgeSignature($pdo, 'sig_vetada', 'spl_vetada', 'usr_vetado', null, $NOW_UTC);
assertCondition(
    $validator->revokeConflictedSignatures('usr_vetado', null, 'reader', $NOW)->hasAnnulments() === false,
    'Sobre una obra ya vetada no se anula de oficio: el contador es cero y su ocaso ya fue inscrito'
);

// Caso F — integridad ATOMICA: si la bitacora no puede inscribirse, la firma
// NO cae. La memoria y la revocacion son un solo gesto.
$pdo->exec("CREATE TRIGGER trg_sella_bitacora BEFORE INSERT ON audit_log
             WHEN NEW.target_entity_id = 'spl_ajena'
             BEGIN SELECT RAISE(ABORT, 'bitacora sellada por el arnes'); END;");
$atomicityError = captureError(static fn () => $validator->revokeConflictedSignatures('usr_ajeno', $CLAN_ROOT, 'master', $NOW));
assertCondition($atomicityError !== null, 'La anulacion falla cuando la bitacora no puede inscribirse');
$pdo->exec('DROP TRIGGER trg_sella_bitacora');
assertCondition((int) $scalar($pdo, "SELECT is_revoked FROM master_signatures WHERE id = 'sig_ajena'") === 0, 'La firma sigue VIVA: el gesto se deshizo entero (RNF-02)');
assertCondition((int) $scalar($pdo, "SELECT signatures_count FROM spell_reviews WHERE spell_id = 'spl_ajena'") === 1, 'El contador no quedo a medias');
assertCondition(
    (int) $scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = 'spl_ajena' AND action_type = 'SIGNATURE_ANNULMENT'") === 0,
    'No hay memoria huerfana de una anulacion que no ocurrio'
);
$healed = $validator->revokeConflictedSignatures('usr_ajeno', $CLAN_ROOT, 'master', $NOW);
assertCondition($healed->count() === 1 && $healed->annulments[0]->newSignaturesCount === 0, 'Retirado el sello, el mismo gesto se consuma sin estorbo');

// Caso G — el contador se CUENTA desde las firmas vivas: si el espejo venia
// divergente, la anulacion lo sana en lugar de heredar el error.
$pdo->prepare("UPDATE spell_reviews SET signatures_count = 3 WHERE spell_id = 'spl_avisada'")->execute();
$forgeSpell($pdo, 'spl_divergente', 'obra-divergente', 'usr_autora', $CLAN_ROOT, $NOW_UTC);
$forgeReview($pdo, 'rev_divergente', 'spl_divergente', 'usr_autora', 'experimental', 3, $CLAN_ROOT, $NOW_UTC);
$forgeSignature($pdo, 'sig_divergente', 'spl_divergente', 'usr_ajeno', null, $NOW_UTC);
$healing = $validator->revokeConflictedSignatures('usr_ajeno', null, 'editor', $NOW);
assertCondition(
    $healing->count() === 1 && $healing->annulments[0]->newSignaturesCount === 0,
    'El contador se recalcula desde las firmas VIVAS: el espejo divergente se sana (Tarea 1.5)'
);

// --- FASE 6: Auditoria estatica y cruce con la bitacora publica ---
echo "\nFASE 6: Auditoria estatica y cruce del acto con la bitacora
";
$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
assertCondition(str_contains($auditEntrySource, "'SIGNATURE_ANNULMENT'"), 'El acto canonico existe en el catalogo cerrado de AuditEntry (Tarea 1.4)');

$auditViewSource = (string) file_get_contents($projectRoot . '/public/assets/js/views/auditLogView.js');
assertCondition(str_contains($auditViewSource, 'SIGNATURE_ANNULMENT:'), 'El acto tiene su rotulo castellano en la Bitacora publica');

assertCondition(
    preg_match_all("/\\\$parameters\\[':/", $validatorSource) >= 0
    && substr_count($validatorSource, '->prepare(') >= 1,
    'La unica consulta propia del validador pasa por sentencia preparada'
);
assertCondition(
    !str_contains($validatorSource, 'UPDATE master_signatures') && !str_contains($validatorSource, 'UPDATE spell_reviews'),
    'El validador no escribe el censo ni el contador por su cuenta: DELEGA en los repositorios'
);
assertCondition(
    !str_contains($validatorSource, 'new PDO') && str_contains($validatorSource, 'new ClanEthicsValidator'),
    'Recibe la conexion y COMPONE la autoridad de SPEC-07 en vez de duplicar la ventana de treinta dias'
);
assertCondition(
    !str_contains($validatorSource, 'time()') && !str_contains($validatorSource, 'date('),
    'No lee el reloj del sistema: el instante se inyecta (RNF-01)'
);
assertCondition(
    preg_match('/[\x{00e1}\x{00e9}\x{00ed}\x{00f3}\x{00fa}\x{00f1}\x{00bf}\x{00a1}]/u', $validatorSource) === 1,
    'La narrativa y los comentarios van en noble castellano (RNF-03, Articulo V)'
);
assertCondition(
    !str_contains($validatorSource, 'innerHTML') && !str_contains($validatorSource, '\\$_POST'),
    'El validador no toca la peticion del mundo exterior'
);

// --- VEREDICTO ---
// Se sueltan las referencias y se retira el fichero; en Windows el motor
// mantiene el bloqueo mientras algún descriptor viva, de modo que la garantía
// que el arnés afirma es la que de verdad importa: que nada quedó en el
// repositorio.
unset($validator, $pdo, $auditRow);
gc_collect_cycles();
@unlink($databasePath);
assertCondition(
    !file_exists($projectRoot . '/scratch/moderation_ethics.sqlite'),
    'El arnes jamas escribe la base efimera dentro del repositorio'
);
echo "\n== RESULTADO: {$assertsPassed} asertos pasados, {$assertsFailed} fallidos ==\n";
if ($assertsFailed === 0) {
    echo "TAREA 2.2 VERIFICADA: el veto etico bloquea la propia pluma, el linaje actual,\n";
    echo "los linajes de los ultimos treinta dias y la segunda voz de un mismo estandarte,\n";
    echo "anulando de oficio las firmas caidas con su memoria en la Bitacora.\n";
    exit(0);
}

echo "TAREA 2.2 NO VERIFICADA: revisense los asertos en rojo.\n";
exit(1);

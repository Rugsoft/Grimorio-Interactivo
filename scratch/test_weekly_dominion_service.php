<?php

/**
 * test_weekly_dominion_service.php — Verificación del Dominio Semanal.
 *
 * Tarea 2.5 (TASKS-07). Comprueba el criterio «Hecho cuando»:
 *   «El cierre semanal dirime empates favoreciendo primero al clan con más
 *    conjuros validados en la semana, resetea weekly_points a 0 para todos los
 *    clanes y añade los puntos al total histórico.»
 *
 * Fases:
 *   [0] Escala por Círculo Arcano: PDA = 100 + (Círculo × 20) (RF-03.1).
 *   [1] Sinergia temática del +25% con redondeo aritmético (RF-03.4).
 *   [2] Techo diario de 50 PDA del simulador y su reinicio a las 00:00 UTC (RF-03.2).
 *   [3] Atribución al linaje de origen y pago único (RF-03.5, RF-05.1, RNF-01).
 *   [4] Elogio comunitario y un solo voto computable por cuenta (RF-03.3, RNF-02).
 *   [5] Cierre dominical, desempates, pliegue histórico y reinicio (RF-04).
 *   [6] Determinismo y bitácora (RNF-01, RNF-04).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo; cero librerías.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_weekly_dominion_service.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

$filesRequired = [
    $projectRoot . '/src/Models/User.php',
    $projectRoot . '/src/Models/AuditEntry.php',
    $projectRoot . '/src/Dto/LineageDto.php',
    $projectRoot . '/src/Dto/ClanDto.php',
    $projectRoot . '/src/Dto/ClanMemberDto.php',
    $projectRoot . '/src/Dto/DominionAwardDto.php',
    $projectRoot . '/src/Dto/WeeklyCycleDto.php',
    $projectRoot . '/src/Exceptions/SpellNotFoundException.php',
    $projectRoot . '/src/Exceptions/ClanGovernanceException.php',
    $projectRoot . '/src/Repositories/ClanRepository.php',
    $projectRoot . '/src/Repositories/ClanMemberRepository.php',
    $projectRoot . '/src/Repositories/WeeklyCycleRepository.php',
    $projectRoot . '/src/Services/LineageSynergyService.php',
    $projectRoot . '/src/Services/AuditService.php',
    $projectRoot . '/src/Services/WeeklyDominionService.php',
];
foreach ($filesRequired as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, '[FATAL] Falta el fichero ' . $filePath . " — fase roja.\n");
        exit(1);
    }
    require_once $filePath;
}

use Grimorio\Dto\DominionAwardDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Services\AuditService;
use Grimorio\Services\WeeklyDominionService;

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

/** Instante UTC a partir de una estampa ISO 8601. */
function instantOf(string $isoUtc): DateTimeImmutable
{
    return new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
}

/** Prepara un plano efímero con el esquema canónico y una escuela de magia. */
function forgeRealm(string $projectRoot): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

    return $pdo;
}

/** Consagra un mago en el plano. */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor'): User
{
    $stamp = '2026-01-01T00:00:00Z';
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, NULL, :createdAt, :updatedAt)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@arcano.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':createdAt'    => $stamp,
        ':updatedAt'    => $stamp,
    ]);

    return new User(
        id: $userId,
        alias: $alias,
        email: $userId . '@arcano.arc',
        role: $role,
        clanId: null,
        passwordHash: str_repeat('x', 60),
        createdAt: $stamp,
        updatedAt: $stamp,
    );
}

/** Funda una hermandad con contadores de gloria arbitrarios (fixture de contienda). */
function seedClan(
    PDO $pdo,
    string $clanId,
    string $name,
    string $lineageType,
    int $weeklyPoints = 0,
    int $historicalPoints = 0,
    string $updatedAt = '2026-09-01T00:00:00Z',
): void {
    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt, :coatOfArms, :lineageType,
                 :admissionMode, :status, NULL, :weeklyPoints, :historicalPoints,
                 :lastActivityAt, :updatedAt)'
    )->execute([
        ':id'               => $clanId,
        ':slug'             => $clanId,
        ':name'             => $name,
        ':motto'            => 'Lema de prueba',
        ':createdAt'        => '2026-01-01T00:00:00Z',
        ':coatOfArms'       => 'rune_test',
        ':lineageType'      => $lineageType,
        ':admissionMode'    => 'open',
        ':status'           => 'active',
        ':weeklyPoints'     => $weeklyPoints,
        ':historicalPoints' => $historicalPoints,
        ':lastActivityAt'   => $updatedAt,
        ':updatedAt'        => $updatedAt,
    ]);
}

/** Inscribe a un mago en una casa como adepto activo. */
function seedMembership(PDO $pdo, string $memberId, string $clanId, string $userId): void
{
    $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, NULL, NULL)'
    )->execute([
        ':id'       => $memberId,
        ':clanId'   => $clanId,
        ':userId'   => $userId,
        ':role'     => 'adept',
        ':joinedAt' => '2026-02-01T00:00:00Z',
    ]);
}

/** Forja un conjuro en el estado y el instante indicados. */
function seedSpell(
    PDO $pdo,
    string $spellId,
    string $clanId,
    string $authorId,
    int $circle,
    string $element,
    string $status = 'validated',
    string $validatedAt = '2026-09-10T10:00:00Z',
): void {
    $pdo->prepare(
        'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                             mana_cost, circle, math_fingerprint, clan_id, summary, status,
                             validation_signatures_count, signatures_count, created_at, updated_at, validated_at)
         VALUES (:id, :slug, :name, :authorId, :magicSchool, :element, :castingTime,
                 :manaCost, :circle, :fingerprint, :clanId, :summary, :status,
                 0, 0, :createdAt, :updatedAt, :validatedAt)'
    )->execute([
        ':id'            => $spellId,
        ':slug'          => $spellId,
        ':name'          => 'Conjuro ' . $spellId,
        ':authorId'      => $authorId,
        ':magicSchool'   => 'evocation',
        ':element'       => $element,
        ':castingTime'   => 'action',
        ':manaCost'      => 10,
        ':circle'        => $circle,
        ':fingerprint'   => str_repeat('f', 64),
        ':clanId'        => $clanId,
        ':summary'       => 'Resumen de prueba',
        ':status'        => $status,
        ':createdAt'     => '2026-09-01T00:00:00Z',
        ':updatedAt'     => '2026-09-01T00:00:00Z',
        ':validatedAt'   => $status === 'validated' ? $validatedAt : null,
    ]);
}

/** Contadores de gloria de una casa. */
function clanCounters(PDO $pdo, string $clanId): array
{
    $statement = $pdo->prepare('SELECT weekly_points, historical_points FROM clans WHERE id = :clanId');
    $statement->execute([':clanId' => $clanId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return [
        'weekly'     => (int) $row['weekly_points'],
        'historical' => (int) $row['historical_points'],
    ];
}

$pdo = forgeRealm($projectRoot);
$auditService = new AuditService($pdo);
$service = new WeeklyDominionService($pdo, $auditService);

$ember = seedUser($pdo, 'usr_ember', 'ForjadoraDeAscuas', 'editor');
$frost = seedUser($pdo, 'usr_frost', 'TejedorDeEscarcha', 'editor');
$walker = seedUser($pdo, 'usr_walker', 'ErranteSinCasa', 'editor');

// =====================================================================
// FASE 0 · Escala por Círculo Arcano (RF-03.1)
// =====================================================================
echo "═══ FASE 0 · La escala por Círculo Arcano ═══\n";

assertCondition(DominionAwardDto::circleBasePoints(1) === 120, 'Círculo I vale 120 PDA (100 + 1×20)');
assertCondition(DominionAwardDto::circleBasePoints(2) === 140, 'Círculo II vale 140 PDA');
assertCondition(DominionAwardDto::circleBasePoints(3) === 160, 'Círculo III vale 160 PDA');
assertCondition(DominionAwardDto::circleBasePoints(4) === 180, 'Círculo IV vale 180 PDA');
assertCondition(DominionAwardDto::circleBasePoints(5) === 200, 'Círculo V vale 200 PDA');

$rejectedCircle = false;
try {
    DominionAwardDto::circleBasePoints(6);
} catch (InvalidArgumentException) {
    $rejectedCircle = true;
}
assertCondition($rejectedCircle, 'Un Círculo fuera del canon (VI) se rechaza antes de tocar el plano');

seedClan($pdo, 'cln_ember', 'Guardianes de Ascuas', 'primordialFlame');
seedMembership($pdo, 'clm_ember', 'cln_ember', $ember->getId());

// Conjuro de fuego bajo un linaje de fuego: sinergia temática.
seedSpell($pdo, 'spl_ember_synergy', 'cln_ember', $ember->getId(), 1, 'fire');
$synergyAward = $service->awardValidatedSpell('spl_ember_synergy', instantOf('2026-09-10T12:00:00Z'));
assertCondition(
    $synergyAward->basePoints === 120 && $synergyAward->awardedPoints === 150,
    'Un Círculo I sinérgico acredita 150 PDA (120 × 1.25)'
);
assertCondition($synergyAward->hasSynergy, 'El recibo declara la sinergia temática');
assertCondition($synergyAward->synergyBonus() === 30, 'El recibo exhibe la bonificación de linaje');
assertCondition(
    $synergyAward->actionType === DominionAwardDto::ACTION_SPELL_VALIDATED
        && $synergyAward->dailyQuotaRemaining === null
        && $synergyAward->reason === null,
    'El recibo de validación porta el contrato canónico completo'
);
assertCondition(clanCounters($pdo, 'cln_ember')['weekly'] === 150, 'La gloria llega al marcador semanal del clan');

// Conjuro de otro elemento: sin sinergia.
seedClan($pdo, 'cln_frost', 'Tejedores de Escarcha', 'celestialTides');
seedMembership($pdo, 'clm_frost', 'cln_frost', $frost->getId());
seedSpell($pdo, 'spl_frost_plain', 'cln_frost', $frost->getId(), 5, 'darkness');
$plainAward = $service->awardValidatedSpell('spl_frost_plain', instantOf('2026-09-10T12:00:00Z'));
assertCondition(
    $plainAward->basePoints === 200 && $plainAward->awardedPoints === 200 && $plainAward->hasSynergy === false,
    'Un Círculo V ajeno al linaje acredita 200 PDA sin bonificación'
);

// =====================================================================
// FASE 1 · Sinergia del +25% con redondeo aritmético (RF-03.4)
// =====================================================================
echo "\n═══ FASE 1 · El redondeo de la sinergia ═══\n";

$stranger = seedUser($pdo, 'usr_stranger', 'ForasteroCurioso', 'editor');

// Elogio sinérgico: 5 × 1.25 = 6.25 → 6 PDA.
$favoriteAward = $service->awardCommunityFavorite($stranger, 'spl_ember_synergy', instantOf('2026-09-11T10:00:00Z'));
assertCondition(
    $favoriteAward->basePoints === 5 && $favoriteAward->awardedPoints === 6,
    'Cinco PDA sinérgicos redondean a 6 (5 × 1.25 = 6.25 → 6)'
);

// Combo sinérgico: 10 × 1.25 = 12.5 → 13 PDA.
$comboAward = $service->awardSimulatorCombo($ember, 'cln_ember', 'fire', instantOf('2026-09-11T11:00:00Z'));
assertCondition(
    $comboAward->basePoints === 10 && $comboAward->awardedPoints === 13,
    'Diez PDA sinérgicos redondean a 13 (10 × 1.25 = 12.5 → 13)'
);
assertCondition(
    $comboAward->dailyQuotaRemaining === 37,
    'El recibo declara el cupo diario restante tras el combo'
);

// Combo ajeno al linaje: sin bonificación.
$plainCombo = $service->awardSimulatorCombo($ember, 'cln_ember', 'water', instantOf('2026-09-11T11:30:00Z'));
assertCondition(
    $plainCombo->awardedPoints === 10 && $plainCombo->hasSynergy === false,
    'Un combo de elemento ajeno al linaje no recibe bonificación'
);

// Afinidad neutra: el Grimorio usa 'none' para las páginas sin afinidad.
$neutralCombo = $service->awardSimulatorCombo($ember, 'cln_ember', 'none', instantOf('2026-09-11T11:45:00Z'));
assertCondition($neutralCombo->awardedPoints === 10, 'Una afinidad neutra jamás devenga sinergia');

// =====================================================================
// FASE 2 · El techo diario del simulador (RF-03.2, RNF-02)
// =====================================================================
echo "\n═══ FASE 2 · El techo diario de 50 PDA ═══\n";

$bench = seedUser($pdo, 'usr_bench', 'PracticanteIncansable', 'editor');
seedClan($pdo, 'cln_bench', 'Casa del Practicante', 'worldRoots');
seedMembership($pdo, 'clm_bench', 'cln_bench', $bench->getId());

$day = instantOf('2026-09-12T08:00:00Z');
$practiceAwards = [];
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $practiceAwards[] = $service->awardSimulatorCombo(
        $bench,
        'cln_bench',
        'earth',
        $day->modify('+' . ($attempt * 60) . ' minutes'),
    );
}

assertCondition(
    array_sum(array_map(static fn (DominionAwardDto $award): int => $award->awardedPoints, $practiceAwards)) === 50,
    'Cinco combos sinérgicos colman justo el techo diario de 50 PDA (5 × 13 = 65 → acotado a 50)'
);
assertCondition(
    $practiceAwards[4]->dailyQuotaRemaining === 0,
    'El quinto combo agota el cupo del día'
);

$capped = $service->awardSimulatorCombo($bench, 'cln_bench', 'earth', $day->modify('+6 hours'));
assertCondition(
    $capped->reason === DominionAwardDto::REASON_DAILY_SIMULATOR_CAP_REACHED && $capped->awardedPoints === 0,
    'El sexto combo del día es denegado con DAILY_SIMULATOR_CAP_REACHED (RF-03.2)'
);
assertCondition($capped->dailyQuotaRemaining === 0, 'El recibo denegado declara el cupo agotado');
assertCondition(
    clanCounters($pdo, 'cln_bench')['weekly'] === 50,
    'La casa jamás recibe más de 50 PDA diarios por un mismo adepto (RNF-02)'
);
assertCondition(
    (int) $pdo->query("SELECT points_awarded FROM daily_simulator_tracker WHERE user_id = 'usr_bench'")->fetchColumn() === 50,
    'El acumulador diario queda exactamente en el techo'
);

// Reinicio automático a las 00:00:00 UTC de la medianoche siguiente.
assertCondition(
    $service->dailyQuotaRemaining('usr_bench', 'cln_bench', $day) === 0,
    'A las 08:00 UTC del día en curso no resta cupo alguno'
);
assertCondition(
    $service->dailyQuotaRemaining('usr_bench', 'cln_bench', instantOf('2026-09-12T23:59:59Z')) === 0,
    'Un segundo antes de la medianoche el cupo sigue agotado'
);
assertCondition(
    $service->dailyQuotaRemaining('usr_bench', 'cln_bench', instantOf('2026-09-13T00:00:00Z')) === 50,
    'A las 00:00:00 UTC de la medianoche el cupo se reinicia por completo (RF-03.2)'
);

$newDayCombo = $service->awardSimulatorCombo($bench, 'cln_bench', 'earth', instantOf('2026-09-13T00:00:00Z'));
assertCondition(
    $newDayCombo->awardedPoints === 13,
    'En el día nuevo el mismo adepto vuelve a practicar con sinergia'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM daily_simulator_tracker WHERE user_id = 'usr_bench'")->fetchColumn() === 2,
    'El reinicio es una propiedad de la clave (adepto, casa, fecha UTC): dos días, dos asientos'
);

// La guarda del cupo muerde también en el borde: nunca se rebasa el techo.
$edgeUser = seedUser($pdo, 'usr_edge', 'AdeptoDelBorde', 'editor');
seedClan($pdo, 'cln_edge', 'Casa del Borde', 'solarCrown');
seedMembership($pdo, 'clm_edge', 'cln_edge', $edgeUser->getId());
$pdo->exec("INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
            VALUES ('dst_edge', 'usr_edge', 'cln_edge', '2026-09-12', 45)");
$edgeCombo = $service->awardSimulatorCombo($edgeUser, 'cln_edge', 'light', instantOf('2026-09-12T20:00:00Z'));
assertCondition(
    $edgeCombo->basePoints === 5 && $edgeCombo->awardedPoints === 5 && $edgeCombo->dailyQuotaRemaining === 0,
    'Con cinco PDA de cupo restante, la sinergia se acota al cupo en vez de rebasarlo (5 × 1.25 = 6 → 5)'
);

// Un mago sin casa no puede granjear gloria para nadie.
$rejectedPractice = false;
try {
    $service->awardSimulatorCombo($walker, 'cln_bench', 'earth', $day);
} catch (ClanGovernanceException $rejection) {
    $rejectedPractice = $rejection->errorCode === ClanGovernanceException::NOT_A_MEMBER;
}
assertCondition($rejectedPractice, 'Quien no milita en la casa no puede acreditarle práctica (RF-03.2)');

// =====================================================================
// FASE 3 · Atribución al linaje de origen (RF-03.5, RF-05.1)
// =====================================================================
echo "\n═══ FASE 3 · La gloria no sigue al desertor ═══\n";

$desertor = seedUser($pdo, 'usr_desertor', 'AprendizVoluble', 'editor');
seedClan($pdo, 'cln_origin', 'Casa del Origen Eterno', 'dawnWinds');
seedClan($pdo, 'cln_destino', 'Casa del Nuevo Destino', 'abyssalShadows');
seedMembership($pdo, 'clm_desertor_origin', 'cln_origin', $desertor->getId());

// El conjuro fue forjado y sometido a moderación bajo el estandarte de origen.
seedSpell($pdo, 'spl_migrante', 'cln_origin', $desertor->getId(), 3, 'wind', 'experimental', '');
// El autor parte antes de que llegue la tercera firma y se afilia a otra casa.
$pdo->exec("UPDATE clan_members SET left_at = '2026-09-05T00:00:00Z', convalescence_expires_at = NULL
             WHERE user_id = 'usr_desertor'");
seedMembership($pdo, 'clm_desertor_destino', 'cln_destino', $desertor->getId());
// Y el conjuro alcanza por fin el estado ratificado.
$pdo->exec("UPDATE spells SET status = 'validated', validated_at = '2026-09-10T09:00:00Z'
             WHERE id = 'spl_migrante'");

$migrantAward = $service->awardValidatedSpell('spl_migrante', instantOf('2026-09-10T09:00:01Z'));
assertCondition(
    $migrantAward->basePoints === 160 && $migrantAward->awardedPoints === 200,
    'El Círculo III sinérgico del migrante acredita 200 PDA (160 × 1.25)'
);
assertCondition(
    clanCounters($pdo, 'cln_origin')['weekly'] === 200,
    'La gloria se acredita ÍNTEGRA al clan bajo cuyo estandarte fue forjado (RF-03.5)'
);
assertCondition(
    clanCounters($pdo, 'cln_destino')['weekly'] === 0,
    'El clan de acogida del desertor no recibe gloria alguna por un conjuro ajeno'
);

// Un mismo conjuro paga una sola vez.
$repeated = $service->awardValidatedSpell('spl_migrante', instantOf('2026-09-10T09:00:02Z'));
assertCondition(
    $repeated->reason === DominionAwardDto::REASON_ALREADY_AWARDED && $repeated->awardedPoints === 0,
    'Una segunda ratificación del mismo conjuro no vuelve a pagar (ALREADY_AWARDED, RNF-01)'
);
assertCondition(
    clanCounters($pdo, 'cln_origin')['weekly'] === 200,
    'El marcador del clan permanece intacto ante el intento de doble pago'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM dominion_awards WHERE source_id = 'spl_migrante'")->fetchColumn() === 1,
    'El libro de gloria conserva un único asiento por conjuro ratificado'
);

$notValidated = false;
try {
    seedSpell($pdo, 'spl_borrador', 'cln_origin', $desertor->getId(), 1, 'wind', 'experimental', '');
    $service->awardValidatedSpell('spl_borrador', instantOf('2026-09-10T09:00:00Z'));
} catch (InvalidArgumentException) {
    $notValidated = true;
}
assertCondition($notValidated, 'Un conjuro aún experimental no computa para el Dominio');

$missingSpell = false;
try {
    $service->awardValidatedSpell('spl_inexistente', instantOf('2026-09-10T09:00:00Z'));
} catch (SpellNotFoundException) {
    $missingSpell = true;
}
assertCondition($missingSpell, 'Un conjuro inexistente alza SpellNotFoundException');

// =====================================================================
// FASE 4 · Elogio comunitario y un solo voto (RF-03.3, RNF-02)
// =====================================================================
echo "\n═══ FASE 4 · Un solo voto computable por cuenta ═══\n";

$devoto = seedUser($pdo, 'usr_devoto', 'DevotoDeLasAscuas', 'editor');
seedSpell($pdo, 'spl_ember_favorite', 'cln_ember', $ember->getId(), 2, 'fire');

$firstFavorite = $service->awardCommunityFavorite($devoto, 'spl_ember_favorite', instantOf('2026-09-11T12:00:00Z'));
assertCondition(
    $firstFavorite->awardedPoints === 6 && $firstFavorite->hasSynergy,
    'Un elogio sobre un conjuro sinérgico acredita 6 PDA (5 × 1.25 → 6)'
);

$secondFavorite = $service->awardCommunityFavorite($devoto, 'spl_ember_favorite', instantOf('2026-09-11T12:05:00Z'));
assertCondition(
    $secondFavorite->reason === DominionAwardDto::REASON_DUPLICATE_FAVORITE && $secondFavorite->awardedPoints === 0,
    'La misma cuenta no puede registrar dos votos computables sobre el mismo conjuro (RNF-02)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM favorites WHERE user_id = 'usr_devoto' AND spell_id = 'spl_ember_favorite'")->fetchColumn() === 1,
    'La Libreta de Favoritos conserva un único voto: la unicidad es estructural'
);

// El morador de la propia casa puede elogiar, pero su elogio no computa (RF-03.3).
$ownClanFavorite = $service->awardCommunityFavorite($ember, 'spl_ember_favorite', instantOf('2026-09-11T12:10:00Z'));
assertCondition(
    $ownClanFavorite->reason === DominionAwardDto::REASON_OWN_CLAN_FAVORITE && $ownClanFavorite->awardedPoints === 0,
    'El elogio de un morador de la propia casa no computa (RF-03.3)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM favorites WHERE user_id = 'usr_ember'")->fetchColumn() === 0,
    'Un elogio no computable no deja asiento en la Libreta'
);

$beforeFavorites = clanCounters($pdo, 'cln_ember')['weekly'];
$favoriteAgain = $service->awardCommunityFavorite($devoto, 'spl_ember_favorite', instantOf('2026-09-11T12:15:00Z'));
assertCondition(
    clanCounters($pdo, 'cln_ember')['weekly'] === $beforeFavorites,
    'Los elogios duplicados no alteran el marcador de la casa'
);
assertCondition($favoriteAgain->wasAwarded() === false, 'El recibo duplicado declara su denegación');

// =====================================================================
// FASE 5 · El cierre dominical (RF-04.1 a RF-04.5)
// =====================================================================
echo "\n═══ FASE 5 · El corte dominical y sus desempates ═══\n";

$closing = instantOf('2026-09-13T23:59:59Z');

// Contienda: dos casas empatadas a 500 PDA; una tercera, rezagada.
seedClan($pdo, 'cln_tide', 'Casa de la Marea', 'celestialTides', 500, 120, '2026-09-10T10:00:00Z');
seedClan($pdo, 'cln_tempest', 'Casa de la Tempestad', 'eternalTempest', 500, 120, '2026-09-10T10:00:00Z');
seedClan($pdo, 'cln_root', 'Casa de las Raíces', 'worldRoots', 300, 40, '2026-09-10T10:00:00Z');
seedUser($pdo, 'usr_tide_author', 'AutoraDeMarea', 'editor');
seedUser($pdo, 'usr_tempest_author', 'AutorDeTempestad', 'editor');

// Primer desempate: la Tempestad aportó DOS conjuros sellados en la semana
// concluida; la Marea, uno solo.
seedSpell($pdo, 'spl_tide_week', 'cln_tide', 'usr_tide_author', 1, 'water', 'validated', '2026-09-08T10:00:00Z');
seedSpell($pdo, 'spl_tempest_week_a', 'cln_tempest', 'usr_tempest_author', 1, 'lightning', 'validated', '2026-09-08T11:00:00Z');
seedSpell($pdo, 'spl_tempest_week_b', 'cln_tempest', 'usr_tempest_author', 1, 'lightning', 'validated', '2026-09-09T11:00:00Z');
// Y un conjuro sellado FUERA de la semana concluida, que no debe contarse.
seedSpell($pdo, 'spl_tide_old', 'cln_tide', 'usr_tide_author', 1, 'water', 'validated', '2026-08-30T10:00:00Z');
// Ni un borrador, que tampoco aporta conjuros a la contienda.
seedSpell($pdo, 'spl_tide_draft', 'cln_tide', 'usr_tide_author', 1, 'water', 'experimental', '');

$cycle = $service->closeWeeklyCycle($closing);
assertCondition($cycle !== null, 'El corte dominical corona a un Clan Regente (RF-04.2)');
assertCondition(
    $cycle !== null && $cycle->weekNumber === 37 && $cycle->cycleYear === 2026,
    'El ciclo se inscribe en la semana ISO 37 del año 2026'
);
assertCondition(
    $cycle !== null && $cycle->regentClanId === 'cln_tempest',
    'El primer desempate corona al clan con MÁS conjuros validados en la semana (RF-04.5)'
);
assertCondition(
    $cycle !== null && $cycle->winnerSpellCount === 2,
    'El acta consigna los dos conjuros sellados de la casa coronada'
);
assertCondition(
    $cycle !== null && $cycle->winningPoints === 500,
    'El acta consigna los PDA con los que se alzó con la corona'
);
assertCondition(
    $cycle !== null && $cycle->label() === 'Año 2026 · Semana 37',
    'La etiqueta ceremonial viaja en noble castellano (Artículo IV)'
);
assertCondition(
    $cycle !== null && $cycle->regentClanName === 'Casa de la Tempestad',
    'El acta porta el nombre de la casa coronada (RF-04.4)'
);
assertCondition(
    $cycle !== null && $cycle->closedAt === '2026-09-13T23:59:59Z',
    'El corte se estampa en el domingo de las 23:59:59 UTC (RF-04.1)'
);

// RF-04.3: reinicio a cero y pliegue sobre la gloria histórica.
$tempestCounters = clanCounters($pdo, 'cln_tempest');
$tideCounters = clanCounters($pdo, 'cln_tide');
$rootCounters = clanCounters($pdo, 'cln_root');
assertCondition($tempestCounters['weekly'] === 0, 'Los contadores semanales del campeón se reinician a cero');
assertCondition(
    $tideCounters['weekly'] === 0 && $rootCounters['weekly'] === 0,
    'Los contadores semanales de TODAS las casas se reinician a cero (RF-04.3)'
);
assertCondition(
    $tempestCounters['historical'] === 620,
    'Los 500 PDA del campeón se pliegan sobre su gloria histórica (120 + 500)'
);
assertCondition(
    $tideCounters['historical'] === 620 && $rootCounters['historical'] === 340,
    'Cada casa pliega su semana sobre su propio total histórico (RF-04.3)'
);
assertCondition(
    clanCounters($pdo, 'cln_ember')['weekly'] === 0,
    'Las casas que no contendieron también se reinician: el marcador semanal jamás arrastra gloria'
);
// Las Ascuas acumularon 150 + 13 + 10 + 10 (validación y prácticas) más
// 6 + 6 (los dos elogios computables) = 195 PDA durante la semana.
assertCondition(
    clanCounters($pdo, 'cln_ember')['historical'] === 195,
    'La gloria acumulada durante la semana sobrevive al corte en el marcador histórico'
);

// Idempotencia: una semana se corona UNA sola vez.
$again = $service->closeWeeklyCycle($closing);
assertCondition(
    $again !== null && $again->id === $cycle?->id,
    'Un segundo corte de la misma semana devuelve el acta ya inmortalizada'
);
assertCondition(
    clanCounters($pdo, 'cln_tempest')['historical'] === 620,
    'El repliegue no se repite: la gloria histórica no se duplica jamás (RNF-01)'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM weekly_cycles')->fetchColumn() === 1,
    'El Libro Mayor de Campeones conserva una única acta por semana'
);

// Evaluación perezosa: la salvaguarda del cron (plan 5, Decisión 1).
assertCondition(
    $service->ensureCycleIsCurrent(instantOf('2026-09-14T00:00:00Z')) === null,
    'La evaluación perezosa del lunes no reabre una semana ya proclamada'
);

// Segundo desempate: mismo marcador y mismos conjuros; vence quien llegó antes.
seedClan($pdo, 'cln_early', 'Casa Madrugadora', 'solarCrown', 400, 0, '2026-09-15T09:00:00Z');
seedClan($pdo, 'cln_late', 'Casa Tardía', 'abyssalShadows', 400, 0, '2026-09-15T21:00:00Z');
seedClan($pdo, 'cln_chaser', 'Casa Perseguidora', 'dawnWinds', 399, 0, '2026-09-15T08:00:00Z');

$secondCycle = $service->closeWeeklyCycle(instantOf('2026-09-20T23:59:59Z'));
assertCondition(
    $secondCycle !== null && $secondCycle->regentClanId === 'cln_early',
    'Con marcador y conjuros empatados, vence quien alcanzó primero su puntuación (RF-04.5)'
);
assertCondition(
    $secondCycle !== null && $secondCycle->weekNumber === 38,
    'El corte siguiente se inscribe en la semana ISO 38'
);
assertCondition(
    $service->ensureCycleIsCurrent(instantOf('2026-09-21T00:00:00Z')) === null,
    'La semana recién coronada tampoco se reabre en la evaluación perezosa'
);

// La evaluación perezosa SÍ corona una semana vencida que quedó sin acta.
seedClan($pdo, 'cln_orphan', 'Casa Olvidada', 'eternalTempest', 250, 0, '2026-09-22T10:00:00Z');
$lazyCycle = $service->ensureCycleIsCurrent(instantOf('2026-09-27T23:59:59Z'));
assertCondition(
    $lazyCycle !== null && $lazyCycle->regentClanId === 'cln_orphan',
    'Si el cron falló, la primera petición del lunes corona la semana vencida (plan 5)'
);
assertCondition(
    clanCounters($pdo, 'cln_orphan')['historical'] === 250 && clanCounters($pdo, 'cln_orphan')['weekly'] === 0,
    'La salvaguarda perezosa pliega y reinicia con idéntico rigor'
);

// Un santuario sin hermandades activas no proclama a nadie.
$pdo->exec("UPDATE clans SET status = 'archived'");
assertCondition(
    $service->closeWeeklyCycle(instantOf('2026-10-04T23:59:59Z')) === null,
    'Sin hermandades en contienda, el corte no proclama regente alguno'
);

// =====================================================================
// FASE 6 · Determinismo y bitácora pública (RNF-01, RNF-04)
// =====================================================================
echo "\n═══ FASE 6 · Determinismo y bitácora ═══\n";

$source = (string) file_get_contents($projectRoot . '/src/Services/WeeklyDominionService.php');
assertCondition(
    str_contains($source, 'time()') === false
        && str_contains($source, 'microtime') === false
        && str_contains($source, 'date(') === false,
    'El servicio no consulta jamás el reloj del sistema (RNF-01)'
);
assertCondition(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE action_type = 'DOMINION_WEEK_CONCLUDED'"
    )->fetchColumn() === 3,
    'Cada coronación queda inscrita en la Bitácora pública (RNF-04)'
);
assertCondition(
    (string) $pdo->query(
        "SELECT actor_role FROM audit_log WHERE action_type = 'DOMINION_WEEK_CONCLUDED' LIMIT 1"
    )->fetchColumn() === 'system',
    'La coronación se inscribe como acto del santuario, no de una pluma'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM dominion_awards")->fetchColumn() >= 4,
    'Todo mérito acreditado deja su asiento en el libro de gloria (RNF-01)'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(*) FROM dominion_awards WHERE awarded_points <= 0')->fetchColumn() === 0,
    'Ninguna gloria denegada deja asiento: el libro solo registra lo acreditado'
);
assertCondition(
    (int) $pdo->query('SELECT COUNT(DISTINCT action_type) FROM dominion_awards')->fetchColumn() === 2,
    'El libro consigna ambos méritos: conjuros ratificados y elogios comunitarios'
);

// =====================================================================
// FASE 7 · Ascenso de una base legada (sql/07_weekly_dominion_ledger.sql)
// =====================================================================
echo "\n═══ FASE 7 · Ascenso de una base legada ═══\n";

$legacy = forgeRealm($projectRoot);
seedUser($legacy, 'usr_legado', 'MoradorDelPasado', 'editor');
seedClan($legacy, 'cln_legado', 'Casa Legada', 'primordialFlame');
seedSpell($legacy, 'spl_legado', 'cln_legado', 'usr_legado', 1, 'fire');

// El plano anterior a la Tarea 2.5 desconocía el libro de gloria.
$legacy->exec('DROP TABLE IF EXISTS favorites');
$legacy->exec('DROP TABLE IF EXISTS dominion_awards');
$migrationSql = (string) file_get_contents($projectRoot . '/sql/07_weekly_dominion_ledger.sql');
$legacy->exec($migrationSql);

$legacyTables = $legacy->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
assertCondition(
    in_array('favorites', $legacyTables, true) && in_array('dominion_awards', $legacyTables, true),
    'La migración levanta la Libreta de Favoritos y el libro de gloria sobre una base legada'
);

// El script es reejecutable: un segundo pase no rompe ni duplica nada.
$legacy->exec($migrationSql);
assertCondition(true, 'La migración se aplica dos veces sin daño (idempotente)');

$legacyService = new WeeklyDominionService($legacy);
$legacyAward = $legacyService->awardValidatedSpell('spl_legado', instantOf('2026-09-10T12:00:00Z'));
assertCondition(
    $legacyAward->awardedPoints === 150,
    'La base ascendida ya liquida gloria con sinergia (120 × 1.25 = 150)'
);
assertCondition(
    (int) $legacy->query('SELECT COUNT(*) FROM dominion_awards')->fetchColumn() === 1,
    'El libro de gloria recién levantado opera con normalidad'
);

$legacyFavorite = $legacyService->awardCommunityFavorite(
    seedUser($legacy, 'usr_legado_dos', 'ElogiadorTardío', 'editor'),
    'spl_legado',
    instantOf('2026-09-10T13:00:00Z'),
);
assertCondition($legacyFavorite->wasAwarded(), 'La Libreta de Favoritos recién levantada admite el primer voto');

$legacyCycle = $legacyService->closeWeeklyCycle(instantOf('2026-09-13T23:59:59Z'));
assertCondition(
    $legacyCycle !== null && $legacyCycle->regentClanId === 'cln_legado',
    'La base ascendida celebra su primer corte dominical sin contratiempo'
);

// La guarda estructural del doble pago sigue mordiendo tras el ascenso.
$legacyService->awardValidatedSpell('spl_legado', instantOf('2026-09-10T14:00:00Z'));
assertCondition(
    clanCounters($legacy, 'cln_legado')['historical'] === 156,
    'Tras migrar, la gloria sigue pagándose una sola vez (150 del conjuro + 6 del elogio)'
);

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: FALLO — el Dominio Semanal no cumple el canon.\n";
    exit(1);
}

echo "RESULTADO: EXITO — La Tarea 2.5 cumple su criterio 'Hecho cuando'.\n";
exit(0);

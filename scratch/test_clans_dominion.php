<?php

/**
 * test_clans_dominion.php — Suite canónica CLI de hermandades y Dominio.
 *
 * Tarea 4.1 (TASKS-07). Ejecuta en SQLite en memoria los DIEZ bloques de
 * prueba del plan 6.1 y comprueba el criterio «Hecho cuando»:
 *   «La invocación `php scratch/test_clans_dominion.php` supera el 100% de los
 *    10 bloques de prueba con código de salida 0 y reporte exhaustivo en
 *    consola.»
 *
 * Bloques:
 *   [1]  Escala de PDA por Círculo Arcano: 100 + (Círculo × 20) (RF-03.1).
 *   [2]  Sinergia temática del +25% con redondeo aritmético (RF-03.4).
 *   [3]  Cupo máximo de treinta adeptos y bloqueo del número 31 (RF-01.4).
 *   [4]  Tope de tres solicitudes pendientes simultáneas (RF-01.5).
 *   [5]  Convalecencia de catorce días naturales (RF-01.6).
 *   [6]  Veto constitucional de treinta días (RF-01.8, Artículo III).
 *   [7]  Techo de cincuenta PDA del simulador y su reinicio a las 00:00:00 UTC (RF-03.2).
 *   [8]  Desempates semanales deterministas (RF-04.5, RNF-01).
 *   [9]  Sucesión dinástica tras cuarenta y cinco días de silencio (RF-01.9).
 *   [10] Inviolabilidad del Nombre Ancestral (RF-01.2, RF-05.4).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo; cero librerías ni dependencias.
 *   - Artículo II: el arnés jamás reimplementa fórmulas — interroga a los
 *     servicios canónicos, de modo que una divergencia rompe la suite.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_clans_dominion.php
 * Salida: código 0 si los diez bloques pasan; código 1 en caso contrario.
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
    $projectRoot . '/src/Dto/ClanApplicationDto.php',
    $projectRoot . '/src/Dto/DominionAwardDto.php',
    $projectRoot . '/src/Dto/WeeklyCycleDto.php',
    $projectRoot . '/src/Exceptions/ClanGovernanceException.php',
    $projectRoot . '/src/Exceptions/ClanConflictOfInterestException.php',
    $projectRoot . '/src/Exceptions/SpellNotFoundException.php',
    $projectRoot . '/src/Repositories/ClanRepository.php',
    $projectRoot . '/src/Repositories/ClanMemberRepository.php',
    $projectRoot . '/src/Repositories/ClanApplicationRepository.php',
    $projectRoot . '/src/Repositories/WeeklyCycleRepository.php',
    $projectRoot . '/src/Services/LineageSynergyService.php',
    $projectRoot . '/src/Services/AuditRecorderInterface.php',
    $projectRoot . '/src/Services/AuditService.php',
    $projectRoot . '/src/Services/ClanAdmissionResult.php',
    $projectRoot . '/src/Services/PatriarchSuccessionResult.php',
    $projectRoot . '/src/Services/ClanEthicsValidator.php',
    $projectRoot . '/src/Services/ClanService.php',
    $projectRoot . '/src/Services/WeeklyDominionService.php',
];
foreach ($filesRequired as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, '[FATAL] Falta el fichero ' . $filePath . " — fase roja.\n");
        exit(1);
    }
    require_once $filePath;
}

use Grimorio\Dto\ClanApplicationDto;
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Exceptions\ClanConflictOfInterestException;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanEthicsValidator;
use Grimorio\Services\ClanService;
use Grimorio\Services\LineageSynergyService;
use Grimorio\Services\WeeklyDominionService;

// =====================================================================
// Bitácora de consola y contabilidad por bloque
// =====================================================================

/** Asertos superados en toda la suite. */
$assertsPassed = 0;

/** Asertos fallidos en toda la suite. */
$assertsFailed = 0;

/** Bloques ya cerrados: list<array{name: string, passed: int, failed: int}>. */
$blocks = [];

/** Bloque en curso, o null fuera de bloque. */
$currentBlock = null;

/** Abre un bloque del plan y estrena su contabilidad. */
function beginBlock(string $name): void
{
    global $currentBlock;

    echo "\n═══ BLOQUE {$name} ═══\n";
    $currentBlock = ['name' => $name, 'passed' => 0, 'failed' => 0];
}

/** Cierra el bloque en curso y lo inscribe en el reporte final. */
function closeBlock(): void
{
    global $blocks, $currentBlock;

    if ($currentBlock === null) {
        return;
    }

    $blocks[] = $currentBlock;
    $currentBlock = null;
}

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed, $currentBlock;

    if ($condition) {
        $assertsPassed++;
        if ($currentBlock !== null) {
            $currentBlock['passed']++;
        }
        echo "  [PASA] {$description}\n";

        return;
    }

    $assertsFailed++;
    if ($currentBlock !== null) {
        $currentBlock['failed']++;
    }
    echo "  [FALLA] {$description}\n";
}

/** Exige que una operación de gobierno sea rechazada con código y estado canónicos. */
function expectRejection(callable $operation, string $expectedCode, int $expectedStatus, string $description): void
{
    try {
        $operation();
    } catch (ClanGovernanceException $rejection) {
        assertCondition(
            $rejection->errorCode === $expectedCode && $rejection->httpStatus === $expectedStatus,
            $description
                . ($rejection->errorCode === $expectedCode && $rejection->httpStatus === $expectedStatus
                    ? ''
                    : " — se esperaba {$expectedCode}/{$expectedStatus} y llegó {$rejection->errorCode}/{$rejection->httpStatus}")
        );

        return;
    }

    assertCondition(false, $description . ' — ninguna excepción alzó');
}

/** Exige que el veto del Artículo III se manifieste como conflicto de interés. */
function expectConflict(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (ClanConflictOfInterestException) {
        assertCondition(true, $description);

        return;
    }

    assertCondition(false, $description . ' — ninguna excepción alzó');
}

/** Exige que una operación sea rechazada por argumento fuera del canon. */
function expectArgumentRejection(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        assertCondition(true, $description);

        return;
    }

    assertCondition(false, $description . ' — ninguna excepción alzó');
}

/** Instante UTC a partir de una estampa ISO 8601. */
function instantOf(string $isoUtc): DateTimeImmutable
{
    return new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
}

/**
 * Prepara un plano efímero con el esquema canónico completo y una escuela.
 */
function forgeRealm(string $projectRoot): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

    return $pdo;
}

/**
 * Consagra los servicios canónicos sobre un plano dado.
 *
 * @return array{0: ClanService, 1: WeeklyDominionService, 2: ClanEthicsValidator}
 */
function forgeServices(PDO $pdo): array
{
    $auditService = new AuditService($pdo);

    return [
        new ClanService($pdo, $auditService),
        new WeeklyDominionService($pdo, $auditService, new LineageSynergyService()),
        new ClanEthicsValidator(new ClanMemberRepository($pdo)),
    ];
}

/** Consagra un mago en el plano con el rol indicado. */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', string $lineage = 'primordialFlame'): User
{
    $stamp = '2026-01-01T00:00:00Z';
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, lineage, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :lineage, NULL, :createdAt, :updatedAt)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@arcano.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':lineage'      => $role === 'reader' ? null : $lineage,
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
        lineage: $role === 'reader' ? null : $lineage,
        createdAt: $stamp,
        updatedAt: $stamp,
    );
}

/** Inscribe una hermandad con los contadores y la actividad que el bloque exija. */
function insertClan(
    PDO $pdo,
    string $clanId,
    string $name,
    string $lineageType,
    int $weeklyPoints = 0,
    int $historicalPoints = 0,
    string $status = ClanDto::STATUS_ACTIVE,
    ?string $patriarchId = null,
    string $admissionMode = ClanDto::ADMISSION_OPEN,
    string $lastActivityAt = '2026-09-01T00:00:00Z',
    string $updatedAt = '2026-09-01T00:00:00Z',
): void {
    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points, historical_points,
                            last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :createdAt, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, :weeklyPoints, :historicalPoints,
                 :lastActivityAt, :updatedAt)'
    )->execute([
        ':id'               => $clanId,
        ':slug'             => $clanId,
        ':name'             => $name,
        ':motto'            => 'Lema de prueba',
        ':createdAt'        => '2026-01-01T00:00:00Z',
        ':coatOfArms'       => 'rune_test',
        ':lineageType'      => $lineageType,
        ':admissionMode'    => $admissionMode,
        ':status'           => $status,
        ':patriarchId'      => $patriarchId,
        ':weeklyPoints'     => $weeklyPoints,
        ':historicalPoints' => $historicalPoints,
        ':lastActivityAt'   => $lastActivityAt,
        ':updatedAt'        => $updatedAt,
    ]);
}

/** Inscribe una membresía en el historial de la casa (activa o ya cerrada). */
function enrollMember(
    PDO $pdo,
    string $memberId,
    string $clanId,
    string $userId,
    string $role = 'adept',
    string $joinedAt = '2026-02-01T00:00:00Z',
    ?string $leftAt = null,
    ?string $convalescenceExpiresAt = null,
): void {
    $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :convalescenceExpiresAt)'
    )->execute([
        ':id'                       => $memberId,
        ':clanId'                   => $clanId,
        ':userId'                   => $userId,
        ':role'                     => $role,
        ':joinedAt'                 => $joinedAt,
        ':leftAt'                   => $leftAt,
        ':convalescenceExpiresAt'   => $convalescenceExpiresAt,
    ]);
}

/** Asienta una práctica del simulador (libro de aportación por adepto). */
function insertPractice(
    PDO $pdo,
    string $trackerId,
    string $userId,
    string $clanId,
    string $cycleDate,
    int $pointsAwarded,
): void {
    $pdo->prepare(
        'INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
         VALUES (:id, :userId, :clanId, :cycleDate, :pointsAwarded)'
    )->execute([
        ':id'            => $trackerId,
        ':userId'        => $userId,
        ':clanId'        => $clanId,
        ':cycleDate'     => $cycleDate,
        ':pointsAwarded' => $pointsAwarded,
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

/** Censo de adeptos ACTIVOS de una casa, leído del plano. */
function activeMemberCount(PDO $pdo, string $clanId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND left_at IS NULL'
    );
    $statement->execute([':clanId' => $clanId]);

    return (int) $statement->fetchColumn();
}

/** Rol vigente de un mago en su casa, o null si no milita en ella. */
function activeRole(PDO $pdo, string $userId, string $clanId): ?string
{
    $statement = $pdo->prepare(
        'SELECT role FROM clan_members
          WHERE user_id = :userId AND clan_id = :clanId AND left_at IS NULL'
    );
    $statement->execute([':userId' => $userId, ':clanId' => $clanId]);
    $role = $statement->fetchColumn();

    return is_string($role) ? $role : null;
}

/** Corona vigente de una casa, o null si yace acéfala. */
function patriarchOf(PDO $pdo, string $clanId): ?string
{
    $statement = $pdo->prepare('SELECT patriarch_id FROM clans WHERE id = :clanId');
    $statement->execute([':clanId' => $clanId]);
    $patriarchId = $statement->fetchColumn();

    return is_string($patriarchId) && $patriarchId !== '' ? $patriarchId : null;
}

/** Espejo del linaje en `users`, o null si el mago es libre. */
function mirroredClanId(PDO $pdo, string $userId): ?string
{
    $statement = $pdo->prepare('SELECT clan_id FROM users WHERE id = :userId');
    $statement->execute([':userId' => $userId]);
    $clanId = $statement->fetchColumn();

    return is_string($clanId) && $clanId !== '' ? $clanId : null;
}

/** Fin de la convalecencia anotada en una membresía cerrada, o null. */
function convalescenceExpiryOf(PDO $pdo, string $userId, string $clanId): ?string
{
    $statement = $pdo->prepare(
        'SELECT convalescence_expires_at FROM clan_members
          WHERE user_id = :userId AND clan_id = :clanId AND left_at IS NOT NULL'
    );
    $statement->execute([':userId' => $userId, ':clanId' => $clanId]);
    $expiry = $statement->fetchColumn();

    return is_string($expiry) ? $expiry : null;
}

/** Solicitudes pendientes que un mago mantiene simultáneamente. */
function countPendingApplications(PDO $pdo, string $userId): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM clan_applications
          WHERE user_id = :userId AND status = 'pending'"
    );
    $statement->execute([':userId' => $userId]);

    return (int) $statement->fetchColumn();
}

/** Cuántas casas portan un Nombre Canónico dado. */
function clanCount(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM clans WHERE name = :name');
    $statement->execute([':name' => $name]);

    return (int) $statement->fetchColumn();
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

$now = instantOf('2026-09-14T12:00:00Z');

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  SUITE CANÓNICA CLI · HERMANDADES Y DOMINIO (TAREA 4.1, PLAN 6.1)    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

// =====================================================================
// BLOQUE 1 · Escala de PDA por Círculo Arcano (RF-03.1)
// =====================================================================
beginBlock('1 · Escala de PDA por Círculo Arcano');

$circleScale = [1 => 120, 2 => 140, 3 => 160, 4 => 180, 5 => 200];
foreach ($circleScale as $circle => $expectedPoints) {
    assertCondition(
        DominionAwardDto::circleBasePoints($circle) === $expectedPoints,
        "El Círculo {$circle} devenga {$expectedPoints} PDA (100 + {$circle} × 20)"
    );
}

assertCondition(
    DominionAwardDto::CIRCLE_BASE_POINTS === 100 && DominionAwardDto::CIRCLE_POINTS_PER_CIRCLE === 20,
    'La escala canónica se declara como 100 + (Círculo × 20)'
);
expectArgumentRejection(
    static fn () => DominionAwardDto::circleBasePoints(0),
    'El Círculo 0 queda fuera del canon y se rechaza'
);
expectArgumentRejection(
    static fn () => DominionAwardDto::circleBasePoints(6),
    'El Círculo VI queda fuera del canon y se rechaza'
);

$pdo = forgeRealm($projectRoot);
[$clanService, $dominionService] = forgeServices($pdo);

$fundadorEscala = seedUser($pdo, 'usr_escala_fundador', 'FundadorDeLaEscala', 'editor');
$casaEscala = $clanService->foundClan(
    $fundadorEscala,
    'Casa de los Cinco Círculos',
    'Cinco anillos, un solo fulgor',
    'rune_quint',
    'primordialFlame',
    ClanDto::ADMISSION_OPEN,
    $now,
);

seedSpell($pdo, 'spl_circulo_v', $casaEscala->id, $fundadorEscala->getId(), 5, 'none', 'validated', '2026-09-10T10:00:00Z');
seedSpell($pdo, 'spl_circulo_i', $casaEscala->id, $fundadorEscala->getId(), 1, 'water', 'validated', '2026-09-10T11:00:00Z');

$reciboQuinto = $dominionService->awardValidatedSpell('spl_circulo_v', $now);
assertCondition(
    $reciboQuinto->basePoints === 200 && $reciboQuinto->awardedPoints === 200,
    'Un conjuro del Círculo V sellado acredita 200 PDA a su linaje'
);
assertCondition(
    $reciboQuinto->hasSynergy === false,
    'Sin coincidencia de afinidad el recibo no declara sinergia alguna'
);

$reciboPrimero = $dominionService->awardValidatedSpell('spl_circulo_i', $now);
assertCondition($reciboPrimero->awardedPoints === 120, 'Un conjuro del Círculo I acredita 120 PDA');
assertCondition(
    clanCounters($pdo, $casaEscala->id)['weekly'] === 320,
    'La gloria de ambos conjuros aterriza en el marcador semanal de la casa (200 + 120)'
);
assertCondition(
    $dominionService->awardValidatedSpell('spl_circulo_v', $now)->awardedPoints === 0,
    'Un mismo conjuro paga UNA sola vez: el segundo recibo no acredita gloria (RNF-01)'
);

closeBlock();

// =====================================================================
// BLOQUE 2 · Sinergia temática del +25% con redondeo (RF-03.4)
// =====================================================================
beginBlock('2 · Sinergia temática del +25% con redondeo');

$lineageService = new LineageSynergyService();
assertCondition(
    $lineageService->applySynergy(5, 'primordialFlame', 'fire') === 6,
    '5 PDA × 1.25 = 6.25 → 6 PDA (redondeo aritmético)'
);
assertCondition(
    $lineageService->applySynergy(10, 'primordialFlame', 'fire') === 13,
    '10 PDA × 1.25 = 12.5 → 13 PDA (redondeo aritmético)'
);
assertCondition(
    $lineageService->applySynergy(200, 'primordialFlame', 'fire') === 250,
    'Un conjuro del Círculo V con afinidad devenga 200 + 25% = 250 PDA'
);
assertCondition(
    $lineageService->applySynergy(10, 'primordialFlame', 'water') === 10,
    'Un elemento ajeno al linaje retorna el valor base intacto'
);
assertCondition(
    $lineageService->applySynergy(10, 'primordialFlame', 'none') === 10,
    'El conjuro sin elemento rector nunca devenga sinergia'
);
assertCondition(
    $lineageService->applySynergy(10, 'primordialFlame', '') === 10,
    'La afinidad vacía equivale a la ausencia de sinergia'
);
assertCondition(
    $lineageService->synergyBonus(5, 'primordialFlame', 'fire') === 1,
    'El diferencial de sinergia del elogio es de 1 PDA'
);
assertCondition(
    $lineageService->agreesWithAwardContract(),
    'El motor de linajes y el contrato del recibo declaran idéntico factor'
);

$pdo = forgeRealm($projectRoot);
[$clanService, $dominionService] = forgeServices($pdo);

$autoraLlama = seedUser($pdo, 'usr_llama_autora', 'AutoraDeLaLlama', 'editor');
$casaLlama = $clanService->foundClan(
    $autoraLlama,
    'Custodios de la Llama Primigenia',
    'En la ceniza renace la llama inmortal',
    'rune_ignis',
    'primordialFlame',
    ClanDto::ADMISSION_OPEN,
    $now,
);
seedSpell($pdo, 'spl_ignis', $casaLlama->id, $autoraLlama->getId(), 1, 'fire', 'validated', '2026-09-10T10:00:00Z');

$visitante = seedUser($pdo, 'usr_llama_visitante', 'VisitanteDelAlba', 'editor');
$favorito = $dominionService->awardCommunityFavorite($visitante, 'spl_ignis', $now);
assertCondition(
    $favorito->basePoints === 5 && $favorito->awardedPoints === 6 && $favorito->hasSynergy,
    'Un elogio con sinergia acredita 6 PDA (5 × 1.25)'
);

$practicanteSinergia = seedUser($pdo, 'usr_llama_practicante', 'AprendizDeLaLlama', 'editor');
enrollMember($pdo, 'clm_llama_practicante', $casaLlama->id, 'usr_llama_practicante');

$comboSinergia = $dominionService->awardSimulatorCombo($practicanteSinergia, $casaLlama->id, 'fire', $now);
assertCondition(
    $comboSinergia->basePoints === 10 && $comboSinergia->awardedPoints === 13 && $comboSinergia->hasSynergy,
    'Un combo con sinergia acredita 13 PDA (10 × 1.25)'
);
assertCondition($comboSinergia->synergyBonus() === 3, 'El recibo consigna el diferencial de sinergia del combo');

$comboAjeno = $dominionService->awardSimulatorCombo($practicanteSinergia, $casaLlama->id, 'water', $now);
assertCondition(
    $comboAjeno->awardedPoints === 10 && $comboAjeno->hasSynergy === false,
    'Un combo de elemento ajeno acredita su valor base sin bonificación'
);
assertCondition(
    clanCounters($pdo, $casaLlama->id)['weekly'] === 29,
    'La casa acumula 6 + 13 + 10 = 29 PDA durante la contienda'
);

closeBlock();

// =====================================================================
// BLOQUE 3 · Cupo máximo de treinta adeptos (RF-01.4)
// =====================================================================
beginBlock('3 · Cupo máximo de treinta adeptos');

$pdo = forgeRealm($projectRoot);
[$clanService] = forgeServices($pdo);

$patronCupo = seedUser($pdo, 'usr_cupo_patron', 'PatriarcaDelCupo', 'editor', 'worldRoots');
$casaCupo = $clanService->foundClan(
    $patronCupo,
    'Casa del Cupo Colmado',
    'Treinta hermanos y ni uno más',
    'rune_cupo',
    'worldRoots',
    ClanDto::ADMISSION_OPEN,
    $now,
);

for ($index = 1; $index <= ClanMemberRepository::MAX_ACTIVE_MEMBERS - 1; $index++) {
    $clanService->applyToClan(
        seedUser($pdo, 'usr_cupo_' . $index, 'Adepto del Cupo ' . $index, 'editor', 'worldRoots'),
        $casaCupo->id,
        $now,
    );
}

assertCondition(
    activeMemberCount($pdo, $casaCupo->id) === 30,
    'Treinta adeptos activos colman la casa (RF-01.4)'
);
assertCondition(
    ClanMemberRepository::MAX_ACTIVE_MEMBERS === ClanDto::MEMBER_LIMIT
        && ClanDto::MEMBER_LIMIT === 30,
    'El cupo canónico se declara idéntico en el DTO y en el repositorio: treinta adeptos'
);
assertCondition(
    $clanService->findClanById($casaCupo->id)?->memberCount === 30,
    'La ficha heráldica exhibe la ocupación «30/30»'
);

$aspiranteTrigésimoPrimero = seedUser($pdo, 'usr_cupo_31', 'ElTrigésimoPrimero', 'editor', 'worldRoots');
expectRejection(
    fn () => $clanService->applyToClan($aspiranteTrigésimoPrimero, $casaCupo->id, $now),
    ClanGovernanceException::CLAN_QUOTA_EXCEEDED,
    409,
    'El adepto número 31 es rechazado (409 CLAN_QUOTA_EXCEEDED)'
);
assertCondition(
    activeMemberCount($pdo, $casaCupo->id) === 30,
    'El censo permanece en treinta: el bloqueo no es cosmético'
);
assertCondition(
    mirroredClanId($pdo, 'usr_cupo_31') === null,
    'El rechazado no queda espejado en linaje alguno'
);
assertCondition(
    activeRole($pdo, 'usr_cupo_31', $casaCupo->id) === null,
    'Y ninguna membresía se inscribe a su nombre'
);

closeBlock();

// =====================================================================
// BLOQUE 4 · Tope de tres solicitudes pendientes (RF-01.5)
// =====================================================================
beginBlock('4 · Tope de tres solicitudes pendientes');

$pdo = forgeRealm($projectRoot);
[$clanService] = forgeServices($pdo);

assertCondition(
    ClanApplicationDto::MAX_PENDING_APPLICATIONS === 3,
    'El canon declara un tope de tres solicitudes pendientes'
);

$postulante = seedUser($pdo, 'usr_postulante', 'ElCortejadorIncansable', 'editor', 'aetherWeavers');
$casasHermeticas = [];
for ($index = 1; $index <= 4; $index++) {
    $fundadora = seedUser($pdo, 'usr_hermetica_' . $index, 'Fundadora Hermética ' . $index, 'editor', 'aetherWeavers');
    $casasHermeticas[$index] = $clanService->foundClan(
        $fundadora,
        'Casa Hermética ' . $index,
        'Solo por postulación',
        'rune_hermetica_' . $index,
        'aetherWeavers',
        ClanDto::ADMISSION_BY_APPLICATION,
        $now,
    );
}

$resoluciones = [];
for ($index = 1; $index <= 3; $index++) {
    // El molde de SPEC-10 (RF-03.1) exige motivación en la petición formal.
    $resoluciones[$index] = $clanService->applyToClan(
        $postulante,
        $casasHermeticas[$index]->id,
        $now,
        'Cortejo esta hermandad con voto de servicio y silencio.'
    );
}

assertCondition(
    $resoluciones[1]->isPending() && $resoluciones[2]->isPending() && $resoluciones[3]->isPending(),
    'Las tres primeras postulaciones quedan pendientes de veredicto'
);
assertCondition(
    $resoluciones[1]->isAdmitted() === false && $resoluciones[1]->membership === null,
    'En régimen cerrado el ingreso no es inmediato: nadie milita aún en esas casas'
);
assertCondition(
    countPendingApplications($pdo, 'usr_postulante') === 3,
    'El plano registra exactamente tres solicitudes pendientes'
);

expectRejection(
    fn () => $clanService->applyToClan($postulante, $casasHermeticas[4]->id, $now, 'La cuarta postulación excede el cupo de tres pendientes.'),
    ClanGovernanceException::PENDING_APPLICATIONS_LIMIT,
    400,
    'La cuarta solicitud simultánea es rechazada (400 PENDING_APPLICATIONS_LIMIT)'
);
assertCondition(
    countPendingApplications($pdo, 'usr_postulante') === 3,
    'El tope no se desborda: siguen siendo tres solicitudes'
);
assertCondition(
    $clanService->listPendingApplications(
        new User(
            id: 'usr_hermetica_1',
            alias: 'Fundadora Hermética 1',
            email: 'usr_hermetica_1@arcano.arc',
            role: 'editor',
            clanId: $casasHermeticas[1]->id,
            passwordHash: str_repeat('x', 60),
            createdAt: '2026-01-01T00:00:00Z',
            updatedAt: '2026-01-01T00:00:00Z',
        ),
        $casasHermeticas[1]->id,
    ) !== [],
    'El Patriarca ve obrar la postulación pendiente sobre su casa'
);

expectRejection(
    fn () => $clanService->applyToClan($postulante, $casasHermeticas[1]->id, $now, 'Re-postulación vedada: la casa quedó clausurada.'),
    ClanGovernanceException::APPLICATION_ALREADY_PENDING,
    409,
    'Duplicar la postulación ante la misma casa se rechaza (409 APPLICATION_ALREADY_PENDING)'
);

closeBlock();

// =====================================================================
// BLOQUE 5 · Convalecencia de catorce días naturales (RF-01.6)
// =====================================================================
beginBlock('5 · Convalecencia de catorce días naturales');

$pdo = forgeRealm($projectRoot);
[$clanService] = forgeServices($pdo);

$patriarcaPartenza = seedUser($pdo, 'usr_partenza_patron', 'PatriarcaDeLaPartenza', 'editor', 'celestialTides');
$casaPartenza = $clanService->foundClan(
    $patriarcaPartenza,
    'Casa del Adiós Meditado',
    'Quien parte, medita',
    'rune_partenza',
    'celestialTides',
    ClanDto::ADMISSION_OPEN,
    $now,
);

$partiente = seedUser($pdo, 'usr_partiente', 'ElQuePartió', 'editor', 'celestialTides');
$clanService->applyToClan($partiente, $casaPartenza->id, $now);

$departure = instantOf('2026-09-01T12:00:00Z');
$clanService->leaveClan($partiente, $casaPartenza->id, $departure);
assertCondition(
    convalescenceExpiryOf($pdo, 'usr_partiente', $casaPartenza->id) === '2026-09-15T12:00:00Z',
    'La renuncia abre exactamente catorce días naturales de meditación (1 sep 12:00 → 15 sep 12:00)'
);
assertCondition(
    mirroredClanId($pdo, 'usr_partiente') === null,
    'Quien parte queda sin linaje: el espejo vuelve a «sin casa»'
);

expectRejection(
    fn () => $clanService->applyToClan($partiente, $casaPartenza->id, $departure),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'En convalecencia no se ingresa en otra casa (403 CONVALESCENCE_ACTIVE)'
);
expectRejection(
    fn () => $clanService->foundClan(
        $partiente,
        'Casa Prematura',
        'Lema prematuro',
        'rune_prematura',
        'celestialTides',
        ClanDto::ADMISSION_OPEN,
        $departure,
    ),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'En convalecencia tampoco se funda una casa'
);
expectRejection(
    fn () => $clanService->foundClan(
        $partiente,
        'Casa CasiCumplida',
        'Lema casi cumplido',
        'rune_casi',
        'celestialTides',
        ClanDto::ADMISSION_OPEN,
        instantOf('2026-09-15T11:59:59Z'),
    ),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'Un segundo antes del plazo la convalecencia aún veda la fundación'
);

$casaRenacida = $clanService->foundClan(
    $partiente,
    'Casa del Renacido',
    'Cumplida la meditación, la senda vuelve a abrirse',
    'rune_renacido',
    'celestialTides',
    ClanDto::ADMISSION_OPEN,
    instantOf('2026-09-15T12:00:00Z'),
);
assertCondition(
    $casaRenacida->patriarchId === 'usr_partiente',
    'Cumplidos los catorce días exactos, el mago recupera su libertad (frontera inclusiva)'
);

$expulsado = seedUser($pdo, 'usr_expulsado', 'ElExpulsado', 'editor', 'celestialTides');
$clanService->applyToClan($expulsado, $casaPartenza->id, instantOf('2026-09-02T12:00:00Z'));
$clanService->expelMember($patriarcaPartenza, $casaPartenza->id, $expulsado->getId(), instantOf('2026-09-10T09:00:00Z'));
assertCondition(
    convalescenceExpiryOf($pdo, 'usr_expulsado', $casaPartenza->id) === '2026-09-24T09:00:00Z',
    'La expulsión abre idéntica convalecencia que la renuncia (RF-01.6)'
);
expectRejection(
    fn () => $clanService->applyToClan($expulsado, $casaPartenza->id, instantOf('2026-09-11T09:00:00Z')),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'El expulsado queda vedado de todo ingreso mientras medita'
);

closeBlock();

// =====================================================================
// BLOQUE 6 · Veto constitucional de treinta días (RF-01.8, Artículo III)
// =====================================================================
beginBlock('6 · Veto constitucional de treinta días');

$pdo = forgeRealm($projectRoot);
[, , $ethicsValidator] = forgeServices($pdo);

insertClan($pdo, 'cln_sangre', 'Casa de la Sangre', 'primordialFlame');
insertClan($pdo, 'cln_memoria', 'Casa de la Memoria', 'celestialTides');
insertClan($pdo, 'cln_ajena', 'Casa Ajena Al Juicio', 'worldRoots');

$evaluation = instantOf('2026-09-14T12:00:00Z');

seedUser($pdo, 'usr_maestro_sangre', 'MaestroDeLaSangre', 'master');
enrollMember($pdo, 'clm_maestro_sangre', 'cln_sangre', 'usr_maestro_sangre', 'adept', '2026-03-01T00:00:00Z');

assertCondition(
    ClanEthicsValidator::HISTORICAL_WINDOW_DAYS === 30,
    'La ventana de incompatibilidad histórica es de treinta días naturales'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_sangre', 'cln_sangre', $evaluation) === false,
    'Un Maestro no juzga conjuros de su propio linaje actual (regla 1)'
);
assertCondition(
    $ethicsValidator->vetoReasonFor('usr_maestro_sangre', 'cln_sangre', $evaluation) !== null,
    'El veto se manifiesta además como motivo solemne'
);
expectConflict(
    fn () => $ethicsValidator->assertMasterCanEvaluateSpell('usr_maestro_sangre', 'cln_sangre', $evaluation),
    'La versión imperativa alza el conflicto de interés y no puede ignorarse por descuido'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_sangre', 'cln_ajena', $evaluation) === true,
    'Un linaje ajeno a su sangre no le veda el juicio'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_sangre', null, $evaluation) === true,
    'Sin estandarte —conjuro de ermitaño— nada nubla su juicio'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_sangre', '', $evaluation) === true,
    'La cadena vacía equivale al conjuro sin linaje'
);

// Historial: tres Maestros que partieron de la Casa de la Memoria en los
// bordes exactos de la ventana de treinta días naturales.
seedUser($pdo, 'usr_maestro_reciente', 'ElQuePartióHace29Días', 'master');
enrollMember(
    $pdo,
    'clm_reciente',
    'cln_memoria',
    'usr_maestro_reciente',
    'adept',
    '2026-02-01T00:00:00Z',
    '2026-08-16T12:00:00Z',
    '2026-08-30T12:00:00Z',
);
seedUser($pdo, 'usr_maestro_frontera', 'ElQuePartióHace30Días', 'master');
enrollMember(
    $pdo,
    'clm_frontera',
    'cln_memoria',
    'usr_maestro_frontera',
    'adept',
    '2026-02-01T00:00:00Z',
    '2026-08-15T12:00:00Z',
    '2026-08-29T12:00:00Z',
);
seedUser($pdo, 'usr_maestro_antiguo', 'ElQuePartióHace31Días', 'master');
enrollMember(
    $pdo,
    'clm_antiguo',
    'cln_memoria',
    'usr_maestro_antiguo',
    'adept',
    '2026-02-01T00:00:00Z',
    '2026-08-14T12:00:00Z',
    '2026-08-28T12:00:00Z',
);

assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_reciente', 'cln_memoria', $evaluation) === false,
    'Quien partió hace veintinueve días queda vetado por incompatibilidad histórica (regla 2)'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_frontera', 'cln_memoria', $evaluation) === false,
    'Partida hace exactamente treinta días: la ventana aún veda el juicio'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_antiguo', 'cln_memoria', $evaluation) === true,
    'Quien partió hace treinta y un días queda admitido a deliberar'
);
expectConflict(
    fn () => $ethicsValidator->assertMasterCanEvaluateSpell('usr_maestro_reciente', 'cln_memoria', $evaluation),
    'El veto histórico también se alza como conflicto de interés'
);
assertCondition(
    $ethicsValidator->canMasterEvaluateSpell('usr_maestro_reciente', 'cln_ajena', $evaluation) === true,
    'Al Maestro en convalecencia histórica solo le veda su antigua casa'
);
assertCondition(
    $ethicsValidator->vetoReasonFor('usr_maestro_sangre', 'cln_sangre', $evaluation->modify('+1 day')) !== null,
    'El veto del propio linaje no caduca con el tiempo: dura mientras dure la sangre'
);

closeBlock();

// =====================================================================
// BLOQUE 7 · Techo diario del simulador y su reinicio a las 00:00 UTC (RF-03.2)
// =====================================================================
beginBlock('7 · Techo diario del simulador y reinicio a las 00:00 UTC');

$pdo = forgeRealm($projectRoot);
[$clanService, $dominionService] = forgeServices($pdo);

assertCondition(
    DominionAwardDto::DAILY_SIMULATOR_CAP === 50 && DominionAwardDto::SIMULATOR_COMBO_POINTS === 10,
    'El canon declara diez PDA por combo y un techo diario de cincuenta'
);

insertClan($pdo, 'cln_ascuas', 'Casa de las Ascuas', 'worldRoots');
$practicante = seedUser($pdo, 'usr_practicante_techo', 'PracticanteDeLasAscuas', 'editor');
enrollMember($pdo, 'clm_practicante_techo', 'cln_ascuas', 'usr_practicante_techo');

$day = instantOf('2026-09-14T08:00:00Z');
$quotas = [];
for ($index = 1; $index <= 5; $index++) {
    $receipt = $dominionService->awardSimulatorCombo($practicante, 'cln_ascuas', 'fire', $day);
    $quotas[] = $receipt->dailyQuotaRemaining;
    assertCondition($receipt->awardedPoints === 10, "La práctica {$index} del día acredita diez PDA");
}

assertCondition(
    $quotas === [40, 30, 20, 10, 0],
    'El cupo diario desciende de diez en diez: 40, 30, 20, 10 y 0'
);
assertCondition(
    clanCounters($pdo, 'cln_ascuas')['weekly'] === 50,
    'Las cinco prácticas acreditan exactamente cincuenta PDA al linaje'
);

$bloqueado = $dominionService->awardSimulatorCombo($practicante, 'cln_ascuas', 'fire', $day);
assertCondition(
    $bloqueado->wasAwarded() === false && $bloqueado->awardedPoints === 0,
    'La sexta práctica del día no acredita gloria alguna'
);
assertCondition(
    $bloqueado->reason === DominionAwardDto::REASON_DAILY_SIMULATOR_CAP_REACHED,
    'El recibo denegado porta el motivo canónico del techo colmado'
);
assertCondition(
    $bloqueado->dailyQuotaRemaining === 0,
    'El cupo restante es cero: nada queda por consumir en la jornada'
);
assertCondition(
    $dominionService->dailyQuotaRemaining('usr_practicante_techo', 'cln_ascuas', $day) === 0,
    'La consulta del cupo confirma el agotamiento del día UTC'
);

$tardeDelMismoDía = instantOf('2026-09-14T23:59:59Z');
assertCondition(
    $dominionService->awardSimulatorCombo($practicante, 'cln_ascuas', 'fire', $tardeDelMismoDía)->wasAwarded() === false,
    'Ni un segundo antes de la medianoche UTC se rebasa el techo'
);

$primeraHoraDelDíaSiguiente = instantOf('2026-09-15T00:00:00Z');
$renovado = $dominionService->awardSimulatorCombo($practicante, 'cln_ascuas', 'fire', $primeraHoraDelDíaSiguiente);
assertCondition(
    $renovado->awardedPoints === 10 && $renovado->dailyQuotaRemaining === 40,
    'A las 00:00:00 UTC del día siguiente el cupo se reinicia: se acreditan diez PDA con cuarenta restantes'
);
assertCondition(
    clanCounters($pdo, 'cln_ascuas')['weekly'] === 60,
    'La gloria del nuevo día se acumula sobre la jornada anterior (50 + 10)'
);

$segundoPracticante = seedUser($pdo, 'usr_practicante_segundo', 'SegundoPracticante', 'editor');
enrollMember($pdo, 'clm_practicante_segundo', 'cln_ascuas', 'usr_practicante_segundo');
assertCondition(
    $dominionService->awardSimulatorCombo($segundoPracticante, 'cln_ascuas', 'fire', $day)->awardedPoints === 10,
    'El techo es personal: otro adepto dispone de su propio cupo diario en la misma casa'
);

closeBlock();

// =====================================================================
// BLOQUE 8 · Desempates semanales deterministas (RF-04.5, RNF-01)
// =====================================================================
beginBlock('8 · Desempates semanales deterministas');

// Escenario A · gloria idéntica; dirime el número de conjuros sellados.
$pdo = forgeRealm($projectRoot);
[$clanService, $dominionService] = forgeServices($pdo);

$closingWeek = instantOf('2026-09-14T12:00:00Z');
insertClan($pdo, 'cln_marea', 'Casa de la Marea', 'celestialTides', 500, 120, ClanDto::STATUS_ACTIVE, null, ClanDto::ADMISSION_OPEN, '2026-09-10T10:00:00Z', '2026-09-10T10:00:00Z');
insertClan($pdo, 'cln_tempestad', 'Casa de la Tempestad', 'eternalTempest', 500, 120, ClanDto::STATUS_ACTIVE, null, ClanDto::ADMISSION_OPEN, '2026-09-10T10:00:00Z', '2026-09-10T10:00:00Z');
insertClan($pdo, 'cln_raices', 'Casa de las Raíces', 'worldRoots', 300, 40, ClanDto::STATUS_ACTIVE, null, ClanDto::ADMISSION_OPEN, '2026-09-10T10:00:00Z', '2026-09-10T10:00:00Z');
insertClan($pdo, 'cln_archivada', 'Casa Yacente', 'abyssalShadows', 0, 90, ClanDto::STATUS_ARCHIVED);

$autoraMarea = seedUser($pdo, 'usr_autor_marea', 'AutoraDeMarea', 'editor');
$autorTempestad = seedUser($pdo, 'usr_autor_tempestad', 'AutorDeTempestad', 'editor');
seedSpell($pdo, 'spl_marea_semana', 'cln_marea', 'usr_autor_marea', 1, 'water', 'validated', '2026-09-08T10:00:00Z');
seedSpell($pdo, 'spl_tempestad_semana_a', 'cln_tempestad', 'usr_autor_tempestad', 1, 'lightning', 'validated', '2026-09-08T11:00:00Z');
seedSpell($pdo, 'spl_tempestad_semana_b', 'cln_tempestad', 'usr_autor_tempestad', 1, 'lightning', 'validated', '2026-09-09T11:00:00Z');
seedSpell($pdo, 'spl_marea_fuera', 'cln_marea', 'usr_autor_marea', 1, 'water', 'validated', '2026-08-30T10:00:00Z');
seedSpell($pdo, 'spl_marea_borrador', 'cln_marea', 'usr_autor_marea', 1, 'water', 'experimental', '');

$actaA = $dominionService->closeWeeklyCycle($closingWeek);
assertCondition($actaA !== null, 'El corte dominical corona a un Clan Regente (RF-04.2)');
assertCondition(
    $actaA !== null && $actaA->regentClanId === 'cln_tempestad',
    'Primer desempate: se corona a quien aportó más conjuros sellados en la semana (RF-04.5)'
);
assertCondition(
    $actaA !== null && $actaA->winnerSpellCount === 2,
    'El acta consigna los dos conjuros sellados de la casa coronada'
);
assertCondition(
    $actaA !== null && $actaA->winningPoints === 500,
    'El acta consigna los 500 PDA del empate en el marcador'
);
assertCondition(
    $actaA !== null && $actaA->closedAt === '2026-09-13T23:59:59Z',
    'El corte se estampa en el domingo de las 23:59:59 UTC (RF-04.1)'
);
assertCondition(
    $actaA !== null && $actaA->weekNumber === 37 && $actaA->cycleYear === 2026,
    'El ciclo se inscribe en la semana ISO 37 del año 2026'
);
assertCondition(
    clanCounters($pdo, 'cln_marea')['weekly'] === 0
        && clanCounters($pdo, 'cln_raices')['weekly'] === 0
        && clanCounters($pdo, 'cln_archivada')['weekly'] === 0,
    'TODAS las casas reinician su contador semanal, incluidas las disueltas (RF-04.3)'
);
assertCondition(
    clanCounters($pdo, 'cln_tempestad')['historical'] === 620,
    'La gloria del campeón se pliega sobre su total histórico (120 + 500)'
);
assertCondition(
    clanCounters($pdo, 'cln_marea')['historical'] === 620
        && clanCounters($pdo, 'cln_raices')['historical'] === 340,
    'Cada casa pliega su semana sobre su propio total histórico'
);
assertCondition(
    $dominionService->closeWeeklyCycle($closingWeek)?->id === $actaA?->id
        && clanCounters($pdo, 'cln_tempestad')['historical'] === 620,
    'Un segundo corte de la misma semana devuelve el acta ya inmortalizada sin duplicar gloria'
);

// Escenario B · gloria y conjuros idénticos; dirime quién alcanzó antes la marca.
$pdo = forgeRealm($projectRoot);
[$clanService, $dominionService] = forgeServices($pdo);

insertClan($pdo, 'cln_temprano', 'Casa del Alba Temprana', 'solarCrown', 400, 0, ClanDto::STATUS_ACTIVE, null, ClanDto::ADMISSION_OPEN, '2026-09-10T08:00:00Z', '2026-09-10T08:00:00Z');
insertClan($pdo, 'cln_tardio', 'Casa del Ocaso Tardío', 'abyssalShadows', 400, 0, ClanDto::STATUS_ACTIVE, null, ClanDto::ADMISSION_OPEN, '2026-09-11T09:00:00Z', '2026-09-11T09:00:00Z');

$autoraAlba = seedUser($pdo, 'usr_autor_temprano', 'AutoraDelAlba', 'editor');
$autorOcaso = seedUser($pdo, 'usr_autor_tardio', 'AutorDelOcaso', 'editor');
seedSpell($pdo, 'spl_temprano_semana', 'cln_temprano', 'usr_autor_temprano', 1, 'light', 'validated', '2026-09-09T10:00:00Z');
seedSpell($pdo, 'spl_tardio_semana', 'cln_tardio', 'usr_autor_tardio', 1, 'dark', 'validated', '2026-09-09T11:00:00Z');

$actaB = $dominionService->closeWeeklyCycle($closingWeek);
assertCondition(
    $actaB !== null && $actaB->winnerSpellCount === 1,
    'Ambas casas empatan también en conjuros sellados durante la semana'
);
assertCondition(
    $actaB !== null && $actaB->regentClanId === 'cln_temprano',
    'Segundo desempate: se corona a quien alcanzó antes su gloria (timestamp anterior)'
);

closeBlock();

// =====================================================================
// BLOQUE 9 · Sucesión dinástica tras cuarenta y cinco días (RF-01.9)
// =====================================================================
beginBlock('9 · Sucesión dinástica tras 45 días de silencio');

$pdo = forgeRealm($projectRoot);
[$clanService] = forgeServices($pdo);

assertCondition(
    ClanService::PATRIARCH_INACTIVITY_DAYS === 45,
    'El canon declara cuarenta y cinco días de silencio antes del velatorio'
);

seedUser($pdo, 'usr_pat_silencio', 'ElPatriarcaQueCalló', 'editor');
seedUser($pdo, 'usr_veterano', 'AdeptoVeterano', 'editor');
seedUser($pdo, 'usr_novato', 'AdeptoNovato', 'editor');
insertClan($pdo, 'cln_silencio', 'Casa del Silencio Largo', 'worldRoots', 0, 0, ClanDto::STATUS_ACTIVE, 'usr_pat_silencio', ClanDto::ADMISSION_OPEN, '2026-07-31T12:00:00Z', '2026-07-31T12:00:00Z');
enrollMember($pdo, 'clm_silencio_pat', 'cln_silencio', 'usr_pat_silencio', 'patriarch', '2026-01-01T00:00:00Z');
enrollMember($pdo, 'clm_silencio_vet', 'cln_silencio', 'usr_veterano', 'adept', '2026-02-01T00:00:00Z');
enrollMember($pdo, 'clm_silencio_nov', 'cln_silencio', 'usr_novato', 'adept', '2026-06-01T00:00:00Z');

$cuarentaYCuatroDías = instantOf('2026-09-13T12:00:00Z');
$antesDelPlazo = $clanService->evaluatePatriarchSuccession('cln_silencio', $cuarentaYCuatroDías);
assertCondition(
    $antesDelPlazo->isDue() === false && $antesDelPlazo->inactivityDays === 44,
    'A los cuarenta y cuatro días el velatorio no se abre'
);
assertCondition(
    patriarchOf($pdo, 'cln_silencio') === 'usr_pat_silencio'
        && activeRole($pdo, 'usr_pat_silencio', 'cln_silencio') === 'patriarch',
    'El Patriarca conserva la corona y su rango intactos'
);

$cuarentaYCincoDías = instantOf('2026-09-14T12:00:00Z');
$velatorio = $clanService->evaluatePatriarchSuccession('cln_silencio', $cuarentaYCincoDías);
assertCondition(
    $velatorio->wasTransferred() && $velatorio->inactivityDays === 45,
    'A los cuarenta y cinco días exactos se transfiere la corona (RF-01.9)'
);
assertCondition(
    $velatorio->previousPatriarchId === 'usr_pat_silencio' && $velatorio->newPatriarchId === 'usr_veterano',
    'La corona recae en el adepto activo de mayor antigüedad'
);
assertCondition(
    patriarchOf($pdo, 'cln_silencio') === 'usr_veterano',
    'La casa declara su nueva cabeza en el plano'
);
assertCondition(
    activeRole($pdo, 'usr_veterano', 'cln_silencio') === 'patriarch'
        && activeRole($pdo, 'usr_pat_silencio', 'cln_silencio') === 'adept',
    'El heredero ciñe la corona y el saliente desciende a Adepto del Linaje'
);
assertCondition(
    activeMemberCount($pdo, 'cln_silencio') === 3,
    'Nadie abandona la casa: el censo permanece intacto tras el velatorio'
);
assertCondition(
    $clanService->evaluatePatriarchSuccession('cln_silencio', $cuarentaYCincoDías)->isDue() === false,
    'Un segundo velatorio en el mismo instante no vuelve a mover el trono (idempotencia)'
);

// Desempate por aportación entre coetáneos: la antigüedad manda y el mérito dirime.
seedUser($pdo, 'usr_pat_coetaneo', 'PatriarcaDeLosCoetáneos', 'editor');
seedUser($pdo, 'usr_coetaneo_uno', 'CoetáneoUno', 'editor');
seedUser($pdo, 'usr_coetaneo_dos', 'CoetáneoDos', 'editor');
insertClan($pdo, 'cln_coetaneos', 'Casa de los Coetáneos', 'dawnWinds', 0, 0, ClanDto::STATUS_ACTIVE, 'usr_pat_coetaneo', ClanDto::ADMISSION_OPEN, '2026-07-31T12:00:00Z', '2026-07-31T12:00:00Z');
enrollMember($pdo, 'clm_coetaneo_pat', 'cln_coetaneos', 'usr_pat_coetaneo', 'patriarch', '2026-01-01T00:00:00Z');
enrollMember($pdo, 'clm_coetaneo_uno', 'cln_coetaneos', 'usr_coetaneo_uno', 'adept', '2026-03-01T00:00:00Z');
enrollMember($pdo, 'clm_coetaneo_dos', 'cln_coetaneos', 'usr_coetaneo_dos', 'adept', '2026-03-01T00:00:00Z');
insertPractice($pdo, 'dst_coetaneo_dos', 'usr_coetaneo_dos', 'cln_coetaneos', '2026-08-01', 40);

$coetaneo = $clanService->evaluatePatriarchSuccession('cln_coetaneos', $cuarentaYCincoDías);
assertCondition(
    $coetaneo->newPatriarchId === 'usr_coetaneo_dos',
    'Entre coetáneos de idéntica antigüedad dirime la gloria aportada a la casa'
);

closeBlock();

// =====================================================================
// BLOQUE 10 · Inviolabilidad del Nombre Ancestral (RF-01.2, RF-05.4)
// =====================================================================
beginBlock('10 · Inviolabilidad del Nombre Ancestral');

$pdo = forgeRealm($projectRoot);
[$clanService] = forgeServices($pdo);

$fundadorAncestral = seedUser($pdo, 'usr_ancestral', 'FundadorDeLaHerencia', 'editor', 'abyssalShadows');
$casaAncestral = $clanService->foundClan(
    $fundadorAncestral,
    'Herencia de los Ancestros',
    'El nombre perdura más allá del estandarte',
    'rune_ancestral',
    'abyssalShadows',
    ClanDto::ADMISSION_OPEN,
    $now,
);

$pdo->prepare("UPDATE clans SET status = 'archived' WHERE id = :clanId")
    ->execute([':clanId' => $casaAncestral->id]);
assertCondition(
    $clanService->findClanById($casaAncestral->id)?->status === ClanDto::STATUS_ARCHIVED,
    'La casa yace disuelta como Herencia Ancestral (status archived)'
);

$usurpador = seedUser($pdo, 'usr_usurpador', 'ElUsurpadorDeNombres', 'editor', 'abyssalShadows');
expectRejection(
    fn () => $clanService->foundClan(
        $usurpador,
        'Herencia de los Ancestros',
        'Otro lema para el mismo nombre',
        'rune_usurpadora',
        'abyssalShadows',
        ClanDto::ADMISSION_OPEN,
        $now,
    ),
    ClanGovernanceException::NAME_ALREADY_RESERVED,
    409,
    'Fundar con el nombre de una casa archivada se rechaza (409 NAME_ALREADY_RESERVED)'
);
expectRejection(
    fn () => $clanService->foundClan(
        $usurpador,
        '   Herencia de los Ancestros   ',
        'El mismo nombre con espacios sobrantes',
        'rune_usurpadora',
        'abyssalShadows',
        ClanDto::ADMISSION_OPEN,
        $now,
    ),
    ClanGovernanceException::NAME_ALREADY_RESERVED,
    409,
    'Los espacios sobrantes no liberan el nombre ancestral: el canon recorta antes de comparar'
);
assertCondition(
    clanCount($pdo, 'Herencia de los Ancestros') === 1,
    'No nace una segunda casa bajo el nombre ancestral'
);

$casaNueva = $clanService->foundClan(
    $usurpador,
    'Casa del Nuevo Albor',
    'Un nombre libre, un estandarte nuevo',
    'rune_albor',
    'abyssalShadows',
    ClanDto::ADMISSION_OPEN,
    $now,
);
assertCondition(
    $casaNueva->name === 'Casa del Nuevo Albor' && $casaNueva->patriarchId === 'usr_usurpador',
    'Un Nombre Canónico libre funda sin obstáculo alguno'
);
assertCondition(
    $casaNueva->status === ClanDto::STATUS_ACTIVE && $casaNueva->memberCount === 1,
    'La casa nueva nace en contienda con su fundador al frente'
);

closeBlock();

// =====================================================================
// Reporte final
// =====================================================================
assertCondition(count($blocks) === 10, 'La suite ejercita los diez bloques del plan 6.1');

echo "\n╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  REPORTE EXHAUSTIVO · SUITE DE HERMANDADES Y DOMINIO (TAREA 4.1)     ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";

foreach ($blocks as $index => $block) {
    printf(
        "  %-58s %3d asertos · %s\n",
        $block['name'],
        $block['passed'] + $block['failed'],
        $block['failed'] === 0 ? 'PASA' : 'FALLA'
    );
}

printf(
    "\n  Bloques: %d de %d en verde\n  Asertos: %d superados · %d fallidos de %d\n",
    count(array_filter($blocks, static fn (array $block): bool => $block['failed'] === 0)),
    count($blocks),
    $assertsPassed,
    $assertsFailed,
    $assertsPassed + $assertsFailed,
);

if ($assertsFailed === 0) {
    echo "\n[VERDE] Los diez bloques del plan 6.1 se cumplen: SPEC-07 queda verificada por CLI.\n";
    exit(0);
}

echo "\n[ROJO] La suite no alcanza el 100%: revisa los asertos fallidos antes de continuar.\n";
exit(1);

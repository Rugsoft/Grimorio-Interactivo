<?php

declare(strict_types=1);

/**
 * test_vestibule_service.php — Verificación de la Tarea 3.2 (sobre único) y
 * de la Tarea 7.1 (arnés de la auditoría de estabilización) de TASKS-10.
 *
 * Valida `ClanVestibuleService::vestibuleStateFor()` — el sobre único del
 * Endpoint 1 (plan §2.2) — contra el «Hecho cuando» de la tarea:
 *
 *   1. Un solo método sirve el sobre completo (aptitud, catálogo, myHouse
 *      y peticiones en una carga, RNF-04).
 *   2. Un linajado sin casas recibe el estado vacío (catálogo [] sin error).
 *   3. Un legado divergente recibe su `myHouse` con `isLegacyDivergent: true`.
 *
 * Y los comportamientos del plan §3.1/§2.2:
 *   - Catálogo derivado de sesión: solo linaje jurado, solo `active`, sin
 *     parámetro de filtro (RF-01.2).
 *   - Aptitud conjuntiva por instante (RF-01.7): lealtad, convalecencia
 *     con alza al entero superior, vacante por casa.
 *   - `unreadVerdictsCount` para el rótulo (RF-01.1/RF-03.4).
 *   - Inventario consolidado con motivación y motivo del dictamen (RF-03.8).
 *   - Peregrino → jamás servido (retención previa, RF-04.3).
 *   - Supremo sin linaje → ADMIN_LINEAGE_REQUIRED (hallazgos 13/19).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo sobre SQLite en memoria.
 *   - Artículo V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_vestibule_service.php
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
    $projectRoot . '/src/Dto/ClanPetitionDto.php',
    $projectRoot . '/src/Dto/VestibuleClanDto.php',
    $projectRoot . '/src/Dto/VestibuleStateDto.php',
    $projectRoot . '/src/Dto/LineageDto.php',
    $projectRoot . '/src/Exceptions/ClanGovernanceException.php',
    $projectRoot . '/src/Exceptions/LineageOathException.php',
    $projectRoot . '/src/Repositories/ClanRepository.php',
    $projectRoot . '/src/Repositories/ClanMemberRepository.php',
    $projectRoot . '/src/Repositories/ClanApplicationRepository.php',
    $projectRoot . '/src/Repositories/WeeklyCycleRepository.php',
    $projectRoot . '/src/Repositories/LineageOathRepository.php',
    $projectRoot . '/src/Services/LineageSynergyService.php',
    $projectRoot . '/src/Services/AuditService.php',
    $projectRoot . '/src/Services/ClanAdmissionResult.php',
    $projectRoot . '/src/Services/PatriarchSuccessionResult.php',
    $projectRoot . '/src/Services/ClanService.php',
    $projectRoot . '/src/Services/ClanVestibuleService.php',
];
foreach ($filesRequired as $filePath) {
    if (!is_file($filePath)) {
        fwrite(STDERR, '[FATAL] Falta el fichero ' . $filePath . " — fase roja.\n");
        exit(1);
    }
    require_once $filePath;
}

use Grimorio\Dto\VestibuleClanDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Models\User;
use Grimorio\Services\ClanVestibuleService;

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
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', ?string $lineage = 'celestialTides'): User
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

/** Funda una casa directamente en la base (el gobierno ya lo cubren otros arneses). */
function seedClan(PDO $pdo, string $clanId, string $name, string $lineageType, string $admissionMode = 'open', int $weeklyPoints = 0): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, coat_of_arms, lineage_type, admission_mode, status,
                            weekly_points, historical_points, created_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :coatOfArms, :lineageType, :admissionMode, \'active\',
                 :weeklyPoints, 0, :now, :now)'
    );
    $statement->execute([
        ':id'            => $clanId,
        ':slug'          => 'slug-' . $clanId,
        ':name'          => $name,
        ':motto'         => 'El juramento de la casa ' . $name,
        ':coatOfArms'    => 'rune_shield_' . $clanId,
        ':lineageType'   => $lineageType,
        ':admissionMode' => $admissionMode,
        ':weeklyPoints'  => $weeklyPoints,
        ':now'           => '2026-01-01T00:00:00Z',
    ]);
}

/** Inscribe una membresía activa (opcionalmente en convalecencia). */
function seedMembership(PDO $pdo, string $clanId, string $userId, ?string $convalescenceExpiresAt = null, string $joinedAt = '2026-01-02T00:00:00Z'): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, \'adept\', :joinedAt, NULL, :convalescenceExpiresAt)'
    );
    $statement->execute([
        ':id'                      => 'clm_' . bin2hex(random_bytes(6)),
        ':clanId'                  => $clanId,
        ':userId'                  => $userId,
        ':joinedAt'                => $joinedAt,
        ':convalescenceExpiresAt'  => $convalescenceExpiresAt,
    ]);
}

/** Remite una petición formal directamente a la base. */
function seedApplication(PDO $pdo, string $appId, string $clanId, string $userId, string $status, string $motivation, ?string $verdictMotive = null, ?string $verdictSeenAt = null): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clan_applications (id, clan_id, user_id, status, motivation, verdict_motive,
                                        created_at, resolved_at, verdict_seen_at)
         VALUES (:id, :clanId, :userId, :status, :motivation, :verdictMotive, :createdAt, :resolvedAt, :seenAt)'
    );
    $isPending = $status === 'pending';
    $statement->execute([
        ':id'            => $appId,
        ':clanId'        => $clanId,
        ':userId'        => $userId,
        ':status'        => $status,
        ':motivation'    => $motivation,
        ':verdictMotive' => $verdictMotive,
        ':createdAt'     => '2026-09-01T10:00:00Z',
        ':resolvedAt'    => $isPending ? null : '2026-09-02T10:00:00Z',
        ':seenAt'        => $verdictSeenAt,
    ]);
}

/** Proclama una coronación vigente (la más reciente gobierna). */
function seedRegentCycle(PDO $pdo, string $clanId, int $year = 2026, int $week = 37): void
{
    $statement = $pdo->prepare(
        'INSERT INTO weekly_cycles (id, week_number, cycle_year, regent_clan_id, winning_points, winner_spell_count, closed_at)
         VALUES (:id, :week, :year, :clanId, 100, 3, :closedAt)'
    );
    $statement->execute([
        ':id'       => 'cyc_' . $year . '_' . $week,
        ':week'     => $week,
        ':year'     => $year,
        ':clanId'   => $clanId,
        ':closedAt' => '2026-09-13T23:59:59Z',
    ]);
}

/** Busca en el catálogo del sobre la casa cuyo id se indica. */
function clanOf(?array $state, string $clanId): ?array
{
    if ($state === null) {
        return null;
    }
    foreach ($state['clans'] as $clan) {
        if ($clan['clanId'] === $clanId) {
            return $clan;
        }
    }

    return null;
}

function forgeRealm(string $projectRoot): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

    return $pdo;
}

echo "== VERIFICACION TAREA 3.2: ClanVestibuleService — el sobre unico ==\n\n";

// --- FASE 0: Un solo método sirve el sobre completo (RNF-04) -------------
echo "FASE 0: Un solo método sirve el sobre completo (RNF-04)\n";
$pdo = forgeRealm($projectRoot);
$vestibule = new ClanVestibuleService($pdo);
$now = instantOf('2026-09-20T12:00:00Z');

// Casa abierta de la casa y casa de deliberación del linaje celestial.
seedClan($pdo, 'cln_mareas', 'Mareas de Aether', 'celestialTides', 'open', 40);
seedClan($pdo, 'cln_tempestad', 'Tempestad Eterna', 'celestialTides', 'byApplication', 10);
// Estandartes de OTRA sangre: jamás deben aparecer (RF-01.2).
seedClan($pdo, 'cln_llama', 'Llama Primordial', 'primordialFlame', 'open');
// Casa archivada del propio linaje: tampoco se exhibe (solo `active`).
seedClan($pdo, 'cln_escarcha', 'Custodios de la Escarcha', 'celestialTides', 'open');
$pdo->exec("UPDATE clans SET status = 'archived' WHERE id = 'cln_escarcha'");

$adepto = seedUser($pdo, 'usr_adepto', 'ElPeregrinoDeMareas', 'editor', 'celestialTides');

$state = $vestibule->vestibuleStateFor($adepto, $now);
$serialized = json_decode(json_encode($state), true);

assertCondition(array_keys($serialized) === ['adeptState', 'myHouse', 'clans', 'petitions'], 'El sobre porta sus cuatro llaves raíz en una sola carga');
assertCondition($serialized['adeptState']['lineage'] === 'celestialTides', 'El linaje se deriva de la sesión (RF-01.2)');
assertCondition($serialized['adeptState']['lineageLabel'] === 'Linaje de las Mareas Celestiales', 'El rótulo del linaje viaja en castellano');
assertCondition(array_map(static fn (array $c): string => $c['clanId'], $serialized['clans']) === ['cln_mareas', 'cln_tempestad'], 'Catálogo: solo linaje jurado y solo active, sin parámetro de filtro');
assertCondition($serialized['adeptState']['aptitude']['isApt'] === true, 'Apto: linajado sin membresía y sin convalecencia');
assertCondition($serialized['adeptState']['aptitude']['vedado'] === null, 'Apto sin causa de vedado');
assertCondition($serialized['adeptState']['aptitude']['pendingPetitionsLimit'] === 3, 'El tope de pendientes viaja servido (3)');
assertCondition($serialized['myHouse'] === null, 'myHouse nulo para el linajado común');
assertCondition(json_encode($state) === json_encode($vestibule->vestibuleStateFor($adepto, $now)), 'El sobre es determinista ante el mismo instante');

// --- FASE 1: Linajado sin casas → estado vacío ----------------------------
echo "\nFASE 1: Un linajado sin casas recibe el estado vacío\n";
$pdoVacio = forgeRealm($projectRoot);
$vestibuleVacio = new ClanVestibuleService($pdoVacio);
$solitario = seedUser($pdoVacio, 'usr_solitario', 'ElSolitario', 'editor', 'eternalTempest');

$stateVacio = $vestibuleVacio->vestibuleStateFor($solitario, $now);
$serializedVacio = json_decode(json_encode($stateVacio), true);

assertCondition($serializedVacio['clans'] === [], 'El catálogo nace vacío sin error');
assertCondition($serializedVacio['petitions'] === [], 'El inventario nace vacío sin error');
assertCondition($serializedVacio['myHouse'] === null, 'Sin casa legada que proyectar');
assertCondition($serializedVacio['adeptState']['aptitude']['isApt'] === true, 'La aptitud no depende de que existan casas');
assertCondition($serializedVacio['adeptState']['unreadVerdictsCount'] === 0, 'Sin veredictos sin contemplar');

// --- FASE 2: Legado divergente → myHouse con isLegacyDivergent: true ------
echo "\nFASE 2: Un legado divergente recibe su myHouse\n";
$pdoLegado = forgeRealm($projectRoot);
$vestibuleLegado = new ClanVestibuleService($pdoLegado);

seedClan($pdoLegado, 'cln_llama_legada', 'Hogar Legado de la Llama', 'primordialFlame', 'open');
seedClan($pdoLegado, 'cln_marea_viva', 'Casa Viva de Mareas', 'celestialTides', 'open');
$legado = seedUser($pdoLegado, 'usr_legado', 'LaHerederaDivergente', 'editor', 'celestialTides');
seedMembership($pdoLegado, 'cln_llama_legada', 'usr_legado'); // casa de OTRO linaje

$stateLegado = $vestibuleLegado->vestibuleStateFor($legado, $now);
$serializedLegado = json_decode(json_encode($stateLegado), true);

assertCondition($serializedLegado['myHouse'] !== null, 'La casa legada divergente viaja en myHouse (RF-01.2)');
assertCondition($serializedLegado['myHouse']['clanId'] === 'cln_llama_legada', 'myHouse nombra la casa donde milita');
assertCondition($serializedLegado['myHouse']['isLegacyDivergent'] === true, 'isLegacyDivergent sella la excepción del filtro');
assertCondition($serializedLegado['myHouse']['state'] === 'active', 'El estado heráldico por metal y forma viaja (active)');
assertCondition($serializedLegado['adeptState']['aptitude']['isApt'] === false, 'El militante jamás es apto (RF-01.7)');
assertCondition($serializedLegado['adeptState']['aptitude']['vedado'] === 'loyalty', 'La lealtad empeñada es la causa del vedado');
assertCondition($serializedLegado['adeptState']['membership']['clanId'] === 'cln_llama_legada', 'La membresía vigente viaja en adeptState');
assertCondition($serializedLegado['clans'] !== [], 'El catálogo propio sigue contemplándose íntegro');
assertCondition(clanOf($serializedLegado, 'cln_marea_viva')['adeptRelation'] === 'none', 'La casa del linaje jurado permanece en el catálogo con relación propia');
assertCondition(clanOf($serializedLegado, 'cln_marea_viva')['gesture'] === null, 'La lealtad empeñada veda también el gesto en el catálogo (RF-03.5)');

// Y el estado heráldico «bronce roto» cuando la casa está archivada:
$pdoLegado->exec("UPDATE clans SET status = 'archived' WHERE id = 'cln_llama_legada'");
$stateLegadoArchivada = json_decode(json_encode($vestibuleLegado->vestibuleStateFor($legado, $now)), true);
assertCondition($stateLegadoArchivada['myHouse']['state'] === 'archived', 'La casa legada archivada viaja como bronce roto (state: archived)');

// Casa propia del MISMO linaje: no es excepción, no viaja en myHouse.
$pdoPropia = forgeRealm($projectRoot);
$vestibulePropia = new ClanVestibuleService($pdoPropia);
seedClan($pdoPropia, 'cln_marea_propia', 'Casa Propia de Mareas', 'celestialTides', 'open');
$habitante = seedUser($pdoPropia, 'usr_habitante', 'ElHabitante', 'editor', 'celestialTides');
seedMembership($pdoPropia, 'cln_marea_propia', 'usr_habitante');
$statePropia = json_decode(json_encode($vestibulePropia->vestibuleStateFor($habitante, $now)), true);
assertCondition($statePropia['myHouse'] === null, 'La casa propia del linaje jurado NO viaja en myHouse (ya vive en el catálogo)');
assertCondition($statePropia['clans'][0]['adeptRelation'] === 'ownHouse', 'La casa propia se declara como tal en el catálogo (RF-03.5)');

// --- FASE 3: Aptitud conjuntiva por instante (RF-01.7) --------------------
echo "\nFASE 3: Aptitud conjuntiva derivada del instante\n";
$pdoAptitud = forgeRealm($projectRoot);
$vestibuleAptitud = new ClanVestibuleService($pdoAptitud);
seedClan($pdoAptitud, 'cln_a', 'Casa Abierta', 'celestialTides', 'open');
seedClan($pdoAptitud, 'cln_d', 'Casa de Deliberación', 'celestialTides', 'byApplication');

// Convaleciente: días con alza al entero superior. El descanso se purga
// FUERA de la casa: partió (left_at cerrado) y su convalecencia sigue viva.
$convaleciente = seedUser($pdoAptitud, 'usr_convaleciente', 'ElDescansante', 'editor', 'celestialTides');
seedMembership($pdoAptitud, 'cln_a', 'usr_convaleciente', '2026-09-23T18:00:00Z', '2026-08-20T00:00:00Z'); // 3 días y 6 horas
$pdoAptitud->exec("UPDATE clan_members SET left_at = '2026-09-19T00:00:00Z' WHERE user_id = 'usr_convaleciente'");
$estadoConvaleciente = json_decode(json_encode($vestibuleAptitud->vestibuleStateFor($convaleciente, $now)), true);
assertCondition($estadoConvaleciente['adeptState']['aptitude']['vedado'] === 'convalescence', 'La convalecencia veda por su leyenda');
assertCondition($estadoConvaleciente['adeptState']['aptitude']['convalescenceDaysRemaining'] === 4, '3 días y 6 horas se alzan a 4 días (plan §3.1)');
assertCondition($estadoConvaleciente['adeptState']['aptitude']['isApt'] === false, 'El convaleciente jamás es apto');

$renaciente = seedUser($pdoAptitud, 'usr_renaciente', 'ElRenaciente', 'editor', 'celestialTides');
// El descanso sobrevive a la partida: la fila histórica (left_at cerrado)
// porta la marca expirada un segundo antes del instante del juicio.
seedMembership($pdoAptitud, 'cln_a', 'usr_renaciente', '2026-09-20T11:59:59Z', '2026-08-20T00:00:00Z');
$pdoAptitud->exec("UPDATE clan_members SET left_at = '2026-08-25T00:00:00Z' WHERE user_id = 'usr_renaciente'");
$estadoRenaciente = json_decode(json_encode($vestibuleAptitud->vestibuleStateFor($renaciente, $now)), true);
assertCondition($estadoRenaciente['adeptState']['aptitude']['isApt'] === true, 'La convalecencia vencida es estado derivado: el instante gobierna (RF-03.5)');

// Plenitud: vacante por casa dentro de la aptitud global.
$pdoLlena = forgeRealm($projectRoot);
$vestibuleLlena = new ClanVestibuleService($pdoLlena);
seedClan($pdoLlena, 'cln_llena', 'Casa en Plenitud', 'celestialTides', 'open');
for ($i = 0; $i < 30; $i++) {
    seedUser($pdoLlena, 'usr_ocupante_' . $i, 'Ocupante' . $i, 'editor', 'celestialTides');
    seedMembership($pdoLlena, 'cln_llena', 'usr_ocupante_' . $i);
}
$aspirante = seedUser($pdoLlena, 'usr_aspirante', 'ElAspirante', 'editor', 'celestialTides');
$estadoLlena = json_decode(json_encode($vestibuleLlena->vestibuleStateFor($aspirante, $now)), true);
$casaLlena = clanOf($estadoLlena, 'cln_llena');
assertCondition($casaLlena !== null && $casaLlena['gesture'] === null, 'Casa en plenitud: sin gesto disponible');
assertCondition($casaLlena !== null && str_contains((string) $casaLlena['vedadoLegend'], 'plenitud de 30'), 'La leyenda de plenitud es la de SPEC-07');

// --- FASE 4: Estados de tarjeta (RF-01.7, RF-03.5) ------------------------
echo "\nFASE 4: Estados de tarjeta derivados (pendiente, retirada, gestos)\n";
$pdoTarjetas = forgeRealm($projectRoot);
$vestibuleTarjetas = new ClanVestibuleService($pdoTarjetas);
seedClan($pdoTarjetas, 'cln_t1', 'Casa del Trueno', 'celestialTides', 'byApplication');
seedClan($pdoTarjetas, 'cln_t2', 'Casa del Arco Iris', 'celestialTides', 'open');

$postulante = seedUser($pdoTarjetas, 'usr_postulante', 'LaPostulante', 'editor', 'celestialTides');
seedApplication($pdoTarjetas, 'app_pen', 'cln_t1', 'usr_postulante', 'pending', 'Ruego un lugar para servir a la casa.');
seedApplication($pdoTarjetas, 'app_rech', 'cln_t2', 'usr_postulante', 'rejected', 'Otro ruego antiguo.', 'La casa guarda luto.', null);

$estadoPostulante = json_decode(json_encode($vestibuleTarjetas->vestibuleStateFor($postulante, $now)), true);

$tarjetaPendiente = clanOf($estadoPostulante, 'cln_t1');
assertCondition($tarjetaPendiente !== null && $tarjetaPendiente['adeptRelation'] === 'pending', 'La casa con petición viva se declara «Pendiente de dictamen»');
assertCondition($tarjetaPendiente !== null && $tarjetaPendiente['gesture'] === 'withdraw', 'Su único gesto es la retirada');
assertCondition($tarjetaPendiente !== null && $tarjetaPendiente['petitionId'] === 'app_pen', 'La retirada porta el identificador de la petición');
assertCondition($tarjetaPendiente !== null && $tarjetaPendiente['admissionModeLabel'] === 'Requiere petición formal', 'El rótulo del régimen viaja servido en castellano');

$tarjetaRechazada = clanOf($estadoPostulante, 'cln_t2');
assertCondition($tarjetaRechazada !== null && $tarjetaRechazada['gesture'] === null, 'La casa clausurada (rechazo previo) no ofrece gesto');
assertCondition($tarjetaRechazada !== null && str_contains((string) $tarjetaRechazada['vedadoLegend'], 'clausurada'), 'La leyenda de clausura veda la re-postulación (RF-03.1)');

$tarjetaOtraCasa = clanOf($estadoPostulante, 'cln_t1') ?? [];
assertCondition($estadoPostulante['adeptState']['aptitude']['pendingPetitionsCount'] === 1, 'El cupo de pendientes cuenta solo las vivas');
assertCondition($estadoPostulante['adeptState']['unreadVerdictsCount'] === 1, 'El rechazo sin contemplar alimenta el rótulo (RF-01.1)');

$peticionRechazada = null;
foreach ($estadoPostulante['petitions'] as $petition) {
    if ($petition['applicationId'] === 'app_rech') {
        $peticionRechazada = $petition;
    }
}
assertCondition($peticionRechazada !== null && $peticionRechazada['verdictSeen'] === false, 'El veredicto sin leer viaja como verdictSeen: false');
assertCondition($peticionRechazada !== null && $peticionRechazada['verdictMotive'] === 'La casa guarda luto.', 'El motivo del dictamen viaja íntegro (Art. III.3)');
assertCondition($peticionRechazada !== null && $peticionRechazada['motivation'] === 'Otro ruego antiguo.', 'La motivación de la petición viaja íntegra (RF-03.8)');

// Gestos canónicos del apto:
$pdoGestos = forgeRealm($projectRoot);
$vestibuleGestos = new ClanVestibuleService($pdoGestos);
seedClan($pdoGestos, 'cln_g1', 'Casa Abierta G', 'celestialTides', 'open');
seedClan($pdoGestos, 'cln_g2', 'Casa de Petición G', 'celestialTides', 'byApplication');
$apto = seedUser($pdoGestos, 'usr_apto', 'ElApto', 'editor', 'celestialTides');
$estadoApto = json_decode(json_encode($vestibuleGestos->vestibuleStateFor($apto, $now)), true);
assertCondition(clanOf($estadoApto, 'cln_g1')['gesture'] === 'join', 'Casa abierta: gesto «join»');
assertCondition(clanOf($estadoApto, 'cln_g2')['gesture'] === 'petition', 'Casa de deliberación: gesto «petition»');
assertCondition(clanOf($estadoApto, 'cln_g1')['vedadoLegend'] === null, 'Con gesto, la leyenda calla');

// --- FASE 5: La corona del Clan Regente (RF-01.3) -------------------------
echo "\nFASE 5: La corona del Clan Regente\n";
$pdoCorona = forgeRealm($projectRoot);
$vestibuleCorona = new ClanVestibuleService($pdoCorona);
seedClan($pdoCorona, 'cln_rey', 'Casa Coronada', 'celestialTides', 'open');
seedClan($pdoCorona, 'cln_cortesano', 'Casa Cortesana', 'celestialTides', 'open');
seedRegentCycle($pdoCorona, 'cln_rey');
$cortesano = seedUser($pdoCorona, 'usr_cortesano', 'ElCortesano', 'editor', 'celestialTides');
$estadoCorona = json_decode(json_encode($vestibuleCorona->vestibuleStateFor($cortesano, $now)), true);
assertCondition(clanOf($estadoCorona, 'cln_rey')['isRegent'] === true, 'La casa proclamada ciñe la corona en su tarjeta');
assertCondition(clanOf($estadoCorona, 'cln_cortesano')['isRegent'] === false, 'Las demás casas permanecen sin corona');

// --- FASE 6: Guardias de la puerta (RF-04.3, hallazgos 13/19) -------------
echo "\nFASE 6: Guardias de la puerta — peregrino y Supremo sin linaje\n";
$pdoGuardias = forgeRealm($projectRoot);
$vestibuleGuardias = new ClanVestibuleService($pdoGuardias);

$peregrino = seedUser($pdoGuardias, 'usr_peregrino', 'ElPeregrinoSinSello', 'editor', null);
$alzado = false;
try {
    $vestibuleGuardias->vestibuleStateFor($peregrino, $now);
} catch (LineageOathException $oath) {
    $alzado = $oath->errorCode === 'LINEAGE_OATH_REQUIRED' && $oath->httpStatus === 403;
}
assertCondition($alzado, 'El peregrino jamás es servido: LINEAGE_OATH_REQUIRED/403 (RF-04.3)');

$supremo = seedUser($pdoGuardias, 'usr_supremo', 'ElSupremoSinSello', 'supremeAdmin', null);
$alzado = false;
try {
    $vestibuleGuardias->vestibuleStateFor($supremo, $now);
} catch (ClanGovernanceException $governance) {
    $alzado = $governance->errorCode === 'ADMIN_LINEAGE_REQUIRED' && $governance->httpStatus === 403;
}
assertCondition($alzado, 'El Supremo sin linaje recibe su aviso solemne propio (hallazgos 13/19)');

// --- FASE 6b: La convalecencia con alza, día a día (RF-03.5, plan §3.1) -----
echo "\nFASE 6b: La convalecencia con alza, día a día\n";
$pdoConv = forgeRealm($projectRoot);
$vestibuleConv = new ClanVestibuleService($pdoConv);
seedClan($pdoConv, 'cln_conv', 'Casa del Descanso', 'celestialTides', 'open');
$convaleciente = seedUser($pdoConv, 'usr_conv', 'ElConvaleciente', 'editor', 'celestialTides');

// Salida el 2026-09-19T10:00Z + 14 días = vence 2026-10-03T10:00:00Z.
// La convalecencia vive en una fila CERRADA (left_at fijado): es la huella
// de una partida, no una membresía vigente (idioma de ClanService::leaveClan).
// El instante de lectura es $now = 2026-09-20T12:00:00Z:
//   restan 12 DÍAS y 22 HORAS → el día parcial SE ALZA a 13 días (techo).
seedMembership($pdoConv, 'cln_conv', 'usr_conv', '2026-10-03T10:00:00Z', '2026-09-19T10:00:00Z');
// La partida: la fila se cierra con la misma estampa del ingreso original.
$pdoConv->prepare('UPDATE clan_members SET left_at = :leftAt WHERE user_id = :userId')
    ->execute([':leftAt' => '2026-09-19T10:00:00Z', ':userId' => 'usr_conv']);
$estadoConv = json_decode(json_encode($vestibuleConv->vestibuleStateFor($convaleciente, $now)), true);
$diasRestantes = (int) $estadoConv['adeptState']['aptitude']['convalescenceDaysRemaining'];
assertCondition($diasRestantes === 13, "El día parcial se alza al entero superior (12d 22h → {$diasRestantes} días)");
assertCondition($estadoConv['adeptState']['aptitude']['isApt'] === false, 'El convaleciente jamás es apto (RF-03.5)');
assertCondition($estadoConv['adeptState']['aptitude']['vedado'] === 'convalescence', 'El vedado nombra la convalecencia como causa');

// La leyenda de la tarjeta nombra los días alzados, no el resto decimal.
$tarjetaConv = clanOf($estadoConv, 'cln_conv');
assertCondition(
    is_string($tarjetaConv['vedadoLegend']) && str_contains((string) $tarjetaConv['vedadoLegend'], '13'),
    'La leyenda de descanso nombra los días con alza (13)',
);

// Frontera exacta: el INSTANTE de vencimiento apaga el vedado sin tocar datos.
$instanteVencido = instantOf('2026-10-03T10:00:00Z');
$estadoLibre = json_decode(json_encode($vestibuleConv->vestibuleStateFor($convaleciente, $instanteVencido)), true);
assertCondition($estadoLibre['adeptState']['aptitude']['isApt'] === true, 'Al vencer el plazo el instante restaura la aptitud (estado derivado, RF-03.5)');
assertCondition($estadoLibre['adeptState']['aptitude']['convalescenceDaysRemaining'] === 0, 'Los días restantes caen a 0 al vencer');
assertCondition(clanOf($estadoLibre, 'cln_conv')['gesture'] === 'join', 'La casa abierta vuelve a ofrecer el gesto de ingreso');
assertCondition(clanOf($estadoLibre, 'cln_conv')['vedadoLegend'] === null, 'La leyenda de descanso calla al volver el gesto');

// --- FASE 7: Determinismo del instante (RNF-01) ----------------------------
echo "\nFASE 7: El instante gobierna, jamás el reloj\n";
$pdoGestos2 = forgeRealm($projectRoot);
$vestibuleGestos2 = new ClanVestibuleService($pdoGestos2);
seedClan($pdoGestos2, 'cln_x', 'Casa X', 'celestialTides', 'open');
$aptoX = seedUser($pdoGestos2, 'usr_apto_x', 'ElAptoX', 'editor', 'celestialTides');
$ayer = $vestibuleGestos2->vestibuleStateFor($aptoX, instantOf('2026-09-19T12:00:00Z'));
$hoy = $vestibuleGestos2->vestibuleStateFor($aptoX, $now);
assertCondition(json_encode($ayer) === json_encode($hoy), 'Mismo estado del plano: el sobre no depende del reloj del servidor');

echo "\nVeredicto: {$assertsPassed} PASA / {$assertsFailed} ROJOS\n";
exit($assertsFailed === 0 ? 0 : 1);

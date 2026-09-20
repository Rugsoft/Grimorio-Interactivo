<?php

/**
 * test_clan_service.php — Verificación del gobierno canónico de hermandades.
 *
 * Tarea 2.4 (TASKS-07). Comprueba el criterio «Hecho cuando»:
 *   «Se bloquea el ingreso al miembro número 31, se bloquea a postulantes en
 *    convalecencia de 14 días y se transfiere la corona al adepto más antiguo
 *    si el Patriarca supera los 45 días de inactividad.»
 *
 * Fases:
 *   [0] Fundación: rango, nombre canónico, linaje y corona del fundador.
 *   [1] Cupo estricto de treinta adeptos: el miembro 31 queda bloqueado.
 *   [2] Convalecencia de catorce días: bloquea ingreso y fundación.
 *   [3] Sucesión dinástica a los 45 días de silencio.
 *   [4] Bitácora pública: cada acto de gobierno queda inscrito (RNF-04).
 *   [5] Régimen bajo petición: postulación, tope de tres y deliberación.
 *   [6] Renuncia, expulsión, disolución y traspaso de la corona.
 *   [7] Determinismo: el servicio jamás lee el reloj por su cuenta (RNF-01).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo; cero librerías.
 *   - Artículo V: identificadores en inglés camelCase, narrativa en castellano.
 *
 * Uso: php scratch/test_clan_service.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

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
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\ClanMemberDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\ClanAdmissionResult;
use Grimorio\Services\ClanService;

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

/**
 * Exige que una operación sea rechazada con el código y estado canónicos.
 */
function expectRejection(callable $operation, string $expectedCode, int $expectedStatus, string $description): void
{
    global $assertsPassed, $assertsFailed;

    try {
        $operation();
    } catch (ClanGovernanceException $rejection) {
        if ($rejection->errorCode === $expectedCode && $rejection->httpStatus === $expectedStatus) {
            $assertsPassed++;
            echo "  [PASA] {$description}\n";

            return;
        }

        $assertsFailed++;
        echo "  [FALLA] {$description} — se esperaba {$expectedCode}/{$expectedStatus} y llegó "
            . $rejection->errorCode . '/' . $rejection->httpStatus . "\n";

        return;
    }

    $assertsFailed++;
    echo "  [FALLA] {$description} — ninguna excepción alzó\n";
}

/** Instante UTC a partir de una estampa ISO 8601. */
function instantOf(string $isoUtc): DateTimeImmutable
{
    return new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
}

/**
 * Consagra un mago en el plano con el rol indicado. Desde SPEC-10 (Tarea 2.2)
 * los adeptos nacen con el linaje jurado por defecto `primordialFlame`: los
 * guardias del Vestíbulo (RF-04.1) exigen identidad arcana en los gestos.
 */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', string $lineage = 'primordialFlame'): User
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
        ':lineage'      => $role === 'reader' ? null : $lineage,
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
        lineage: $role === 'reader' ? null : $lineage,
        createdAt: $now,
        updatedAt: $now,
    );
}

/** El censo activo de una casa, tal y como vive en la base. */
function activeMemberCount(PDO $pdo, string $clanId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM clan_members WHERE clan_id = :clanId AND left_at IS NULL'
    );
    $statement->execute([':clanId' => $clanId]);

    return (int) $statement->fetchColumn();
}

/** El rol vigente de un mago en su casa, o null si no milita en ella. */
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

/** Prepara un plano efímero con el esquema canónico completo. */
function forgeRealm(string $projectRoot): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

    return $pdo;
}

$now = instantOf('2026-09-14T12:00:00Z');
$pdo = forgeRealm($projectRoot);
$auditService = new AuditService($pdo);
$clanService = new ClanService($pdo, $auditService);

// =====================================================================
// FASE 0 · Fundación de una hermandad (RF-01.1, RF-01.2, RF-05.4)
// =====================================================================
echo "═══ FASE 0 · Fundación de una hermandad ═══\n";

$lector = seedUser($pdo, 'usr_lector', 'LectorAnonimo', 'reader');
$fundador = seedUser($pdo, 'usr_fundador', 'FrierenLaElfa', 'editor');
$rival = seedUser($pdo, 'usr_rival', 'HeiterElSabio', 'master');

expectRejection(
    fn () => $clanService->foundClan($lector, 'Casa Imposible', 'Lema', 'rune', 'primordialFlame', 'open', $now),
    ClanGovernanceException::INSUFFICIENT_RANK,
    403,
    'Un lector anónimo no puede fundar (RF-01.1, 403 INSUFFICIENT_RANK)'
);

$clan = $clanService->foundClan(
    $fundador, // linaje jurado primordialFlame (seedUser por defecto)
    'Custodios del Fuego Sagrado',
    'En la ceniza renace la llama inmortal',
    'rune_flame_shield',
    'primordialFlame',
    ClanDto::ADMISSION_OPEN,
    $now,
);

assertCondition($clan->name === 'Custodios del Fuego Sagrado', 'La casa nace con su Nombre Canónico');
assertCondition($clan->lineageType === 'primordialFlame', 'El estandarte porta el linaje rector elegido');
assertCondition($clan->status === ClanDto::STATUS_ACTIVE, 'La casa nace en contienda (status active)');
assertCondition($clan->patriarchId === 'usr_fundador', 'El fundador ciñe la corona (RF-01.2)');
assertCondition($clan->memberCount === 1, 'El censo arranca con un solo morador');
assertCondition(
    $clan->jsonSerialize()['memberLimit'] === ClanMemberRepository::MAX_ACTIVE_MEMBERS,
    'La ficha declara el cupo de treinta adeptos (RF-01.4)'
);
assertCondition($clan->admissionMode === ClanDto::ADMISSION_OPEN, 'El régimen abierto rige desde la fundación');
assertCondition(
    activeRole($pdo, 'usr_fundador', $clan->id) === ClanMemberDto::ROLE_PATRIARCH,
    'La autoridad inscribe al fundador como Patriarca (RF-01.3)'
);
assertCondition(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_fundador'")->fetchColumn() === $clan->id,
    'El espejo del linaje sigue a la autoridad (Tarea 2.3)'
);

expectRejection(
    fn () => $clanService->foundClan($rival, 'Custodios del Fuego Sagrado', 'Otro lema', 'rune', 'primordialFlame', 'open', $now),
    ClanGovernanceException::NAME_ALREADY_RESERVED,
    409,
    'Un nombre ya inscrito no se usurpa (RF-01.2, 409)'
);

expectRejection(
    fn () => $clanService->foundClan($rival, 'Aba', 'Lema', 'rune', 'primordialFlame', 'open', $now),
    ClanGovernanceException::INVALID_NAME,
    400,
    'Un nombre de tres caracteres rompe el canon (RF-01.2, 400)'
);

expectRejection(
    fn () => $clanService->foundClan($rival, 'Casa del Noveno Arte', 'Lema', 'rune', 'novenoArte', 'open', $now),
    ClanGovernanceException::UNKNOWN_LINEAGE,
    400,
    'Un linaje ajeno a los ocho canónicos se rechaza (RF-02.1, 400)'
);

// El rival postula bajo SU linaje jurado (guardia de SPEC-10: la fundación
// solo cabe bajo la propia sangre arcana).
$rivalClan = $clanService->foundClan($rival, 'Eruditos Astrales', 'Saber sin ocaso', 'rune_astral', 'primordialFlame', 'open', $now);
expectRejection(
    fn () => $clanService->foundClan($rival, 'Segunda Casa del Sabio', 'Lema', 'rune', 'primordialFlame', 'open', $now),
    ClanGovernanceException::ALREADY_AFFILIATED,
    409,
    'La lealtad mágica es indivisible: nadie funda dos casas (RF-01.1, 409)'
);

// Nombre del largo máximo: cincuenta caracteres exactos.
$longName = str_repeat('A', ClanDto::NAME_MAX_LENGTH);
$nombreLargo = $clanService->foundClan(
    seedUser($pdo, 'usr_largo', 'NombreLargo', 'editor', 'dawnWinds'),
    $longName,
    'Lema',
    'rune',
    'dawnWinds',
    'open',
    $now,
);
assertCondition($nombreLargo->name === $longName, 'Un nombre de cincuenta caracteres es canónico (RF-01.2)');

// =====================================================================
// FASE 1 · Cupo estricto de treinta adeptos (RF-01.4)
// =====================================================================
echo "\n═══ FASE 1 · El miembro número 31 ═══\n";

$cupo = $clanService->foundClan(
    seedUser($pdo, 'usr_cupo_0', 'FundadorDelCupo', 'editor', 'worldRoots'),
    'Casa del Cupo Colmado',
    'Treinta hermanos y ni uno más',
    'rune_cupo',
    'worldRoots',
    'open',
    $now,
);

// El Patriarca ocupa la primera plaza: se incorporan veintinueve adeptos
// (todos de linaje worldRoots: la casa solo admite su propia sangre).
for ($index = 1; $index <= ClanMemberRepository::MAX_ACTIVE_MEMBERS - 1; $index++) {
    $adepto = seedUser($pdo, 'usr_cupo_' . $index, 'Adepto del Cupo ' . $index, 'editor', 'worldRoots');
    $clanService->applyToClan($adepto, $cupo->id, $now);
}

assertCondition(
    activeMemberCount($pdo, $cupo->id) === ClanMemberRepository::MAX_ACTIVE_MEMBERS,
    'La casa alcanza sus treinta adeptos activos (RF-01.4)'
);

$numeroTreintaYUno = seedUser($pdo, 'usr_cupo_31', 'ElTrigésimoPrimero', 'editor', 'worldRoots');
expectRejection(
    fn () => $clanService->applyToClan($numeroTreintaYUno, $cupo->id, $now),
    ClanGovernanceException::CLAN_QUOTA_EXCEEDED,
    409,
    'El miembro número 31 queda bloqueado (RF-01.4, 409 CLAN_QUOTA_EXCEEDED)'
);
assertCondition(
    activeMemberCount($pdo, $cupo->id) === ClanMemberRepository::MAX_ACTIVE_MEMBERS,
    'El censo permanece en treinta: el bloqueo es real, no cosmético'
);
assertCondition(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_cupo_31'")->fetchColumn() === '',
    'El rechazado no queda espejado en linaje alguno'
);

// =====================================================================
// FASE 2 · Convalecencia Arcana de catorce días (RF-01.6)
// =====================================================================
echo "\n═══ FASE 2 · Convalecencia de catorce días ═══\n";

$otraCasa = $clanService->foundClan(
    seedUser($pdo, 'usr_acogedor', 'AnfitriónPiadoso', 'editor', 'celestialTides'),
    'Casa del Acogimiento',
    'Puertas abiertas al que medita',
    'rune_acogida',
    'celestialTides',
    'open',
    $now,
);

$renunciante = seedUser($pdo, 'usr_renunciante', 'AlguienQueSeMarcha', 'editor', 'celestialTides');
$clanService->applyToClan($renunciante, $otraCasa->id, $now);
assertCondition(
    activeRole($pdo, 'usr_renunciante', $otraCasa->id) === ClanMemberDto::ROLE_ADEPT,
    'El adepto ingresa por régimen abierto (RF-01.5)'
);

$departure = $clanService->leaveClan($renunciante, $otraCasa->id, $now);
assertCondition($departure->leftAt === '2026-09-14T12:00:00Z', 'La renuncia queda estampada en la autoridad');
assertCondition(
    $departure->convalescenceExpiresAt === '2026-09-28T12:00:00Z',
    'La convalecencia se abre a catorce días naturales exactos (RF-01.6)'
);
assertCondition($departure->isActive() === false, 'La membresía cerrada no figura como vigente');
assertCondition(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_renunciante'")->fetchColumn() === '',
    'Al partir, el espejo vuelve a «sin linaje» y libera al mago'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE user_id = 'usr_renunciante'")->fetchColumn() === 1,
    'La fila de membresía JAMÁS se borra: es la memoria del Artículo III'
);

$casaRefugio = $clanService->foundClan(
    seedUser($pdo, 'usr_refugio', 'SeñorDelRefugio', 'editor', 'celestialTides'),
    'Casa del Refugio Lejano',
    'Aguarda la meditación ajena',
    'rune_refugio',
    'celestialTides',
    'open',
    $now,
);

expectRejection(
    fn () => $clanService->applyToClan($renunciante, $casaRefugio->id, $now),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'Un convaleciente no puede ingresar en otra casa (RF-01.6, 403)'
);
expectRejection(
    fn () => $clanService->foundClan($renunciante, 'Casa del Impaciente', 'Lema', 'rune', 'celestialTides', 'open', $now),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'Un convaleciente tampoco puede fundar su propia casa (RF-01.6, 403)'
);

// La frontera exacta: el día 14 aún medita; cumplido el plazo, queda libre.
expectRejection(
    fn () => $clanService->applyToClan($renunciante, $casaRefugio->id, instantOf('2026-09-28T11:59:59Z')),
    ClanGovernanceException::CONVALESCENCE_ACTIVE,
    403,
    'Un segundo antes del plazo, la meditación sigue vedando el ingreso'
);
$admisionTardia = $clanService->applyToClan($renunciante, $casaRefugio->id, instantOf('2026-09-28T12:00:00Z'));
assertCondition(
    $admisionTardia->isAdmitted(),
    'Cumplidos los catorce días naturales, el mago vuelve a ser apto (RF-01.6)'
);

// La expulsión abre la misma convalecencia que la renuncia.
$expulsado = seedUser($pdo, 'usr_expulsado', 'AdeptoIndócil', 'editor', 'celestialTides');
$clanService->applyToClan($expulsado, $otraCasa->id, $now);
$patriarcaAcogedor = new User(
    id: 'usr_acogedor',
    alias: 'AnfitriónPiadoso',
    email: 'usr_acogedor@arcano.arc',
    role: 'editor',
    clanId: $otraCasa->id,
    passwordHash: str_repeat('x', 60),
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
);
$expulsion = $clanService->expelMember($patriarcaAcogedor, $otraCasa->id, 'usr_expulsado', $now);
assertCondition(
    $expulsion->convalescenceExpiresAt === '2026-09-28T12:00:00Z',
    'La expulsión abre idéntica convalecencia de catorce días (RF-01.6)'
);
expectRejection(
    fn () => $clanService->expelMember($patriarcaAcogedor, $otraCasa->id, 'usr_acogedor', $now),
    ClanGovernanceException::CANNOT_EXPEL_SELF,
    403,
    'El Patriarca no puede expulsarse a sí mismo (Endpoint 8, 403)'
);
expectRejection(
    fn () => $clanService->expelMember($renunciante, $otraCasa->id, 'usr_expulsado', $now),
    ClanGovernanceException::NOT_PATRIARCH,
    403,
    'Un adepto cualquiera no puede expulsar a nadie (Endpoint 8, 403)'
);

// =====================================================================
// FASE 3 · Sucesión dinástica por inactividad (RF-01.9)
// =====================================================================
echo "\n═══ FASE 3 · El velatorio de los cuarenta y cinco días ═══\n";

$patriarcaDormido = seedUser($pdo, 'usr_patriarca_dormido', 'PatriarcaDormido', 'editor', 'abyssalShadows');
$antiguo = seedUser($pdo, 'usr_adepto_antiguo', 'ElMásAntiguo', 'editor', 'abyssalShadows');
$mediano = seedUser($pdo, 'usr_adepto_mediano', 'ElMediano', 'editor', 'abyssalShadows');
$reciente = seedUser($pdo, 'usr_adepto_reciente', 'ElReciénLlegado', 'editor', 'abyssalShadows');

$dinastia = $clanService->foundClan(
    $patriarcaDormido,
    'Casa del Trono Dormido',
    'El silencio también gobierna',
    'rune_trono',
    'abyssalShadows',
    'open',
    $now,
);
$memberRepository = new ClanMemberRepository($pdo);
$memberRepository->addMember('clm_antiguo', $dinastia->id, $antiguo->getId(), 'adept', '2026-02-01T00:00:00Z');
$memberRepository->addMember('clm_mediano', $dinastia->id, $mediano->getId(), 'adept', '2026-05-01T00:00:00Z');
$memberRepository->addMember('clm_reciente', $dinastia->id, $reciente->getId(), 'adept', '2026-08-01T00:00:00Z');

// Cuarenta y cuatro días de silencio: el velatorio aún no vence.
$pdo->exec("UPDATE clans SET last_activity_at = '2026-08-01T12:00:00Z' WHERE id = '{$dinastia->id}'");
$velatorioTemprano = $clanService->evaluatePatriarchSuccession($dinastia->id, $now);
assertCondition($velatorioTemprano->isDue() === false, 'A los cuarenta y cuatro días el trono no se mueve (RF-01.9)');
assertCondition($velatorioTemprano->inactivityDays === 44, 'El velatorio cuenta los días naturales transcurridos');
assertCondition(
    activeRole($pdo, 'usr_patriarca_dormido', $dinastia->id) === ClanMemberDto::ROLE_PATRIARCH,
    'El Patriarca conserva la corona mientras calla menos de cuarenta y cinco días'
);

// El día cuarenta y cinco exacto: la corona cambia de manos.
$pdo->exec("UPDATE clans SET last_activity_at = '2026-07-31T12:00:00Z' WHERE id = '{$dinastia->id}'");
$velatorio = $clanService->evaluatePatriarchSuccession($dinastia->id, $now);
assertCondition($velatorio->isDue(), 'A los cuarenta y cinco días el velatorio vence (RF-01.9)');
assertCondition($velatorio->wasTransferred(), 'La corona se transfiere en lugar de archivarse');
assertCondition($velatorio->inactivityDays === 45, 'Los días de silencio quedan registrados en el veredicto');
assertCondition(
    $velatorio->previousPatriarchId === 'usr_patriarca_dormido' && $velatorio->newPatriarchId === 'usr_adepto_antiguo',
    'La corona pasa del Patriarca dormido al adepto activo más antiguo (RF-01.9)'
);
assertCondition(
    activeRole($pdo, 'usr_patriarca_dormido', $dinastia->id) === ClanMemberDto::ROLE_ADEPT,
    'El Patriarca saliente desciende a Adepto del Linaje'
);
assertCondition(
    activeRole($pdo, 'usr_adepto_antiguo', $dinastia->id) === ClanMemberDto::ROLE_PATRIARCH,
    'El nuevo Patriarca ciñe la corona en la autoridad'
);
assertCondition(
    (string) $pdo->query("SELECT patriarch_id FROM clans WHERE id = '{$dinastia->id}'")->fetchColumn() === 'usr_adepto_antiguo',
    'La hermandad declara su nueva cabeza: jamás dos coronas ni ninguna'
);
assertCondition(
    activeMemberCount($pdo, $dinastia->id) === 4,
    'Nadie abandona la casa en la sucesión: el trono se hereda, no se expulsa'
);

// Desempate por PDA aportados entre adeptos de IDÉNTICA antigüedad.
$coetaneoA = seedUser($pdo, 'usr_coetaneo_a', 'Coetáneo Austero', 'editor', 'eternalTempest');
$coetaneoB = seedUser($pdo, 'usr_coetaneo_b', 'Coetáneo Pródigo', 'editor', 'eternalTempest');
$patriarcaTercero = seedUser($pdo, 'usr_patriarca_tercero', 'PatriarcaDelEmpate', 'editor', 'eternalTempest');
$empate = $clanService->foundClan(
    $patriarcaTercero,
    'Casa del Empate Perfecto',
    'Manda la antigüedad, dirime el mérito',
    'rune_empate',
    'eternalTempest',
    'open',
    $now,
);
$memberRepository->addMember('clm_coetaneo_a', $empate->id, $coetaneoA->getId(), 'adept', '2026-03-01T00:00:00Z');
$memberRepository->addMember('clm_coetaneo_b', $empate->id, $coetaneoB->getId(), 'adept', '2026-03-01T00:00:00Z');
$pdo->exec(
    "INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded) VALUES
     ('dst_a', 'usr_coetaneo_a', '{$empate->id}', '2026-06-01', 10),
     ('dst_b', 'usr_coetaneo_b', '{$empate->id}', '2026-06-01', 45)"
);
$pdo->exec("UPDATE clans SET last_activity_at = '2026-07-01T00:00:00Z' WHERE id = '{$empate->id}'");

$desempate = $clanService->evaluatePatriarchSuccession($empate->id, $now);
assertCondition(
    $desempate->newPatriarchId === 'usr_coetaneo_b',
    'Entre coetáneos, la corona premia al mayor contribuyente de PDA (RF-01.9)'
);

// Un Patriarca activo jamás pierde el trono.
$pdo->exec("UPDATE clans SET last_activity_at = '2026-09-14T11:00:00Z' WHERE id = '{$empate->id}'");
$tronoFirme = $clanService->evaluatePatriarchSuccession($empate->id, $now);
assertCondition($tronoFirme->isDue() === false, 'Una hora de actividad basta para conservar el trono');

// Orfandad de adeptos: la casa se disuelve como Herencia Ancestral (RF-05.3).
$solitario = seedUser($pdo, 'usr_solitario', 'PatriarcaSolitario', 'editor', 'celestialTides');
$solaCasa = $clanService->foundClan(
    $solitario,
    'Casa del Último Morador',
    'Ni un adepto que herede',
    'rune_soledad',
    'celestialTides',
    'open',
    $now,
);
$pdo->exec("UPDATE clans SET last_activity_at = '2026-07-01T00:00:00Z' WHERE id = '{$solaCasa->id}'");
$archivo = $clanService->evaluatePatriarchSuccession($solaCasa->id, $now);
assertCondition($archivo->wasArchived(), 'Sin adeptos que hereden, la casa se disuelve (RF-05.3)');
assertCondition(
    (string) $pdo->query("SELECT status FROM clans WHERE id = '{$solaCasa->id}'")->fetchColumn() === ClanDto::STATUS_ARCHIVED,
    'La casa transiciona al estado «archived» (Herencia Ancestral)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE clan_id = '{$solaCasa->id}' AND left_at IS NULL")->fetchColumn() === 0,
    'El último morador queda libre de un estandarte ya disuelto'
);
assertCondition(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_solitario'")->fetchColumn() === '',
    'Sin corona ni casa, el espejo del Patriarca se vacía'
);
$solitarioLibre = $clanService->applyToClan($solitario, $otraCasa->id, $now); // el solitario es celestialTides como la casa
assertCondition(
    $solitarioLibre->isAdmitted(),
    'La disolución no es pena: no abre convalecencia y el mago puede reingresar (RF-01.6)'
);
expectRejection(
    fn () => $clanService->foundClan(
        seedUser($pdo, 'usr_usurpador', 'UsurpadorDeNombres', 'editor', 'worldRoots'),
        'Casa del Último Morador',
        'Lema',
        'rune',
        'worldRoots',
        'open',
        $now,
    ),
    ClanGovernanceException::NAME_ALREADY_RESERVED,
    409,
    'El nombre de una casa disuelta queda reservado a perpetuidad (RF-05.4, 409)'
);

// =====================================================================
// FASE 4 · Bitácora pública de auditoría (RNF-04)
// =====================================================================
echo "\n═══ FASE 4 · La Bitácora pública ═══\n";

/** Cuenta las entradas de la bitácora con una acción y un objetivo dados. */
function auditCount(PDO $pdo, string $actionType, string $targetEntityId): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM audit_log
          WHERE action_type = :actionType AND target_entity_id = :targetEntityId'
    );
    $statement->execute([':actionType' => $actionType, ':targetEntityId' => $targetEntityId]);

    return (int) $statement->fetchColumn();
}

assertCondition(
    auditCount($pdo, 'CLAN_FOUNDED', $cupo->id) === 1,
    'La fundación de una casa queda inscrita en la bitácora (RNF-04)'
);
assertCondition(
    auditCount($pdo, 'CLAN_MEMBER_LEFT', $otraCasa->id) === 1,
    'La renuncia de un adepto queda inscrita (RNF-04)'
);
assertCondition(
    auditCount($pdo, 'CLAN_MEMBER_EXPELLED', $otraCasa->id) === 1,
    'La expulsión de un adepto queda inscrita (RNF-04)'
);
assertCondition(
    auditCount($pdo, 'PATRIARCH_INACTIVITY_SUCCESSION', $dinastia->id) === 1,
    'La sucesión por inactividad queda inscrita (RNF-04)'
);
assertCondition(
    auditCount($pdo, 'CLAN_ARCHIVED_EMPTY_SUCCESSION', $solaCasa->id) === 1,
    'La disolución por orfandad queda inscrita (RNF-04)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE target_entity_type = 'clan'")->fetchColumn() >= 6,
    'Todos los actos apuntan al tipo de entidad «clan»'
);
assertCondition(
    (string) $pdo->query("SELECT actor_role FROM audit_log WHERE action_type = 'PATRIARCH_INACTIVITY_SUCCESSION' LIMIT 1")->fetchColumn() === 'system',
    'La sucesión automática se inscribe como acto del santuario, no de una pluma'
);

// =====================================================================
// FASE 5 · Régimen bajo petición (RF-01.5)
// =====================================================================
echo "\n═══ FASE 5 · Postulaciones y deliberación ═══\n";

$casaCerrada = $clanService->foundClan(
    seedUser($pdo, 'usr_guardian', 'GuardiánDeLaPuerta', 'editor', 'dawnWinds'),
    'Casa de la Puerta Cerrada',
    'Nadie entra sin deliberación',
    'rune_puerta',
    'dawnWinds',
    ClanDto::ADMISSION_BY_APPLICATION,
    $now,
);
$guardian = new User(
    id: 'usr_guardian',
    alias: 'GuardiánDeLaPuerta',
    email: 'usr_guardian@arcano.arc',
    role: 'editor',
    clanId: $casaCerrada->id,
    passwordHash: str_repeat('x', 60),
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
);

$postulante = seedUser($pdo, 'usr_postulante', 'AspiranteTenaz', 'editor', 'dawnWinds');
$postulacion = $clanService->applyToClan(
    $postulante,
    $casaCerrada->id,
    $now,
    'Cortejo esta casa con voto de estudio y servicio.'
);
assertCondition($postulacion->isPending(), 'En régimen bajo petición, el ingreso aguarda deliberación (RF-01.5)');
assertCondition(
    $postulacion->application?->status === ClanApplicationDto::STATUS_PENDING,
    'La solicitud nace en estado `pending`'
);
assertCondition(
    $postulacion->application?->clanName === 'Casa de la Puerta Cerrada',
    'La solicitud porta el nombre de la casa cortejada'
);
assertCondition(
    activeMemberCount($pdo, $casaCerrada->id) === 1,
    'Postular no incorpora a nadie: la puerta sigue cerrada'
);

// El mismo aspirante corteja otras dos casas: la cuarta postulación se bloquea.
foreach (['usr_casa_dos', 'usr_casa_tres'] as $index => $clanOwner) {
    $otra = $clanService->foundClan(
        seedUser($pdo, $clanOwner, 'Señor de la Casa ' . ($index + 2), 'editor', 'dawnWinds'),
        'Casa Cortejada Número ' . ($index + 2),
        'Lema',
        'rune',
        'dawnWinds',
        ClanDto::ADMISSION_BY_APPLICATION,
        $now,
    );
    $clanService->applyToClan($postulante, $otra->id, $now, 'Cortejo esta segunda casa con igual devoción.');
}
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_applications WHERE user_id = 'usr_postulante' AND status = 'pending'")->fetchColumn() === 3,
    'El aspirante sostiene tres solicitudes pendientes simultáneas (RF-01.5)'
);

$cuartaCasa = $clanService->foundClan(
    seedUser($pdo, 'usr_casa_cuatro', 'Señor de la Cuarta Casa', 'editor', 'dawnWinds'),
    'Casa Cortejada Número 4',
    'Lema',
    'rune',
    'dawnWinds',
    ClanDto::ADMISSION_BY_APPLICATION,
    $now,
);
expectRejection(
    fn () => $clanService->applyToClan($postulante, $cuartaCasa->id, $now, 'La cuarta petición excede el cupo de tres.'),
    ClanGovernanceException::PENDING_APPLICATIONS_LIMIT,
    400,
    'La cuarta solicitud pendiente queda bloqueada (RF-01.5, 400)'
);
expectRejection(
    fn () => $clanService->applyToClan($postulante, $casaCerrada->id, $now, 'Postulación duplicada sobre la primera casa.'),
    ClanGovernanceException::APPLICATION_ALREADY_PENDING,
    409,
    'No se admiten postulaciones duplicadas sobre la misma casa (409)'
);

$applicationId = (string) $postulacion->application?->id;
expectRejection(
    fn () => $clanService->resolveApplication($postulante, $casaCerrada->id, $applicationId, 'approve', $now),
    ClanGovernanceException::NOT_PATRIARCH,
    403,
    'Solo el Patriarca delibera sobre las postulaciones de su casa (403)'
);
expectRejection(
    fn () => $clanService->resolveApplication($guardian, $casaCerrada->id, $applicationId, 'dudar', $now),
    ClanGovernanceException::INVALID_DECISION,
    400,
    'Un veredicto ajeno al canon («dudar») se rechaza (400)'
);
expectRejection(
    fn () => $clanService->resolveApplication($guardian, $casaCerrada->id, 'app_inexistente', 'approve', $now),
    ClanGovernanceException::APPLICATION_NOT_FOUND,
    404,
    'Una solicitud inexistente no puede dirimirse (404)'
);

$aprobada = $clanService->resolveApplication($guardian, $casaCerrada->id, $applicationId, 'approve', $now);
assertCondition($aprobada->isAdmitted(), 'El Patriarca aprueba y el postulante ingresa (Endpoint 6)');
assertCondition(
    activeRole($pdo, 'usr_postulante', $casaCerrada->id) === ClanMemberDto::ROLE_ADEPT,
    'El aprobado ingresa como Adepto del Linaje (RF-01.3)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM clan_applications WHERE user_id = 'usr_postulante' AND status = 'pending'")->fetchColumn() === 0,
    'La aprobación cancela sus restantes postulaciones: ya no corteja otras casas'
);
expectRejection(
    fn () => $clanService->resolveApplication($guardian, $casaCerrada->id, $applicationId, 'approve', $now),
    ClanGovernanceException::APPLICATION_ALREADY_RESOLVED,
    409,
    'Una solicitud ya resuelta no vuelve a deliberarse (409)'
);

// Rechazo: no incorpora a nadie ni toca sus otras postulaciones.
$rechazado = seedUser($pdo, 'usr_rechazado', 'AspiranteDesdeñado', 'editor', 'dawnWinds');
$solicitudAjena = $clanService->applyToClan($rechazado, $casaCerrada->id, $now, 'Mi petición, para ser desdeñada con honor.');
$rechazo = $clanService->resolveApplication(
    $guardian,
    $casaCerrada->id,
    (string) $solicitudAjena->application?->id,
    'reject',
    $now,
    'La casa guarda plenitud de plumas: vuelve a otra luna con honor.'
);
assertCondition($rechazo->wasRejected(), 'El Patriarca rechaza la postulación (Endpoint 6)');
assertCondition(
    $rechazo->application?->status === ClanApplicationDto::STATUS_REJECTED,
    'La solicitud queda en estado `rejected`'
);
assertCondition(
    activeMemberCount($pdo, $casaCerrada->id) === 2,
    'El rechazado no ingresó: la casa sigue con dos moradores'
);

// =====================================================================
// FASE 6 · Traspaso de la corona y disolución voluntaria (Endpoints 7 y 9)
// =====================================================================
echo "\n═══ FASE 6 · Corona y disolución ═══\n";

expectRejection(
    fn () => $clanService->leaveClan($guardian, $casaCerrada->id, $now),
    ClanGovernanceException::PATRIARCH_MUST_TRANSFER_CROWN,
    400,
    'El Patriarca no parte sin ceder antes la corona (Endpoint 7, 400)'
);

expectRejection(
    fn () => $clanService->transferLeadership($guardian, $casaCerrada->id, 'usr_ajeno', $now),
    ClanGovernanceException::INELIGIBLE_SUCCESSOR,
    400,
    'La corona solo ciñe a un adepto activo de la casa (Endpoint 9, 400)'
);

$coronado = $clanService->transferLeadership($guardian, $casaCerrada->id, 'usr_postulante', $now);
assertCondition(
    $coronado->role === ClanMemberDto::ROLE_PATRIARCH,
    'El traspaso corona al adepto designado (Endpoint 9)'
);
assertCondition(
    activeRole($pdo, 'usr_guardian', $casaCerrada->id) === ClanMemberDto::ROLE_ADEPT,
    'El Patriarca saliente desciende a Adepto'
);
assertCondition(
    (string) $pdo->query("SELECT patriarch_id FROM clans WHERE id = '{$casaCerrada->id}'")->fetchColumn() === 'usr_postulante',
    'La hermandad declara su nueva cabeza tras el traspaso'
);

// El Patriarca saliente ya puede marcharse: cedió el cetro.
$partidaDelFundador = $clanService->leaveClan($guardian, $casaCerrada->id, $now);
assertCondition(
    $partidaDelFundador->convalescenceExpiresAt !== null,
    'Una vez cedida la corona, el Patriarca saliente parte con su convalecencia'
);

// El último morador disuelve la casa al partir (RF-05.3).
$nuevoPatriarca = new User(
    id: 'usr_postulante',
    alias: 'AspiranteTenaz',
    email: 'usr_postulante@arcano.arc',
    role: 'editor',
    clanId: $casaCerrada->id,
    passwordHash: str_repeat('x', 60),
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
);
$disolucion = $clanService->leaveClan($nuevoPatriarca, $casaCerrada->id, $now);
assertCondition($disolucion->convalescenceExpiresAt === null, 'La disolución no es una pena: no abre convalecencia');
assertCondition(
    (string) $pdo->query("SELECT status FROM clans WHERE id = '{$casaCerrada->id}'")->fetchColumn() === ClanDto::STATUS_ARCHIVED,
    'La casa del último morador pasa a Herencia Ancestral (RF-05.3)'
);
assertCondition(
    auditCount($pdo, 'CLAN_ARCHIVED_BY_PATRIARCH', $casaCerrada->id) === 1,
    'La disolución por voluntad del Patriarca queda inscrita (RNF-04)'
);

// =====================================================================
// FASE 7 · Determinismo y dualidad lingüística (RNF-01, RNF-05)
// =====================================================================
echo "\n═══ FASE 7 · Determinismo y dualismo ═══\n";

$source = (string) file_get_contents($projectRoot . '/src/Services/ClanService.php');
assertCondition(
    str_contains($source, 'time()') === false
        && str_contains($source, 'microtime') === false
        && str_contains($source, 'date(') === false,
    'El servicio no consulta jamás el reloj del sistema ni la fecha suelta (RNF-01)'
);
assertCondition(
    str_contains($source, 'beginTransaction') && str_contains($source, 'rollBack'),
    'La fundación y el ingreso se consuman en transacción reversible (RNF-01)'
);

/**
 * Forja un mundo en el que un Patriarca lleva cuarenta y cinco días callado.
 *
 * @return array{0: ClanService, 1: string} El servicio y el identificador de la casa.
 */
function forgeDormantRealm(string $projectRoot, string $suffix): array
{
    $pdo = forgeRealm($projectRoot);
    $service = new ClanService($pdo);
    $instant = instantOf('2026-09-14T12:00:00Z');

    $patriarca = seedUser($pdo, 'usr_dormido_' . $suffix, 'Dormido ' . $suffix, 'editor', 'worldRoots');
    $heredero = seedUser($pdo, 'usr_heredero_' . $suffix, 'Heredero ' . $suffix, 'editor', 'worldRoots');
    $clan = $service->foundClan($patriarca, 'Casa Determinista', 'Lema', 'rune', 'worldRoots', 'open', $instant);

    (new ClanMemberRepository($pdo))->addMember(
        'clm_heredero_' . $suffix,
        $clan->id,
        $heredero->getId(),
        ClanMemberDto::ROLE_ADEPT,
        '2026-02-01T00:00:00Z',
    );
    $pdo->exec("UPDATE clans SET last_activity_at = '2026-07-01T00:00:00Z' WHERE id = '{$clan->id}'");

    return [$service, $clan->id];
}

// Dos mundos gemelos, juzgados con el mismo instante, dictan idéntico veredicto.
[$serviceA, $clanA] = forgeDormantRealm($projectRoot, 'alpha');
[$serviceB, $clanB] = forgeDormantRealm($projectRoot, 'beta');

$veredictoA = $serviceA->evaluatePatriarchSuccession($clanA, instantOf('2026-09-14T12:00:00Z'));
$veredictoB = $serviceB->evaluatePatriarchSuccession($clanB, instantOf('2026-09-14T12:00:00Z'));
assertCondition(
    $veredictoA->outcome === $veredictoB->outcome
        && $veredictoA->inactivityDays === $veredictoB->inactivityDays,
    'Dos mundos gemelos dictan el mismo veredicto con el mismo instante (RNF-01)'
);
assertCondition(
    $veredictoA->newPatriarchId !== null
        && $veredictoA->newPatriarchId === 'usr_heredero_alpha'
        && $veredictoB->newPatriarchId === 'usr_heredero_beta',
    'Cada mundo corona a su propio heredero: ninguna lotería interviene (RNF-01)'
);

// Dualismo lingüístico: claves de contrato en camelCase, noble castellano
// en los valores, y columnas de tabla en snake_case.
$clanJson = $clan->jsonSerialize();
assertCondition(
    isset($clanJson['coatOfArms'], $clanJson['lineageType'], $clanJson['memberLimit'])
        && array_key_exists('patriarchId', $clanJson),
    'El contrato JSON viaja en inglés camelCase (RNF-05)'
);
assertCondition(
    $clanJson['name'] === 'Custodios del Fuego Sagrado'
        && $clanJson['motto'] === 'En la ceniza renace la llama inmortal',
    'Los textos preservan el noble castellano (RNF-05, Artículo IV)'
);
$columnNames = array_column(
    $pdo->query('PRAGMA table_info(clan_members)')->fetchAll(PDO::FETCH_ASSOC),
    'name'
);
assertCondition(
    in_array('convalescence_expires_at', $columnNames, true)
        && in_array('left_at', $columnNames, true),
    'Las columnas del plano permanecen en snake_case (RNF-05)'
);
assertCondition(
    $departure->jsonSerialize()['convalescenceExpiresAt'] === '2026-09-28T12:00:00Z',
    'El contrato de membresía expone la convalecencia en camelCase (RNF-05)'
);

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: FALLO — el gobierno de clanes no cumple el canon.\n";
    exit(1);
}

echo "RESULTADO: EXITO — La Tarea 2.4 cumple su criterio 'Hecho cuando'.\n";
exit(0);

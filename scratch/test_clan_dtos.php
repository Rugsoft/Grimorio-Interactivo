<?php

/**
 * test_clan_dtos.php — Verificación de la Tarea 2.1 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «Todos los DTOs se instancian con tipificación estricta y json_encode()
 *    genera el formato de contrato REST estipulado en el plan.»
 *
 * Estrategia: se inspecciona la superficie de las seis clases (finales,
 * readonly, JsonSerializable, tipado estricto), se ejercita cada contrato
 * contra datos reales leídos de la base SQLite migrada —schema.sql +
 * seeds.sql— y se comprueba que
 * json_encode() emite EXACTAMENTE las claves camelCase y el orden canónico
 * declarados en cada docblock, sin advertencias de tipo.
 *
 * Fases:
 *   [0] Superficie: los seis ficheros existen y declaran tipado estricto.
 *   [1] Arquitectura: clases `final readonly`, JsonSerializable y parámetros
 *       de constructor íntegramente tipados (Artículo V).
 *   [2] LineageDto — ficha del Linaje Canónico y su serialización.
 *   [3] ClanDto — identidad heráldica, cupo y gloria; contrato del Endpoint 2/3.
 *   [4] ClanMemberDto — censo, rol y cómputo determinista de convalecencia.
 *   [5] ClanApplicationDto — postulación, tope de 3 pendientes y veredicto.
 *   [6] WeeklyCycleDto — corte dominical, etiqueta ceremonial y Libro Mayor.
 *   [7] DominionAwardDto — escala por Círculo, sinergia y techo del simulador.
 *   [8] Anclaje a la base real: fromDatabaseRow() traduce snake_case a
 *       camelCase para cada tabla del dominio.
 *   [9] Inmutabilidad de facto: toda escritura sobre un DTO levanta Error.
 *  [10] Guardas de canon: enumerados inválidos e identidades vacías son
 *       rechazados con InvalidArgumentException antes de tocar la base.
 *  [11] Determinismo y Dogma Vanilla: json_encode estable, sin dependencias.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo y json_encode; sin librerías.
 *   - Artículo V: claves en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_clan_dtos.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dtosRequired = [
    'ClanDto'             => __DIR__ . '/../src/Dto/ClanDto.php',
    'ClanMemberDto'       => __DIR__ . '/../src/Dto/ClanMemberDto.php',
    'LineageDto'          => __DIR__ . '/../src/Dto/LineageDto.php',
    'ClanApplicationDto'  => __DIR__ . '/../src/Dto/ClanApplicationDto.php',
    'WeeklyCycleDto'      => __DIR__ . '/../src/Dto/WeeklyCycleDto.php',
    'DominionAwardDto'    => __DIR__ . '/../src/Dto/DominionAwardDto.php',
];

foreach ($dtosRequired as $dtoName => $dtoPath) {
    if (!is_file($dtoPath)) {
        fwrite(STDERR, "[FATAL] Falta src/Dto/{$dtoName}.php — fase roja: aún no existe.\n");
        exit(1);
    }
}

foreach ($dtosRequired as $dtoPath) {
    require $dtoPath;
}

use Grimorio\Dto\ClanApplicationDto;
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\ClanMemberDto;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Dto\LineageDto;
use Grimorio\Dto\WeeklyCycleDto;

$assertsPassed = 0;
$assertsFailed = 0;

/** Advertencias y avisos de PHP capturados durante la prueba. */
$phpWarnings = [];

/** Convierte cualquier advertencia nativa en materia de aserto (no la silencia). */
set_error_handler(static function (int $severity, string $message): bool {
    global $phpWarnings;
    $phpWarnings[] = $message;
    return true;
});

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

/** Aserta que json_encode() emite exactamente las claves y el orden esperados. */
function assertJsonContract(object $dto, array $expectedKeys, string $description): void
{
    $encoded = json_encode($dto, JSON_UNESCAPED_UNICODE);
    $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

    assertCondition($encoded !== false && is_array($decoded), "{$description}: json_encode válido");
    assertCondition(
        is_array($decoded) && array_keys($decoded) === $expectedKeys,
        "{$description}: claves y orden canónicos (" . implode(', ', $expectedKeys) . ')'
    );
    assertCondition(
        $encoded !== false && str_contains($encoded, '\\') === false,
        "{$description}: JSON sin rutas ni escapes espurios"
    );
}

/** Aserta el contrato de un enumerado guardado. */
function assertRejects(callable $forge, string $description): void
{
    try {
        $forge();
        assertCondition(false, "{$description}: debía rechazarse y no lo hizo");
    } catch (InvalidArgumentException $exception) {
        assertCondition(trim($exception->getMessage()) !== '', "{$description}: rechazado con leyenda");
    } catch (Throwable $throwable) {
        assertCondition(false, "{$description}: rechazado con " . $throwable::class . ' y no InvalidArgumentException');
    }
}

echo "═══ FASE 0 · Superficie de los seis DTOs ═══\n";
foreach ($dtosRequired as $dtoName => $dtoPath) {
    $source = (string) file_get_contents($dtoPath);
    assertCondition(str_contains($source, 'declare(strict_types=1);'), "{$dtoName}: declara tipado estricto");
    assertCondition(str_contains($source, 'namespace Grimorio\\Dto;'), "{$dtoName}: mora en el espacio Grimorio\\Dto");
}

echo "\n═══ FASE 1 · Arquitectura inmutable y tipado íntegro ═══\n";
$canonicalClasses = [
    ClanDto::class,
    ClanMemberDto::class,
    LineageDto::class,
    ClanApplicationDto::class,
    WeeklyCycleDto::class,
    DominionAwardDto::class,
];
foreach ($canonicalClasses as $class) {
    $shortName = substr($class, strrpos($class, '\\') + 1);
    $reflection = new ReflectionClass($class);
    assertCondition($reflection->isFinal(), "{$shortName}: es final");
    assertCondition($reflection->isReadOnly(), "{$shortName}: es readonly");
    assertCondition($reflection->implementsInterface(JsonSerializable::class), "{$shortName}: implementa JsonSerializable");

    $untyped = [];
    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        if ($parameter->getType() === null) {
            $untyped[] = $parameter->getName();
        }
    }
    assertCondition($untyped === [], "{$shortName}: todos los parámetros están tipados" . ($untyped !== [] ? ' (sin tipo: ' . implode(', ', $untyped) . ')' : ''));
}

echo "\n═══ FASE 2 · LineageDto — ficha del Linaje Canónico ═══\n";
$primordialFlame = new LineageDto(
    id: 'primordialFlame',
    name: 'Linaje de la Llama Primordial',
    rulingElement: 'fire',
    glyph: 'rune_flame',
    bannerColor: '#c0392b',
    heraldicFrame: 'flame_shield',
    description: 'Guardianes de la chispa que precedió a toda forma.',
);
assertCondition($primordialFlame->hasRulingElement('fire'), 'RF-03.4: reconoce su elemento rector');
assertCondition(!$primordialFlame->hasRulingElement('water'), 'RF-03.4: no usurpa elementos ajenos');
assertJsonContract($primordialFlame, ['id', 'name', 'rulingElement', 'glyph', 'bannerColor', 'heraldicFrame', 'description'], 'LineageDto');
assertRejects(static fn () => new LineageDto('aetherWeavers', 'Tejedores del Éter', 'pureArcane', 'rune_ether', 'azul', 'frame'), 'Linaje: notación heráldica inválida');
assertRejects(static fn () => new LineageDto('', 'Sin identidad', 'fire', 'g', '#ffffff', 'f'), 'Linaje: identificador vacío');

echo "\n═══ FASE 3 · ClanDto — identidad heráldica, cupo y gloria ═══\n";
$clan = new ClanDto(
    id: 'cln_fuego',
    slug: 'custodios-del-fuego-sagrado',
    name: 'Custodios del Fuego Sagrado',
    motto: 'En la ceniza renace la llama inmortal',
    coatOfArms: 'rune_flame_shield',
    lineageType: 'primordialFlame',
    admissionMode: ClanDto::ADMISSION_BY_APPLICATION,
    status: ClanDto::STATUS_ACTIVE,
    patriarchId: 'usr_custodio_primordial',
    weeklyPoints: 260,
    historicalPoints: 1480,
    memberCount: 12,
    lastActivityAt: '2026-09-14T10:00:00Z',
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-09-14T10:00:00Z',
);
assertCondition($clan->isActive() && !$clan->isArchived(), 'Estado activo reconocido (RF-05.3)');
assertCondition($clan->hasVacancy() && $clan->remainingVacancies() === 18, 'RF-01.4: cupo y vacantes correctos');
assertCondition(!$clan->isOpenAdmission(), 'RF-01.5: régimen bajo petición reconocido');
assertCondition($clan->dominionPoints() === 1740, 'PDA semanales e históricos sumados');
assertJsonContract($clan, ['id', 'slug', 'name', 'motto', 'coatOfArms', 'lineageType', 'admissionMode', 'status', 'patriarchId', 'weeklyPoints', 'historicalPoints', 'memberCount', 'memberLimit', 'lastActivityAt', 'createdAt', 'updatedAt'], 'ClanDto');
assertCondition(json_encode($clan, JSON_UNESCAPED_UNICODE) !== false && (string) json_encode($clan, JSON_UNESCAPED_UNICODE) === (string) json_encode($clan, JSON_UNESCAPED_UNICODE), 'ClanDto: serialización determinista');
assertRejects(static fn () => new ClanDto('cln_x', 's', 'Ab'), 'Clan: Nombre Canónico demasiado breve (RF-01.2)');
assertRejects(static fn () => new ClanDto('cln_x', 's', 'Casa Válida', '', '', 'primordialFlame', 'porInvitacion'), 'Clan: régimen de admisión inválido');

echo "\n═══ FASE 4 · ClanMemberDto — censo, rol y convalecencia ═══\n";
$now = new DateTimeImmutable('2026-09-14T12:00:00Z');
$patriarch = new ClanMemberDto(
    id: 'mem_1',
    clanId: 'cln_fuego',
    userId: 'usr_custodio_primordial',
    role: ClanMemberDto::ROLE_PATRIARCH,
    userAlias: 'El Custodio Primordial',
    joinedAt: '2026-01-01T00:00:00Z',
);
$convalescent = new ClanMemberDto(
    id: 'mem_2',
    clanId: 'cln_fuego',
    userId: 'usr_errante',
    role: ClanMemberDto::ROLE_ADEPT,
    userAlias: 'El Errante',
    joinedAt: '2026-02-01T00:00:00Z',
    leftAt: '2026-09-10T12:00:00Z',
    convalescenceExpiresAt: '2026-09-24T12:00:00Z',
);
assertCondition($patriarch->isActive() && $patriarch->isPatriarch(), 'RF-01.3: Patriarca vigente reconocido');
assertCondition($patriarch->convalescenceDaysRemaining($now) === null, 'Sin partida no hay convalecencia');
assertCondition($convalescent->isInConvalescenceAt($now), 'RF-01.6: convaleciente detectado');
assertCondition($convalescent->convalescenceDaysRemaining($now) === 10, 'RF-01.6: restan 10 días naturales');
assertCondition(!$convalescent->isInConvalescenceAt(new DateTimeImmutable('2026-09-24T12:00:00Z')), 'Frontera: al expirar deja de haber convalecencia');
assertJsonContract($patriarch, ['id', 'clanId', 'userId', 'userAlias', 'role', 'joinedAt', 'leftAt', 'convalescenceExpiresAt', 'isActive'], 'ClanMemberDto');
assertRejects(static fn () => new ClanMemberDto('m', 'c', 'u', 'archimago'), 'Miembro: rol ajeno al canon (RF-01.3)');
assertRejects(static fn () => new ClanMemberDto('m', 'c', 'u', 'adept', '', '2026-01-01T00:00:00Z', null, '2026-01-15T00:00:00Z'), 'Miembro: convalecencia sin partida');

echo "\n═══ FASE 5 · ClanApplicationDto — postulación y veredicto ═══\n";
$pending = new ClanApplicationDto(
    id: 'app_1',
    clanId: 'cln_fuego',
    userId: 'usr_postulante',
    status: ClanApplicationDto::STATUS_PENDING,
    clanName: 'Custodios del Fuego Sagrado',
    userAlias: 'La Postulante',
    createdAt: '2026-09-13T08:00:00Z',
);
$approved = new ClanApplicationDto(
    id: 'app_2',
    clanId: 'cln_fuego',
    userId: 'usr_postulante',
    status: ClanApplicationDto::STATUS_APPROVED,
    clanName: 'Custodios del Fuego Sagrado',
    userAlias: 'La Postulante',
    createdAt: '2026-09-13T08:00:00Z',
    resolvedAt: '2026-09-14T09:00:00Z',
);
assertCondition($pending->isPending() && !$pending->wasApproved(), 'RF-01.5: postulación pendiente reconocida');
assertCondition($approved->wasApproved() && !$approved->isPending(), 'RF-01.5: postulación admitida reconocida');
assertCondition(ClanApplicationDto::MAX_PENDING_APPLICATIONS === 3, 'RF-01.5: tope de 3 postulaciones pendientes publicado');
assertJsonContract($pending, ['id', 'clanId', 'clanName', 'userId', 'userAlias', 'status', 'createdAt', 'resolvedAt', 'isPending'], 'ClanApplicationDto');
assertRejects(static fn () => new ClanApplicationDto('a', 'c', 'u', 'enEspera'), 'Solicitud: estado inválido');
assertRejects(static fn () => new ClanApplicationDto('a', 'c', 'u', 'pending', '', '', null, '2026-09-14T09:00:00Z'), 'Solicitud: pendiente con veredicto');

echo "\n═══ FASE 6 · WeeklyCycleDto — corte dominical ═══\n";
$cycle = new WeeklyCycleDto(
    id: 'cyc_2026_36',
    weekNumber: 36,
    cycleYear: 2026,
    regentClanId: 'cln_fuego',
    winningPoints: 1240,
    winnerSpellCount: 7,
    closedAt: '2026-09-06T23:59:59Z',
    regentClanName: 'Custodios del Fuego Sagrado',
);
assertCondition($cycle->label() === 'Año 2026 · Semana 36', 'Etiqueta ceremonial del Libro Mayor (Art. IV)');
assertCondition($cycle->matchesCycle(2026, 36) && !$cycle->matchesCycle(2026, 37), 'Guarda de idempotencia del cierre (RF-04.2)');
assertJsonContract($cycle, ['id', 'weekNumber', 'cycleYear', 'regentClanId', 'regentClanName', 'winningPoints', 'winnerSpellCount', 'closedAt', 'label'], 'WeeklyCycleDto');
assertRejects(static fn () => new WeeklyCycleDto('c', 54, 2026, 'cln_x'), 'Ciclo: semana ISO fuera de rango');
assertRejects(static fn () => new WeeklyCycleDto('c', 36, 2026, ''), 'Ciclo: sin Clan Regente');

echo "\n═══ FASE 7 · DominionAwardDto — gloria, sinergia y techos ═══\n";
$expectedCirclePoints = [1 => 120, 2 => 140, 3 => 160, 4 => 180, 5 => 200];
foreach ($expectedCirclePoints as $circle => $points) {
    assertCondition(DominionAwardDto::circleBasePoints($circle) === $points, "RF-03.1: Círculo {$circle} ⇒ {$points} PDA");
}
assertRejects(static fn () => DominionAwardDto::circleBasePoints(6), 'RF-03.1: Círculo VI rechazado');
assertRejects(static fn () => DominionAwardDto::circleBasePoints(0), 'RF-03.1: Círculo 0 rechazado');

$synergyAward = new DominionAwardDto(
    actionType: DominionAwardDto::ACTION_SIMULATOR_COMBO,
    basePoints: 10,
    awardedPoints: 13,
    hasSynergy: true,
    awardedAt: '2026-09-14T12:00:00Z',
    dailyQuotaRemaining: 27,
);
assertCondition($synergyAward->wasAwarded(), 'RF-03.4: recibo acreditado');
assertCondition($synergyAward->synergyBonus() === 3, 'RF-03.4: 10 × 1.25 ⇒ 13 PDA (+3)');
assertJsonContract($synergyAward, ['actionType', 'basePoints', 'awardedPoints', 'hasSynergy', 'synergyBonus', 'awardedAt', 'dailyQuotaRemaining', 'reason'], 'DominionAwardDto');

$capReached = DominionAwardDto::capReached('2026-09-14T23:00:00Z');
assertCondition(!$capReached->wasAwarded(), 'RF-03.2: recibo denegado por techo diario');
assertCondition($capReached->reason === DominionAwardDto::REASON_DAILY_SIMULATOR_CAP_REACHED, 'RF-03.2: motivo canónico de denegación');
assertCondition($capReached->dailyQuotaRemaining === 0, 'RF-03.2: cupo diario agotado');
assertRejects(static fn () => new DominionAwardDto('spellForged'), 'Dominio: acción ajena al canon');
assertRejects(static fn () => new DominionAwardDto('simulatorCombo', 10, 13, true, null, null, 'MOTIVO'), 'Dominio: denegación que acredita gloria');
assertRejects(static fn () => new DominionAwardDto('communityFavorite', 0, 5, false), 'Dominio: gloria sin valor base');

echo "\n═══ FASE 8 · Anclaje a la base real (snake_case → camelCase) ═══\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/seeds.sql'));
// El DDL canónico ya incorpora el dominio de SPEC-07: no hay migración que
// aplicar en una base recién construida.

$pdo->exec("INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type, admission_mode, status, patriarch_id, weekly_points, historical_points, last_activity_at, updated_at) VALUES ('cln_prueba', 'casa-prueba', 'Casa de la Prueba', 'Probemos la verdad', '2026-01-02T00:00:00Z', 'rune_probe', 'primordialFlame', 'open', 'active', 'usr_custodio_primordial', 42, 210, '2026-09-14T11:00:00Z', '2026-09-14T11:00:00Z')");
// El tutor del linaje fundacional ya milita en el suyo (semillas), así que el
// censo de prueba emplea a un adepto propio.
$pdo->exec("INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at) VALUES ('usr_censo_prueba', 'Adepto del Censo', 'censo@prueba.arc', 'x', 'editor', 'cln_prueba', '2026-01-02T00:00:00Z', '2026-01-02T00:00:00Z')");
$pdo->exec("INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at) VALUES ('mem_prueba', 'cln_prueba', 'usr_censo_prueba', 'patriarch', '2026-01-02T00:00:00Z', NULL, NULL)");
$pdo->exec("INSERT INTO clan_applications (id, clan_id, user_id, status, created_at, resolved_at) VALUES ('app_prueba', 'cln_prueba', 'usr_custodio_primordial', 'pending', '2026-09-13T00:00:00Z', NULL)");
$pdo->exec("INSERT INTO weekly_cycles (id, week_number, cycle_year, regent_clan_id, winning_points, winner_spell_count, closed_at) VALUES ('cyc_prueba', 35, 2026, 'cln_prueba', 900, 5, '2026-08-30T23:59:59Z')");

$clanRow = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM clan_members m WHERE m.clan_id = c.id AND m.left_at IS NULL) AS member_count FROM clans c WHERE c.id = 'cln_prueba'")->fetch(PDO::FETCH_ASSOC);
$clanFromDb = ClanDto::fromDatabaseRow($clanRow);
assertCondition($clanFromDb->admissionMode === 'open' && $clanFromDb->lineageType === 'primordialFlame', 'ClanDto::fromDatabaseRow traduce estados del motor');
assertCondition($clanFromDb->weeklyPoints === 42 && $clanFromDb->historicalPoints === 210, 'ClanDto::fromDatabaseRow preserva la gloria');
assertCondition($clanFromDb->memberCount === 1, 'ClanDto::fromDatabaseRow recoge el censo resuelto por JOIN');
assertCondition($clanFromDb->isOpenAdmission() && $clanFromDb->hasVacancy(), 'ClanDto::fromDatabaseRow: casa abierta con vacantes');

$memberRow = $pdo->query("SELECT m.*, u.alias AS user_alias FROM clan_members m JOIN users u ON u.id = m.user_id WHERE m.id = 'mem_prueba'")->fetch(PDO::FETCH_ASSOC);
$memberFromDb = ClanMemberDto::fromDatabaseRow($memberRow);
assertCondition($memberFromDb->userAlias === 'Adepto del Censo', 'ClanMemberDto::fromDatabaseRow resuelve el alias');
assertCondition($memberFromDb->isPatriarch() && $memberFromDb->isActive(), 'ClanMemberDto::fromDatabaseRow reconoce al Patriarca vigente');

$applicationRow = $pdo->query("SELECT a.*, c.name AS clan_name, u.alias AS user_alias FROM clan_applications a JOIN clans c ON c.id = a.clan_id JOIN users u ON u.id = a.user_id WHERE a.id = 'app_prueba'")->fetch(PDO::FETCH_ASSOC);
$applicationFromDb = ClanApplicationDto::fromDatabaseRow($applicationRow);
assertCondition($applicationFromDb->isPending() && $applicationFromDb->clanName === 'Casa de la Prueba', 'ClanApplicationDto::fromDatabaseRow traduce la postulación');

$cycleRow = $pdo->query("SELECT w.*, c.name AS regent_clan_name FROM weekly_cycles w JOIN clans c ON c.id = w.regent_clan_id WHERE w.id = 'cyc_prueba'")->fetch(PDO::FETCH_ASSOC);
$cycleFromDb = WeeklyCycleDto::fromDatabaseRow($cycleRow);
assertCondition($cycleFromDb->label() === 'Año 2026 · Semana 35', 'WeeklyCycleDto::fromDatabaseRow rotula el corte');
assertCondition($cycleFromDb->winningPoints === 900 && $cycleFromDb->winnerSpellCount === 5, 'WeeklyCycleDto::fromDatabaseRow preserva la gloria del regente');

echo "\n═══ FASE 9 · Inmutabilidad de facto (readonly) ═══\n";
foreach ([
    'ClanDto::weeklyPoints'      => [$clan, 'weeklyPoints', 999],
    'ClanMemberDto::role'        => [$patriarch, 'role', 'adept'],
    'LineageDto::glyph'          => [$primordialFlame, 'glyph', 'otro'],
    'ClanApplicationDto::status' => [$pending, 'status', 'approved'],
    'WeeklyCycleDto::cycleYear'  => [$cycle, 'cycleYear', 1999],
    'DominionAwardDto::awardedPoints' => [$synergyAward, 'awardedPoints', 99],
] as $description => [$dto, $property, $value]) {
    try {
        $dto->{$property} = $value;
        assertCondition(false, "{$description}: la escritura debía levantarse");
    } catch (Error) {
        assertCondition(true, "{$description}: inmutable de facto");
    }
}

echo "\n═══ FASE 10 · Guardas de canon y advertencias de PHP ═══\n";
assertCondition($phpWarnings === [], 'Ninguna advertencia de tipo emitida durante la prueba' . ($phpWarnings !== [] ? ': ' . implode(' | ', $phpWarnings) : ''));

echo "\n═══ FASE 11 · Dogma Vanilla: cero dependencias externas ═══\n";
foreach ($dtosRequired as $dtoName => $dtoPath) {
    $source = (string) file_get_contents($dtoPath);
    assertCondition(!preg_match('/^\s*use\s+Vendor\\\\/m', $source) && !str_contains($source, 'require '), "{$dtoName}: sin dependencias de terceros");
}

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: FALLO — la Tarea 2.1 no cumple aún su criterio 'Hecho cuando'.\n";
    exit(1);
}

echo "RESULTADO: EXITO — La Tarea 2.1 cumple su criterio 'Hecho cuando'.\n";
exit(0);

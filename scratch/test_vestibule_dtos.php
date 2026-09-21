<?php

/**
 * test_vestibule_dtos.php — Verificación de la Tarea 3.1 de TASKS-10.
 *
 * Valida contra el criterio «Hecho cuando»:
 *   «jsonSerialize() produce exactamente las llaves del contrato y un arnés
 *    de DTOs las coteja una a una» (plan §2.2, Endpoint 1).
 *
 * Estrategia: se inspecciona la superficie de las tres clases (finales,
 * readonly, JsonSerializable, tipado estricto) y se ejercita cada contrato
 * comprobando que json_encode() emite EXACTAMENTE las llaves camelCase, en
 * el orden canónico del plan, preservando los nulos canónicos (myHouse,
 * petitionId, vedadoLegend, membership).
 *
 * Fases:
 *   [0] Superficie: los tres ficheros existen y declaran tipado estricto.
 *   [1] Arquitectura: clases `final readonly`, JsonSerializable y
 *       parámetros de constructor íntegramente tipados (Artículo V).
 *   [2] ClanPetitionDto — inventario consolidado (RF-03.8) con su estado
 *       derivado verdictSeen (RF-03.4).
 *   [3] VestibuleClanDto — casa del catálogo con su relación y gesto
 *       (RF-01.3, RF-01.7, RF-03.5).
 *   [4] VestibuleStateDto — el sobre único (RNF-04) con su aptitude anidada.
 *   [5] Guardas de canon: parejas indivisibles (gesto/leyenda, aptitud/
 *       vedado), estados ajenos y tipos extraños rechazados.
 *   [6] Nulos canónicos preservados y determinismo de json_encode.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): json_encode nativo; sin librerías.
 *   - Artículo V: claves en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_vestibule_dtos.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
$dtosRequired = [
    'VestibuleStateDto' => __DIR__ . '/../src/Dto/VestibuleStateDto.php',
    'VestibuleClanDto'  => __DIR__ . '/../src/Dto/VestibuleClanDto.php',
    'ClanPetitionDto'   => __DIR__ . '/../src/Dto/ClanPetitionDto.php',
];

foreach ($dtosRequired as $dtoName => $dtoPath) {
    if (!is_file($dtoPath)) {
        fwrite(STDERR, "[FATAL] Falta src/Dto/{$dtoName}.php — fase roja: aún no existe.\n");
        exit(1);
    }
}

require_once __DIR__ . '/../src/Dto/ClanDto.php';
require_once __DIR__ . '/../src/Dto/ClanApplicationDto.php';
require_once __DIR__ . '/../src/Dto/ClanPetitionDto.php';
require_once __DIR__ . '/../src/Dto/VestibuleClanDto.php';
require_once __DIR__ . '/../src/Dto/VestibuleStateDto.php';

use Grimorio\Dto\ClanPetitionDto;
use Grimorio\Dto\VestibuleClanDto;
use Grimorio\Dto\VestibuleStateDto;

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  [PASA] {$description}\n";
        return;
    }
    ++$failed;
    echo "  [FALLA] {$description}\n";
}

/** Coteja las llaves raíz del JSON serializado con el orden canónico exacto. */
function assertJsonContract(object $dto, array $expectedKeys, string $description): void
{
    $decoded = json_decode(json_encode($dto), true);
    assertCondition(is_array($decoded), "{$description} — el DTO serializa a array");
    assertCondition(array_keys($decoded) === $expectedKeys, "{$description} — llaves y orden exactos del plan");
}

echo "== Fase 0: Superficie de los ficheros ==\n";
foreach ($dtosRequired as $dtoName => $dtoPath) {
    $source = (string) file_get_contents($dtoPath);
    assertCondition(str_contains($source, 'declare(strict_types=1);'), "{$dtoName} declara tipado estricto");
    assertCondition(str_contains($source, 'final readonly class'), "{$dtoName} es final readonly");
    assertCondition(str_contains($source, 'implements JsonSerializable'), "{$dtoName} implementa JsonSerializable");
}

echo "== Fase 1: Arquitectura (Artículo V) ==\n";
$petition = new ClanPetitionDto(
    applicationId: 'app_9f2c1',
    clanId: 'cln_tempestad',
    clanName: 'Tempestad Eterna',
    status: ClanPetitionDto::STATUS_PENDING,
    motivation: 'Sirvo desde hace años a la causa de la casa.',
    verdictMotive: null,
    verdictSeenAt: null,
    createdAt: '2026-09-20T10:15:00Z',
);
$clan = new VestibuleClanDto(
    clanId: 'cln_mareas',
    name: 'Mareas de Aether',
    motto: 'Donde la marea manda',
    coatOfArms: 'cln_mareas',
    lineageType: 'celestialTides',
    memberCount: 12,
    memberLimit: 30,
    admissionMode: 'open',
    isRegent: false,
    adeptRelation: VestibuleClanDto::RELATION_NONE,
    gesture: VestibuleClanDto::GESTURE_JOIN,
    vedadoLegend: null,
    petitionId: null,
);
$state = new VestibuleStateDto(
    lineage: 'celestialTides',
    lineageLabel: 'Mareas Celestiales',
    membershipClanId: null,
    membershipClanName: null,
    isApt: true,
    vedado: null,
    convalescenceDaysRemaining: 0,
    pendingPetitionsCount: 1,
    pendingPetitionsLimit: 3,
    unreadVerdictsCount: 2,
    myHouse: null,
    clans: [$clan],
    petitions: [$petition],
);
assertCondition($petition instanceof JsonSerializable, 'ClanPetitionDto serializable');
assertCondition($clan instanceof JsonSerializable, 'VestibuleClanDto serializable');
assertCondition($state instanceof JsonSerializable, 'VestibuleStateDto serializable');

echo "== Fase 2: ClanPetitionDto — inventario consolidado (RF-03.8) ==\n";
assertJsonContract(
    $petition,
    ['applicationId', 'clanId', 'clanName', 'status', 'motivation', 'verdictMotive', 'verdictSeen', 'createdAt'],
    'Petición pendiente'
);
$petitionData = json_decode(json_encode($petition), true);
assertCondition($petitionData['verdictSeen'] === false, 'verdictSeen deriva false de verdictSeenAt nulo');
assertCondition($petitionData['verdictMotive'] === null, 'verdictMotive nulo canónico en pendiente');

$rejected = new ClanPetitionDto(
    applicationId: 'app_7a1',
    clanId: 'cln_tempestad',
    clanName: 'Tempestad Eterna',
    status: ClanPetitionDto::STATUS_REJECTED,
    motivation: 'Pido ingresar para servir a la casa.',
    verdictMotive: 'La casa guarda luto por su Fundador.',
    verdictSeenAt: '2026-09-20T13:30:00Z',
    createdAt: '2026-09-19T09:00:00Z',
);
$rejectedData = json_decode(json_encode($rejected), true);
assertCondition($rejectedData['verdictSeen'] === true, 'verdictSeen deriva true del contemplado (RF-03.4)');
assertCondition($rejectedData['verdictMotive'] === 'La casa guarda luto por su Fundador.', 'verdictMotive viaja íntegro en el rechazo');
$rowTranslated = ClanPetitionDto::fromDatabaseRow([
    'id' => 'app_row1',
    'clan_id' => 'cln_tempestad',
    'clan_name' => 'Tempestad Eterna',
    'status' => 'approved',
    'motivation' => 'Ruego un lugar entre los vuestros.',
    'verdict_motive' => null,
    'verdict_seen_at' => '2026-09-20T13:30:00Z',
    'created_at' => '2026-09-18T08:00:00Z',
]);
assertCondition($rowTranslated->jsonSerialize()['applicationId'] === 'app_row1', 'fromDatabaseRow traduce snake_case a camelCase');
assertCondition($rowTranslated->jsonSerialize()['status'] === 'approved', 'fromDatabaseRow respeta el estado aprobado');

echo "== Fase 3: VestibuleClanDto — casa del catálogo (RF-01.3) ==\n";
assertJsonContract(
    $clan,
    ['clanId', 'name', 'motto', 'coatOfArms', 'lineageType', 'memberCount', 'memberLimit', 'admissionMode', 'admissionModeLabel', 'isRegent', 'adeptRelation', 'gesture', 'vedadoLegend', 'petitionId'],
    'Casa abierta sin relación'
);
$clanData = json_decode(json_encode($clan), true);
assertCondition($clanData['admissionModeLabel'] === 'Admisión abierta', 'rótulo castellano del régimen abierto');
assertCondition($clanData['vedadoLegend'] === null, 'leyenda nula cuando el gesto procede');
assertCondition($clanData['petitionId'] === null, 'petitionId nulo canónico sin petición pendiente');

$byApplication = new VestibuleClanDto(
    clanId: 'cln_tempestad',
    name: 'Tempestad Eterna',
    motto: 'El trueno deliberará',
    coatOfArms: 'cln_tempestad',
    lineageType: 'celestialTides',
    memberCount: 30,
    memberLimit: 30,
    admissionMode: 'byApplication',
    isRegent: true,
    adeptRelation: VestibuleClanDto::RELATION_PENDING,
    gesture: VestibuleClanDto::GESTURE_WITHDRAW,
    vedadoLegend: null,
    petitionId: 'app_9f2c1',
);
$byApplicationData = json_decode(json_encode($byApplication), true);
assertCondition($byApplicationData['admissionModeLabel'] === 'Requiere petición formal', 'rótulo castellano del régimen de petición');
assertCondition($byApplicationData['isRegent'] === true, 'corona del Clan Regente portada en el DTO');
assertCondition($byApplicationData['petitionId'] === 'app_9f2c1', 'petitionId viaja con el gesto de retirada');

$vedado = new VestibuleClanDto(
    clanId: 'cln_llama',
    name: 'Llama Primordial',
    admissionMode: 'open',
    adeptRelation: VestibuleClanDto::RELATION_NONE,
    gesture: null,
    vedadoLegend: 'La hermandad ha alcanzado su plenitud de 30 hermanos.',
);
$vedadoData = json_decode(json_encode($vedado), true);
assertCondition($vedadoData['gesture'] === null && str_contains((string) $vedadoData['vedadoLegend'], 'plenitud'), 'gesto vedado con su leyenda solemne');

echo "== Fase 4: VestibuleStateDto — el sobre único (RNF-04) ==\n";
$stateJson = json_encode($state);
$stateData = json_decode((string) $stateJson, true);
assertCondition(array_keys($stateData) === ['adeptState', 'myHouse', 'clans', 'petitions'], 'llaves raíz del sobre único en orden canónico');
assertCondition(array_keys($stateData['adeptState']) === ['lineage', 'lineageLabel', 'membership', 'aptitude', 'unreadVerdictsCount'], 'adeptState con sus llaves canónicas');
assertCondition(array_keys($stateData['adeptState']['aptitude']) === ['isApt', 'vedado', 'convalescenceDaysRemaining', 'pendingPetitionsCount', 'pendingPetitionsLimit'], 'aptitude con sus llaves canónicas (plan §3.1)');
assertCondition($stateData['myHouse'] === null, 'myHouse nulo canónico para el linajado común');
assertCondition($stateData['adeptState']['membership'] === null, 'membership nulo canónico sin casa propia');
assertCondition($stateData['adeptState']['unreadVerdictsCount'] === 2, 'unreadVerdictsCount servido para el rótulo (RF-01.1)');
assertCondition($stateData['clans'][0]['clanId'] === 'cln_mareas', 'catálogo anidado con casas del linaje');
assertCondition($stateData['petitions'][0]['applicationId'] === 'app_9f2c1', 'inventario anidado con peticiones propias');
assertCondition(VestibuleStateDto::PENDING_PETITIONS_LIMIT === 3, 'tope de pendientes heredado de la fuente única (3)');

$legacy = new VestibuleStateDto(
    lineage: 'celestialTides',
    lineageLabel: 'Mareas Celestiales',
    membershipClanId: 'cln_llama',
    membershipClanName: 'Llama Primordial',
    isApt: false,
    vedado: 'loyalty',
    myHouse: ['clanId' => 'cln_llama', 'clanName' => 'Llama Primordial', 'isLegacyDivergent' => true, 'state' => 'active'],
);
$legacyData = json_decode(json_encode($legacy), true);
assertCondition($legacyData['myHouse']['isLegacyDivergent'] === true, 'casa legada divergente como entrada única especial (RF-01.2)');
assertCondition($legacyData['adeptState']['aptitude']['vedado'] === 'loyalty', 'vedado de lealtad empeñada en la aptitud');
assertCondition($legacyData['adeptState']['aptitude']['isApt'] === false, 'el militante jamás es apto');

$convalescing = new VestibuleStateDto(
    lineage: 'celestialTides',
    lineageLabel: 'Mareas Celestiales',
    isApt: false,
    vedado: 'convalescence',
    convalescenceDaysRemaining: 3,
);
$convalescingData = json_decode(json_encode($convalescing), true);
assertCondition($convalescingData['adeptState']['aptitude']['convalescenceDaysRemaining'] === 3, 'días de convalecencia con alza al entero superior (plan §3.1)');

echo "== Fase 5: Guardas de canon ==\n";
$guardPassed = false;
try {
    new VestibuleStateDto(lineage: 'celestialTides', lineageLabel: 'Mareas Celestiales', isApt: true, vedado: 'loyalty');
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'un adepto apto jamás porta causa de vedado');

$guardPassed = false;
try {
    new VestibuleStateDto(lineage: 'celestialTides', lineageLabel: 'Mareas Celestiales', isApt: false, vedado: null);
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'la aptitud negada exige su causa solemne');

$guardPassed = false;
try {
    new VestibuleClanDto(clanId: 'cln_x', name: 'Casa X', gesture: null, vedadoLegend: null);
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'un gesto vedado exige su leyenda solemne (RF-03.5)');

$guardPassed = false;
try {
    new VestibuleClanDto(clanId: 'cln_x', name: 'Casa X', gesture: VestibuleClanDto::GESTURE_JOIN, vedadoLegend: 'No procede');
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'un gesto disponible jamás porta leyenda vedada');

$guardPassed = false;
try {
    new VestibuleClanDto(clanId: 'cln_x', name: 'Casa X', admissionMode: 'secret');
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'régimen de admisión ajeno al canon rechazado');

$guardPassed = false;
try {
    new ClanPetitionDto(applicationId: 'app_x', clanId: 'cln_x', status: 'awaiting');
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'estado de petición ajeno al canon rechazado');

$guardPassed = false;
try {
    new VestibuleStateDto(clans: [new stdClass()]);
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'catálogo con tipo extraño rechazado');

$guardPassed = false;
try {
    new VestibuleStateDto(petitions: ['app_9f2c1']);
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'inventario con tipo extraño rechazado');

$guardPassed = false;
try {
    new VestibuleClanDto(clanId: '   ', name: 'Casa sin sello');
} catch (InvalidArgumentException) {
    $guardPassed = true;
}
assertCondition($guardPassed, 'casa sin identificador rechazada');

echo "== Fase 6: Nulos canónicos y determinismo ==\n";
assertCondition(json_encode($state) === json_encode($state), 'json_encode determinista (Dogma Vanilla)');
$secondRender = json_decode(json_encode($state), true);
assertCondition($secondRender === $stateData, 'segunda serialización idéntica byte a byte');

echo "\nVeredicto: {$passed} PASA / {$failed} ROJOS\n";
exit($failed === 0 ? 0 : 1);

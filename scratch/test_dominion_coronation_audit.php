<?php

/**
 * test_dominion_coronation_audit.php — Arnés del asiento de coronación del
 * Dominio Semanal en la Bitácora pública (SPEC-07, RF-04.2 + RNF-04, hueco
 * gris C).
 *
 * El arnés del servicio comprueba la FILA en audit_log; este fija la cadena
 * completa que la comunidad contempla:
 *
 *   [1] La coronación deja su asiento canónico: DOMINION_WEEK_CONCLUDED,
 *       inscrito por «El Santuario» (rol system, jamás una pluma), con la
 *       casa como entidad objetivo y crónica en noble castellano que nombra
 *       campeón, gloria y conjuros sellados.
 *   [2] El asiento viaja por el endpoint PÚBLICO /audit/log: la comunidad
 *       contempla la coronación sin vínculo arcano y la vista la rotula.
 *   [3] La idempotencia del corte (RNF-01): un segundo latido sobre la
 *       misma semana NO duplica el asiento — la historia no se reescribe.
 *   [4] La corona cambia de manos: cada semana coronada genera SU asiento,
 *       y la crónica de cada uno nombra a su campeón.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo sobre sqlite::memory:.
 *   - Artículo III: la Bitácora es imborrable y pública.
 *   - Artículo V: identificadores en inglés camelCase, asertos en castellano.
 *
 * Ejecución: php scratch/test_dominion_coronation_audit.php  (exit 0 = verde)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Services\AuditService;
use Grimorio\Services\WeeklyDominionService;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];

function assert_throne(bool $condition, string $label): void
{
    global $assertionsPassed, $assertionsFailed, $failures;
    if ($condition) {
        $assertionsPassed++;
        echo "  [OK]    {$label}\n";
        return;
    }
    $assertionsFailed++;
    $failures[] = $label;
    echo "  [FALLA] {$label}\n";
}

/** Despacha por el router de producción (buildRouter). */
function dispatch_throne(string $method, string $uri, array $headers = []): object
{
    global $router;
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);

    return $router->dispatch(new Request($method, $path, [], $headers));
}

/** Sobre JSON decodificado de una Response. */
function payload_throne(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

/** Inscribe un mago sin linaje. */
function throneSeedUser(PDO $pdo, string $userId, string $alias): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', \'editor\', :now, :now)'
    )->execute([
        ':id'    => $userId,
        ':alias' => $alias,
        ':email' => strtolower($userId) . '@arcano.arc',
        ':now'   => '2026-01-01T00:00:00Z',
    ]);
}

/** Forja una casa activa con la gloria semanal indicada. */
function throneSeedClan(PDO $pdo, string $clanId, string $name, int $weeklyPoints): void
{
    $patriarchId = 'usr_patriarch_' . $clanId;
    throneSeedUser($pdo, $patriarchId, 'Patriarca de ' . $name);

    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, :weeklyPoints, 0, :now, :now)'
    )->execute([
        ':id'            => $clanId,
        ':slug'          => strtolower(str_replace('_', '-', $clanId)),
        ':name'          => $name,
        ':motto'         => 'Lema de ' . $name,
        ':now'           => '2026-01-01T00:00:00Z',
        ':coatOfArms'    => 'rune_' . $clanId,
        ':lineageType'   => 'primordialFlame',
        ':admissionMode' => 'open',
        ':status'        => 'active',
        ':patriarchId'   => $patriarchId,
        ':weeklyPoints'  => $weeklyPoints,
        ':now'           => '2026-01-01T00:00:00Z',
    ]);
}

/** Las filas de coronación inscritas en la Bitácora, de reciente a antigua. */
function throneEntries(PDO $pdo): array
{
    return $pdo->query(
        "SELECT actor_user_id, actor_alias, actor_role, action_type, target_entity_type,
                target_entity_id, justification, created_at
           FROM audit_log
          WHERE action_type = 'DOMINION_WEEK_CONCLUDED'
          ORDER BY created_at DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// =====================================================================
// Preparación: pila de producción sobre memoria efímera
// =====================================================================
echo "== ARNÉS DEL ASIENTO DE CORONACIÓN EN LA BITÁCORA PÚBLICA (RF-04.2 + RNF-04) ==\n";

$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();
$dominionService = new WeeklyDominionService($pdo, new AuditService($pdo));

/** El domingo que cierra la semana de un instante (réplica del canon). */
$sundayOf = static function (DateTimeImmutable $instant): DateTimeImmutable {
    return $instant->modify('last sunday')->setTime(23, 59, 59);
};

// =====================================================================
// [1] La coronación deja su asiento canónico
// =====================================================================
echo "\n[1] El asiento canónico de la primera coronación\n";

throneSeedClan($pdo, 'cln_ember', 'Casa Ascua', 180);
throneSeedClan($pdo, 'cln_mist', 'Casa Bruma', 60);

$firstSunday = ($sundayOf)(new DateTimeImmutable('now', new DateTimeZone('UTC')));
$firstCycle = $dominionService->closeWeeklyCycle($firstSunday);
assert_throne($firstCycle !== null && $firstCycle->regentClanId === 'cln_ember', 'La primera coronación ciñe la corona a la casa de mayor PDA');

$entries = throneEntries($pdo);
assert_throne(count($entries) === 1, 'La coronación deja EXACTAMENTE un asiento en la Bitácora');

$entry = $entries[0] ?? [];
assert_throne(($entry['action_type'] ?? '') === 'DOMINION_WEEK_CONCLUDED', 'El acto inscrito es DOMINION_WEEK_CONCLUDED (catálogo canónico, jamás inventado)');
assert_throne(($entry['actor_user_id'] ?? '') === 'sys_santuario' && ($entry['actor_role'] ?? '') === 'system', 'El asiento se inscribe como acto del SANTUARIO (rol system, no una pluma)');
assert_throne(($entry['actor_alias'] ?? '') === 'El Santuario', 'El actor firma con su nombre solemne «El Santuario»');
assert_throne(($entry['target_entity_type'] ?? '') === 'clan' && ($entry['target_entity_id'] ?? '') === 'cln_ember', 'La casa coronada es la entidad objetivo del asiento');
assert_throne(
    str_contains((string) ($entry['justification'] ?? ''), 'Casa Ascua')
        && str_contains((string) ($entry['justification'] ?? ''), '180'),
        'La crónica nombra campeón y gloria en noble castellano',
);

// =====================================================================
// [2] El asiento viaja por el endpoint público /audit/log
// =====================================================================
echo "\n[2] La comunidad contempla la coronación en la Bitácora pública\n";

$logResponse = dispatch_throne('GET', '/api/v1/audit/log?limit=10');
$logBody = payload_throne($logResponse);
assert_throne((int) $logResponse->getStatusCode() === 200 && ($logBody['success'] ?? false) === true, 'El endpoint público /audit/log responde 200 sin vínculo arcano (RNF-04)');

$publicItems = $logBody['data']['items'] ?? [];
$coronationItem = null;
foreach ($publicItems as $item) {
    if (($item['actionType'] ?? '') === 'DOMINION_WEEK_CONCLUDED') {
        $coronationItem = $item;
        break;
    }
}
assert_throne($coronationItem !== null, 'El asiento de coronación es visible en la Bitácora pública');
assert_throne(
    $coronationItem !== null
    && isset($coronationItem['actorAlias'], $coronationItem['actorRole'], $coronationItem['targetEntityType'], $coronationItem['targetEntityId'], $coronationItem['justification'], $coronationItem['createdAt']),
    'El asiento público porta el contrato camelCase completo de la Bitácora',
);
assert_throne(
    $coronationItem !== null && str_contains((string) ($coronationItem['justification'] ?? ''), 'Casa Ascua'),
    'La crónica pública nombra al campeón coronado',
);

// =====================================================================
// [3] La idempotencia: la historia no se reescribe
// =====================================================================
echo "\n[3] Un segundo latido sobre la misma semana jamás duplica el asiento\n";

$repeatCycle = $dominionService->closeWeeklyCycle($firstSunday);
assert_throne($repeatCycle !== null, 'El segundo latido devuelve el acta ya inmortalizada (idempotencia RNF-01)');
assert_throne($repeatCycle->regentClanId === 'cln_ember', 'El acta repetida nombra al mismo campeón');
assert_throne(count(throneEntries($pdo)) === 1, 'El asiento NO se duplica: una semana, una coronación, una crónica');

// =====================================================================
// [4] La corona cambia de manos: cada semana escribe su crónica
// =====================================================================
echo "\n[4] La segunda semana inscribe su propio asiento\n";

$secondSunday = $firstSunday->modify('+7 days');
throneSeedClan($pdo, 'cln_frost', 'Casa Escarcha', 240);
$secondCycle = $dominionService->closeWeeklyCycle($secondSunday);
assert_throne($secondCycle !== null && $secondCycle->regentClanId === 'cln_frost', 'La segunda semana corona a la nueva contendiente');

$entries = throneEntries($pdo);
assert_throne(count($entries) === 2, 'Dos semanas coronadas: dos asientos, cada uno con su crónica');
assert_throne(
    ($entries[0]['target_entity_id'] ?? '') === 'cln_frost' && str_contains((string) ($entries[0]['justification'] ?? ''), 'Casa Escarcha'),
    'El asiento más reciente narra a la campeón vigente',
);
assert_throne(
    ($entries[1]['target_entity_id'] ?? '') === 'cln_ember',
    'Y el asiento previo conserva intacta la memoria de la primera corona',
);
assert_throne(
    (string) ($entries[1]['justification'] ?? '') !== (string) ($entries[0]['justification'] ?? ''),
    'Cada crónica es propia: la Bitácora jamás repite su historia',
);

// =====================================================================
// Resumen
// =====================================================================
echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "RESULTADO: DENEGADO — la coronación no deja su memoria completa en la Bitácora.\n";
    exit(1);
}
echo "RESULTADO: EXITO — La coronación semanal queda asentada en la Bitácora pública (RNF-04).\n";
exit(0);

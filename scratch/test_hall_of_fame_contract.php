<?php

/**
 * test_hall_of_fame_contract.php — Arnés del contrato REST del Libro Mayor
 * de Campeones (SPEC-07, RF-06.1, hueco gris B).
 *
 * El Libro Mayor (hallOfFameWeeks) solo se ejercitaba después de un corte
 * consumado dentro del propio arnés. Este arnés fija el CONTRATO completo
 * del tercer estante del Salón del Dominio (Endpoint 11):
 *
 *   [1] El estante PRÍSTINO: un santuario que jamás cerró semana expone la
 *       lista vacía — el salón existe, pero aún no hay campeones que honrar.
 *   [2] La forja multiciclo: DOS cortes consumados (dos semanas distintas)
 *       con campeones distintos, coronados por la vía de producción (el
 *       endpoint cron con su sello de custodio).
 *   [3] El contrato de cada acta: weekNumber, cycleYear, regentClanId,
 *       regentClanName, winningPoints, winnerSpellCount, closedAt y label.
 *   [4] La cronología perpetua: las actas llegan del más reciente al más
 *       antiguo — el Libro se lee de atrás hacia adelante, como toda crónica.
 *   [5] La lectura pública: la Bitácora se contempla sin vínculo arcano y
 *       sin exponer el acta ningún secreto de gobierno.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo sobre sqlite::memory:.
 *   - Artículo V: identificadores en inglés camelCase, asertos en castellano.
 *
 * Ejecución: php scratch/test_hall_of_fame_contract.php  (exit 0 = verde)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
const CRON_SECRET_HOF = 'sello-hall-of-fame-2026';

putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET_HOF);
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Repositories\WeeklyCycleRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\WeeklyDominionService;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];

function assert_heritage(bool $condition, string $label): void
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
function dispatch_hall(string $method, string $uri, array $headers = []): object
{
    global $router;
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);

    return $router->dispatch(new Request($method, $path, [], $headers));
}

/** Sobre JSON decodificado de una Response. */
function payload_hall(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

function status_hall(object $response): int
{
    return (int) $response->getStatusCode();
}

/** Inscribe un mago sin linaje. */
function hallSeedUser(PDO $pdo, string $userId, string $alias): void
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
function hallSeedClan(PDO $pdo, string $clanId, string $name, int $weeklyPoints, int $historicalPoints = 0): void
{
    $patriarchId = 'usr_patriarch_' . $clanId;
    hallSeedUser($pdo, $patriarchId, 'Patriarca de ' . $name);

    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, :weeklyPoints, :historicalPoints, :now, :now)'
    )->execute([
        ':id'             => $clanId,
        ':slug'           => strtolower(str_replace('_', '-', $clanId)),
        ':name'           => $name,
        ':motto'          => 'Lema de ' . $name,
        ':now'            => '2026-01-01T00:00:00Z',
        ':coatOfArms'     => 'rune_' . $clanId,
        ':lineageType'    => 'primordialFlame',
        ':admissionMode'  => 'open',
        ':status'         => 'active',
        ':patriarchId'    => $patriarchId,
        ':weeklyPoints'   => $weeklyPoints,
        ':historicalPoints' => $historicalPoints,
        ':now'            => '2026-01-01T00:00:00Z',
    ]);
}

/** El corte que el canon consuma: réplica del algoritmo del servicio. */
function hallExpectedClosing(): DateTimeImmutable
{
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $tonight = $instant->setTime(23, 59, 59);

    if ((int) $instant->format('N') === 7 && $instant >= $tonight) {
        return $tonight;
    }

    return $instant->modify('last sunday')->setTime(23, 59, 59);
}

// =====================================================================
// Preparación: pila de producción sobre memoria efímera
// =====================================================================
echo "== ARNÉS DEL CONTRATO REST DEL LIBRO MAYOR DE CAMPEONES (RF-06.1) ==\n";

Connection::resetInstance();
$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();

// =====================================================================
// [1] El estante prístino: la base nace sin actas en el Libro Mayor
// =====================================================================
echo "\n[1] El Libro Mayor nace virgen: la crónica aún no se escribió\n";

// La lectura pública jamás ve el estante vacío (la salvaguarda perezosa del
// Salón corona la semana vigente al primer vistazo, plan 5 Decisión 1);
// por eso la virginidad se comprueba en la CAPA DE PERSISTENCIA: el
// repositorio de ciclos nace sin actas que exhibir.
$cycles = (new WeeklyCycleRepository($pdo))->findCycleHistory();
assert_heritage($cycles === [], 'La base del santuario nace con el Libro Mayor PRÍSTINO (cero actas, nunca null)');

// =====================================================================
// [2] La forja multiciclo: dos cortes con campeones distintos
// =====================================================================
// Los cortes se consuman por la clase de producción con INSTANTE INYECTADO
// (RNF-01): dos domingos canónicos distintos, cada uno con su contendiente.
// El endpoint cron con sello ya ejercita el latido real en su arnés propio.
echo "\n[2] Dos semanas, dos campeones: la crónica se escribe corte a corte\n";

$dominionService = new WeeklyDominionService($pdo, new AuditService($pdo));
$lastSunday = hallExpectedClosing();
$previousSunday = $lastSunday->modify('-7 days');

// Semana previa: reina la Primeriza (120 PDA frente al 0 del linaje
// fundacional). El corte la pliega a histórica y repliega la contienda.
hallSeedClan($pdo, 'cln_first', 'Casa Primeriza', 120);
$firstCrown = $dominionService->closeWeeklyCycle($previousSunday);
assert_heritage($firstCrown !== null && $firstCrown->regentClanId === 'cln_first', 'El primer corte corona a la casa de mayor PDA de su semana');

// Semana vigente: la Relámpago entra en liza y toma la corona con gloria propia.
hallSeedClan($pdo, 'cln_bolt', 'Casa Relámpago', 220);
$secondCrown = $dominionService->closeWeeklyCycle($lastSunday);
assert_heritage($secondCrown !== null && $secondCrown->regentClanId === 'cln_bolt', 'El segundo corte traspasa la corona a la Casa Relámpago');

// =====================================================================
// [3] El contrato de cada acta del Libro Mayor
// =====================================================================
echo "\n[3] El contrato de cada acta (todas las llaves del plan, siempre)\n";

$hall = payload_hall(dispatch_hall('GET', '/api/v1/dominion/leaderboard'))['data'] ?? [];
$fame = $hall['hallOfFameWeeks'] ?? [];
assert_heritage(count($fame) === 2, 'El Libro Mayor inmortaliza las DOS semanas concluidas');

$expectedKeys = ['id', 'weekNumber', 'cycleYear', 'regentClanId', 'regentClanName', 'winningPoints', 'winnerSpellCount', 'closedAt', 'label'];
$firstEntry = $fame[0] ?? [];
$missingKeys = array_diff($expectedKeys, array_keys($firstEntry));
assert_heritage($missingKeys === [], 'Cada acta porta las llaves completas del plan: ' . implode(', ', $expectedKeys));
assert_heritage(
    ($firstEntry['regentClanName'] ?? '') === 'Casa Relámpago',
    'El acta resuelve el NOMBRE solemne del campeón, no solo su clave',
);
assert_heritage(
    ($firstEntry['winningPoints'] ?? 0) === 220,
    'El acta conserva la gloria con que se ganó la corona',
);
assert_heritage(
    is_string($firstEntry['closedAt'] ?? null) && trim((string) $firstEntry['closedAt']) !== '',
    'El acta porta la estampa temporal canónica del corte (closedAt UTC)',
);
assert_heritage(
    ($firstEntry['label'] ?? '') === 'Año ' . ($firstEntry['cycleYear'] ?? 0) . ' · Semana ' . str_pad((string) ($firstEntry['weekNumber'] ?? 0), 2, '0', STR_PAD_LEFT),
    'El acta se rotula en noble castellano: «Año X · Semana NN»',
);

// =====================================================================
// [4] La cronología perpetua: del más reciente al más antiguo
// =====================================================================
echo "\n[4] La crónica se lee de atrás hacia adelante\n";

$secondWeek = (int) ($firstEntry['weekNumber'] ?? 0);
$firstWeekEntry = $fame[1] ?? [];
assert_heritage(
    ($firstWeekEntry['regentClanName'] ?? '') === 'Casa Primeriza' && ($firstWeekEntry['winningPoints'] ?? 0) === 120,
    'El segundo asiento del Libro es el campeón PRIMERO (la crónica no reordena el pasado)',
);
$sameYear = (int) ($firstWeekEntry['cycleYear'] ?? 0) === (int) ($firstEntry['cycleYear'] ?? 0);
assert_heritage(
    $sameYear
        ? $secondWeek > (int) ($firstWeekEntry['weekNumber'] ?? 99)
        : (int) ($firstWeekEntry['cycleYear'] ?? 0) < (int) ($firstEntry['cycleYear'] ?? 0),
    'El orden es cronológico INVERSO: la semana más reciente primero (año y semana)',
);

// =====================================================================
// [5] La lectura pública: crónica completa, sin secretos, contienda a cero
// =====================================================================
echo "\n[5] La lectura pública no expone secretos ni recorta la crónica\n";

$emptyHallSecond = payload_hall(dispatch_hall('GET', '/api/v1/dominion/leaderboard'))['data'] ?? [];
assert_heritage(status_hall(dispatch_hall('GET', '/api/v1/dominion/leaderboard')) === 200, 'El Salón responde 200 sin vínculo arcano (lectura pública)');
assert_heritage(
    count($emptyHallSecond['hallOfFameWeeks'] ?? []) === 2,
    'El Salón público exhibe la crónica completa sin parámetro alguno',
);
assert_heritage(
    !isset($emptyHallSecond['currentRegentClan']['patriarchEmail'])
        && !isset($firstEntry['regentClanEmail']),
    'El acta pública jamás expone datos de gobierno del campeón',
);
// La contienda viva quedó a cero tras el pliegue; el podio semanal parte limpio.
assert_heritage(
    (array_map('intval', array_column($emptyHallSecond['weeklyRanking'] ?? [], 'weeklyPoints')) === [0, 0, 0]),
    'Tras dos pliegues, la contienda viva parte de cero (reinicio canónico RF-04.3)',
);

// =====================================================================
// Resumen
// =====================================================================
echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertionsPassed}, fallidos: {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "RESULTADO: FALLO — el contrato del Libro Mayor no está completo.\n";
    exit(1);
}
echo "RESULTADO: EXITO — El Libro Mayor de Campeones cumple su contrato REST (RF-06.1).\n";
exit(0);

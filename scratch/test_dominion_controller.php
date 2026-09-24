<?php

/**
 * test_dominion_controller.php — Arnés TDD de la Tarea 3.3 (TASKS-07).
 *
 * Verifica los Endpoints 11 y 12 del plan 2.2 por la pila REAL de producción
 * (se carga public/index.php y se despacha por buildRouter()), más un cierre
 * de integración por HTTP con `php -S`:
 *
 *   - GET  /api/v1/dominion/leaderboard      → Salón del Dominio con sus CUATRO
 *     secciones: podio semanal ordenado por PDA descendente, prestigio
 *     histórico, Clan Regente vigente y Libro Mayor de Campeones.
 *   - POST /api/v1/dominion/cron-cycle-close → proclamación y reinicio a cero,
 *     vedado a quien no porte el sello del custodio (401/403).
 *
 * Criterio «Hecho cuando» (Tarea 3.3): la ruta del leaderboard devuelve el
 * podio semanal ordenado descendentemente por PDA y la ruta de cierre
 * dominical ejecuta la proclamación y el reseteo sin errores.
 *
 * Además se comprueba, sobre bases efímeras renovadas por fase:
 *   · la salvaguarda perezosa del corte (plan 5, Decisión 1) servida por el
 *     propio Salón: una semana vencida se corona al primer latido;
 *   · la idempotencia: un segundo corte NO vuelve a plegar la gloria;
 *   · el fallo cerrado cuando el santuario no declaró sello alguno;
 *   · la inscripción del corte en la Bitácora pública (RNF-04);
 *   · la neutralidad del maná (Artículo II): ninguna gloria altera la forja.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos; cero
 *     librerías y cero dependencias npm.
 *   - Artículo V: identificadores camelCase; leyendas en noble castellano.
 *
 * Uso: php scratch/test_dominion_controller.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
const CRON_SECRET = 'sello-del-custodio-2026';

// El sello se declara ANTES de cargar el front controller: el controlador lo
// lee del entorno al construirse (fallo cerrado si falta).
putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET);
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Dto\ClanDto;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Services\AuditService;
use Grimorio\Services\WeeklyDominionService;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];
/** @var list<string> */
$warnings = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warnings): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    $warnings[] = "{$message} (en {$file}:{$line})";
    return true;
});

function assert_truthy(bool $condition, string $label): void
{
    global $assertionsPassed, $assertionsFailed, $failures;
    if ($condition) {
        $assertionsPassed++;
        echo "  [OK]  {$label}\n";
        return;
    }
    $assertionsFailed++;
    $failures[] = $label;
    echo "  [FALLA] {$label}\n";
}

/** Despacha por el router de producción (buildRouter). */
function dispatch(string $method, string $uri, array $headers = []): object
{
    global $router;
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);

    return $router->dispatch(new Request($method, $path, [], $headers));
}

/** Sobre JSON decodificado de una Response. */
function payloadOf(object $response): array
{
    $decoded = json_decode($response->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

function errorCodeOf(object $response): string
{
    return (string) (payloadOf($response)['error']['code'] ?? '');
}

/** Instante UTC canónico del santuario. */
function utcStamp(string $modifier = 'now'): string
{
    return (new DateTimeImmutable($modifier, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/**
 * El corte que el canon consuma: el último domingo de las 23:59:59 UTC ya
 * transcurrido (réplica exacta del algoritmo del servicio, para medirlo).
 */
function expectedClosingInstant(): DateTimeImmutable
{
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $tonight = $instant->setTime(23, 59, 59);

    if ((int) $instant->format('N') === 7 && $instant >= $tonight) {
        return $tonight;
    }

    return $instant->modify('last sunday')->setTime(23, 59, 59);
}

/** Inscribe un mago (sin linaje salvo que se indique). */
function seedUser(\PDO $pdo, string $userId, string $alias, string $role = 'editor', ?string $clanId = null): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :now, :now)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@sanctuario.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':clanId'       => $clanId,
        ':now'          => utcStamp(),
    ]);
}

/** Funda una casa con sus contadores de gloria y su Patriarca militando. */
function seedClan(
    \PDO $pdo,
    string $clanId,
    string $name,
    int $weeklyPoints = 0,
    int $historicalPoints = 0,
    string $status = 'active',
): void {
    $now = utcStamp();
    $patriarchId = 'usr_patriarch_' . $clanId;

    // El tutor se inscribe ANTES que su casa: la clave foránea de
    // `clans.patriarch_id` exige que la cuenta exista ya en `users`.
    seedUser($pdo, $patriarchId, 'Patriarca de ' . $name, 'editor');

    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 :admissionMode, :status, :patriarchId, :weeklyPoints, :historicalPoints, :now, :now)'
    )->execute([
        ':id'               => $clanId,
        ':slug'             => strtolower(str_replace('_', '-', $clanId)),
        ':name'             => $name,
        ':motto'            => 'Lema de ' . $name,
        ':now'              => $now,
        ':coatOfArms'       => 'rune_' . $clanId,
        ':lineageType'      => 'primordialFlame',
        ':admissionMode'    => ClanDto::ADMISSION_OPEN,
        ':status'           => $status,
        ':patriarchId'      => $patriarchId,
        ':weeklyPoints'     => $weeklyPoints,
        ':historicalPoints' => $historicalPoints,
    ]);

    // La membresía se asienta en la AUTORIDAD y deja el espejo en su sitio.
    (new ClanMemberRepository($pdo))->addMember('clm_' . $clanId . '_patriarch', $clanId, $patriarchId, 'patriarch', $now);
}

/** Puntos de gloria semanal de una casa, leídos del plano. */
function weeklyPointsOf(\PDO $pdo, string $clanId): int
{
    return (int) $pdo->query("SELECT weekly_points FROM clans WHERE id = '{$clanId}'")->fetchColumn();
}

/** Gloria perpetua de una casa, leída del plano. */
function historicalPointsOf(\PDO $pdo, string $clanId): int
{
    return (int) $pdo->query("SELECT historical_points FROM clans WHERE id = '{$clanId}'")->fetchColumn();
}

/** Reconstruye la pila sobre una base efímera nueva y la devuelve. */
function freshStack(): \PDO
{
    global $router;

    Connection::resetInstance();
    putenv('GRIMORIO_DB_DSN=sqlite::memory:');
    $pdo = Connection::getInstance()->getPdo();
    $router = buildRouter();

    return $pdo;
}

echo "== ARNÉS TDD — DOMINIO SEMANAL: PODIO Y CORTE DOMINICAL (Tarea 3.3, SPEC-07) ==\n";

// =====================================================================
// FASE 0 · Estado heredado: una semana ya proclamada y una contienda viva
// =====================================================================
echo "\n[FASE 0] Preparación: la semana pasada ya fue coronada\n";

$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();

// Contienda de la semana pasada: Alfa 300 PDA, Beta 200, Gamma 100. Beta ya
// porta gloria perpetua anterior, para que el podio histórico no sea idéntico
// al semanal. Reliquia yace disuelta con 900 de gloria: no compite, pero su
// memoria no se pierde.
seedClan($pdo, 'cln_alpha', 'Casa Alfa', 300, 0);
seedClan($pdo, 'cln_beta', 'Casa Beta', 200, 50);
seedClan($pdo, 'cln_gamma', 'Casa Gamma', 100, 0);
seedClan($pdo, 'cln_relic', 'Reliquia Disuelta', 0, 900, 'archived');

$dominionService = new WeeklyDominionService($pdo, new AuditService($pdo));
$crownedLastWeek = $dominionService->closeWeeklyCycle();
assert_truthy($crownedLastWeek !== null, 'El corte de la semana pasada corona a una casa');
assert_truthy(($crownedLastWeek->regentClanId ?? '') === 'cln_alpha', 'La corona recae en la casa de mayor PDA (Alfa)');
assert_truthy(historicalPointsOf($pdo, 'cln_alpha') === 300, 'El pliegue acredita 300 de gloria perpetua a Alfa');

// Contienda viva de la semana en curso, en orden distinto al histórico.
$pdo->exec("UPDATE clans SET weekly_points = 40 WHERE id = 'cln_alpha'");
$pdo->exec("UPDATE clans SET weekly_points = 170 WHERE id = 'cln_beta'");
$pdo->exec("UPDATE clans SET weekly_points = 90 WHERE id = 'cln_gamma'");

// =====================================================================
// FASE 1 · Endpoint 11: el Salón del Dominio completo
// =====================================================================
echo "\n[FASE 1] GET /api/v1/dominion/leaderboard — Salón del Dominio\n";

$hall = dispatch('GET', '/api/v1/dominion/leaderboard');
$hallData = payloadOf($hall)['data'] ?? [];
assert_truthy(statusOf($hall) === 200, 'El Salón del Dominio responde 200 sin vínculo arcano (lectura pública)');
assert_truthy(
    array_keys($hallData) === ['weeklyRanking', 'historicalRanking', 'currentRegentClan', 'hallOfFameWeeks'],
    'El Salón declara EXACTAMENTE las cuatro secciones del plan (Endpoint 11)',
);

$weeklyRanking = $hallData['weeklyRanking'] ?? [];
$podioPoints = array_map('intval', array_column($weeklyRanking, 'weeklyPoints'));
$podioDescending = $podioPoints;
rsort($podioDescending);
assert_truthy($podioPoints === $podioDescending, 'El podio semanal llega ordenado por PDA descendente');
assert_truthy(
    array_values(array_intersect(array_column($weeklyRanking, 'id'), ['cln_alpha', 'cln_beta', 'cln_gamma'])) === ['cln_beta', 'cln_gamma', 'cln_alpha'],
    'Las hermandades del arnés se ordenan por su gloria semanal (170, 90, 40)',
);
assert_truthy(
    array_column(array_filter($weeklyRanking, static fn (array $clan): bool => str_starts_with((string) $clan['id'], 'cln_')), 'weeklyPoints') !== [],
    'El podio porta la gloria semanal de cada estandarte',
);
foreach ($weeklyRanking as $podioClan) {
    $tournamentHouse = in_array($podioClan['id'], ['cln_alpha', 'cln_beta', 'cln_gamma'], true);
    assert_truthy(
        (int) $podioClan['memberCount'] === 1 || !$tournamentHouse,
        "El estandarte «{$podioClan['id']}» porta su censo de adeptos (" . $podioClan['memberCount'] . ')'
    );
}
assert_truthy(
    !in_array('cln_relic', array_column($weeklyRanking, 'id'), true),
    'Una casa disuelta no figura en la contienda viva (RF-05.3)',
);

$historicalRanking = $hallData['historicalRanking'] ?? [];
$legacyPoints = array_map('intval', array_column($historicalRanking, 'historicalPoints'));
$legacyDescending = $legacyPoints;
rsort($legacyDescending);
assert_truthy($legacyPoints === $legacyDescending, 'El prestigio histórico llega ordenado por gloria perpetua descendente');
assert_truthy(
    array_values(array_intersect(array_column($historicalRanking, 'id'), ['cln_alpha', 'cln_beta', 'cln_gamma'])) === ['cln_alpha', 'cln_beta', 'cln_gamma'],
    'El prestigio perpetuo ordena a las casas del arnés (300, 50, 0)',
);
// El corte pliega la gloria SEMANAL de cada casa sobre la suya histórica: el
// campeón (0 + 300) y también las contendientes (Beta 50 + 200; Gamma 0 + 100).
assert_truthy(
    historicalPointsOf($pdo, 'cln_alpha') === 300
    && historicalPointsOf($pdo, 'cln_beta') === 250
    && historicalPointsOf($pdo, 'cln_gamma') === 100,
    'El pliegue acredita a cada casa su gloria semanal sobre la perpetua',
);

$regent = $hallData['currentRegentClan'] ?? null;
assert_truthy(is_array($regent) && ($regent['id'] ?? '') === 'cln_alpha', 'El Clan Regente vigente es el coronado en el último corte');
assert_truthy(($regent['memberCount'] ?? 0) === 1, 'El estandarte del Regente también exhibe su censo');

$fameWeeks = $hallData['hallOfFameWeeks'] ?? [];
assert_truthy(count($fameWeeks) === 1, 'El Libro Mayor porta el corte ya proclamado');
assert_truthy(($fameWeeks[0]['regentClanId'] ?? '') === 'cln_alpha', 'El acta del Libro Mayor nombra a su campeón');
assert_truthy(($fameWeeks[0]['winningPoints'] ?? 0) === 300, 'El acta conserva la gloria de la coronación');
assert_truthy(trim((string) ($fameWeeks[0]['label'] ?? '')) !== '', 'El acta porta su rótulo ceremonial en castellano');
assert_truthy(
    ($fameWeeks[0]['weekNumber'] ?? 0) === (int) expectedClosingInstant()->format('W'),
    'El acta declara la semana ISO del último domingo concluido',
);
assert_truthy(
    ($fameWeeks[0]['closedAt'] ?? '') === expectedClosingInstant()->format('Y-m-d\TH:i:s\Z'),
    'El acta sella el corte a las 23:59:59 UTC del domingo concluido',
);

// La salvaguarda perezosa NO vuelve a plegar una semana ya proclamada.
assert_truthy(historicalPointsOf($pdo, 'cln_alpha') === 300, 'Leer el Salón no duplica la gloria histórica (idempotencia)');
assert_truthy(
    [weeklyPointsOf($pdo, 'cln_beta'), weeklyPointsOf($pdo, 'cln_gamma')] === [170, 90],
    'Leer el Salón no reinicia la contienda en curso',
);

// =====================================================================
// FASE 2 · Endpoint 12: el sello del custodio
// =====================================================================
echo "\n[FASE 2] POST /api/v1/dominion/cron-cycle-close — el sello del custodio\n";

$withoutSeal = dispatch('POST', '/api/v1/dominion/cron-cycle-close');
assert_truthy(statusOf($withoutSeal) === 401, 'Sin sello del custodio, el corte responde 401');
assert_truthy(errorCodeOf($withoutSeal) === 'CRON_SECRET_REQUIRED', 'El 401 porta CRON_SECRET_REQUIRED');
assert_truthy((payloadOf($withoutSeal)['success'] ?? null) === false, 'El sobre del rechazo declara success: false');

$withForeignSeal = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => 'sello-ajeno']);
assert_truthy(statusOf($withForeignSeal) === 403, 'Un sello ajeno responde 403 Forbidden');
assert_truthy(errorCodeOf($withForeignSeal) === 'CRON_SECRET_INVALID', 'El 403 porta CRON_SECRET_INVALID');

$wrongVerb = dispatch('GET', '/api/v1/dominion/cron-cycle-close');
assert_truthy(statusOf($wrongVerb) === 405, 'El corte dominical no admite el verbo de lectura (405)');

// =====================================================================
// FASE 3 · El corte ya proclamado no se repite
// =====================================================================
echo "\n[FASE 3] El segundo latido del cron sobre la misma semana\n";

$sealedClose = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => CRON_SECRET]);
$sealedData = payloadOf($sealedClose)['data'] ?? [];
assert_truthy(statusOf($sealedClose) === 200, 'Con el sello del custodio, el corte responde 200');
assert_truthy(($sealedData['cycle']['id'] ?? '') === ($crownedLastWeek->id ?? ''), 'El segundo latido devuelve el acta ya inmortalizada');
assert_truthy(($sealedData['regentClan']['id'] ?? '') === 'cln_alpha', 'El corte resuelve la casa que ciñe la corona');
assert_truthy(historicalPointsOf($pdo, 'cln_alpha') === 300, 'Un segundo latido NO vuelve a plegar la gloria (idempotencia)');
assert_truthy(weeklyPointsOf($pdo, 'cln_beta') === 170, 'Un segundo latido NO reinicia la contienda viva');

// =====================================================================
// FASE 4 · Proclamación y reinicio servidos por el Endpoint 12
// =====================================================================
echo "\n[FASE 4] POST /api/v1/dominion/cron-cycle-close — proclamación real\n";

$pdo = freshStack();
seedClan($pdo, 'cln_dawn', 'Casa del Alba', 480, 20);
seedClan($pdo, 'cln_dusk', 'Casa del Ocaso', 120, 60);
$closingInstant = expectedClosingInstant();

$proclamation = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => CRON_SECRET]);
$proclamationData = payloadOf($proclamation)['data'] ?? [];
assert_truthy(statusOf($proclamation) === 200, 'El corte dominical se consuma sin errores (200)');
assert_truthy(($proclamationData['cycle']['regentClanId'] ?? '') === 'cln_dawn', 'El acta corona a la casa de mayor PDA');
assert_truthy(($proclamationData['cycle']['winningPoints'] ?? 0) === 480, 'El acta conserva los PDA de la proclamación');
assert_truthy(
    ($proclamationData['cycle']['weekNumber'] ?? 0) === (int) $closingInstant->format('W')
    && ($proclamationData['cycle']['cycleYear'] ?? 0) === (int) $closingInstant->format('o'),
    'El acta declara la semana ISO y el año del domingo concluido',
);
assert_truthy(($proclamationData['regentClan']['id'] ?? '') === 'cln_dawn', 'El nuevo Clan Regente viaja resuelto en la respuesta');
assert_truthy(weeklyPointsOf($pdo, 'cln_dawn') === 0 && weeklyPointsOf($pdo, 'cln_dusk') === 0, 'TODAS las casas reinician su contador semanal a cero');
assert_truthy(historicalPointsOf($pdo, 'cln_dawn') === 500, 'La gloria de la semana se pliega sobre la histórica (20 + 480)');
assert_truthy(historicalPointsOf($pdo, 'cln_dusk') === 180, 'La casa derrotada conserva su gloria (60 + 120)');
assert_truthy(
    (int) $pdo->query('SELECT COUNT(*) FROM weekly_cycles')->fetchColumn() === 1,
    'El Libro Mayor inscribe el corte en el plano',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DOMINION_WEEK_CONCLUDED' AND target_entity_id = 'cln_dawn'")->fetchColumn() === 1,
    'La proclamación queda inscrita en la Bitácora pública (RNF-04)',
);

$afterProclamation = payloadOf(dispatch('GET', '/api/v1/dominion/leaderboard'))['data'] ?? [];
assert_truthy(
    array_sum(array_column($afterProclamation['weeklyRanking'] ?? [], 'weeklyPoints')) === 0,
    'El Salón muestra la contienda recién reiniciada',
);
assert_truthy(($afterProclamation['currentRegentClan']['id'] ?? '') === 'cln_dawn', 'El Salón proclama al nuevo Regente vigente');

$secondBlow = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => CRON_SECRET]);
assert_truthy(statusOf($secondBlow) === 200, 'Un segundo corte sobre la semana proclamada responde 200 (sin errores)');
assert_truthy(historicalPointsOf($pdo, 'cln_dawn') === 500, 'Y no vuelve a plegar la gloria (RNF-01)');

// =====================================================================
// FASE 5 · La salvaguarda perezosa servida por el Salón (plan 5, Decisión 1)
// =====================================================================
echo "\n[FASE 5] El Salón corona una semana vencida si el cron faltó a su cita\n";

$pdo = freshStack();
seedClan($pdo, 'cln_alone', 'Casa Sola', 260, 0);

$lazyHall = payloadOf(dispatch('GET', '/api/v1/dominion/leaderboard'))['data'] ?? [];
assert_truthy(
    ($lazyHall['currentRegentClan']['id'] ?? '') === 'cln_alone',
    'La lectura del Salón corona la semana vencida por sí sola (evaluación perezosa)',
);
assert_truthy(weeklyPointsOf($pdo, 'cln_alone') === 0, 'La salvaguarda perezosa reinicia el contador semanal');
assert_truthy(historicalPointsOf($pdo, 'cln_alone') === 260, 'La salvaguarda perezosa pliega la gloria sobre la histórica');
assert_truthy(
    count($lazyHall['hallOfFameWeeks'] ?? []) === 1,
    'El Libro Mayor registra el corte proclamado de oficio',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'DOMINION_WEEK_CONCLUDED'")->fetchColumn() === 1,
    'El corte perezoso también queda en la Bitácora (RNF-04)',
);

// =====================================================================
// FASE 6 · Casos límite: sin casas activas y sin sello declarado
// =====================================================================
echo "\n[FASE 6] Casos límite del corte\n";

$pdo = freshStack();
$pdo->exec("UPDATE clans SET status = 'archived'");
$noContenders = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => CRON_SECRET]);
$noContendersData = payloadOf($noContenders)['data'] ?? [];
assert_truthy(statusOf($noContenders) === 200, 'Sin casas activas, el corte responde 200 (gesto vacío, jamás error)');
assert_truthy(array_key_exists('cycle', $noContendersData) && $noContendersData['cycle'] === null, 'Sin contendientes no hay acta que inmortalizar');
assert_truthy(array_key_exists('regentClan', $noContendersData) && $noContendersData['regentClan'] === null, 'Y ninguna corona que ceñir');
assert_truthy(
    (int) $pdo->query('SELECT COUNT(*) FROM weekly_cycles')->fetchColumn() === 0,
    'El gesto vacío no inscribe ciclos en el Libro Mayor',
);

// Fallo cerrado: si el santuario no declaró sello, NADIE invoca el corte.
putenv('GRIMORIO_CRON_SECRET');
$router = buildRouter();
$unsealedSantuary = dispatch('POST', '/api/v1/dominion/cron-cycle-close', ['X-Arcane-Cron-Secret' => CRON_SECRET]);
assert_truthy(statusOf($unsealedSantuary) === 403, 'Sin sello declarado en el entorno, el corte falla cerrado (403)');
assert_truthy(errorCodeOf($unsealedSantuary) === 'CRON_SECRET_INVALID', 'El rechazo no delata que el sello no estaba declarado');
putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET);

// =====================================================================
// FASE 7 · Integración REST por HTTP real
// =====================================================================
echo "\n[FASE 7] Integración REST por HTTP real (php -S)\n";

$serverHost = '127.0.0.1';
$serverPort = 8123; // Puerto dedicado de esta verificación (no usado por otras suites).
$serverBaseUrl = "http://{$serverHost}:{$serverPort}";
$tempDbPath = $projectRoot . '/scratch/test_dominion.sqlite';
$serverLogFile = $projectRoot . '/scratch/test_dominion_server.log';

if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}

$seedPdo = new \PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$seedPdo->exec('PRAGMA foreign_keys = ON');
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
seedClan($seedPdo, 'cln_real', 'Casa del Vínculo Real', 330, 0);
$seedPdo = null;

putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);
putenv('GRIMORIO_CRON_SECRET=' . CRON_SECRET);

$serverCommand = sprintf(
    '%s -S %s:%d %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverHost,
    $serverPort,
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($serverLogFile)
);
$serverProcessHandle = popen($serverCommand, 'r');

/**
 * Petición HTTP real (PHP nativo: sin curl).
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
$httpRequest = static function (string $method, string $url, ?string $seal = null, ?string $body = null): array {
    $headers = ['Content-Type: application/json'];
    if ($seal !== null) {
        $headers[] = 'X-Arcane-Cron-Secret: ' . $seal;
    }

    $context = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body ?? '',
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);

    $rawBody = @file_get_contents($url, false, $context);
    if ($rawBody === false) {
        return [0, []];
    }

    $statusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }
    }

    $decoded = json_decode($rawBody, true);

    return [$statusCode, is_array($decoded) ? $decoded : []];
};

$serverReady = false;
for ($attempt = 0; $attempt < 25; $attempt++) {
    [$probeStatus] = $httpRequest('GET', $serverBaseUrl . '/api/v1/dominion/leaderboard');
    if ($probeStatus !== 0) {
        $serverReady = true;
        break;
    }
    usleep(200000);
}
assert_truthy($serverReady, "El servidor nativo responde en {$serverBaseUrl}");

if ($serverReady) {
    [$hallStatus, $hallPayload] = $httpRequest('GET', $serverBaseUrl . '/api/v1/dominion/leaderboard');
    assert_truthy($hallStatus === 200, 'HTTP real: el Salón del Dominio responde 200');
    assert_truthy(
        count($hallPayload['data']['hallOfFameWeeks'] ?? []) === 1,
        'HTTP real: el Salón proclamó de oficio la semana vencida',
    );
    assert_truthy(
        ($hallPayload['data']['currentRegentClan']['id'] ?? '') === 'cln_real',
        'HTTP real: el Regente vigente viaja resuelto',
    );

    [$unsealedStatus, $unsealedPayload] = $httpRequest('POST', $serverBaseUrl . '/api/v1/dominion/cron-cycle-close');
    assert_truthy($unsealedStatus === 401 && ($unsealedPayload['error']['code'] ?? '') === 'CRON_SECRET_REQUIRED', 'HTTP real: sin sello, el corte responde 401');

    [$foreignStatus] = $httpRequest('POST', $serverBaseUrl . '/api/v1/dominion/cron-cycle-close', 'sello-ajeno');
    assert_truthy($foreignStatus === 403, 'HTTP real: un sello ajeno responde 403');

    [$sealedStatus, $sealedPayload] = $httpRequest('POST', $serverBaseUrl . '/api/v1/dominion/cron-cycle-close', CRON_SECRET);
    assert_truthy($sealedStatus === 200, 'HTTP real: con el sello del entorno, el corte responde 200');
    assert_truthy(
        ($sealedPayload['data']['cycle']['regentClanId'] ?? '') === 'cln_real',
        'HTTP real: el acta nombra al Regente de la semana vencida',
    );
}

$cleanupCommand = stripos(PHP_OS_FAMILY, 'WIN') === 0
    ? 'powershell -Command "Get-NetTCPConnection -LocalPort ' . $serverPort . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object { Stop-Process -Id $_ -Force }"'
    : "fuser -k {$serverPort}/tcp 2>/dev/null";
shell_exec($cleanupCommand);
pclose($serverProcessHandle);

if (is_file($tempDbPath)) {
    @unlink($tempDbPath);
}
if (is_file($serverLogFile)) {
    @unlink($serverLogFile);
}
echo "  [OK]  Entorno HTTP efímero cerrado y limpio\n";

// =====================================================================
// FASE 8 · Dogma Vanilla, neutralidad del maná y cero advertencias
// =====================================================================
echo "\n[FASE 8] Dogma Vanilla y neutralidad de la forja\n";

foreach ([
    'DominionController' => $projectRoot . '/src/Controllers/DominionController.php',
    'WeeklyDominionService' => $projectRoot . '/src/Services/WeeklyDominionService.php',
    'DominionHall' => $projectRoot . '/src/Services/DominionHall.php',
] as $classLabel => $filePath) {
    $source = (string) file_get_contents($filePath);
    assert_truthy(
        !str_contains($source, 'vendor/autoload') && !str_contains($source, 'node_modules') && !str_contains($source, 'Composer'),
        "{$classLabel} no invoca dependencia externa alguna (Artículo I)",
    );
}

$controllerSource = (string) file_get_contents($projectRoot . '/src/Controllers/DominionController.php');
assert_truthy(str_contains($controllerSource, 'declare(strict_types=1);'), 'DominionController declara tipado estricto (AGENTS.md 2.1)');
assert_truthy(str_contains($controllerSource, 'hash_equals('), 'El sello del cron se compara en tiempo constante (hash_equals)');
assert_truthy(!str_contains($controllerSource, 'SELECT '), 'El controlador no compone SQL: delega en el servicio');

foreach (['src/Controllers/DominionController.php', 'src/Services/DominionHall.php'] as $relativePath) {
    $openTagLine = 0;
    foreach (file($projectRoot . '/' . $relativePath) ?: [] as $index => $line) {
        if (str_contains($line, 'declare(strict_types=1);')) {
            $openTagLine = $index + 1;
            break;
        }
    }
    assert_truthy($openTagLine > 0 && $openTagLine <= 40, "{$relativePath}: declare(strict_types=1); en las primeras 40 líneas");
}

$warnings = array_values(array_filter($warnings, static fn (string $warning): bool => !str_contains($warning, 'unlink')));
assert_truthy($warnings === [], 'Cero advertencias de PHP en toda la batería');
foreach ($warnings as $warning) {
    echo "    ⚠ {$warning}\n";
}

// ---------------------------------------------------------------------
echo "\n════════════════════════════════════════════════════════════════════\n";
echo "  Asertos superados: {$assertionsPassed} · fallidos: {$assertionsFailed}\n";
if ($assertionsFailed > 0) {
    echo "  Fallos:\n";
    foreach ($failures as $failure) {
        echo "    - {$failure}\n";
    }
    echo "\n  RESULTADO: DENEGADO — la Tarea 3.3 no cumple su criterio «Hecho cuando».\n";
    exit(1);
}

echo "  RESULTADO: EXITO — La Tarea 3.3 cumple su criterio 'Hecho cuando'.\n";
exit(0);

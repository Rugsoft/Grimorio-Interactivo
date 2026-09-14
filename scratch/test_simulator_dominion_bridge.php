<?php

/**
 * test_simulator_dominion_bridge.php — Arnés de la Tarea 7.1 (TASKS-07).
 *
 * Verifica el criterio «Hecho cuando» de la integración cruzada:
 *
 *   «La ejecución de un combo en el simulador acredita puntos al clan del
 *    usuario reflejándose en el ranking semanal en vivo y deteniéndose al
 *    alcanzar el tope diario de 50 PDA.»
 *
 * Se ejerce el Endpoint 14 (`POST /api/v1/dominion/simulator-combo`) por la
 * pila REAL de producción —se carga `public/index.php` y se despacha por
 * `buildRouter()`— contra el PDO canónico sobre SQLite en memoria, con el
 * adepto ya resuelto como lo haría AuthMiddleware (`Request::setUser`).
 *
 * Fases:
 *   [0] Fixtures: hermandades con linaje rector, adeptos y membresías.
 *   [1] Fronteras de la ruta: 401, 400 y 409 (sin hermandad que acreditar).
 *   [2] La gloria del combo: 10 PDA base, 13 con sinergia de linaje (+25%
 *       redondeado), sin bonificación para elementos ajenos al Códice.
 *   [3] El ranking semanal EN VIVO refleja el nuevo marcador (RF-03.1/03.2).
 *   [4] El techo diario de 50 PDA se detiene en seco, con su recibo canónico,
 *       y el marcador de la casa no se mueve un ápice más allá del tope.
 *   [5] El reinicio a las 00:00:00 UTC devuelve la práctica (RF-03.2).
 *   [6] Fronteras y dogmas: espejo rancio (404), payload hostil, contador
 *       único (el simulador no se asienta en `dominion_awards`) y maná intacto.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Request/Response/Router/PDO nativos.
 *   - Artículo II: el techo y los montos los decide el SERVICIO canónico; el
 *     controlador jamás recalcula, y la forja de maná queda intacta.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: php scratch/test_simulator_dominion_bridge.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\Request;
use Grimorio\Database\Connection;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return true;
    }

    echo "  [AVISO] {$message} (en {$file}:{$line})\n";

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

/** Despacha por el router REAL de producción (buildRouter). */
function dispatch(string $method, string $uri, ?User $actor = null, ?string $rawBody = null): object
{
    global $router;
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);
    $request = new Request($method, $path, [], [], $rawBody);
    if ($actor !== null) {
        $request->setUser($actor);
    }

    return $router->dispatch($request);
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

/** Titular consagrado, tal y como lo materializaría AuthMiddleware. */
function actor(PDO $pdo, string $userId): User
{
    $statement = $pdo->prepare(
        'SELECT id, alias, email, password_hash, role, clan_id, created_at, updated_at
           FROM users WHERE id = :userId'
    );
    $statement->execute([':userId' => $userId]);

    return User::fromDatabaseRow($statement->fetch(PDO::FETCH_ASSOC));
}

/** Inscribe un mago en el plano (sin linaje salvo que se indique). */
function seedUser(PDO $pdo, string $userId, string $alias, string $role = 'editor', ?string $clanId = null): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :now, :now)'
    )->execute([
        ':id'           => $userId,
        ':alias'        => $alias,
        ':email'        => $userId . '@santuario.arc',
        ':passwordHash' => str_repeat('x', 60),
        ':role'         => $role,
        ':clanId'       => $clanId,
        ':now'          => utcStamp(),
    ]);
}

/** Funda una hermandad directamente en el plano (andarivel de fixtures). */
function seedClan(PDO $pdo, string $clanId, string $name, string $lineageType): void
{
    $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                            admission_mode, status, patriarch_id, weekly_points,
                            historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :now, :coatOfArms, :lineageType,
                 \'open\', \'active\', NULL, 0, 0, :now, :now)'
    )->execute([
        ':id'         => $clanId,
        ':slug'       => str_replace('_', '-', $clanId),
        ':name'       => $name,
        ':motto'      => 'Lema de ' . $name,
        ':now'        => utcStamp(),
        ':coatOfArms' => 'rune_' . $clanId,
        ':lineageType' => $lineageType,
    ]);
}

/** Inscribe una membresía por la AUTORIDAD (`clan_members`) y su espejo. */
function seedMembership(PDO $pdo, string $memberId, string $clanId, string $userId, string $role = 'adept'): void
{
    (new ClanMemberRepository($pdo))->addMember($memberId, $clanId, $userId, $role, utcStamp('-30 days'));
}

/** Puntos semanales canónicos de una casa. */
function weeklyPointsOf(PDO $pdo, string $clanId): int
{
    $statement = $pdo->prepare('SELECT weekly_points FROM clans WHERE id = :clanId');
    $statement->execute([':clanId' => $clanId]);

    return (int) $statement->fetchColumn();
}

/** Podio semanal del Salón: lista de [id, weeklyPoints] en su orden servido. */
function weeklyRankingOf(object $response): array
{
    $ranking = payloadOf($response)['data']['weeklyRanking'] ?? [];

    return array_map(
        static fn (array $clan): array => [(string) $clan['id'], (int) $clan['weeklyPoints']],
        is_array($ranking) ? $ranking : [],
    );
}

/** Un combo contra el Endpoint 14. */
function practise(?User $actor, array $body = ['comboElement' => 'fire']): object
{
    return dispatch('POST', '/api/v1/dominion/simulator-combo', $actor, json_encode($body, JSON_THROW_ON_ERROR));
}

echo "== ARNÉS TDD — PUENTE SIMULADOR → PDA → RANKING EN VIVO (Tarea 7.1, SPEC-07) ==\n";

// =====================================================================
// FASE 0 · Fixtures
// =====================================================================
echo "\n[FASE 0] Fixtures: hermandades con linaje rector y adeptos\n";

$pdo = Connection::getInstance()->getPdo();
$router = buildRouter();

seedClan($pdo, 'cln_ignis', 'Heraldos de la Llama', 'primordialFlame');  // elemento rector: fire
seedClan($pdo, 'cln_marea', 'Mareas Celestiales', 'celestialTides');      // elemento rector: water

seedUser($pdo, 'usr_patriarca_ignis', 'PatriarcaIgnis');
seedUser($pdo, 'usr_ignis_adept', 'AdeptoIgnis');
seedUser($pdo, 'usr_marea_adept', 'AdeptoMarea');
seedUser($pdo, 'usr_sin_casa', 'ErranteSinCasa');

seedMembership($pdo, 'clm_ignis_patriarch', 'cln_ignis', 'usr_patriarca_ignis', 'patriarch');
seedMembership($pdo, 'clm_ignis_adept', 'cln_ignis', 'usr_ignis_adept', 'adept');
seedMembership($pdo, 'clm_marea_adept', 'cln_marea', 'usr_marea_adept', 'adept');

assert_truthy(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM clan_members
          WHERE left_at IS NULL AND clan_id IN ('cln_ignis', 'cln_marea')"
    )->fetchColumn() === 3,
    'La autoridad de la afiliación inscribe a los tres adeptos del arnés',
);
assert_truthy(
    (string) $pdo->query("SELECT clan_id FROM users WHERE id = 'usr_ignis_adept'")->fetchColumn() === 'cln_ignis',
    'El espejo `users.clan_id` declara la casa del adepto',
);

// El Salón consume la salvaguarda perezosa del corte (plan 5, Decisión 1): la
// semana vencida se proclama al primer latido y la contienda en curso arranca
// en cero. Se consume ANTES de practicar para que lo que se mida después sea
// exactamente lo que la práctica acredita.
$opening = dispatch('GET', '/api/v1/dominion/leaderboard');
assert_truthy(statusOf($opening) === 200, 'El Salón abre la contienda en curso con su salvaguarda perezosa');

// =====================================================================
// FASE 1 · Fronteras de la ruta
// =====================================================================
echo "\n[FASE 1] Fronteras del Endpoint 14 (RF-03.2)\n";

$anonymous = practise(null);
assert_truthy(statusOf($anonymous) === 401, 'Sin vínculo arcano el combo responde 401');
assert_truthy(errorCodeOf($anonymous) === 'UNAUTHENTICATED', '…con el código canónico UNAUTHENTICATED');

$homeless = practise(actor($pdo, 'usr_sin_casa'));
assert_truthy(statusOf($homeless) === 409, 'Un mago sin hermandad no puede acreditar gloria (409)');
assert_truthy(errorCodeOf($homeless) === 'NO_CLAN_AFFILIATION', '…con el código canónico NO_CLAN_AFFILIATION');
assert_truthy(
    weeklyPointsOf($pdo, 'cln_ignis') === 0 && weeklyPointsOf($pdo, 'cln_marea') === 0,
    'El rechazo no movió el marcador de casa alguna',
);

$malformed = dispatch('POST', '/api/v1/dominion/simulator-combo', actor($pdo, 'usr_ignis_adept'), 'no-soy-json');
assert_truthy(statusOf($malformed) === 400, 'Un cuerpo ilegible responde 400');
assert_truthy(errorCodeOf($malformed) === 'INVALID_REQUEST_BODY', '…con el código canónico INVALID_REQUEST_BODY');

// =====================================================================
// FASE 2 · La gloria del combo
// =====================================================================
echo "\n[FASE 2] La gloria del combo elemental (RF-03.2, RF-03.4)\n";

$ignis = actor($pdo, 'usr_ignis_adept');

$plain = practise($ignis, ['comboElement' => 'water']);
$plainPayload = payloadOf($plain);
$plainAward = $plainPayload['data']['award'] ?? [];
assert_truthy(statusOf($plain) === 200, 'Un combo ejecutado por un adepto responde 200');
assert_truthy((int) ($plainAward['awardedPoints'] ?? 0) === 10, '…acreditando 10 PDA al clan del adepto (RF-03.2)');
assert_truthy((string) ($plainAward['actionType'] ?? '') === 'simulatorCombo', '…con la acción canónica simulatorCombo');
assert_truthy(($plainAward['hasSynergy'] ?? true) === false, '…sin sinergia: el agua no es la afinidad rectora de la Llama');
assert_truthy((int) ($plainAward['dailyQuotaRemaining'] ?? 0) === 40, '…y 40 PDA de cupo diario restante');
assert_truthy((int) ($plainPayload['data']['dailyCap'] ?? 0) === 50, 'El sobre declara el techo diario canónico de 50 PDA');
assert_truthy((string) ($plainPayload['data']['clanId'] ?? '') === 'cln_ignis', '…y la casa acreditada es la del adepto');

$synergic = practise($ignis, ['comboElement' => 'fire']);
$synergicAward = payloadOf($synergic)['data']['award'] ?? [];
assert_truthy((int) ($synergicAward['awardedPoints'] ?? 0) === 13, 'La afinidad rectora devenga 13 PDA (10 × 1.25 redondeado)');
assert_truthy(($synergicAward['hasSynergy'] ?? false) === true, '…y el recibo declara la sinergia de linaje');
assert_truthy((int) ($synergicAward['synergyBonus'] ?? 0) === 3, '…con la bonificación de sinergia cifrada en 3 PDA');

$unknown = practise($ignis, ['comboElement' => 'quicksilver']);
$unknownAward = payloadOf($unknown)['data']['award'] ?? [];
assert_truthy(statusOf($unknown) === 200, 'Un elemento ajeno al Códice no es un error (legítimo conjuro sin afinidad)');
assert_truthy((int) ($unknownAward['awardedPoints'] ?? 0) === 10, '…y acredita el valor base sin bonificación');

$silent = practise($ignis, []);
$silentAward = payloadOf($silent)['data']['award'] ?? [];
assert_truthy((int) ($silentAward['awardedPoints'] ?? 0) === 10, 'Sin declarar elemento el combo acredita el valor base');

$hostile = practise($ignis, ['comboElement' => str_repeat('X', 400)]);
$hostileAward = payloadOf($hostile)['data']['award'] ?? [];
assert_truthy(statusOf($hostile) === 200, 'Un elemento desmesurado no derriba la ruta');
assert_truthy(
    mb_strlen((string) (payloadOf($hostile)['data']['comboElement'] ?? '')) === 32,
    '…y el recibo acota el elemento declarado a 32 caracteres',
);

// 10 + 13 + 10 + 10 = 43 y el quinto combo (el hostil) tropieza con el cupo
// restante de 7: el techo muerde incluso sobre el camino del payload hostil.
$expected = 10 + 13 + 10 + 10 + 7;
$ignisPoints = weeklyPointsOf($pdo, 'cln_ignis');
assert_truthy(
    (int) ($hostileAward['awardedPoints'] ?? -1) === 7,
    'El quinto combo solo cobra el cupo restante del día (7 de 10)',
);
assert_truthy((int) ($hostileAward['dailyQuotaRemaining'] ?? -1) === 0, '…y cierra el cupo diario del adepto');
assert_truthy(
    $ignisPoints === $expected,
    "La casa acumula {$expected} PDA de los cinco combos acreditados (real: {$ignisPoints})",
);

// =====================================================================
// FASE 3 · El ranking semanal en vivo
// =====================================================================
echo "\n[FASE 3] El nuevo marcador se refleja en el ranking semanal en vivo (RF-03.1)\n";

$practisedWater = practise(actor($pdo, 'usr_marea_adept'), ['comboElement' => 'water']);
$waterAward = payloadOf($practisedWater)['data']['award'] ?? [];
assert_truthy((int) ($waterAward['awardedPoints'] ?? 0) === 13, 'La casa de las Mareas devenga su propia sinergia (13 PDA)');

$leaderboard = dispatch('GET', '/api/v1/dominion/leaderboard');
$ranking = weeklyRankingOf($leaderboard);
assert_truthy(statusOf($leaderboard) === 200, 'El Salón del Dominio se sirve tras la práctica');
assert_truthy(
    $ranking[0] === ['cln_ignis', $ignisPoints],
    "El podio en vivo corona a la Llama con los {$ignisPoints} PDA recién acreditados (real: "
        . json_encode($ranking[0] ?? null) . ')',
);
assert_truthy(
    in_array(['cln_marea', 13], $ranking, true),
    'La casa de las Mareas figura con sus 13 PDA en la misma consulta (real: ' . json_encode($ranking) . ')',
);
assert_truthy(
    (int) $pdo->query("SELECT historical_points FROM clans WHERE id = 'cln_ignis'")->fetchColumn() === 0,
    'Durante la contienda solo se acredita el marcador SEMANAL: el pliegue perpetuo es del corte dominical (RF-04.3)',
);

// =====================================================================
// FASE 4 · El techo diario de 50 PDA
// =====================================================================
echo "\n[FASE 4] El techo diario de 50 PDA detiene la práctica (RF-03.2)\n";

$marea = actor($pdo, 'usr_marea_adept');
$mareaClanBefore = weeklyPointsOf($pdo, 'cln_marea');

// Cuatro combos sin afinidad colman el cupo: 13 + 10 × 4 = 53 → el quinto
// tropieza con el tope y solo acredita el cupo restante.
$partial = null;
for ($index = 0; $index < 3; $index++) {
    practise($marea, ['comboElement' => 'none']);
}
$partial = practise($marea, ['comboElement' => 'none']);
$partialAward = payloadOf($partial)['data']['award'] ?? [];
assert_truthy(
    (int) ($partialAward['awardedPoints'] ?? -1) === 7,
    'La séptima gloria parcial acredita solo el cupo restante (7 de 10)',
);
assert_truthy(
    (int) ($partialAward['dailyQuotaRemaining'] ?? -1) === 0,
    '…y el cupo diario queda en cero',
);

$capped = practise($marea, ['comboElement' => 'water']);
$cappedAward = payloadOf($capped)['data']['award'] ?? [];
assert_truthy(statusOf($capped) === 200, 'El combo con el techo colmado NO es un error de la petición');
assert_truthy((int) ($cappedAward['awardedPoints'] ?? -1) === 0, '…acredita cero PDA');
assert_truthy(
    (string) ($cappedAward['reason'] ?? '') === 'DAILY_SIMULATOR_CAP_REACHED',
    '…con el motivo canónico DAILY_SIMULATOR_CAP_REACHED',
);
// 13 (sinergia) + 30 (tres combos) + 7 (cupo restante) = 50: el marcador de
// la casa se detiene EXACTAMENTE en el techo del adepto.
assert_truthy(
    weeklyPointsOf($pdo, 'cln_marea') === $mareaClanBefore + 37,
    'La casa se detiene exactamente en el techo del adepto: 13 + 30 + 7 = 50 PDA (real: '
        . weeklyPointsOf($pdo, 'cln_marea') . ' sobre ' . $mareaClanBefore . ')',
);
assert_truthy(
    (int) $pdo->query("SELECT points_awarded FROM daily_simulator_tracker WHERE user_id = 'usr_marea_adept'")->fetchColumn() === 50,
    'El acumulador diario del adepto se detiene en 50 PDA',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM daily_simulator_tracker WHERE user_id = 'usr_marea_adept'")->fetchColumn() === 1,
    'La práctica del día vive en UNA sola fila del acumulador (por adepto, casa y fecha UTC)',
);
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM dominion_awards WHERE user_id = 'usr_marea_adept'")->fetchColumn() === 0,
    'El simulador NO se asienta en `dominion_awards`: un único contador de gloria (RNF-01)',
);

// =====================================================================
// FASE 5 · El reinicio a las 00:00:00 UTC
// =====================================================================
echo "\n[FASE 5] El techo diario se reinicia a las 00:00:00 UTC (RF-03.2)\n";

// El acumulador está indexado por la FECHA UTC del día en curso: desplazar la
// fila a la jornada anterior equivale al vuelo de la medianoche, sin depender
// del reloj de la máquina (RNF-01: el cómputo es auditable y determinista).
$pdo->prepare("UPDATE daily_simulator_tracker SET cycle_date = :yesterday WHERE user_id = 'usr_marea_adept'")
    ->execute([':yesterday' => (new DateTimeImmutable('yesterday', new DateTimeZone('UTC')))->format('Y-m-d')]);

assert_truthy(
    (int) $pdo->query("SELECT points_awarded FROM daily_simulator_tracker WHERE user_id = 'usr_marea_adept'")->fetchColumn() === 50,
    'La memoria de la jornada vencida se conserva íntegra (50 PDA)',
);

$renewed = practise($marea, ['comboElement' => 'water']);
$renewedAward = payloadOf($renewed)['data']['award'] ?? [];
assert_truthy((int) ($renewedAward['awardedPoints'] ?? 0) === 13, 'La medianoche UTC devuelve la práctica: 13 PDA otra vez');
assert_truthy((int) ($renewedAward['dailyQuotaRemaining'] ?? 0) === 37, '…con el cupo diario renovado (37 restantes)');
assert_truthy(
    (int) $pdo->query("SELECT COUNT(*) FROM daily_simulator_tracker WHERE user_id = 'usr_marea_adept'")->fetchColumn() === 2,
    'La nueva jornada abre su propia fila en el acumulador',
);

// =====================================================================
// FASE 6 · Fronteras, dogmas y maná intacto
// =====================================================================
echo "\n[FASE 6] Fronteras, Dogma Vanilla y neutralidad del maná (Artículo II)\n";

// Un espejo rancio jamas acredita gloria a una casa ajena: la autoridad es
// `clan_members` y el servicio la contrasta antes de inscribir nada. El
// titular se relee DESPUÉS de la muda, como haría cada petición real.
$pdo->prepare("UPDATE users SET clan_id = 'cln_marea' WHERE id = 'usr_ignis_adept'")->execute();
$stale = practise(actor($pdo, 'usr_ignis_adept'), ['comboElement' => 'fire']);
assert_truthy(statusOf($stale) === 404, 'Un espejo rancio no acredita gloria a la casa que declara (404)');
assert_truthy(errorCodeOf($stale) === 'NOT_A_MEMBER', '…con el código canónico NOT_A_MEMBER');
$pdo->prepare("UPDATE users SET clan_id = 'cln_ignis' WHERE id = 'usr_ignis_adept'")->execute();

$manaBefore = (int) $pdo->query('SELECT COALESCE(SUM(mana_cost), 0) FROM spells')->fetchColumn();
practise(actor($pdo, 'usr_ignis_adept'), ['comboElement' => 'fire']);
$manaAfter = (int) $pdo->query('SELECT COALESCE(SUM(mana_cost), 0) FROM spells')->fetchColumn();
assert_truthy($manaBefore === $manaAfter, 'La gloria de hermandad jamás altera el coste de maná de la forja (Artículo II)');

$frontController = (string) file_get_contents($projectRoot . '/public/index.php');
assert_truthy(
    str_contains($frontController, "'/api/v1/dominion/simulator-combo'"),
    'El front controller registra la ruta canónica del Endpoint 14',
);
assert_truthy(
    str_contains((string) file_get_contents($projectRoot . '/src/Controllers/DominionController.php'), 'declare(strict_types=1);'),
    'Tipado estricto declarado en el controlador (AGENTS.md 8)',
);
$controllerSource = (string) file_get_contents($projectRoot . '/src/Controllers/DominionController.php');
assert_truthy(
    str_contains($controllerSource, 'awardSimulatorCombo($member, $clanId, $comboElement)'),
    'El controlador delega el cómputo en el servicio canónico: no recalcula el monto ni el techo (Art. II)',
);
assert_truthy(
    !preg_match('/\$pdo->(query|exec)\(/', $controllerSource),
    'El controlador no toca la base de datos a mano (cero consultas sin preparar)',
);
assert_truthy(
    is_dir($projectRoot . '/node_modules') === false
        && file_exists($projectRoot . '/composer.json') === false,
    'Cero dependencias npm/Composer (Dogma Vanilla)',
);

// =====================================================================
// Resumen
// =====================================================================
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertionsPassed}\n";
echo "Asertos fallidos:  {$assertionsFailed}\n";

if ($assertionsFailed > 0) {
    echo "\nFallos:\n";
    foreach ($failures as $failure) {
        echo "  · {$failure}\n";
    }
    echo "\nRESULTADO: FALLO — la Tarea 7.1 no cumple aún su criterio 'Hecho cuando'.\n";
    exit(1);
}

echo "\nRESULTADO: EXITO — El combo del simulador acredita PDA al clan y se detiene en el tope diario.\n";

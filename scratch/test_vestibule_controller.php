<?php

declare(strict_types=1);

/**
 * test_vestibule_controller.php — Verificación de la Tarea 3.4 de TASKS-10.
 *
 * Ejercita los CUATRO endpoints del Vestíbulo de las Hermandades contra la
 * PILA REAL de producción (autoload nativo + buildRouter + Connection),
 * validando el «Hecho cuando»:
 *
 *   1. GET  /api/v1/clans/vestibule                              → show()
 *   2. POST /api/v1/clans/{id}/applications/{appId}/withdraw     → withdraw()
 *   3. POST /api/v1/clans/applications/{appId}/verdict-acknowledge → acknowledgeVerdict()
 *   4. GET  /api/v1/clans/verdicts/unread-count                  → unreadCount()
 *
 * con sus contratos de estado (200/401/403/404/409) y la defensa en
 * profundidad de SPEC-09: el peregrino sin linaje JAMÁS alcanza el
 * controlador — la retención del middleware precede.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo sobre la base efímera.
 *   - Artículo V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_vestibule_controller.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

// La pila REAL (autoload nativo + buildRouter + Connection) se carga una sola
// vez; el PDO canónico se materializa al primer getPdo() sobre la base efímera.
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Core\SessionManager;
use Grimorio\Database\Connection;
use Grimorio\Exceptions\LineageOathException;
use Grimorio\Middleware\LineageOathMiddleware;
use Grimorio\Models\User;

$assertionsPassed = 0;
$assertionsFailed = 0;
/** @var list<string> */
$failures = [];

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
function dispatch(string $method, string $uri, ?User $actor = null, ?string $rawBody = null, array $sessionSeed = []): object
{
    $_SESSION = [];
    foreach ($sessionSeed as $clave => $valor) {
        $_SESSION[$clave] = $valor;
    }
    global $router;
    $request = new Grimorio\Core\Request($method, $uri, [], [], $rawBody);
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

/** Código de estado de una Response. */
function statusOf(object $response): int
{
    return (int) $response->getStatusCode();
}

/** Código de error del sobre canónico, o cadena vacía. */
function errorCodeOf(object $response): string
{
    return (string) (payloadOf($response)['error']['code'] ?? '');
}

/** Forja una cuenta peregrina o linajada sobre el PDO real y devuelve su User. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $lineage, string $role = 'editor'): User
{
    $now = '2026-09-20T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :role, NULL, :lineage, :now, :now)'
    );
    $statement->execute([
        ':id' => $id, ':alias' => $alias, ':email' => $email,
        ':role' => $role, ':lineage' => $lineage, ':now' => $now,
    ]);

    return User::fromDatabaseRow([
        'id' => $id, 'alias' => $alias, 'email' => $email, 'role' => $role,
        'clan_id' => null, 'lineage' => $lineage, 'password_hash' => 'x',
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** Funda una casa directamente en el plano (andamiaje de fixtures). */
function foundClan(PDO $pdo, string $founderId, string $name, string $lineageType, string $admissionMode = 'open'): string
{
    $statement = $pdo->prepare(
        'INSERT INTO clans (id, slug, name, motto, coat_of_arms, lineage_type, admission_mode, status, patriarch_id, weekly_points, historical_points, created_at, updated_at)
         VALUES (:id, :slug, :name, :motto, :coatOfArms, :lineageType, :admissionMode, \'active\', :patriarchId, 0, 0, :now, :now)'
    );
    $clanId = 'cln_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?: uniqid());
    $statement->execute([
        ':id' => $clanId, ':slug' => $clanId, ':name' => $name, ':motto' => 'Motto de prueba',
        ':coatOfArms' => $clanId, ':lineageType' => $lineageType, ':admissionMode' => $admissionMode,
        ':patriarchId' => $founderId, ':now' => '2026-09-20T10:00:00Z',
    ]);

    // El Patriarca fundador milita en la AUTORIDAD (clan_members).
    $memberRepository = new Grimorio\Repositories\ClanMemberRepository($pdo);
    $memberRepository->addMember('clm_' . bin2hex(random_bytes(4)), $clanId, $founderId, 'patriarch', '2026-09-01T00:00:00Z');

    return $clanId;
}

/** Remite una petición formal directamente en el plano (andamiaje de fixtures). */
function seedApplication(PDO $pdo, string $id, string $userId, string $clanId, string $status = 'pending', ?string $motivation = 'Motivación de prueba suficientemente larga.'): void
{
    $statement = $pdo->prepare(
        'INSERT INTO clan_applications (id, user_id, clan_id, motivation, status, verdict_motive, verdict_seen_at, created_at, resolved_at)
         VALUES (:id, :userId, :clanId, :motivation, :status, NULL, NULL, :now, NULL)'
    );
    $statement->execute([
        ':id' => $id, ':userId' => $userId, ':clanId' => $clanId,
        ':motivation' => $motivation, ':status' => $status, ':now' => '2026-09-20T10:30:00Z',
    ]);
}

echo "== VERIFICACIÓN TAREA 3.4 (SPEC-10): Controlador REST del Vestíbulo ==\n\n";

$pdo = Connection::getInstance()->getPdo();
$_SESSION = [];
$router = buildRouter();

// Fixtures: dos casas de celestialTides (una abierta, una por deliberación)
// y los actores del drama.
$patriarchMarea = forgeAdept($pdo, 'usr_patriarca_marea', 'Patriarca Marea', 'patmarea@arcano.arc', 'celestialTides');
$clanAbiertaId = foundClan($pdo, 'usr_patriarca_marea', 'Mareas de Aether', 'celestialTides', 'open');
$patriarchTorm = forgeAdept($pdo, 'usr_patriarca_torm', 'Patriarca Tormenta', 'pattorm@arcano.arc', 'celestialTides');
$clanDeliberanteId = foundClan($pdo, 'usr_patriarca_torm', 'Tempestad Eterna', 'celestialTides', 'byApplication');

$adepto = forgeAdept($pdo, 'usr_adepto', 'Adepto del Vestíbulo', 'adepto@arcano.arc', 'celestialTides');
$peregrino = forgeAdept($pdo, 'usr_peregrino', 'Peregrino Sin Umbral', 'peregrino@arcano.arc', null);
$otroLinaje = forgeAdept($pdo, 'usr_otro', 'Forastero de la Llama', 'forastero@arcano.arc', 'primordialFlame');

// --- FASE 1 · Endpoint 1: estado del Vestíbulo (RF-01.2, RF-01.7, RNF-04) ---
echo "[FASE 1] GET /api/v1/clans/vestibule — el sobre único\n";
$estado = dispatch('GET', '/api/v1/clans/vestibule', $adepto);
$cuerpoEstado = payloadOf($estado);
assert_truthy(statusOf($estado) === 200, 'El estado responde 200');
assert_truthy(
    isset($cuerpoEstado['data']['adeptState']['aptitude']) && isset($cuerpoEstado['data']['clans']) && array_key_exists('petitions', $cuerpoEstado['data']),
    'El sobre porta adeptState (aptitud), clans y petitions (contrato del plan §2.2)'
);
assert_truthy(
    ($cuerpoEstado['data']['adeptState']['lineage'] ?? 'x') === 'celestialTides',
    'El linaje se DERIVA de la sesión — sin parámetro de filtro (RF-01.2)'
);
$clanAbierta = null;
foreach ($cuerpoEstado['data']['clans'] ?? [] as $tarjeta) {
    if (($tarjeta['clanId'] ?? '') === $clanAbiertaId) {
        $clanAbierta = $tarjeta;
        break;
    }
}
assert_truthy($clanAbierta !== null, 'La casa del propio linaje figura en el catálogo');
assert_truthy(
    $clanAbierta !== null && ($clanAbierta['gesture'] ?? 'x') === 'join',
    'La casa abierta con vacante ofrece el gesto join (RF-01.7)'
);

// Sin sesión: 401 controlado.
$sinSesion = dispatch('GET', '/api/v1/clans/vestibule', null);
assert_truthy(
    statusOf($sinSesion) === 401 && errorCodeOf($sinSesion) === 'UNAUTHENTICATED',
    'Sin sesión: 401 UNAUTHENTICATED (contrato SPEC-03)'
);

// --- FASE 2 · La retención de SPEC-09 precede (RF-04.3) ---
echo "\n[FASE 2] El peregrino sin linaje jamás alcanza los cuatro endpoints\n";
// La cadena vigente del front controller (AuthMiddleware → LineageOathMiddleware)
// se replica aquí: la guardia de retención se aplica ANTES del despacho.
$oathGuard = new LineageOathMiddleware(new Grimorio\Repositories\LineageOathRepository($pdo));
foreach (
    [
        ['GET', '/api/v1/clans/vestibule'],
        ['GET', '/api/v1/clans/verdicts/unread-count'],
        ['POST', '/api/v1/clans/applications/app_x/verdict-acknowledge'],
        ['POST', '/api/v1/clans/cln_x/applications/app_x/withdraw'],
    ] as [$metodo, $uri]
) {
    $_SESSION = [];
    $peticionPeregrina = new Grimorio\Core\Request($metodo, $uri, [], [], null);
    $peticionPeregrina->setUser($peregrino);
    $veredictoGuardia = $oathGuard->guard($peticionPeregrina);
    if ($veredictoGuardia !== null) {
        // La retención del middleware deniega ANTES del despacho.
        assert_truthy(
            (int) $veredictoGuardia->getStatusCode() === 403
                && errorCodeOf($veredictoGuardia) === LineageOathException::OATH_REQUIRED_CODE,
            "{$metodo} {$uri} → la retención deniega 403 LINEAGE_OATH_REQUIRED antes del despacho"
        );
        continue;
    }
    // Prefijo público heredado (`/api/v1/clans`): la DEFENSA EN PROFUNDIDAD
    // del servicio deniega igualmente al peregrino con el mismo código.
    $retenido = dispatch($metodo, $uri, $peregrino);
    assert_truthy(
        statusOf($retenido) === 403 && errorCodeOf($retenido) === LineageOathException::OATH_REQUIRED_CODE,
        "{$metodo} {$uri} → 403 LINEAGE_OATH_REQUIRED (defensa en profundidad del servicio)"
    );
}

// --- FASE 3 · Endpoint 5: contador del rótulo (RF-01.1) ---
echo "\n[FASE 3] GET /api/v1/clans/verdicts/unread-count — el rótulo solemne\n";
seedApplication($pdo, 'app_veredicto_a', 'usr_adepto', $clanDeliberanteId, 'rejected');
$contador = dispatch('GET', '/api/v1/clans/verdicts/unread-count', $adepto);
$cuerpoContador = payloadOf($contador);
assert_truthy(statusOf($contador) === 200, 'El contador responde 200');
assert_truthy(
    ($cuerpoContador['data']['unreadVerdictsCount'] ?? 0) === 1,
    'Un veredicto sin contemplar suma 1 al rótulo (RF-01.1)'
);

// --- FASE 4 · Endpoint 4: veredicto contemplado (RF-03.4) ---
echo "\n[FASE 4] POST /api/v1/clans/applications/{appId}/verdict-acknowledge\n";
$contemplado = dispatch('POST', '/api/v1/clans/applications/app_veredicto_a/verdict-acknowledge', $adepto);
assert_truthy(statusOf($contemplado) === 200, 'El contemplado feliz responde 200');
$contadorTras = json_decode(dispatch('GET', '/api/v1/clans/verdicts/unread-count', $adepto)->getBody(), true);
assert_truthy(
    ($contadorTras['data']['unreadVerdictsCount'] ?? 1) === 0,
    'Tras contemplar, el rótulo se apaga: 0 dictámenes a la espera'
);
// Idempotencia: reenvío → 200 sin mutación.
$reenvio = dispatch('POST', '/api/v1/clans/applications/app_veredicto_a/verdict-acknowledge', $adepto);
assert_truthy(statusOf($reenvio) === 200, 'El reenvío responde 200 sin mutación (idempotencia)');
// Ajena: 404.
$ajena = dispatch('POST', '/api/v1/clans/applications/app_veredicto_a/verdict-acknowledge', $otroLinaje);
assert_truthy(statusOf($ajena) === 404, 'La petición ajena alza 404 APPLICATION_NOT_FOUND');
// Pendiente: 409 (en casa DISTINTA: la clausura por casa es invariante único).
seedApplication($pdo, 'app_pendiente_a', 'usr_adepto', $clanAbiertaId, 'pending');
$pendiente = dispatch('POST', '/api/v1/clans/applications/app_pendiente_a/verdict-acknowledge', $adepto);
assert_truthy(
    statusOf($pendiente) === 409 && errorCodeOf($pendiente) === 'APPLICATION_ALREADY_PENDING',
    'La petición pendiente no se contempla: 409 APPLICATION_ALREADY_PENDING'
);

// --- FASE 5 · Endpoint 3: retirada del postulante (RF-03.3) ---
echo "\n[FASE 5] POST /api/v1/clans/{id}/applications/{appId}/withdraw\n";
// Tercera casa del linaje: la clausura por casa (RF-03.1) veda reutilizar
// la deliberante (donde ya consta el rechazo de la Fase 3) ni la abierta.
$patriarchTercera = forgeAdept($pdo, 'usr_patriarca_tercera', 'Patriarca Tercera', 'patter@arcano.arc', 'celestialTides');
$clanTerceraId = foundClan($pdo, 'usr_patriarca_tercera', 'Voces de la Marea', 'celestialTides', 'byApplication');
seedApplication($pdo, 'app_retirada_a', 'usr_adepto', $clanTerceraId, 'pending');
$retirada = dispatch('POST', '/api/v1/clans/' . $clanTerceraId . '/applications/app_retirada_a/withdraw', $adepto);
assert_truthy(statusOf($retirada) === 200, 'La retirada feliz responde 200');
$row = $pdo->query("SELECT status, resolved_at FROM clan_applications WHERE id = 'app_retirada_a'")->fetch(PDO::FETCH_ASSOC);
assert_truthy(
    is_array($row) && $row['status'] === 'cancelled' && $row['resolved_at'] !== null,
    'La fila persiste cancelled con resolved_at (la casa queda clausurada, RF-03.1)'
);
// Carrera con el dictamen: 409.
$yaResuelta = dispatch('POST', '/api/v1/clans/' . $clanTerceraId . '/applications/app_retirada_a/withdraw', $adepto);
assert_truthy(
    statusOf($yaResuelta) === 409 && errorCodeOf($yaResuelta) === 'APPLICATION_ALREADY_RESOLVED',
    'La doble retirada alza 409 APPLICATION_ALREADY_RESOLVED (serialización, caso límite 5)'
);
// Ajena: 404.
$ajenaRetirada = dispatch('POST', '/api/v1/clans/' . $clanTerceraId . '/applications/app_retirada_a/withdraw', $otroLinaje);
assert_truthy(statusOf($ajenaRetirada) === 404, 'La retirada de petición ajena alza 404');

// --- FASE 6 · El rótulo ignora pendientes, cuenta terminales sin leer (RF-01.1) ---
echo "\n[FASE 6] El rótulo ignora pendientes y suma terminales sin leer\n";
// El adepto porta: app_veredicto_a (rechazado, YA LEÍDO en la Fase 4),
// app_pendiente_a (pendiente: jamás enciende rótulo) y app_retirada_a
// (cancelada en la Fase 5, SIN contemplar: la máquina de estados del plan
// §3.3 dicta «TODO estado terminal + verdict_seen_at NULL → rótulo»).
$contadorFinal = json_decode(dispatch('GET', '/api/v1/clans/verdicts/unread-count', $adepto)->getBody(), true);
assert_truthy(
    ($contadorFinal['data']['unreadVerdictsCount'] ?? -1) === 1,
    'La retirada sin contemplar enciende el rótulo (1); la pendiente jamás suma'
);

// --- VEREDICTO ---
echo "\n== VEREDICTO: {$assertionsPassed} asertos en verde, {$assertionsFailed} en rojo ==\n";
if ($assertionsFailed > 0) {
    echo "\nAsertos heridos:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}
echo "TAREA 3.4 COMPLETA: los cuatro endpoints responden según contrato\n";
echo "con la pila real de middleware y el peregrino sin linaje jamás los alcanza.\n";
exit(0);

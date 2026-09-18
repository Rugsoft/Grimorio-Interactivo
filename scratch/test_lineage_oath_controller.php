<?php

declare(strict_types=1);

/**
 * test_lineage_oath_controller.php — Verificación de la Tarea 2.6 de
 * TASKS-09 (controlador, rutas y cadena de middlewares).
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en dos frentes:
 *   1. Los tres endpoints responden con los contratos y códigos exactos
 *      del plan ante entradas válidas y hostiles.
 *   2. La cadena de retención (AuthMiddleware → LineageOathMiddleware)
 *      deniega al peregrino las rutas de gestión ANTES del despacho.
 *
 * La pila REAL (autoload nativo + buildRouter + Connection efímera) se
 * carga una sola vez, estilo test_clan_controller_endpoints.php.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): Request/Response/Router/PDO nativos.
 *   - Art. V: identificadores camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_oath_controller.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);

// La pila REAL se carga una sola vez; el PDO canónico se materializa al
// primer getPdo() sobre la base efímera (GRIMORIO_DB_DSN).
putenv('GRIMORIO_DB_DSN=sqlite::memory:');
require_once $projectRoot . '/public/index.php';

use Grimorio\Controllers\AuthController;
use Grimorio\Controllers\LineageOathController;
use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Database\Connection;
use Grimorio\Middleware\LineageOathMiddleware;
use Grimorio\Models\User;
use Grimorio\Repositories\LineageOathRepository;
use Grimorio\Services\LineageCatalogService;
use Grimorio\Services\LineageOathService;

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

/** Despacha una petición contra el router real, con usuario inyectado. */
function dispatch(string $method, string $uri, ?User $actor = null, ?string $rawBody = null, array $headers = [], array $sessionSeed = []): object
{
    $_SESSION = [];
    foreach ($sessionSeed as $clave => $valor) {
        $_SESSION[$clave] = $valor;
    }
    $router = buildRouter();
    $request = new Request($method, $uri, [], $headers, $rawBody);
    if ($actor !== null) {
        $request->setUser($actor);
    }

    return $router->dispatch($request);
}

/** Forja una cuenta peregrina o linajada sobre el PDO real. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $lineage, string $role = 'editor'): User
{
    $NOW = '2026-09-18T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :role, NULL, :lineage, :now, :now)'
    );
    $statement->execute([':id' => $id, ':alias' => $alias, ':email' => $email, ':role' => $role, ':lineage' => $lineage, ':now' => $NOW]);

    return User::fromDatabaseRow([
        'id' => $id, 'alias' => $alias, 'email' => $email, 'role' => $role,
        'clan_id' => null, 'lineage' => $lineage, 'password_hash' => 'x',
        'created_at' => $NOW, 'updated_at' => $NOW,
    ]);
}

echo "== VERIFICACION TAREA 2.6: El portal REST de la ceremonia ==\n\n";

$pdo = Connection::getInstance()->getPdo();
$_SESSION = [];

$peregrina = forgeAdept($pdo, 'usr_peregrina', 'Peregrina del Velo', 'peregrina@arcano.arc', null);
$linajada = forgeAdept($pdo, 'usr_linajada', 'Jurada de la Marea', 'jurada@arcano.arc', 'celestialTides');

// --- FASE 1: El canon ceremonial ---
echo "FASE 1: GET /api/v1/lineage/oath-catalog (RF-02.1)\n";
$canon = dispatch('GET', '/api/v1/lineage/oath-catalog', $peregrina);
$cuerpoCanon = json_decode($canon->getBody(), true);
assertCondition($canon->getStatusCode() === 200, 'El canon responde 200');
assertCondition(
    ($cuerpoCanon['data']['accountState'] ?? 'x') === 'pilgrim' && count($cuerpoCanon['data']['lineages'] ?? []) === 8,
    'El contrato porta accountState=pilgrim y las 8 fichas'
);
$canonLinajada = json_decode(dispatch('GET', '/api/v1/lineage/oath-catalog', $linajada)->getBody(), true);
assertCondition(($canonLinajada['data']['accountState'] ?? 'x') === 'lineaged', 'Un linajado curioso lee el canon con accountState=lineaged (RF-01.6)');
$anonimo = dispatch('GET', '/api/v1/lineage/oath-catalog', null);
assertCondition($anonimo->getStatusCode() === 401, 'Sin sesión: 401 controlado');

// --- FASE 2: El sellado del juramento ---
echo "\nFASE 2: POST /api/v1/lineage/oath (RF-03.1/03.3)\n";
$sellado = dispatch('POST', '/api/v1/lineage/oath', $peregrina, (string) json_encode(['lineageId' => 'primordialFlame']), [], [
    LineageOathMiddleware::SESSION_KEY_RETAINED_ROUTE => '#/creador',
]);
$cuerpoSellado = json_decode($sellado->getBody(), true);
assertCondition($sellado->getStatusCode() === 200, 'El sellado feliz responde 200');
assertCondition(
    ($cuerpoSellado['data']['lineage'] ?? 'x') === 'primordialFlame'
    && ($cuerpoSellado['data']['sealedNow'] ?? false) === true
    && ($cuerpoSellado['data']['retainedRoute'] ?? 'x') === '#/creador',
    'El veredicto porta lineage, sealedNow=true y la ruta retenida consumida (RF-03.1)'
);
assertCondition(!isset($_SESSION[LineageOathMiddleware::SESSION_KEY_RETAINED_ROUTE]), 'La ruta retenida se CONSUME al sellar: una ceremonia, un retorno');

// Idempotencia: mismo linaje reenviado → 200 sin mutación.
$reenvio = dispatch('POST', '/api/v1/lineage/oath', $peregrina, (string) json_encode(['lineageId' => 'primordialFlame']));
$cuerpoReenvio = json_decode($reenvio->getBody(), true);
assertCondition(
    $reenvio->getStatusCode() === 200 && ($cuerpoReenvio['data']['sealedNow'] ?? true) === false,
    'El reenvío del MISMO linaje responde 200 con sealedNow=false (idempotencia, RF-03.3)'
);

// Conflicto: linaje distinto sobre cuenta linajada.
$conflicto = dispatch('POST', '/api/v1/lineage/oath', $peregrina, (string) json_encode(['lineageId' => 'solarCrown']));
$conflictoBody = json_decode($conflicto->getBody(), true);
assertCondition(
    $conflicto->getStatusCode() === 403 && ($conflictoBody['error']['code'] ?? 'x') === 'LINEAGE_OATH_CONFLICT',
    'El linaje DISTINTO alza 403 LINEAGE_OATH_CONFLICT'
);

// Canon inválido: 400.
$invalido = dispatch('POST', '/api/v1/lineage/oath', forgeAdept($pdo, 'usr_otra', 'Otra Sin Umbral', 'otra@arcano.arc', null), (string) json_encode(['lineageId' => 'dracoStorm']));
$invalidoBody = json_decode($invalido->getBody(), true);
assertCondition(
    $invalido->getStatusCode() === 400 && ($invalidoBody['error']['code'] ?? 'x') === 'INVALID_LINEAGE',
    'Un linaje ajeno al canon alza 400 INVALID_LINEAGE'
);

// Payloads hostiles: cuerpo ausente, lineageId ausente, tipo hostil.
$hostilSinCuerpo = dispatch('POST', '/api/v1/lineage/oath', forgeAdept($pdo, 'usr_hostil', 'Hostil Sin Casa', 'hostil@arcano.arc', null), null);
assertCondition($hostilSinCuerpo->getStatusCode() === 400, 'Cuerpo ausente: 400 controlado (sin 500)');
$hostilSinClave = dispatch('POST', '/api/v1/lineage/oath', forgeAdept($pdo, 'usr_hostil2', 'Hostil Dos', 'hostil2@arcano.arc', null), '{}');
assertCondition($hostilSinClave->getStatusCode() === 400, 'lineageId ausente: 400 controlado');
$hostilArray = dispatch('POST', '/api/v1/lineage/oath', forgeAdept($pdo, 'usr_hostil3', 'Hostil Tres', 'hostil3@arcano.arc', null), (string) json_encode(['lineageId' => ['primordialFlame']]));
assertCondition($hostilArray->getStatusCode() === 400, 'lineageId no cadena (array): 400 controlado');

// El Supremo exento: 403 OATH_FORBIDDEN_ROLE.
$supremo = forgeAdept($pdo, 'usr_supremo', 'El Supremo', 'supremo@arcano.arc', null, 'supremeAdmin');
$supremoResp = dispatch('POST', '/api/v1/lineage/oath', $supremo, (string) json_encode(['lineageId' => 'solarCrown']));
$supremoBody = json_decode($supremoResp->getBody(), true);
assertCondition(
    $supremoResp->getStatusCode() === 403 && ($supremoBody['error']['code'] ?? 'x') === 'OATH_FORBIDDEN_ROLE',
    'El Supremo recibe 403 OATH_FORBIDDEN_ROLE (RF-01.6)'
);

// --- FASE 3: La ruta retenida del interceptor ---
echo "\nFASE 3: POST /api/v1/lineage/retained-route (RF-05.3)\n";
$retencion = dispatch('POST', '/api/v1/lineage/retained-route', forgeAdept($pdo, 'usr_retengo', 'Retengo Mi Ruta', 'retengo@arcano.arc', null), (string) json_encode(['route' => '#/simulador']));
assertCondition($retencion->getStatusCode() === 204, 'La ruta interna retenida responde 204');
assertCondition(($_SESSION[LineageOathMiddleware::SESSION_KEY_RETAINED_ROUTE] ?? null) === '#/simulador', 'La ruta interna queda en la sesión');

$externa = dispatch('POST', '/api/v1/lineage/retained-route', forgeAdept($pdo, 'usr_retengo2', 'Retengo Dos', 'retengo2@arcano.arc', null), (string) json_encode(['route' => 'https://malvado.example.com']));
assertCondition($externa->getStatusCode() === 204 && !isset($_SESSION[LineageOathMiddleware::SESSION_KEY_RETAINED_ROUTE]), 'La URL externa responde 204 y se DESCARTA en silencio');
$sesionAnonima = dispatch('POST', '/api/v1/lineage/retained-route', null, (string) json_encode(['route' => '#/creador']));
assertCondition($sesionAnonima->getStatusCode() === 401, 'Sin sesión: 401 controlado');

// --- FASE 4: La cadena de retención sobre rutas de gestión ---
echo "\nFASE 4: La cadena AuthMiddleware → LineageOathMiddleware (RF-05.1)\n";
// NOTA de alcance: el guard global corre en el front controller ANTES del
// despacho (public/index.php); aquí se ejercita como pieza con la misma
// Request que despacharía el front controller, comprobando la denegación
// de rutas de gestión y el paso de las permitidas.
$middleware = new LineageOathMiddleware(new LineageOathRepository($pdo));
$peregrinaDos = forgeAdept($pdo, 'usr_peregrina2', 'Segunda Sin Umbral', 'segunda@arcano.arc', null);

$gestionDenegada = $middleware->guard(self_forgeRequest('POST', '/api/v1/spells/drafts', '#/creador', $peregrinaDos));
assertCondition($gestionDenegada !== null && $gestionDenegada->getStatusCode() === 403, 'El peregrino es denegado en rutas de gestión (403 LINEAGE_OATH_REQUIRED)');
assertCondition(
    ($_SESSION[LineageOathMiddleware::SESSION_KEY_RETAINED_ROUTE] ?? null) === '#/creador',
    'La ruta solicitada queda retenida en la sesión antes de responder'
);
$lecturaPermitida = $middleware->guard(self_forgeRequest('GET', '/api/v1/spells', null, $peregrinaDos));
assertCondition($lecturaPermitida === null, 'La lectura pública pasa sin retención');
$canonPermitido = $middleware->guard(self_forgeRequest('GET', '/api/v1/lineage/oath-catalog', null, $peregrinaDos));
assertCondition($canonPermitido === null, 'El canon ceremonial pasa: la ceremonia nunca es retenida (RF-05.1)');

/** Forja una Request con usuario y cabecera de vista (helper de Fase 4). */
function self_forgeRequest(string $method, string $uri, ?string $route, User $actor): Request
{
    $headers = $route !== null ? ['X-Requested-Route' => $route] : [];
    $request = new Request($method, $uri, [], $headers);
    $request->setUser($actor);

    return $request;
}

// La cadena real en el front controller: el arnés comprueba su presencia.
$frontSource = (string) file_get_contents($projectRoot . '/public/index.php');
assertCondition(
    str_contains($frontSource, 'LineageOathMiddleware') && str_contains($frontSource, 'injectContext($request)'),
    'El front controller encadena AuthMiddleware → LineageOathMiddleware antes del despacho'
);
assertCondition(
    (int) preg_match_all("/addRoute\\('(?:GET|POST)', '\\/api\\/v1\\/lineage\\//", $frontSource) === 3,
    'Las TRES rutas del juramento están registradas en el enrutador'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El portal REST de la ceremonia responde con los contratos exactos y la retención blinda el santuario (Tarea 2.6).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);

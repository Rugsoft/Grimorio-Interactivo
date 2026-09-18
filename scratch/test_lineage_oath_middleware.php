<?php

declare(strict_types=1);

/**
 * test_lineage_oath_middleware.php — Verificación de la Tarea 2.3 de TASKS-09.
 *
 * Valida la guardia de sustancia (`LineageOathMiddleware`):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en cinco frentes:
 *   1. Un peregrino recibe 403 `LINEAGE_OATH_REQUIRED` en cualquier ruta
 *      de gestión.
 *   2. La ruta solicitada interna queda en su sesión.
 *   3. Una URL externa se descarta.
 *   4. Un linajado jamás ve la guardia.
 *   5. El Supremo navega exento con o sin linaje.
 *
 * Fases:
 *   [0]  Superficie: el middleware existe y declara su contrato.
 *   [1]  El peregrino retenido en rutas de gestión (con ruta interna en
 *        sesión y externa descartada).
 *   [2]  Las rutas permitidas del peregrino: canon, juramento, credenciales,
 *        logout y lectura pública pasan sin retención.
 *   [3]  El linajado jamás ve la guardia; el Supremo exento con o sin linaje.
 *   [4]  El cruce de listas: el mapa de hashes del middleware y el de
 *        main.js no divergen.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_oath_middleware.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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

/** Construye el santuario canónico con el mundo sembrado. */
function forgeSanctuary(): PDO
{
    $projectRoot = dirname(__DIR__);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
    $pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

    return $pdo;
}

/** Inscribe un adepto de prueba. */
function forgeAdept(PDO $pdo, string $id, string $alias, string $email, ?string $lineage, string $role = 'editor'): void
{
    $NOW = '2026-09-18T10:00:00Z';
    $statement = $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
         VALUES (:id, :alias, :email, \'x\', :role, NULL, :lineage, :now, :now)'
    );
    $statement->execute([':id' => $id, ':alias' => $alias, ':email' => $email, ':role' => $role, ':lineage' => $lineage, ':now' => $NOW]);
}

/** Forja un Request con usuario, método, ruta y cabecera de vista. */
function forgeRequest(?Grimorio\Models\User $user, string $method, string $path, ?string $requestedRoute = null): Grimorio\Core\Request
{
    $headers = $requestedRoute !== null ? ['X-Requested-Route' => $requestedRoute] : [];
    $request = new Grimorio\Core\Request($method, $path, [], $headers);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $request;
}

echo "== VERIFICACION TAREA 2.3: La guardia de sustancia ==\n\n";

$projectRoot = dirname(__DIR__);
$middlewarePath = $projectRoot . '/src/Middleware/LineageOathMiddleware.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie del middleware\n";
assertCondition(file_exists($middlewarePath), 'Existe src/Middleware/LineageOathMiddleware.php');
if (!file_exists($middlewarePath)) {
    echo "\nRESULTADO: FALLO — falta el middleware de la Tarea 2.3.\n";
    exit(1);
}
$middlewareSource = (string) file_get_contents($middlewarePath);
assertCondition(str_contains($middlewareSource, 'declare(strict_types=1);'), 'Tipado estricto obligatorio');
assertCondition(str_contains($middlewareSource, "WHERE id = :userId AND lineage IS NULL") === false && str_contains($middlewareSource, 'LINEAGE_OATH_REQUIRED'), 'El middleware porta el código del contrato `LINEAGE_OATH_REQUIRED`');

// --- FASE 1: El peregrino retenido ---
echo "\nFASE 1: El peregrino retenido en rutas de gestión (criterios 1-3)\n";
require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Core/Request.php';
require_once $projectRoot . '/src/Core/Response.php';
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
require_once $middlewarePath;

$pdo = forgeSanctuary();
$middleware = new Grimorio\Middleware\LineageOathMiddleware(new Grimorio\Repositories\LineageOathRepository($pdo));
forgeAdept($pdo, 'usr_peregrino', 'Peregrino del Velo', 'peregrino@arcano.arc', null);
// El arnés ejercita la sesión nativa sin sesiones reales: $_SESSION se
// simula como array global, igual que hará el middleware en producción.
$_SESSION = [];

$peregrino = new Grimorio\Models\User(
    id: 'usr_peregrino',
    alias: 'Peregrino del Velo',
    email: 'peregrino@arcano.arc',
    role: 'editor',
    clanId: null,
    passwordHash: 'x',
    createdAt: '2026-09-18T10:00:00Z',
    updatedAt: '2026-09-18T10:00:00Z',
);

// La cabecera de vista viaja por el constructor del Request, como la
// SPA la porta en la petición real.
$request = forgeRequest($peregrino, 'POST', '/api/v1/spells/drafts', '#/creador');
$response = $middleware->guard($request);
assertCondition($response instanceof Grimorio\Core\Response && $response->getStatusCode() === 403, 'El peregrino recibe 403 en una ruta de gestión (RF-05.1)');
$payload = $response !== null ? json_decode($response->getBody(), true) : [];
assertCondition(
    ($payload['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED'
    && str_contains($payload['error']['message'] ?? '', 'juramento'),
    'El sobre porta el código del contrato y la leyenda solemne en castellano'
);
assertCondition(
    ($payload['error']['details']['oathView'] ?? '') === '#/juramento',
    'El sobre señala la vista de la ceremonia (`oathView: #/juramento`)'
);
assertCondition(($_SESSION['retainedRoute'] ?? null) === '#/creador', 'La ruta solicitada INTERNA queda retenida en la sesión (RF-05.3, criterio 2)');

// Otra denegación sin cabecera de ruta: no borra una retención previa ni
// la inventa.
$middleware->guard(forgeRequest($peregrino, 'DELETE', '/api/v1/spells/drafts/xyz'));
assertCondition(($_SESSION['retainedRoute'] ?? null) === '#/creador', 'Una denegación sin ruta no toca la retención existente');

// La URL externa se descarta en silencio (criterio 3).
$response = $middleware->guard(forgeRequest($peregrino, 'POST', '/api/v1/clans', 'https://malvado.example.com/robar-mana'));
assertCondition($response !== null && $response->getStatusCode() === 403, 'Una segunda ruta de gestión también es denegada');
assertCondition(!isset($_SESSION['retainedRoute']) || $_SESSION['retainedRoute'] === '#/creador', 'La URL EXTERNA se descarta: jamás entra en la sesión');
$middleware->guard(forgeRequest($peregrino, 'POST', '/api/v1/clans', '#/clave-inexistente'));
assertCondition(!isset($_SESSION['retainedRoute']) || $_SESSION['retainedRoute'] === '#/creador', 'Un hash de vista desconocido también se descarta');

// --- FASE 2: Las rutas permitidas del peregrino ---
echo "\nFASE 2: Las rutas permitidas del peregrino (RF-01.4, criterio)\n";
$permittedCases = [
    ['GET', '/api/v1/lineage/oath-catalog'],
    ['POST', '/api/v1/lineage/oath'],
    ['POST', '/api/v1/lineage/retained-route'],
    ['GET', '/api/v1/auth/session'],
    ['POST', '/api/v1/auth/dissolve'],
    ['GET', '/api/v1/spells'],
    ['GET', '/api/v1/spells/alba-ardiente'],
    ['GET', '/api/v1/portal/featured'],
    ['GET', '/api/v1/clans/preview'],
    ['GET', '/api/v1/audit/log'],
];
$allowed = true;
foreach ($permittedCases as [$method, $path]) {
    if ($middleware->guard(forgeRequest($peregrino, $method, $path, null)) !== null) {
        $allowed = false;
        echo "      (fallo en {$method} {$path})\n";
    }
}
assertCondition($allowed, 'Canon, juramento, ruta retenida, credenciales, logout y lectura pública: paso sin retención');

// La lectura pública pasa, pero la escritura no: la excepción no es un agujero.
assertCondition(
    $middleware->guard(forgeRequest($peregrino, 'POST', '/api/v1/spells', null)) !== null,
    'La lectura pública de spells pasa pero su escritura sigue retenida: la excepción no es un agujero'
);

// --- FASE 3: Linajados y Supremo ---
echo "\nFASE 3: Linajados jamás retenidos; Supremo exento (criterios 4-5)\n";
forgeAdept($pdo, 'usr_jurada', 'Jurada de la Llama', 'jurada@arcano.arc', 'primordialFlame');
$linajada = new Grimorio\Models\User(
    id: 'usr_jurada',
    alias: 'Jurada de la Llama',
    email: 'jurada@arcano.arc',
    role: 'editor',
    clanId: null,
    passwordHash: 'x',
    createdAt: '2026-09-18T10:00:00Z',
    updatedAt: '2026-09-18T10:00:00Z',
);
assertCondition(
    $middleware->guard(forgeRequest($linajada, 'POST', '/api/v1/spells/drafts', '#/creador')) === null,
    'El linajado jamás ve la guardia (criterio 4)'
);

$supremoSinLinaje = new Grimorio\Models\User(
    id: 'usr_supremo',
    alias: 'El Supremo',
    email: 'supremo@arcano.arc',
    role: 'supremeAdmin',
    clanId: null,
    passwordHash: 'x',
    createdAt: '2026-09-18T10:00:00Z',
    updatedAt: '2026-09-18T10:00:00Z',
);
assertCondition(
    $middleware->guard(forgeRequest($supremoSinLinaje, 'POST', '/api/v1/spells/drafts', null)) === null,
    'El Supremo SIN linaje navega exento (RF-01.6, criterio 5)'
);

// Un peregrino sin usuario inyectado (defecto de cadena): el flujo de
// autenticación es del AuthMiddleware, no de esta guardia.
assertCondition($middleware->guard(forgeRequest(null, 'POST', '/api/v1/spells/drafts', null)) === null, 'Sin usuario inyectado la guardia no retiene: el 401 es del AuthMiddleware');

// --- FASE 4: El cruce de listas con la SPA ---
echo "\nFASE 4: Las dos fuentes de la lista de vistas no divergen (main.js ↔ middleware)\n";
$mainJs = (string) file_get_contents($projectRoot . '/public/assets/js/main.js');
$spaNativeHashes = [];
if (preg_match_all("/'(#[a-z\/]*)':\s*'[a-zA-Z]+'/", $mainJs, $matches) > 0) {
    $spaNativeHashes = $matches[1];
}
$middlewareHashes = [];
if (preg_match_all("/'(#[a-z\/]*)'\s*=>\s*'[a-zA-Z]+'/s", $middlewareSource, $matches) > 0) {
    $middlewareHashes = $matches[1];
}
assertCondition(count($middlewareHashes) >= 9, 'El middleware replica el mapa canónico de hashes (' . count($middlewareHashes) . ' vistas)');
$diferencia = array_diff($spaNativeHashes, $middlewareHashes);
assertCondition($diferencia === [], 'Todo hash de la SPA está cubierto por el middleware (diferencia: ' . implode(', ', $diferencia) . ')');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La retención de sustancia está en pie: peregrino denegado con ruta retenida, permitidos pasan, linajados y Supremo libres (Tarea 2.3).\n";
    exit(0);
}

echo "RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);

<?php

/**
 * test_endpoints.php — Verificación de integración de la API REST (Tarea 1.6).
 *
 * Orquesta un entorno E2E completo de forma autónoma:
 *   1. Siembra una base SQLite efímera con schema.sql + seeds.sql.
 *   2. Arranca el servidor nativo `php -S` con public/index.php como subproceso.
 *   3. Ejecuta peticiones HTTP reales con file_get_contents + contexto (nativo).
 *   4. Valida los 5 escenarios del plan 7.1 y los códigos HTTP del estándar AGENTS.md.
 *   5. Detiene el servidor y limpia los artefactos temporales.
 *
 * Escenarios (plan técnico, sección 7.1):
 *   1. GET /api/v1/portal/featured          -> 200 con arreglo de exactamente 3 elementos.
 *   2. GET /api/v1/spells?query=frieren     -> 200 filtrando resultados relevantes.
 *   3. GET /api/v1/spells?query=<250 chars> -> 200 (truncado a 100 sin fallar, RF-03.4).
 *   4. GET /api/v1/spells/conjuro-inexistente -> 404 con SCROLL_LOST_IN_AETHER.
 *   5. GET /api/v1/clans/preview            -> 200 con linajes en modo lectura.
 *   6. GET /api/v1/lineages                 -> 200 con los 8 Linajes Canónicos (SPEC-07).
 *
 * Extra del estándar de la API (AGENTS.md 6.1 / plan 3): 400 ante parámetros basura.
 *
 * Uso: php scratch/test_endpoints.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

// --- Configuración del entorno de prueba ---
$projectRoot     = dirname(__DIR__);
$tempDbPath      = $projectRoot . '/scratch/test_endpoints.sqlite';
$serverHost      = '127.0.0.1';
$serverPort      = 8101; // Puerto dedicado de esta verificación (evita choques con otros procesos).
$serverBaseUrl   = "http://{$serverHost}:{$serverPort}";
$serverLogFile   = $projectRoot . '/scratch/test_endpoints_server.log';

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
 * Realiza una petición HTTP GET nativa y retorna [statusCode, bodyDecodificado].
 * Solo PHP estándar: sin curl ni dependencias (Artículo I).
 *
 * @return array{0: int, 1: ?array}
 */
function httpGetJson(string $url): array
{
    // Silencio controlado: los fallos de conexión se tratan como código 0.
    $streamContext = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 5,
        'ignore_errors' => true, // Necesario para leer cuerpos de 4xx/5xx.
    ]]);

    $rawBody = @file_get_contents($url, false, $streamContext);
    if ($rawBody === false) {
        return [0, null];
    }

    // El código de estado viaja en la cabecera de respuesta mágica $http_response_header.
    $statusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }
    }

    $decodedBody = json_decode($rawBody, true);

    return [$statusCode, is_array($decodedBody) ? $decodedBody : null];
}

echo "== TAREA 1.6: Verificacion de integracion de la API REST ==\n\n";

// --- FASE A: Siembra de la base efímera ---
echo "FASE A: Siembra de la base de datos efímera\n";
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}

$seedPdo = new PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seedPdo->exec('PRAGMA foreign_keys = ON');
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

// Hechizo de usuario con 'frieren' en el nombre para el escenario 2 (plan 7.1.2).
// Se declaran las columnas obligatorias del plano que el cargador de semillas
// también provee: author_id y updated_at (NOT NULL sin DEFAULT) y la huella
// matemática de 64 caracteres que exige su CHECK.
$seedPdo->prepare(
    'INSERT INTO spells (id, slug, name, author_id, magic_school, mana_cost,
                         math_fingerprint, clan_id, summary, status,
                         is_genesis_sample, created_at, updated_at, validated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'spl_user_frieren', 'llamas-de-frieren', 'Llamas de Frieren',
    'usr_custodio_primordial', 'evocation', 45, str_repeat('f', 64),
    'cln_primordial', 'Ráfaga continua de fuego purificador del bosque eterno.',
    'validated', 0, '2026-09-01T00:00:00Z', '2026-09-01T00:00:00Z', '2026-09-10T14:30:00Z',
]);

$seededSpells = (int) $seedPdo->query('SELECT COUNT(*) FROM spells')->fetchColumn();
$seedPdo = null; // Cerrar la conexión de siembra antes de que el servidor la abra.
assertCondition($seededSpells === 5, "Base sembrada: 3 pergaminos primordiales + 1 experimental de semilla + 1 de usuario ({$seededSpells})");

// --- FASE B: Arranque del servidor nativo como subproceso ---
echo "\nFASE B: Arranque del servidor de desarrollo\n";

// El DSN debe fijarse ANTES de popen: el subproceso hereda este entorno.
putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverCommand = sprintf(
    '%s -S %s:%d %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverHost,
    $serverPort,
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($serverLogFile)
);

$serverProcessHandle = popen($serverCommand, 'r');
if ($serverProcessHandle === false) {
    echo "  [FALLA] No se pudo lanzar el subproceso del servidor.\n";
    exit(1);
}

// Sondeo breve hasta que el puerto responda (máx. ~5 s).
$serverReady = false;
for ($attempt = 0; $attempt < 25; $attempt++) {
    [$probeStatus] = httpGetJson($serverBaseUrl . '/api/v1/clans/preview');
    if ($probeStatus !== 0) {
        $serverReady = true;
        break;
    }
    usleep(200000);
}assertCondition($serverReady, "El servidor nativo responde en {$serverBaseUrl}");


// --- FASE C: Escenarios del plan 7.1 ---
echo "\nFASE C: Escenarios de integración (plan 7.1)\n";

// 7.1.1 — Destacados: exactamente 3 elementos.
[$featuredStatus, $featuredPayload] = httpGetJson($serverBaseUrl . '/api/v1/portal/featured');
assertCondition($featuredStatus === 200, "GET /portal/featured -> 200 OK");
assertCondition(
    ($featuredPayload['success'] ?? null) === true && count($featuredPayload['data'] ?? []) === 3,
    'El arreglo JSON de destacados contiene exactamente 3 elementos'
);

// 7.1.2 — Filtro por query: 'frieren' retorna solo el hechizo del usuario.
[$filterStatus, $filterPayload] = httpGetJson($serverBaseUrl . '/api/v1/spells?query=frieren');
$filterSlugs = array_map(
    static fn (array $item): string => (string) ($item['slug'] ?? ''),
    $filterPayload['data']['items'] ?? []
);
assertCondition($filterStatus === 200, "GET /spells?query=frieren -> 200 OK");
assertCondition(
    $filterSlugs === ['llamas-de-frieren'],
    'El filtro query=frieren retorna solo el hechizo relevante (' . implode(',', $filterSlugs) . ')'
);

// 7.1.3 — Truncamiento a 100 caracteres sin fallo (RF-03.4).
$oversizedQuery = str_repeat('x', 250);
[$truncateStatus, $truncatePayload] = httpGetJson($serverBaseUrl . '/api/v1/spells?query=' . rawurlencode($oversizedQuery));
assertCondition($truncateStatus === 200, "GET /spells?query=<250 caracteres> -> 200 OK (truncado a 100 sin error)");
assertCondition(
    ($truncatePayload['success'] ?? null) === true && is_array($truncatePayload['data']['items'] ?? null),
    'La respuesta truncada mantiene el contrato de colección íntegro'
);

// 7.1.4 — Pergamino desterrado: 404 con contrato místico completo (plan 2.4).
[$lostStatus, $lostPayload] = httpGetJson($serverBaseUrl . '/api/v1/spells/conjuro-inexistente');
assertCondition($lostStatus === 404, 'GET /spells/conjuro-inexistente -> 404 Not Found');
assertCondition(
    ($lostPayload['error']['code'] ?? '') === 'SCROLL_LOST_IN_AETHER'
    && ($lostPayload['error']['recoveryAction'] ?? '') === 'RETURN_TO_LIBRARY',
    'El 404 porta SCROLL_LOST_IN_AETHER con recoveryAction RETURN_TO_LIBRARY'
);

// 7.1.5 — Salón de Linajes en modo lectura.
[$clansStatus, $clansPayload] = httpGetJson($serverBaseUrl . '/api/v1/clans/preview');
assertCondition($clansStatus === 200, 'GET /clans/preview -> 200 OK (lectura pública)');
assertCondition(
    ($clansPayload['success'] ?? null) === true
    && count($clansPayload['data'] ?? []) >= 1
    && isset($clansPayload['data'][0]['domainPoints']),
    'La lista de linajes llega con domainPoints en camelCase'
);

// 7.1.6 — Salón de los Linajes: canon canónico servido por HTTP real (SPEC-07, Tarea 3.1).
[$lineagesStatus, $lineagesPayload] = httpGetJson($serverBaseUrl . '/api/v1/lineages');
assertCondition($lineagesStatus === 200, 'GET /lineages -> 200 OK (lectura pública del canon)');
assertCondition(
    count($lineagesPayload['data'] ?? []) === 8,
    'El canon comprende exactamente 8 Linajes Mágicos Canónicos (RF-02.1)'
);
assertCondition(
    array_column($lineagesPayload['data'] ?? [], 'id') === [
        'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
        'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers',
    ],
    'Las 8 claves canónicas viajan en inglés camelCase y en el orden del canon'
);

// Extra del estándar: 400 ante parámetros inválidos (AGENTS.md 6.1).
[, $invalidPayload] = [0, null];
[$invalidStatus, $invalidPayload] = httpGetJson($serverBaseUrl . '/api/v1/spells?maxMana=no-es-numero');
assertCondition($invalidStatus === 400, 'GET /spells?maxMana=no-es-numero -> 400 Bad Request');
assertCondition(
    isset($invalidPayload['error']['code']),
    'El 400 mantiene el contrato de error { success: false, error: { code, ... } }'
);

// --- FASE D: Cierre ordenado ---
echo "\nFASE D: Cierre del entorno\n";

// pclose() bloquearía esperando al servidor (nunca termina solo): se remate
// primero el proceso por puerto y pclose retorna de inmediato tras la muerte.
$cleanupCommand = stripos(PHP_OS_FAMILY, 'WIN') === 0
    ? 'powershell -Command "Get-NetTCPConnection -LocalPort ' . $serverPort . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object { Stop-Process -Id $_ -Force }"'
    : "fuser -k {$serverPort}/tcp 2>/dev/null";
shell_exec($cleanupCommand);
pclose($serverProcessHandle);

if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
echo "  Servidor detenido y base efímera eliminada.\n";

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 1.6 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

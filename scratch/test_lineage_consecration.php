<?php

declare(strict_types=1);

/**
 * test_lineage_consecration.php — Verificación de la Tarea 2.5 de TASKS-09
 * (enmienda de la consagración, SPEC-09 + SPEC-03).
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en tres frentes:
 *   1. Una consagración sin `clanId` crea cuenta `editor` con
 *      `lineage: null` y sesión iniciada.
 *   2. Una consagración con `clanId` legado lo ignora sin error.
 *   3. El contrato de sesión (`GET /api/v1/auth/session` vía
 *      AuthController::session) retorna `lineage`.
 *
 * Fases:
 *   [0]  Superficie: el contrato ya no porta `clanName` en la 201.
 *   [1]  Servicio: consagración sin clan → peregrino con sesión.
 *   [2]  Servicio: `clanId` legado ignorado en silencio (plan §5.8).
 *   [3]  Controlador: el contrato 201 porta `lineage: null` y jamás `clanName`.
 *   [4]  Controlador: `session` (auth/me) expone `lineage` para el store.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo en :memory:, sin librerías.
 *   - Art. V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_consecration.php
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

/** ¿Lanza este cierre de excepción? Devuelve la excepción o null. */
function captureThrowable(callable $operation): ?Throwable
{
    try {
        $operation();

        return null;
    } catch (Throwable $failure) {
        return $failure;
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

echo "== VERIFICACION TAREA 2.5: La consagración sin linaje ==\n\n";

// En CLI, setcookie() no tiene cabeceras a donde ir: se silencia para que
// el cuerpo JSON de las respuestas del controlador llegue limpio.
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // Los avisos de cabeceras ya enviadas se tragan; el resto se delega.
    if (str_contains($message, 'Cannot modify header information') || str_contains($message, 'headers already sent')) {
        return true;
    }

    return false;
}, E_WARNING);

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/Core/ActiveSession.php';
require_once $projectRoot . '/src/Core/RateLimitVerdict.php';
require_once $projectRoot . '/src/Core/SessionManager.php';
require_once $projectRoot . '/src/Core/RateLimiter.php';
require_once $projectRoot . '/src/Core/Request.php';
require_once $projectRoot . '/src/Core/Response.php';
require_once $projectRoot . '/src/Models/User.php';
require_once $projectRoot . '/src/Models/AuditEntry.php';
require_once $projectRoot . '/src/Repositories/ClanMemberRepository.php';
require_once $projectRoot . '/src/Repositories/LineageOathRepository.php';
require_once $projectRoot . '/src/Services/ConsecrationResult.php';
require_once $projectRoot . '/src/Services/BindResult.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): se carga antes del
// servicio, como hace el autoloader del front controller.
require_once $projectRoot . '/src/Services/AuditRecorderInterface.php';
require_once $projectRoot . '/src/Services/AuditService.php';
require_once $projectRoot . '/src/Services/AuthService.php';
require_once $projectRoot . '/src/Controllers/AuthController.php';

// --- FASE 0: Superficie ---
echo "FASE 0: Superficie de la enmienda\n";
$serviceSource = (string) file_get_contents($projectRoot . '/src/Services/AuthService.php');
$controllerSource = (string) file_get_contents($projectRoot . '/src/Controllers/AuthController.php');
assertCondition(
    str_contains($serviceSource, 'ENMIENDA SPEC-09') && str_contains($serviceSource, 'legacyClanId'),
    'AuthService documenta la enmienda y neutraliza el clan legado'
);
assertCondition(
    !str_contains($controllerSource, "'clanName' => \$clanName"),
    'El contrato 201 de la consagración ya no declara `clanName`'
);

// --- FASE 1: Consagración sin clan → peregrino con sesión ---
echo "\nFASE 1: Consagración sin clanId — peregrino con sesión (criterio 1)\n";
$pdo = forgeSanctuary();
$sessionManager = new Grimorio\Core\SessionManager($pdo);
$authService = new Grimorio\Services\AuthService($pdo, $sessionManager);

$hora = new DateTimeImmutable('2026-09-18T10:00:00Z', new DateTimeZone('UTC'));
$resultado = $authService->consecrate('Novicia del Alba', 'novicia@arcano.arc', 'frase de paso larga', null, $hora);
assertCondition($resultado->userId !== '' && $resultado->lineage === null, 'La cuenta nace con `lineage: null` (peregrina, RF-01.2 de SPEC-09)');

$fila = $pdo->prepare('SELECT role, lineage, clan_id FROM users WHERE id = :id');
$fila->execute([':id' => $resultado->userId]);
$cuenta = $fila->fetch(PDO::FETCH_ASSOC);
assertCondition($cuenta !== false && $cuenta['role'] === 'editor', 'La cuenta nace con el rol técnico `editor`');
assertCondition($cuenta !== false && $cuenta['lineage'] === null && $cuenta['clan_id'] === null, 'Ni linaje ni espejo de clan: la afiliación es asunto de SPEC-07');

$vinculo = $authService->bind('novicia@arcano.arc', 'frase de paso larga', $hora);
assertCondition($vinculo->success && $vinculo->session !== null, 'La consagración deja sesión iniciada (RF-01.2)');

// --- FASE 2: clanId legado ignorado en silencio ---
echo "\nFASE 2: `clanId` legado ignorado en silencio (criterio 2)\n";
$legado = null;
try {
    $legado = $authService->consecrate('Mareante en Caché', 'mareante@arcano.arc', 'frase de paso larga', 'cln_primordial', $hora);
} catch (Throwable $fracaso) {
    $legado = $fracaso;
}
assertCondition($legado instanceof Grimorio\Services\ConsecrationResult, 'Un `clanId` legado no rompe la consagración: se ignora sin error (plan §5.8)');
assertCondition(
    $legado instanceof Grimorio\Services\ConsecrationResult
    && (int) $pdo->prepare('SELECT COUNT(*) FROM clan_members WHERE user_id = :id')->execute([':id' => $legado->userId]) > 0
    && (int) $pdo->query("SELECT COUNT(*) FROM clan_members WHERE user_id = '" . $legado->userId . "'")->fetchColumn() === 0,
    'El clan legado no genera membresía alguna en la AUTORIDAD (clan_members)'
);
// Un clanId INEXISTENTE tampoco rompe: se ignora igual (sin validación de
// canon, porque el registro ya no declara linaje ni hermandad).
$inexistente = null;
try {
    $inexistente = $authService->consecrate('Peregrina Errante', 'errante@arcano.arc', 'frase de paso larga', 'cln_fantasma', $hora);
} catch (Throwable $fracaso) {
    $inexistente = $fracaso;
}
assertCondition($inexistente instanceof Grimorio\Services\ConsecrationResult, 'Un `clanId` inexistente tampoco rompe: sin validación de canon en el registro (exclusión 5)');

// --- FASE 3: El contrato 201 del controlador ---
echo "\nFASE 3: El contrato 201 porta `lineage: null` y jamás `clanName` (criterio)\n";
$controller = new Grimorio\Controllers\AuthController($pdo, $sessionManager, new Grimorio\Core\RateLimiter($pdo));
$request = new Grimorio\Core\Request('POST', '/api/v1/auth/consecrate', [], [], (string) json_encode([
    'alias'      => 'Contrato Nuevo',
    'email'      => 'contrato@arcano.arc',
    'passphrase' => 'frase de paso larga',
]));
$respuesta = $controller->consecrate($request);
$cuerpo = json_decode($respuesta->getBody(), true);
assertCondition($respuesta->getStatusCode() === 201, 'La consagración responde 201');
assertCondition(
    isset($cuerpo['data']['user']) && array_key_exists('lineage', $cuerpo['data']['user']) && $cuerpo['data']['user']['lineage'] === null,
    'El contrato porta `user.lineage: null` (plan §2.2)'
);
assertCondition(
    !array_key_exists('clanId', $cuerpo['data']['user']) && !array_key_exists('clanName', $cuerpo['data']['user']),
    'El contrato ya no porta `clanId` ni `clanName`'
);

// Con clanId legado en el payload: mismo contrato, misma peregrina.
$requestLegado = new Grimorio\Core\Request('POST', '/api/v1/auth/consecrate', [], [], (string) json_encode([
    'alias'      => 'Contrato Legado',
    'email'      => 'legado@arcano.arc',
    'passphrase' => 'frase de paso larga',
    'clanId'     => 'cln_primordial',
]));
$respuestaLegado = $controller->consecrate($requestLegado);
$cuerpoLegado = json_decode($respuestaLegado->getBody(), true);
assertCondition(
    $respuestaLegado->getStatusCode() === 201
    && isset($cuerpoLegado['data']['user']) && array_key_exists('lineage', $cuerpoLegado['data']['user']) && $cuerpoLegado['data']['user']['lineage'] === null,
    'El payload con `clanId` legado produce la misma 201 peregrina'
);

// --- FASE 4: La sesión expone lineage (criterio 3) ---
echo "\nFASE 4: La sesión (auth/me) expone `lineage` para el store (criterio 3)\n";
$peregrinaId = $resultado->userId;
$requestSession = new Grimorio\Core\Request('GET', '/api/v1/auth/session');
$requestSession->setUser(Grimorio\Models\User::fromDatabaseRow([
    'id' => $peregrinaId, 'alias' => 'Novicia del Alba', 'email' => 'novicia@arcano.arc',
    'role' => 'editor', 'clan_id' => null, 'lineage' => null,
    'password_hash' => 'x', 'created_at' => '2026-09-18T10:00:00Z', 'updated_at' => '2026-09-18T10:00:00Z',
]));
$respuestaSession = $controller->session($requestSession);
$cuerpoSession = json_decode($respuestaSession->getBody(), true);
$usuarioSession = $cuerpoSession['data']['user'] ?? null;
assertCondition(
    is_array($usuarioSession) && array_key_exists('lineage', $usuarioSession) && $usuarioSession['lineage'] === null,
    '`auth/session` retorna `lineage` (nulo para la peregrina): el store se hidrata sin round-trip extra'
);

// Y para una linajada: el linaje jurado viaja.
$requestLinajada = new Grimorio\Core\Request('GET', '/api/v1/auth/session');
$requestLinajada->setUser(Grimorio\Models\User::fromDatabaseRow([
    'id' => 'usr_jurada', 'alias' => 'Jurada de la Llama', 'email' => 'jurada@arcano.arc',
    'role' => 'editor', 'clan_id' => 'cln_primordial', 'lineage' => 'primordialFlame',
    'password_hash' => 'x', 'created_at' => '2026-09-18T10:00:00Z', 'updated_at' => '2026-09-18T10:00:00Z',
]));
$cuerpoLinajada = json_decode($controller->session($requestLinajada)->getBody(), true);
assertCondition(
    ($cuerpoLinajada['data']['user']['lineage'] ?? null) === 'primordialFlame',
    'La linajada expone su linaje jurado en la sesión (RF-04.3: heráldica tras jurar)'
);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La consagración nace peregrina: sin clan, con sesión y contrato lineage exacto (Tarea 2.5).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);

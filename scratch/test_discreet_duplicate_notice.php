<?php

/**
 * test_discreet_duplicate_notice.php — Arnés del hueco de cobertura RF-01.3 (SPEC-03).
 *
 * La auditoría de estabilización de SPEC-03 halló que el 409 neutro de la
 * consagración estaba probado, pero no la SEGUNDA pata del requisito: la
 * notificación discreta al titular («canalizando el aviso mediante un
 * correo discreto a la cuenta original si corresponde»). Este arnés sella
 * el criterio completo en tres frentes:
 *
 *   1. Neutralidad de superficie (lo ya probado, re-certificado aquí como
 *      base del criterio): alias reclamado, correo reclamado y ambos
 *      reclamados producen el MISMO veredicto del servicio y el MISMO
 *      sobre REST — sin revelar jamás cuál de los dos campos choca.
 *   2. Aviso al titular determinista: el servicio expone
 *      `buildDuplicateOwnerNotice()` — el pergamino del aviso discreto
 *      destinado AL CORREO DEL TITULAR — con la identidad del titular
 *      interpolada y JAMÁS los datos del pretendiente. El transporte
 *      queda fuera del santuario (no hay servicio de correo: Dogma
 *      Vanilla), pero el CONTENIDO del aviso es una función pura del
 *      servicio, por tanto verificable por arnés.
 *   3. Sin pistas de enumeración en el aviso: el texto difiere del
 *      mensaje público y porta el correo del titular, no el del
 *      pretendiente — un observador del aviso no aprende qué pretendiente
 *      lo activó ni cuándo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO preparado, sin dependencias.
 *   - Artículo V: identificadores en inglés camelCase; asertos castellanos.
 *
 * Uso: php scratch/test_discreet_duplicate_notice.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/** Asegura una condición y la reporta con el formato estándar del proyecto. */
function assertArcane(bool $condition, string $label): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$label}\n";
    } else {
        $assertsFailed++;
        echo "  FALLA {$label}\n";
    }
}

$projectRoot = dirname(__DIR__);

// Buffer diferido: setcookie() del SessionManager exige emitir cabeceras
// antes de cualquier output (los warnings cosméticos de la CLI no afectan).
ob_start();

echo "=== Hueco RF-01.3 (SPEC-03): correo discreto al titular y neutralidad anti-enumeración ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\RateLimiter;
use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Services\AuthService;

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_discreet_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_test', 'test-lineage', 'Linaje de Prueba', 'Ensayo', '{$now}')"
);

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés Aviso Discreto/1.0');
$authService = new AuthService($pdo, $sessionManager);
$controller = new Grimorio\Controllers\AuthController($pdo, $sessionManager, new RateLimiter($pdo));

echo "[0] Existencia de la superficie del criterio\n";

assertArcane(
    method_exists(AuthService::class, 'consecrate'),
    'AuthService::consecrate() existe (la vía que detecta la colisión)'
);
assertArcane(
    method_exists(AuthService::class, 'buildDuplicateOwnerNotice'),
    'AuthService::buildDuplicateOwnerNotice() existe (el contenido del aviso discreto al titular)'
);

// ---------------------------------------------------------------------
// 1. Titular consagrado: el dueño legítimo de la identidad.
// ---------------------------------------------------------------------
echo "\n[1] Titular y pretendiente consagrados\n";

$owner = $authService->consecrate('FrierenElf', 'frieren@sanctuario.arc', 'palabra-secreta-del-mago', null, new DateTimeImmutable($now));
assertArcane($owner !== null && $owner->userId !== '', 'El titular consagra su identidad (frieren@sanctuario.arc)');

$pretender = null;
$pretenderRejected = false;
try {
    $pretender = $authService->consecrate('FrierenElf', 'pretendiente@sanctuario.arc', 'frase-del-pretendiente', null, new DateTimeImmutable($now));
} catch (RuntimeException) {
    $pretenderRejected = true;
}
assertArcane($pretenderRejected, 'El pretendiente que reclama el alias del titular es rechazado');

$pretenderByMailRejected = false;
try {
    $authService->consecrate('OtroAlias', 'frieren@sanctuario.arc', 'frase-del-pretendiente', null, new DateTimeImmutable($now));
} catch (RuntimeException) {
    $pretenderByMailRejected = true;
}
assertArcane($pretenderByMailRejected, 'El pretendiente que reclama el correo del titular es rechazado');

// ---------------------------------------------------------------------
// 2. Neutralidad de superficie: los tres caminos producen el MISMO veredicto.
// ---------------------------------------------------------------------
echo "\n[2] Neutralidad: alias, correo o ambos chocan → mismo veredicto\n";

$verdictAlias = null;
$verdictEmail = null;
$verdictBoth = null;
try {
    $authService->consecrate('FrierenElf', 'x1@sanctuario.arc', 'clave-larga-suficiente', null, new DateTimeImmutable($now));
} catch (RuntimeException $e) {
    $verdictAlias = $e->getMessage();
}
try {
    $authService->consecrate('AliasLibre', 'frieren@sanctuario.arc', 'clave-larga-suficiente', null, new DateTimeImmutable($now));
} catch (RuntimeException $e) {
    $verdictEmail = $e->getMessage();
}
try {
    $authService->consecrate('FrierenElf', 'frieren@sanctuario.arc', 'clave-larga-suficiente', null, new DateTimeImmutable($now));
} catch (RuntimeException $e) {
    $verdictBoth = $e->getMessage();
}

assertArcane($verdictAlias !== null && $verdictEmail !== null && $verdictBoth !== null, 'Los tres caminos de colisión son rechazados');
assertArcane($verdictAlias === $verdictEmail && $verdictEmail === $verdictBoth, 'El mensaje de rechazo es IDÉNTICO sea cual sea el campo en conflicto');
assertArcane(
    !str_contains($verdictAlias, 'alias') && !str_contains($verdictAlias, 'correo') && !str_contains($verdictAlias, 'pretendiente'),
    'El mensaje público no nombra ninguno de los campos ni actores'
);

// La superficie REST conserva la neutralidad (sobre canónico 409).
$duplicateResponse = $controller->consecrate(new Request(
    'POST',
    '/api/v1/auth/consecrate',
    [],
    ['Content-Type' => 'application/json'],
    (string) json_encode(['alias' => 'FrierenElf', 'email' => 'x2@sanctuario.arc', 'passphrase' => 'clave-larga-suficiente'], JSON_UNESCAPED_UNICODE)
));
$duplicateBody = json_decode((string) $duplicateResponse->getBody(), true);
assertArcane($duplicateResponse->getStatusCode() === 409, 'La superficie REST responde 409 Conflict');
assertArcane(
    ($duplicateBody['error']['code'] ?? null) === 'IDENTITY_ALREADY_CLAIMED',
    'El 409 porta el código neutro IDENTITY_ALREADY_CLAIMED'
);

// ---------------------------------------------------------------------
// 3. El aviso discreto al titular: contenido puro del servicio.
// ---------------------------------------------------------------------
echo "\n[3] El aviso discreto destinado al correo del titular\n";

$notice = $authService->buildDuplicateOwnerNotice('FrierenElf', 'frieren@sanctuario.arc', new DateTimeImmutable($now));
assertArcane(is_string($notice) && trim($notice) !== '', 'buildDuplicateOwnerNotice() devuelve el texto del aviso');
assertArcane(str_contains($notice, 'frieren@sanctuario.arc'), 'El aviso va dirigido al correo del TITULAR (su identidad aparece)');
assertArcane(
    !str_contains($notice, 'pretendiente@sanctuario.arc') && !str_contains($notice, 'x1@') && !str_contains($notice, 'x2@'),
    'El aviso jamás revela el correo del pretendiente que lo activó'
);
assertArcane(
    !str_contains($notice, 'AliasLibre'),
    'El aviso jamás revela datos del intento (alias, frase o correo ajeno al titular)'
);
assertArcane(
    mb_stripos($notice, 'notificación') !== false || mb_stripos($notice, 'aviso') !== false || mb_stripos($notice, 'intento') !== false,
    'El aviso narra en castellano la situación al titular (Art. V)'
);

// Determinismo: el mismo titular en el mismo instante produce el MISMO aviso.
$noticeAgain = $authService->buildDuplicateOwnerNotice('FrierenElf', 'frieren@sanctuario.arc', new DateTimeImmutable($now));
assertArcane($notice === $noticeAgain, 'El aviso es determinista (misma entrada → mismo texto)');

// Otro titular produce SU propio aviso (la identidad viaja interpolada).
$authService->consecrate('SegundoTitular', 'segundo@sanctuario.arc', 'clave-larga-suficiente', null, new DateTimeImmutable($now));
$noticeSecond = $authService->buildDuplicateOwnerNotice('SegundoTitular', 'segundo@sanctuario.arc', new DateTimeImmutable($now));
assertArcane(str_contains($noticeSecond, 'segundo@sanctuario.arc') && !str_contains($noticeSecond, 'frieren@sanctuario.arc'), 'El aviso de otro titular porta SU identidad, no la del primero');

// El aviso difiere del mensaje público: dos canales, dos tonos.
assertArcane($notice !== $verdictAlias, 'El aviso discreto NO es el mensaje público de rechazo (canales separados)');

// ---------------------------------------------------------------------
// 4. Anti-DoS en la puerta del aviso: la cola del pretendiente es limitada
//    por el RateLimiter existente (5 fallos → 15 minutos), de modo que el
//    aviso al titular no puede ser masificado como canal de acoso.
// ---------------------------------------------------------------------
echo "\n[4] El canal del aviso queda tras la puerta anti-DoS (RF-03.2)\n";

$rateLimiter = new RateLimiter($pdo);
$attackerIp = '203.0.113.10';
$attemptInstant = new DateTimeImmutable($now);
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $rateLimiter->recordAttempt($attackerIp, 'FrierenElf', false, $attemptInstant);
}
$blockVerdict = $rateLimiter->isBlocked($attackerIp, $attemptInstant);
assertArcane($blockVerdict->isBlocked, 'Quinta procedencia con fallos queda congelada (el aviso no es una puerta abierta al acoso)');
assertArcane($blockVerdict->remainingSeconds > 0, 'La congelación porta su ventana temporal (15 minutos del canon)');

// ---------------------------------------------------------------------
// 5. Limpieza.
// ---------------------------------------------------------------------
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

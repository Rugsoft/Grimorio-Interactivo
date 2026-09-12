<?php

/**
 * test_rate_limiter.php — Arnés de la Tarea 2.2 de TASKS-03.
 *
 * Verifica el sistema de defensa anti-DoS y fuerza bruta por IP
 * src/Core/RateLimiter.php: registro de intentos en login_attempts,
 * bloqueo tras 5 fallos en una ventana de 15 minutos, cálculo de los
 * segundos restantes de bloqueo y ausencia de daños colaterales a otras
 * IPs o a usuarios legítimos (RF-03.2).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el servicio.
 * Fase roja = la clase Grimorio\Core\RateLimiter no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Tras registrar 5 intentos fallidos para una misma IP,
 *      isBlocked($ip) devuelve verdadero junto con el tiempo restante.
 *   2. Sin bloquear a otras IPs ni a usuarios legítimos.
 *   3. Extras estructurales: registro de intentos, ventana deslizante
 *      (fallos antiguos fuera de la ventana no bloquean), éxitos no
 *      computan como fallo, y reinicio parcial tras la ventana.
 *
 * Ejecución: php scratch/test_rate_limiter.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 */
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

echo "=== Tarea 2.2 (TASKS-03): Defensa anti-DoS y fuerza bruta por IP ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\RateLimiter;

echo "[0] Existencia y cargabilidad del servicio\n";

assertArcane(class_exists(RateLimiter::class), 'La clase Grimorio\Core\RateLimiter existe y el autocompilador la resuelve');

if (!class_exists(RateLimiter::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo (Tarea 1.1).
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_rate_limiter_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

/** Reconstruye un DateTimeImmutable UTC desde texto ISO 8601. */
function instantOf(string $isoDateTime): DateTimeImmutable
{
    return new DateTimeImmutable($isoDateTime);
}

$attackerIp = '203.0.113.66';
$victimIp = '198.51.100.7';
$legitIp = '192.0.2.15';

$rateLimiter = new RateLimiter($pdo);

// ---------------------------------------------------------------------
// 1. Registro de intentos (recordAttempt).
// ---------------------------------------------------------------------
echo "\n[1] Registro de intentos en login_attempts\n";

$recordOk = true;
try {
    $rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', false, instantOf('2026-09-12T12:00:00Z'));
} catch (Throwable $recordError) {
    $recordOk = false;
    echo '  Excepción: ' . $recordError->getMessage() . "\n";
}
assertArcane($recordOk, 'recordAttempt() registra un intento fallido sin errores');

$storedRow = $pdo->query(
    "SELECT ip_address, attempted_identity, is_success FROM login_attempts WHERE ip_address = '{$attackerIp}'"
)->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($storedRow) && $storedRow['is_success'] == 0 && $storedRow['attempted_identity'] === 'frieren@sanctuario.arc',
    'El intento queda persistido con su identidad e is_success = 0'
);

// ---------------------------------------------------------------------
// 2. Por debajo del umbral: permitido.
// ---------------------------------------------------------------------
echo "\n[2] Debajo del umbral de 5 fallos\n";

// Cuatro fallos más (total 5 vendrá después; ahora 1 registrado, añadimos 3).
for ($failureIndex = 0; $failureIndex < 3; $failureIndex++) {
    $rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', false, instantOf('2026-09-12T12:0' . ($failureIndex + 1) . ':00Z'));
}
$rateLimiter->recordAttempt($victimIp, 'otra@victima.arc', false, instantOf('2026-09-12T12:02:00Z'));

$attackerVerdict = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:03:00Z'));
assertArcane($attackerVerdict->isBlocked === false, 'Con 4 fallos en la ventana, la IP sigue permitida');
assertArcane($attackerVerdict->remainingSeconds === 0, 'Sin bloqueo, remainingSeconds es 0');

$victimVerdict = $rateLimiter->isBlocked($victimIp, instantOf('2026-09-12T12:03:00Z'));
assertArcane($victimVerdict->isBlocked === false, 'Otra IP con 1 fallo sigue permitida (sin daños colaterales)');

// ---------------------------------------------------------------------
// 3. Umbral alcanzado: bloqueo con tiempo restante (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[3] Bloqueo tras 5 fallos con tiempo restante\n";

$rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', false, instantOf('2026-09-12T12:04:00Z'));

$blockedVerdict = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:05:00Z'));
assertArcane($blockedVerdict->isBlocked === true, 'Con 5 fallos en la ventana, isBlocked() devuelve verdadero');
assertArcane(
    $blockedVerdict->remainingSeconds > 0 && $blockedVerdict->remainingSeconds <= 15 * 60,
    'remainingSeconds reporta el tiempo restante dentro de la ventana (' . $blockedVerdict->remainingSeconds . ' s)'
);

// Precisión del cálculo: el último fallo fue 12:04:00; el bloqueo expira a
// las 12:19:00. Consultado a las 12:05:00 → quedan 14 minutos = 840 s.
assertArcane(
    $blockedVerdict->remainingSeconds === 840,
    'El cálculo es exacto: 840 s restantes (último fallo a las 12:04, ventana de 15 min)'
);

// La IP víctima NO se bloquea aunque el atacante sí (RF-03.2).
$victimVerdictAfter = $rateLimiter->isBlocked($victimIp, instantOf('2026-09-12T12:05:00Z'));
assertArcane($victimVerdictAfter->isBlocked === false, 'La IP de la víctima legítima NO queda bloqueada por el atacante');

// ---------------------------------------------------------------------
// 4. Los éxitos no computan como fallo y no desbloquean.
// ---------------------------------------------------------------------
echo "\n[4] Éxitos no computan ni desbloquean\n";

$rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', true, instantOf('2026-09-12T12:06:00Z'));
$stillBlocked = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:06:30Z'));
assertArcane($stillBlocked->isBlocked === true, 'Un intento exitoso no levanta el bloqueo de la IP congelada');

// El fallo nº 6 dentro del bloqueo no extiende el castigo más allá de la ventana.
$prePurgeCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM login_attempts WHERE ip_address = '{$attackerIp}'"
)->fetchColumn();
assertArcane($prePurgeCount === 6, 'El intento exitoso también queda registrado para la bitácora (6 filas)');

// ---------------------------------------------------------------------
// 5. Ventana deslizante: los fallos caducan solos.
// ---------------------------------------------------------------------
echo "\n[5] Ventana deslizante de 15 minutos\n";

// 16 minutos después del último fallo (12:04), todos los fallos han
// salido de la ventana → la IP vuelve a estar permitida.
$afterWindow = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:21:00Z'));
assertArcane($afterWindow->isBlocked === false, 'Pasados los 15 minutos, la IP queda desbloqueada (ventana deslizante)');

// Nueva ráfaga: los fallos antiguos NO cuentan con los nuevos.
for ($newRaid = 0; $newRaid < 4; $newRaid++) {
    $rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', false, instantOf('2026-09-12T12:22:' . str_pad((string) $newRaid, 2, '0', STR_PAD_LEFT) . 'Z'));
}
$partialRaid = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:23:00Z'));
assertArcane($partialRaid->isBlocked === false, 'Solo los 4 fallos nuevos cuentan: aún por debajo del umbral');

$rateLimiter->recordAttempt($attackerIp, 'frieren@sanctuario.arc', false, instantOf('2026-09-12T12:24:00Z'));
$reBlocked = $rateLimiter->isBlocked($attackerIp, instantOf('2026-09-12T12:25:00Z'));
assertArcane($reBlocked->isBlocked === true, 'El 5º fallo nuevo vuelve a congelar la IP (reinicio limpio del contador)');

// ---------------------------------------------------------------------
// 6. Usuario legítimo de otra IP no se bloquea jamás (RF-03.2).
// ---------------------------------------------------------------------
echo "\n[6] Usuarios legítimos sin daño colateral\n";

$legitVerdict = $rateLimiter->isBlocked($legitIp, instantOf('2026-09-12T12:25:00Z'));
assertArcane($legitVerdict->isBlocked === false, 'La IP de un usuario legítimo jamás ha sido bloqueada');

// Un login exitoso desde la IP legítima tampoco la congela.
$rateLimiter->recordAttempt($legitIp, 'maestro@sanctuario.arc', true, instantOf('2026-09-12T12:25:30Z'));
$legitAfterSuccess = $rateLimiter->isBlocked($legitIp, instantOf('2026-09-12T12:26:00Z'));
assertArcane($legitAfterSuccess->isBlocked === false, 'Un login exitoso legítimo no provoca bloqueo');

// ---------------------------------------------------------------------
// Limpieza del sandbox.
// ---------------------------------------------------------------------
$pdo = null;
gc_collect_cycles();
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

<?php

/**
 * test_auth_middleware.php — Arnés de la Tarea 3.1 de TASKS-03.
 *
 * Verifica el middleware de autenticación src/Middleware/AuthMiddleware.php:
 * lee la cookie de sesión, valida su vigencia en user_sessions, actualiza
 * last_activity_at (vía SessionManager::resolveSession, Tarea 2.1) e
 * inyecta la entidad User activa en la petición — o un anónimo reader si
 * no hay vínculo.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el middleware.
 * Fase roja = la clase Grimorio\Middleware\AuthMiddleware no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Las peticiones autenticadas tienen acceso a $request->getUser()
 *      con latencia de resolución menor a 50 ms.
 *   2. Extras estructurales: anónimo reader sin cookie, sesión revocada
 *      degrada a anónimo en vivo, userId sin fila (cuenta borrada) cae a
 *      anónimo, y la petición jamás queda sin usuario inyectado.
 *
 * Ejecución: php scratch/test_auth_middleware.php  (exit 0 = verde)
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

// Buffer diferido: setcookie() del SessionManager exige emitir cabeceras
// antes de cualquier output; los warnings cosméticos de la SAPI CLI no
// afectan a las aserciones de este arnés.
ob_start();

echo "=== Tarea 3.1 (TASKS-03): Middleware de autenticación e inyección de contexto ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\Request;
use Grimorio\Core\SessionManager;
use Grimorio\Middleware\AuthMiddleware;
use Grimorio\Models\User;

echo "[0] Existencia y cargabilidad del middleware\n";

assertArcane(class_exists(AuthMiddleware::class), 'La clase Grimorio\Middleware\AuthMiddleware existe y el autocompilador la resuelve');

if (!class_exists(AuthMiddleware::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Sandbox: base SQLite efímera con el esquema completo.
// ---------------------------------------------------------------------
$sandboxDb = sys_get_temp_dir() . '/grimorio_auth_mw_' . getmypid() . '.sqlite';
if (file_exists($sandboxDb)) {
    @unlink($sandboxDb);
}

$pdo = new PDO('sqlite:' . $sandboxDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-12T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, domain_points, created_at)
     VALUES ('cln_test', 'test-lineage', 'Linaje de Prueba', 'Ensayo', 0, '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_mw_01', 'MaestroVinculado', 'vinculado@test.arc', '" . str_repeat('x', 60) . "', 'master', 'cln_test', '{$now}', '{$now}')"
);

$sessionManager = new SessionManager($pdo, '127.0.0.1', 'Arnés AuthMW/1.0');
$authMiddleware = new AuthMiddleware($pdo, $sessionManager);

// ---------------------------------------------------------------------
// 1. Petición anónima (sin cookie): usuario reader por defecto.
// ---------------------------------------------------------------------
echo "\n[1] Visitante anónimo degrada a reader\n";

$anonymousRequest = new Request('GET', '/api/v1/spells');
$authMiddleware->injectContext($anonymousRequest, new DateTimeImmutable($now));

$anonymousUser = $anonymousRequest->getUser();
assertArcane($anonymousUser !== null, 'La petición anónima JAMÁS queda sin usuario inyectado');
assertArcane($anonymousUser !== null && $anonymousUser->getRole() === 'reader', 'El anónimo porta el rol reader (acceso público RF-05.1)');
assertArcane($anonymousUser !== null && $anonymousUser->getId() === '', 'El anónimo no porta identificador de cuenta');

// ---------------------------------------------------------------------
// 2. Petición autenticada: inyección de la entidad User real.
// ---------------------------------------------------------------------
echo "\n[2] Petición autenticada inyecta el usuario activo\n";

$boundSession = $sessionManager->createSession('usr_mw_01', new DateTimeImmutable($now));
// El middleware lee la cookie de la superglobal (simulando al cliente):
// la sembramos con el token crudo que el SessionManager acaba de emitir.
$_COOKIE['grimorio_session'] = $boundSession->getToken();
$authenticatedRequest = new Request('GET', '/api/v1/auth/session');

$injectionStart = hrtime(true);
$authMiddleware->injectContext($authenticatedRequest, new DateTimeImmutable($now));
$injectionNanoseconds = hrtime(true) - $injectionStart;
$injectionMilliseconds = $injectionNanoseconds / 1e6;

$activeUser = $authenticatedRequest->getUser();
assertArcane($activeUser !== null && $activeUser->getId() === 'usr_mw_01', 'La petición autenticada porta la entidad User del titular');
assertArcane($activeUser !== null && $activeUser->getRole() === 'master', 'El rol inyectado es el real de la cuenta (master)');
assertArcane($activeUser !== null && $activeUser->getAlias() === 'MaestroVinculado', 'El alias inyectado es el real de la cuenta');
assertArcane($activeUser !== null && $activeUser->getClanId() === 'cln_test', 'El clan inyectado es el real de la cuenta');
assertArcane($activeUser !== null && $activeUser->isMaster(), 'isMaster() refleja la jerarquía real para el RbacMiddleware');
assertArcane(
    $injectionMilliseconds < 50,
    'Latencia de resolución ' . round($injectionMilliseconds, 2) . ' ms < 50 ms (criterio «Hecho cuando»)'
);

// ---------------------------------------------------------------------
// 3. Sesión revocada en vivo: degrada a anónimo.
// ---------------------------------------------------------------------
echo "\n[3] Revocación en vivo degrada a anónimo\n";

$revokedSession = $sessionManager->createSession('usr_mw_01', new DateTimeImmutable($now));
$sessionManager->dissolveSession($revokedSession->getToken());
$_COOKIE['grimorio_session'] = $revokedSession->getToken(); // El cliente aún porta la cookie revocada.

$revokedRequest = new Request('GET', '/api/v1/spells');
$authMiddleware->injectContext($revokedRequest, new DateTimeImmutable($now));
$revokedUser = $revokedRequest->getUser();
assertArcane($revokedUser !== null && $revokedUser->getRole() === 'reader', 'Un token revocado degrada a anónimo reader sin errores');

// ---------------------------------------------------------------------
// 4. Sesión caducada por el tope absoluto: degrada a anónimo.
// ---------------------------------------------------------------------
echo "\n[4] Caducidad absoluta degrada a anónimo\n";

$expiredSession = $sessionManager->createSession('usr_mw_01', new DateTimeImmutable('2026-08-01T00:00:00Z'));
$_COOKIE['grimorio_session'] = $expiredSession->getToken();
$expiredRequest = new Request('GET', '/api/v1/spells');
// 31 días después: tope absoluto rebasado (máquina de estados, plan 3.2).
$authMiddleware->injectContext($expiredRequest, new DateTimeImmutable('2026-09-01T12:00:00Z'));
$expiredUser = $expiredRequest->getUser();
assertArcane($expiredUser !== null && $expiredUser->getRole() === 'reader', 'Un vínculo rebasado por el tope absoluto degrada a anónimo reader');

// ---------------------------------------------------------------------
// 5. Huérfano: la sesión existe pero la cuenta fue borrada (RF-09).
// ---------------------------------------------------------------------
echo "\n[5] Cuenta borrada con sesión viva degrada a anónimo\n";

$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_ghost', 'FantasmaEfimero', 'fantasma@test.arc', '" . str_repeat('y', 60) . "', 'editor', 'cln_test', '{$now}', '{$now}')"
);
$ghostSession = $sessionManager->createSession('usr_ghost', new DateTimeImmutable($now));
$_COOKIE['grimorio_session'] = $ghostSession->getToken();
// La cuenta desaparece (derecho al olvido, RF-09.1); la sesión huérfana
// queda sin titular por la FK ON DELETE CASCADE, pero se prueba el
// camino defensivo del middleware: sin fila de usuario → anónimo.
$pdo->exec("DELETE FROM users WHERE id = 'usr_ghost'");

$ghostRequest = new Request('GET', '/api/v1/spells');
$authMiddleware->injectContext($ghostRequest, new DateTimeImmutable($now));
$ghostUser = $ghostRequest->getUser();
assertArcane($ghostUser !== null && $ghostUser->getRole() === 'reader', 'Una sesión sin cuenta titular degrada a anónimo reader (defensa en profundidad)');

// ---------------------------------------------------------------------
// 6. Rendimiento sostenido: media de 25 resoluciones autenticadas.
// ---------------------------------------------------------------------
echo "\n[6] Latencia sostenida bajo réplicas\n";

$sustainedSession = $sessionManager->createSession('usr_mw_01', new DateTimeImmutable($now));
$_COOKIE['grimorio_session'] = $sustainedSession->getToken();
$latencySamples = [];
for ($sampleIndex = 0; $sampleIndex < 25; $sampleIndex++) {
    $repeatRequest = new Request('GET', '/api/v1/spells');
    $sampleStart = hrtime(true);
    $authMiddleware->injectContext($repeatRequest, new DateTimeImmutable($now));
    $latencySamples[] = (hrtime(true) - $sampleStart) / 1e6;
}
$averageLatency = array_sum($latencySamples) / count($latencySamples);
assertArcane(
    $averageLatency < 50,
    'Latencia media de 25 resoluciones: ' . round($averageLatency, 3) . ' ms < 50 ms'
);

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
ob_end_flush();
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

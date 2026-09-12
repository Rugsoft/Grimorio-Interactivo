<?php

/**
 * test_rbac_middleware.php — Arnés de la Tarea 3.2 de TASKS-03.
 *
 * Verifica el middleware de control de acceso por roles
 * src/Middleware/RbacMiddleware.php: la matriz jerárquica de los 4 roles
 * canónicos (reader < editor < master < supremeAdmin) y la respuesta
 * 403 Forbidden con leyenda de jerarquía insuficiente (RF-05.1, RF-05.2).
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar el middleware.
 * Fase roja = la clase Grimorio\Middleware\RbacMiddleware no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Un usuario con rol editor intentando acceder a una ruta de master
 *      recibe un error 403 Forbidden con el mensaje místico correspondiente.
 *   2. Extras estructurales: los 4 roles atraviesan la matriz completa
 *      (jerarquía acumulativa), el anónimo es reader, rutas públicas
 *      permitidas a todos, y el cuerpo del 403 es JSON canónico del
 *      proyecto (success/error.code/message).
 *
 * Ejecución: php scratch/test_rbac_middleware.php  (exit 0 = verde)
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

echo "=== Tarea 3.2 (TASKS-03): Middleware de control de acceso por roles ===\n\n";

require_once $projectRoot . '/public/index.php'; // Autoload nativo del proyecto.

use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Middleware\RbacMiddleware;
use Grimorio\Models\User;

echo "[0] Existencia y cargabilidad del middleware\n";

assertArcane(class_exists(RbacMiddleware::class), 'La clase Grimorio\Middleware\RbacMiddleware existe y el autocompilador la resuelve');

if (!class_exists(RbacMiddleware::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

$rbacMiddleware = new RbacMiddleware();

/**
 * Forja un usuario con el rol canónico indicado.
 */
function forgeRbacUser(string $role): User
{
    return new User(
        id: $role === 'reader' ? '' : 'usr_' . $role,
        alias: 'Iniciado' . ucfirst($role),
        email: $role . '@test.arc',
        role: $role,
        clanId: 'cln_test',
        passwordHash: str_repeat('x', 60),
        createdAt: '2026-09-12T12:00:00Z',
        updatedAt: '2026-09-12T12:00:00Z',
    );
}

/**
 * Ejecuta el middleware contra una petición con el rol dado y devuelve
 * la Response resultante (null = acceso concedido, continúa el flujo).
 */
function probeRbac(RbacMiddleware $middleware, string $requiredPrivilege, string $role): ?Response
{
    $request = new Request('POST', '/api/v1/protegida');
    $request->setUser(forgeRbacUser($role));
    return $middleware->authorize($request, $requiredPrivilege);
}

// ---------------------------------------------------------------------
// 1. Matriz jerárquica completa (RF-05.1): 4 roles × 4 privilegios.
// ---------------------------------------------------------------------
echo "\n[1] Matriz jerárquica de los 4 roles canónicos\n";

// Jerarquía acumulativa: cada rol hereda todo lo del anterior (RF-05.1).
$expectedMatrix = [
    // [rol solicitante => privilegios permitidos]
    'reader'       => ['reader'],
    'editor'       => ['reader', 'editor'],
    'master'       => ['reader', 'editor', 'master'],
    'supremeAdmin' => ['reader', 'editor', 'master', 'supremeAdmin'],
];

foreach ($expectedMatrix as $roleName => $allowedPrivileges) {
    foreach (['reader', 'editor', 'master', 'supremeAdmin'] as $requiredPrivilege) {
        $verdict = probeRbac($rbacMiddleware, $requiredPrivilege, $roleName);
        $isAllowed = $verdict === null;

        if (in_array($requiredPrivilege, $allowedPrivileges, true)) {
            assertArcane(
                $isAllowed,
                "'{$roleName}' ALCANZA el privilegio '{$requiredPrivilege}'"
            );
        } else {
            assertArcane(
                !$isAllowed && $verdict !== null && $verdict->getStatusCode() === 403,
                "'{$roleName}' RECIBE 403 para el privilegio '{$requiredPrivilege}' (jerarquía insuficiente)"
            );
        }
    }
}

// ---------------------------------------------------------------------
// 2. Criterio «Hecho cuando»: editor sobre ruta de master → 403 + mensaje.
// ---------------------------------------------------------------------
echo "\n[2] Editor sobre ruta de master: 403 con mensaje místico\n";

$editorRequest = new Request('POST', '/api/v1/spells/llamas-de-frieren/sign');
$editorRequest->setUser(forgeRbacUser('editor'));
$editorVerdict = $rbacMiddleware->authorize($editorRequest, 'master');

assertArcane($editorVerdict !== null && $editorVerdict->getStatusCode() === 403, 'El editor recibe 403 Forbidden sobre la ruta de master');

$editorBody = json_decode((string) $editorVerdict->getBody(), true);
assertArcane(is_array($editorBody) && ($editorBody['success'] ?? null) === false, 'El cuerpo del 403 porta success: false (JSON canónico)');
assertArcane(
    is_array($editorBody) && isset($editorBody['error']['code']) && $editorBody['error']['code'] === 'INSUFFICIENT_HIERARCHY',
    'El error porta el código técnico INSUFFICIENT_HIERARCHY'
);
assertArcane(
    is_array($editorBody) && str_contains((string) ($editorBody['error']['message'] ?? ''), 'jerarquía'),
    'La leyenda mística menciona la jerarquía insuficiente (RF-05.2)'
);
assertArcane(
    (string) $editorVerdict->getHeader('Content-Type') === 'application/json; charset=utf-8',
    'El 403 viaja con Content-Type JSON estándar del proyecto'
);

// ---------------------------------------------------------------------
// 3. Anónimo: tratado como reader (sin sesión iniciada).
// ---------------------------------------------------------------------
echo "\n[3] Visitante anónimo como reader\n";

$anonymousRequest = new Request('GET', '/api/v1/audit/log');
// Sin setUser: el middleware debe defenderse de un contexto sin usuario.
$anonymousVerdict = $rbacMiddleware->authorize($anonymousRequest, 'reader');
assertArcane($anonymousVerdict === null, 'Una petición sin usuario inyectado se trata como reader para rutas públicas');

$anonymousBlocked = $rbacMiddleware->authorize($anonymousRequest, 'editor');
assertArcane($anonymousBlocked !== null && $anonymousBlocked->getStatusCode() === 403, 'El anónimo recibe 403 sobre rutas de editor');

// ---------------------------------------------------------------------
// 4. Los mensajes jamás revelan el rol real del solicitante.
// ---------------------------------------------------------------------
echo "\n[4] Respuesta uniforme sin filtraciones\n";

$readerVerdict = probeRbac($rbacMiddleware, 'supremeAdmin', 'reader');
$masterVerdict = probeRbac($rbacMiddleware, 'supremeAdmin', 'master');

assertArcane(
    $readerVerdict !== null && $masterVerdict !== null
    && $readerVerdict->getBody() === $masterVerdict->getBody(),
    'El cuerpo del 403 es idéntico para cualquier rol insuficiente (sin filtración de contexto)'
);
assertArcane(
    $readerVerdict !== null && $masterVerdict !== null
    && $readerVerdict->getStatusCode() === $masterVerdict->getStatusCode(),
    'El código del 403 es uniforme (403) para cualquier jerarquía insuficiente'
);

// ---------------------------------------------------------------------
// Limpieza: nada persistente que limpiar (arnés sin base de datos).
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

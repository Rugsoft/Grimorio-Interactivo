<?php

/**
 * test_user_entity.php — Arnés de la Tarea 1.2 de TASKS-03.
 *
 * Verifica la entidad de dominio src/Models/User.php: inmutabilidad,
 * tipado estricto, validación de los 4 roles canónicos, métodos de
 * comprobación de jerarquía y serialización JSON segura que jamás
 * expone el hash de la frase de paso.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar la entidad.
 * Fase roja = la clase Grimorio\Models\User no existe todavía.
 *
 * Escenarios verificados (criterio «Hecho cuando» de la tarea):
 *   1. Instanciación que valida estrictamente los 4 roles permitidos.
 *   2. json_encode($user) no expone nunca passwordHash.
 *   3. Extras estructurales: inmutabilidad real, getters tipados,
 *      isMaster()/isSupremeAdmin() y reconstrucción desde fila de BD.
 *
 * Ejecución: php scratch/test_user_entity.php  (exit 0 = verde)
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

echo "=== Tarea 1.2 (TASKS-03): Entidad de dominio User inmutable y tipada ===\n\n";

// ---------------------------------------------------------------------
// Carga de la entidad (autoload nativo del front controller o require
// directo si el arnés corre aislado).
// ---------------------------------------------------------------------
require_once $projectRoot . '/public/index.php'; // Registra el spl_autoload del proyecto.

use Grimorio\Models\User;

echo "[0] Existencia y cargabilidad de la entidad\n";

assertArcane(class_exists(User::class), 'La clase Grimorio\Models\User existe y se autocompilador la resuelve');

if (!class_exists(User::class)) {
    echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
    exit(1);
}

/**
 * Fábrica de usuario válido para los escenarios.
 */
function forgeUser(string $role = 'editor'): User
{
    return new User(
        id: 'usr_test_01',
        alias: 'friki_test',
        email: 'friki@test.arc',
        role: $role,
        clanId: 'cln_test',
        passwordHash: '$2y$12$abcdefghijklmnopqrstuvabcdefghijklmnopqrstuvabcdefghijklmnopqrstu',
        createdAt: '2026-09-12T10:00:00Z',
        updatedAt: '2026-09-12T10:00:00Z',
    );
}

// ---------------------------------------------------------------------
// 1. Validación estricta de los 4 roles canónicos (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[1] Validación estricta de roles canónicos\n";

foreach (['reader', 'editor', 'master', 'supremeAdmin'] as $canonicalRole) {
    $user = null;
    $instantiationFailed = false;
    try {
        $user = forgeUser($canonicalRole);
    } catch (Throwable $roleError) {
        $instantiationFailed = true;
    }
    assertArcane(
        !$instantiationFailed && $user !== null && $user->getRole() === $canonicalRole,
        "Acepta el rol canónico '{$canonicalRole}'"
    );
}

foreach (['wizard_overlord', 'admin', 'EDITOR', '', 'root'] as $forbiddenRole) {
    $rejected = false;
    try {
        forgeUser($forbiddenRole);
    } catch (Throwable $forbiddenRoleError) {
        $rejected = true;
    }
    assertArcane(
        $rejected,
        "Rechaza el rol fuera del canon ('{$forbiddenRole}')"
    );
}

// ---------------------------------------------------------------------
// 2. Serialización JSON segura (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[2] Serialización JSON sin exposición del hash\n";

$serializedUser = forgeUser('master');
$json = json_encode($serializedUser);
assertArcane($json !== false, 'json_encode($user) serializa sin errores');

$jsonPayload = (string) $json;
assertArcane(!str_contains($jsonPayload, 'passwordHash'), 'La serialización jamás expone la clave passwordHash');
assertArcane(!str_contains($jsonPayload, '$2y$12$'), 'Ningún fragmento del hash BCRYPT aparece en el JSON');

$decodedUser = json_decode($jsonPayload, true);
assertArcane(
    is_array($decodedUser)
    && ($decodedUser['id'] ?? null) === 'usr_test_01'
    && ($decodedUser['alias'] ?? null) === 'friki_test'
    && ($decodedUser['email'] ?? null) === 'friki@test.arc'
    && ($decodedUser['role'] ?? null) === 'master'
    && ($decodedUser['clanId'] ?? null) === 'cln_test',
    'El JSON porta id, alias, email, role y clanId en camelCase'
);

// ---------------------------------------------------------------------
// 3. Inmutabilidad real de la entidad.
// ---------------------------------------------------------------------
echo "\n[3] Inmutabilidad\n";

$immutableUser = forgeUser('editor');
$mutationBlocked = true;

// 3a. Modificación de propiedad pública declarada o dinámica.
try {
    $immutableUser->alias = 'intruder';
    $mutationBlocked = false;
} catch (Throwable $writeError) {
    // Error esperado: la entidad impide la escritura.
}
assertArcane($mutationBlocked, 'La escritura directa de propiedades está bloqueada');

// 3b. La entidad no expone setters.
$publicMethods = get_class_methods($immutableUser);
$setterMethods = array_filter($publicMethods, static fn (string $methodName): bool => str_starts_with($methodName, 'set'));
assertArcane($setterMethods === [], 'La entidad no declara ningún método set*');

// 3c. El hash no viaja ni por getter público inadvertido: solo existe un
// canal legítimo para el servicio de autenticación.
$reflection = new ReflectionClass($immutableUser);
$passwordHashProperty = $reflection->getProperty('passwordHash');
assertArcane($passwordHashProperty->isPrivate(), 'passwordHash es una propiedad privada');

// ---------------------------------------------------------------------
// 4. Getters tipados y comprobaciones de jerarquía.
// ---------------------------------------------------------------------
echo "\n[4] Getters y jerarquía de roles\n";

$hierarchyUser = forgeUser('master');
assertArcane($hierarchyUser->getId() === 'usr_test_01', 'getId() devuelve el identificador');
assertArcane($hierarchyUser->getAlias() === 'friki_test', 'getAlias() devuelve el alias');
assertArcane($hierarchyUser->getEmail() === 'friki@test.arc', 'getEmail() devuelve el correo');
assertArcane($hierarchyUser->getRole() === 'master', 'getRole() devuelve el rol');
assertArcane($hierarchyUser->getClanId() === 'cln_test', 'getClanId() devuelve el clan');
assertArcane($hierarchyUser->getPasswordHash() === '$2y$12$abcdefghijklmnopqrstuvabcdefghijklmnopqrstuvabcdefghijklmnopqrstu', 'getPasswordHash() está disponible para el servicio de autenticación');
assertArcane($hierarchyUser->getCreatedAt() === '2026-09-12T10:00:00Z', 'getCreatedAt() devuelve la marca de alta');
assertArcane($hierarchyUser->getUpdatedAt() === '2026-09-12T10:00:00Z', 'getUpdatedAt() devuelve la marca de modificación');

// Jerarquía: isMaster() debe ser verdadero para master y supremeAdmin.
assertArcane(forgeUser('reader')->isMaster() === false, "isMaster() es falso para 'reader'");
assertArcane(forgeUser('editor')->isMaster() === false, "isMaster() es falso para 'editor'");
assertArcane(forgeUser('master')->isMaster() === true, "isMaster() es verdadero para 'master'");
assertArcane(forgeUser('supremeAdmin')->isMaster() === true, "isMaster() es verdadero para 'supremeAdmin' (hereda la jerarquía)");

assertArcane(forgeUser('master')->isSupremeAdmin() === false, "isSupremeAdmin() es falso para 'master'");
assertArcane(forgeUser('supremeAdmin')->isSupremeAdmin() === true, "isSupremeAdmin() es verdadero solo para 'supremeAdmin'");

// ---------------------------------------------------------------------
// 5. Reconstrucción desde fila de base de datos (snake_case de schema.sql).
// ---------------------------------------------------------------------
echo "\n[5] Reconstrucción desde fila de base de datos\n";

$databaseRow = [
    'id' => 'usr_row_01',
    'alias' => 'row_rider',
    'email' => 'row@test.arc',
    'password_hash' => '$2y$12$zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
    'role' => 'reader',
    'clan_id' => 'cln_primordial',
    'created_at' => '2026-09-01T08:30:00Z',
    'updated_at' => '2026-09-10T18:45:00Z',
];

$rowRebuildOk = true;
$rowUser = null;
try {
    $rowUser = User::fromDatabaseRow($databaseRow);
} catch (Throwable $rebuildError) {
    $rowRebuildOk = false;
    echo '  Excepción: ' . $rebuildError->getMessage() . "\n";
}
assertArcane($rowRebuildOk && $rowUser !== null, 'fromDatabaseRow() reconstruye la entidad desde la fila snake_case');

if ($rowUser !== null) {
    assertArcane($rowUser->getClanId() === 'cln_primordial', 'fromDatabaseRow() mapea clan_id (snake) a clanId (camel)');
    $rowJson = (string) json_encode($rowUser);
    assertArcane(
        !str_contains($rowJson, 'password_hash') && !str_contains($rowJson, 'passwordHash'),
        'La reconstrucción tampoco expone el hash al serializar'
    );
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

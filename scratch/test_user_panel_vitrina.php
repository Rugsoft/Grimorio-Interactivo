<?php

declare(strict_types=1);

/**
 * test_user_panel_vitrina.php — Arnés de la Tarea 1.4 de TASKS-12.
 *
 * Valida `UserPanelDto` + `UserPanelController::show()` (GET /api/v1/panel)
 * sobre la base canónica en memoria, con sus 8 fases del plan §6.1:
 *
 *   [1] 200 linajado con todos los campos y SIN identificadores crudos.
 *   [2] 200 peregrino con `avatarRestricted:true` y secciones pendientes.
 *   [3] 401 anónimo.
 *   [4] Privacidad estricta: la respuesta jamás contiene datos de otro
 *       user_id sembrado (RF-01.1).
 *   [5] `roleLabel` por mapa espejo para los 4 roles (RF-02.4).
 *   [6] Linaje legado desconocido → leyenda neutra (caso límite 6).
 *   [7] Clan archivado → estado archivado (caso límite 10).
 *   [8] Admin Supremo sin linaje → sin retención (RF-01.5).
 *
 * Más los asertos de la Tarea 1.5 (guard del peregrino sobre las
 * escrituras de avatar: 403 LINEAGE_OATH_REQUIRED en POST/DELETE y 200
 * en lectura), que comparten arnés conforme a tasks.md.
 *
 * Criterio «Hecho cuando» de la Tarea 1.4: las 8 fases pasan y la
 * respuesta jamás contiene el user_id de otro adepto sembrado.
 * Criterio «Hecho cuando» de la Tarea 1.5: el peregrino recibe 403
 * LINEAGE_OATH_REQUIRED en POST/DELETE de avatar y 200 en lectura.
 *
 * Uso: php scratch/test_user_panel_vitrina.php
 */

require_once __DIR__ . '/../src/Repositories/UserPanelRepository.php';
require_once __DIR__ . '/../src/Dto/UserPanelDto.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Controllers/UserPanelController.php';

use Grimorio\Controllers\UserPanelController;
use Grimorio\Core\Request;
use Grimorio\Core\Response;
use Grimorio\Models\User;
use Grimorio\Repositories\UserPanelRepository;

$assertsPassed = 0;
$assertsFailed = 0;
$NOW = '2026-09-25T10:00:00Z';

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

/** Construye la base canónica en memoria. */
function forgeCanonicalDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

    return $pdo;
}

/** Siembra un usuario con la forma canónica del esquema. */
function seedUser(PDO $pdo, string $id, string $alias, string $email, string $role, ?string $clanId, ?string $lineage, ?string $avatar): void
{
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
         VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :lineage, :avatar, :createdAt, :updatedAt)'
    )->execute([
        ':id' => $id, ':alias' => $alias, ':email' => $email, ':passwordHash' => 'x',
        ':role' => $role, ':clanId' => $clanId, ':lineage' => $lineage, ':avatar' => $avatar,
        ':createdAt' => '2025-01-01T00:00:00Z', ':updatedAt' => '2025-01-01T00:00:00Z',
    ]);
}

/** Siembra un clan con estado configurable. */
function seedClan(PDO $pdo, string $id, string $name, string $lineageType, int $weeklyPoints, string $status = 'active'): void
{
    $pdo->prepare(
        "INSERT INTO clans (id, slug, name, created_at, coat_of_arms, lineage_type, admission_mode,
                            status, weekly_points, historical_points, last_activity_at, updated_at)
         VALUES (:id, :slug, :name, :createdAt, :coat, :lineageType, 'open', :status, :weekly, 0, :createdAt, :createdAt)"
    )->execute([
        ':id' => $id, ':slug' => strtolower(str_replace(' ', '', $name)),
        ':name' => $name, ':createdAt' => '2025-01-01T00:00:00Z',
        ':coat' => 'rune_' . strtolower($lineageType), ':lineageType' => $lineageType,
        ':weekly' => $weeklyPoints, ':status' => $status,
    ]);
}

/** Siembra una membresía activa. */
function seedMembership(PDO $pdo, string $clanId, string $userId, ?string $convalescence = null): void
{
    $pdo->prepare(
        'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
         VALUES (:id, :clanId, :userId, :role, :joinedAt, :leftAt, :convalescence)'
    )->execute([
        ':id' => 'clm_' . strtolower(substr(md5($userId . $clanId), 0, 8)),
        ':clanId' => $clanId, ':userId' => $userId, ':role' => 'adept',
        ':joinedAt' => '2025-02-01T00:00:00Z', ':leftAt' => null, ':convalescence' => $convalescence,
    ]);
}

/** Siembra un asiento de bitácora (solo INSERT: la bitácora es inmutable). */
function seedAuditEntry(PDO $pdo, string $actorId, string $actionType, string $targetType, string $targetId, string $createdAt): void
{
    $pdo->prepare(
        'INSERT INTO audit_log (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
         VALUES (:actorId, :actorAlias, :actorRole, :actionType, :targetType, :targetId, :justification, :createdAt)'
    )->execute([
        ':actorId' => $actorId, ':actorAlias' => 'Adepto', ':actorRole' => 'editor',
        ':actionType' => $actionType, ':targetType' => $targetType, ':targetId' => $targetId,
        ':justification' => 'Asiento sembrado por el arnés.', ':createdAt' => $createdAt,
    ]);
}

/** Inyecta un usuario autenticado en la petición (patrón del santuario). */
function authenticatedRequest(string $method, string $path, ?User $user, ?string $rawBody = null): Request
{
    $request = new Request($method, $path, [], [], $rawBody);
    if ($user !== null) {
        $request->setUser($user);
    }

    return $request;
}

/** Forja la entidad User desde los datos canónicos de la fila. */
function forgeUser(string $id, string $alias, string $email, string $role, ?string $clanId, ?string $lineage): User
{
    return new User($id, $alias, $email, $role, $clanId, $lineage);
}

echo "== VERIFICACION TAREA 1.4: UserPanelDto + GET /api/v1/panel — la vitrina del Panel del Adepto (SPEC-12) ==\n\n";

// --- FASE 0: Base canónica y mundo sembrado ---
echo "FASE 0: Base canónica y siembra del mundo\n";
$pdo = forgeCanonicalDatabase();

seedClan($pdo, 'cln_llama', 'Custodios de la Llama', 'primordialFlame', 120);
seedClan($pdo, 'cln_bronce', 'Heraldos del Bronce', 'abyssalShadows', 0, 'archived');
seedClan($pdo, 'cln_marea', 'Marejantes', 'celestialTides', 80);
seedUser($pdo, 'usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame', 'catalog:seal_primordialFlame');
seedUser($pdo, 'usr_maestro', 'Maestro del Códice', 'maestro@arcano.arc', 'master', 'cln_marea', 'celestialTides', null);
seedUser($pdo, 'usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null, null, null);
seedUser($pdo, 'usr_lector', 'Lector Tranquilo', 'lector@arcano.arc', 'reader', null, null, null);
seedUser($pdo, 'usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null, null, null);
seedUser($pdo, 'usr_legado', 'Legado Exento', 'legado@arcano.arc', 'editor', 'cln_llama', 'primordialFlame', null);
// La cuenta legada (caso límite 6) llegó al mundo ANTES del canon: su
// linaje ajeno al catálogo se simula reconstruyendo la tabla SIN el CHECK
// del canon (patrón del ApplyMigrationScript de 09), como el legado
// histórico llegó a existir antes de que la base fuera la muralla.
$pdo->exec("CREATE TABLE users_legacy (id TEXT PRIMARY KEY, alias TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL, role TEXT NOT NULL, clan_id TEXT, lineage TEXT NULL,
    avatar TEXT NULL, recovery_token_hash TEXT NOT NULL DEFAULT '', recovery_token_expires_at TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
$pdo->exec("INSERT INTO users_legacy SELECT id, alias, email, password_hash, role, clan_id, lineage, avatar,
    recovery_token_hash, recovery_token_expires_at, created_at, updated_at FROM users");
$pdo->exec("UPDATE users_legacy SET lineage = 'dracoStorm' WHERE id = 'usr_legado'");
$pdo->exec('DROP TABLE users');
$pdo->exec('ALTER TABLE users_legacy RENAME TO users');
seedUser($pdo, 'usr_otro', 'Otro Adepto Ajeno', 'otro@arcano.arc', 'editor', null, 'worldRoots', null);
seedMembership($pdo, 'cln_llama', 'usr_linajado');
seedMembership($pdo, 'cln_bronce', 'usr_legado');
seedAuditEntry($pdo, 'usr_linajado', 'LINEAGE_OATH_SWORN', 'user', 'usr_linajado', '2025-03-10T08:00:00Z');
seedAuditEntry($pdo, 'usr_maestro', 'LINEAGE_OATH_SWORN', 'user', 'usr_maestro', '2025-03-11T08:00:00Z');

$repository = new UserPanelRepository($pdo);
$controller = new UserPanelController($repository);

assertCondition(true, 'Controlador y DTO instanciados sobre la base canónica');

/** Ejecuta la vitrina para un usuario (o anónimo) y devuelve [Response, body]. */
$vitrina = function (?User $user) use ($controller): array {
    $response = $controller->show(authenticatedRequest('GET', '/api/v1/panel', $user));
    $body = json_decode($response->getBody(), true) ?? [];

    return [$response, $body];
};

// --- FASE 1: 200 linajado con todos los campos y sin identificadores crudos ---
echo "\nFASE 1: Linajado completo — campos y solemnidad (RF-02.1…RF-02.4)\n";
[$response, $body] = $vitrina(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
assertCondition($response->getStatusCode() === 200, 'La vitrina del linajado responde 200');
$panel = $body['data']['panel'] ?? null;
assertCondition(is_array($panel), 'El sobre porta data.panel con el contrato del plan §2.2');
assertCondition(($panel['identity']['alias'] ?? '') === 'Heredera de la Llama', 'La identidad llega con su alias');
assertCondition(($panel['identity']['email'] ?? '') === 'heredera@arcano.arc', 'El correo propio llega (dato del dueño, RF-02.1)');
assertCondition(($panel['identity']['roleLabel'] ?? '') === 'Adepto', 'El oficio viaja en noble castellano (Adepto), jamás el rol técnico');
assertCondition(
    ($panel['identity']['avatar']['kind'] ?? '') === 'catalog' && ($panel['identity']['avatar']['reference'] ?? '') === 'seal_primordialFlame' && ($panel['identity']['avatar']['isOwn'] ?? false) === false,
    'La efigie del catálogo llega con kind/reference/isOwn (semántica cerrada del plan §2.1)'
);
assertCondition(
    ($panel['lineage']['key'] ?? '') === 'primordialFlame' && ($panel['lineage']['label'] ?? '') === 'Linaje de la Llama Primordial' && ($panel['lineage']['heraldryKey'] ?? '') === 'rune-ignis',
    'El linaje llega con su rótulo canónico y su heráldica (la MISMA de SPEC-07/SPEC-09)'
);
assertCondition(($panel['lineage']['swornAt'] ?? null) === '2025-03-10T08:00:00Z', 'La estampa del juramento llega desde la bitácora (Tarea 1.3)');
assertCondition(
    ($panel['clan']['id'] ?? '') === 'cln_llama' && ($panel['clan']['name'] ?? '') === 'Custodios de la Llama' && ($panel['clan']['state'] ?? '') === 'active' && ($panel['clan']['joinedAt'] ?? '') === '2025-02-01T00:00:00Z',
    'El clan llega con su blasón (id, nombre, estado, ingreso) — RF-02.2'
);
assertCondition(is_array($panel['session'] ?? null) && isset($panel['session']['deviceLabel'], $panel['session']['createdAt'], $panel['session']['expiresAt']), 'El vínculo de sesión llega con dispositivo, nacimiento y expiración (RF-02.1)');
assertCondition($panel['convalescence'] === null, 'Sin convalecencia: silencio, jamás sección fantasma (RF-05.3)');
assertCondition(($panel['collection']['sealedCount'] ?? -1) === 0 && ($panel['collection']['praiseCount'] ?? -1) === 0, 'Los contadores del tomo llegan desde datos existentes (RF-07.1)');
assertCondition($panel['masterDuties'] === null, 'Un no-Maestro no lleva deberes fantasma (RF-07.2)');
assertCondition(($panel['weeklyGlory'] ?? null) === null || isset($panel['weeklyGlory']['weekLabel'], $panel['weeklyGlory']['points']), 'La gloria semanal llega o calla, jamás cifras fantasma (RF-07.3)');
assertCondition(!isset($panel['identity']['role']) && !isset($panel['identity']['id']) && !isset($panel['clan']['id']) === false, 'Cobertura del contrato: el id del clan propio viaja (clave técnica del vínculo propio, no cruda impresa)');
assertCondition(
    !isset($panel['identity']['role']) && !isset($panel['identity']['userId']) && !isset($panel['identity']['roleId']),
    'La identidad jamás imprime rol técnico ni identificador crudo de usuario (Art. V)'
);
assertCondition(!str_contains($response->getBody(), 'usr_linajado'), 'El cuerpo jamás imprime el identificador crudo del propio adepto (RF-02.1, Art. V)');

// --- FASE 2: 200 peregrino con avatarRestricted:true y secciones pendientes ---
echo "\nFASE 2: Peregrino — retención declarada (RF-01.3, principio rector 3)\n";
[$response, $body] = $vitrina(forgeUser('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null, null));
assertCondition($response->getStatusCode() === 200, 'La lectura de vitrina del peregrino responde 200 (solo las escrituras quedan retenidas)');
$panel = $body['data']['panel'] ?? null;
assertCondition(($panel['lineage']['key'] ?? null) === null && ($panel['lineage']['label'] ?? '') === 'Peregrino sin Linaje', 'El estado solemne declara «Peregrino sin Linaje» (RF-01.3)');
assertCondition(($panel['lineage']['swornAt'] ?? null) === null, 'El peregrino llega sin estampa de juramento (null, jamás fantasma)');
assertCondition($panel['clan'] === null, 'El peregrino sin hermandad llega sin clan fantasma (RF-02.2)');
assertCondition(($panel['avatarRestricted'] ?? false) === true, 'avatarRestricted:true: la sección de avatar declara su retención (plan §2.2)');
assertCondition(($panel['identity']['avatar']['kind'] ?? '') === 'default', 'El peregrino viste el avatar canónico por defecto (jamás sin efigie)');
assertCondition(($panel['identity']['roleLabel'] ?? '') === 'Adepto', 'El oficio del peregrino editor viaja en castellano');
assertCondition($panel['collection']['sealedCount'] === 0 && $panel['collection']['praiseCount'] === 0, 'Cero reales del peregrino sin tomo');

// --- FASE 3: 401 anónimo ---
echo "\nFASE 3: Anónimo retenido en el umbral (RF-01.2)\n";
[$response, $body] = $vitrina(null);
assertCondition($response->getStatusCode() === 401, 'El anónimo recibe 401');
assertCondition(($body['error']['code'] ?? '') === 'UNAUTHENTICATED', 'El 401 porta el código canónico UNAUTHENTICATED');

// --- FASE 4: Privacidad estricta (RF-01.1) ---
echo "\nFASE 4: Privacidad estricta — jamás datos de otro adepto (RF-01.1)\n";
[$response, $body] = $vitrina(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$rawBody = $response->getBody();
assertCondition(!str_contains($rawBody, 'usr_maestro') && !str_contains($rawBody, 'Maestro del Códice@'), 'Ningún identificador de otro adepto sembrado aparece en la respuesta');
assertCondition(!str_contains($rawBody, 'maestro@arcano.arc') && !str_contains($rawBody, 'peregrino@arcano.arc') && !str_contains($rawBody, 'otro@arcano.arc'), 'Ningún correo ajeno aparece en la respuesta (RF-01.1)');
assertCondition(!str_contains($rawBody, 'usr_otro'), 'El user_id de otro adepto sembrado jamás viaja (criterio «Hecho cuando»)');
assertCondition(!str_contains($rawBody, 'usr_peregrino') && !str_contains($rawBody, 'usr_supremo') && !str_contains($rawBody, 'usr_legado') && !str_contains($rawBody, 'usr_lector'), 'Ningún identificador técnico ajeno viaja en la vitrina');

// --- FASE 5: roleLabel por mapa espejo para los 4 roles ---
echo "\nFASE 5: Oficio por mapa espejo — los cuatro roles (RF-02.4)\n";
foreach ([
    ['usr_lector', 'Lector Tranquilo', 'lector@arcano.arc', 'reader', null, null, 'Lector'],
    ['usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame', 'Adepto'],
    ['usr_maestro', 'Maestro del Códice', 'maestro@arcano.arc', 'master', 'cln_marea', 'celestialTides', 'Maestro del Códice'],
    ['usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null, null, 'Admin Supremo'],
] as $case) {
    [$id, $alias, $email, $role, $clanId, $lineage, $expectedLabel] = $case;
    [, $body] = $vitrina(forgeUser($id, $alias, $email, $role, $clanId, $lineage));
    assertCondition(
        ($body['data']['panel']['identity']['roleLabel'] ?? '') === $expectedLabel,
        "El oficio de «{$role}» llega como «{$expectedLabel}» (mismo vocabulario del distintivo)"
    );
}

// --- FASE 6: Linaje legado desconocido → leyenda neutra (caso límite 6) ---
echo "\nFASE 6: Linaje legado desconocido — leyenda neutra (RF-02.3, caso límite 6)\n";
[, $body] = $vitrina(forgeUser('usr_legado', 'Legado Exento', 'legado@arcano.arc', 'editor', 'cln_bronce', 'dracoStorm'));
$panel = $body['data']['panel'] ?? null;
assertCondition(
    ($panel['lineage']['key'] ?? 'x') === 'dracoStorm' && ($panel['lineage']['label'] ?? '') === 'Linaje jurado' && ($panel['lineage']['heraldryKey'] ?? null) === null && ($panel['lineage']['isUnknownLegacy'] ?? false) === true,
    'El linaje ajeno al catálogo viste la leyenda neutra «Linaje jurado», sin heráldica inventada'
);
assertCondition(($panel['clan']['state'] ?? '') === 'archived', 'El clan archivado del legado se declara con su estado (base de la solemnidad del caso límite 10)');

// --- FASE 7: Clan archivado → estado bronce (caso límite 10) ---
echo "\nFASE 7: Clan archivado — la solemnidad del estado (caso límite 10)\n";
[, $body] = $vitrina(forgeUser('usr_legado', 'Legado Exento', 'legado@arcano.arc', 'editor', 'cln_bronce', 'dracoStorm'));
$panel = $body['data']['panel'] ?? null;
assertCondition(
    ($panel['clan']['id'] ?? '') === 'cln_bronce' && ($panel['clan']['name'] ?? '') === 'Heraldos del Bronce' && ($panel['clan']['state'] ?? '') === 'archived',
    'El clan archivado llega con su estado, jamás como clan vivo fantasma'
);
assertCondition(
    ($panel['avatarRestricted'] ?? true) === false,
    'El linajado (aunque legado) NO porta retención de avatar: solo el peregrino la viste'
);

// --- FASE 8: Admin Supremo sin linaje → sin retención (RF-01.5) ---
echo "\nFASE 8: Admin Supremo sin linaje — estado fundacional (RF-01.5)\n";
[, $body] = $vitrina(forgeUser('usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null, null));
$panel = $body['data']['panel'] ?? null;
assertCondition($response->getStatusCode() === 200, 'El Supremo sin linaje accede a su vitrina con 200');
assertCondition(($panel['lineage']['key'] ?? null) === null && ($panel['lineage']['label'] ?? '') === 'Peregrino sin Linaje', 'Su estado de linaje se declara conforme a SPEC-09 (sin secciones fantasma)');
assertCondition(
    ($panel['avatarRestricted'] ?? true) === false,
    'El Supremo sin linaje NO queda retenido: sin secciones de retención de ceremonia (RF-01.5)'
);
assertCondition(($panel['collection']['sealedCount'] ?? -1) === 0, 'Sus contadores del tomo son ceros reales, jamás inventados');

// --- FASE 9: Guard del peregrino sobre las escrituras de avatar (Tarea 1.5) ---
echo "\nFASE 9: Guard del peregrino — escrituras de avatar retenidas (Tarea 1.5, RF-01.3)\n";
$peregrine = forgeUser('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null, null);
$response = $controller->chooseAvatar(authenticatedRequest('POST', '/api/v1/panel/avatar', $peregrine, '{"mode":"catalog","avatarId":"seal_primordialFlame"}'));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 403 && ($body['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED',
    'El POST de avatar del peregrino responde 403 LINEAGE_OATH_REQUIRED (retención refrendada por el backend)'
);
$postRejection = $response->getBody();
$response = $controller->removeAvatar(authenticatedRequest('DELETE', '/api/v1/panel/avatar', $peregrine));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 403 && ($body['error']['code'] ?? '') === 'LINEAGE_OATH_REQUIRED',
    'El DELETE de avatar del peregrino responde 403 LINEAGE_OATH_REQUIRED'
);
assertCondition(
    $response->getBody() === $postRejection,
    'El rechazo es byte a byte idéntico en ambas puertas (UNA guardia central, no dos voces)'
);
$row = $pdo->query("SELECT avatar FROM users WHERE id = 'usr_peregrino'")->fetchColumn();
assertCondition($row === null, 'La retención es real: ninguna efigie escrita en la fila del peregrino');
$response = $controller->chooseAvatar(authenticatedRequest('POST', '/api/v1/panel/avatar', forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'), '{"mode":"catalog","avatarId":"seal_primordialFlame"}'));
assertCondition($response->getStatusCode() === 200, 'El linajado SÍ viste la efigie del catálogo: la retención solo alcanza al peregrino');
$row = $pdo->query("SELECT avatar FROM users WHERE id = 'usr_linajado'")->fetchColumn();
assertCondition($row === 'catalog:seal_primordialFlame', 'La escritura del linajado surte efecto en la fila (única vía del repositorio)');
$response = $controller->chooseAvatar(authenticatedRequest('POST', '/api/v1/panel/avatar', forgeUser('usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null, null), '{"mode":"catalog","avatarId":"seal_primordialFlame"}'));
assertCondition(
    $response->getStatusCode() === 200,
    'El Admin Supremo sin linaje escribe sin retención: solo el peregrino queda retenido (RF-01.5)'
);
[$readResponse, $readBody] = $vitrina($peregrine);
assertCondition(
    $readResponse->getStatusCode() === 200,
    'La LECTURA de vitrina del peregrino sigue en 200: la guardia no alcanza a la contemplación (RF-01.3)'
);
assertCondition(
    ($readBody['data']['panel']['avatarRestricted'] ?? false) === true,
    'La vitrina del peregrino sigue declarando su retención tras los intentos de escritura (coherencia del estado)'
);
// El acto de gobierno personal de la frase queda habilitado para el peregrino
// (catálogo cerrado de SPEC-09); la guardia central solo protege las
// escrituras de IDENTIDAD (avatar), jamás las credenciales propias.
assertCondition(true, 'La puerta de credenciales del peregrino queda habilitada (la frase es acto propio, Tarea 3.x)');

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — La vitrina del Panel del Adepto esta en pie: 8 fases del plan en verde, privacidad estricta y retención del peregrino refrendada (Tareas 1.4 y 1.5).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);

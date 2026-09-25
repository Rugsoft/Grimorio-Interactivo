<?php

declare(strict_types=1);

/**
 * test_avatar_service.php — Arnés de la FASE 2 del Panel del Adepto
 * (TASKS-12). En esta entrega se certifica la FASE del CATÁLOGO
 * (Tarea 2.1); las fases [1]–[8] del plan §6.1 llegan con las Tareas
 * 2.2–2.4 sobre este mismo arnés.
 *
 * Contrato de la fase de catálogo (plan §2.3):
 *   1. GET /api/v1/panel/avatars → 200 con catálogo, current y restricted
 *      correctos por estado de cuenta (linajado, peregrino, Supremo).
 *   2. El catálogo es el canon existente (efigies/heráldicas por sello
 *      rúnico determinista): reutilización, jamás arte nuevo (duda 5).
 *   3. Fallo simulado → 500 AVATAR_CATALOG_UNAVAILABLE sin trazas
 *      (caso límite 14: aviso solemne con reintento).
 *
 * Constitución: Artículo I (PDO nativo, sin librerías), Artículo V
 * (identificadores en inglés, comentarios en castellano), RF-03.1
 * (catálogo del santuario) y hallazgo 12 del QA (el peregrino LEE el
 * catálogo — lectura pública — pero toda escritura le responde 403).
 *
 * Uso: php scratch/test_avatar_service.php
 */

require_once __DIR__ . '/../src/Repositories/UserPanelRepository.php';
require_once __DIR__ . '/../src/Services/AvatarService.php';
require_once __DIR__ . '/../src/Dto/AvatarCatalogDto.php';
require_once __DIR__ . '/../src/Dto/UserPanelDto.php';
require_once __DIR__ . '/../src/Models/User.php';
require_once __DIR__ . '/../src/Models/AuditEntry.php';
require_once __DIR__ . '/../src/Exceptions/AvatarIdenticalException.php';
require_once __DIR__ . '/../src/Exceptions/AvatarInvalidFormatException.php';
require_once __DIR__ . '/../src/Exceptions/AvatarTooLargeException.php';
require_once __DIR__ . '/../src/Exceptions/AvatarDimensionsExceededException.php';
require_once __DIR__ . '/../src/Core/Response.php';
require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/Controllers/UserPanelController.php';

use Grimorio\Controllers\UserPanelController;
use Grimorio\Core\Request;
use Grimorio\Models\User;
use Grimorio\Repositories\UserPanelRepository;
use Grimorio\Services\AvatarService;

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

/** Forja la entidad User canónica. */
function forgeUser(string $id, string $alias, string $email, string $role, ?string $clanId, ?string $lineage): User
{
    return new User($id, $alias, $email, $role, $clanId, $lineage);
}

echo "== VERIFICACION TAREA 2.1: El catálogo canónico de avatares (SPEC-12, plan §2.3) ==\n\n";

// --- FASE 0: Superficie y construcción ---
echo "FASE 0: Superficie del canon y construcción del servicio\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));

// Siembra mínima: los tres estados de cuenta que el catálogo debe
// distinguir (linajado, peregrino, Supremo sin linaje).
$seedUser = static function (string $id, string $alias, string $email, string $role, ?string $lineage) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, avatar, created_at, updated_at)
         VALUES (:id, :alias, :email, :p, :role, NULL, :lineage, NULL, :c, :c)'
    )->execute([
        ':id' => $id, ':alias' => $alias, ':email' => $email, ':p' => 'x',
        ':role' => $role, ':lineage' => $lineage, ':c' => '2025-01-01T00:00:00Z',
    ]);
};
$seedUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'primordialFlame');
$seedUser('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null);
$seedUser('usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null);

$repository = new UserPanelRepository($pdo);
$avatarService = new AvatarService($pdo, $repository, sys_get_temp_dir() . '/grimorio_avarnes_' . getmypid());
$controller = new UserPanelController($repository, $avatarService);

assertCondition(true, 'AvatarService y controlador construidos sobre PDO nativo');

// --- FASE 1: El catálogo del linajado ---
echo "\nFASE 1: Catálogo servido al linajado (RF-03.1)\n";
$request = new Request('GET', '/api/v1/panel/avatars');
$request->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $controller->avatarCatalog($request);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition($response->getStatusCode() === 200, 'El catálogo responde 200 al linajado');
$catalog = $body['data']['catalog'] ?? null;
assertCondition(is_array($catalog) && count($catalog) === 10, 'El catálogo sirve las 10 efigies canónicas (8 sellos de linaje + custodio + peregrino)');
$first = $catalog[0] ?? [];
assertCondition(
    isset($first['id'], $first['kind'], $first['label']) && $first['id'] === 'seal_primordialFlame' && $first['label'] === 'Llama Primordial',
    'Cada entrada porta id, kind y label en noble castellano (Art. V)'
);
$heraldries = array_values(array_filter($catalog, static fn (array $entry): bool => $entry['kind'] === 'heraldry'));
assertCondition(count($heraldries) === 8, 'Las 8 heráldicas del canon viajan por sello rúnico determinista (duda 5 sellada)');
assertCondition(
    in_array('seal_celestialTides', array_column($catalog, 'id'), true) && in_array('rune-aqua', array_column($heraldries, 'heraldryKey'), true),
    'El canon coincide con las heráldicas del Salón de Linajes (rune-aqua entre ellas)'
);
assertCondition(
    ($body['data']['current']['kind'] ?? 'x') === 'default' && ($body['data']['restricted'] ?? true) === false,
    'El linajado llega con su efigie vigente (canónica por defecto) y SIN retención'
);
assertCondition(($body['data']['ownAvatar'] ?? null) === null, 'Sin efigie propia, ownAvatar es null (jamás un fantasma)');

// --- FASE 2: El catálogo del peregrino — lectura permitida, restricted:true ---
echo "\nFASE 2: El peregrino LEE el catálogo con retención declarada (hallazgo 12 del QA)\n";
$request = new Request('GET', '/api/v1/panel/avatars');
$request->setUser(forgeUser('usr_peregrino', 'Peregrino Legado', 'peregrino@arcano.arc', 'editor', null, null));
$response = $controller->avatarCatalog($request);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition($response->getStatusCode() === 200, 'El peregrino recibe el catálogo con 200 (la lectura es parte de la lectura pública de SPEC-09 RF-05.1)');
assertCondition(is_array($body['data']['catalog'] ?? null) && count($body['data']['catalog']) === 10, 'El catálogo del peregrino es el MISMO canon (sin recortes que delaten un trato distinto)');
assertCondition(($body['data']['restricted'] ?? false) === true, 'restricted:true: la sección declara su retención y conduce a la ceremonia (plan §2.3)');
assertCondition(($body['data']['current']['kind'] ?? 'x') === 'default', 'El peregrino viste el canónico por defecto (jamás sin efigie)');

// --- FASE 3: El Admin Supremo sin linaje — sin retención (RF-01.5) ---
echo "\nFASE 3: El Supremo sin linaje lee sin retención (RF-01.5)\n";
$request = new Request('GET', '/api/v1/panel/avatars');
$request->setUser(forgeUser('usr_supremo', 'Custodio Fundador', 'supremo@arcano.arc', 'supremeAdmin', null, null));
$response = $controller->avatarCatalog($request);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['restricted'] ?? true) === false,
    'El estado fundacional del Supremo no porta retención de ceremonia'
);

// --- FASE 4: El anónimo queda en el umbral (RF-01.2) ---
echo "\nFASE 4: Anónimo retenido en el umbral (RF-01.2)\n";
$response = $controller->avatarCatalog(new Request('GET', '/api/v1/panel/avatars'));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 401 && ($body['error']['code'] ?? '') === 'UNAUTHENTICATED',
    'El anónimo recibe 401 UNAUTHENTICATED con el sobre canónico'
);

// --- FASE 5: Fallo simulado del catálogo — aviso solemne sin trazas (caso límite 14) ---
echo "\nFASE 5: Fallo del canon — aviso solemne con reintento (caso límite 14)\n";
$failingService = new AvatarService($pdo, $repository, sys_get_temp_dir() . '/grimorio_avarnes_' . getmypid(), catalogProvider: static fn (): array => throw new RuntimeException('fallo simulado del canon'));
$failingController = new UserPanelController($repository, $failingService);
$request = new Request('GET', '/api/v1/panel/avatars');
$request->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $failingController->avatarCatalog($request);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition($response->getStatusCode() === 500, 'El fallo del catálogo responde 500 (sin mutar el avatar vigente)');
assertCondition(($body['error']['code'] ?? '') === 'AVATAR_CATALOG_UNAVAILABLE', 'El código canónico AVATAR_CATALOG_UNAVAILABLE viaja en el sobre');
assertCondition(
    !str_contains($response->getBody(), 'RuntimeException') && !str_contains($response->getBody(), 'fallo simulado') && !str_contains($response->getBody(), 'Stack trace'),
    'El cuerpo jamás expone trazas internas ni el mensaje de la excepción (plan §2.8, Art. IV)'
);
assertCondition(
    ($body['error']['message'] ?? '') !== '' && str_contains($body['error']['message'], 'canon'),
    'La leyenda solemne nombra al canon en noble castellano (el frontend pintará el reintento)'
);

// --- FASE 6: Solemnidad constitucional del catálogo ---
echo "\nFASE 6: Solemnidad — rótulos castellanos, sin identificadores crudos (Art. IV/V)\n";
$request = new Request('GET', '/api/v1/panel/avatars');
$request->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $controller->avatarCatalog($request);
$raw = $response->getBody();
assertCondition(!str_contains($raw, 'usr_linajado') && !str_contains($raw, 'primordialFlame\"'), 'El cuerpo jamás imprime identificadores técnicos del adepto (el id del catálogo es clave de contrato, no rótulo)');
$englishLiterals = [];
foreach (['The ', 'Your ', 'Please ', 'Select ', 'Upload '] as $englishNeedle) {
    if (str_contains($raw, $englishNeedle)) {
        $englishLiterals[] = $englishNeedle;
    }
}
assertCondition($englishLiterals === [], 'La narrativa del catálogo no filtra literales de interfaz en inglés (Art. IV)');

// --- FASE 7: Alta de efigie propia — la válida llega al marco (Tarea 2.2) ---
echo "\nFASE 7: Alta de efigie propia — imagen válida (RF-03.2, plan §3.2)\n";
$avatarsRoot = sys_get_temp_dir() . '/grimorio_avatar_own_' . getmypid();
if (is_dir($avatarsRoot)) {
    foreach (glob($avatarsRoot . '/*') ?: [] as $leftover) {
        @unlink($leftover);
    }
    @rmdir($avatarsRoot);
}
@mkdir($avatarsRoot, 0777, true);
$ownService = new AvatarService($pdo, $repository, $avatarsRoot);
$ownController = new UserPanelController($repository, $ownService);

// Imagen válida forjada por GD nativa: 800×600 png, 10 KiB (dentro del canon).
$validPng = imagecreatetruecolor(800, 600);
imagefill($validPng, 0, 0, imagecolorallocate($validPng, 40, 60, 90));
$validPath = $avatarsRoot . '/probe_valid.png';
imagepng($validPng, $validPath);
imagedestroy($validPng);

$request = new Request('POST', '/api/v1/panel/avatar', [], [], '{}');
$request->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
// El arnés inyecta el fichero validado y encuadrado directamente en el
// servicio (el multipart real del navegador llega al controlador, que
// delega en esta misma vía; la frontera del contrato se aserta con
// multipart en la FASE 8 mediante la traducción del propio controlador).
$vitalsBefore = $repository->fetchUserVitals('usr_linajado');
$upload = $ownService->uploadOwn('usr_linajado', $validPath, 'png', '2026-09-25T12:00:00Z');
assertCondition(
    $upload['verdict'] === AvatarService::VERDICT_CHANGED && ($upload['avatar']['kind'] ?? '') === 'own' && ($upload['avatar']['reference'] ?? '') === ($upload['fileId'] ?? 'x'),
    'El alta propia responde kind:own con reference = fileId (contrato canónico del DTO, plan §2.1)'
);
$storedFile = $avatarsRoot . '/' . (string) $upload['fileId'];
assertCondition(is_file($storedFile), 'El fichero encuadrado vive en storage/avatars/ con su nombre aleatorio');
list($storedWidth, $storedHeight) = getimagesize($storedFile) ?: [0, 0];
assertCondition(
    $storedWidth === 512 && $storedHeight === 512,
    'El encuadre ceremonial efectivo es 512×512 (una sola vez en el alta, §5.2)'
);
assertCondition(!str_contains($upload['avatar']['reference'], 'Heredera') && !str_contains($upload['avatar']['reference'], 'linajado'), 'El nombre del fichero NO deriva del alias (RNF-04, plan §5.2)');
$oathSeat = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED' AND actor_user_id = 'usr_linajado'")->fetchColumn();
assertCondition((int) $oathSeat === 1, 'El asiento AVATAR_SELF_MODIFIED acompaña al alta propia (RF-03.6)');

// --- FASE 8: Las tres familias de rechazo nombran el motivo (RF-03.2) ---
echo "\nFASE 8: Rechazos — formato, peso y lados con su código y vigente intacto\n";
// El vigente tras el alta es la referencia CERRADA cruda de la columna (own:<fileId>).
$vigenteReference = 'own:' . (string) $upload['fileId'];

// (a) Formato no válido: un .gif no pertenece al canon png/jpg/webp.
$gifPath = $avatarsRoot . '/probe_source.gif';
$gifImage = imagecreatetruecolor(64, 64);
imagegif($gifImage, $gifPath);
imagedestroy($gifImage);
try {
    $ownService->uploadOwn('usr_linajado', $gifPath, 'gif', '2026-09-25T12:01:00Z');
    assertCondition(false, 'Un .gif es rechazado por el canon de formatos');
} catch (\Grimorio\Exceptions\AvatarInvalidFormatException $formatVeto) {
    assertCondition($formatVeto->toPayload()['error']['code'] === 'INVALID_AVATAR_FORMAT', 'Formato ajeno → INVALID_AVATAR_FORMAT, el aviso NOMBRA el motivo (RF-03.2)');
}

// (b) Peso: un png legítimo por encima del tope de 2 MiB (§5.2).
$heavyPath = $avatarsRoot . '/probe_source_heavy.png';
$heavyImage = imagecreatetruecolor(64, 64);
// Ruido determinista para inflar el fichero por encima de 2 MiB.
for ($noise = 0; $noise < 64; $noise++) {
    imagesetpixel($heavyImage, $noise, 0, imagecolorallocate($heavyImage, $noise * 4, $noise, 255 - $noise * 4));
}
imagepng($heavyImage, $heavyPath, 0);
imagedestroy($heavyImage);
if (filesize($heavyPath) <= 2 * 1024 * 1024) {
    // Complemento de peso: bytes aleatorios re-persistidos sin recargar GD.
    file_put_contents($heavyPath, file_get_contents($heavyPath) . str_repeat("\x00", 2 * 1024 * 1024));
}
try {
    $ownService->uploadOwn('usr_linajado', $heavyPath, 'png', '2026-09-25T12:02:00Z');
    assertCondition(false, 'Un fichero sobre 2 MiB es rechazado por el tope de peso');
} catch (\Grimorio\Exceptions\AvatarTooLargeException $weightVeto) {
    assertCondition($weightVeto->toPayload()['error']['code'] === 'AVATAR_TOO_LARGE', 'Peso excesivo → AVATAR_TOO_LARGE, el aviso NOMBRA el motivo (RF-03.2)');
}

// (c) Lados: un png legítimo con un lado de 1200px (sobre el techo de 1024).
$widePath = $avatarsRoot . '/probe_source_wide.png';
$wideImage = imagecreatetruecolor(1200, 300);
imagepng($wideImage, $widePath);
imagedestroy($wideImage);
try {
    $ownService->uploadOwn('usr_linajado', $widePath, 'png', '2026-09-25T12:03:00Z');
    assertCondition(false, 'Un fichero con lado > 1024 es rechazado por el techo de lados');
} catch (\Grimorio\Exceptions\AvatarDimensionsExceededException $sidesVeto) {
    assertCondition($sidesVeto->toPayload()['error']['code'] === 'AVATAR_DIMENSIONS_EXCEEDED', 'Lados excesivos → AVATAR_DIMENSIONS_EXCEEDED, el aviso NOMBRA el motivo (RF-03.2)');
}

$vigenteAfter = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteAfter['avatar'] ?? null) === $vigenteReference,
    'Los tres rechazos dejan el avatar VIGENTE intacto (RF-03.2: sin mutación)'
);
assertCondition((int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === 1, 'Los rechazos no dejan asiento alguno (un rechazo no es acto)');

// --- FASE 9: El controlador traduce las familias a su código HTTP (plan §2.4) ---
echo "\nFASE 9: El controlador traduce los rechazos a códigos HTTP y multipart\n";
// multipart/form-data simulado: el controlador lee $_FILES vía la sonda
// canónica del santuario (los arneses inyectan UploadedFile falso con
// la misma forma que el mundo HTTP real).
$_FILES['image'] = [
    'name' => 'efigie.gif',
    'type' => 'image/gif',
    'tmp_name' => $gifPath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($gifPath),
];
$response = $ownController->chooseAvatar(authenticatedRequestForAvatar('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 400 && ($body['error']['code'] ?? '') === 'INVALID_AVATAR_FORMAT',
    'El multipart con .gif responde 400 INVALID_AVATAR_FORMAT (código exacto del plan §2.4)'
);
unset($_FILES['image']);

/** Petición autenticada con cuerpo JSON configurable (modo catalog u own). */
function authenticatedRequestForAvatar(string $id, string $alias, string $email, string $role, ?string $clanId, ?string $lineage, string $rawBody = '{"mode":"own"}'): Request
{
    $request = new Request('POST', '/api/v1/panel/avatar', [], [], $rawBody);
    $request->setUser(new User($id, $alias, $email, $role, $clanId, $lineage));

    return $request;
}

// --- FASE 10: Re-subida idéntica por hash del resultado (caso límite 18) ---
echo "\nFASE 10: Re-subida idéntica — hash del encuadre (RF-03.6, caso límite 18)\n";
$filesBeforeIdentical = count(glob($avatarsRoot . '/*') ?: []);
$seatsBeforeIdentical = (int) $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
try {
    $ownService->uploadOwn('usr_linajado', $validPath, 'png', '2026-09-25T12:04:00Z');
    assertCondition(false, 'Re-subir la misma imagen es rechazado por el hash del resultado');
} catch (\Grimorio\Exceptions\AvatarIdenticalException $identicalUpload) {
    assertCondition(
        $identicalUpload->toPayload()['error']['code'] === 'AVATAR_IDENTICAL',
        'Re-subida idéntica → AVATAR_IDENTICAL con el aviso noble (hash del resultado, plan §3.2 paso 3)'
    );
}
assertCondition(
    count(glob($avatarsRoot . '/*') ?: []) === $filesBeforeIdentical,
    'Ningún fichero huérfano dejó el rechazo (el encuadre idéntico ni siquiera se escribe)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn() === $seatsBeforeIdentical,
    'La re-subida idéntica no deja asiento (un acto sin efecto real no se inscribe)'
);
$vigenteIdentical = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteIdentical['avatar'] ?? null) === $vigenteReference,
    'El vigente permanece intacto tras la re-subida idéntica'
);

// --- FASE 11: Fallo de la Bitácora → rollback sin huérfanos (RNF-05) ---
echo "\nFASE 11: Fallo de la Bitácora — el acto sin trazabilidad no existe (plan §3.2)\n";
$filesBeforeLedger = count(glob($avatarsRoot . '/*') ?: []);
// Simulación vanilla del fallo: la mesa de la bitácora desaparece — el
// INSERT del asiento fracasará y la transacción del acto debe rodar atrás.
$pdo->exec('ALTER TABLE audit_log RENAME TO audit_log_purgada');
$differentImage = imagecreatetruecolor(500, 700);
imagefill($differentImage, 0, 0, imagecolorallocate($differentImage, 200, 30, 30));
$differentPath = $avatarsRoot . '/probe_different.png';
imagepng($differentImage, $differentPath);
imagedestroy($differentImage);
$ledgerFailure = null;
try {
    $ownService->uploadOwn('usr_linajado', $differentPath, 'png', '2026-09-25T12:05:00Z');
} catch (\Throwable $ledgerFailureThrown) {
    $ledgerFailure = $ledgerFailureThrown;
}
assertCondition($ledgerFailure !== null, 'El fallo de la Bitácora detiene el alta (sin asiento no hay acto)');
$pdo->exec('ALTER TABLE audit_log_purgada RENAME TO audit_log');
$vigenteLedger = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteLedger['avatar'] ?? null) === $vigenteReference,
    'El UPDATE rodó atrás: el vigente queda intacto (rollback del acto, RNF-05)'
);
assertCondition(
    count(glob($avatarsRoot . '/*') ?: []) === $filesBeforeLedger + 1,
    'El fichero huérfano fue purgado con el rollback (solo queda la fuente de la sonda)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === 1,
    'Ningún asiento del acto fallido sobrevive al rollback'
);
@unlink($differentPath);

// --- FASE 12: Fallo de almacenamiento — sin mutación ni huérfanos (caso límite 16) ---
echo "\nFASE 12: Fallo de almacenamiento — la aceptación es atómica\n";
$storageFile = sys_get_temp_dir() . '/grimorio_storage_failure_' . getmypid() . '.lock';
file_put_contents($storageFile, 'muralla');
$brokenService = new AvatarService($pdo, $repository, $storageFile);
$storageFailure = null;
try {
    $brokenService->uploadOwn('usr_linajado', $validPath, 'png', '2026-09-25T12:06:00Z');
} catch (\Throwable $storageFailureThrown) {
    $storageFailure = $storageFailureThrown;
}
assertCondition($storageFailure !== null, 'El fallo de almacenamiento detiene el alta antes de escribir');
assertCondition(
    $storageFailure instanceof \RuntimeException,
    'El fallo de almacenamiento viaja como RuntimeException (el controlador responde 500 sin trazas)'
);
$vigenteStorage = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteStorage['avatar'] ?? null) === $vigenteReference,
    'El vigente queda intacto ante el fallo de almacenamiento (sin mutación)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === 1,
    'Ningún asiento nace de un acto que no pudo escribirse'
);
assertCondition(!is_dir($storageFile), 'Ningún huérfano quedó en el almacenamiento roto (jamás se creó)');
unlink($storageFile);

// --- FASE 13: Elección del catálogo por el controlador (RF-03.1, RF-03.4) ---
echo "\nFASE 13: Elección del catálogo — asiento si cambia, inocuo si ya viste (RF-03.1/03.4)\n";
// El linajado viste su propia efigie (own:<fileId>): elegir del catálogo
// ES un cambio con efecto real → asiento.
$seatsBeforeCatalog = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn();
$response = $ownController->chooseAvatar(authenticatedRequestForAvatar(
    'usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame',
    '{"mode":"catalog","avatarId":"seal_solarCrown"}'
));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['avatar']['kind'] ?? '') === 'catalog' && ($body['data']['auditRecorded'] ?? false) === true,
    'La elección del catálogo con efecto real responde 200 con asiento (auditRecorded:true)'
);
$vigenteAfterCatalog = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteAfterCatalog['avatar'] ?? null) === 'catalog:seal_solarCrown',
    'La referencia catalog:<id> quedó escrita en la fila (efecto inmediato, RF-03.4)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === $seatsBeforeCatalog + 1,
    'El asiento acompañó al cambio con efecto real (RF-03.6)'
);

// --- FASE 14: Re-elección de la vigente — inocuo sin asiento (RF-03.6) ---
echo "\nFASE 14: Re-elección de la vigente — acto inocuo (RF-03.6)\n";
$response = $ownController->chooseAvatar(authenticatedRequestForAvatar(
    'usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame',
    '{"mode":"catalog","avatarId":"seal_solarCrown"}'
));
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 400 && ($body['error']['code'] ?? '') === 'AVATAR_IDENTICAL',
    'Re-elegir la vigente del catálogo responde 400 AVATAR_IDENTICAL (acto inocuo)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === $seatsBeforeCatalog + 1,
    'La re-elección inocua no dejó asiento (un acto sin efecto real no se inscribe)'
);

// --- FASE 15: El retiro por DELETE — retorno al canónico con borrado (RF-03.5, caso límite 5) ---
echo "\nFASE 15: El retiro — DELETE devuelve kind:default y borra el fichero propio\n";
// Primero: el linajado viste de nuevo su propia efigie (alta por el servicio).
$restoreUpload = $ownService->uploadOwn('usr_linajado', $validPath, 'png', '2026-09-25T12:07:00Z');
$ownFileId = (string) $restoreUpload['fileId'];
assertCondition(
    is_file($avatarsRoot . '/' . $ownFileId),
    'La efigie propia restaurada vive en el almacenamiento (preparación del retiro)'
);
$seatsBeforeRemove = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn();
$deleteRequest = new Request('DELETE', '/api/v1/panel/avatar');
$deleteRequest->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $ownController->removeAvatar($deleteRequest);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['avatar']['kind'] ?? '') === 'default' && ($body['data']['auditRecorded'] ?? false) === true,
    'El retiro responde 200 con kind:default y asiento (RF-03.5: la identidad jamás sin efigie)'
);
assertCondition(
    !is_file($avatarsRoot . '/' . $ownFileId),
    'El fichero propio fue BORRADO con el retiro (RF-03.4: el anterior deja de referenciarse)'
);
$vigenteAfterRemove = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteAfterRemove['avatar'] ?? null) === null,
    'La columna vuelve a NULL: el avatar canónico por defecto viste la identidad'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === $seatsBeforeRemove + 1,
    'El asiento del retiro acompañó al acto (RF-03.6: alta/retiro de efigie propia)'
);

// --- FASE 16: El doble retiro — inocuo sin asiento ni error (caso límite 5) ---
echo "\nFASE 16: El doble retiro — idempotencia solemne\n";
$response = $ownController->removeAvatar($deleteRequest);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    $response->getStatusCode() === 200 && ($body['data']['identical'] ?? false) === true && ($body['data']['auditRecorded'] ?? false) === false,
    'Retirar sin efigie propia es inocuo: 200 idempotente sin asiento (jamás un error)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === $seatsBeforeRemove + 1,
    'El doble retiro no dejó asiento nuevo (RF-03.6)'
);

// --- FASE 17: Degradación ante fichero inaccesible (Tarea 2.5, caso límite 15) ---
echo "\nFASE 17: Fichero inaccesible — degradación al canónico con bandera discreta\n";
// Alta propia válida y verificación de la vitrina ANTES del cataclismo.
$degradeUpload = $ownService->uploadOwn('usr_linajado', $validPath, 'png', '2026-09-25T12:08:00Z');
$degradeFileId = (string) $degradeUpload['fileId'];
$degradeReference = 'own:' . $degradeFileId;
$vitrinaRequest = new Request('GET', '/api/v1/panel');
$vitrinaRequest->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $ownController->show($vitrinaRequest);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    ($body['data']['panel']['identity']['avatar']['kind'] ?? '') === 'own' && !isset($body['data']['panel']['identity']['avatar']['unavailable']),
    'Con el fichero vivo, la vitrina muestra la efigie propia sin bandera alguna'
);

// El cataclismo: el fichero físico desaparece a mano (borrado externo,
// corrupción o cuota del plan — caso límite 15).
assertCondition(unlink($avatarsRoot . '/' . $degradeFileId), 'El fichero físico fue eliminado a mano (preparación del caso límite 15)');

$response = $ownController->show($vitrinaRequest);
$body = json_decode($response->getBody(), true) ?? [];
$degradedAvatar = $body['data']['panel']['identity']['avatar'] ?? [];
assertCondition(
    ($degradedAvatar['kind'] ?? '') === 'default' && ($degradedAvatar['reference'] ?? null) === null,
    'La vitrina degrada al avatar canónico por defecto: la identidad JAMÁS queda sin efigie'
);
assertCondition(
    ($degradedAvatar['unavailable'] ?? false) === true,
    'La bandera discreta de indisponibilidad viaja (unavailable:true, leyenda para el frontend)'
);
assertCondition(
    ($body['data']['panel']['identity']['avatar']['isOwn'] ?? true) === false,
    'La efigie degradada no se declara propia (isOwn:false)'
);

// El catálogo degrada con la MISMA solemnidad (su current es la vía de
// la cabecera y del picker).
$catalogRequest = new Request('GET', '/api/v1/panel/avatars');
$catalogRequest->setUser(forgeUser('usr_linajado', 'Heredera de la Llama', 'heredera@arcano.arc', 'editor', 'cln_llama', 'primordialFlame'));
$response = $ownController->avatarCatalog($catalogRequest);
$body = json_decode($response->getBody(), true) ?? [];
assertCondition(
    ($body['data']['current']['kind'] ?? '') === 'default' && ($body['data']['current']['unavailable'] ?? false) === true && ($body['data']['ownAvatar'] ?? null) === null,
    'El catálogo degrada su current al canónico con la bandera y ownAvatar deviene null'
);

// La fila JAMÁS se muta: la degradación es de lectura, no de escritura.
$vigenteAfterDegradation = $repository->fetchUserVitals('usr_linajado');
assertCondition(
    ($vigenteAfterDegradation['avatar'] ?? null) === $degradeReference,
    'La fila users.avatar NO cambia: sigue apuntando a la referencia propia (degradación de solo-lectura)'
);
assertCondition(
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'AVATAR_SELF_MODIFIED'")->fetchColumn() === $seatsBeforeRemove + 2,
    'La degradación no es acto: ningún asiento nuevo (un cataclismo no es un acto de gobierno)'
);

// Limpieza del almacenamiento temporal del arnés.
foreach (glob($avatarsRoot . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($avatarsRoot);

echo "\n== RESUMEN == Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";
if ($assertsFailed === 0) {
    echo "RESULTADO: EXITO — El ciclo de vida del avatar esta en pie: catálogo con efecto inmediato, alta propia validada y atómica, re-elección/retiro idempotentes, degradación de solo-lectura ante fichero inaccesible y trazabilidad solo de los actos con efecto real (Tareas 2.1–2.5).\n";
    exit(0);
}

echo "RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.\n";
exit(1);

<?php

/**
 * test_spell_drafts.php — Arnés TDD de la Tarea 3.1 (TASKS-04).
 *
 * Verifica la gestión de borradores privados (SpellManagementService):
 *   - createDraft(User, SpellCreateDto): persiste un borrador 'draft' con
 *     coste y círculo recalculados por el backend (determinismo ciego)
 *     y la huella matemática canónica.
 *   - listDrafts(User): solo los borradores del autor, ordenados.
 *   - updateDraft(User, spellId, SpellCreateDto): solo el titular.
 *   - deleteDraft(User, spellId): solo el titular.
 *   - Cuota dura de 10 borradores simultáneos por autor: el 11.º dispara
 *     DraftQuotaExceededException (HTTP 403, DRAFT_QUOTA_EXCEEDED); al
 *     eliminar uno, el autor puede operar de nuevo (criterio «Hecho cuando»).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria, sin ORM.
 *   - Artículo II: el backend recalcula el maná de forma ciega; el DTO de
 *     creación jamás porta coste.
 *   - Artículo III: la cuota limita la acumulación de datos zombis.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Core/Request.php';
require __DIR__ . '/../src/Core/Response.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Repositories/SpellReviewRepository.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\DraftQuotaExceededException;
use Grimorio\Models\User;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne del arnés.
 */
function assertArcane(bool $condition, string $legend): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  OK   {$legend}\n";
        return;
    }
    $assertsFailed++;
    echo "  FALLA {$legend}\n";
}

function catchException(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $exception) {
        return $exception;
    }
}

/**
 * Forja un DTO de creación canónico con el nombre dado.
 */
function forgeCreateDto(string $name, int $damage = 30): SpellCreateDto
{
    return new SpellCreateDto(
        name: $name,
        elementalAffinity: 'fire',
        magicSchool: 'evocation',
        castingTime: 'action',
        description: 'Conjuro forjado por el arnés de borradores.',
        calculationInput: new SpellCalculationInputDto(
            damage: $damage,
            healing: 0,
            barrier: 0,
            crowdControlType: 'none',
            rangeType: 'medium',
            areaType: 'sphere',
            durationType: 'instant',
            hasVerbal: true,
            hasSomatic: true,
            hasMaterial: false,
        ),
    );
}

echo "=== Tarea 3.1 (TASKS-04): SpellManagementService — Borradores ===\n\n";

// =====================================================================
// Escenario: SQLite en memoria con el esquema real.
// =====================================================================
$projectRoot = dirname(__DIR__);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$now = '2026-09-13T12:00:00Z';
$pdo->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_astral', 'astral-scholars', 'Eruditos Astrales', 'Saber', '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_autor', 'ForjadorDeBorradores', 'forjador@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_ajeno', 'ForjadorAjeno', 'ajeno@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User(
    id: 'usr_autor',
    alias: 'ForjadorDeBorradores',
    email: 'forjador@sanctuario.arc',
    role: 'editor',
    clanId: 'cln_astral',
    passwordHash: 'x',
    createdAt: $now,
    updatedAt: $now,
);
$foreignAuthor = new User(
    id: 'usr_ajeno',
    alias: 'ForjadorAjeno',
    email: 'ajeno@sanctuario.arc',
    role: 'editor',
    clanId: 'cln_astral',
    passwordHash: 'x',
    createdAt: $now,
    updatedAt: $now,
);

// =====================================================================
// [0] Superficie (fase roja).
// =====================================================================
echo "[0] Superficie — servicio y excepción de cuota\n";

assertArcane(class_exists(DraftQuotaExceededException::class), 'La clase DraftQuotaExceededException existe');
assertArcane(
    class_exists(SpellManagementService::class)
    && method_exists(SpellManagementService::class, 'createDraft')
    && method_exists(SpellManagementService::class, 'updateDraft')
    && method_exists(SpellManagementService::class, 'deleteDraft')
    && method_exists(SpellManagementService::class, 'listDrafts'),
    'SpellManagementService expone createDraft/updateDraft/deleteDraft/listDrafts'
);

$service = new SpellManagementService($pdo);
$quotaReflection = new ReflectionClass(DraftQuotaExceededException::class);
assertArcane(
    $quotaReflection->hasConstant('HTTP_STATUS_CODE') && $quotaReflection->getConstant('HTTP_STATUS_CODE') === 403,
    'DraftQuotaExceededException porta el código HTTP 403'
);
assertArcane(
    $quotaReflection->hasConstant('ERROR_CODE') && $quotaReflection->getConstant('ERROR_CODE') === 'DRAFT_QUOTA_EXCEEDED',
    'El código canónico de cuota es DRAFT_QUOTA_EXCEEDED'
);

// =====================================================================
// [1] Creación de borrador: determinismo ciego del backend.
// =====================================================================
echo "\n[1] createDraft — persistencia con coste recalculado por el backend\n";

$draft = $service->createDraft($author, forgeCreateDto('Esfera Ígnea de Frieren', damage: 30));

assertArcane(str_starts_with($draft['id'], 'spl_'), 'El borrador recibe un identificador spl_*');
assertArcane($draft['slug'] === 'esfera-ignea-de-frieren', 'El slug canónico se genera del nombre');
assertArcane($draft['status'] === 'draft', 'El estado inicial es draft (privado)');
assertArcane($draft['manaCost'] === 48, 'El backend recalculó el maná (30 × 2.0 − 20% = 48), ciego al cliente');
assertArcane($draft['circle'] === 3, 'El círculo asignado es el 3 (46-80)');
assertArcane($draft['circleLabel'] === 'Círculo III (Magister)', 'La etiqueta solemne del círculo acompaña al cálculo');
assertArcane($draft['mathFingerprint'] === hash('sha256', '30:0:0:none:medium:sphere:instant:1:1:0'), 'La huella matemática canónica queda persistida');
assertArcane($draft['signaturesCount'] === 0, 'Un borrador nace sin firmas');

// El nombre duplicado se rechaza (UNIQUE canónico del plan).
$duplicate = catchException(static fn () => $service->createDraft($author, forgeCreateDto('Esfera Ígnea de Frieren')));
assertArcane($duplicate instanceof RuntimeException, 'El nombre duplicado se rechaza (nombre canónico único)');

// =====================================================================
// [2] Listado y titularidad: solo los borradores del autor.
// =====================================================================
echo "\n[2] listDrafts — privacidad del borrador\n";

$service->createDraft($author, forgeCreateDto('Lanza de Escarcha', damage: 10));
$service->createDraft($foreignAuthor, forgeCreateDto('Muro de Sombras Ajeno', damage: 12));

$ownDrafts = $service->listDrafts($author);
assertArcane(count($ownDrafts) === 2, 'listDrafts retorna solo los borradores del autor (2)');
assertArcane(
    !in_array('muro-de-sombras-ajeno', array_column($ownDrafts, 'slug'), true),
    'El borrador ajeno jamás aparece en el listado'
);
$foreignDrafts = $service->listDrafts($foreignAuthor);
assertArcane(count($foreignDrafts) === 1 && $foreignDrafts[0]['slug'] === 'muro-de-sombras-ajeno', 'El autor ajeno ve exclusivamente su propio borrador');

// =====================================================================
// [3] Actualización y borrado: solo el titular opera.
// =====================================================================
echo "\n[3] updateDraft / deleteDraft — titularidad estricta\n";

$updateTarget = $ownDrafts[0]['id'];
$updated = $service->updateDraft($author, $updateTarget, forgeCreateDto('Esfera Ígnea de Frieren', damage: 40));
// 40 × 1.25 × 1.6 = 80 bruto → −20% = 64 neto → maná 64.
assertArcane($updated['manaCost'] === 64, 'La actualización recalcula el maná (40 × 2.0 − 20% = 64)');

$foreignUpdate = catchException(static fn () => $service->updateDraft($foreignAuthor, $updateTarget, forgeCreateDto('Robo Arcano')));
assertArcane($foreignUpdate === null || $foreignUpdate instanceof RuntimeException, 'Un autor ajeno no puede actualizar un borrador ajeno');

$foreignDelete = catchException(static fn () => $service->deleteDraft($foreignAuthor, $updateTarget));
$stillThere = catchException(static fn () => $service->updateDraft($author, $updateTarget, forgeCreateDto('Esfera Ígnea de Frieren', damage: 40)));
assertArcane($stillThere === null, 'El intento de borrado ajeno NO destruye el borrador del titular');

$deleted = $service->deleteDraft($author, $updateTarget);
assertArcane($deleted === true, 'El titular borra su propio borrador');

$missingUpdate = catchException(static fn () => $service->updateDraft($author, $updateTarget, forgeCreateDto('Fantasma')));
assertArcane($missingUpdate instanceof RuntimeException, 'Tras el borrado, actualizar el mismo id falla (inexistente)');

// =====================================================================
// [4] Cuota dura de 10 borradores: el 11.º dispara la excepción.
// =====================================================================
echo "\n[4] Cuota de 10 borradores (RF-05.1)\n";

// Estado actual del autor: 2 - 1 borrado + creación previa = 2 borradores
// ('Esfera Ígnea' actualizada + 'Lanza de Escarcha'). Se rellenan hasta 10.
$currentDrafts = $service->listDrafts($author);
$slotsToFill = 10 - count($currentDrafts);
for ($i = 1; $i <= $slotsToFill; $i++) {
    $service->createDraft($author, forgeCreateDto("Borrador de Relleno {$i}", damage: 5 + $i));
}
$filledDrafts = $service->listDrafts($author);
assertArcane(count($filledDrafts) === 10, 'El autor alcanza exactamente 10 borradores simultáneos');

$eleventh = catchException(static fn () => $service->createDraft($author, forgeCreateDto('El Undécimo Sello')));
assertArcane($eleventh instanceof DraftQuotaExceededException, 'El 11.º borrador dispara DraftQuotaExceededException (HTTP 403)');
assertArcane(
    $eleventh !== null && $eleventh->getHttpStatusCode() === 403 && $eleventh->getErrorCode() === 'DRAFT_QUOTA_EXCEEDED',
    'La excepción porta el contrato (403 + DRAFT_QUOTA_EXCEEDED)'
);
assertArcane(
    $eleventh !== null && str_contains($eleventh->getMessage(), '10'),
    'La leyenda porta el límite de cuota al autor'
);

// Al eliminar uno, el autor vuelve a poder operar (criterio «Hecho cuando»).
$service->deleteDraft($author, $filledDrafts[0]['id']);
$recovered = $service->createDraft($author, forgeCreateDto('El Undécimo Sello'));
assertArcane(str_starts_with($recovered['id'], 'spl_'), 'Tras eliminar uno previo, la creación del 11.º prospera (cuota liberada)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

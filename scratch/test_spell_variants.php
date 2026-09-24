<?php

/**
 * test_spell_variants.php — Arnés TDD de la Tarea 3.4 (TASKS-04).
 *
 * Verifica el mecanismo de derivación a Variantes (RF-06.1, RF-06.2,
 * RF-06.3, Art. III):
 *   - La prohibición de edición/borrado sobre el estado 'validated'
 *     queda blindada en updateDraft() y deleteDraft() con
 *     SpellImmutableException (HTTP 403).
 *   - createVariant(User, string) clona la ficha validada como un nuevo
 *     borrador 'draft' del invocante con el sufijo «(Variante)», nuevo
 *     identificador y recálculo ciego del maná (Art. II), sin alterar
 *     en absoluto el original.
 *
 * Criterio «Hecho cuando» (Tarea 3.4): intentar alterar un conjuro
 * validado lanza SpellImmutableException (HTTP 403), y la clonación
 * como variante crea un borrador editable con nuevo identificador sin
 * alterar el original.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, SQLite en memoria.
 *   - Artículo II: el maná de la variante se recalcula de forma ciega.
 *   - Artículo III: el validado es patrimonio inmutable de la biblioteca
 *     colectiva; la evolución legítima discurre por la Variante.
 *   - Artículo V: identificadores en inglés camelCase, comentarios y
 *     leyendas en castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
// La pluma exige su contrato desde SPEC-11 (Fase 2): el arnés lo carga
// antes del servicio, como el autoloader del front controller.
require __DIR__ . '/../src/Services/AuditRecorderInterface.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Exceptions/SpellNotFoundException.php';
require __DIR__ . '/../src/Repositories/SpellReviewRepository.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\SpellImmutableException;
use Grimorio\Models\User;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

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
 * DTO de creación con parámetros cuantitativos configurables.
 */
function forgeCreateDto(string $name, int $damage = 30, string $rangeType = 'medium'): SpellCreateDto
{
    return new SpellCreateDto(
        name: $name,
        elementalAffinity: 'fire',
        magicSchool: 'evocation',
        castingTime: 'action',
        description: 'Conjuro del arnés de variantes.',
        calculationInput: new SpellCalculationInputDto(
            damage: $damage,
            healing: 0,
            barrier: 0,
            crowdControlType: 'none',
            rangeType: $rangeType,
            areaType: 'sphere',
            durationType: 'instant',
            hasVerbal: true,
            hasSomatic: true,
            hasMaterial: false,
        ),
    );
}

function fetchRow(PDO $pdo, string $spellId): array
{
    $statement = $pdo->prepare(
        'SELECT id, slug, name, status, author_id, mana_cost, circle, math_fingerprint, signatures_count,
                damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                has_verbal, has_somatic, has_material
         FROM spells WHERE id = :id'
    );
    $statement->execute([':id' => $spellId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

echo "=== Tarea 3.4 (TASKS-04): inviolabilidad de validados y Variantes ===\n\n";

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
     VALUES ('usr_autor', 'AutorValidado', 'autor@sanctuario.arc', 'x', 'editor', 'cln_astral', '{$now}', '{$now}')"
);
$pdo->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$author = new User('usr_autor', 'AutorValidado', 'autor@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);
$foreignUser = new User('usr_ajeno', 'AjenoVariantes', 'ajeno@sanctuario.arc', 'editor', 'cln_astral', 'x', $now, $now);

// El servicio porta su propio AuditService (bitácora real).
$service = new SpellManagementService($pdo);

// =====================================================================
// [0] Superficie (fase roja): el método existe.
// =====================================================================
echo "[0] Superficie\n";
assertArcane(
    method_exists(SpellManagementService::class, 'createVariant'),
    'SpellManagementService expone createVariant()'
);

// =====================================================================
// [1] Preparación: ciclo completo draft → experimental → validated.
// =====================================================================
echo "\n[1] Preparación del legítimo validado\n";

$draft = $service->createDraft($author, forgeCreateDto('Lanza Solar', damage: 30));
$validatedId = $draft['id'];

// El original debe editarse libremente mientras permanezca en draft (RF-06.1).
$freeEdit = $service->updateDraft($author, $validatedId, forgeCreateDto('Lanza Solar', damage: 32));
assertArcane(is_array($freeEdit) && $freeEdit['id'] === $validatedId, 'En draft, el titular edita libremente (RF-06.1)');

$service->publishToExperimental($author, $validatedId);

// Tres firmas de Maestros: el motor legitimate el estado validated.
$pdo->prepare("UPDATE spells SET status = 'validated', signatures_count = 3 WHERE id = :id")
    ->execute([':id' => $validatedId]);
$originalBefore = fetchRow($pdo, $validatedId);
assertArcane($originalBefore['status'] === 'validated' && (int) $originalBefore['signatures_count'] === 3, 'El conjuro alcanza el estado validated con 3 firmas');

// =====================================================================
// [2] Inviolabilidad: el validado NO admite edición ni borrado (RF-06.2).
// =====================================================================
echo "\n[2] Inviolabilidad del validado (RF-06.2, 403)\n";

$editAttempt = catchException(
    static fn () => $service->updateExperimental($author, $validatedId, forgeCreateDto('Lanza Solar Corrupta'))
);
assertArcane($editAttempt instanceof SpellImmutableException, 'Editar el validado lanza SpellImmutableException (403)');

// El muro también cubre las rutas de draft (edición/borrado por estado).
$draftEditAttempt = catchException(
    static fn () => $service->updateDraft($author, $validatedId, forgeCreateDto('Lanza Solar Mutada'))
);
assertArcane($draftEditAttempt instanceof SpellImmutableException, 'updateDraft sobre un validado lanza SpellImmutableException (403)');

$draftDeleteAttempt = catchException(
    static fn () => $service->deleteDraft($author, $validatedId)
);
assertArcane($draftDeleteAttempt instanceof SpellImmutableException, 'deleteDraft sobre un validado lanza SpellImmutableException (403)');

// El original permanece IDÉNTICO tras todos los asaltos.
$originalAfter = fetchRow($pdo, $validatedId);
assertArcane($originalAfter === $originalBefore, 'El original permanece byte a byte intocable tras los asaltos');

// =====================================================================
// [3] Derivación a Variante (RF-06.3): clonación legítima.
// =====================================================================
echo "\n[3] createVariant: clonación legítima del validado (RF-06.3)\n";

$variant = $service->createVariant($author, $validatedId);

assertArcane(is_array($variant), 'createVariant retorna la ficha de la nueva variante');
assertArcane(is_array($variant) && ($variant['status'] ?? '') === 'draft', 'La variante nace en estado draft (editable)');
assertArcane(is_array($variant) && ($variant['id'] ?? '') !== $validatedId, 'La variante porta un identificador NUEVO y distinto');
assertArcane(is_array($variant) && str_contains((string) ($variant['name'] ?? ''), '(Variante)'), 'El nombre porta el sufijo solemne «(Variante)»');
assertArcane(is_array($variant) && ($variant['authorId'] ?? '') === $author->getId(), 'La variante pertenece al invocante que la deriva');

$variantRow = fetchRow($pdo, $variant['id'] ?? '');
assertArcane($variantRow !== [], 'La variante está materializada en la base de datos');
assertArcane(($variantRow['status'] ?? '') === 'draft' && (int) ($variantRow['signatures_count'] ?? 9) === 0, 'La variante es draft con 0 firmas (Moderación en 2 pasos desde cero)');
assertArcane((int) ($variantRow['mana_cost'] ?? 0) === (int) $originalBefore['mana_cost'], 'El maná clonado coincide con el del validado (recalculado ciegamente, Art. II)');
assertArcane((string) ($variantRow['math_fingerprint'] ?? '') === (string) $originalBefore['math_fingerprint'], 'La huella matemática de la variante es la del legítimo validado');
assertArcane(
    (int) $variantRow['damage'] === (int) $originalBefore['damage']
    && (string) $variantRow['range_type'] === (string) $originalBefore['range_type']
    && (string) $variantRow['duration_type'] === (string) $originalBefore['duration_type'],
    'Los 10 parámetros matemáticos se clonan fielmente'
);

// La variante es EDITABLE por su nuevo titular (borrador normal).
$variantEdit = $service->updateDraft($author, $variant['id'], forgeCreateDto('Lanza Solar (Variante)', damage: 45, rangeType: 'long'));
assertArcane(is_array($variantEdit) && (int) $variantEdit['manaCost'] === 87, 'La variante se edita libremente como borrador (recálculo: 45 × 1.5 × 1.6 = 108 → −20% → 87)');
assertArcane((int) fetchRow($pdo, (string) $variant['id'])['mana_cost'] === 87, 'El maná editado de la variante persiste recalculado');

// El original NO ha cambiado por la clonación ni por la edición de la variante.
assertArcane(fetchRow($pdo, $validatedId) === $originalBefore, 'El validado original permanece inalterado tras clonar y editar la variante');

// =====================================================================
// [4] Defensas: titularidad, inexistencia y ajeno.
// =====================================================================
echo "\n[4] Defensas de la derivación\n";

$foreignAttempt = catchException(static fn () => $service->createVariant($foreignUser, $validatedId));
assertArcane($foreignAttempt instanceof RuntimeException, 'Un ajeno no puede derivar variantes de un validado ajeno');

$ghostAttempt = catchException(static fn () => $service->createVariant($author, 'spl_fantasma'));
assertArcane($ghostAttempt instanceof RuntimeException, 'Derivar desde un identificador inexistente se rechaza');

// Solo los validados son origen de variantes: un draft no se deriva.
$draftSource = $service->createDraft($author, forgeCreateDto('Borrador Origen'));
$draftSourceAttempt = catchException(static fn () => $service->createVariant($author, $draftSource['id']));
assertArcane($draftSourceAttempt instanceof RuntimeException, 'Derivar desde un draft (no validado) se rechaza: solo el validado engendra variantes');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

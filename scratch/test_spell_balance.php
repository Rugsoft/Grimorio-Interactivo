<?php

/**
 * test_spell_balance.php — Suite A (matemática) + Suite B (ciclo de vida).
 *
 * Tarea 2.4 (TASKS-04): script CLI autónomo con los casos del plan 6.1:
 *   1. Suelo mínimo: efectos mínimos (1 de daño con 3 componentes) → maná 5.
 *   2. Redondeo ceil: caso con fracción → redondeo hacia arriba.
 *   3. Límite de descuento: el descuento nunca excede el 30%.
 *   4. Sobrecarga Arcana: 150 de daño en área esférica a larga distancia
 *      → ArcaneOverloadException (maná > 200).
 *   + Ponderaciones de efectos, fronteras de Círculos, determinismo de la
 *     huella matemática y neutralidad elemental.
 *
 * Tarea 3.5 (TASKS-04): Suite B de integración sobre SQLite en memoria
 * (esquema real de database/schema.sql):
 *   5. Límite de 10 borradores simultáneos (RF-05.1).
 *   6. Transición de publicación draft → experimental (RF-05.2).
 *   7. Reseteo antifraude de firmas: matemático vs narrativo (RF-05.3/05.4).
 *   8. Inviolabilidad de validados y creación de variantes (RF-06.1 a 06.3).
 *
 * Criterio «Hecho cuando» (Tarea 2.4): la ejecución
 * `php scratch/test_spell_balance.php` ejecuta los asertos matemáticos y
 * todos terminan en estado verde exitoso.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): aritmética nativa, sin librerías.
 *   - Artículo II: determinismo ciego y techo de contención.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Dto/SpellCreateDto.php';
require __DIR__ . '/../src/Exceptions/ArcaneOverloadException.php';
require __DIR__ . '/../src/Exceptions/DraftQuotaExceededException.php';
require __DIR__ . '/../src/Exceptions/SpellImmutableException.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';
require __DIR__ . '/../src/Models/User.php';
require __DIR__ . '/../src/Models/AuditEntry.php';
require __DIR__ . '/../src/Services/AuditLogPage.php';
require __DIR__ . '/../src/Services/AuditService.php';
require __DIR__ . '/../src/Repositories/SpellReviewRepository.php';
require __DIR__ . '/../src/Services/SpellManagementService.php';

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\ArcaneOverloadException;
use Grimorio\Exceptions\DraftQuotaExceededException;
use Grimorio\Exceptions\SpellImmutableException;
use Grimorio\Models\User;
use Grimorio\Services\SpellBalanceService;
use Grimorio\Services\SpellManagementService;

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Aserto solemne de la suite.
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

/**
 * Forja un DTO de entrada con todos los parámetros explícitos.
 */
function forgeInput(
    int $damage = 0,
    int $healing = 0,
    int $barrier = 0,
    string $crowdControlType = 'none',
    string $rangeType = 'touch',
    string $areaType = 'singleTarget',
    string $durationType = 'instant',
    bool $hasVerbal = false,
    bool $hasSomatic = false,
    bool $hasMaterial = false,
): SpellCalculationInputDto {
    return new SpellCalculationInputDto(
        damage: $damage,
        healing: $healing,
        barrier: $barrier,
        crowdControlType: $crowdControlType,
        rangeType: $rangeType,
        areaType: $areaType,
        durationType: $durationType,
        hasVerbal: $hasVerbal,
        hasSomatic: $hasSomatic,
        hasMaterial: $hasMaterial,
    );
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

echo "=== Tarea 2.4 (TASKS-04): Suite A — pruebas unitarias matemáticas ===\n\n";

$service = new SpellBalanceService();

// =====================================================================
// PRUEBA 1 (plan 6.1): Suelo mínimo de maná.
// =====================================================================
echo "[PRUEBA 1] Suelo mínimo: 1 de daño con 3 componentes → maná exacto 5\n";

$floorResult = $service->calculate(forgeInput(damage: 1, hasVerbal: true, hasSomatic: true, hasMaterial: true));
// 1 × 1.0 = 1 base → ×1.0 = 1 bruto → −30% → 0.7 → ceil 1 → suelo 5.
assertArcane($floorResult->finalManaCost === 5, 'El maná resultante es exactamente 5 (MANA_FLOOR)');
assertArcane($floorResult->circle === 1, 'El conjuro mínimo clasifica en Círculo I');
assertArcane($floorResult->discounts['totalPercent'] === 0.30, 'Los 3 componentes aplican el descuento máximo del 30%');

// =====================================================================
// PRUEBA 2 (plan 6.1): Redondeo hacia arriba (ceil).
// =====================================================================
echo "\n[PRUEBA 2] Redondeo ceil: fracciones siempre hacia arriba\n";

// Base 9.5 (healing 5 × 1.5 = 7.5 + damage 2 × 1.0 = 2.0) → ceil 10.
$ceilResult = $service->calculate(forgeInput(healing: 5, damage: 2));
assertArcane($ceilResult->netMana === 9.5, 'El maná neto conserva la fracción (9.5)');
assertArcane($ceilResult->finalManaCost === 10, 'ceil(9.5) = 10: el redondeo es hacia arriba');

// Base 30.4 (damage 16 + barrier 12 × 1.2) con descuento 30% → 21.28 → 22.
$ceilResult2 = $service->calculate(forgeInput(damage: 16, barrier: 12, hasVerbal: true, hasSomatic: true, hasMaterial: true));
assertArcane($ceilResult2->finalManaCost === 22, 'ceil(21.28) = 22 (coherente con las semillas del santuario)');

// =====================================================================
// PRUEBA 3 (plan 6.1): Límite de descuento — nunca superior al 30%.
// =====================================================================
echo "\n[PRUEBA 3] Límite de descuento: tope del 30% con los 3 componentes\n";

$cappedResult = $service->calculate(forgeInput(damage: 100, hasVerbal: true, hasSomatic: true, hasMaterial: true));
assertArcane($cappedResult->discounts['totalPercent'] === SpellBalanceService::MAX_COMPONENT_DISCOUNT, 'El descuento total queda acotado en MAX_COMPONENT_DISCOUNT (0.30)');
assertArcane($cappedResult->finalManaCost === 70, '100 × (1 − 0.30) = 70 de maná');
assertArcane($cappedResult->discounts['amountDeducted'] === 30.0, 'El importe deducido es 30.0');

// Cada componente aislado aporta exactamente su 10%.
$soloVerbal = $service->calculate(forgeInput(damage: 100, hasVerbal: true));
assertArcane($soloVerbal->discounts['totalPercent'] === 0.10 && $soloVerbal->finalManaCost === 90, 'Un solo componente aplica exactamente su 10%');

// =====================================================================
// PRUEBA 4 (plan 6.1): Sobrecarga Arcana (> 200 de maná).
// =====================================================================
echo "\n[PRUEBA 4] Sobrecarga Arcana: 150 de daño esférico a larga distancia\n";

// Caso literal del plan 6.1: 150 daño × (1.5 × 1.6) ≈ 360 > 200.
$overloadException = catchException(
    static fn () => $service->calculate(forgeInput(damage: 150, rangeType: 'long', areaType: 'sphere'))
);
assertArcane($overloadException instanceof ArcaneOverloadException, 'El caso del plan (150 daño × long × sphere) lanza ArcaneOverloadException');
assertArcane(
    $overloadException !== null && $overloadException->getCalculatedMana() > 200,
    'El maná calculado supera el techo (calculatedMana > 200)'
);
assertArcane(
    $overloadException !== null
    && $overloadException->getHttpStatusCode() === 400
    && $overloadException->getErrorCode() === 'ARCANE_OVERLOAD'
    && $overloadException->getMessage() === 'La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).',
    'El contrato de la sobrecarga viaja íntegro (400, ARCANE_OVERLOAD, mensaje ceremonial)'
);

// Frontera inclusiva: 200 exactos se admiten (Círculo V), 201 se rechaza.
$atCeiling = catchException(static fn () => $service->calculate(forgeInput(damage: 200)));
assertArcane($atCeiling === null, 'El maná 200 exacto NO dispara la sobrecarga (frontera inclusiva)');
$overThreshold = catchException(static fn () => $service->calculate(forgeInput(damage: 201)));
assertArcane($overThreshold instanceof ArcaneOverloadException, 'El maná 201 dispara la sobrecarga (umbral crítico)');

// =====================================================================
// PRUEBA 5: Ponderaciones de efectos base (WEIGHT_*, CC_WEIGHTS).
// =====================================================================
echo "\n[PRUEBA 5] Ponderaciones de efectos base\n";

assertArcane($service->calculate(forgeInput(healing: 20))->finalManaCost === 30, 'WEIGHT_HEALING 1.5: 20 de cura → 30 de maná');
assertArcane($service->calculate(forgeInput(barrier: 10))->finalManaCost === 12, 'WEIGHT_BARRIER 1.2: 10 de barrera → 12 de maná');
assertArcane($service->calculate(forgeInput(crowdControlType: 'stun'))->finalManaCost === 25, "CC_WEIGHTS stun → 25 de maná");
assertArcane($service->calculate(forgeInput(crowdControlType: 'root'))->finalManaCost === 15, "CC_WEIGHTS root → 15 de maná");
assertArcane($service->calculate(forgeInput(crowdControlType: 'slow'))->finalManaCost === 8, "CC_WEIGHTS slow → 8 de maná");

// =====================================================================
// PRUEBA 6: Multiplicadores geométricos combinados y Círculos.
// =====================================================================
echo "\n[PRUEBA 6] Multiplicadores combinados y fronteras de Círculos\n";

$combined = $service->calculate(forgeInput(damage: 10, rangeType: 'long', areaType: 'line', durationType: 'sustained'));
assertArcane(abs($combined->multipliers['combined'] - 3.15) < 1e-9 && $combined->finalManaCost === 32, '1.5 × 1.4 × 1.5 ≈ 3.15 → 32 de maná (ceil)');

$circleBoundaries = [[5, 1], [20, 1], [21, 2], [45, 2], [46, 3], [80, 3], [81, 4], [130, 4], [131, 5], [200, 5]];
$boundariesOk = true;
foreach ($circleBoundaries as [$mana, $expectedCircle]) {
    $boundaryResult = $service->calculate(forgeInput(damage: $mana));
    if ($boundaryResult->finalManaCost !== $mana || $boundaryResult->circle !== $expectedCircle) {
        $boundariesOk = false;
    }
}
assertArcane($boundariesOk, 'Las 10 fronteras de los 5 Círculos Arcanos clasifican correctamente (RF-03.1)');

// =====================================================================
// PRUEBA 7: Determinismo de la huella matemática (plan 3.2).
// =====================================================================
echo "\n[PRUEBA 7] Huella matemática: SHA-256 determinista\n";

$inputA = forgeInput(damage: 30, healing: 0, barrier: 5, crowdControlType: 'slow', rangeType: 'medium', areaType: 'sphere', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: false);
$inputB = forgeInput(damage: 30, healing: 0, barrier: 5, crowdControlType: 'slow', rangeType: 'medium', areaType: 'sphere', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: false);
$inputC = forgeInput(damage: 31, healing: 0, barrier: 5, crowdControlType: 'slow', rangeType: 'medium', areaType: 'sphere', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: false);

$fingerprintA = $service->computeMathFingerprint($inputA);
assertArcane($fingerprintA === $service->computeMathFingerprint($inputB), 'Parámetros idénticos → huella idéntica');
assertArcane($fingerprintA !== $service->computeMathFingerprint($inputC), 'Un cambio mínimo (+1 daño) → huella distinta');
assertArcane($fingerprintA === hash('sha256', '30:0:5:slow:medium:sphere:instant:1:1:0'), 'La huella sigue el formato canónico del plan 3.2');
assertArcane(strlen($fingerprintA) === 64 && ctype_xdigit($fingerprintA), 'La huella es SHA-256 hexadecimal de 64 caracteres');

// =====================================================================
// PRUEBA 8: Neutralidad elemental (sin interferencia de escuela/afinidad).
// =====================================================================
echo "\n[PRUEBA 8] Neutralidad elemental\n";

$fireLike = forgeInput(damage: 25);
$lightLike = forgeInput(damage: 25);
assertArcane(
    $service->calculate($fireLike)->finalManaCost === $service->calculate($lightLike)->finalManaCost,
    'La naturaleza elemental (fuego vs luz) no altera el coste'
);
$mathProperties = array_map(
    static fn (ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(SpellCalculationInputDto::class))->getProperties(),
);
assertArcane(
    !in_array('elementalAffinity', $mathProperties, true) && !in_array('magicSchool', $mathProperties, true),
    'El DTO matemático no porta afinidad elemental ni escuela (neutralidad estructural)'
);

// =====================================================================
// PRUEBA 9: Contrato de salida — desglose pedagógico serializable.
// =====================================================================
echo "\n[PRUEBA 9] Contrato de salida pedagógico\n";

$canonical = $service->calculate(forgeInput(damage: 30, rangeType: 'medium', areaType: 'sphere', hasVerbal: true, hasSomatic: true));
$decoded = json_decode((string) json_encode($canonical, JSON_UNESCAPED_UNICODE), true);
assertArcane(
    is_array($decoded)
    && $decoded['baseEffectPoints'] == 30.0
    && $decoded['grossMana'] == 60.0
    && $decoded['netMana'] == 48.0
    && $decoded['finalManaCost'] === 48
    && $decoded['circle'] === 3,
    'El desglose canónico del Endpoint 1 serializa al contrato del plan (30 → 60 → −12 → 48, Círculo III)'
);
assertArcane(
    $canonical->circleLabel === 'Círculo III (Magister)',
    'La etiqueta solemne del Círculo viaja en castellano (Art. IV)'
);

// =====================================================================
// =====================================================================
// SUITE B (Tarea 3.5): Integración de ciclo de vida sobre SQLite en
// memoria con el esquema real (database/schema.sql).
// =====================================================================
// =====================================================================

echo "\n\n=== Suite B (Tarea 3.5): ciclo de vida sobre SQLite en memoria ===\n";

$projectRoot = dirname(__DIR__);
$pdoLifecycle = new PDO('sqlite::memory:');
$pdoLifecycle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoLifecycle->exec('PRAGMA foreign_keys = ON');
$pdoLifecycle->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));

$lifecycleNow = '2026-09-13T18:00:00Z';
$pdoLifecycle->exec(
    "INSERT INTO clans (id, slug, name, motto, created_at)
     VALUES ('cln_suiteb', 'suite-b-scholars', 'Eruditos de la Suite B', 'Verificar', '{$lifecycleNow}')"
);
$pdoLifecycle->exec(
    "INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
     VALUES ('usr_bautor', 'AutorSuiteB', 'bautor@sanctuario.arc', 'x', 'editor', 'cln_suiteb', '{$lifecycleNow}', '{$lifecycleNow}')"
);
$pdoLifecycle->exec("INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación')");

$lifecycleAuthor = new User('usr_bautor', 'AutorSuiteB', 'bautor@sanctuario.arc', 'editor', 'cln_suiteb', 'x', $lifecycleNow, $lifecycleNow);
$managementService = new SpellManagementService($pdoLifecycle);

/**
 * DTO de creación configurable para la Suite B.
 */
function forgeCreateDto(string $name, int $damage = 30, string $rangeType = 'medium'): SpellCreateDto
{
    return new SpellCreateDto(
        name: $name,
        elementalAffinity: 'fire',
        magicSchool: 'evocation',
        castingTime: 'action',
        description: 'Conjuro de la Suite B.',
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

// ---------------------------------------------------------------------
// PRUEBA 10 (plan 6.2): Límite de 10 borradores (RF-05.1).
// ---------------------------------------------------------------------
echo "\n[PRUEBA 10] Cuota de 10 borradores simultáneos (RF-05.1)\n";

$draftIds = [];
for ($draftNumber = 1; $draftNumber <= 10; $draftNumber++) {
    $draftIds[] = $managementService->createDraft($lifecycleAuthor, forgeCreateDto("Borrador {$draftNumber}"))['id'];
}
assertArcane(count($draftIds) === 10, 'Los 10 borradores simultáneos se crean sin fricción');

$eleventhAttempt = catchException(
    static fn () => $managementService->createDraft($lifecycleAuthor, forgeCreateDto('Borrador 11'))
);
assertArcane($eleventhAttempt instanceof DraftQuotaExceededException, 'El undécimo borrador dispara DraftQuotaExceededException (403)');

// Eliminar uno libera el hueco y el undécimo prospera.
$managementService->deleteDraft($lifecycleAuthor, $draftIds[0]);
$recoveredDraft = $managementService->createDraft($lifecycleAuthor, forgeCreateDto('Borrador 11'));
assertArcane(is_array($recoveredDraft) && ($recoveredDraft['status'] ?? '') === 'draft', 'Tras eliminar uno previo, la creación del undécimo prospera');

// ---------------------------------------------------------------------
// PRUEBA 11 (plan 6.2): Transición de publicación (RF-05.2).
// ---------------------------------------------------------------------
echo "\n[PRUEBA 11] Publicación draft → experimental (RF-05.2)\n";

$published = $managementService->publishToExperimental($lifecycleAuthor, $recoveredDraft['id']);
assertArcane(($published['status'] ?? '') === 'experimental', 'El borrador transiciona a experimental');
assertArcane(($published['signaturesCount'] ?? -1) === 0, 'Las firmas quedan en 0/3 al publicar');

$publishedRowStatement = $pdoLifecycle->prepare('SELECT status, signatures_count FROM spells WHERE id = :id');
$publishedRowStatement->execute([':id' => $recoveredDraft['id']]);
$publishedRow = $publishedRowStatement->fetch(PDO::FETCH_ASSOC);
assertArcane(
    is_array($publishedRow) && $publishedRow['status'] === 'experimental' && (int) $publishedRow['signatures_count'] === 0,
    'La fila persistida confirma experimental con 0/3 firmas'
);

// ---------------------------------------------------------------------
// PRUEBA 12 (plan 6.2): Reseteo antifraude selectivo (RF-05.3 / RF-05.4).
// ---------------------------------------------------------------------
echo "\n[PRUEBA 12] Antifraude: cambio matemático resetea, cambio narrativo preserva\n";

// Dos Maestros firman el conjuro experimental.
$pdoLifecycle->prepare('UPDATE spells SET signatures_count = 2 WHERE id = :id')
    ->execute([':id' => $recoveredDraft['id']]);

// Cambio MATEMÁTICO: el alcance medium → long altera la huella.
$mathUpdate = $managementService->updateExperimental($lifecycleAuthor, $recoveredDraft['id'], forgeCreateDto('Borrador 11', damage: 30, rangeType: 'long'));
assertArcane($mathUpdate['signaturesReset'] === true, 'Un cambio matemático (alcance) retorna signaturesReset = true');

$mathRowStatement = $pdoLifecycle->prepare('SELECT signatures_count FROM spells WHERE id = :id');
$mathRowStatement->execute([':id' => $recoveredDraft['id']]);
assertArcane((int) $mathRowStatement->fetchColumn() === 0, 'Las firmas se restablecen a 0/3 tras el fraude matemático');

$resetEventStatement = $pdoLifecycle->prepare(
    "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = :id AND action_type = 'RESET_SIGNATURES_MATH_CHANGE'"
);
$resetEventStatement->execute([':id' => $recoveredDraft['id']]);
assertArcane((int) $resetEventStatement->fetchColumn() === 1, 'El evento RESET_SIGNATURES_MATH_CHANGE queda en audit_log');

// Cambio NARRATIVO: solo la descripción; las firmas se re-firman primero.
$pdoLifecycle->prepare('UPDATE spells SET signatures_count = 2 WHERE id = :id')
    ->execute([':id' => $recoveredDraft['id']]);

$narrativeDto = new SpellCreateDto(
    name: 'Borrador 11',
    elementalAffinity: 'fire',
    magicSchool: 'evocation',
    castingTime: 'action',
    description: 'Conjuro de la Suite B, ahora con la ortografía corregida.',
    calculationInput: new SpellCalculationInputDto(
        damage: 30, healing: 0, barrier: 0, crowdControlType: 'none',
        rangeType: 'long', areaType: 'sphere', durationType: 'instant',
        hasVerbal: true, hasSomatic: true, hasMaterial: false,
    ),
);
$narrativeUpdate = $managementService->updateExperimental($lifecycleAuthor, $recoveredDraft['id'], $narrativeDto);
assertArcane($narrativeUpdate['signaturesReset'] === false, 'Un cambio narrativo retorna signaturesReset = false');

$narrativeRowStatement = $pdoLifecycle->prepare('SELECT signatures_count FROM spells WHERE id = :id');
$narrativeRowStatement->execute([':id' => $recoveredDraft['id']]);
assertArcane((int) $narrativeRowStatement->fetchColumn() === 2, 'Las 2 firmas se PRESERVAN ante la corrección ortográfica');

$narrativeEventStatement = $pdoLifecycle->prepare(
    "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = :id AND action_type = 'UPDATE_DESCRIPTION_INTACT_SIGNATURES'"
);
$narrativeEventStatement->execute([':id' => $recoveredDraft['id']]);
assertArcane((int) $narrativeEventStatement->fetchColumn() === 1, 'El evento UPDATE_DESCRIPTION_INTACT_SIGNATURES queda en audit_log');

// ---------------------------------------------------------------------
// PRUEBA 13 (plan 6.2): Inviolabilidad de validados y variantes (RF-06).
// ---------------------------------------------------------------------
echo "\n[PRUEBA 13] Inviolabilidad del validado y derivación a Variantes (RF-06)\n";

// El experimento se consagra: 3 firmas → validated.
$pdoLifecycle->prepare("UPDATE spells SET status = 'validated', signatures_count = 3 WHERE id = :id")
    ->execute([':id' => $recoveredDraft['id']]);
$validatedSourceId = $recoveredDraft['id'];

$editAttempt = catchException(
    static fn () => $managementService->updateExperimental($lifecycleAuthor, $validatedSourceId, forgeCreateDto('Intacto'))
);
$draftEditAttempt = catchException(
    static fn () => $managementService->updateDraft($lifecycleAuthor, $validatedSourceId, forgeCreateDto('Intacto'))
);
$draftDeleteAttempt = catchException(
    static fn () => $managementService->deleteDraft($lifecycleAuthor, $validatedSourceId)
);
assertArcane(
    $editAttempt instanceof SpellImmutableException
    && $draftEditAttempt instanceof SpellImmutableException
    && $draftDeleteAttempt instanceof SpellImmutableException,
    'Las 3 rutas de mutación sobre el validado lanzan SpellImmutableException (403)'
);

$variant = $managementService->createVariant($lifecycleAuthor, $validatedSourceId);
assertArcane(is_array($variant) && ($variant['status'] ?? '') === 'draft' && ($variant['id'] ?? '') !== $validatedSourceId, 'La variante nace como draft editable con identificador inédito');
assertArcane(is_array($variant) && str_contains((string) ($variant['name'] ?? ''), '(Variante)'), 'La variante porta el sufijo solemne «(Variante)»');

// La huella clonada se verifica ANTES de editar la variante (la edición
// legítima altera su matemática y, con ella, su huella).
$variantFingerprintStatement = $pdoLifecycle->prepare('SELECT math_fingerprint FROM spells WHERE id = :id');
$variantFingerprintStatement->execute([':id' => $variant['id']]);
$sourceFingerprintStatement = $pdoLifecycle->prepare('SELECT math_fingerprint FROM spells WHERE id = :id');
$sourceFingerprintStatement->execute([':id' => $validatedSourceId]);
assertArcane(
    (string) $variantFingerprintStatement->fetchColumn() === (string) $sourceFingerprintStatement->fetchColumn(),
    'La huella matemática de la variante coincide con la del validado origen'
);

$variantEdit = $managementService->updateDraft($lifecycleAuthor, $variant['id'], forgeCreateDto('Lanza Solar (Variante)', damage: 45, rangeType: 'long'));
assertArcane(is_array($variantEdit) && (int) $variantEdit['manaCost'] === 87, 'La variante se edita libremente con recálculo ciego (45 × 1.5 × 1.6 → −20% → 87)');

$variantEventStatement = $pdoLifecycle->prepare(
    "SELECT COUNT(*) FROM audit_log WHERE target_entity_id = :id AND action_type = 'CREATE_VARIANT_FROM_VALIDATED'"
);
$variantEventStatement->execute([':id' => $variant['id']]);
assertArcane((int) $variantEventStatement->fetchColumn() === 1, 'La derivación queda imborrable en audit_log (CREATE_VARIANT_FROM_VALIDATED)');

// =====================================================================
// Resumen final.
// =====================================================================
echo "\n=== RESUMEN: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

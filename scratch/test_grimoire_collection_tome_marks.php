<?php

declare(strict_types=1);

/**
 * test_grimoire_collection_tome_marks.php — Verificación de la Tarea 2.1
 * de TASKS-11.
 *
 * Valida el MAPA ÚNICO DE ESTADOS a marcas solemnes del tomo
 * (`GrimoireCollectionService::tomeMarkForStatus()`, plan §3.1)
 * contra el «Hecho cuando» de la tarea:
 *
 *   1. Los cinco estados del ciclo de vida (`draft`, `experimental`,
 *      `validated`, `rejected`, `archived` — catálogo cerrado de
 *      SPEC-08) producen exactamente las TRES marcas canónicas.
 *   2. El método LANZA ante un estado fuera del catálogo (jamás
 *      inventa una marca que la especificación no conoce).
 *
 * Fases:
 *   [0] Superficie: el servicio existe y declara sus tres marcas.
 *   [1] Mapa completo: los cinco estados, las tres marcas, sin residuos.
 *   [2] La muralla: estados fuera del catálogo lanzan InvalidArgumentException.
 *   [3] Unicidad del mapa: la traducción vive UNA sola vez (RF-03.2,
 *       hallazgo 12) — cero lógica duplicada en el frontend.
 *   [4] Guardia de sellabilidad: solo `validated` es sellable (RF-01.1,
 *       RF-04.5), compartida por sellado y elogio.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): sin dependencias externas.
 *   - Artículo V (Dualidad): identificadores en inglés; narrativa en
 *     noble castellano.
 *
 * Uso: php scratch/test_grimoire_collection_tome_marks.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

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

echo "=== Mapa único de estados a marcas del tomo — Tarea 2.1 de TASKS-11 ===\n";

require __DIR__ . '/../src/Services/GrimoireCollectionService.php';

use Grimorio\Services\GrimoireCollectionService;

echo "\n[FASE 0] Superficie: el servicio existe y declara sus tres marcas.\n";
assertCondition(class_exists(GrimoireCollectionService::class), 'El servicio GrimoireCollectionService existe.');
assertCondition(defined(GrimoireCollectionService::class . '::TOME_MARK_LIVING'), 'Declara la marca living (obra viva del canon).');
assertCondition(defined(GrimoireCollectionService::class . '::TOME_MARK_GESTATION'), 'Declara la marca gestation (obra en gestación).');
assertCondition(defined(GrimoireCollectionService::class . '::TOME_MARK_WITHDRAWN'), 'Declara la marca withdrawn (obra apartada del canon).');
assertCondition(method_exists(GrimoireCollectionService::class, 'tomeMarkForStatus'), 'Declara tomeMarkForStatus() como el mapa único.');

echo "\n[FASE 1] Mapa completo: cinco estados → tres marcas, sin residuos (RF-03.2).\n";
$expectedMap = [
    'validated' => GrimoireCollectionService::TOME_MARK_LIVING,
    'draft' => GrimoireCollectionService::TOME_MARK_GESTATION,
    'experimental' => GrimoireCollectionService::TOME_MARK_GESTATION,
    'rejected' => GrimoireCollectionService::TOME_MARK_WITHDRAWN,
    'archived' => GrimoireCollectionService::TOME_MARK_WITHDRAWN,
];
$producedMarks = [];
foreach ($expectedMap as $status => $expectedMark) {
    $produced = GrimoireCollectionService::tomeMarkForStatus($status);
    $producedMarks[] = $produced;
    assertCondition($produced === $expectedMark, "El estado '{$status}' viste la marca '{$expectedMark}'.");
}
assertCondition(count(array_unique($producedMarks)) === 3, 'Los cinco estados agotan EXACTAMENTE las tres marcas canónicas (ninguna marca muere sin estado, ningún estado queda sin marca).');
// La partición es total: cada grupo de estados viste UNA sola marca y las
// tres marcas están presentes. Caso límite 7 (validado que cae) queda
// cubierto: el método acepta el estado vivo de la fila en cada lectura.
assertCondition(GrimoireCollectionService::tomeMarkForStatus('validated') !== GrimoireCollectionService::tomeMarkForStatus('experimental'), 'Lo vivo del canon y lo en gestación jamás comparten marca (la convocatoria se distingue del aguardo).');

echo "\n[FASE 2] La muralla: estados fuera del catálogo LANZAN.\n";
$thrown = null;
try {
    GrimoireCollectionService::tomeMarkForStatus('en_revision');
} catch (InvalidArgumentException $failure) {
    $thrown = $failure->getMessage();
}
assertCondition($thrown !== null, "Un estado fuera del catálogo ('en_revision') lanza InvalidArgumentException.");
assertCondition($thrown !== null && str_contains($thrown, 'en_revision'), 'El mensaje nombra el estado intruso para el diagnóstico.');
foreach (['', 'VALIDATED', 'unknown', 'pending', 'deleted'] as $intruder) {
    $launched = false;
    try {
        GrimoireCollectionService::tomeMarkForStatus($intruder);
    } catch (InvalidArgumentException) {
        $launched = true;
    }
    assertCondition($launched, "El estado '{$intruder}' (vacío, mayúsculas o inventado) jamás obtiene marca.");
}

echo "\n[FASE 3] Unicidad del mapa: la traducción vive UNA sola vez (hallazgo 12).\n";
$repositorySource = '';
$repositoryPath = __DIR__ . '/../src/Repositories/GrimoireCollectionRepository.php';
if (is_readable($repositoryPath)) {
    $repositorySource = (string) file_get_contents($repositoryPath);
}
assertCondition(!str_contains($repositorySource, 'living') && !str_contains($repositorySource, 'gestation') && !str_contains($repositorySource, 'withdrawn'), 'El repositorio jamás traduce estados a marcas: el mapa vive SOLO en el servicio.');
// La vigilancia del frontend busca el TRÍO completo de marcas juntas
// (solo la traducción del mapa las necesita a las tres): coincidencias
// sueltas con una sola palabra —un aviso de retirada, un evento de
// petición— no son el mapa.
$frontendTomeMarkFiles = array_filter(
    glob(__DIR__ . '/../public/assets/js/**/*.js') ?: [],
    static fn (string $path): bool => (bool) (preg_match('/living/', (string) file_get_contents($path))
        && preg_match('/gestation/', (string) file_get_contents($path))
        && preg_match('/withdrawn/', (string) file_get_contents($path)))
);
assertCondition($frontendTomeMarkFiles === [], 'Cero lógica del mapa duplicada en el frontend: ninguna superficie porta el trío de marcas (aún no existe la vista; la vigilancia queda instalada).');

echo "\n[FASE 4] Guardia de sellabilidad: solo lo consagrado entra (RF-01.1, RF-04.5).\n";
assertCondition(GrimoireCollectionService::isSellableStatus('validated') === true, "Solo 'validated' es sellable.");
foreach (['draft', 'experimental', 'rejected', 'archived'] as $unworthy) {
    assertCondition(GrimoireCollectionService::isSellableStatus($unworthy) === false, "El estado '{$unworthy}' no es sellable ni elogiable.");
}

echo "\n=== RESULTADO: {$assertsPassed} asertos en verde, {$assertsFailed} en rojo ===\n";
if ($assertsFailed > 0) {
    exit(1);
}
echo "Tarea 2.1 verificada: el mapa único de marcas viste todo el ciclo de vida.\n";

<?php

/**
 * test_lineage_synergy_service.php — Verificación de la Tarea 2.2 de TASKS-07.
 *
 * Valida contra el criterio «Hecho cuando» de
 * specs/07-clans-lineages.tasks.md:
 *   «La función applySynergy(5, 'primordialFlame', 'fire') retorna 6 PDA
 *    (6.25 → 6) y applySynergy(10, 'eternalTempest', 'lightning') retorna
 *    13 PDA (12.5 → 13), mientras que elementos sin coincidencia retornan el
 *    valor base sin alteración.»
 *
 * Fases:
 *   [0] Superficie: el servicio existe, declara tipado estricto y es `final`.
 *   [1] Catálogo canónico (RF-02.1): exactamente 8 linajes, claves exactas y
 *       en el orden del canon, sin duplicados.
 *   [2] Heráldica (RF-02.2): elemento rector, sigilo, color de estandarte y
 *       marco distintivo, únicos y válidos.
 *   [3] Coherencia con el Códice Elemental (SPEC-06): todo elemento rector
 *       existe como elemento del Códice y comparte su color heráldico.
 *   [4] «Hecho cuando»: los dos casos literales del criterio de aceptación.
 *   [5] Elementos sin coincidencia: el valor base retorna intacto.
 *   [6] Redondeo aritmético estándar: paridad frente a round() de PHP en un
 *       barrido amplio, con los casos frontera del 0.5.
 *   [7] Mapa inmutable de sinergia (plan 3.2) y paridad con el recibo canónico.
 *   [8] Determinismo (RNF-01): dos instancias forjan catálogos idénticos byte
 *       a byte; applySynergy() es función pura de sus argumentos.
 *   [9] Guardas de canon: linaje desconocido y valor base negativo.
 *  [10] Artículo II: el coste de maná de la forja queda demostrablemente
 *       intacto tras aplicar la sinergia.
 *  [11] Dogma Vanilla y ausencia de azar/reloj/PDO en el servicio.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PHP nativo; sin librerías ni npm.
 *   - Artículo V: claves en inglés camelCase; narrativa en castellano.
 *
 * Uso: php scratch/test_lineage_synergy_service.php
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$servicePath = __DIR__ . '/../src/Services/LineageSynergyService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "[FATAL] Falta src/Services/LineageSynergyService.php — fase roja: aún no existe.\n");
    exit(1);
}

require __DIR__ . '/../src/Dto/LineageDto.php';
require __DIR__ . '/../src/Dto/DominionAwardDto.php';
require __DIR__ . '/../src/Dto/ElementalMatrixGraphDto.php';
require __DIR__ . '/../src/Dto/ElementalReactionDto.php';
require __DIR__ . '/../src/Services/LineageSynergyService.php';
require __DIR__ . '/../src/Services/ElementalMatrixService.php';
require __DIR__ . '/../src/Dto/SpellCalculationInputDto.php';
require __DIR__ . '/../src/Dto/SpellCalculationResultDto.php';
require __DIR__ . '/../src/Services/SpellBalanceService.php';

use Grimorio\Dto\DominionAwardDto;
use Grimorio\Dto\LineageDto;
use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Services\ElementalMatrixService;
use Grimorio\Services\LineageSynergyService;
use Grimorio\Services\SpellBalanceService;

$assertsPassed = 0;
$assertsFailed = 0;

/** Advertencias y avisos de PHP capturados durante la prueba. */
$phpWarnings = [];

/** Convierte cualquier advertencia nativa en materia de aserto (no la silencia). */
set_error_handler(static function (int $severity, string $message): bool {
    global $phpWarnings;
    $phpWarnings[] = $message;
    return true;
});

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

/** Aserta que una invocación es rechazada con InvalidArgumentException. */
function assertRejects(callable $invocation, string $description): void
{
    try {
        $invocation();
        assertCondition(false, "{$description}: debía rechazarse y no lo hizo");
    } catch (InvalidArgumentException) {
        assertCondition(true, "{$description}: rechazado con leyenda");
    } catch (Throwable $throwable) {
        assertCondition(false, "{$description}: rechazado con " . $throwable::class);
    }
}

/** Canon literal del enunciado (RF-02.1): no se deriva del servicio, se dicta. */
$expectedLineageElementMap = [
    'primordialFlame' => 'fire',
    'celestialTides'  => 'water',
    'eternalTempest'  => 'lightning',
    'worldRoots'      => 'earth',
    'dawnWinds'       => 'wind',
    'solarCrown'      => 'light',
    'abyssalShadows'  => 'darkness',
    'aetherWeavers'   => 'pureArcane',
];

$service = new LineageSynergyService();

echo "═══ FASE 0 · Superficie del servicio ═══\n";
$source = (string) file_get_contents($servicePath);
assertCondition(str_contains($source, 'declare(strict_types=1);'), 'Declara tipado estricto');
assertCondition(str_contains($source, 'namespace Grimorio\\Services;'), 'Mora en Grimorio\\Services');
$reflection = new ReflectionClass(LineageSynergyService::class);
assertCondition($reflection->isFinal(), 'La clase es final');
assertCondition(!$reflection->isAbstract(), 'La clase es instanciable');

echo "\n═══ FASE 1 · Catálogo canónico de los 8 Linajes (RF-02.1) ═══\n";
$lineages = $service->listLineages();
assertCondition(count($lineages) === 8, 'Existen exactamente 8 Linajes Canónicos');
assertCondition(array_map(static fn (LineageDto $l): string => $l->id, $lineages) === array_keys($expectedLineageElementMap), 'Las claves canónicas y su orden son exactos');
assertCondition($service->canonicalLineageIds() === array_keys($expectedLineageElementMap), 'canonicalLineageIds() coincide con el canon');
assertCondition(count(array_unique($service->canonicalLineageIds())) === 8, 'No hay linajes duplicados');
assertCondition($service->lineageElementMap() === $expectedLineageElementMap, 'El mapa linaje→elemento rector es exacto');
foreach ($expectedLineageElementMap as $lineageId => $element) {
    assertCondition($service->hasLineage($lineageId), "hasLineage('{$lineageId}') reconoce el canon");
    assertCondition($service->rulingElementFor($lineageId) === $element, "rulingElementFor('{$lineageId}') ⇒ {$element}");
}
assertCondition(!$service->hasLineage('primordialFlames'), 'Un identificador aproximado NO pertenece al canon');
assertCondition($service->findLineage('  solarCrown  ') !== null, 'findLineage() tolera espacios sobrantes');
assertCondition($service->findLineage('linajeInventado') === null, 'findLineage() devuelve null fuera del canon');

echo "\n═══ FASE 2 · Heráldica distintiva de cada Linaje (RF-02.2) ═══\n";
$glyphs = [];
$colors = [];
$frames = [];
foreach ($lineages as $lineage) {
    $glyphs[] = $lineage->glyph;
    $colors[] = $lineage->bannerColor;
    $frames[] = $lineage->heraldicFrame;
    assertCondition(trim($lineage->name) !== '' && str_starts_with($lineage->name, 'Linaje de'), "{$lineage->id}: título ceremonial en castellano");
    assertCondition(trim($lineage->description) !== '', "{$lineage->id}: prosa mitológica presente");
    assertCondition(preg_match('/^#[0-9a-f]{6}$/i', $lineage->bannerColor) === 1, "{$lineage->id}: estandarte en notación heráldica");
    assertCondition(str_starts_with($lineage->glyph, 'rune-'), "{$lineage->id}: glifo rúnico ancestral");
    assertCondition(preg_match('/^[a-z][a-zA-Z]+$/', $lineage->heraldicFrame) === 1, "{$lineage->id}: marco heráldico en inglés camelCase");
}
assertCondition(count(array_unique($frames)) === 8, 'Los ocho marcos heráldicos son distintivos');
assertCondition(count(array_unique($colors)) === 8, 'Los ocho colores de estandarte son distintivos');
assertCondition(count(array_unique($glyphs)) === 8, 'Los ocho glifos rúnicos son distintivos');

echo "\n═══ FASE 3 · Coherencia con el Códice Elemental (SPEC-06) ═══\n";
$matrixGraph = (new ElementalMatrixService())->getMatrixGraph();
assertCondition($matrixGraph->reactionCount() >= 7, 'El Códice Elemental responde (integración viva)');
foreach ($expectedLineageElementMap as $lineageId => $element) {
    $codexElement = $matrixGraph->getElementById($element);
    assertCondition($codexElement !== null, "{$lineageId}: su elemento rector «{$element}» existe en el Códice");
    if ($codexElement !== null) {
        assertCondition(
            strtolower((string) $codexElement['color']) === strtolower($service->findLineage($lineageId)->bannerColor),
            "{$lineageId}: el estandarte comparte la heráldica de «{$element}»"
        );
    }
}

echo "\n═══ FASE 4 · «Hecho cuando»: los dos casos del criterio ═══\n";
assertCondition($service->applySynergy(5, 'primordialFlame', 'fire') === 6, "applySynergy(5, primordialFlame, fire) ⇒ 6 PDA (6.25 → 6)");
assertCondition($service->applySynergy(10, 'eternalTempest', 'lightning') === 13, "applySynergy(10, eternalTempest, lightning) ⇒ 13 PDA (12.5 → 13)");
assertCondition($service->synergyBonus(5, 'primordialFlame', 'fire') === 1, 'El diferencial de sinergia sobre 5 PDA es +1');
assertCondition($service->synergyBonus(10, 'eternalTempest', 'lightning') === 3, 'El diferencial de sinergia sobre 10 PDA es +3');

echo "\n═══ FASE 5 · Elementos sin coincidencia: valor base intacto (RF-03.4) ═══\n";
$neutralCases = [
    [5, 'primordialFlame', 'water'],
    [5, 'primordialFlame', 'none'],
    [5, 'primordialFlame', ''],
    [5, 'primordialFlame', null],
    [10, 'eternalTempest', 'earth'],
    [10, 'eternalTempest', 'NONE'],
    [140, 'celestialTides', 'fire'],
    [200, 'aetherWeavers', 'darkness'],
    [0, 'worldRoots', 'fire'],
];
foreach ($neutralCases as [$base, $lineageId, $element]) {
    $label = $element === null ? 'null' : ($element === '' ? 'cadena vacía' : $element);
    assertCondition(
        $service->applySynergy($base, $lineageId, $element) === $base,
        "Sin coincidencia: {$base} PDA con «{$label}» retorna {$base} intacto"
    );
    assertCondition($service->hasSynergy($lineageId, $element) === false, "hasSynergy() es falso para «{$label}» en {$lineageId}");
}
assertCondition($service->hasSynergy('solarCrown', 'light') === true, 'hasSynergy() es cierto para la afinidad rectora');

echo "\n═══ FASE 6 · Redondeo aritmético estándar (RF-03.4) ═══\n";
$roundingFrontier = [1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 8, 7 => 9, 8 => 10, 9 => 11, 10 => 13];
foreach ($roundingFrontier as $base => $expected) {
    $awarded = $service->applySynergy($base, 'dawnWinds', 'wind');
    assertCondition($awarded === $expected, "Frontera del 0.5: {$base} × 1.25 ⇒ {$expected} PDA");
}
$parityFailures = 0;
for ($base = 0; $base <= 2000; $base++) {
    if ($service->applySynergy($base, 'abyssalShadows', 'darkness') !== (int) round($base * 1.25)) {
        $parityFailures++;
    }
}
assertCondition($parityFailures === 0, 'Paridad exacta con round(basePoints × 1.25) en 2001 valores');
assertCondition($service->applySynergy(0, 'abyssalShadows', 'darkness') === 0, 'Cero PDA base permanece cero');
assertCondition($service->applySynergy(200, 'solarCrown', 'light') === 250, 'Círculo V sinérgico: 200 ⇒ 250 PDA');

echo "\n═══ FASE 7 · Mapa inmutable de sinergia y recibo canónico ═══\n";
assertCondition($service->lineageElementMap() === $expectedLineageElementMap, 'lineageElementMap() reproduce el mapa del plan 3.2');
assertCondition(LineageSynergyService::SYNERGY_MULTIPLIER === 1.25, 'El factor de sinergia es exactamente 1.25');
assertCondition(DominionAwardDto::SYNERGY_MULTIPLIER === LineageSynergyService::SYNERGY_MULTIPLIER, 'El recibo canónico declara el mismo factor (sin divergencia)');
assertCondition($service->agreesWithAwardContract(), 'agreesWithAwardContract() confirma la paridad de contratos');

echo "\n═══ FASE 8 · Determinismo y pureza (RNF-01) ═══\n";
$twinService = new LineageSynergyService();
assertCondition(
    json_encode($service->listLineages(), JSON_UNESCAPED_UNICODE) === json_encode($twinService->listLineages(), JSON_UNESCAPED_UNICODE),
    'Dos instancias forjan catálogos idénticos byte a byte'
);
assertCondition(
    json_encode($service->lineageElementMap()) === json_encode($twinService->lineageElementMap()),
    'Dos instancias producen el mismo mapa de sinergia'
);
$firstPass = $service->applySynergy(10, 'eternalTempest', 'lightning');
$secondPass = $service->applySynergy(10, 'eternalTempest', 'lightning');
assertCondition($firstPass === $secondPass, 'applySynergy() es idempotente y sin memoria oculta');
assertCondition($service->listLineages() === $lineages, 'El catálogo memoizado se devuelve estable entre invocaciones');

echo "\n═══ FASE 9 · Guardas de canon ═══\n";
assertRejects(static fn () => $service->applySynergy(5, 'linajeInventado', 'fire'), 'Sinergia: linaje ajeno al canon');
assertRejects(static fn () => $service->applySynergy(5, '', 'fire'), 'Sinergia: linaje vacío');
assertRejects(static fn () => $service->applySynergy(-5, 'primordialFlame', 'fire'), 'Sinergia: valor base negativo');
assertRejects(static fn () => $service->rulingElementFor('ordenDelFelino'), 'rulingElementFor(): linaje desconocido');
assertRejects(static fn () => $service->synergyBonus(-1, 'worldRoots', 'earth'), 'synergyBonus(): valor base negativo');

echo "\n═══ FASE 10 · Artículo II: el maná de la forja permanece inviolable (RF-02.3) ═══\n";
$manaInput = new SpellCalculationInputDto(
    damage: 40,
    healing: 0,
    barrier: 0,
    crowdControlType: 'none',
    rangeType: 'medium',
    areaType: 'singleTarget',
    durationType: 'instant',
    hasVerbal: true,
    hasSomatic: true,
    hasMaterial: false,
);
$balanceService = new SpellBalanceService();
$manaBefore = $balanceService->calculate($manaInput)->finalManaCost;
// El linaje del clan es rector para el elemento del conjuro: se aplica la sinergia.
$fireSynergyAward = $service->applySynergy(200, 'primordialFlame', 'fire');
$manaAfter = $balanceService->calculate($manaInput)->finalManaCost;
assertCondition($fireSynergyAward === 250, 'La sinergia otorga gloria al clan (200 ⇒ 250 PDA)');
assertCondition($manaBefore === $manaAfter, 'El coste de maná del conjuro no cambia al aplicar sinergia');
assertCondition((new SpellBalanceService())->calculate($manaInput)->finalManaCost === $manaBefore, 'La fórmula universal de maná sigue siendo determinista');
assertCondition(preg_match('/\bmana\b|\bMANA\b|SpellBalance/i', $source) !== 1, 'El servicio no menciona el maná ni el forjador: no puede alterarlo');

echo "\n═══ FASE 11 · Dogma Vanilla, sin azar y sin reloj ═══\n";
assertCondition(!preg_match('/^\s*use\s+Vendor\\\\/m', $source), 'Sin dependencias de terceros');
assertCondition(preg_match('/\bPDO\b|new\s+PDO/', $source) !== 1, 'Sin acceso a base de datos (función pura)');
assertCondition(preg_match('/\b(mt_rand|random_int|rand|shuffle|array_rand)\s*\(/', $source) !== 1, 'Sin azar: el cómputo es ciego (RNF-01)');
assertCondition(preg_match('/\b(time|date|microtime|hrtime)\s*\(/', $source) !== 1, 'Sin lectura del reloj del sistema (RNF-01)');
assertCondition($phpWarnings === [], 'Ninguna advertencia de PHP emitida' . ($phpWarnings !== [] ? ': ' . implode(' | ', $phpWarnings) : ''));

echo "\n" . str_repeat('─', 72) . "\n";
echo "Asertos superados: {$assertsPassed}, fallidos: {$assertsFailed}\n";

if ($assertsFailed > 0) {
    echo "RESULTADO: FALLO — la Tarea 2.2 no cumple aún su criterio 'Hecho cuando'.\n";
    exit(1);
}

echo "RESULTADO: EXITO — La Tarea 2.2 cumple su criterio 'Hecho cuando'.\n";
exit(0);

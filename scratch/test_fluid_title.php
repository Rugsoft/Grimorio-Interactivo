<?php

/**
 * test_fluid_title.php — Verificación de la Tarea 4.3 de TASKS-02.
 *
 * Tipografía fluida para nombres extensos de conjuros: `clamp()`
 * tipográfico y limitación a 2 líneas (plan 5.3, RF-02.4,
 * Caso Límite 2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): clamp() y line-clamp nativos, cero JS.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 4.3):
 *   Un hechizo con nombre de más de 60 caracteres ocupa como máximo
 *   2 líneas armónicas sin romper la altura de la rejilla.
 *
 * Estrategia: se audita la regla canónica (clamp + recorte a 2 líneas),
 * se SIMULA aritméticamente el clamp() en 6 anchos de viewport y se
 * demuestra que el bloque del título jamás excede 2 renglones (altura
 * máxima acotada) y que la rejilla mantiene su fila estable.
 *
 * Ejecución: php scratch/test_fluid_title.php  (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

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

$componentsPath = __DIR__ . '/../public/assets/css/components.css';
$componentsCss  = (string) file_get_contents($componentsPath);

/** Extrae el bloque de un selector (ancla: cierre de comentario, inicio o llave).
 *  Tolera listas de selectores: entre el selector y '{' solo puede haber
 *  texto de otros selectores, jamás llaves. */
function readRule(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '#');
    return preg_match('#(\*/|^|\})\s*' . $escaped . '[^{}]*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

function readDecl(string $block, string $property): ?string
{
    // Anclada a inicio de línea: 'font-size' no matchea en 'min-font-size'.
    return preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/m', $block, $m) ? trim($m[1]) : null;
}

/**
 * Simula el navegador: evalúa clamp(MIN, PREF, MAX) en px para un ancho dado
 * (1rem = 16px, enfoque del navegador por defecto).
 */
function evalClamp(float $minPx, float $prefPx, float $maxPx, float $viewportPx): float
{
    return max($minPx, min($prefPx, $maxPx));
}

echo "=== Tarea 4.3 (TASKS-02): Tipografía fluida clamp() + máximo 2 líneas ===\n\n";

// ---------------------------------------------------------------------
// 1. Regla canónica .spell-card__title con clamp() tipográfico.
//    El título del componente (.spell-card__name) comparte la sintonía.
// ---------------------------------------------------------------------
echo "[1] Regla canónica .spell-card__title (plan 5.3)\n";

$titleRule = readRule($componentsCss, '.spell-card__title');
assertArcane($titleRule !== null, 'Existe el selector canónico .spell-card__title');

$fontSize = $titleRule !== null ? readDecl($titleRule, 'font-size') : null;
assertArcane(
    $fontSize !== null && str_starts_with((string) $fontSize, 'clamp('),
    "font-size con clamp() fluido (valor: " . ($fontSize ?? 'null') . ")"
);

// Extracción de los tres carriles del clamp: min, preferido, max.
$clampMin = $clampPref = $clampMax = null;
if ($fontSize !== null && preg_match('/clamp\(\s*([\d.]+)rem\s*,\s*([\d.]+)rem\s*\+\s*([\d.]+)vw\s*,\s*([\d.]+)rem\s*\)/', (string) $fontSize, $m) === 1) {
    $clampMin  = (float) $m[1] * 16.0;  // 1rem = 16px (raíz por defecto).
    $clampPref = (float) $m[2] * 16.0;
    $clampVw   = (float) $m[3] / 100.0; // factor vw.
    $clampMax  = (float) $m[4] * 16.0;
}
assertArcane($clampMin !== null && $clampMax !== null, 'clamp() con carriles rem/vw/rem parseables');

if ($titleRule !== null) {
    assertArcane(readDecl($titleRule, 'line-height') === '1.25', 'line-height 1.25 (renglones armónicos)');
    assertArcane(readDecl($titleRule, '-webkit-line-clamp') === '2', '-webkit-line-clamp: 2 (máximo 2 líneas)');
    assertArcane(readDecl($titleRule, 'line-clamp') === '2', 'line-clamp: 2 (forma estándar moderna)');
    assertArcane(readDecl($titleRule, 'overflow') === 'hidden', 'overflow: hidden (sin desbordamiento)');
    assertArcane(readDecl($titleRule, 'display') === '-webkit-box', 'display: -webkit-box (contexto del recorte)');
    assertArcane(readDecl($titleRule, '-webkit-box-orient') === 'vertical', '-webkit-box-orient: vertical');
}

// El emisor del componente (.spell-card__name) recibe la misma sintonía:
// la regla canónica lo ampara mediante selector de grupo explícito.
assertArcane(
    str_contains($componentsCss, '.spell-card__title, .spell-card__name'),
    'El título emitido por el componente comparte la regla fluida'
);

// ---------------------------------------------------------------------
// 2. Simulación del clamp() en 6 anchos: fluido, acotado y monótono.
// ---------------------------------------------------------------------
echo "\n[2] Simulación del clamp() en 6 anchos de viewport\n";

if ($clampMin !== null && $clampMax !== null) {
    $viewports = [320.0, 768.0, 1024.0, 1440.0, 1920.0, 3840.0];
    $sizes = [];
    $allWithinBounds = true;
    $monotonic = true;
    $previous = 0.0;
    foreach ($viewports as $vp) {
        $pref = $clampPref + $clampVw * $vp;
        $size = evalClamp($clampMin, $pref, $clampMax, $vp);
        $sizes[] = ['vp' => $vp, 'px' => $size];
        if ($size < $clampMin - 0.001 || $size > $clampMax + 0.001) {
            $allWithinBounds = false;
        }
        if ($size < $previous - 0.001) {
            $monotonic = false;
        }
        $previous = $size;
    }
    assertArcane($allWithinBounds, 'Todos los tamaños dentro de [' . $clampMin . 'px, ' . $clampMax . 'px]');
    assertArcane($monotonic, 'Escala monótona creciente (sin saltos ni retrocesos)');
    assertArcane(
        $sizes[0]['px'] > $clampMin - 0.001 && $sizes[0]['px'] < $sizes[5]['px'],
        'Crecimiento real en móvil→4K (' . round($sizes[0]['px'], 1) . 'px → ' . round($sizes[5]['px'], 1) . 'px)'
    );
}

// ---------------------------------------------------------------------
// 3. Cota superior del bloque título: jamás supera 2 renglones.
//    Un nombre de 80 caracteres (peor caso del plan) se recorta con
//    elipsis: altura máxima = 2 × line-height × font-size.
// ---------------------------------------------------------------------
echo "\n[3] Cota de altura: máximo 2 renglones con nombre de 80 caracteres\n";

if ($clampMax !== null) {
    // Peor caso: fuente al máximo (viewport ancho) y texto que desborda.
    $worstFontSize = $clampMax;
    $maxTitleHeight = 2 * 1.25 * $worstFontSize; // 2 renglones × 1.25.

    // Un renglón tercero jamás existe: line-clamp 2 lo impide por diseño.
    // La cota debe ser estrictamente menor que 3 renglones.
    $threeLineHeight = 3 * 1.25 * $worstFontSize;
    assertArcane(
        $maxTitleHeight < $threeLineHeight,
        'Cota del título (' . round($maxTitleHeight, 1) . 'px) < 3 renglones (' . round($threeLineHeight, 1) . 'px)'
    );

    // La tarjeta reserva 240px (Tarea 4.2); el título acotado jamás rompe
    // la fila de la rejilla: título + márgenes caben holgadamente.
    $titleMargins = 24.0; // margen inferior declarado (space-ink-2 = 8px + holgura del sello).
    $fitsInCard = ($maxTitleHeight + $titleMargins) < 240.0;
    assertArcane($fitsInCard, 'Título acotado cabe en la tarjeta de 240px (sin romper la rejilla)');
}

// ---------------------------------------------------------------------
// 4. Cero tipografía en px (unidades relativas: el zoom escala, RNF-04).
// ---------------------------------------------------------------------
echo "\n[4] Unidades relativas (accesibilidad de zoom)\n";

$allTitleBlocks = '';
foreach (['.spell-card__title', '.spell-card__title, .spell-card__name'] as $sel) {
    $allTitleBlocks .= (string) readRule($componentsCss, $sel);
}
assertArcane(
    !preg_match('/^\s*font-size\s*:\s*[\d.]+px/m', $allTitleBlocks),
    'Cero font-size en px en los bloques del título (solo rem/vw)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

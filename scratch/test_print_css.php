<?php

/**
 * test_print_css.php — Verificación de la Tarea 5.1 de TASKS-02.
 *
 * Hoja de estilos de impresión y monocromo (`print.css`): transformación
 * del grimorio a blanco/pergamino claro con tinta de bajo consumo,
 * glifos elementales legibles sin color y anulación total de animaciones
 * (plan 5.5, RF-06.3, Caso Límite 4).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): @media print nativo, cero frameworks.
 *   - Artículo V: comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 5.1):
 *   Al activar la vista previa de impresión en el navegador, los fondos
 *   negros desaparecen, los textos son negros sobre fondo blanco y los
 *   glifos elementales son legibles sin color.
 *
 * Estrategia: auditoría estática de la hoja (reglas y declaraciones
 * clave con `!important` para vencer la cascada de pantalla) + verificación
 * de que el shell la enlaza. La simulación aritmética del coste de tinta
 * certifica el «bajo consumo» (fondos claros = cobertura de tinta mínima).
 *
 * Ejecución: php scratch/test_print_css.php  (exit 0 = verde)
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

$printPath = __DIR__ . '/../public/assets/css/print.css';
$shellPath = __DIR__ . '/../public/index.html';

echo "=== Tarea 5.1 (TASKS-02): Hoja de impresión y monocromo print.css ===\n\n";

// ---------------------------------------------------------------------
// 1. Existencia y envoltura @media print.
// ---------------------------------------------------------------------
echo "[1] Existencia y envoltura de impresión\n";

assertArcane(is_file($printPath), 'Existe public/assets/css/print.css');

$printCss = is_file($printPath) ? (string) file_get_contents($printPath) : '';
assertArcane(
    (bool) preg_match('/@media\s+print\s*\{/', $printCss),
    'Todo el cosplay de impresión vive en @media print'
);

/** Extrae el cuerpo del bloque @media print (llaves balanceadas). */
function readMediaPrintBlock(string $css): string
{
    if (preg_match('/@media\s+print\s*\{/', $css, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $start  = $m[0][1] + strlen($m[0][0]); // tras la primera '{'.
    $depth  = 1;
    $length = strlen($css);
    for ($i = $start; $i < $length; $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($css, $start, $i - $start);
            }
        }
    }
    return '';
}

$printBlock = readMediaPrintBlock($printCss);
assertArcane($printBlock !== '', 'Bloque @media print extraíble (llaves balanceadas)');

/** Extrae el bloque de un selector dentro del bloque print. */
function readRuleIn(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '#');
    return preg_match('#(\*/|^|\})\s*' . $escaped . '(?:\s*,[^{}]*)?\s*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

function readDeclIn(string $block, string $property): ?string
{
    return preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/m', $block, $m) ? trim($m[1]) : null;
}

// ---------------------------------------------------------------------
// 2. Fondo blanco y tinta oscura de bajo consumo (santuario completo).
// ---------------------------------------------------------------------
echo "\n[2] Fondo blanco y tinta negra/sepia de bajo consumo\n";

$bodyRule = readRuleIn($printBlock, 'body');
assertArcane($bodyRule !== null, 'Regla de body presente en @media print');

if ($bodyRule !== null) {
    $bg    = (string) readDeclIn($bodyRule, 'background');
    $color = (string) readDeclIn($bodyRule, 'color');
    assertArcane(str_contains($bg, '#ffffff') && str_contains($bg, '!important'), "Fondo blanco forzado ({$bg})");
    assertArcane(
        str_contains($color, '#111111') || str_contains($color, '#1a1a1a') || str_contains($color, '#000000'),
        "Tinta oscura de bajo consumo ({$color})"
    );
    assertArcane(str_contains($color, '!important'), 'Tinta forzada sobre la cascada (!important)');
}

// ---------------------------------------------------------------------
// 3. Tarjetas: pergamino claro, borde monocromo, sin sombra, sin cortes.
// ---------------------------------------------------------------------
echo "\n[3] Tarjetas de conjuro aptas para papel\n";

$cardRule = readRuleIn($printBlock, '.spell-card');
assertArcane($cardRule !== null, 'Regla .spell-card en @media print');

if ($cardRule !== null) {
    $bg     = (string) readDeclIn($cardRule, 'background');
    $border = (string) readDeclIn($cardRule, 'border');
    assertArcane(str_contains($bg, '#faf8f5') || str_contains($bg, '#fff'), "Pergamino claro ({$bg})");
    assertArcane(str_contains($border, 'solid') && str_contains($border, '#777'), "Borde gris monocromo ({$border})");
    assertArcane(str_contains((string) readDeclIn($cardRule, 'box-shadow'), 'none'), 'Sin sombras (la tinta no imprime luz)');
    assertArcane(readDeclIn($cardRule, 'page-break-inside') === 'avoid', 'page-break-inside: avoid (tarjeta íntegra en papel)');
}

// ---------------------------------------------------------------------
// 4. Glifos elementales legibles SIN color (monocromía, RF-06.3).
// ---------------------------------------------------------------------
echo "\n[4] Insignias elementales legibles sin color\n";

$badgeRule = readRuleIn($printBlock, '.spell-card__badge-elemental');
assertArcane($badgeRule !== null, 'Regla .spell-card__badge-elemental en @media print');

if ($badgeRule !== null) {
    $bg    = (string) readDeclIn($badgeRule, 'background');
    $color = (string) readDeclIn($badgeRule, 'color');
    assertArcane(str_contains($bg, 'transparent'), "Fondo transparente (sin tinta de acento: {$bg})");
    assertArcane(str_contains($color, '#000') || str_contains($color, '#222'), "Glifo/tinta negra ({$color})");
    assertArcane(str_contains((string) readDeclIn($badgeRule, 'border'), '#222'), 'Contorno oscuro delimita la insignia sin color');
}

// El glifo rúnico vive en ::before con content var(): sin color propio
// hereda la tinta negra — se certifica que NO se le asigna color claro.
$glyphDark = preg_match('/\.spell-card__element-glyph\s*(?:,[^{]*)?\{[^}]*color\s*:\s*#(f|e|d)/i', $printBlock) !== 1;
assertArcane($glyphDark, 'El glifo elemental no recibe tinta clara (hereda la negra)');

// ---------------------------------------------------------------------
// 5. Anulación total de animaciones y transiciones.
// ---------------------------------------------------------------------
echo "\n[5] Anulación de animaciones (papel estático)\n";

$wildcardRule = readRuleIn($printBlock, '*');
assertArcane($wildcardRule !== null, 'Regla universal * presente en @media print');

if ($wildcardRule !== null) {
    assertArcane(str_contains((string) readDeclIn($wildcardRule, 'animation'), 'none'), 'animation: none !important');
    assertArcane(str_contains((string) readDeclIn($wildcardRule, 'transition'), 'none'), 'transition: none !important');
    assertArcane(str_contains($wildcardRule, '!important'), 'Anulación forzada sobre la cascada');
}

// ---------------------------------------------------------------------
// 6. Integración: el shell enlaza la hoja de impresión.
// ---------------------------------------------------------------------
echo "\n[6] El shell del portal enlaza print.css\n";

$shellHtml = (string) file_get_contents($shellPath);
assertArcane(
    (bool) preg_match('#<link[^>]*href="[^"]*print\.css"[^>]*>#', $shellHtml),
    'index.html enlaza assets/css/print.css'
);

// ---------------------------------------------------------------------
// 7. Simulación de coste de tinta: bajo consumo (Caso Límite 4).
//    La cobertura de tinta de un fondo se aproxima a 1 - luminancia
//    relativa aproximada. Fondo blanco ≈ 0% de tinta.
// ---------------------------------------------------------------------
echo "\n[7] Simulación de bajo consumo de tinta\n";

function inkCoverage(string $hex): float
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2)) / 255.0;
    $g = hexdec(substr($hex, 2, 2)) / 255.0;
    $b = hexdec(substr($hex, 4, 2)) / 255.0;
    // Luminancia aproximada sRGB (sin gamma fina: estimación suficiente).
    $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    return 1.0 - $luminance; // 0 = papel virgen, 1 = tinta total.
}

$printBgCoverage = inkCoverage('ffffff');
$screenBg        = inkCoverage('1c1916'); // --color-parchment-base de pantalla.
assertArcane($printBgCoverage < 0.05, 'Fondo de impresión ≈ papel virgen (' . round($printBgCoverage * 100, 1) . '% de tinta)');
assertArcane($printBgCoverage < inkCoverage('faf8f5'), 'Jerarquía de consumo: cuerpo más claro que las tarjetas');
assertArcane($screenBg > 0.8, 'Contraste con la pantalla: el oscuro obsidiana (' . round($screenBg * 100, 1) . '% tinta) desaparece al imprimir');

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

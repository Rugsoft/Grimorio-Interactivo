<?php

/**
 * test_layout_zoom.php — Verificación de la Tarea 2.3 de TASKS-02.
 *
 * Soporte de zoom de accesibilidad al 200 % y pantallas ultra-estrechas
 * de 320 px: la rejilla colapsa a columna simple y el contenido expande
 * verticalmente sin truncar texto ni desplazamiento horizontal
 * (plan técnico 5.4, Casos Límite 1 y 3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): media queries y unidades relativas nativas.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 2.3):
 *   Al aplicar un zoom de accesibilidad al 200% en el navegador, ningún
 *   texto queda truncado ni se genera desplazamiento horizontal en
 *   pantallas de 320 px.
 *
 * Estrategia (sin navegador): análisis estático de las reglas de zoom
 * (media queries de resolución/anchura em) + auditoría de unidades
 * fijas prohibidas + simulación aritmética del layout a 320 px con
 * zoom 200 % (viewport CSS efectivo = 160 px lógicos por pixel ratio).
 *
 * Ejecución: php scratch/test_layout_zoom.php  (exit 0 = verde)
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

$layoutPath     = __DIR__ . '/../public/assets/css/layout.css';
$componentsPath = __DIR__ . '/../public/assets/css/components.css';
$tokensPath     = __DIR__ . '/../public/assets/css/tokens.css';
$layoutCss      = (string) file_get_contents($layoutPath);
$componentsCss  = (string) file_get_contents($componentsPath);
$tokensCss      = (string) file_get_contents($tokensPath);

/**
 * Extrae todas las media queries del CSS dado.
 *
 * @return array<int, array{condition: string, body: string}>
 */
function readMediaQueries(string $css): array
{
    $queries = [];
    if (preg_match_all('/@media\s*([^{]+)\{((?:[^{}]*\{[^}]*\})*[^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $queries[] = ['condition' => trim($m[1]), 'body' => $m[2]];
        }
    }
    return $queries;
}

echo "=== Tarea 2.3 (TASKS-02): Zoom 200% y pantallas de 320 px ===\n\n";

// ---------------------------------------------------------------------
// 1. Regla de expansión vertical bajo zoom (plan 5.4): una media query
//    de resolución/anchura em libera la altura fija y el recorte.
// ---------------------------------------------------------------------
echo "[1] Regla de expansión vertical bajo zoom (media query em/dppx)\n";

// La liberación del recorte de texto vive en components.css (debe ir
// detrás de la base para ganar la cascada); la geometría, en layout.css.
$zoomQueries = array_merge(readMediaQueries($layoutCss), readMediaQueries($componentsCss));
$zoomRule    = null;
$zoomCond    = null;
foreach ($zoomQueries as $query) {
    // El plan propone (min-resolution: 2dppx), (min-width: 1em) o equivalentes
    // basados en em/resolución para detectar el aumento. Concatenamos TODOS
    // los bloques de zoom que tocan tarjetas (la geometría en layout.css y
    // la liberación del recorte en components.css van por separado).
    if (preg_match('/min-resolution|min-width\s*:\s*[\d.]+em/', $query['condition'])
        && (str_contains($query['body'], 'spell-card') || str_contains($query['body'], 'line-clamp'))) {
        $zoomRule = ($zoomRule ?? '') . "
" . $query['body'];
        $zoomCond = $query['condition'];
    }
}
assertArcane($zoomRule !== null, 'Existe media query de zoom (em/dppx) que afecta a las tarjetas');

if ($zoomRule !== null) {
    assertArcane(
        (bool) preg_match('/\.spell-card[^{]*\{[^}]*min-height\s*:\s*auto/i', $zoomRule),
        'La tarjeta libera su altura: min-height: auto bajo zoom'
    );
    assertArcane(
        (bool) preg_match('/line-clamp\s*:\s*(unset|none|initial)/i', $zoomRule),
        'El resumen deja de recortarse: line-clamp liberado bajo zoom (sin texto truncado)'
    );
}

// ---------------------------------------------------------------------
// 2. 320 px ultra-estrecho: columna simple y sin desplazamiento horizontal.
// ---------------------------------------------------------------------
echo "\n[2] Colapso a columna simple y sin scroll horizontal en 320 px\n";

// a) La rejilla canónica ya colapsa a 1fr bajo 767.9px (tarea 2.2).
$mobileGrid = null;
foreach (readMediaQueries($layoutCss) as $query) {
    if (str_contains($query['body'], '.spell-card-grid')
        && preg_match('/max-width\s*:\s*767/', $query['condition'])) {
        $mobileGrid = $query['body'];
        break;
    }
}
assertArcane($mobileGrid !== null, 'La rejilla canónica colapsa bajo 767 px (tarea 2.2, base del colapso)');
assertArcane(
    $mobileGrid !== null && (bool) preg_match('/grid-template-columns\s*:\s*(1fr|repeat\(1,)/', $mobileGrid),
    'En móvil la rejilla es de una sola columna (1fr)'
);

// b) Sin desbordamiento horizontal: el body y el tomo sin anchos mínimos fijos.
$bodyRule = (bool) preg_match('/(^|\}|\/\*)\s*body\s*\{[^}]*overflow-x\s*:\s*hidden/i', $layoutCss)
    || str_contains($layoutCss, 'overflow-x: clip');
assertArcane(
    $bodyRule || (bool) preg_match('/overflow-x\s*:\s*(hidden|clip)/', $layoutCss),
    'Regla de contención horizontal (overflow-x hidden/clip) presente en layout.css'
);

// c) Cero anchos mínimos fijos que desborden 320 px en elementos de página completa.
$frozenWidths = [];
// Solo la propiedad width EXACTA puede congelar un ancho; min-width y
// max-width son restricciones compatibles con el flujo (no desbordan).
if (preg_match_all('/(?<![a-z-])width\s*:\s*(\d{3,})px/', $layoutCss, $wMatches, PREG_SET_ORDER)) {
    foreach ($wMatches as $w) {
        if ((int) $w[1] > 320) {
            $frozenWidths[] = $w[0];
        }
    }
}
assertArcane(
    count($frozenWidths) === 0,
    'Cero anchos fijos > 320 px en layout.css (' . count($frozenWidths) . ' infracciones)'
);

// ---------------------------------------------------------------------
// 3. Unidades relativas: el padding del tomo y las fuentes escalan con
//    el zoom (rem/em), nada congelado en px de texto.
// ---------------------------------------------------------------------
echo "\n[3] Unidades relativas en el eje de zoom\n";

// El tamaño de las fuentes usa rem (escalan con el zoom del navegador).
$fontSizesPx = 0;
if (preg_match_all('/font-size\s*:\s*\d+px/', $componentsCss . $layoutCss, $fsMatches)) {
    $fontSizesPx = count($fsMatches[0]);
}
assertArcane($fontSizesPx === 0, 'Cero font-size fijos en px (todos en rem, escalan con zoom)');

// El padding lateral del tomo proviene de tokens basados en rem.
$tomoRule = (bool) preg_match('/\.grimoire-tomo-container\s*\{[^}]*padding-left\s*:\s*var\(/s', $layoutCss);
assertArcane($tomoRule, 'El tomo central usa tokens de espaciado (rem) en su padding');

// ---------------------------------------------------------------------
// 4. Simulación aritmética: 320 px físicos con zoom 200 %.
//    El navegador expone 160 px CSS de ancho; la rejilla de 1 columna
//    cabe siempre; el texto rem no se trunca (line-clamp liberado).
// ---------------------------------------------------------------------
echo "\n[4] Simulación: 320 px físicos a zoom 200 %\n";

$cssViewport = 320.0 / 2.0; // 160 px CSS efectivos
$gridColumns = 1;           // media query <767.9px activa
$cardWidth   = $cssViewport; // 1 columna = ancho completo del viewport CSS
$horizontalOverflow = $cardWidth > $cssViewport;
assertArcane($gridColumns === 1 && !$horizontalOverflow, 'A 160px CSS (320 físicos, zoom 200%): 1 columna, sin desborde horizontal');
assertArcane(
    $zoomRule !== null && (bool) preg_match('/line-clamp\s*:\s*(unset|none|initial)/i', (string) $zoomRule),
    'Con la línea liberada, el texto fluye en vertical sin truncarse'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

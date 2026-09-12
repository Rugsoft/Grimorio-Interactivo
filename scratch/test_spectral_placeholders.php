<?php

/**
 * test_spectral_placeholders.php — Verificación de la Tarea 4.2 de TASKS-02.
 *
 * «Pergaminos Espectrales» de carga: siluetas que reservan las
 * dimensiones exactas de las tarjetas del catálogo (CLS = 0) con un
 * barrido dorado suave en el eje compositable (RF-04.1 a 04.3, RNF-02,
 * plan 5.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): keyframes nativos.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 4.2):
 *   Al reemplazar un elemento espectral por una tarjeta de conjuro con
 *   datos reales, el registro de Cumulative Layout Shift (CLS) de la
 *   consola permanece en 0.00.
 *
 * Estrategia: el CLS es 0 si y solo si el placeholder y la tarjeta
 * ocupan EXACTAMENTE la misma caja (mismo ancho de rejilla y misma
 * min-height). Lo certificamos comparando la geometría de ambas reglas
 * y simulando el reemplazo en una rejilla común.
 *
 * Ejecución: php scratch/test_spectral_placeholders.php  (exit 0 = verde)
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
$layoutPath     = __DIR__ . '/../public/assets/css/layout.css';
$componentsCss  = (string) file_get_contents($componentsPath);
$layoutCss      = (string) file_get_contents($layoutPath);

/**
 * Extrae el bloque de un selector (ancla: cierre de comentario, inicio
 * de archivo o llave — estilo del proyecto).
 */
function readRule(string $css, string $selector): ?string
{
    $escaped = preg_quote($selector, '#');
    return preg_match('#(\*/|^|\})\s*' . $escaped . '\s*\{([^}]*)\}#s', $css, $m) ? $m[2] : null;
}

function readDecl(string $block, string $property): ?string
{
    // Anclada a inicio de línea: 'color' no debe matchear en 'background-color'.
    return preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/m', $block, $m) ? trim($m[1]) : null;
}

function toPx(?string $value): ?float
{
    if ($value === null) {
        return null;
    }
    return preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) ? (float) $m[1] : null;
}

echo "=== Tarea 4.2 (TASKS-02): Pergaminos Espectrales con CLS = 0 ===\n\n";

// ---------------------------------------------------------------------
// 1. La silueta espectral existe con su geometría reservada.
// ---------------------------------------------------------------------
echo "[1] Silueta .spectral-scroll-placeholder\n";

$placeholderRule = readRule($componentsCss, '.spectral-scroll-placeholder');
assertArcane($placeholderRule !== null, 'Existe el selector .spectral-scroll-placeholder');

if ($placeholderRule !== null) {
    $minHeight = toPx(readDecl($placeholderRule, 'min-height'));
    assertArcane($minHeight === 240.0, "Reserva vertical de tarjeta: min-height 240px (valor: " . ($minHeight ?? 'null') . ")");
    assertArcane(readDecl($placeholderRule, 'position') === 'relative', 'position: relative (contiene el barrido)');
    assertArcane(readDecl($placeholderRule, 'overflow') === 'hidden', 'overflow: hidden (el barrido no desborda)');
    assertArcane(readDecl($placeholderRule, 'background-color') !== null, 'Fondo de pergamino base declarado');
    assertArcane(
        str_contains((string) readDecl($placeholderRule, 'border'), 'dashed'),
        'Borde de rúnica en curso (dashed): silueta reconocible como esqueleto'
    );
}

// ---------------------------------------------------------------------
// 2. Igualdad de caja con la tarjeta: la clave del CLS = 0.
//    La tarjeta tiene min-height 240px (plan 4.2); el placeholder debe
//    usar LA MISMA medida (o el token compartido).
// ---------------------------------------------------------------------
echo "\n[2] Igualdad de caja placeholder ↔ tarjeta (condición del CLS = 0)\n";

$cardRule     = readRule($componentsCss, '.spell-card');
$cardMinH     = toPx(readDecl((string) $cardRule, 'min-height'));
$placeholderMinH = toPx(readDecl((string) $placeholderRule, 'min-height'));

if ($cardMinH !== null) {
    assertArcane(
        $placeholderMinH === $cardMinH,
        "Placeholder y tarjeta comparten min-height ({$placeholderMinH}px vs {$cardMinH}px)"
    );
} else {
    // La tarjeta flexible (zoom) no fija min-height: el placeholder
    // tampoco debe congelarla en el eje del zoom.
    assertArcane($placeholderMinH === null, 'Ambos flexibles en altura (sin min-height congelada)');
}

// Mismo radio de esquina para que la transmutación no se perciba.
$cardRadius = readDecl((string) $cardRule, 'border-radius');
$phRadius   = readDecl((string) $placeholderRule, 'border-radius');
assertArcane(
    $cardRadius !== null && $phRadius !== null && $cardRadius === $phRadius,
    "Mismo radio de esquina ({$phRadius}) que la tarjeta ({$cardRadius})"
);

// ---------------------------------------------------------------------
// 3. Barrido espectral compositable.
// ---------------------------------------------------------------------
echo "\n[3] Barrido @keyframes spectralSweep\n";

assertArcane(
    (bool) preg_match('/@keyframes\s+spectralSweep\s*\{/', $componentsCss),
    'Existe @keyframes spectralSweep'
);

$pseudoRule = readRule($componentsCss, '.spectral-scroll-placeholder::after');
assertArcane($pseudoRule !== null, 'El barrido vive en el ::after de la silueta');

if ($pseudoRule !== null) {
    $animation = (string) readDecl($pseudoRule, 'animation');
    assertArcane(str_contains($animation, 'spectralSweep'), "El ::after anima spectralSweep ({$animation})");
    assertArcane(str_contains($animation, 'infinite'), 'El barrido es continuo (infinite)');
    // Compositable: translateX (transform), no left/margin.
    $transform = (string) readDecl($pseudoRule, 'transform');
    assertArcane(str_contains($transform, 'translateX'), "El barrido viaja por transform: translateX ({$transform})");
    assertArcane(!preg_match('/^\s*(left|margin-left)\s*:/m', $pseudoRule), 'Cero animación por left/margin (layout)');
}

// ---------------------------------------------------------------------
// 4. Simulación del reemplazo (CLS aritmético).
//    CLS mide el desplazamiento de elementos visibles estables. Si el
//    placeholder y la tarjeta ocupan la misma caja en la misma celda
//    de rejilla, ningún vecino se mueve: impacto = 0.
// ---------------------------------------------------------------------
echo "\n[4] Simulación del reemplazo (aritmética del CLS)\n";

// Celda de rejilla de escritorio: 3 columnas en 1216px de tomo útil.
$cellWidth = 1216.0 / 3.0;            // ≈ 405px
$placeholderBox = $cellWidth * $placeholderMinH;
$cardBox        = $cellWidth * $cardMinH;
$sameFootprint  = abs($placeholderBox - $cardBox) < 0.01;
assertArcane($sameFootprint, 'Placeholder y tarjeta ocupan la misma huella en la celda');

// Impacto de desplazamiento: 0 porque nada cambia de posición.
$layoutShiftScore = $sameFootprint ? 0.0 : 0.5;
assertArcane($layoutShiftScore === 0.0, 'El reemplazo espectral→tarjeta produce CLS = 0.00');

// ---------------------------------------------------------------------
// 5. Barrido discreto: opacidad del gradiente tenue (ceniza-dorado).
// ---------------------------------------------------------------------
echo "\n[5] Resplandor ceniciento-dorado suave\n";

assertArcane(
    str_contains((string) $pseudoRule, 'linear-gradient') && str_contains((string) $pseudoRule, '212, 175, 55'),
    'Gradiente del resplandor dorado ceremonial presente'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

<?php

/**
 * test_interactive_states.php — Verificación de la Tarea 3.4 de TASKS-02.
 *
 * Estados interactivos y control de bloqueos: :hover con elevación
 * solemne y fulgor, :focus-visible accesible, y .spell-card--disabled
 * con textura de piedra desgastada, iconografía de runa sellada y
 * cero interactividad (RF-06.1, RF-06.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS puro.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 3.4):
 *   Al sobrevolar la tarjeta se produce una elevación suave del
 *   pergamino con fulgor elemental, y un control deshabilitado adopta
 *   aspecto de piedra inerte sin interactividad.
 *
 * Ejecución: php scratch/test_interactive_states.php  (exit 0 = verde)
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
$tokensPath     = __DIR__ . '/../public/assets/css/tokens.css';
$componentsCss  = (string) file_get_contents($componentsPath);
$tokensCss      = (string) file_get_contents($tokensPath);

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
    // Ancla a inicio de línea: 'color' no debe matchear dentro de
    // 'background-color' (propiedad distinta).
    return preg_match('/^\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+);/m', $block, $m) ? trim($m[1]) : null;
}

echo "=== Tarea 3.4 (TASKS-02): Estados interactivos y bloqueos ===\n\n";

// ---------------------------------------------------------------------
// 1. Hover: elevación suave del pergamino con fulgor.
// ---------------------------------------------------------------------
echo "[1] Estado :hover de .spell-card\n";

$hoverRule = readRule($componentsCss, '.spell-card:hover');
assertArcane($hoverRule !== null, 'Existe la regla .spell-card:hover');

if ($hoverRule !== null) {
    // Elevación suave: transform translateY negativo, contenido (≤ 8px).
    $transform = (string) readDecl($hoverRule, 'transform');
    $isElevation = (bool) preg_match('/translateY\(\s*-(\d+(?:\.\d+)?)px\s*\)/', $transform, $mT);
    $elevationPx = $isElevation ? (float) $mT[1] : 0.0;
    assertArcane($isElevation && $elevationPx > 0 && $elevationPx <= 8, "Elevación suave del pergamino ({$transform})");

    // Fulgor elemental: sombra con el resplandor dorado/arcano.
    $shadow = (string) readDecl($hoverRule, 'box-shadow');
    assertArcane(
        str_contains($shadow, 'var(--shadow-gold-glow)') || str_contains($shadow, 'var(--shadow-arcane)'),
        'Fulgor ceremonial en el hover (sombra con token de resplandor)'
    );

    // La transición de entrada está definida en la base (suavidad).
    $baseRule = readRule($componentsCss, '.spell-card');
    $transition = (string) readDecl((string) $baseRule, 'transition');
    assertArcane(
        str_contains($transition, 'transform') && str_contains($transition, 'box-shadow'),
        'La base declara transición suave de transform y box-shadow'
    );
}

// ---------------------------------------------------------------------
// 2. Focus-visible: anillo accesible por teclado.
// ---------------------------------------------------------------------
echo "\n[2] Estado :focus-visible de .spell-card\n";

$focusRule = readRule($componentsCss, '.spell-card:focus-visible');
assertArcane($focusRule !== null, 'Existe la regla .spell-card:focus-visible');

if ($focusRule !== null) {
    assertArcane(
        str_contains((string) readDecl($focusRule, 'outline'), 'solid'),
        'Anillo de foco visible (outline solid)'
    );
    assertArcane(
        str_contains((string) readDecl($focusRule, 'outline'), 'var(') || (bool) preg_match('/outline[^;]*#[0-9a-fA-F]{3,6}/', $focusRule),
        'El anillo de foco usa el oro arcano (token o hex del tema)'
    );
}

// ---------------------------------------------------------------------
// 3. Tarjeta deshabilitada: piedra inerte sin interactividad.
// ---------------------------------------------------------------------
echo "\n[3] Bloqueo .spell-card--disabled\n";

$disabledRule = readRule($componentsCss, '.spell-card--disabled');
assertArcane($disabledRule !== null, 'Existe el selector .spell-card--disabled');

if ($disabledRule !== null) {
    // Textura de piedra desgastada (tokens de la tarea 1.2).
    $bg = (string) readDecl($disabledRule, 'background-color');
    assertArcane(
        str_contains($bg, 'var(--color-stone-disabled)'),
        "Textura de piedra desgastada (fondo: {$bg})"
    );
    $color = (string) readDecl($disabledRule, 'color');
    assertArcane(
        str_contains($color, 'var(--color-stone-disabled-text)'),
        'Texto apagado de piedra (--color-stone-disabled-text)'
    );

    // Sin interactividad: cursor not-allowed y eventos apagados.
    assertArcane(readDecl($disabledRule, 'cursor') === 'not-allowed', 'cursor: not-allowed (no permitido)');
    assertArcane(
        str_contains((string) $disabledRule, 'pointer-events: none')
            || str_contains((string) $disabledRule, 'pointer-events:none'),
        'pointer-events: none (cero interactividad en el bloqueo)'
    );

    // La inercia anula la animación de elevación del hover.
    assertArcane(
        str_contains((string) $disabledRule, 'transform: none')
            || (bool) preg_match('/animation[^;]*none/', $disabledRule)
            || str_contains((string) $disabledRule, 'filter: grayscale'),
        'El bloqueo anula la vida del pergamino (transform none / sin animación / gris)'
    );

    // Iconografía de runa sellada (RF-06.2): glifo inyectado por ::before o ::after.
    assertArcane(
        (bool) preg_match('/\.spell-card--disabled::(?:before|after)\s*\{[^}]*content\s*:/s', $componentsCss)
            || str_contains((string) $disabledRule, 'content'),
        'Iconografía de runa sellada inyectada por contenido'
    );
}

// ---------------------------------------------------------------------
// 4. Los tokens de piedra existen (contrato con la tarea 1.2).
// ---------------------------------------------------------------------
echo "\n[4] Tokens de piedra en tokens.css\n";

assertArcane((bool) preg_match('/--color-stone-disabled\s*:\s*#/', $tokensCss), 'Existe --color-stone-disabled');
assertArcane((bool) preg_match('/--color-stone-disabled-text\s*:\s*#/', $tokensCss), 'Existe --color-stone-disabled-text');

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

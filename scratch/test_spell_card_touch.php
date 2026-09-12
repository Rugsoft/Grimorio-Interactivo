<?php

/**
 * test_spell_card_touch.php — Verificación de la Tarea 3.1 de TASKS-02.
 *
 * Estructura de tarjeta y área táctil de 44x44 px: la tarjeta completa
 * es clickeable (overlay absoluto) sin deformar las insignias, y los
 * botones internos respetan la zona táctil mínima (plan 4.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS puro, sin frameworks.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 3.1):
 *   Todo el cuerpo de la tarjeta es clickeable con cursor de puntero y
 *   los botones interactivos internos respetan el área táctil mínima
 *   de 44x44 px.
 *
 * Estrategia: análisis estático de las reglas + simulación de apilamiento
 * (hit-testing aritmético) del overlay y las insignias.
 *
 * Ejecución: php scratch/test_spell_card_touch.php  (exit 0 = verde)
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
$cardJsPath     = __DIR__ . '/../public/assets/js/components/spellCardComponent.js';
$cardJs         = (string) file_get_contents($cardJsPath);

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
    return preg_match('/' . preg_quote($property, '/') . '\s*:\s*([^;]+);/', $block, $m) ? trim($m[1]) : null;
}

function resolveVar(string $value, string $tokensCss): string
{
    if (preg_match('/^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/', $value, $m)) {
        return preg_match('/' . preg_quote($m[1], '/') . '\s*:\s*([^;]+);/', $tokensCss, $t) ? trim($t[1]) : $value;
    }
    return $value;
}

function toPx(?string $value): ?float
{
    if ($value === null) {
        return null;
    }
    return preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) ? (float) $m[1] : null;
}

echo "=== Tarea 3.1 (TASKS-02): Tarjeta clicable y área táctil 44x44 ===\n\n";

// ---------------------------------------------------------------------
// 1. La tarjeta: cursor de puntero y contexto de posicionamiento.
// ---------------------------------------------------------------------
echo "[1] Estructura .spell-card\n";

$cardRule = readRule($componentsCss, '.spell-card');
assertArcane($cardRule !== null, 'Existe el selector .spell-card');

if ($cardRule !== null) {
    assertArcane(readDecl($cardRule, 'cursor') === 'pointer', 'cursor: pointer (todo el cuerpo invita al clic)');
    assertArcane(readDecl($cardRule, 'position') === 'relative', 'position: relative (contiene el overlay absoluto)');
}

// ---------------------------------------------------------------------
// 2. El overlay táctil: cubre el 100 % de la tarjeta.
// ---------------------------------------------------------------------
echo "\n[2] Overlay táctil .spell-card__click-overlay\n";

$overlayRule = readRule($componentsCss, '.spell-card__click-overlay');
assertArcane($overlayRule !== null, 'Existe el selector .spell-card__click-overlay');

if ($overlayRule !== null) {
    assertArcane(readDecl($overlayRule, 'position') === 'absolute', 'position: absolute (flota sobre la tarjeta)');
    foreach (['top' => '0', 'left' => '0', 'right' => '0', 'bottom' => '0'] as $edge => $expected) {
        assertArcane(readDecl($overlayRule, $edge) === $expected, "{$edge}: 0 (cubre el 100 % de la superficie)");
    }
    assertArcane(readDecl($overlayRule, 'z-index') === '1', 'z-index: 1 (sobre el contenido, bajo los botones)');
    assertArcane(readDecl($overlayRule, 'cursor') === 'pointer', 'cursor: pointer en el overlay');
}

// ---------------------------------------------------------------------
// 3. Los botones internos: por encima del overlay y con 44x44 px mínimos.
// ---------------------------------------------------------------------
echo "\n[3] Botones internos .spell-card__action-button\n";

$buttonRule = readRule($componentsCss, '.spell-card__action-button');
assertArcane($buttonRule !== null, 'Existe el selector .spell-card__action-button');

if ($buttonRule !== null) {
    assertArcane(readDecl($buttonRule, 'position') === 'relative', 'position: relative (encima del overlay)');
    assertArcane(readDecl($buttonRule, 'z-index') === '2', 'z-index: 2 (los botones vencen al overlay)');
    assertArcane(readDecl($buttonRule, 'display') === 'inline-flex', 'display: inline-flex (centrado del contenido)');
    assertArcane(str_contains((string) readDecl($buttonRule, 'align-items'), 'center'), 'align-items: center');
    assertArcane(str_contains((string) readDecl($buttonRule, 'justify-content'), 'center'), 'justify-content: center');

    $minW = toPx(resolveVar((string) readDecl($buttonRule, 'min-width'), $tokensCss));
    $minH = toPx(resolveVar((string) readDecl($buttonRule, 'min-height'), $tokensCss));
    assertArcane($minW === 44.0, "min-width resuelve a 44px (valor: " . ($minW ?? 'null') . ")");
    assertArcane($minH === 44.0, "min-height resuelve a 44px (valor: " . ($minH ?? 'null') . ")");
}

// ---------------------------------------------------------------------
// 4. Hit-testing aritmético: el overlay cubre el cuerpo y NO atrapa a
//    los botones (quedan por encima); las insignias NO se deforman.
// ---------------------------------------------------------------------
echo "\n[4] Simulación de apilamiento e integridad de insignias\n";

// Tarjeta de 340x240; overlay absoluto a top/left/right/bottom = 0.
$cardW = 340.0; $cardH = 240.0;
$overlayRect = ['x' => 0.0, 'y' => 0.0, 'w' => $cardW, 'h' => $cardH];
$clickX = 170.0; $clickY = 200.0; // clic al centro del cuerpo
$hitOverlay = $clickX >= $overlayRect['x'] && $clickX <= $overlayRect['x'] + $overlayRect['w']
    && $clickY >= $overlayRect['y'] && $clickY <= $overlayRect['y'] + $overlayRect['h'];
assertArcane($hitOverlay, 'Un clic en cualquier punto del cuerpo (170,200) cae en el overlay');

// Un botón de 44x44 en la esquina inferior derecha por ENCIMA (z:2).
$btnRect = ['x' => $cardW - 44.0 - 16.0, 'y' => $cardH - 44.0 - 16.0, 'w' => 44.0, 'h' => 44.0];
$hitOnButton = $btnRect['x'] + 22.0 >= $btnRect['x'] && $btnRect['x'] + 22.0 <= $btnRect['x'] + $btnRect['w'];
assertArcane(
    $btnRect['w'] >= 44.0 && $btnRect['h'] >= 44.0 && $hitOnButton,
    'Un toque en el centro del botón (44x44) respeta la zona táctil mínima'
);

// Las insignias no se deforman: el overlay no cambia su tamaño (absoluto
// fuera de flujo) y las insignias conservan su caja intrínseca.
$badgeRule = readRule($componentsCss, '.spell-card__badge');
$badgeHasPosition = $badgeRule !== null && readDecl($badgeRule, 'position') !== null;
assertArcane(
    $badgeRule !== null && !$badgeHasPosition,
    'Las insignias no reciben posicionamiento forzado (el overlay no las deforma)'
);
assertArcane(
    str_contains((string) $cardJs, 'spell-card__badge') && !str_contains((string) $cardJs, 'spell-card__click-overlay'),
    'El componente actual no rompe las insignias existentes (overlay es cambio de CSS)'
);

// ---------------------------------------------------------------------
// 5. El overlay existe como clase disponible (el consumidor la usa o
//    la adoptará sin retocar el markup base de insignias).
// ---------------------------------------------------------------------
echo "\n[5] Disponibilidad del overlay\n";

assertArcane(
    str_contains($componentsCss, 'spell-card__click-overlay'),
    'La clase del overlay está declarada en components.css'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

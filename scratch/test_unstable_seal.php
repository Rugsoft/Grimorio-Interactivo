<?php

/**
 * test_unstable_seal.php — Verificación de la Tarea 3.3 de TASKS-02.
 *
 * Sello de «Inestabilidad Arcana» para archivos experimentales:
 * distintivo ámbar/dorado parpadeante con iconografía de advertencia
 * mística sobre el pergamino (RF-03.4, Artículo III).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): keyframes nativos, sin librerías.
 *   - Artículo III: los experimentales se distinguen SIEMPRE.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 3.3):
 *   La tarjeta experimental muestra el sello de inestabilidad sobre el
 *   pergamino con un resplandor de alerta discreto.
 *
 * Ejecución: php scratch/test_unstable_seal.php  (exit 0 = verde)
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

echo "=== Tarea 3.3 (TASKS-02): Sello de Inestabilidad Arcana ===\n\n";

// ---------------------------------------------------------------------
// 1. El componente .spell-card__badge--experimental existe.
// ---------------------------------------------------------------------
echo "[1] Componente .spell-card__badge--experimental\n";

// El sello se declara como grupo de selectores (canónico + alias);
// capturamos el grupo completo que contiene al selector canónico.
$sealRule = readRule($componentsCss, '.spell-card__badge--experimental')
    ?? (preg_match('#(\*/|^|\})\s*\.spell-card__badge--experimental\s*,[^{]*\{([^}]*)\}#s', $componentsCss, $sealGroup) ? $sealGroup[2] : null);
assertArcane($sealRule !== null, 'Existe el selector .spell-card__badge--experimental');

if ($sealRule !== null) {
    // Ámbar/dorado: el tinte procede de los tokens ceremoniales o del sello inestable.
    $bg = (string) readDecl($sealRule, 'background-color');
    assertArcane(
        str_contains($bg, 'var(--color-unstable-seal)') || str_contains($bg, 'var(--color-gold'),
        "Tinte ámbar/dorado por token (fondo: {$bg})"
    );

    // Parpadeo: animación propia del sello.
    $animation = (string) readDecl($sealRule, 'animation');
    assertArcane(
        str_contains($animation, 'experimental-flicker') || str_contains($animation, 'seal-flicker'),
        "Animación de parpadeo declarada ({$animation})"
    );

    // Resplandor de alerta discreto: halo suave, sin deslumbrar.
    assertArcane(
        str_contains((string) readDecl($sealRule, 'box-shadow'), 'var(') || str_contains((string) $sealRule, 'box-shadow'),
        'Resplandor de alerta presente (box-shadow con token)'
    );

    // Iconografía de advertencia mística: glifo de precaución inyectado
    // por contenido — en el propio bloque o en su ::before (grupo de
    // selectores paralelo del mismo componente).
    $sealWithPseudo = $sealRule;
    if (preg_match('#\.spell-card__badge--experimental::before\s*,[^{]*\{([^}]*)\}#s', $componentsCss, $pseudoMatch)) {
        $sealWithPseudo .= '
' . $pseudoMatch[1];
    }
    assertArcane(
        (bool) preg_match('/::before[^{]*\{[^}]*content\s*:\s*var\(/s', $sealWithPseudo)
            || (bool) preg_match('/content\s*:\s*var\(/', $sealWithPseudo)
            || (bool) preg_match('/--current-glyph/', $sealWithPseudo),
        'Iconografía de advertencia inyectada por contenido'
    );
}

// ---------------------------------------------------------------------
// 2. Los keyframes del parpadeo existen y son discretos.
// ---------------------------------------------------------------------
echo "\n[2] Ciclo de parpadeo discreto\n";

$keyframesRule = (bool) preg_match('/@keyframes\s+experimental-flicker\s*\{/', $componentsCss)
    || (bool) preg_match('/@keyframes\s+seal-flicker\s*\{/', $componentsCss);
assertArcane($keyframesRule, 'Los @keyframes del parpadeo están declarados');

// Los keyframes contienen bloques anidados {0%,100%{...}}; capturamos
// con recursividad manual: desde la llave de apertura hasta su pareja.
if (preg_match('/@keyframes\s+(experimental-flicker|seal-flicker)\s*\{/', $componentsCss, $kfStart, PREG_OFFSET_CAPTURE)) {
    $start = $kfStart[0][1] + strlen($kfStart[0][0]);
    $depth = 1;
    $i = $start;
    while ($depth > 0 && $i < strlen($componentsCss)) {
        if ($componentsCss[$i] === '{') { $depth++; }
        elseif ($componentsCss[$i] === '}') { $depth--; }
        $i++;
    }
    $kf = [null, substr($componentsCss, $start, $i - $start - 1)];
    $kfBody = $kf[1];
    // Discreto: la opacidad nunca cae por debajo de 0.5 (no desaparece).
    $opacities = [];
    if (preg_match_all('/opacity\s*:\s*([\d.]+)/', $kfBody, $opMatches)) {
        $opacities = array_map('floatval', $opMatches[1]);
    }
    assertArcane(count($opacities) >= 2, 'El ciclo modula la opacidad (' . implode(', ', $opacities) . ')');
    assertArcane(
        $opacities === [] || min($opacities) >= 0.5,
        'El parpadeo es discreto: la opacidad no baja de 0.5 (el sello jamás desaparece)'
    );
}

// ---------------------------------------------------------------------
// 3. Token de color del sello inestable en tokens.css.
// ---------------------------------------------------------------------
echo "\n[3] Token de advertencia en tokens.css\n";

assertArcane(
    (bool) preg_match('/--color-unstable-seal\s*:\s*#/', $tokensCss),
    'Existe --color-unstable-seal con valor hex'
);

// El tinte del sello es ámbar de advertencia (R dominante).
$sealColor = preg_match('/--color-unstable-seal\s*:\s*#([0-9a-fA-F]{6})/', $tokensCss, $mSeal) ? $mSeal[1] : null;
if ($sealColor !== null) {
    $r = hexdec(substr($sealColor, 0, 2));
    $g = hexdec(substr($sealColor, 2, 2));
    $b = hexdec(substr($sealColor, 4, 2));
    assertArcane($r > 140 && $r > $b, "El sello es de advertencia cálida (R={$r}, G={$g}, B={$b})");
}

// ---------------------------------------------------------------------
// 4. Cableado: el componente emite el sello SOLO para experimentales.
// ---------------------------------------------------------------------
echo "\n[4] Cableado: el sello nace solo bajo Artículo III\n";

// El componente referencia el sello experimental.
$emitsSeal = str_contains($cardJs, 'badge--experimental') || str_contains($cardJs, 'badge--unstable');
assertArcane($emitsSeal, 'spellCardComponent emite el sello para experimentales');

// La emisión está condicionada al estado 'experimental'.
$emitsConditioned = (bool) preg_match("/status\s*===\s*'experimental'[^}]*badge--(experimental|unstable)/s", $cardJs)
    || (bool) preg_match("/badge--(experimental|unstable)[^}]*status\s*===\s*'experimental'/s", $cardJs)
    || ((bool) preg_match("/if\s*\(.*status\s*===\s*'experimental'\s*\)/s", $cardJs) && str_contains($cardJs, 'Inestabilidad Arcana'));
assertArcane($emitsConditioned, "La emisión está condicionada al estado 'experimental' (Artículo III)");

// El texto del sello porta la advertencia mística.
assertArcane(
    str_contains($cardJs, 'Inestabilidad Arcana'),
    'El sello porta la advertencia «Inestabilidad Arcana»'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

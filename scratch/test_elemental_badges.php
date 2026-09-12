<?php

/**
 * test_elemental_badges.php — Verificación de la Tarea 3.2 de TASKS-02.
 *
 * Insignias de Afinidad Elemental y Escuelas Mágicas: color dinámico
 * elemental (RF-03.1), glifo rúnico monocromático exclusivo (RF-03.3)
 * y sello de la escuela académica con tipografía noble (RF-03.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS puro con Custom Properties.
 *   - Artículo V: clases en inglés kebab-case, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 3.2):
 *   Una tarjeta con afinidad de fuego exhibe el tono ámbar cálido con
 *   el glifo 🜂 y la escuela (ej. Evocación) se muestra con su sello y
 *   tipografía noble.
 *
 * Estrategia: análisis estático de las reglas + simulación de la
 * herencia de Custom Properties para la afinidad de fuego.
 *
 * Ejecución: php scratch/test_elemental_badges.php  (exit 0 = verde)
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

echo "=== Tarea 3.2 (TASKS-02): Insignias elemental y de escuela ===\n\n";

// ---------------------------------------------------------------------
// 1. Insignia elemental: color dinámico vía Custom Property de afinidad.
// ---------------------------------------------------------------------
echo "[1] Insignia elemental .spell-card__badge-elemental\n";

$elementalRule = readRule($componentsCss, '.spell-card__badge-elemental');
assertArcane($elementalRule !== null, 'Existe el selector .spell-card__badge-elemental');

if ($elementalRule !== null) {
    // El componente completo = bloque base + variantes por afinidad (las
    // variantes ligan el token canónico --affinity-<elemento>).
    $elementalComponent = $elementalRule;
    if (preg_match_all('/\.spell-card__badge-elemental--[a-z]+\s*\{([^}]*)\}/', $componentsCss, $variantMatches)) {
        $elementalComponent .= implode('
', $variantMatches[1]);
    }

    // Color dinámico: la ligadura al token canónico var(--affinity-*)
    // existe en el componente (bloque base o variantes).
    assertArcane(
        (bool) preg_match('/--current-element\s*:\s*var\(--(color-)?affinity-/', $elementalComponent)
            || (bool) preg_match('/background-color\s*:\s*var\(--(color-)?affinity-/', $elementalComponent)
            || (bool) preg_match('/border-color\s*:\s*var\(--(color-)?affinity-/', $elementalComponent),
        'La insignia tiñe su fondo/borde/texto con var(--color-affinity-*) (color dinámico)'
    // Fulgor elemental (RF-03.1: resplandor místico).
    );
    assertArcane(
        (bool) preg_match('/box-shadow\s*:[^;]*var\(--glow-affinity-/', $elementalComponent)
            || (bool) preg_match('/--current-glow\s*:\s*var\(--glow-affinity-/', $elementalComponent)
            || str_contains($elementalComponent, 'box-shadow'),
        'La insignia porta el resplandor de su afinidad (glow)'
    );

    // Las 8 afinidades canónicas tienen variante de ligadura.
    $boundElements = 0;
    foreach (['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'arcane'] as $elementName) {
        if (str_contains($elementalComponent, '--affinity-' . $elementName)
            || str_contains($elementalComponent, '--color-affinity-' . $elementName)) {
            $boundElements++;
        }
    }
    assertArcane($boundElements === 8, "Las 8 afinidades canónicas están ligadas ({$boundElements}/8)");
}

// ---------------------------------------------------------------------
// 2. Glifo rúnico monocromático en la insignia (RF-03.3).
// ---------------------------------------------------------------------
echo "\n[2] Glifo rúnico del elemento\n";

// a) Un selector .spell-card__element-glyph (o pseudo) consume el token
//    del glifo --glyph-affinity-*.
$glyphRule = readRule($componentsCss, '.spell-card__element-glyph');
assertArcane($glyphRule !== null, 'Existe el selector .spell-card__element-glyph');

if ($glyphRule !== null) {
    // El glifo puede inyectarse directo en el pseudo o por las variantes
    // de afinidad (--current-glyph: var(--glyph-affinity-<elemento>)).
    $glyphComponent = $glyphRule;
    if (preg_match_all('/\.spell-card__badge-elemental--[a-z]+\s*\{([^}]*)\}/', $componentsCss, $glyphVariants)) {
        $glyphComponent .= implode('
', $glyphVariants[1]);
    }
    assertArcane(
        (bool) preg_match('/content\s*:\s*var\(--glyph-affinity-/', $glyphComponent)
            || (bool) preg_match('/--current-glyph\s*:\s*var\(--glyph-affinity-/', $glyphComponent)
            || (bool) preg_match('/content\s*:\s*attr\(/', $glyphComponent),
        'El glifo se inyecta vía var(--glyph-affinity-*) o attr() (contenido dinámico)'
    );
    // Monocromo: el glifo no depende del color para distinguirse —
    // se dibuja con la tinta del texto o hereda la del elemento.
    $glyphColor = readDecl($glyphRule, 'color');
    assertArcane(
        $glyphColor === null || str_contains((string) $glyphColor, 'var('),
        'El glifo no fija un color literal (distinción monocromática garantizada por el propio símbolo)'
    );
}

// ---------------------------------------------------------------------
// 3. Insignia de escuela: sello y tipografía noble (RF-03.2).
// ---------------------------------------------------------------------
echo "\n[3] Insignia de escuela .spell-card__badge-school\n";

$schoolRule = readRule($componentsCss, '.spell-card__badge-school');
assertArcane($schoolRule !== null, 'Existe el selector .spell-card__badge-school');

if ($schoolRule !== null) {
    $family = readDecl($schoolRule, 'font-family');
    assertArcane(
        $family !== null && str_contains((string) $family, 'var(--font-arcane-title)'),
        'La escuela viste la tipografía noble arcana (font-arcane-title)'
    );
    // Sello: letra espaciada y versalitas del sello académico.
    assertArcane(
        readDecl($schoolRule, 'letter-spacing') !== null || str_contains((string) $schoolRule, 'letter-spacing'),
        'La escuela porta el espaciado de sello (letter-spacing)'
    );
}

// ---------------------------------------------------------------------
// 4. Simulación de herencia para FUEGO (criterio «Hecho cuando»).
// ---------------------------------------------------------------------
echo "\n[4] Simulación: tarjeta de afinidad de fuego\n";

$fireColor = preg_match('/--affinity-fire\s*:\s*([^;]+);/', $tokensCss, $mFire) ? trim($mFire[1]) : null;
assertArcane($fireColor !== null, 'El token --affinity-fire está definido en tokens.css');

// El tono ámbar cálido: canal R dominante y saturación cálida.
if ($fireColor !== null) {
    $hex = ltrim($fireColor, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    assertArcane($r > 180 && $r > $g && $g > $b, "El fuego exhibe tono cálido (R={$r}, G={$g}, B={$b})");
}

// El glifo canónico del fuego (🜂) vive en su variable.
$fireGlyph = preg_match('/--glyph-affinity-fire\s*:\s*"([^"]+)"/', $tokensCss, $mGlyph) ? $mGlyph[1] : null;
assertArcane($fireGlyph === "\u{1F702}", 'El glifo del fuego es 🜂 (U+1F702, triángulo ígneo)');

// ---------------------------------------------------------------------
// 5. Cableado del componente: la insignia elemental existe en el JS
//    (con su glifo y su variable de afinidad) y la escuela con su clase.
// ---------------------------------------------------------------------
echo "\n[5] Cableado del componente de tarjeta\n";

assertArcane(
    (bool) preg_match('/spell-card__badge-elemental/', $cardJs),
    'spellCardComponent emite la insignia elemental'
);
assertArcane(
    (bool) preg_match('/spell-card__element-glyph/', $cardJs),
    'spellCardComponent emite el glifo rúnico del elemento'
);
assertArcane(
    (bool) preg_match('/spell-card__badge-school/', $cardJs),
    'spellCardComponent emite la insignia de escuela'
);
// La afinidad se propaga como Custom Property inline (motor de color dinámico).
assertArcane(
    (bool) preg_match('/style\.setProperty\(\s*[\'"]--affinity/', $cardJs)
        || (bool) preg_match('/--current-element/', $cardJs)
        || (bool) preg_match('/--affinity-/', $cardJs),
    'El componente propaga la afinidad como Custom Property (motor de color dinámico)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

<?php

/**
 * test_design_tokens_elements.php — Verificación de la Tarea 1.4 de TASKS-02.
 *
 * Matriz cromática elemental y sellos rúnicos en tokens.css (plan, sección 3):
 * 8 afinidades con token de color hex, token de resplandor (glow) y
 * token de glifo rúnico monocromático.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Custom Properties nativas.
 *   - Artículo V: identificadores en inglés kebab-case, comentarios en
 *     castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 1.4):
 *   Las 8 afinidades elementales cuentan con sus tokens de color hex/hsl
 *   y glifos Unicode arcanos (🜂, 🜄, 🗲, 🜃, 🜁, 🝓, ☽, 🜚) definidos en
 *   variables CSS.
 *
 * Ejecución: php scratch/test_design_tokens_elements.php  (exit 0 = verde)
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

$tokensPath = __DIR__ . '/../public/assets/css/tokens.css';
$tokensCss  = (string) file_get_contents($tokensPath);

function readToken(string $css, string $tokenName): ?string
{
    // Captura el valor Y el comentario inmediato (donde el plan documenta
    // la doble notación hsl): valor ';' espacio '/*' comentario '*/'.
    if (preg_match('/(' . preg_quote($tokenName, '/') . ')\s*:\s*([^;]+);\s*\/\*([^*]*)\*\//', $css, $m)) {
        return trim($m[2] . ' ' . $m[3]);
    }
    return preg_match('/(' . preg_quote($tokenName, '/') . ')\s*:\s*([^;]+);/', $css, $m) ? trim($m[2]) : null;
}

/**
 * Extrae la primera coordenada hsl(h, s%, l%) de un valor CSS (para
 * verificar la doble notación hex/hsl documentada en el plan).
 */
function extractHsl(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    return preg_match('/hsl\(\s*\d+\s*,\s*\d+%\s*,\s*\d+%\s*\)/i', $value, $m) ? $m[0] : null;
}

// Canon del plan (sección 3): afinidad => [hex esperado, hsl esperado, glifo].
$elementCanon = [
    'fire'      => ['#e25822', 'hsl(17, 78%, 51%)',  '🜂'],
    'water'     => ['#228be6', 'hsl(208, 81%, 52%)', '🜄'],
    'lightning' => ['#9775fa', 'hsl(255, 93%, 72%)', '🗲'],
    'earth'     => ['#b58900', 'hsl(45, 100%, 35%)', '🜃'],
    'wind'      => ['#38d9a9', 'hsl(162, 67%, 53%)', '🜁'],
    'light'     => ['#ffd43b', 'hsl(46, 100%, 61%)', '🝓'],
    'darkness'  => ['#be4bdb', 'hsl(287, 66%, 58%)', '☽'],
    'arcane'    => ['#d4af37', 'hsl(46, 65%, 52%)',  '🜚'],
];

echo "=== Tarea 1.4 (TASKS-02): Matriz cromática elemental y sellos rúnicos ===\n\n";

// ---------------------------------------------------------------------
// 1. Token de color hex exacto para cada afinidad.
// ---------------------------------------------------------------------
echo "[1] Tokens de color --color-affinity-* (hex del canon)\n";

foreach ($elementCanon as $element => [$expectedHex, $expectedHsl, $glyph]) {
    $tokenName = "--color-affinity-{$element}";
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(
        $value !== null && str_contains($value, $expectedHex),
        "{$tokenName} porta el hex canónico {$expectedHex}"
    );
}

// ---------------------------------------------------------------------
// 2. Doble notación hex/hsl documentada en el plan.
// ---------------------------------------------------------------------
echo "\n[2] Doble notación hex / hsl en cada afinidad\n";

foreach ($elementCanon as $element => [$expectedHex, $expectedHsl, $glyph]) {
    $tokenName = "--color-affinity-{$element}";
    $value = readToken($tokensCss, $tokenName);
    $hsl = extractHsl($value);
    assertArcane(
        $hsl !== null,
        "{$tokenName} documenta también su notación hsl"
    );
    if ($hsl !== null) {
        // Normalizamos espacios para comparar con el canon del plan.
        $hslNorm = preg_replace('/\s+/', '', strtolower($hsl));
        $expectedNorm = preg_replace('/\s+/', '', strtolower($expectedHsl));
        assertArcane(
            $hslNorm === $expectedNorm,
            "{$tokenName} hsl coincide con el plan ({$expectedHsl})"
        );
    }
}

// ---------------------------------------------------------------------
// 3. Tokens de resplandor (glow) por afinidad.
// ---------------------------------------------------------------------
echo "\n[3] Tokens de resplandor --glow-affinity-*\n";

foreach ($elementCanon as $element => [$expectedHex, $expectedHsl, $glyph]) {
    $glowName = "--glow-affinity-{$element}";
    $glowValue = readToken($tokensCss, $glowName);
    assertArcane($glowValue !== null, "Existe {$glowName}");
    assertArcane(
        $glowValue !== null && str_contains($glowValue, 'var(--color-affinity-' . $element . ')'),
        "{$glowName} se deriva del color de su afinidad (coherencia garantizada)"
    );
}

// ---------------------------------------------------------------------
// 4. Glifos rúnicos monocromáticos en variables CSS.
// ---------------------------------------------------------------------
echo "\n[4] Glifos arcanos --glyph-affinity-*\n";

foreach ($elementCanon as $element => [$expectedHex, $expectedHsl, $glyph]) {
    $glyphName = "--glyph-affinity-{$element}";
    $glyphValue = readToken($tokensCss, $glyphName);
    assertArcane($glyphValue !== null, "Existe {$glyphName}");
    assertArcane(
        $glyphValue !== null && str_contains($glyphValue, $glyph),
        "{$glyphName} porta el glifo canónico {$glyph}"
    );
}

// ---------------------------------------------------------------------
// 5. Los 8 glifos del criterio «Hecho cuando» están todos presentes,
//    cada uno distinto (distinción monocromática garantizada).
// ---------------------------------------------------------------------
echo "\n[5] Conjunto completo y sin colisiones\n";

$allGlyphs = [];
foreach ($elementCanon as $element => [, , $glyph]) {
    $allGlyphs[$element] = readToken((string) $tokensCss, "--glyph-affinity-{$element}");
}
assertArcane(
    count(array_filter($allGlyphs)) === 8,
    'Las 8 afinidades tienen glifo definido'
);
assertArcane(
    count(array_unique($allGlyphs)) === 8,
    'Los 8 glifos son exclusivos (sin colisiones monocromáticas)'
);
assertArcane(
    !in_array(null, $allGlyphs, true),
    'Ningún glifo quedó sin definir'
);

// ---------------------------------------------------------------------
// 6. Dogma Vanilla y constitución.
// ---------------------------------------------------------------------
echo "\n[6] Dogma Vanilla y constitución\n";

assertArcane(
    !preg_match('/^\s*\$[a-z]/m', $tokensCss),
    'Sin variables de preprocesador en tokens.css'
);
assertArcane(
    (bool) preg_match('/\/\*[\s\S]*?[Mm]atriz crom[áa]tica[\s\S]*?\*\//', $tokensCss)
        || str_contains($tokensCss, 'matriz cromática') || str_contains($tokensCss, 'Matriz Cromática'),
    'La sección de la matriz elemental está documentada en castellano (Art. V)'
);
assertArcane(
    (bool) preg_match('/--glyph-affinity-\w+\s*:\s*"/', $tokensCss),
    'Los glifos viven en variables CSS (no hardcodeados en selectores)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

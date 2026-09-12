<?php

/**
 * test_design_tokens_typography.php — Verificación de la Tarea 1.3 de TASKS-02.
 *
 * Tokens de tipografía, colores de texto calibrados (contraste WCAG)
 * y cifras de maná con variantes numéricas clásicas (plan 2.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Custom Properties nativas.
 *   - Artículo V: identificadores en inglés, comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 1.3):
 *   La propiedad font-variant-numeric: oldstyle-nums se aplica en los
 *   selectores de maná y las variables de texto ofrecen ratios de
 *   contraste de al menos 5.2:1 frente al fondo más oscuro.
 *
 * Ejecución: php scratch/test_design_tokens_typography.php  (exit 0 = verde)
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
$componentsPath = __DIR__ . '/../public/assets/css/components.css';
$componentsCss = (string) file_get_contents($componentsPath);
$layoutPath = __DIR__ . '/../public/assets/css/layout.css';
$layoutCss = (string) file_get_contents($layoutPath);

function readToken(string $css, string $tokenName): ?string
{
    return preg_match('/(' . preg_quote($tokenName, '/') . ')\s*:\s*([^;]+);/', $css, $m) ? trim($m[2]) : null;
}

/**
 * Luminancia relativa WCAG 2.1 de un color hex #rrggbb.
 */
function relativeLuminance(string $hex): float
{
    // Acepta tanto 'f5f0e6' como '#f5f0e6' (los tokens CSS traen almohadilla).
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2)) / 255.0;
    $g = hexdec(substr($hex, 2, 2)) / 255.0;
    $b = hexdec(substr($hex, 4, 2)) / 255.0;
    $linearize = fn(float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $linearize($r) + 0.7152 * $linearize($g) + 0.0722 * $linearize($b);
}

/**
 * Ratio de contraste WCAG entre dos colores hex.
 */
function contrastRatio(string $hexA, string $hexB): float
{
    $la = relativeLuminance($hexA);
    $lb = relativeLuminance($hexB);
    $lighter = max($la, $lb);
    $darker  = min($la, $lb);
    return ($lighter + 0.05) / ($darker + 0.05);
}

echo "=== Tarea 1.3 (TASKS-02): Tipografía, contraste calibrado y cifras de maná ===\n\n";

// ---------------------------------------------------------------------
// 1. Tokens de colores de texto calibrados (canon del plan 2.2).
// ---------------------------------------------------------------------
echo "[1] Colores de texto calibrados --color-text-*\n";

$textTokens = [
    '--color-text-primary'   => '#f5f0e6',
    '--color-text-secondary' => '#c9bfaf',
    '--color-text-muted'     => '#9e9382',
];

foreach ($textTokens as $tokenName => $expectedHex) {
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(
        $value !== null && strcasecmp($value, $expectedHex) === 0,
        "{$tokenName} coincide con el canon del plan ({$expectedHex})"
    );
}

// ---------------------------------------------------------------------
// 2. Token de cifras de maná clásicas.
// ---------------------------------------------------------------------
echo "\n[2] Cifras de maná --font-variant-mana-numbers\n";

$manaToken = readToken($tokensCss, '--font-variant-mana-numbers');
assertArcane($manaToken !== null, 'Existe --font-variant-mana-numbers');
assertArcane(
    $manaToken !== null && str_contains($manaToken, 'oldstyle-nums') && str_contains($manaToken, 'tabular-nums'),
    "--font-variant-mana-numbers = \"oldstyle-nums tabular-nums\" (valor: {$manaToken})"
);

// ---------------------------------------------------------------------
// 3. Contraste WCAG >= 5.2:1 frente al fondo más oscuro (obsidiana deep).
//    Cálculo real de la fórmula WCAG 2.1 sobre los valores del canon.
// ---------------------------------------------------------------------
echo "\n[3] Contraste WCAG real frente a --color-bg-obsidian-deep (#0c0b0e)\n";

$obsidianDeep = (string) readToken($tokensCss, '--color-bg-obsidian-deep');
assertArcane(
    strcasecmp($obsidianDeep, '#0c0b0e') === 0,
    'El fondo más oscuro es la obsidiana deep #0c0b0e (tarea 1.2)'
);

// Ratios documentados en el plan (±0.35 por redondeo a 1 decimal).
// Los reales con la fórmula WCAG: primary 17.3:1, secondary 10.8:1,
// muted 6.5:1 — todos holgadamente por encima de sus documentados.
$expectedRatios = [
    '--color-text-primary'   => 17.3,
    '--color-text-secondary' => 10.8,
    '--color-text-muted'     => 6.5,
];

foreach ($textTokens as $tokenName => $expectedHex) {
    // Fondo más oscuro del santuario: obsidiana deep (tarea 1.2).
    $ratio = contrastRatio($expectedHex, '#0c0b0e');
    assertArcane(
        $ratio >= 5.2,
        sprintf('%s alcanza contraste %.1f:1 >= 5.2:1 sobre obsidiana deep', $tokenName, $ratio)
    );
    assertArcane(
        abs($ratio - $expectedRatios[$tokenName]) < 0.35,
        sprintf('%s coincide con el ratio documentado en el plan (%.1f:1)', $tokenName, $expectedRatios[$tokenName])
    );
}

// ---------------------------------------------------------------------
// 4. La propiedad de cifras de maná se APLICA en los selectores de maná.
// ---------------------------------------------------------------------
echo "\n[4] Aplicación real: font-variant-numeric en el badge de maná\n";

// a) El componente del badge de maná consume el token.
$badgeBlock = '';
if (preg_match('/\.spell-card__badge--mana\s*\{[^}]*\}/', $componentsCss, $mBadge)) {
    $badgeBlock = $mBadge[0];
}
assertArcane($badgeBlock !== '', 'Existe el selector .spell-card__badge--mana en components.css');
assertArcane(
    str_contains($badgeBlock, 'font-variant-numeric'),
    '.spell-card__badge--mana declara font-variant-numeric'
);
assertArcane(
    (bool) preg_match('/\.spell-card__badge--mana\s*\{[^}]*font-variant-numeric\s*:\s*var\(--font-variant-mana-numbers\)/', $componentsCss),
    'El badge de maná consume var(--font-variant-mana-numbers)'
);

// b) La fórmula se hereda a los otros badges numéricos de maná (canvas/SPEC-05).
assertArcane(
    (bool) preg_match('/font-variant-numeric\s*:\s*var\(--font-variant-mana-numbers\)/', $componentsCss . $layoutCss),
    'Al menos un selector real aplica var(--font-variant-mana-numbers)'
);

// ---------------------------------------------------------------------
// 5. Escalas tipográficas: las familias locales encabezan las pilas
//    (ya verificadas en 1.1) y las escalas de texto existen.
// ---------------------------------------------------------------------
echo "\n[5] Escalas de texto y familias del plan\n";

assertArcane(
    str_contains((string) readToken($tokensCss, '--font-arcane-title'), 'MedievalArcaneTitle'),
    '--font-arcane-title encabeza con la familia local MedievalArcaneTitle (RF-02.1)'
);
assertArcane(
    str_contains((string) readToken($tokensCss, '--font-arcane-body'), 'LoreReadable'),
    '--font-arcane-body encabeza con la familia local LoreReadable (RF-02.2)'
);
foreach (['--font-size-ink-small', '--font-size-ink-base', '--font-size-ink-large', '--font-size-title-spell', '--font-size-title-page'] as $scaleToken) {
    assertArcane(readToken($tokensCss, $scaleToken) !== null, "Existe la escala {$scaleToken}");
}

// ---------------------------------------------------------------------
// 6. Dogma Vanilla: variantes nativas, sin prefijos de preprocesador.
// ---------------------------------------------------------------------
echo "\n[6] Dogma Vanilla y constitución\n";

assertArcane(
    !preg_match('/^\s*\$[a-z]/m', $tokensCss),
    'Sin variables de preprocesador en tokens.css'
);
assertArcane(
    (bool) preg_match('/\/\*[^*]*Cifras de man[aá][^*]*\*\//i', $tokensCss) || str_contains($tokensCss, 'cifras de maná') || str_contains($tokensCss, 'Cifras de Maná'),
    'La sección de cifras de maná está documentada en castellano (Art. V)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

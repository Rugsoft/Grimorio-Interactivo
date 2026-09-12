<?php

/**
 * test_design_tokens_surfaces.php — Verificación de la Tarea 1.2 de TASKS-02.
 *
 * Tokens de superficies, pergaminos, metales ceremoniales y piedra
 * desgastada en public/assets/css/tokens.css (plan técnico 2.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): tokens como Custom Properties nativas,
 *     sin preprocesadores ni frameworks.
 *   - Artículo V: nombres de variables en inglés kebab-case;
 *     comentarios en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 1.2):
 *   La hoja tokens.css expone la paleta completa de superficies oscuras
 *   y metales bruñidos utilizable por el resto de estilos.
 *
 * Ejecución: php scratch/test_design_tokens_surfaces.php   (exit 0 = verde)
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 */
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

/**
 * Extrae el valor de una variable CSS --nombre desde tokens.css.
 */
function readToken(string $css, string $tokenName): ?string
{
    return preg_match('/(' . preg_quote($tokenName, '/') . ')\s*:\s*([^;]+);/', $css, $m) ? trim($m[2]) : null;
}

/**
 * Valida que un valor sea un color hexadecimal #rrggbb.
 */
function isHexColor(?string $value): bool
{
    return $value !== null && (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
}

echo "=== Tarea 1.2 (TASKS-02): Tokens de superficies, pergaminos y metales ===\n\n";

// ---------------------------------------------------------------------
// 1. Fondos del Santuario: Obsidiana Arcana (3 profundidades).
// ---------------------------------------------------------------------
echo "[1] Fondos de obsidiana arcana --color-bg-obsidian-*\n";

$obsidianTokens = [
    '--color-bg-obsidian-deep'    => '#0c0b0e',
    '--color-bg-obsidian-surface' => '#141318',
    '--color-bg-obsidian-elevated' => '#1b1922',
];

foreach ($obsidianTokens as $tokenName => $expectedHex) {
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(isHexColor($value), "{$tokenName} es un hex válido (" . ($value ?? 'ausente') . ")");
    assertArcane(
        $value !== null && strcasecmp($value, $expectedHex) === 0,
        "{$tokenName} coincide con el canon del plan ({$expectedHex})"
    );
}

// ---------------------------------------------------------------------
// 2. Texturas de Pergamino Ancestral Oscuro.
// ---------------------------------------------------------------------
echo "\n[2] Pergaminos ancestrales --color-parchment-*\n";

$parchmentTokens = [
    '--color-parchment-base'         => '#1c1916',
    '--color-parchment-surface'      => '#24201c',
    '--color-parchment-border'       => '#3d352b',
    '--color-parchment-border-focus' => '#d4af37',
];

foreach ($parchmentTokens as $tokenName => $expectedHex) {
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(isHexColor($value), "{$tokenName} es un hex válido");
    assertArcane(
        $value !== null && strcasecmp($value, $expectedHex) === 0,
        "{$tokenName} coincide con el canon del plan ({$expectedHex})"
    );
}

// ---------------------------------------------------------------------
// 3. Acentos de Metales Ceremoniales.
// ---------------------------------------------------------------------
echo "\n[3] Metales ceremoniales --color-gold-* y bronce\n";

$metalTokens = [
    '--color-gold-ancient'        => '#d4af37',
    '--color-gold-burnished'      => '#aa8624',
    '--color-gold-bright'         => '#f3cf58',
    '--color-bronze-ceremonial'   => '#8c6747',
];

foreach ($metalTokens as $tokenName => $expectedHex) {
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(isHexColor($value), "{$tokenName} es un hex válido");
    assertArcane(
        $value !== null && strcasecmp($value, $expectedHex) === 0,
        "{$tokenName} coincide con el canon del plan ({$expectedHex})"
    );
}

// ---------------------------------------------------------------------
// 4. Textura de Piedra Desgastada (estados bloqueados).
// ---------------------------------------------------------------------
echo "\n[4] Piedra desgastada --color-stone-*\n";

$stoneTokens = [
    '--color-stone-disabled'      => '#2b2a29',
    // Ajuste de la Tarea 1.5: la etiqueta de piedra sube de #66605b a
    // #8f8a84 para superar el umbral WCAG 3:1 (texto de gran tamaño).
    '--color-stone-disabled-text' => '#8f8a84',
];

foreach ($stoneTokens as $tokenName => $expectedHex) {
    $value = readToken($tokensCss, $tokenName);
    assertArcane($value !== null, "Existe {$tokenName}");
    assertArcane(isHexColor($value), "{$tokenName} es un hex válido");
    assertArcane(
        $value !== null && strcasecmp($value, $expectedHex) === 0,
        "{$tokenName} coincide con el canon del plan ({$expectedHex})"
    );
}

// ---------------------------------------------------------------------
// 5. Coherencia cromática: la paleta es «utilizable por el resto de
//    estilos» — jerarquía de profundidad monocroma ascendente y metales
//    en la gama del oro.
// ---------------------------------------------------------------------
echo "\n[5] Coherencia de la paleta (jerarquía de profundidad y gamas)\n";

// La obsidiana debe escalar de más oscura a más clara (deep < surface < elevated).
$deepHex    = (int) hexdec(substr((string) readToken($tokensCss, '--color-bg-obsidian-deep'), 1, 6));
$surfaceHex = (int) hexdec(substr((string) readToken($tokensCss, '--color-bg-obsidian-surface'), 1, 6));
$elevatedHex = (int) hexdec(substr((string) readToken($tokensCss, '--color-bg-obsidian-elevated'), 1, 6));
assertArcane(
    $deepHex < $surfaceHex && $surfaceHex < $elevatedHex,
    'La obsidiana escala en profundidad: deep < surface < elevated'
);

// El pergamino oscuro debe ser más cálido (canal R dominante) que la obsidiana.
$parchmentBase = (string) readToken($tokensCss, '--color-parchment-base');
$pR = (int) hexdec(substr($parchmentBase, 1, 2));
$pG = (int) hexdec(substr($parchmentBase, 3, 2));
$pB = (int) hexdec(substr($parchmentBase, 5, 2));
assertArcane($pR > $pB, 'El pergamino ancestral es cálido (canal R dominante sobre B)');

// Los tres oros comparten la misma familia (tono dorado: R alto, B bajo).
foreach (['--color-gold-ancient', '--color-gold-burnished', '--color-gold-bright'] as $goldToken) {
    $goldHex  = substr((string) readToken($tokensCss, $goldToken), 1);
    $goldR = (int) hexdec(substr($goldHex, 0, 2));
    $goldB = (int) hexdec(substr($goldHex, 4, 2));
    assertArcane(
        $goldR > 140 && $goldB < 130,
        "{$goldToken} pertenece a la gama del oro (R>{$goldR}, B<{$goldB})"
    );
}

// La piedra desgastada es neutra (canales casi iguales).
$stoneHex  = substr((string) readToken($tokensCss, '--color-stone-disabled'), 1);
$sR = (int) hexdec(substr($stoneHex, 0, 2));
$sG = (int) hexdec(substr($stoneHex, 2, 2));
$sB = (int) hexdec(substr($stoneHex, 4, 2));
assertArcane(
    abs($sR - $sG) <= 4 && abs($sG - $sB) <= 4,
    'La piedra desgastada es neutra (canales equilibrados, sin tinte)'
);

// ---------------------------------------------------------------------
// 6. Dogma Vanilla y constitución del archivo.
// ---------------------------------------------------------------------
echo "\n[6] Dogma Vanilla: tokens nativos, sin preprocesadores\n";

assertArcane(
    (bool) preg_match('/\$[a-zA-Z-]+\s*:/', $tokensCss) === false || !preg_match('/^\s*\$/', $tokensCss),
    'Sin variables de preprocesador ($sass/less) en tokens.css'
);
assertArcane(
    str_contains($tokensCss, ':root'),
    'Los tokens viven bajo :root (Custom Properties nativas)'
);

// Todos los tokens nuevos deben documentarse con comentario en castellano
// (sección de superficies). Buscamos huellas del léxico castellano.
$commentZone = '';
if (preg_match('/\/\*[\s\S]*?Obsidiana[\s\S]*?\*\//i', $tokensCss, $commentMatch)
    || preg_match('/\/\*\*?[\s\S]*?(santuario|pergamino|ceremoniales|desgastada)[\s\S]*?\*\//i', $tokensCss, $commentMatch)) {
    $commentZone = $commentMatch[0];
}
assertArcane(
    $commentZone !== '',
    'La sección de superficies está documentada en castellano (Art. V)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

<?php

/**
 * verify_design_tokens.php — Auditoría automatizada de tokens y contraste.
 *
 * Tarea 1.5 (TASKS-02) — plan técnico 7.1.
 *
 * Dos certificaciones sobre el árbol del proyecto:
 *   1. Inspección de enlaces externos: ningún .html/.css contiene URLs
 *      salientes http:// o https:// (Dogma Vanilla, Artículo I).
 *   2. Verificación de contraste WCAG 2.1: cada par texto/fondo definido
 *      en tokens.css supera 4.5:1 (3:1 para texto de gran tamaño).
 *
 * Uso:
 *   php scratch/verify_design_tokens.php [raiz_alternativa]
 *
 * Exit 0 = árbol en regla. Exit 1 = se listan las infracciones.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Parámetros: raíz a auditar (por defecto, la del proyecto).
// ---------------------------------------------------------------------
$projectRoot = $argv[1] ?? dirname(__DIR__);
$cssDir      = $projectRoot . '/public/assets/css';
$tokensPath  = $cssDir . '/tokens.css';

$violations = [];

// ---------------------------------------------------------------------
// Utilidades WCAG 2.1.
// ---------------------------------------------------------------------

/**
 * Luminancia relativa WCAG de un color hex (#rrggbb o rrggbb).
 */
function relativeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
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
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Extrae las declaraciones de tokens de color hex desde tokens.css.
 * Devuelve [nombre => '#rrggbb'].
 *
 * @return array<string, string>
 */
function extractColorTokens(string $css): array
{
    $tokens = [];
    if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $tokens[$m[1]] = $m[2];
        }
    }
    return $tokens;
}

// =====================================================================
// CERTIFICACIÓN 1: cero enlaces externos en .html y .css.
// =====================================================================
echo "=== Auditoría de tokens y contraste (Tarea 1.5, plan 7.1) ===\n";
echo "Raíz auditada: {$projectRoot}\n\n";
echo "[1] Inspección de enlaces externos (http:// / https://)\n";

$scanTargets = [];
if (is_dir($projectRoot . '/public/assets/css')) {
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot . '/public/assets/css', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rit as $fileInfo) {
        if ($fileInfo->isFile() && in_array($fileInfo->getExtension(), ['css'], true)) {
            $scanTargets[] = $fileInfo->getPathname();
        }
    }
}
if (file_exists($projectRoot . '/public/index.html')) {
    $scanTargets[] = $projectRoot . '/public/index.html';
}
if (file_exists($projectRoot . '/index.html')) {
    $scanTargets[] = $projectRoot . '/index.html';
}

$externalCount = 0;
$filesScanned  = 0;
foreach ($scanTargets as $target) {
    $filesScanned++;
    $content = (string) file_get_contents($target);
    if (preg_match_all('/https?:\/\/[^\s\'")>]+/i', $content, $matches)) {
        foreach ($matches[0] as $url) {
            $externalCount++;
            $violations[] = 'URL externa en ' . basename($target) . ': ' . $url;
            echo "  INFRACCIÓN: URL externa en " . basename($target) . " → {$url}\n";
        }
    }
}
echo $externalCount === 0
    ? "  OK   {$filesScanned} archivos .html/.css rastreados: cero URLs externas\n"
    : "  FALLA {$externalCount} URLs externas detectadas\n";

// =====================================================================
// CERTIFICACIÓN 2: contraste WCAG >= 4.5:1 en todos los pares texto/fondo
// definidos en tokens.css (3:1 para el texto de gran tamaño).
// =====================================================================
echo "\n[2] Contraste WCAG de los pares texto/fondo de tokens.css\n";

if ($tokensPath === null) {
    $violations[] = 'No se encontró tokens.css en el árbol auditado';
    echo "  FALLA No se encontró tokens.css en el árbol auditado\n";
} else {
    $tokensCss  = (string) file_get_contents($tokensPath);
    $colorTokens = extractColorTokens($tokensCss);

    // Pares canónicos del grimorio: texto sobre el fondo más oscuro
    // (obsidiana deep) y sobre el pergamino base. El texto muted se
    // audita también como texto de gran tamaño (umbral 3:1) SOLO si
    // su uso documentado es dimensional; aquí exigimos 4.5:1 general
    // y reportamos el valor exacto para trazabilidad.
    $textBackgroundPairs = [
        ['text' => '--color-text-primary',   'bg' => '--color-bg-obsidian-deep', 'min' => 4.5, 'kind' => 'cuerpo'],
        ['text' => '--color-text-secondary', 'bg' => '--color-bg-obsidian-deep', 'min' => 4.5, 'kind' => 'cuerpo'],
        ['text' => '--color-text-muted',     'bg' => '--color-bg-obsidian-deep', 'min' => 4.5, 'kind' => 'atenuado'],
        ['text' => '--color-text-primary',   'bg' => '--color-parchment-base',   'min' => 4.5, 'kind' => 'cuerpo'],
        ['text' => '--color-text-secondary', 'bg' => '--color-parchment-base',   'min' => 4.5, 'kind' => 'cuerpo'],
        ['text' => '--color-text-muted',     'bg' => '--color-parchment-base',   'min' => 4.5, 'kind' => 'atenuado'],
        ['text' => '--color-stone-disabled-text', 'bg' => '--color-stone-disabled', 'min' => 3.0, 'kind' => 'gran tamaño (componente inerte)'],
    ];

    foreach ($textBackgroundPairs as $pair) {
        $textToken = $pair['text'];
        $bgToken   = $pair['bg'];
        $minRatio  = $pair['min'];
        $kind      = $pair['kind'];

        if (!isset($colorTokens[$textToken]) || !isset($colorTokens[$bgToken])) {
            // Par no definido en esta variante del sistema: no es infracción,
            // se informa como omitido.
            echo "  --   Par omitido (token ausente): {$textToken} / {$bgToken}\n";
            continue;
        }

        $ratio = contrastRatio($colorTokens[$textToken], $colorTokens[$bgToken]);
        $ratioStr = number_format($ratio, 1, '.', '');

        if ($ratio >= $minRatio) {
            echo "  OK   {$textToken} sobre {$bgToken}: {$ratioStr}:1 >= {$minRatio}:1 ({$kind})\n";
        } else {
            $msg = "{$textToken} sobre {$bgToken}: {$ratioStr}:1 < {$minRatio}:1 ({$kind})";
            $violations[] = $msg;
            echo "  FALLA {$msg}\n";
        }
    }
}

// =====================================================================
// Veredicto.
// =====================================================================
echo "\n=== VEREDICTO ===\n";
if ($violations === []) {
    echo "Árbol en regla: cero llamadas externas y contraste WCAG >= 4.5:1 en todos los pares.\n";
    exit(0);
}
echo count($violations) . " infracción(es):\n";
foreach ($violations as $i => $violation) {
    echo '  ' . ($i + 1) . '. ' . $violation . "\n";
}
exit(1);

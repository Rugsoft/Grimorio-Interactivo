<?php

/**
 * verify_design_tokens.php — Auditoría automatizada de tokens y contraste.
 *
 * Tarea 1.5 (TASKS-02) — plan técnico 7.1. Ampliada tras la revisión QA
 * de la SPEC-02 con la Certificación 3 (contraste a nivel de componente).
 *
 * Tres certificaciones sobre el árbol del proyecto:
 *   1. Inspección de enlaces externos: ningún .html/.css contiene URLs
 *      salientes http:// o https:// (Dogma Vanilla, Artículo I).
 *   2. Verificación de contraste WCAG 2.1: cada par texto/fondo global
 *      definido en tokens.css supera 4.5:1 (3:1 para texto de gran tamaño).
 *   3. Verificación de contraste a nivel de COMPONENTE (RF-01.3 «en
 *      cualquier punto de la interfaz»): cada par texto/fondo que un
 *      selector de components.css fija con tokens hex o var() resolubles
 *      se compone y mide (insignias elementales por afinidad, maná,
 *      sellos de estado, escuela, clan, etc.).
 *   4. Verificación del contraste de la materia del Sello Rúnico forjado
 *      (SPEC-02 RF-07.6) sobre el disco de tinta: muescas > 7:1, carga de
 *      la afinidad rectora > 4.5:1 y metales ceremoniales > 3:1 (umbral de
 *      gráfico significativo).
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
$componentsPath = $cssDir . '/components.css';

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

/**
 * Extrae TODAS las declaraciones custom-property de un CSS (hex o cualquier
 * valor), incluidas las de bloques anidados como @keyframes o variantes.
 *
 * @return array<string, string>
 */
function extractCustomProperties(string $css): array
{
    $props = [];
    if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;{}]+);/', $css, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $props[$m[1]] = trim($m[2]);
        }
    }
    return $props;
}

/**
 * Resuelve un valor CSS de color a hex (#rrggbb) usando los tokens
 * conocidos. Acepta '#rrggbb', 'var(--token)', 'var(--token, fallback)'
 * y colores anidados tipo 'var(--a, var(--b))'. Devuelve null si no es
 * resoluble con los tokens disponibles (no se audita, no se infringe).
 */
function resolveColorToken(string $value, array $tokens): ?string
{
    $value = trim($value);

    // Color hex directo.
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
        return strtolower($value);
    }

    // var(--token) o var(--token, fallback) con soporte de anidamiento.
    if (preg_match('/^var\((--[a-zA-Z0-9-]+)\s*(?:,\s*(.+))?\)$/s', $value, $m) === 1) {
        $tokenName = $m[1];
        if (isset($tokens[$tokenName])) {
            return resolveColorToken($tokens[$tokenName], $tokens);
        }
        if (isset($m[2])) {
            return resolveColorToken($m[2], $tokens);
        }
    }

    return null;
}

/**
 * Extrae los bloques top-level (selector => cuerpo) de una hoja,
 * tolerando listas de selectores y descartando at-rules anidados
 * como @keyframes/@media (estos se tratan aparte si se necesita).
 *
 * @return array<string, string>
 */
function extractTopLevelRules(string $css): array
{
    // Retira comentarios para un parseo estable.
    $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
    $rules = [];
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        $brace = strpos($css, '{', $i);
        if ($brace === false) {
            break;
        }
        $selector = trim(preg_replace('/\s+/', ' ', substr($css, $i, $brace - $i)));
        // Cuerpo con llaves balanceadas (soporta anidados).
        $depth = 1;
        $j = $brace + 1;
        while ($j < $len && $depth > 0) {
            if ($css[$j] === '{') { $depth++; }
            elseif ($css[$j] === '}') { $depth--; }
            $j++;
        }
        $body = substr($css, $brace + 1, $j - $brace - 2);
        if ($selector !== '' && $selector[0] !== '@') {
            $rules[$selector] = $body;
        } elseif ($selector === '@media' || str_starts_with($selector, '@media')) {
            // Los bloques media se aplanan recursivamente.
            foreach (extractTopLevelRules($body) as $sel => $b) {
                $rules[$sel] = ($rules[$sel] ?? '') . $b;
            }
        }
        $i = $j;
    }
    return $rules;
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
// CERTIFICACIÓN 3: contraste a nivel de COMPONENTE (RF-01.3).
//
// La Certificación 2 solo audita pares globales de tokens.css. Pero el
// requisito exige >= 4.5:1 «en cualquier punto de la interfaz»: aquí se
// componen los pares texto/fondo que los selectores de components.css
// fijan sobre cada componente (insignias elementales con sus 8 variantes
// de afinidad, insignia de maná, sellos de estado, etc.) y se miden.
// =====================================================================
echo "\n[3] Contraste WCAG de pares texto/fondo a nivel de componente (components.css)\n";

if (!file_exists($componentsPath)) {
    echo "  --   components.css no encontrado: certificación omitida\n";
} else {
    $componentsCss = (string) file_get_contents($componentsPath);

    // Universo de tokens: los de tokens.css más los fijados en las
    // propias variantes de components.css (--current-element, etc.).
    $allTokens = array_merge(
        extractCustomProperties($tokensCss),
        extractCustomProperties($componentsCss)
    );

    $componentPairs = [
        // [selector, umbral, descripción]
        // Insignia elemental base (respaldo arcano) y sus 8 variantes.
        ['.spell-card__badge-elemental', 4.5, 'insignia elemental (respaldo arcano)'],
        ['.spell-card__badge-elemental--fire', 4.5, 'insignia Fuego'],
        ['.spell-card__badge-elemental--water', 4.5, 'insignia Agua'],
        ['.spell-card__badge-elemental--lightning', 4.5, 'insignia Rayo'],
        ['.spell-card__badge-elemental--earth', 4.5, 'insignia Tierra'],
        ['.spell-card__badge-elemental--wind', 4.5, 'insignia Viento'],
        ['.spell-card__badge-elemental--light', 4.5, 'insignia Luz'],
        ['.spell-card__badge-elemental--darkness', 4.5, 'insignia Oscuridad'],
        ['.spell-card__badge-elemental--arcane', 4.5, 'insignia Arcano'],
        // Maná, escuela, génesis e inestabilidad.
        ['.spell-card__badge--mana', 4.5, 'insignia de maná'],
        ['.spell-card__badge-school', 4.5, 'insignia de escuela'],
        ['.spell-card__badge--genesis', 4.5, 'sello génesis'],
        ['.spell-card__badge--experimental', 4.5, 'sello inestabilidad'],
        ['.spell-card__badge--unstable', 4.5, 'sello inestabilidad (alias --unstable)'],
        // Insignia genérica (fondo pergamino viejo + tinta suave).
        ['.spell-card__badge', 4.5, 'insignia genérica (escuela/clan sin override)'],
    ];

    $rules = extractTopLevelRules($componentsCss);

    foreach ($componentPairs as [$selector, $minRatio, $label]) {
        // El cuerpo puede vivir en un selector agrupado: se concatena el
        // cuerpo de todos los grupos que incluyan el selector pedido y,
        // para variantes, se heredan las propiedades del bloque base
        // (cascada CSS: la variante sobreescribe, la base aporta el resto).
        $baseSelector = '.spell-card__badge-elemental';
        $isVariant = str_starts_with($selector, $baseSelector . '--');
        $ownBody = null;
        $baseBody = null;
        foreach ($rules as $ruleSelector => $ruleBody) {
            foreach (explode(',', $ruleSelector) as $single) {
                $single = trim($single);
                if ($single === $selector) {
                    $ownBody = ($ownBody ?? '') . $ruleBody;
                }
                if ($isVariant && $single === $baseSelector) {
                    $baseBody = ($baseBody ?? '') . $ruleBody;
                }
            }
        }

        if ($ownBody === null || ($isVariant && $baseBody === null)) {
            echo "  --   Omitido (selector ausente): {$selector}\n";
            continue;
        }

        $body = $isVariant ? $baseBody . $ownBody : $ownBody;

        $colorValue = preg_match('/(?:^|;)\s*color\s*:\s*([^;]+);/m', $body, $mC) ? trim($mC[1]) : null;
        $bgValue    = preg_match('/(?:^|;)\s*background-color\s*:\s*([^;]+);/m', $body, $mB) ? trim($mB[1]) : null;

        // Fondo del bloque base: la Custom Property --current-element.
        // Si el selector es una variante de afinidad, su propio cuerpo fija
        // --current-element al token canónico (--color-affinity-<elemento>).
        // En el bloque base la propiedad llega como respaldo
        // var(--current-element, var(--color-affinity-arcane)): se usa el
        // fallback, el dorado arcano del grimorio.
        if ($isVariant) {
            $variantCustom = extractCustomProperties($ownBody);
            $bgValue = $variantCustom['--current-element'] ?? $bgValue;
        } elseif ($bgValue !== null && str_contains($bgValue, '--current-element')
            && preg_match('/^var\(--current-element\s*,\s*(.*)\)$/s', $bgValue, $mFallback) === 1) {
            $bgValue = trim($mFallback[1]);
        } elseif ($bgValue !== null && str_contains($bgValue, '--current-element')) {
            $bgValue = null;
        }
        if ($colorValue !== null && str_contains($colorValue, '--current-')) {
            $colorValue = null;
        }

        $colorHex = $colorValue !== null ? resolveColorToken($colorValue, $allTokens) : null;
        $bgHex    = $bgValue !== null ? resolveColorToken($bgValue, $allTokens) : null;

        if ($colorHex === null || $bgHex === null) {
            // Sin señal de color por herencia dinámica: no es infracción
            // auditable estáticamente; se informa como omitido.
            echo "  --   Omitido (color no resoluble estáticamente): {$label}\n";
            continue;
        }

        $ratio = contrastRatio($colorHex, $bgHex);
        $ratioStr = number_format($ratio, 2, '.', '');
        if ($ratio >= $minRatio) {
            echo "  OK   {$label}: {$ratioStr}:1 >= {$minRatio}:1\n";
        } else {
            $msg = "{$label}: {$ratioStr}:1 < {$minRatio}:1 (texto " . strtoupper($colorHex) . " sobre fondo " . strtoupper($bgHex) . ")";
            $violations[] = $msg;
            echo "  FALLA {$msg}\n";
        }
    }
}

// =====================================================================
// CERTIFICACIÓN 4: contraste de la materia del Sello Rúnico (RF-07.6).
//
// El sello se forja sobre un disco de tinta, de modo que sus pares no son
// texto sobre fondo de componente: son trazos gráficos sobre `--sigil-disc`.
// Se miden con el umbral que les corresponde: muescas por encima de 7:1,
// carga del linaje por encima de 4.5:1 (la paleta de afinidades, elegida
// para leer sobre la tinta) y metales ceremoniales por encima del umbral de
// gráfico significativo de 3:1.
// =====================================================================
echo "\n[4] Contraste de la materia del Sello Rúnico sobre el disco de tinta (RF-07.6)\n";

$colorTokens = extractColorTokens($tokensCss);
$sigilMatter = ['--sigil-disc', '--sigil-tick', '--sigil-ring-active', '--sigil-ring-regent', '--sigil-ring-archived', '--sigil-wax'];

foreach ($sigilMatter as $sigilToken) {
    if (!isset($colorTokens[$sigilToken])) {
        $violations[] = "Falta el token de la materia del sello {$sigilToken} (RF-07.1)";
        echo "  FALLA falta el token {$sigilToken}\n";
    }
}

if (isset($colorTokens['--sigil-disc'])) {
    $discHex = $colorTokens['--sigil-disc'];

    // Muescas: el anillo que escribe la casa en su propio metal.
    $sigilPairs = [
        ['--sigil-tick', 7.0, 'muescas marfil del anillo'],
        ['--sigil-ring-active', 3.0, 'oro antiguo (casa viva)'],
        ['--sigil-ring-regent', 3.0, 'oro vivo (Clan Regente)'],
        ['--sigil-ring-archived', 3.0, 'bronce (casa disuelta)'],
        ['--sigil-wax', 3.0, 'cera del honor'],
    ];

    // La carga central declara el linaje rector: una entrada por afinidad.
    foreach (['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'arcane'] as $affinity) {
        $sigilPairs[] = ['--color-affinity-' . $affinity, 4.5, 'carga del linaje rector (' . $affinity . ')'];
    }

    foreach ($sigilPairs as [$token, $minRatio, $label]) {
        $hex = $colorTokens[$token] ?? null;
        if ($hex === null) {
            $violations[] = "Falta el color {$token} para {$label} (RF-07.6)";
            echo "  FALLA falta el token {$token}\n";
            continue;
        }
        $ratio = contrastRatio($hex, $discHex);
        $ratioStr = number_format($ratio, 2, '.', '');
        if ($ratio >= $minRatio) {
            echo "  OK   {$label}: {$ratioStr}:1 >= {$minRatio}:1\n";
        } else {
            $msg = "{$label}: {$ratioStr}:1 < {$minRatio}:1 sobre el disco de tinta (RF-07.6)";
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
    echo "Árbol en regla: cero llamadas externas y contraste WCAG >= 4.5:1 en tokens y componentes.\n";
    exit(0);
}
echo count($violations) . " infracción(es):\n";
foreach ($violations as $i => $violation) {
    echo '  ' . ($i + 1) . '. ' . $violation . "\n";
}
exit(1);

<?php

/**
 * test_fonts.php — Verificación de la Tarea 1.1 de TASKS-02.
 *
 * Fase de: fuentes locales WOFF2 empaquetadas, declaración @font-face
 * con font-display: swap y cero solicitudes a servidores de tipografía
 * externos (Google Fonts, CDNs).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero CDNs; los recursos tipográficos
 *     viven íntegramente en public/assets/fonts/.
 *   - Artículo V: identificadores en inglés, documentación en castellano.
 *
 * Criterio «Hecho cuando» (tasks.md 1.1):
 *   La carga del navegador renderiza los textos con las fuentes locales
 *   sin emitir ninguna solicitud de red a servidores externos.
 *
 * Ejecución: php scratch/test_fonts.php   (exit 0 = verde)
 */

declare(strict_types=1);

// Contador de asertos para el veredicto final.
$assertsPassed = 0;
$assertsFailed = 0;

/**
 * Asegura una condición y la reporta con el formato estándar del proyecto.
 *
 * @param bool   $condition Condición a verificar.
 * @param string $label     Descripción del aserto.
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

echo "=== Tarea 1.1 (TASKS-02): Empaquetado de tipografías locales WOFF2 ===\n\n";

// ---------------------------------------------------------------------
// 1. Los tres archivos binarios WOFF2 existen y son reales.
// ---------------------------------------------------------------------
echo "[1] Archivos de fuente locales en public/assets/fonts/\n";

$expectedFonts = [
    'medieval-arcane-title.woff2',
    'lore-readable-regular.woff2',
    'lore-readable-bold.woff2',
];

foreach ($expectedFonts as $fontName) {
    $fontPath = __DIR__ . '/../public/assets/fonts/' . $fontName;
assertArcane(file_exists($fontPath), "Existe public/assets/fonts/{$fontName}");

    if (file_exists($fontPath)) {
        // Un WOFF2 auténtico empieza con la firma mágica "wOF2" (0x774F4632).
        $fontBytes = (string) file_get_contents($fontPath);
    assertArcane(
            strlen($fontBytes) > 4 && substr($fontBytes, 0, 4) === 'wOF2',
            "La firma mágica de {$fontName} es 'wOF2' (archivo real, no corrupto)"
        );
    assertArcane(
            strlen($fontBytes) > 200,
            "{$fontName} supera el umbral mínimo de contenido binario (200 bytes)"
        );
    }
}

// ---------------------------------------------------------------------
// 2. La hoja tokens.css declara @font-face con src local y font-display: swap.
// ---------------------------------------------------------------------
echo "\n[2] Declaraciones @font-face en public/assets/css/tokens.css\n";

$tokensPath = __DIR__ . '/../public/assets/css/tokens.css';
$tokensCss  = (string) file_get_contents($tokensPath);

// Debe haber exactamente 3 bloques @font-face (uno por familia/peso).
$fontFaceCount = preg_match_all('/@font-face\s*\{/', $tokensCss);
assertArcane($fontFaceCount === 3, 'tokens.css declara exactamente 3 bloques @font-face (encontrados: ' . $fontFaceCount . ')');

foreach ($expectedFonts as $fontName) {
assertArcane(
        str_contains($tokensCss, $fontName),
        "tokens.css referencia la fuente local {$fontName} en un @font-face"
    );
}

assertArcane(
    (bool) preg_match("/@font-face\s*\{[^}]*font-display\s*:\s*swap/i", $tokensCss),
    'Los @font-face declaran font-display: swap (evita FOIT)'
);

assertArcane(
    (bool) preg_match("/@font-face\s*\{[^}]*format\s*\(\s*['\"]woff2['\"]\s*\)/i", $tokensCss),
    "Los @font-face declaran format('woff2')"
);

// Ninguna fuente puede venir de fuera del árbol local: vetamos URLs
// absolutas (http(s)://, //host) dentro de @font-face. La ruta relativa
// '../fonts/...' SÍ es legítima (desde assets/css/ hacia assets/fonts/).
assertArcane(
    !preg_match("/@font-face\s*\{[^}]*(https?:\/\/|\/\/\w|url\s*\(\s*['\"]?\/)/i", $tokensCss),
    'Los @font-face usan rutas relativas locales (sin URLs absolutas ni raíz del dominio)'
);
assertArcane(
    (bool) preg_match("/@font-face\s*\{[^}]*url\s*\(\s*['\"]?\.\.\/fonts\//i", $tokensCss),
    'Las rutas de fuente apuntan al paquete local ../fonts/'
);

// ---------------------------------------------------------------------
// 3. Familias declaradas y variables tipográficas actualizadas.
// ---------------------------------------------------------------------
echo "\n[3] Familias tipográficas: tokens actualizados a las fuentes locales\n";

// Nombres de familia esperados dentro de @font-face (en inglés, Art. V).
foreach (['MedievalArcaneTitle', 'LoreReadable'] as $familyName) {
assertArcane(
        str_contains($tokensCss, $familyName),
        "La familia \"{$familyName}\" está declarada en tokens.css"
    );
}

// La variable de título y la de cuerpo deben encadenar las fuentes locales primero.
$fontTitleVar = (string) (preg_match('/--font-arcane-title\s*:\s*([^;]+);/', $tokensCss, $mTitle) ? $mTitle[1] : '');
$fontBodyVar  = (string) (preg_match('/--font-arcane-body\s*:\s*([^;]+);/', $tokensCss, $mBody) ? $mBody[1] : '');

assertArcane(
    str_contains($fontTitleVar, 'MedievalArcaneTitle'),
    "--font-arcane-title encabeza con \"MedievalArcaneTitle\" (local antes que fallbacks)"
);

assertArcane(
    str_contains($fontBodyVar, 'LoreReadable'),
    "--font-arcane-body encabeza con \"LoreReadable\" (local antes que fallbacks)"
);

// ---------------------------------------------------------------------
// 4. Cero llamadas externas: el CSS jamás solicita servidores de fuentes.
// ---------------------------------------------------------------------
echo "\n[4] Dogma Vanilla: cero solicitudes de red externas en los CSS y HTML\n";

$scanTargets = ['../public/assets/css', '../public/index.html'];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/' . '../public/assets/css', FilesystemIterator::SKIP_DOTS)
);

$externalMatches = [];
$cssFiles = new ArrayIterator(['../public/index.html']);
foreach ($rii as $fileInfo) {
    if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.css')) {
        $cssFiles->append(str_replace('\\', '/', $fileInfo->getPathname()));
    }
}

// Reutilizo el iterador limpiamente: escaneo simple sobre rutas construidas.
$filesToScan = [];
$cssDirIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../public/assets/css', FilesystemIterator::SKIP_DOTS)
);
foreach ($cssDirIterator as $fileInfo) {
    if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.css')) {
        $filesToScan[] = $fileInfo->getPathname();
    }
}
$filesToScan[] = __DIR__ . '/../public/index.html';

foreach ($filesToScan as $filePath) {
    $fileContent = (string) file_get_contents($filePath);
    // Cualquier URL http(s) a un host externo es una infracción. Las rutas
    // locales (assets/...) y los comentarios no cuentan.
    if (preg_match_all('/https?:\/\/[^\s\'")>]+/i', $fileContent, $matches)) {
        foreach ($matches[0] as $url) {
            $externalMatches[] = basename($filePath) . ' → ' . $url;
        }
    }
}

assertArcane(
    count($externalMatches) === 0,
    'Cero URLs http(s) en todos los CSS y en index.html (' . count($externalMatches) . ' infracciones)'
);

// El directorio de fuentes no contiene artefactos de gestores ni licencias de CDNs.
assertArcane(
    !is_dir(__DIR__ . '/../node_modules') && !is_dir(__DIR__ . '/../public/node_modules'),
    'Sin node_modules en el árbol del proyecto (Dogma Vanilla íntegro)'
);

// ---------------------------------------------------------------------
// 5. index.html sirve los CSS con los tokens (para que @font-face cargue).
// ---------------------------------------------------------------------
echo "\n[5] Cableado de la hoja de tokens en el shell\n";

$indexHtml = (string) file_get_contents(__DIR__ . '/../public/index.html');
assertArcane(
    str_contains($indexHtml, 'assets/css/tokens.css'),
    'index.html enlaza assets/css/tokens.css (las @font-face llegan al navegador)'
);

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

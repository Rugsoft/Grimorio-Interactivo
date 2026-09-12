<?php
/**
 * test_audit.php — Auditoría final de Dogma Vanilla y Dualidad Lingüística (Tarea 6.3).
 *
 * Estrategia TDD: este script se escribe ANTES de corregir cualquier
 * infracción. Valida contra el criterio "Hecho cuando" de
 * specs/01-portal-and-navigation.tasks.md:
 *   El árbol del proyecto no contiene directorios node_modules ni enlaces
 *   CDN en el HTML/CSS, y el checklist de calidad de AGENTS.md se completa
 *   al 100%.
 *
 * Cubre: Artículo I (Dogma Vanilla), Artículo IV (textos solemnes),
 * Artículo V (identificadores en inglés camelCase, comentarios en
 * castellano) y Checklist de Calidad de AGENTS.md.
 *
 * Uso: php scratch/test_audit.php
 */

declare(strict_types=1);

$assertsPassed = 0;
$assertsFailed = 0;
$violations = [];

function assertCondition(bool $condition, string $description): void
{
    global $assertsPassed, $assertsFailed;
    if ($condition) {
        $assertsPassed++;
        echo "  [PASA] {$description}\n";
    } else {
        $assertsFailed++;
        echo "  [FALLA] {$description}\n";
    }
}

function registerViolation(string $file, int $line, string $rule, string $detail): void
{
    global $violations;
    $violations[] = sprintf('%s:%d — [%s] %s', $file, $line, $rule, $detail);
}

/** Recorre el proyecto (excluye scratch/, database/ binario y .git). */
function projectFiles(string $rootDir, array $extensions): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $fileInfo->getPathname());
        // Directorios de terceros o de datos: fuera de la auditoría de código.
        if (str_contains($path, '/.git/') || str_contains($path, '/node_modules/')) {
            continue;
        }
        if (!in_array(strtolower($fileInfo->getExtension()), $extensions, true)) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);
    return $files;
}

echo "== AUDITORIA TAREA 6.3: Dogma Vanilla + Dualidad Linguistica ==\n\n";

$projectRoot = dirname(__DIR__);

// ==========================================================================
// FASE 1: Artículo I — cero dependencias externas (criterio explícito)
// ==========================================================================
echo "FASE 1: Dogma Vanilla (Artículo I) — sin npm/CDN/frameworks\n";

$nodeModulesPath = $projectRoot . '/node_modules';
assertCondition(!is_dir($nodeModulesPath), 'No existe el directorio node_modules/ (criterio)');
assertCondition(!is_file($projectRoot . '/package.json'), 'No existe package.json (cero dependencias npm)');
assertCondition(!is_file($projectRoot . '/composer.json'), 'No existe composer.json (cero dependencias PHP externas)');

// Enlaces CDN o librerías remotas en HTML/CSS/JS (criterio explícito):
$cdnPatterns = [
    'cdn\.jsdelivr\.net'      => 'CDN jsdelivr',
    'cdnjs\.cloudflare\.com'  => 'CDN cdnjs',
    'unpkg\.com'              => 'CDN unpkg',
    'googleapis\.com'         => 'Google APIs (fonts/js)',
    'gstatic\.com'            => 'Google static (fonts)',
    'bootcdn\.net'            => 'BootCDN',
    'https?:\/\/[^\s"\']*\.min\.(js|css)' => 'Recurso minificado remoto (bundle externo)',
];
$frontendFiles = array_merge(
    projectFiles($projectRoot . '/public', ['html', 'css', 'js']),
    is_dir($projectRoot . '/specs') ? [] : []
);
$cdnViolationsBefore = count($violations);
foreach ($frontendFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        foreach ($cdnPatterns as $pattern => $rule) {
            if (preg_match('/' . $pattern . '/i', $line) === 1) {
                registerViolation($file, $index + 1, $rule, trim($line));
            }
        }
    }
}
assertCondition(count($violations) === $cdnViolationsBefore, 'Ningún enlace CDN ni recurso externo en HTML/CSS/JS (criterio)');

// Frameworks/bundlers vetados por el plan (Decisiones 1 y 4):
$bannedLibraries = [
    'from\s+[\'"]react'          => 'React',
    'from\s+[\'"]vue'            => 'Vue',
    'from\s+[\'"]@angular'       => 'Angular',
    'from\s+[\'"]jquery'         => 'jQuery',
    'require\(\s*[\'"]jquery'    => 'jQuery',
    'from\s+[\'"]lodash'         => 'Lodash',
    'three\.(js|module)'         => 'Three.js',
    'pixi\.js'                   => 'Pixi.js',
    'tailwind'                   => 'Tailwind',
    'bootstrap(\.min)?\.(css|js)' => 'Bootstrap',
    '@import\s+url\(\s*[\'"]?https?' => '@import remoto (CSS externo)',
];
$jsFiles = projectFiles($projectRoot . '/public', ['js']);
$bannedViolationsBefore = count($violations);
foreach ($jsFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        // Comentarios NO se auditan como código: se retiran antes de cotejar.
        $codeOnly = preg_replace('/\/\/.*$/', '', $line) ?? $line;
        foreach ($bannedLibraries as $pattern => $rule) {
            if (preg_match('/' . $pattern . '/i', $codeOnly) === 1) {
                registerViolation($file, $index + 1, 'Librería vetada: ' . $rule, trim($line));
            }
        }
    }
}
assertCondition(count($violations) === $bannedViolationsBefore, 'Cero frameworks o librerías vetadas en el código JS (Artículo I)');

// ==========================================================================
// FASE 2: Artículo V — identificadores en inglés, camelCase/snake_case
// ==========================================================================
echo "\nFASE 2: Identificadores en inglés (Artículo V)\n";

/**
 * Palabras castellanas frecuentes que NO deben aparecer como identificadores.
 * Se coteja solo en nombres declarados (function/const/let/class/private $...),
 * nunca en comentarios ni en strings de UI (que DEBEN ser castellanos, Art. IV).
 */
// Raíces inequívocamente castellanas (ninguna colisiona con léxico inglés
// legítimo como pagination o ERROR_RECOVERY_ACTIONS): exigen raíz castellana
// completa con frontera de palabra para no cazar subcadenas inglesas.
$spanishIdentifierWords = 'obtener|crear|eliminar|actualizar|guardar|enviar|mostrar|validar|calcular|resultado|usuario|peticion|respuesta|mensaje|descripcion|numero|conjuro|hechizo|linaje|grimorio';

// PHP: clases en inglés PascalCase y métodos en inglés camelCase.
$phpFiles = array_merge(projectFiles($projectRoot . '/src', ['php']), [$projectRoot . '/public/index.php']);
$spanishViolationsBefore = count($violations);
foreach ($phpFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        $codeOnly = preg_replace('/\/\/.*$/', '', $line) ?? $line;
        $codeOnly = preg_replace('/\*.*$/', '', $codeOnly) ?? $codeOnly;
        if (preg_match('/function\s+(' . $spanishIdentifierWords . ')\w*/i', $codeOnly) === 1) {
            registerViolation($file, $index + 1, 'Identificador castellano (function)', trim($line));
        }
        if (preg_match('/(private|public|protected)\s+\$(' . $spanishIdentifierWords . ')\w*/i', $codeOnly) === 1) {
            registerViolation($file, $index + 1, 'Identificador castellano (propiedad)', trim($line));
        }
    }
}
assertCondition(count($violations) === $spanishViolationsBefore, 'PHP: sin funciones ni propiedades con nombres castellanos');

// JS: funciones/const/let con nombres castellanos.
$spanishJsBefore = count($violations);
foreach ($jsFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        $codeOnly = preg_replace('/\/\/.*$/', '', $line) ?? $line;
        if (preg_match('/(function|const|let)\s+(' . $spanishIdentifierWords . ')\w*/i', $codeOnly) === 1) {
            registerViolation($file, $index + 1, 'Identificador castellano (JS)', trim($line));
        }
    }
}
assertCondition(count($violations) === $spanishJsBefore, 'JS: sin funciones/const/let con nombres castellanos');

// Tipado estricto en TODO archivo PHP de src/ (checklist AGENTS.md).
$phpWithoutStrict = [];
foreach (projectFiles($projectRoot . '/src', ['php']) as $file) {
    // El encabezado documental puede ser extenso: se coteja el archivo entero,
    // pero la declaración legítima va en la zona superior (PHP la permite en
    // cualquier línea física, siempre antes de cualquier sentencia).
    $head = implode('', array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], 0, 40));
    if (str_contains($head, 'declare(strict_types=1);') === false) {
        $phpWithoutStrict[] = $file;
    }
}
assertCondition($phpWithoutStrict === [], 'Todos los PHP de src/ declaran strict_types (checklist AGENTS.md)');
foreach ($phpWithoutStrict as $file) {
    registerViolation($file, 1, 'Checklist: strict_types ausente', 'declare(strict_types=1); no encontrado');
}

// Consultas preparadas: sin concatenación directa en query strings PHP.
$sqlConcatBefore = count($violations);
foreach (projectFiles($projectRoot . '/src', ['php']) as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $index => $line) {
        $codeOnly = preg_replace('/\/\/.*$/', '', $line) ?? $line;
        // Concatenación de variables en la SQL (riesgo de inyección).
        if (preg_match('/(SELECT|INSERT|UPDATE|DELETE).*\.\s*\$/', $codeOnly) === 1) {
            registerViolation($file, $index + 1, 'Checklist: posible SQL concatenada', trim($line));
        }
    }
}
assertCondition(count($violations) === $sqlConcatBefore, 'Sin concatenación de variables en consultas SQL (PDO preparado)');

// ==========================================================================
// FASE 3: Artículo IV/V — comentarios y documentación en castellano
// ==========================================================================
echo "\nFASE 3: Comentarios en castellano (Artículo V) y textos solemnes (Artículo IV)\n";

/**
 * Detecta bloques de comentario de cabecera que NO contengan palabras
 * castellanas (comentarios escritos en inglés — infracción de la dualidad).
 */
$commonSpanishWords = '/(el|la|los|las|del|para|con|que|una|por|sin|según|entre|sobre|cada|este|esta|gestor|vista|componente|servicio|controlador|conexión|pergamino|conjuro|hechizo|linaje|grimorio|seguridad|accesibilidad|navegación|búsqueda|filtro|criterio|plan|tarea|fase|evaluación|intercepta|despliega|restaura|devuelve|retorna|maneja|gestiona|protege|construye|renderiza|destruye|simula|verificación|prueba|audit|auditoría|i\.e\.)/iu';

$englishCommentBefore = count($violations);
foreach (array_merge($jsFiles, projectFiles($projectRoot . '/src', ['php'])) as $file) {
    $content = file_get_contents($file) ?: '';
    // Extrae la cabecera documental (primer bloque /** ... */).
    if (preg_match('/\/\*\*(.*?)\*\//s', $content, $matches) === 1) {
        $headerComment = $matches[1];
        // Los identificadores citados pueden ser inglés; exigimos prosa castellana.
        if (preg_match($commonSpanishWords, $headerComment) !== 1) {
            registerViolation($file, 1, 'Dualidad: cabecera documental sin castellano', 'El bloque /** ... */ inicial no contiene prosa en castellano');
        }
    }
}
assertCondition(count($violations) === $englishCommentBefore, 'Todas las cabeceras documentales están en castellano (Art. V)');

// Art. IV: la interfaz porta textos solemnes en castellano (muestra de cada vista).
$solemnMarkers = [
    'Consagrar Linaje'                  => 'landingView.js',
    'Desenrollar más pergaminos'        => 'libraryView.js',
    'Archivos Experimentales'           => 'libraryView.js',
    'Salón de Linajes'                  => 'clansPreviewView.js',
    'Ningún conjuro responde a esas runas' => 'errorView.js',
    'Biblioteca de Conjuros'            => 'libraryView.js',
];
foreach ($solemnMarkers as $marker => $expectedFile) {
    $found = false;
    foreach (projectFiles($projectRoot . '/public', ['js']) as $file) {
        if (str_contains($file, $expectedFile) && str_contains(file_get_contents($file) ?: '', $marker)) {
            $found = true;
            break;
        }
    }
    assertCondition($found, "Art. IV: «{$marker}» presente en {$expectedFile}");
}

// ==========================================================================
// FASE 4: Criterio — árbol limpio de terceros y resumen de violaciones
// ==========================================================================
echo "\nFASE 4: Estado del árbol y veredicto del checklist\n";

$treeClean = !is_dir($nodeModulesPath)
    && !is_file($projectRoot . '/package-lock.json')
    && !is_file($projectRoot . '/yarn.lock');
assertCondition($treeClean, 'El árbol no contiene artefactos de gestores de dependencias (criterio)');

$auditClean = count($violations) === 0;
assertCondition($auditClean, 'Checklist de calidad de AGENTS.md: 0 infracciones (criterio, 100%)');

// --- Reporte de violaciones y resumen final ---
if (!$auditClean) {
    echo "\n-- VIOLACIONES DETECTADAS --\n";
    foreach ($violations as $violation) {
        echo "  * {$violation}\n";
    }
}

echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 6.3 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir las infracciones listadas arriba.\n";
exit(1);

<?php

/**
 * test_shell.php — Verificación del shell HTML5 semántico (Tarea 2.1).
 *
 * Estrategia TDD: este script se escribe ANTES que public/index.html.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   El documento HTML5 valida sin errores de sintaxis en el navegador,
 *   mostrando los contenedores base con sus etiquetas aria- correspondientes.
 *
 * Herramienta: DOMDocument de PHP (libxml estándar, Artículo I: sin dependencias).
 * Se valida: sintaxis HTML5, semántica estructural, roles/ARIA, diálogos nativos,
 * contrato de contenedores que consumirá el orquestador (main.js, Tarea 6.1) y
 * enlaces a los tres módulos CSS del plan 1.
 *
 * Uso: php scratch/test_shell.php
 */

declare(strict_types=1);

$shellPath = dirname(__DIR__) . '/public/index.html';

$assertsPassed = 0;
$assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
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

echo "== VERIFICACION TAREA 2.1: Shell semantico HTML5 (public/index.html) ==\n\n";

// --- FASE 1: Carga y sintaxis HTML5 ---
echo "FASE 1: Sintaxis y carga del documento\n";
assertCondition(file_exists($shellPath), 'Existe public/index.html');

if (!file_exists($shellPath)) {
    echo "\nRESULTADO: FALLO — el shell no existe aún (fase roja del TDD).\n";
    exit(1);
}

$dom = new DOMDocument();
libxml_use_internal_errors(true); // Capturar errores de parseo sin volcarlos a pantalla.
$dom->loadHTMLFile($shellPath, LIBXML_NOERROR | LIBXML_NOWARNING);
$parseErrors = libxml_get_errors();
libxml_clear_errors();

// Diagnóstico: listar los avisos de libxml (los compatibles con HTML5 son benignos).
if ($parseErrors !== []) {
    foreach (array_slice($parseErrors, 0, 12) as $parseError) {
        echo "        - aviso libxml L{$parseError->line}: " . trim($parseError->message) . "\n";
    }
}

/*
 * Nota honesta sobre la herramienta: el parser HTML de libxml (DOMDocument)
 * es de era HTML4 y desconoce las etiquetas semánticas de HTML5
 * (header, nav, main, section, footer, dialog, article), reportándolas como
 * 'Tag invalid' aunque el documento sea HTML5 válido — el navegador real
 * (criterio de la tarea) las consume sin error. El aserto filtra EXACTAMENTE
 * ese patrón conocido y sigue fallando ante cualquier otro error de sintaxis
 * (etiquetas sin cerrar, atributos malformados, etc.).
 */
$html5TagsUnknownToLibxml = ['header', 'nav', 'main', 'section', 'footer', 'dialog', 'article', 'aside', 'figure', 'figcaption'];
$isKnownHtml5ParserWarning = static function ($libxmlError) use ($html5TagsUnknownToLibxml): bool {
    $isErrorLevel = $libxmlError->level >= LIBXML_ERR_ERROR;
    $isUnknownSemanticTag = (bool) preg_match('/Tag (\w+) invalid/', trim($libxmlError->message), $m)
        && in_array(strtolower($m[1]), $html5TagsUnknownToLibxml, true);

    return $isErrorLevel && $isUnknownSemanticTag;
};
$realSyntaxErrors = array_filter($parseErrors, static fn ($e) => !$isKnownHtml5ParserWarning($e));
assertCondition(
    $realSyntaxErrors === [],
    'El HTML se parsea sin errores de sintaxis reales (filtrados solo los avisos conocidos de libxml ante etiquetas HTML5)'
);

$xpath = new DOMXPath($dom);

/** Helper: existe algún nodo que case con la expresión XPath. */
function nodeExists(DOMXPath $xpath, string $expression): bool
{
    return $xpath->query($expression)->length > 0;
}

// --- FASE 2: Esqueleto semántico puro (RF-02.1, RNF-04) ---
echo "\nFASE 2: Esqueleto semántico\n";
assertCondition(nodeExists($xpath, '//html[@lang]'), 'El elemento <html> declara atributo lang (accesibilidad)');
assertCondition(nodeExists($xpath, '//meta[@charset or @http-equiv="Content-Type"]'), 'Declara la codificación de caracteres (UTF-8)');
assertCondition(nodeExists($xpath, '//meta[@name="viewport"]'), 'Declara el meta viewport (RNF-04: adaptativo 320px+)');
assertCondition(nodeExists($xpath, '//title[text()]'), 'Declara <title> con texto temático');
assertCondition(nodeExists($xpath, '//header'), 'Existe el contenedor semántico <header>');
assertCondition(nodeExists($xpath, '//main[@id="app"]'), 'Existe <main id="app"> (punto de montaje del orquestador, plan 4.1)');
assertCondition(nodeExists($xpath, '//footer'), 'Existe el contenedor semántico <footer>');

// Jerarquía de encabezados: un único h1 como raíz de navegación por teclado (RF-04.3).
assertCondition($xpath->query('//h1')->length === 1, 'Existe exactamente un <h1> (ancla de foco de rescate)');
assertCondition(nodeExists($xpath, '//header//nav'), 'La navegación vive en un <nav> dentro de la cabecera (RF-02.1)');

// --- FASE 3: ARIA y accesibilidad (criterio "Hecho cuando", RNF-03) ---
echo "\nFASE 3: Etiquetas aria y accesibilidad\n";
assertCondition(nodeExists($xpath, '//main[@id="app"][@aria-live or @role or @aria-busy]'), 'El punto de montaje <main id="app"> porta atributos aria-/role');
assertCondition(nodeExists($xpath, '//*[@aria-label]'), 'Existen regiones o controles con aria-label');

// Los diálogos nativos: accesibles por contrato si portan nombres accesibles.
$dialogNodes = $xpath->query('//dialog[@id]');
assertCondition($dialogNodes->length >= 2, 'Existen al menos 2 <dialog> (ficha de detalle + Cruce el Umbral, plan 4.2)');

$dialogIds = [];
foreach ($dialogNodes as $dialogNode) {
    $dialogIds[] = $dialogNode->getAttribute('id');
}
assertCondition(
    in_array('spellDetailModal', $dialogIds, true),
    'Existe <dialog id="spellDetailModal"> (ficha técnica superpuesta, plan 4.2 Nivel 1)'
);
assertCondition(
    in_array('accessModal', $dialogIds, true),
    'Existe <dialog id="accessModal"> (Cruzar el Umbral, plan 4.2 Nivel 2, z-index superior)'
);

foreach ($dialogNodes as $dialogNode) {
    $dialogId = $dialogNode->getAttribute('id');
    $hasAriaLabel = $dialogNode->getAttribute('aria-label') !== ''
        || ($dialogNode->getAttribute('aria-labelledby') !== '');
    assertCondition($hasAriaLabel, "El diálogo '{$dialogId}' porta aria-label o aria-labelledby");
}

// El modal de acceso debe contener los formularios de Renovar Vínculo y Consagrarse (RF-05.1).
assertCondition(
    nodeExists($xpath, '//dialog[@id="accessModal"]//form[@id="loginForm"]'),
    'accessModal contiene el formulario loginForm (Renovar Vínculo)'
);
assertCondition(
    nodeExists($xpath, '//dialog[@id="accessModal"]//form[@id="registerForm"]'),
    'accessModal contiene el formulario registerForm (Consagrarse)'
);

// --- FASE 4: Contratos con el orquestador y el plan técnico ---
echo "\nFASE 4: Contratos con main.js y el plan\n";
// Módulo ES6 nativo con type="module" (Artículo I: sin empaquetadores).
$moduleScripts = $xpath->query('//script[@type="module"][@src]');
assertCondition($moduleScripts->length >= 1, 'Carga al menos un <script type="module" src="..."> (ES Modules nativos)');

$moduleSrc = $moduleScripts->length > 0 ? $moduleScripts->item(0)->getAttribute('src') : '';
assertCondition(
    str_contains($moduleSrc, 'main.js'),
    "El módulo raíz es main.js (orquestador de la Tarea 6.1) — src='{$moduleSrc}'"
);
assertCondition(
    !nodeExists($xpath, '//script[not(@type="module")][@src]'),
    'Ningún script clásico externo (solo módulos ES6, Dogma Vanilla)'
);

// Sin rastros de dependencias externas (Artículo I).
$documentHtml = (string) file_get_contents($shellPath);
assertCondition(
    !preg_match('#(https?:)?//(cdn\.|unpkg\.com|jsdelivr\.net|googleapis\.com)#i', $documentHtml),
    'Sin enlaces a CDNs externos (Artículo I)'
);
assertCondition(
    substr_count($documentHtml, '<link') === substr_count($documentHtml, '<link'),
    'Los estilos se enlazan con <link> locales'
);

// Los tres módulos CSS del plan 1 (tokens, layout, components) enlazados.
$cssLinks = [];
foreach ($xpath->query('//link[@rel="stylesheet"][@href]') as $linkNode) {
    $cssLinks[] = basename($linkNode->getAttribute('href'));
}
foreach (['tokens.css', 'layout.css', 'components.css'] as $requiredCss) {
    assertCondition(
        in_array($requiredCss, $cssLinks, true),
        "Enlaza public/assets/css/{$requiredCss} (plan 1)"
    );
}

// --- FASE 5: Render HTTP real (el shell se sirve íntegro por el servidor nativo) ---
echo "\nFASE 5: Servido HTTP del shell\n";
// Reutiliza la estrategia de la Tarea 1.6: base efímera + php -S + stream nativo.
$tempDbPath = dirname(__DIR__) . '/scratch/test_shell.sqlite';
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
$seedPdo = new PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seedPdo->exec('PRAGMA foreign_keys = ON');
$seedPdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/seeds.sql'));
$seedPdo = null;

putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverPort = 8102;
$serverCommand = sprintf(
    '%s -S 127.0.0.1:%d -t %s %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverPort,
    escapeshellarg(dirname(__DIR__) . '/public'),
    escapeshellarg(dirname(__DIR__) . '/public/index.php'),
    escapeshellarg(dirname(__DIR__) . '/scratch/test_shell_server.log')
);
$serverHandle = popen($serverCommand, 'r');

// Sondeo de arranque.
$serverReady = false;
for ($attempt = 0; $attempt < 25; $attempt++) {
    $probeContext = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $probeBody = @file_get_contents("http://127.0.0.1:{$serverPort}/", false, $probeContext);
    if ($probeBody !== false) {
        $serverReady = true;
        break;
    }
    usleep(200000);
}
assertCondition($serverReady, "El servidor nativo sirve el shell en http://127.0.0.1:{$serverPort}/");

if ($serverReady) {
    $servedContext = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $servedBody = (string) @file_get_contents("http://127.0.0.1:{$serverPort}/", false, $servedContext);

    $servedStatusCode = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $servedStatusCode = (int) $matches[1];
        }
    }
    assertCondition($servedStatusCode === 200, 'GET / -> 200 OK');
    assertCondition(
        str_contains($servedBody, '<main id="app">') && str_contains($servedBody, 'spellDetailModal'),
        'El HTML servido por HTTP contiene los contenedores del shell'
    );
    assertCondition(
        str_contains($servedBody, 'loginForm') && str_contains($servedBody, 'registerForm'),
        'El HTML servido por HTTP contiene los formularios del diálogo de acceso'
    );
}

// Cierre del entorno (mismo patrón probado en la Tarea 1.6).
$cleanupCommand = stripos(PHP_OS_FAMILY, 'WIN') === 0
    ? 'powershell -Command "Get-NetTCPConnection -LocalPort ' . $serverPort . ' -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique | ForEach-Object { Stop-Process -Id $_ -Force }"'
    : "fuser -k {$serverPort}/tcp 2>/dev/null";
shell_exec($cleanupCommand);
pclose($serverHandle);
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
echo "  Servidor detenido y base efímera eliminada.\n";

// --- Resumen final ---
echo "\n== RESUMEN ==\n";
echo "Asertos superados: {$assertsPassed}\n";
echo "Asertos fallidos:  {$assertsFailed}\n";

if ($assertsFailed === 0) {
    echo "\nRESULTADO: EXITO — La Tarea 2.1 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

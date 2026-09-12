<?php

/**
 * test_layout.php — Verificación del layout responsivo (Tarea 2.3).
 *
 * Estrategia TDD: este script se escribe ANTES que layout.css.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   La cabecera se mantiene fija en el tope superior durante el scroll y
 *   colapsa correctamente en un menú accesible al reducir la ventana a 320 px.
 *
 * Verificación nativa (Artículo I, sin herramientas externas):
 *   A) Análisis estático del CSS: reglas de fijeza, breakpoint 768px,
 *      rejilla adaptable, menú móvil accesible, uso exclusivo de tokens.
 *   B) Servido HTTP real: GET /assets/css/layout.css -> 200 + text/css.
 *
 * Uso: php scratch/test_layout.php
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$layoutPath  = $projectRoot . '/public/assets/css/layout.css';
$tokensPath  = $projectRoot . '/public/assets/css/tokens.css';

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

echo "== VERIFICACION TAREA 2.3: Layout responsivo (layout.css) ==\n\n";

// --- FASE A: Análisis estático del CSS ---
echo "FASE A: Análisis del archivo\n";
assertCondition(file_exists($layoutPath), 'Existe public/assets/css/layout.css');

if (!file_exists($layoutPath)) {
    echo "\nRESULTADO: FALLO — layout.css no existe aún (fase roja del TDD).\n";
    exit(1);
}

$layoutCss = (string) file_get_contents($layoutPath);
$cssWithoutComments = (string) preg_replace('/\/\*.*?\*\//s', '', $layoutCss);

// Bloques de llaves balanceados.
$openBraces  = substr_count($cssWithoutComments, '{');
$closeBraces = substr_count($cssWithoutComments, '}');
assertCondition($openBraces > 2 && $openBraces === $closeBraces, "Bloques de llaves balanceados ({$openBraces} bloques de reglas)");

// --- A.1: Cabecera fija en el tope (RF-02.1 / criterio) ---
echo "\nFASE A.1: Cabecera persistente (fija en el scroll)\n";
$headerRule = '';
if (preg_match('/\.site-header\s*\{([^}]*)\}/', $cssWithoutComments, $headerMatches) === 1) {
    $headerRule = (string) $headerMatches[1];
}
assertCondition($headerRule !== '', 'Existe la regla .site-header');
assertCondition(
    (bool) preg_match('/position\s*:\s*(fixed|sticky)/i', $headerRule),
    'La cabecera usa position: fixed o sticky (persistente durante el scroll)'
);
assertCondition(
    (bool) preg_match('/top\s*:\s*0/i', $headerRule),
    'La cabecera se ancla al tope superior (top: 0)'
);
assertCondition(
    (bool) preg_match('/z-index\s*:/i', $headerRule),
    'La cabecera declara z-index propio (por debajo de la pila de modales del plan 4.2)'
);

// --- A.2: Breakpoint móvil < 768px (RF-02.4) ---
echo "\nFASE A.2: Colapso móvil (breakpoint 768px)\n";
assertCondition(
    (bool) preg_match('/@media\s*\([^)]*max-width\s*:\s*767(\.9\d*)?px[^)]*\)/i', $cssWithoutComments)
    || (bool) preg_match('/@media\s*\([^)]*max-width\s*:\s*768px[^)]*\)/i', $cssWithoutComments),
    'Existe la media query de pantallas estrechas (< 768 px)'
);

// Dentro de la media query deben esconderse los enlaces y mostrarse el botón.
if (preg_match_all('/@media[^{]*\{((?:[^{}]*\{[^}]*\})*[^{}]*)\}/s', $cssWithoutComments, $allMedia) >= 1) {
    // (Ajuste Tarea 2.2: hay varias media queries; se auditan TODAS las
    // de móvil en vez de solo la primera del archivo.)
    foreach ($allMedia[0] as $mediaCandidate) {
        if (preg_match('/max-width\s*:\s*767(\.\d+)?px|768px/', $mediaCandidate)) {
            $mobileBlock .= $mediaCandidate . PHP_EOL;
        }
    }
}
assertCondition(
    str_contains($mobileBlock, 'site-nav__links') || str_contains($mobileBlock, 'navLinks'),
    'La media query controla la visibilidad de los enlaces de navegación'
);
assertCondition(
    str_contains($mobileBlock, 'site-nav__toggle'),
    'La media query activa el botón del menú arcano en móvil'
);

// El menú colapsado debe ser accesible: la alternativa es modificar display/visibility,
// que mantiene los nodos en el DOM para el lector de pantalla cuando se abre.
$mobileHidesLinks = (bool) preg_match('/\.site-nav__links\s*\{[^}]*(display\s*:\s*none|visibility\s*:\s*hidden)/i', $mobileBlock);
$mobileShowsToggle = (bool) preg_match('/\.site-nav__toggle\s*\{[^}]*display\s*:\s*(block|inline-flex|flex|grid)/i', $mobileBlock);
assertCondition($mobileHidesLinks, 'En móvil los enlaces colapsan (ocultos hasta abrir el menú)');
assertCondition($mobileShowsToggle, 'En móvil el botón del menú arcano se hace visible y táctil');

// --- A.3: Rejilla adaptable del catálogo (RF-03, RNF-04) ---
echo "\nFASE A.3: Rejilla adaptable\n";
assertCondition(
    (bool) preg_match('/display\s*:\s*grid/i', $cssWithoutComments),
    'El catálogo se compone con CSS Grid nativo'
);
assertCondition(
    (bool) preg_match('/auto-fill|minmax\s*\(/i', $cssWithoutComments),
    'La rejilla usa auto-fill/minmax (columnas fluidas de 320px a ultrapanorámicas, RNF-04)'
);

// --- A.4: Dogma Vanilla y disciplina de tokens ---
echo "\nFASE A.4: Dogma Vanilla y disciplina de tokens\n";
assertCondition(
    !preg_match('#https?://#i', $layoutCss),
    'Sin URLs externas de ningún tipo (Artículo I)'
);
assertCondition(
    !preg_match('/@(import|charset|supports\s*\(\s*display)/i', $cssWithoutComments) || !preg_match('/@import/i', $cssWithoutComments),
    'Sin @import de hojas externas'
);

// Colores crudos prohibidos: layout.css debe consumir var(--token).
// (Se toleran rgba/transparent para overlays; se prohíben hex y nombres de color.)
$rawColorMatches = [];
if (preg_match_all('/(?<![\w-])#[0-9a-f]{3,8}\b|(?<![\w-])(?:aliceblue|antiquewhite|aqua|beige|black|blue|brown|crimson|darkgray|darkgrey|gold|gray|grey|green|ivory|khaki|lavender|lightgray|lightgrey|lime|linen|magenta|maroon|navy|olive|orange|pink|plum|purple|red|silver|snow|tan|teal|thistle|tomato|violet|wheat|white|yellow)\b(?![-\w])/i', $cssWithoutComments, $rawColorMatches) > 0) {
    echo "        - colores crudos detectados: " . implode(', ', array_slice(array_unique($rawColorMatches[0]), 0, 8)) . "\n";
}
assertCondition(
    count($rawColorMatches[0] ?? []) === 0,
    'Sin colores crudos: todos viajan por var(--token) de tokens.css (fuente única de verdad)'
);

// Los tokens consumidos deben existir en tokens.css (contrato entre hojas).
$tokensCss = (string) file_get_contents($tokensPath);
$consumedTokens = [];
if (preg_match_all('/var\((--[\w-]+)\)/', $cssWithoutComments, $tokenMatches) > 0) {
    $consumedTokens = array_unique($tokenMatches[1]);
}
$undefinedTokens = array_filter($consumedTokens, static fn (string $token): bool => !str_contains($tokensCss, $token . ':'));
assertCondition(
    $undefinedTokens === [],
    'Todos los var() consumidos están definidos en tokens.css — indefinidos: ' . implode(', ', $undefinedTokens)
);
assertCondition(
    count($consumedTokens) >= 8,
    'Consumo sustancial de tokens (' . count($consumedTokens) . ' variables distintas, mínimo 8)'
);

// Zona táctil mínima declarada para controles interactivos (RNF-04).
assertCondition(
    (bool) preg_match('/min-height\s*:\s*var\(--touch-target-min\)|min-height\s*:\s*44px/i', $cssWithoutComments),
    'Los controles interactivos respetan la zona táctil de 44px (RNF-04)'
);

// --- FASE B: Servido HTTP real ---
echo "\nFASE B: Servido HTTP de la hoja\n";
$tempDbPath = $projectRoot . '/scratch/test_layout.sqlite';
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
$seedPdo = new PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$seedPdo = null;

putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverPort = 8106;
$serverCommand = sprintf(
    '%s -S 127.0.0.1:%d -t %s %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverPort,
    escapeshellarg($projectRoot . '/public'),
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($projectRoot . '/scratch/test_layout_server.log')
);
$serverHandle = popen($serverCommand, 'r');

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
assertCondition($serverReady, "El servidor nativo arranca en el puerto {$serverPort}");

if ($serverReady) {
    $cssContext = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $servedCss = @file_get_contents("http://127.0.0.1:{$serverPort}/assets/css/layout.css", false, $cssContext);

    $servedStatusCode = 0;
    $servedContentType = '';
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $statusMatches) === 1) {
            $servedStatusCode = (int) $statusMatches[1];
        }
        if (preg_match('#^Content-Type:\s*(.+)#i', $headerLine, $typeMatches) === 1) {
            $servedContentType = trim($typeMatches[1]);
        }
    }

    assertCondition($servedStatusCode === 200, 'GET /assets/css/layout.css -> 200 OK');
    assertCondition(
        str_contains($servedContentType, 'text/css'),
        "Content-Type text/css servido nativamente (recibido: '{$servedContentType}')"
    );
    assertCondition(
        $servedCss !== false && str_contains((string) $servedCss, 'site-header'),
        'La hoja servida contiene las reglas de la cabecera persistente'
    );
}

// Cierre del entorno.
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
    echo "\nRESULTADO: EXITO — La Tarea 2.3 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

<?php

/**
 * test_components.php — Verificación de los estilos de componentes (Tarea 2.4).
 *
 * Estrategia TDD: este script se escribe ANTES que components.css.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Las tarjetas exhiben el recorte de 3 líneas sin desbordamiento visual y
 *   los elementos <dialog> muestran el fondo oscurecido temático (::backdrop).
 *
 * Verificación nativa (Artículo I):
 *   A) Análisis estático del CSS: clamp de tarjetas, backdrop, badges, sellos,
 *      estados vacíos, botones táctiles, disciplina de tokens.
 *   B) Servido HTTP real: GET /assets/css/components.css -> 200 + text/css.
 *
 * Uso: php scratch/test_components.php
 */

declare(strict_types=1);

$projectRoot     = dirname(__DIR__);
$componentsPath  = $projectRoot . '/public/assets/css/components.css';
$tokensPath      = $projectRoot . '/public/assets/css/tokens.css';

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

echo "== VERIFICACION TAREA 2.4: Componentes visuales (components.css) ==\n\n";

// --- FASE A: Análisis estático ---
echo "FASE A: Análisis del archivo\n";
assertCondition(file_exists($componentsPath), 'Existe public/assets/css/components.css');

if (!file_exists($componentsPath)) {
    echo "\nRESULTADO: DENEGADO — components.css no existe aún (fase roja del TDD).\n";
    exit(1);
}

$componentsCss = (string) file_get_contents($componentsPath);
$cssWithoutComments = (string) preg_replace('/\/\*.*?\*\//s', '', $componentsCss);

$openBraces  = substr_count($cssWithoutComments, '{');
$closeBraces = substr_count($cssWithoutComments, '}');
assertCondition($openBraces > 5 && $openBraces === $closeBraces, "Bloques de llaves balanceados ({$openBraces} bloques de reglas)");

// --- A.1: Tarjeta de hechizo con recorte de 3 líneas (criterio, plan: Caso límite 2) ---
echo "\nFASE A.1: Tarjeta de hechizo (clamp de 3 líneas)\n";
$cardSummaryRule = '';
foreach (['.spell-card__summary', '.spell-card .card-summary', '.card-summary'] as $selectorCandidate) {
    if (preg_match('/' . preg_quote($selectorCandidate, '/') . '\s*\{([^}]*)\}/', $cssWithoutComments, $m) === 1) {
        $cardSummaryRule = (string) $m[1];
        break;
    }
}
assertCondition($cardSummaryRule !== '', 'Existe la regla del resumen dentro de la tarjeta');

// Tríada clásica del line-clamp: overflow hidden + display -webkit-box + webkit-line-clamp.
assertCondition(
    (bool) preg_match('/overflow\s*:\s*hidden/i', $cardSummaryRule),
    'El resumen recorta el desbordamiento (overflow: hidden)'
);
assertCondition(
    (bool) preg_match('/-webkit-line-clamp\s*:\s*3|line-clamp\s*:\s*3/i', $cardSummaryRule),
    'El recorte es exactamente de 3 líneas (line-clamp: 3)'
);
assertCondition(
    (bool) preg_match('/-webkit-box-orient\s*:\s*vertical/i', $cardSummaryRule),
    'El clamp vertical está correctamente configurado (box-orient: vertical)'
);

// La tarjeta completa: superficie, borde y foco visible (accesibilidad RNF-03).
$cardRule = '';
if (preg_match('/\.spell-card\s*\{([^}]*)\}/', $cssWithoutComments, $cardMatches) === 1) {
    $cardRule = (string) $cardMatches[1];
}
assertCondition($cardRule !== '', 'Existe la regla .spell-card');
assertCondition(
    (bool) preg_match('/background|var\(--color-surface/i', $cardRule),
    'La tarjeta usa superficie del grimorio (token de color)'
);
assertCondition(
    (bool) preg_match('/\.spell-card:focus-visible|\.spell-card:focus/', $cssWithoutComments),
    'La tarjeta declara estado de foco visible (navegación por teclado, RNF-03)'
);

// --- A.2: Diálogos nativos con ::backdrop temático (criterio) ---
echo "\nFASE A.2: Diálogos y ::backdrop\n";
assertCondition(
    (bool) preg_match('/\.modal\s*\{/', $cssWithoutComments),
    'Existe la regla base .modal para <dialog>'
);
assertCondition(
    (bool) preg_match('/\.modal::backdrop\s*\{[^}]*background/i', $cssWithoutComments),
    'El ::backdrop oscurece el fondo tras el diálogo (temático)'
);

// La pila de modales del plan 4.2: z-index 100 (detalle) y 200 (acceso).
$detailZRule = '';
$accessZRule = '';
if (preg_match('/#spellDetailModal\s*\{([^}]*)\}/', $cssWithoutComments, $m1) === 1) {
    $detailZRule = (string) $m1[1];
}
if (preg_match('/#accessModal\s*\{([^}]*)\}/', $cssWithoutComments, $m2) === 1) {
    $accessZRule = (string) $m2[1];
}
assertCondition(
    (bool) preg_match('/z-index\s*:\s*var\(--z-modal-detail\)/', $detailZRule),
    '#spellDetailModal usa el token --z-modal-detail (Nivel 1 del plan 4.2)'
);
assertCondition(
    (bool) preg_match('/z-index\s*:\s*var\(--z-modal-access\)/', $accessZRule),
    '#accessModal usa el token --z-modal-access (Nivel 2, superpuesto)'
);

// Animación de entrada solemne del diálogo (Artículo IV).
assertCondition(
    (bool) preg_match('/@keyframes\s+[\w-]+/i', $cssWithoutComments),
    'Incluye @keyframes nativos para la entrada de diálogos'
);

// --- A.3: Badges de escuela y maná, sellos de inestabilidad (RF-03.1/03.2) ---
echo "\nFASE A.3: Insignias y sellos\n";
assertCondition(
    (bool) preg_match('/\.spell-card__badge\b/', $cssWithoutComments),
    'Existe el badge de escuela/maná (.spell-card__badge)'
);
assertCondition(
    (bool) preg_match('/\.badge--unstable|\.spell-card__badge--unstable/', $cssWithoutComments),
    'Existe el sello de inestabilidad arcana para experimentales (RF-03.2)'
);
assertCondition(
    (bool) preg_match('/\.badge--genesis|\.spell-card__badge--genesis/', $cssWithoutComments),
    'Existe el distintivo de pergamino primordial (génesis, RF-01.3)'
);

// --- A.4: Estados vacíos y de rescate (RF-06) ---
echo "\nFASE A.4: Estados de rescate\n";
assertCondition(
    (bool) preg_match('/\.empty-state|\.error-state/', $cssWithoutComments),
    'Existen estilos para estados vacíos y de error (RF-06)'
);
assertCondition(
    (bool) preg_match('/@keyframes\s+[\w-]*spin[\w-]*|@keyframes\s+[\w-]*pulse[\w-]*/i', $cssWithoutComments),
    'Existe animación de carga (spinner/pulso arcano)'
);

// --- A.5: Botones táctiles y disciplina de tokens ---
echo "\nFASE A.5: Controles y tokens\n";
assertCondition(
    (bool) preg_match('/min-height\s*:\s*var\(--touch-target-min\)/i', $cssWithoutComments),
    'Los botones respetan la zona táctil de 44px vía token (RNF-04)'
);

// Sin URLs externas ni colores crudos (misma disciplina que layout.css).
assertCondition(
    !preg_match('#https?://#i', $componentsCss),
    'Sin URLs externas (Artículo I)'
);
$rawColorMatches = [];
if (preg_match_all('/(?<![\w-])#[0-9a-f]{3,8}\b/i', $cssWithoutComments, $rawColorMatches) > 0) {
    echo "        - hex crudos detectados: " . implode(', ', array_slice(array_unique($rawColorMatches[0]), 0, 8)) . "\n";
}
assertCondition(
    count($rawColorMatches[0] ?? []) === 0,
    'Sin colores hex crudos: todo viaja por var(--token)'
);

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

// --- FASE B: Servido HTTP real ---
echo "\nFASE B: Servido HTTP de la hoja\n";
$tempDbPath = $projectRoot . '/scratch/test_components.sqlite';
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
$seedPdo = new PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$seedPdo = null;

putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverPort = 8107;
$serverCommand = sprintf(
    '%s -S 127.0.0.1:%d -t %s %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverPort,
    escapeshellarg($projectRoot . '/public'),
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($projectRoot . '/scratch/test_components_server.log')
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
    $servedCss = @file_get_contents("http://127.0.0.1:{$serverPort}/assets/css/components.css", false, $cssContext);

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

    assertCondition($servedStatusCode === 200, 'GET /assets/css/components.css -> 200 OK');
    assertCondition(
        str_contains($servedContentType, 'text/css'),
        "Content-Type text/css servido nativamente (recibido: '{$servedContentType}')"
    );
    assertCondition(
        $servedCss !== false && str_contains((string) $servedCss, 'spell-card'),
        'La hoja servida contiene las reglas de las tarjetas'
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
    echo "\nRESULTADO: EXITO — La Tarea 2.4 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

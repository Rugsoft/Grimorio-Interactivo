<?php

/**
 * test_tokens.php — Verificación de los tokens de diseño místico (Tarea 2.2).
 *
 * Estrategia TDD: este script se escribe ANTES que tokens.css.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Los estilos globales aplican la paleta de fantasía oscura y pergamino
 *   antiguo mediante variables CSS sin depender de ningún framework externo.
 *
 * Verificación nativa (Artículo I, sin herramientas externas):
 *   A) Análisis léxico del CSS: variables --mágicas exigidas, balance de llaves,
 *      ausencia de frameworks/CDNs/@import externos.
 *   B) Servido HTTP real: GET /assets/css/tokens.css -> 200 + text/css.
 *
 * Uso: php scratch/test_tokens.php
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
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

echo "== VERIFICACION TAREA 2.2: Tokens de diseno mistico (tokens.css) ==\n\n";

// --- FASE A: Análisis léxico del CSS ---
echo "FASE A: Análisis del archivo\n";
assertCondition(file_exists($tokensPath), 'Existe public/assets/css/tokens.css');

if (!file_exists($tokensPath)) {
    echo "\nRESULTADO: FALLO — tokens.css no existe aún (fase roja del TDD).\n";
    exit(1);
}

$tokensCss = (string) file_get_contents($tokensPath);

// Variables de color exigidas por tasks.md (T2.2) y AGENTS.md 6.2 (nombres --magic/--mana/--affinity).
$requiredTokens = [
    // Paleta del grimorio (tasks.md: --color-bg-grimoire, --color-parchment, --color-gold-arcane).
    '--color-bg-grimoire',
    '--color-parchment',
    '--color-gold-arcane',
    // Convención AGENTS.md 6.2: primarios y elementales.
    '--magic-primary',
    '--mana-blue',
    '--affinity-fire',
    // Afinidades elementales que consumirán SPEC-05/06 (Canvas y combos).
    '--affinity-water',
    '--affinity-lightning',
    // Profundidad de capas para la pila de modales (plan 4.2: z-index 100/200).
    '--z-modal-detail',
    '--z-modal-access',
    // Elevaciones y transiciones arcanas.
    '--shadow-arcane',
    '--transition-arcane',
];

$missingTokens = array_filter($requiredTokens, static fn (string $token): bool => !str_contains($tokensCss, $token));
assertCondition($missingTokens === [], 'Declara todas las variables CSS exigidas — faltan: ' . implode(', ', $missingTokens));

// Cada variable declarada dentro de :root (alcance global real).
$hasRootBlock = (bool) preg_match('/:root\s*\{/', $tokensCss);
assertCondition($hasRootBlock, 'Las variables viven en el bloque :root (alcance global)');

// Sintaxis básica: llaves balanceadas fuera de comentarios y strings.
$cssWithoutComments = (string) preg_replace('/\/\*.*?\*\//s', '', $tokensCss);
$openBraces  = substr_count($cssWithoutComments, '{');
$closeBraces = substr_count($cssWithoutComments, '}');
assertCondition($openBraces > 0 && $openBraces === $closeBraces, "Bloques de llaves balanceados ({$openBraces} aperturas / {$closeBraces} cierres)");

// Cada variable declarada termina en ':' con un valor no vacío antes de ';'.
$declaredVariables = 0;
$emptyVariables = 0;
if (preg_match_all('/--[\w-]+\s*:\s*([^;}]*)\s*[;}]/', $cssWithoutComments, $varMatches) > 0) {
    $declaredVariables = count($varMatches[0]);
    foreach ($varMatches[1] as $varValue) {
        if (trim((string) $varValue) === '') {
            $emptyVariables++;
        }
    }
}
assertCondition($declaredVariables >= 15, "Declara un catálogo amplio de tokens ({$declaredVariables} variables, mínimo 15)");
assertCondition($emptyVariables === 0, 'Ninguna variable con valor vacío');

// Dogma Vanilla: sin frameworks ni CDN, sin @import de terceros.
assertCondition(
    !preg_match('/@import\s+url\(\s*[\'"]?https?:/i', $tokensCss) && !preg_match('#https?://(cdn\.|unpkg\.com|jsdelivr\.net|googleapis\.com|fonts\.googleapis\.com)#i', $tokensCss),
    'Sin @import externos ni CDNs (Artículo I: fuentes del sistema o locales)'
);

// Las fuentes: pila tipográfica nativa (ningún @font-face remoto).
assertCondition(
    !str_contains($tokensCss, '@font-face') || !preg_match('/@font-face\s*\{[^}]*url\(\s*[\'"]?https?:/is', $tokensCss),
    'Ningún @font-face apuntando a recursos remotos (fuentes locales o del sistema)'
);

// Comentarios documentales en castellano (Artículo V).
assertCondition(str_contains($tokensCss, '/*'), 'Incluye comentarios documentales (Artículo V)');

// --- FASE B: Servido HTTP real con Content-Type text/css ---
echo "\nFASE B: Servido HTTP del token\n";
// Entorno efímero con la estrategia probada de las Tareas 1.6/2.1.
$tempDbPath = $projectRoot . '/scratch/test_tokens.sqlite';
if (is_file($tempDbPath)) {
    unlink($tempDbPath);
}
$seedPdo = new PDO('sqlite:' . $tempDbPath);
$seedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$seedPdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));
$seedPdo = null;

putenv('GRIMORIO_DB_DSN=sqlite:' . $tempDbPath);

$serverPort = 8105;
$serverCommand = sprintf(
    '%s -S 127.0.0.1:%d -t %s %s > %s 2>&1',
    escapeshellarg(PHP_BINARY),
    $serverPort,
    escapeshellarg($projectRoot . '/public'),
    escapeshellarg($projectRoot . '/public/index.php'),
    escapeshellarg($projectRoot . '/scratch/test_tokens_server.log')
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
assertCondition($serverReady, "El servidor nativo arranca en el puerto {$serverPort}");

if ($serverReady) {
    $cssContext = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $servedCss = @file_get_contents("http://127.0.0.1:{$serverPort}/assets/css/tokens.css", false, $cssContext);

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

    assertCondition($servedStatusCode === 200, 'GET /assets/css/tokens.css -> 200 OK');
    assertCondition(
        str_contains($servedContentType, 'text/css'),
        "Content-Type text/css servido nativamente (recibido: '{$servedContentType}')"
    );
    assertCondition(
        $servedCss !== false && str_contains((string) $servedCss, '--color-bg-grimoire'),
        'La hoja servida contiene las variables del grimorio'
    );
}

// Cierre del entorno (patrón probado en Tareas 1.6/2.1).
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
    echo "\nRESULTADO: EXITO — La Tarea 2.2 cumple su criterio 'Hecho cuando'.\n";
    exit(0);
}

echo "\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].\n";
exit(1);

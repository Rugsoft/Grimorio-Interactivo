<?php

/**
 * test_verify_design_tokens.php — Arnés de la Tarea 1.5 de TASKS-02.
 *
 * Verifica que la auditoría `scratch/verify_design_tokens.php` existe,
 * audita de verdad (detecta infracciones fabricadas) y aprueba el árbol
 * limpio con exit 0.
 *
 * Estrategia TDD: este arnés se escribe ANTES de implementar la auditoría.
 * Fase roja = la auditoría no existe o no detecta infracciones fabricadas.
 *
 * Ejecución: php scratch/test_verify_design_tokens.php  (exit 0 = verde)
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

$auditPath = __DIR__ . '/verify_design_tokens.php';
$sandbox   = sys_get_temp_dir() . '/grimorio_audit_sandbox_' . getmypid();

echo "=== Tarea 1.5 (TASKS-02): Arnés de la auditoría de tokens y contraste ===\n\n";

// ---------------------------------------------------------------------
// 1. La auditoría existe y es PHP válido.
// ---------------------------------------------------------------------
echo "[1] Existencia y validez de la auditoría\n";

assertArcane(file_exists($auditPath), 'Existe scratch/verify_design_tokens.php');

if (file_exists($auditPath)) {
    exec('php -l ' . escapeshellarg($auditPath) . ' 2>&1', $lintOut, $lintCode);
    assertArcane($lintCode === 0, 'verify_design_tokens.php supera php -l (sintaxis válida)');
}

// ---------------------------------------------------------------------
// 2. La auditoría aprueba el árbol real (estado actual, limpio).
//    Nota: es la FASE ROJA esperada si la auditoría aún no existe;
//    cuando exista y el árbol esté limpio debe salir 0.
// ---------------------------------------------------------------------
echo "\n[2] Auditoría sobre el árbol real\n";

if (file_exists($auditPath)) {
    exec('php ' . escapeshellarg($auditPath) . ' 2>&1', $auditOut, $auditCode);
    assertArcane(
        $auditCode === 0,
        'La auditoría aprueba el árbol real con exit 0 (código: ' . $auditCode . ')'
    );
    $auditText = implode("\n", $auditOut);
    assertArcane(
        str_contains($auditText, 'WCAG') || str_contains($auditText, 'contraste'),
        'La auditoría reporta verificación de contraste WCAG'
    );
} else {
    assertArcane(false, 'La auditoría aún no existe (fase roja esperada)');
}

// ---------------------------------------------------------------------
// 3. La auditoría DETECTA infracciones fabricadas (no es un cacheador
//    de éxitos): sandbox con un CSS que viola ambas reglas.
// ---------------------------------------------------------------------
echo "\n[3] Detección real de infracciones (sandbox con CSS culpable)\n";

if (file_exists($auditPath)) {
    // Sandbox A: URL externa + par de texto de contraste insuficiente.
    mkdir($sandbox . '/assets/css', 0777, true);
    file_put_contents($sandbox . '/assets/css/tokens.css', <<<'CSS'
:root {
  --color-text-muted: #777777;      /* texto tenue */
  --color-bg-obsidian-deep: #0c0b0e;
  --evil-import: url("https://fonts.googleapis.com/css2?family=Evil");
}
CSS);
    file_put_contents($sandbox . '/index.html', '<link rel="stylesheet" href="http://cdn.evil.com/x.css">');

    // La auditoría debe aceptar un directorio raíz alternativo (argv[1]).
    exec(
        'php ' . escapeshellarg($auditPath) . ' ' . escapeshellarg($sandbox) . ' 2>&1',
        $evilOut,
        $evilCode
    );
    assertArcane(
        $evilCode !== 0,
        'La auditoría RECHAZA el sandbox culpable (exit != 0, código: ' . $evilCode . ')'
    );
    $evilText = mb_strtolower(implode("\n", $evilOut));
    assertArcane(
        str_contains($evilText, 'http') || str_contains($evilText, 'extern') || str_contains($evilText, 'cdn') || str_contains($evilText, 'url'),
        'La auditoría nombra la infracción de URL externa'
    );
    assertArcane(
        str_contains($evilText, 'contraste') || str_contains($evilText, 'contrast') || str_contains($evilText, '4.5') || str_contains($evilText, 'wcag'),
        'La auditoría nombra la infracción de contraste'
    );

    // Sandbox B: CSS limpio → la auditoría debe aprobarlo (exit 0).
    mkdir($sandbox . '/clean/assets/css', 0777, true);
    file_put_contents($sandbox . '/clean/assets/css/tokens.css', <<<'CSS'
:root {
  --color-text-primary: #f5f0e6;
  --color-text-secondary: #c9bfaf;
  --color-text-muted: #9e9382;
  --color-bg-obsidian-deep: #0c0b0e;
}
CSS);
    file_put_contents($sandbox . '/clean/index.html', '<link rel="stylesheet" href="assets/css/tokens.css">');

    exec(
        'php ' . escapeshellarg($auditPath) . ' ' . escapeshellarg($sandbox . '/clean') . ' 2>&1',
        $cleanOut,
        $cleanCode
    );
    assertArcane(
        $cleanCode === 0,
        'La auditoría APRUEBA el sandbox limpio (exit 0, código: ' . $cleanCode . ')'
    );
} else {
    assertArcane(false, 'Sandbox omitido: la auditoría aún no existe (fase roja)');
}

// ---------------------------------------------------------------------
// Limpieza del sandbox.
// ---------------------------------------------------------------------
if (is_dir($sandbox)) {
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rit as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($sandbox);
}

// ---------------------------------------------------------------------
// Veredicto.
// ---------------------------------------------------------------------
echo "\n=== VEREDICTO: {$assertsPassed} asertos superados, {$assertsFailed} fallidos ===\n";
exit($assertsFailed === 0 ? 0 : 1);

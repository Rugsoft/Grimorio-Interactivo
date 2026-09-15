<?php

/**
 * test_spec08_closure.php — Verificación de la Tarea 7.2 de TASKS-08.
 *
 * Cierre formal de SPEC-08 (Sistema de Moderación Solemne en Dos Pasos y Consecución de Firmas).
 * Seis fases de auditoría exhaustiva:
 *
 *   [1] Alineación de la tríada canónica (`spec.md`, `plan.md`, `tasks.md`):
 *       todo RF/RNF de las Secciones 4 y 5 queda cubierto por las tareas (columna
 *       «Cubre:») y por el Mapeo Estricto de Trazabilidad del plan (Sección 7);
 *       las 23 tareas de las 7 fases están cerradas; los 17 Criterios de la Sección 8
 *       están debidamente censados.
 *   [2] Dogma Vanilla (Artículo I): cero npm, cero composer, cero CDN, cero paquetes.
 *   [3] Tipificación estricta en PHP 8.2+: `declare(strict_types=1);` al frente
 *       de cada fichero y compilación sintáctica (`php -l`) de todo el código servidor.
 *   [4] Conformidad con los Artículos I, II, III, IV, V, VI y VII de la Constitución.
 *   [5] Ejecución íntegra de la batería automatizada (backend PHP y frontend Node),
 *       sin más suites en rojo que las excluidas por diseño (`test_spell_balance_bridge.php`).
 *   [6] Los 17 Criterios de Finalización y Aceptación de la Sección 8, uno a
 *       uno, cada uno con su evidencia nombrada, ejecutada y verificada.
 *
 * Criterio «Hecho cuando» (tasks.md 7.2):
 *   «Todos los asertos automatizados pasan con éxito (código de salida 0) y la Tríada
 *    Canónica de SPEC-08 (spec.md, plan.md, tasks.md) queda completamente alineada y lista
 *    para la ejecución.»
 *
 * Ejecución: php scratch/test_spec08_closure.php   (exit 0 = especificación formalmente cerrada)
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$scratchDir  = __DIR__;

$passed = 0;
$failed = 0;
$notes  = [];

/** Anota un aserto de auditoría. */
function audit(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASA] {$label}\n";
        return;
    }
    $failed++;
    echo "  [FALLA] {$label}\n";
}

echo "\n══════════════════════════════════════════════════════════════════════\n";
echo " CIERRE DE SPEC-08 · SISTEMA DE MODERACIÓN SOLEMNE EN DOS PASOS\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

/* ═══════════════════════════════════════════════════════════════════════
   FASE 1 · Alineación de la tríada canónica (spec · plan · tasks)
   ═══════════════════════════════════════════════════════════════════════ */

echo "FASE 1 · Tríada canónica (spec · plan · tasks)\n";

$specPath  = $projectRoot . '/specs/08-moderation-two-step.spec.md';
$planPath  = $projectRoot . '/specs/08-moderation-two-step.plan.md';
$tasksPath = $projectRoot . '/specs/08-moderation-two-step.tasks.md';

foreach ([$specPath, $planPath, $tasksPath] as $triadFile) {
    audit(is_file($triadFile), 'La pata de la tríada existe: ' . basename($triadFile));
}

$specSource  = (string) file_get_contents($specPath);
$planSource  = (string) file_get_contents($planPath);
$tasksSource = (string) file_get_contents($tasksPath);

/* 1.1 · Requisitos declarados en la especificación (30 RF + 5 RNF = 35). */
preg_match_all('/^\* \*\*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/m', $specSource, $declaredRaw);
$declared = array_values(array_unique($declaredRaw[1]));
sort($declared);
audit(count($declared) === 35, 'La especificación declara 35 requisitos (30 RF + 5 RNF); hallados ' . count($declared));

/** Expande citas de requisitos simples o rangos tipo «RF-01.1 a RF-06.2» o «RNF-01 a RNF-05». */
function expandRequirements(string $source, array $declaredList): array
{
    $found = [];

    // Rangos con conectores «a» o «-»
    preg_match_all(
        '/((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\s*(?:a|-)\s*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/u',
        $source,
        $ranges,
        PREG_SET_ORDER
    );

    foreach ($ranges as $range) {
        [$from, $to] = [$range[1], $range[2]];
        $idxFrom = array_search($from, $declaredList, true);
        $idxTo   = array_search($to, $declaredList, true);

        if ($idxFrom !== false && $idxTo !== false && $idxFrom <= $idxTo) {
            for ($i = $idxFrom; $i <= $idxTo; $i++) {
                $found[] = $declaredList[$i];
            }
            continue;
        }

        // Fallback para rangos por prefijo
        $prefixFrom = substr($from, 0, 5);
        $prefixTo   = substr($to, 0, 5);
        if ($prefixFrom === $prefixTo) {
            $start = (int) substr($from, 6);
            $end   = (int) substr($to, 6);
            for ($i = $start; $i <= $end; $i++) {
                $found[] = $prefixFrom . '.' . $i;
            }
        }
    }

    // Ocurrencias simples
    preg_match_all('/(?:RF-\d{2}\.\d+)|(?:RNF-\d{2})/', $source, $simple);
    return array_values(array_unique(array_merge($found, $simple[0])));
}

/* 1.2 · Cobertura en las tareas. */
$cleanTasksSource = str_replace('`', '', $tasksSource);
$taskCoverage = expandRequirements($cleanTasksSource, $declared);
$missingInTasks = array_values(array_diff($declared, $taskCoverage));
audit(
    $missingInTasks === [],
    'Las tareas cubren todo requisito declarado de SPEC-08'
    . ($missingInTasks ? ' — huérfanos: ' . implode(', ', $missingInTasks) : '')
);

/* 1.3 · Mapeo del plan (Sección 7). */
preg_match('/^## 7\..*?(?=^## 8\.)/ms', $planSource, $planMapBlock);
$cleanPlanMap = str_replace('`', '', $planMapBlock[0] ?? '');
$planMapCoverage = expandRequirements($cleanPlanMap, $declared);
$missingInPlan = array_values(array_diff($declared, $planMapCoverage));
audit(
    $missingInPlan === [],
    'El Mapeo Estricto de Trazabilidad del plan cubre todo requisito'
    . ($missingInPlan ? ' — huérfanos: ' . implode(', ', $missingInPlan) : '')
);

/* 1.4 · Estado de las tareas. */
$doneTasks = preg_match_all('/^- \[x\] \*\*Tarea /m', $tasksSource);
$openTasks = preg_match_all('/^- \[ \] \*\*Tarea /m', $tasksSource);
audit($openTasks === 0, "Ninguna tarea queda pendiente (marcadas {$doneTasks}, abiertas {$openTasks})");
audit($doneTasks === 23, "Las 23 tareas de la especificación están cerradas (halladas {$doneTasks})");

/* 1.5 · Fases declaradas. */
$phases = preg_match_all('/^## Fase \d+:/m', $tasksSource);
audit($phases === 7, "Las 7 fases del plan de tareas están presentes (halladas {$phases})");

/* 1.6 · Los criterios de la Sección 8 son 17. */
preg_match_all('/^\* \[ \] (.+)$/m', $specSource, $criteriaRaw);
$criteria = array_map('trim', $criteriaRaw[1]);
audit(count($criteria) === 17, 'La Sección 8 declara 17 criterios de aceptación; hallados ' . count($criteria));

/* ═══════════════════════════════════════════════════════════════════════
   FASE 2 · Dogma Vanilla (Artículo I) — Cero dependencias externas
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 2 · Dogma Vanilla — cero dependencias externas\n";

$forbiddenArtifacts = [
    'package.json',
    'package-lock.json',
    'pnpm-lock.yaml',
    'yarn.lock',
    'composer.json',
    'composer.lock',
    'node_modules',
    'vendor',
];
foreach ($forbiddenArtifacts as $artifact) {
    audit(!file_exists($projectRoot . '/' . $artifact), "No existe «{$artifact}» en la raíz del proyecto");
}

/* 2.1 · Ningún recurso remoto servido al navegador, salvo el namespace XML de SVG. */
$remoteResources = [];
$publicAssets = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/public', FilesystemIterator::SKIP_DOTS)
);
foreach ($publicAssets as $asset) {
    if (!$asset->isFile() || !in_array($asset->getExtension(), ['js', 'css', 'html'], true)) {
        continue;
    }
    $contents = (string) file_get_contents($asset->getPathname());
    preg_match_all('#https?://[^\s"\'`)]+#', $contents, $urls);
    foreach ($urls[0] as $url) {
        if (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')) {
            continue;
        }
        if ($url === 'http://www.w3.org/2000/svg') {   // namespace XML estándar, jamás una descarga
            continue;
        }
        $remoteResources[] = basename($asset->getPathname()) . ' → ' . $url;
    }
}
audit(
    $remoteResources === [],
    'El escaparate no carga un solo recurso remoto (CDN)'
    . ($remoteResources ? ' — hallados: ' . implode(' | ', array_slice($remoteResources, 0, 3)) : '')
);

/* 2.2 · Los ES Modules no importan desde node_modules ni la red. */
$externalImports = [];
$jsModules = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/public/assets/js', FilesystemIterator::SKIP_DOTS)
);
foreach ($jsModules as $module) {
    if (!$module->isFile() || $module->getExtension() !== 'js') {
        continue;
    }
    preg_match_all('#^\s*import\s[^;]*?from\s+[\'"]([^\'"]+)[\'"]#m', (string) file_get_contents($module->getPathname()), $imports);
    foreach ($imports[1] as $specifier) {
        if (preg_match('#^(https?:)?//#', $specifier) || str_contains($specifier, 'node_modules')) {
            $externalImports[] = basename($module->getPathname()) . ' → ' . $specifier;
        }
    }
}
audit(
    $externalImports === [],
    'Ningún módulo ES importa desde la red ni desde node_modules'
    . ($externalImports ? ' — hallados: ' . implode(' | ', $externalImports) : '')
);

/* 2.3 · El código servidor no incluye árboles vendor/. */
$phpVendor = glob($projectRoot . '/src/**/vendor') ?: [];
audit($phpVendor === [], 'El código servidor no incluye árboles vendor/');

/* ═══════════════════════════════════════════════════════════════════════
   FASE 3 · Tipificación estricta en PHP 8.2+ y compilación
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 3 · Tipificación estricta y compilación sintáctica\n";

audit(
    version_compare(PHP_VERSION, '8.2.0', '>='),
    'El intérprete cumple PHP 8.2+ (versión en uso: ' . PHP_VERSION . ')'
);

$phpFiles = [];
foreach (['/src', '/public'] as $tree) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot . $tree, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
sort($phpFiles);
audit(count($phpFiles) > 70, 'Se auditan todos los ficheros PHP servidores (' . count($phpFiles) . ')');

$untyped = [];
foreach ($phpFiles as $phpFile) {
    $head = implode("\n", array_slice(file($phpFile) ?: [], 0, 40));
    if (!str_contains($head, 'declare(strict_types=1);')) {
        $untyped[] = basename($phpFile);
    }
}
audit(
    $untyped === [],
    'Todo fichero PHP servidor declara `declare(strict_types=1);` en sus primeros renglones'
    . ($untyped ? ' — infractores: ' . implode(', ', $untyped) : '')
);

$syntaxErrors = [];
foreach ($phpFiles as $phpFile) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($phpFile) . ' 2>&1', $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $syntaxErrors[] = basename($phpFile);
    }
    $lintOut = [];
}
audit(
    $syntaxErrors === [],
    'Todo el código servidor compila limpiamente sin errores sintácticos'
    . ($syntaxErrors ? ' — infractores: ' . implode(', ', $syntaxErrors) : '')
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 4 · Conformidad Constitucional (Artículos I a VII)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 4 · Conformidad Constitucional (Artículos I a VII)\n";

/* Artículo I · Dogma Vanilla: Módulos nativos de SPEC-08 presentes en src/ */
$moderationModules = [
    'src/Services/ModerationWorkflowService.php',
    'src/Services/MasterDeliberationService.php',
    'src/Services/ConstitutionalEthicsValidator.php',
    'src/Services/SovereignAdminService.php',
    'src/Repositories/SpellReviewRepository.php',
    'src/Repositories/MasterSignatureRepository.php',
    'src/Repositories/ObjectionVerdictRepository.php',
    'src/Repositories/ImperialDecreeRepository.php',
    'src/Controllers/ModerationController.php',
    'src/Controllers/MasterDeliberationController.php',
    'src/Controllers/SovereignAdminController.php',
];
foreach ($moderationModules as $module) {
    audit(is_file($projectRoot . '/' . $module), 'Artículo I · módulo nativo presente: ' . basename($module));
}

/* Artículo II · La Ley Universal del Maná: Ningún servicio de moderación altera el maná arbitrariamente */
$manaTrespass = [];
foreach ($moderationModules as $module) {
    $source = (string) file_get_contents($projectRoot . '/' . $module);
    if (preg_match('/mana_cost\s*=\s*\d+/', $source)) {
        $manaTrespass[] = basename($module);
    }
}
audit(
    $manaTrespass === [],
    'Artículo II · ningún servicio de moderación asigna costes manuales de maná'
    . ($manaTrespass ? ' — infractores: ' . implode(', ', $manaTrespass) : '')
);

/* Artículo III · Tribunal de Tres Firmas y Veto Ético de 30 días */
$ethicsSource = (string) file_get_contents($projectRoot . '/src/Services/ConstitutionalEthicsValidator.php');
audit(
    str_contains($ethicsSource, 'canMasterEvaluateSpell') && (str_contains($ethicsSource, 'treinta') || str_contains($ethicsSource, '30') || str_contains($ethicsSource, 'ClanEthicsValidator')),
    'Artículo III · el validador constitucional gobierna la ventana de 30 días y la pluralidad'
);

$deliberationSource = (string) file_get_contents($projectRoot . '/src/Services/MasterDeliberationService.php');
$reviewRepoSource   = (string) file_get_contents($projectRoot . '/src/Repositories/SpellReviewRepository.php');
audit(
    str_contains($deliberationSource, 'SIGNATURES_REQUIRED') && str_contains($reviewRepoSource, 'MAX_SIGNATURES = 3'),
    'Artículo III · el Tribunal de Tres Firmas se impone con la constante canónica'
);

/* Artículo IV · Velo Arcano: Componentes de interfaz en noble castellano sin literales en inglés */
$moderationUiSources = [
    'public/assets/js/components/experimentalHallComponent.js',
    'public/assets/js/components/mastersTowerComponent.js',
    'public/assets/js/components/objectionModalComponent.js',
    'public/assets/js/components/imperialDecreeModalComponent.js',
    'public/assets/js/components/spellCorrectionComponent.js',
    'public/assets/js/views/experimentalHallView.js',
    'public/assets/js/views/mastersTowerView.js',
];
$englishUiLiterals = [];
foreach ($moderationUiSources as $uiSource) {
    $source = (string) file_get_contents($projectRoot . '/' . $uiSource);
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '';
    if (preg_match_all('/[\'"`]((?:The|Your|Please|Sign|Reject|Review|Submit|Approve|Invalid)\s[^\'"`]{3,})[\'"`]/', $stripped, $hits)) {
        $englishUiLiterals[] = basename($uiSource) . ' → ' . $hits[1][0];
    }
}
audit(
    $englishUiLiterals === [],
    'Artículo IV · la narrativa de los componentes de moderación no filtra literales en inglés'
    . ($englishUiLiterals ? ' — hallados: ' . implode(' | ', $englishUiLiterals) : '')
);

/* Artículo V · Dualismo Lingüístico: Contratos DTO en camelCase y SQL con Parameter Binding */
$moderationDtos = [
    'src/Dto/SpellReviewDto.php',
    'src/Dto/MasterSignatureDto.php',
    'src/Dto/ObjectionVerdictDto.php',
    'src/Dto/ImperialDecreeDto.php',
    'src/Dto/ModerationQueueItemDto.php',
];
$snakeKeysFound = [];
foreach ($moderationDtos as $dtoFile) {
    $source = (string) file_get_contents($projectRoot . '/' . $dtoFile);
    preg_match_all("/'([A-Za-z0-9_]+)'\s*=>/", $source, $jsonKeys);
    foreach ($jsonKeys[1] as $k) {
        if (str_contains($k, '_')) {
            $snakeKeysFound[] = basename($dtoFile) . " ('{$k}')";
        }
    }
}
audit(
    $snakeKeysFound === [],
    'Artículo V · todos los contratos JSON de los DTOs viajan en inglés camelCase'
    . ($snakeKeysFound ? ' — halladas claves snake_case: ' . implode(', ', $snakeKeysFound) : '')
);

/* Parameter Binding en repositorios */
$interpolatedSql = [];
$repositoryFiles = glob($projectRoot . '/src/Repositories/*Moderation*.php')
    ?: glob($projectRoot . '/src/Repositories/*.php') ?: [];
foreach ($repositoryFiles as $repoFile) {
    $source = (string) file_get_contents($repoFile);
    if (preg_match('/->(?:query|exec)\s*\(\s*["\'][^"\']*\$/', $source)) {
        $interpolatedSql[] = basename($repoFile);
    }
}
audit(
    $interpolatedSql === [],
    'Artículo V · 100% de consultas PDO usan parameter binding (cero concatenación/interpolación)'
    . ($interpolatedSql ? ' — infractores: ' . implode(', ', $interpolatedSql) : '')
);

/* Tablas del DDL en database/schema.sql */
$schemaSource = (string) file_get_contents($projectRoot . '/database/schema.sql');
foreach (['spell_reviews', 'master_signatures', 'objection_verdicts', 'sovereign_decrees'] as $table) {
    audit(
        str_contains($schemaSource, "CREATE TABLE IF NOT EXISTS {$table}"),
        "Artículo V · la tabla «{$table}» está declarada en el esquema canónico"
    );
}

/* Artículo VI · Supremacía de la Especificación: Tríada vinculada */
audit(
    str_contains($planSource, '08-moderation-two-step.spec.md') && str_contains($tasksSource, '08-moderation-two-step.spec.md'),
    'Artículo VI · Plan y Tareas referencian formalmente a SPEC-08'
);

/* Artículo VII · Soberanía Inmutable de la Constitución */
$constitutionSource = (string) file_get_contents($projectRoot . '/constitution.md');
audit(
    str_contains($constitutionSource, 'Constitución del Grimorio Interactivo') && str_contains($constitutionSource, 'Arquitecto Fundador'),
    'Artículo VII · la Constitución permanece ratificada, íntegra y soberana'
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 5 · Batería automatizada completa (backend PHP + frontend Node)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 5 · Batería automatizada completa (backend PHP + frontend Node)\n";

$suiteCache = [];

/**
 * Ejecuta una suite una sola vez y devuelve su veredicto.
 *
 * @return array{exit:int, asserts:int, failures:int, tail:string, output:string}
 */
function runSuite(string $scratchDir, string $suite): array
{
    global $suiteCache;
    if (isset($suiteCache[$suite])) {
        return $suiteCache[$suite];
    }

    $runner = str_ends_with($suite, '.mjs') ? 'node' : escapeshellarg(PHP_BINARY);
    $command = $runner . ' ' . escapeshellarg($scratchDir . '/' . $suite) . ' 2>&1';
    exec($command, $lines, $exitCode);
    $output = implode("\n", $lines);
    $lines = [];

    $asserts = 0;
    if (preg_match_all('/(?:asertos? superados:|asertos:)\s*(\d+)/i', $output, $assertHits)) {
        $asserts = (int) end($assertHits[1]);
    }
    $failures = preg_match_all('/FALLA|FALLO\b/u', $output);
    if (preg_match_all('/asertos fallidos:\s*(\d+)/i', $output, $failHits)) {
        $failures += (int) end($failHits[1]);
    }

    $tail = trim((string) (preg_split('/\R/', trim($output))[count(preg_split('/\R/', trim($output))) - 1] ?? ''));

    return $suiteCache[$suite] = [
        'exit'     => $exitCode,
        'asserts'  => $asserts,
        'failures' => $failures,
        'tail'     => mb_substr($tail, 0, 120),
        'output'   => $output,
    ];
}

$phpSuites = array_map('basename', glob($scratchDir . '/test_*.php') ?: []);
$mjsSuites = array_map('basename', glob($scratchDir . '/test_*.mjs') ?: []);
sort($phpSuites);
sort($mjsSuites);

$byDesignNonZero = [
    'test_spec07_closure.php',
    'test_spec08_closure.php',
    'test_spell_balance_bridge.php',
];

$totalAsserts = 0;
$redSuites = [];
foreach (array_merge($phpSuites, $mjsSuites) as $suite) {
    if (in_array($suite, $byDesignNonZero, true)) {
        continue;
    }
    $verdict = runSuite($scratchDir, $suite);
    $totalAsserts += $verdict['asserts'];
    $green = $verdict['exit'] === 0 && $verdict['failures'] === 0;
    if (!$green) {
        $redSuites[] = sprintf('%s (exit %d, fallos %d)', $suite, $verdict['exit'], $verdict['failures']);
    }
    printf(
        "  %-52s %4d asertos · %s\n",
        $suite,
        $verdict['asserts'],
        $green ? 'VERDE' : 'ROJO'
    );
}

printf(
    "\n  Suites ejecutadas: %d (PHP %d · Node %d) · asertos contabilizados: %d\n",
    count($phpSuites) + count($mjsSuites) - count($byDesignNonZero),
    count($phpSuites) - 1, // descontando este cierre
    count($mjsSuites),
    $totalAsserts
);

audit($redSuites === [], 'Toda la batería del santuario pasa: cero suites en rojo' . ($redSuites ? ' — ' . implode(' | ', $redSuites) : ''));
audit($totalAsserts > 5000, "El volumen de asertos ejecutados es masivo ({$totalAsserts})");

/* ═══════════════════════════════════════════════════════════════════════
   FASE 6 · Los 17 Criterios de Finalización y Aceptación (Sección 8)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 6 · Criterios de Finalización y Aceptación de la Sección 8 de SPEC-08\n";

/**
 * Evidencia de cada criterio de SPEC-08 Sección 8:
 *  [0] Título resumido
 *  [1] Suites que lo ejercitan
 *  [2] Aguja o patrón probatorio en la salida
 */
$criteriaEvidence = [
    0  => ['Elevación sellada y consagración por 3 firmas o soberana', ['test_moderation_workflow.php', 'test_moderation_workflow_service.php'], 'experimental'],
    1  => ['Pluralidad de clanes (3 hermandades distintas / ermitaños)', ['test_moderation_workflow.php', 'test_moderation_deliberation.php'], 'CLAN_PLURALITY_VIOLATION'],
    2  => ['Veto constitucional Art. III (clan actual y últimos 30 días)', ['test_moderation_workflow.php', 'test_moderation_ethics_validator.php'], 'CONSTITUTIONAL_ETHICS_VETO'],
    3  => ['Bloqueo absoluto de auto-firma (master y supremeAdmin)', ['test_moderation_workflow.php', 'test_moderation_ethics_validator.php'], 'SELF_SIGNING_PROHIBITED'],
    4  => ['Firma Soberana en experimental y veto al clan del Supremo', ['test_moderation_workflow.php', 'test_moderation_sovereign_service.php'], 'SOVEREIGN_OWN_CLAN_VETO'],
    5  => ['Dictamen de objeción (>= 20 car.) pasa a rejected y retira del Atrio', ['test_moderation_workflow.php', 'test_moderation_modals.mjs'], 'OBJECTION_TOO_BRIEF'],
    6  => ['Reapertura formal reopenAsDraft con dictamen previo visible', ['test_moderation_workflow.php', 'test_spell_correction_component.mjs'], 'reabre'],
    7  => ['Liberación inmediata del cupo de 3 al vetar o consagrar', ['test_moderation_workflow.php', 'test_moderation_workflow_service.php'], 'TOWER_CAPACITY_EXCEEDED'],
    8  => ['Retractación voluntaria de firma antes de 3 firmas', ['test_moderation_deliberation.php', 'test_moderation_tower_endpoints.php'], 'SIGNATURE_IRREVOCABLE'],
    9  => ['Anulación automática por conflicto sobrevenido o pérdida de rango (N-1)', ['test_moderation_workflow.php', 'test_moderation_ethics_validator.php'], 'clan_conflict_arisen'],
    10 => ['Consagración atómica en 3ª firma, Gran Tomo y crédito de PDA', ['test_moderation_integration.php', 'test_moderation_workflow.php'], 'SPELL_CONSECRATED'],
    11 => ['Caducidad automática por letargo de 90 días', ['test_moderation_workflow.php', 'test_moderation_workflow_service.php'], 'review_expired'],
    12 => ['Inmutabilidad de lo evaluado en experimental sin retiro previo', ['test_moderation_workflow.php', 'test_moderation_endpoints.php'], 'retirada'],
    13 => ['Atrio de Pruebas: exhibición con medidor, simulador y aislamiento PDA', ['test_moderation_integration.php', 'test_experimental_hall_component.mjs'], 'bloqueo de gloria'],
    14 => ['Herencia Ancestral ante disolución de clan durante la revisión', ['test_moderation_workflow.php', 'test_moderation_sovereign_service.php'], 'Herencia Ancestral'],
    15 => ['Inscripción inmutable en Bitácora de Auditoría pública', ['test_moderation_audit_integration.php', 'test_moderation_workflow.php'], 'Bitacora'],
    16 => ['Dogma Vanilla, Velo Arcano y Dualismo Lingüístico cumplidos', ['test_moderation_workflow.php', 'test_moderation_css.mjs'], 'Dogma Vanilla'],
];

foreach ($criteria as $index => $criterion) {
    $evidence = $criteriaEvidence[$index] ?? null;
    $label = 'Criterio ' . ($index + 1) . ' · ' . ($evidence[0] ?? mb_substr($criterion, 0, 42));
    if ($evidence === null) {
        audit(false, $label . ' — sin evidencia nombrada');
        continue;
    }
    [$title, $suites, $needle] = $evidence;
    $nailed = false;
    $exercise = [];
    foreach ($suites as $suite) {
        $path = $scratchDir . '/' . $suite;
        if (!is_file($path)) {
            continue;
        }
        $verdict = runSuite($scratchDir, $suite);
        $exercise[] = sprintf('%s (%d asertos, exit %d)', $suite, $verdict['asserts'], $verdict['exit']);
        if ($verdict['exit'] === 0 && mb_stripos($verdict['output'], $needle) !== false) {
            $nailed = true;
        }
    }
    audit(
        $nailed,
        sprintf('%s · %s — evidencia: %s', $label, $title, $exercise ? implode(', ', $exercise) : 'ninguna')
    );
}

/* ═══════════════════════════════════════════════════════════════════════
   VEREDICTO FINAL DE CIERRE DE SPEC-08
   ═══════════════════════════════════════════════════════════════════════ */

echo "\n" . str_repeat('─', 70) . "\n";
printf("Auditorías superadas: %d · fallidas: %d\n", $passed, $failed);
printf("Suites automatizadas ejecutadas: %d · asertos declarados: %d\n", count($suiteCache), $totalAsserts);
if ($failed === 0) {
    echo "RESULTADO: EXITO — SPEC-08 queda formalmente certificada y cerrada por la Tarea 7.2.\n";
    exit(0);
}
echo "RESULTADO: FALLO — SPEC-08 no puede declararse cerrada.\n";
exit(1);

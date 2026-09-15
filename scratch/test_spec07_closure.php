<?php

/**
 * test_spec07_closure.php — Verificación de la Tarea 7.2 de TASKS-07.
 *
 * Cierre formal de SPEC-07 (Clanes, Linajes y Dominio Semanal). Seis fases:
 *
 *   [1] Alineación de la tríada canónica (`spec.md`, `plan.md`, `tasks.md`):
 *       todo RF/RNF de la Sección 5 queda cubierto por una tarea (columna
 *       «Cubre») y por el Mapeo Estricto de Trazabilidad del plan (Sección 7);
 *       las 22 tareas están marcadas y ninguna queda pendiente.
 *   [2] Dogma Vanilla (Artículo I): cero npm, cero composer, cero CDN.
 *   [3] Tipificación estricta en PHP 8.2+: `declare(strict_types=1);` al frente
 *       de cada fichero y compilación sintáctica de todo el código servidor.
 *   [4] Conformidad con los Artículos I, II, III, IV y V de la Constitución.
 *   [5] Ejecución íntegra de la batería automatizada (backend PHP y frontend
 *       Node), sin más salida no-cero que la que el repositorio declara
 *       «por diseño».
 *   [6] Los 19 Criterios de Finalización y Aceptación de la Sección 8, uno a
 *       uno, cada uno con su evidencia nombrada y ejecutada.
 *
 * Criterio «Hecho cuando» (tasks.md 7.2):
 *   Todos los asertos automatizados pasan con éxito y se confirma que la
 *   tríada canónica de SPEC-07 se encuentra totalmente alineada.
 *
 * Ejecución: php scratch/test_spec07_closure.php   (exit 0 = especificación cerrada)
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
echo " CIERRE DE SPEC-07 · SISTEMA DE CLANES, LINAJES Y DOMINIO SEMANAL\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

/* ═══════════════════════════════════════════════════════════════════════
   FASE 1 · Alineación de la tríada canónica
   ═══════════════════════════════════════════════════════════════════════ */

echo "FASE 1 · Tríada canónica (spec · plan · tasks)\n";

$specPath  = $projectRoot . '/specs/07-clans-lineages.spec.md';
$planPath  = $projectRoot . '/specs/07-clans-lineages.plan.md';
$tasksPath = $projectRoot . '/specs/07-clans-lineages.tasks.md';

foreach ([$specPath, $planPath, $tasksPath] as $triadFile) {
    audit(is_file($triadFile), 'La pata de la tríada existe: ' . basename($triadFile));
}

$specSource  = (string) file_get_contents($specPath);
$planSource  = (string) file_get_contents($planPath);
$tasksSource = (string) file_get_contents($tasksPath);

/* 1.1 · Requisitos declarados en la Sección 5 de la especificación. */
preg_match_all('/^\* \*\*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/m', $specSource, $declaredRaw);
$declared = array_values(array_unique($declaredRaw[1]));
sort($declared);
audit(count($declared) === 34, 'La Sección 5 declara 34 requisitos (29 RF + 5 RNF); hallados ' . count($declared));

/** Expande citas del tipo «RF-01.1 a RF-01.7» o «RF-02.1 - RF-02.3». */
function expandRequirements(string $source): array
{
    $found = [];
    // Rangos explícitos primero, para que «RF-01.1 a RF-01.7» sume los 7.
    preg_match_all(
        '/((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\s*(?:a|-)\s*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/u',
        $source,
        $ranges,
        PREG_SET_ORDER
    );
    foreach ($ranges as $range) {
        [$from, $to] = [$range[1], $range[2]];
        if (str_starts_with($from, 'RNF') && str_starts_with($to, 'RNF')) {
            for ($i = (int) substr($from, 4); $i <= (int) substr($to, 4); $i++) {
                $found[] = sprintf('RNF-%02d', $i);
            }
            continue;
        }
        $prefix = substr($from, 0, 5);              // «RF-01»
        if ($prefix !== substr($to, 0, 5)) {
            continue;
        }
        for ($i = (int) substr($from, 6); $i <= (int) substr($to, 6); $i++) {
            $found[] = $prefix . '.' . $i;
        }
    }
    preg_match_all('/(?:RF-\d{2}\.\d+)|(?:RNF-\d{2})/', $source, $simple);
    return array_values(array_unique(array_merge($found, $simple[0])));
}

/* 1.2 · Cobertura en las tareas. */
$taskCoverage = expandRequirements($tasksSource);
$missingInTasks = array_values(array_diff($declared, $taskCoverage));
audit(
    $missingInTasks === [],
    'Las tareas cubren todo requisito de la Sección 5'
    . ($missingInTasks ? ' — huérfanos: ' . implode(', ', $missingInTasks) : '')
);

/* 1.3 · Mapeo del plan (Sección 7). */
preg_match('/^## 7\..*?(?=^## 8\.)/ms', $planSource, $planMapBlock);
$planMapCoverage = expandRequirements($planMapBlock[0] ?? '');
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
audit($doneTasks === 22, "Las 22 tareas de la especificación están cerradas (halladas {$doneTasks})");

/* 1.5 · Fases declaradas. */
$phases = preg_match_all('/^## Fase \d+:/m', $tasksSource);
audit($phases === 7, "Las 7 fases del plan de tareas están presentes (halladas {$phases})");

/* 1.6 · Los criterios de la Sección 8 son 19. */
preg_match_all('/^\* \[ \] (.+)$/m', $specSource, $criteriaRaw);
$criteria = array_map('trim', $criteriaRaw[1]);
audit(count($criteria) === 20, 'La Sección 8 declara 20 criterios de aceptación; hallados ' . count($criteria));

/* ═══════════════════════════════════════════════════════════════════════
   FASE 2 · Dogma Vanilla (Artículo I)
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

/* 2.1 · Ningún recurso remoto servido al navegador, salvo el namespace SVG. */
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
        if ($url === 'http://www.w3.org/2000/svg') {   // namespace XML, jamás una descarga
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

/* 2.2 · Los ES Modules no importan especímenes externos. */
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

/* 2.3 · El santuario no arrastra paquetes de terceros. */
$phpVendor = glob($projectRoot . '/src/**/vendor') ?: [];
audit($phpVendor === [], 'El código servidor no incluye árboles vendor/');

/* ═══════════════════════════════════════════════════════════════════════
   FASE 3 · Tipificación estricta en PHP 8.2+
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 3 · Tipificación estricta y compilación\n";

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
audit(count($phpFiles) > 60, 'Se auditan todos los ficheros PHP servidores (' . count($phpFiles) . ')');

$untyped = [];
foreach ($phpFiles as $phpFile) {
    $head = implode("\n", array_slice(file($phpFile) ?: [], 0, 40));
    if (!str_contains($head, 'declare(strict_types=1);')) {
        $untyped[] = basename($phpFile);
    }
}
audit(
    $untyped === [],
    'Todo fichero PHP declara `strict_types=1` en sus primeros 40 renglones'
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
    'Todo el código servidor compila sin errores sintácticos'
    . ($syntaxErrors ? ' — infractores: ' . implode(', ', $syntaxErrors) : '')
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 4 · Conformidad constitucional (Artículos I a V)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 4 · Constitución — Artículos I a V\n";

/* Artículo I · Dogma Vanilla: ya acreditado en la Fase 2 para todo el dominio. */
$clanModules = [
    'src/Services/LineageSynergyService.php',
    'src/Services/ClanService.php',
    'src/Services/WeeklyDominionService.php',
    'src/Services/ClanEthicsValidator.php',
];
foreach ($clanModules as $module) {
    audit(is_file($projectRoot . '/' . $module), 'Artículo I · módulo nativo presente: ' . basename($module));
}

/* Artículo II · La Ley Universal del Maná: los linajes JAMÁS tocan el coste. */
$manaTrespass = [];
foreach ($clanModules as $module) {
    $source = (string) file_get_contents($projectRoot . '/' . $module);
    if (preg_match('/SpellBalanceService|ManaBalance|manaCost\s*[=*\/]/', $source)) {
        $manaTrespass[] = basename($module);
    }
}
audit(
    $manaTrespass === [],
    'Artículo II · ningún servicio de clanes altera el coste de maná'
    . ($manaTrespass ? ' — infractores: ' . implode(', ', $manaTrespass) : '')
);

/* Artículo III · Veto ético de 30 días en la Torre. */
$ethicsSource = (string) file_get_contents($projectRoot . '/src/Services/ClanEthicsValidator.php');
audit(
    str_contains($ethicsSource, '30') && preg_match('/veto|conflict|evaluate/i', $ethicsSource) === 1,
    'Artículo III · el veto ético de 30 días vive en ClanEthicsValidator'
);

/* Artículo IV · Velo Arcano: la interfaz habla en noble castellano. */
$clanUiSources = [
    'public/assets/js/components/clanManagementComponent.js',
    'public/assets/js/components/lineageHallComponent.js',
    'public/assets/js/views/clanView.js',
    'public/assets/js/views/lineageHallView.js',
];
$englishUiLiterals = [];
foreach ($clanUiSources as $uiSource) {
    $source = (string) file_get_contents($projectRoot . '/' . $uiSource);
    // Literales de interfaz sospechosos: frases en inglés con espacios, fuera de comentarios.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '';
    if (preg_match_all('/[\'"`]((?:The|Your|Please|Join|Leave|Members?|Ranking|Invalid)\s[^\'"`]{3,})[\'"`]/', $stripped, $hits)) {
        $englishUiLiterals[] = basename($uiSource) . ' → ' . $hits[1][0];
    }
}
audit(
    $englishUiLiterals === [],
    'Artículo IV · la narrativa de la interfaz no filtra literales en inglés'
    . ($englishUiLiterals ? ' — hallados: ' . implode(' | ', $englishUiLiterals) : '')
);

/* Artículo V · Dualismo Lingüístico. */
$interpolatedSql = [];
$repositoryFiles = glob($projectRoot . '/src/Repositories/*.php') ?: [];
foreach ($repositoryFiles as $repositoryFile) {
    $source = (string) file_get_contents($repositoryFile);
    // Interpolación de variables dentro de una consulta: violación del binding.
    if (preg_match('/->(?:query|exec)\s*\(\s*["\'][^"\']*\$/', $source)) {
        $interpolatedSql[] = basename($repositoryFile);
    }
}
audit(
    $interpolatedSql === [],
    'Artículo V · ningún repositorio interpola variables en SQL (solo parameter binding)'
    . ($interpolatedSql ? ' — infractores: ' . implode(', ', $interpolatedSql) : '')
);

$clanDtoSource = (string) file_get_contents($projectRoot . '/src/Dto/ClanDto.php');
preg_match_all("/'([A-Za-z_]+)'\s*=>/", $clanDtoSource, $jsonKeys);
$snakeKeys = array_values(array_filter($jsonKeys[1], fn (string $k): bool => str_contains($k, '_')));
audit(
    $snakeKeys === [],
    'Artículo V · el contrato JSON viaja en inglés camelCase'
    . ($snakeKeys ? ' — halladas claves snake_case: ' . implode(', ', $snakeKeys) : '')
);

$schemaSource = (string) file_get_contents($projectRoot . '/database/schema.sql');
$legacyMigrationSource = (string) file_get_contents($projectRoot . '/sql/07_clans_lineages_schema.sql');
foreach (['clans', 'clan_members', 'clan_applications', 'weekly_cycles', 'daily_simulator_tracker'] as $table) {
    audit(
        str_contains($schemaSource, "CREATE TABLE IF NOT EXISTS {$table}"),
        "Artículo V · la tabla «{$table}» se crea con su nombre canónico"
    );
}
audit(
    str_contains($schemaSource, 'weekly_points') && str_contains($schemaSource, 'historical_points'),
    'Artículo V · las columnas del plano persisten en snake_case inglés'
);
audit(
    is_file($projectRoot . '/sql/07_clans_lineages_schema.sql')
    && str_contains($legacyMigrationSource, 'SÓLO PARA BASES LEGADAS'),
    'Artículo V · la vía de ascenso de bases legadas queda declarada como migración incremental'
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 5 · Ejecución íntegra de la batería automatizada
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 5 · Batería automatizada (backend PHP + frontend Node)\n";

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
    $command = 'timeout 300 ' . $runner . ' ' . escapeshellarg($scratchDir . '/' . $suite) . ' 2>&1';
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

/** Salidas no-cero que el repositorio declara deliberadas. */
$byDesignNonZero = ['test_spec07_closure.php', 'test_spell_balance_bridge.php'];

$totalAsserts = 0;
$redSuites = [];
foreach (array_merge($phpSuites, $mjsSuites) as $suite) {
    if (in_array($suite, $byDesignNonZero, true)) {
        continue;
    }
    if ($suite === 'test_spec07_closure.php') {
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
    "\n  Suites ejecutadas: %d (PHP %d · Node %d) · asertos declarados: %d\n",
    count($phpSuites) + count($mjsSuites) - count($byDesignNonZero),
    count($phpSuites),
    count($mjsSuites),
    $totalAsserts
);

audit($redSuites === [], 'Toda la batería pasa: cero suites en rojo' . ($redSuites ? ' — ' . implode(' | ', $redSuites) : ''));
audit($totalAsserts > 3000, "El volumen de asertos ejecutados es sustancial ({$totalAsserts})");

/* ═══════════════════════════════════════════════════════════════════════
   FASE 6 · Criterios de Finalización y Aceptación (Sección 8)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 6 · Criterios de Finalización y Aceptación de la Sección 8\n";

/**
 * Evidencia nombrada de cada criterio: suites que lo ejercitan y la aguja
 * que debe aparecer en su seno para que la coartada no sea hueca.
 */
$criteriaEvidence = [
    0  => ['Pertenencia única', ['test_membership_single_source.php', 'test_clans_lineages_schema.php'], 'afiliac'],
    1  => ['Cupo de treinta adeptos', ['test_clan_service.php'], 'treinta'],
    2  => ['Régimen de admisión y 3 solicitudes', ['test_clan_application_cycle_repositories.php'], 'solicitud'],
    3  => ['Fundación canónica', ['test_clan_controller_endpoints.php'], 'blasón'],
    4  => ['Convalecencia de 14 días', ['test_clan_service.php', 'test_convalescence_banner_component.mjs'], 'convalecencia'],
    5  => ['Historial y veto de 30 días', ['test_clan_ethics_validator.php'], '30'],
    6  => ['Sucesión a los 45 días', ['test_clan_service.php'], 'cuarenta y cinco'],
    7  => ['Los 8 Linajes Canónicos', ['test_lineage_synergy_service.php'], 'maná'],
    8  => ['Sinergia del +25% con round()', ['test_lineage_synergy_service.php'], '1.25'],
    9  => ['PDA por Círculo (100 + C × 20)', ['test_weekly_dominion_service.php'], '100'],
    10 => ['Tope de 50 PDA diarios en simulador', ['test_simulator_dominion_bridge.php'], '50'],
    11 => ['Favoritos de la comunidad (+5 PDA)', ['test_weekly_dominion_service.php'], 'favorit'],
    12 => ['Cierre dominical a las 23:59:59 UTC', ['test_weekly_dominion_service.php'], '23:59:59'],
    13 => ['Desempate semanal determinista', ['test_weekly_dominion_service.php'], 'empate'],
    14 => ['Honores del Clan Regente', ['test_clan_banner_component.mjs', 'test_dominion_frontend_bridges.mjs'], 'estandarte'],
    15 => ['Patrimonio inviolable', ['test_clan_legacy.php', 'test_clan_view.mjs'], 'patrimonio'],
    16 => ['Herencia Ancestral de clanes disueltos', ['test_clan_legacy.php', 'test_clan_repository.php'], 'Ancestral'],
    17 => ['Salón de los Linajes', ['test_lineage_hall_component.mjs', 'test_dominion_controller.php'], 'Salón'],
    18 => ['Dogma Vanilla y Dualismo Lingüístico', ['test_audit.php'], 'Dogma'],
    19 => ['Sello Rúnico forjado y heráldica determinista', ['test_rune_seal.mjs', 'test_clan_view.mjs'], 'Sello'],
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
   VEREDICTO
   ═══════════════════════════════════════════════════════════════════════ */

echo "\n" . str_repeat('─', 70) . "\n";
printf("Auditorías superadas: %d · fallidas: %d\n", $passed, $failed);
printf("Suites automatizadas: %d · asertos declarados: %d\n", count($suiteCache), $totalAsserts);
if ($failed === 0) {
    echo "RESULTADO: EXITO — SPEC-07 queda formalmente cerrada por la Tarea 7.2.\n";
    exit(0);
}
echo "RESULTADO: FALLO — SPEC-07 no puede declararse cerrada.\n";
exit(1);

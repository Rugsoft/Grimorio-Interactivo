<?php

/**
 * test_spec10_closure.php — Arnés de cierre formal de SPEC-10 (Tarea 9.3).
 *
 * Ceremonia de Adhesión a Clanes del Propio Linaje: «Vestíbulo de las
 * Hermandades». Seis fases, al estilo de los cierres 07/08:
 *
 *   [1] Alineación de la tríada canónica (spec · plan · tasks): los 29
 *       requisitos declarados en la Sección 4 (23 RF + 6 RNF) quedan
 *       cubiertos por las tareas y por el Mapeo Estricto de Trazabilidad
 *       del plan (Sección 7); las 32 tareas de la ceremonia quedan
 *       cerradas — la propia 9.3 se sella al declarar este arnés el EXITO.
 *   [2] Dogma Vanilla (Artículo I): cero npm, cero composer, cero CDN.
 *   [3] Tipificación estricta en PHP 8.2+ y compilación del código server.
 *   [4] Conformidad constitucional (Artículos I–V) sobre los módulos de la
 *       ceremonia: el Vestíbulo jamás toca el maná (II), los guardias de
 *       adhesión anteceden a la persistencia (III), leyendas en noble
 *       castellano (IV) y SQL exclusivamente preparado (V).
 *   [5] Ejecución íntegra de la batería automatizada (PHP + Node).
 *   [6] Los 13 criterios de finalización y aceptación de la Sección 8 de
 *       la spec, uno a uno, cada uno con su suite de evidencia nombrada y
 *       ejecutada en vivo.
 *
 * Criterio «Hecho cuando» (Tarea 9.3): el arnés declara «SPEC-10 queda
 * formalmente cerrada» con cero suites en rojo y los criterios de la
 * Sección 8 con evidencia nombrada.
 *
 * Ejecución: php scratch/test_spec10_closure.php   (exit 0 = SPEC-10 cerrada)
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$scratchDir  = __DIR__;

$passed = 0;
$failed = 0;

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
echo " CIERRE DE SPEC-10 · CEREMONIA DE ADHESIÓN (VESTÍBULO DE LAS HERMANDADES)\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

/* ═══════════════════════════════════════════════════════════════════════
   FASE 1 · Alineación de la tríada canónica
   ═══════════════════════════════════════════════════════════════════════ */

echo "FASE 1 · Tríada canónica (spec · plan · tasks)\n";

$specPath  = $projectRoot . '/specs/10-clan-adhesion-ceremony.spec.md';
$planPath  = $projectRoot . '/specs/10-clan-adhesion-ceremony.plan.md';
$tasksPath = $projectRoot . '/specs/10-clan-adhesion-ceremony.tasks.md';

foreach ([$specPath, $planPath, $tasksPath] as $triadFile) {
    audit(is_file($triadFile), 'La pata de la tríada existe: ' . basename($triadFile));
}

$specSource  = (string) file_get_contents($specPath);
$planSource  = (string) file_get_contents($planPath);
$tasksSource = (string) file_get_contents($tasksPath);

/* 1.1 · Requisitos declarados en la Sección 4 de la especificación. */
preg_match_all('/^\* \*\*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/m', $specSource, $declaredRaw);
$declared = array_values(array_unique($declaredRaw[1]));
sort($declared);
audit(count($declared) === 29, 'La Sección 4 declara 29 requisitos (23 RF + 6 RNF); hallados ' . count($declared));

/** Expande citas del tipo «RF-01.1–01.7» o «RF-02.1 a RF-02.3». */
function expandRequirements(string $source): array
{
    $found = [];
    preg_match_all(
        '/((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\s*(?:a|-|–)\s*((?:RF-\d{2}(?:\.\d+)?)|(?:RNF-\d{2}))\b/u',
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
        $toIndex = (int) substr($to, 6);
        for ($i = (int) substr($from, 6); $i <= $toIndex; $i++) {
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
    'Las tareas cubren todo requisito de la Sección 4'
    . ($missingInTasks ? ' — huérfanos: ' . implode(', ', $missingInTasks) : '')
);

/* 1.3 · Mapeo del plan (Sección 7, Trazabilidad RF-x / RNF-x ↔ Plan). */
preg_match('/^## 7\..*?(?=^## 8\.|^---$)/ms', $planSource, $planMapBlock);
$planMapCoverage = expandRequirements($planMapBlock[0] ?? '');
$missingInPlan = array_values(array_diff($declared, $planMapCoverage));
audit(
    $missingInPlan === [],
    'El Mapeo Estricto de Trazabilidad del plan cubre todo requisito'
    . ($missingInPlan ? ' — huérfanos: ' . implode(', ', $missingInPlan) : '')
);

/* 1.4 · Estado de las tareas: todas cerradas salvo esta misma 9.3. */
$doneTasks = preg_match_all('/^- \[x\] \*\*Tarea /m', $tasksSource);
$openTasks = preg_match_all('/^- \[ \] \*\*Tarea /m', $tasksSource);
audit($doneTasks === 32, "Las 32 tareas de la ceremonia están cerradas (halladas {$doneTasks})");
audit($openTasks === 0, "Ninguna tarea queda pendiente (abiertas: {$openTasks})");

/* 1.5 · Fases declaradas. */
$phases = preg_match_all('/^## Fase \d+/m', $tasksSource);
audit($phases === 9, "Las 9 fases del plan de tareas están presentes (halladas {$phases})");

/* 1.6 · Los criterios de la Sección 8 son 13. */
preg_match_all('/^\* \[ \] (.+)$/m', $specSource, $criteriaRaw);
$criteria = array_map('trim', $criteriaRaw[1]);
audit(count($criteria) === 13, 'La Sección 8 declara 13 criterios de aceptación; hallados ' . count($criteria));

/* 1.7 · El Anexo A del plan (leyendas solemnes) está ratificado. */
audit(
    str_contains($planSource, '## Anexo A') && str_contains($planSource, '(RATIFICADAS)'),
    'El Anexo A de las leyendas solemnes está ratificado en el plan'
);

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

/* ═══════════════════════════════════════════════════════════════════════
   FASE 3 · Tipificación estricta en PHP 8.2+
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 3 · Tipificación estricta y compilación de los módulos de la ceremonia\n";

$ceremonyPhp = [
    'src/Repositories/ClanApplicationRepository.php',
    'src/Services/ClanVestibuleService.php',
    'src/Services/ClanService.php',
    'src/Controllers/VestibuleController.php',
    'src/Dto/VestibuleClanDto.php',
    'src/Exceptions/ClanGovernanceException.php',
];
$untyped = [];
foreach ($ceremonyPhp as $module) {
    $path = $projectRoot . '/' . $module;
    if (!is_file($path)) {
        $untyped[] = basename($module) . ' (ausente)';
        continue;
    }
    $head = implode("\n", array_slice(file($path) ?: [], 0, 40));
    if (!str_contains($head, 'declare(strict_types=1);')) {
        $untyped[] = basename($module);
    }
}
audit(
    $untyped === [],
    'Todo módulo PHP de la ceremonia declara `strict_types=1`'
    . ($untyped ? ' — infractores: ' . implode(', ', $untyped) : '')
);

$syntaxErrors = [];
foreach ($ceremonyPhp as $module) {
    $path = $projectRoot . '/' . $module;
    if (!is_file($path)) {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $syntaxErrors[] = basename($module);
    }
    $lintOut = [];
}
audit(
    $syntaxErrors === [],
    'Todo el código servidor de la ceremonia compila sin errores'
    . ($syntaxErrors ? ' — infractores: ' . implode(', ', $syntaxErrors) : '')
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 4 · Conformidad constitucional (Artículos I a V)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 4 · Constitución — Artículos I a V sobre la ceremonia\n";

/* Artículo I · Módulos nativos de la ceremonia. */
$ceremonyFrontend = [
    'public/assets/js/api/vestibuleClient.js',
    'public/assets/js/components/vestibuleClanCardComponent.js',
    'public/assets/js/components/admissionModalComponent.js',
    'public/assets/js/components/petitionComposerComponent.js',
    'public/assets/js/components/petitionInventoryComponent.js',
    'public/assets/js/views/vestibuleView.js',
    'public/assets/css/components/vestibule.css',
];
foreach ($ceremonyFrontend as $module) {
    audit(is_file($projectRoot . '/' . $module), 'Artículo I · módulo nativo presente: ' . basename($module));
}

/* Artículo II · El Vestíbulo jamás toca el coste de maná. */
$manaTrespass = [];
foreach (array_merge(
    ['src/Services/ClanVestibuleService.php', 'src/Repositories/ClanApplicationRepository.php'],
    $ceremonyFrontend
) as $module) {
    $source = (string) file_get_contents($projectRoot . '/' . $module);
    if (preg_match('/SpellBalanceService|ManaBalance|manaCost\s*[=*\/]/', $source)) {
        $manaTrespass[] = basename($module);
    }
}
audit(
    $manaTrespass === [],
    'Artículo II · la ceremonia jamás altera el coste de maná'
    . ($manaTrespass ? ' — infractores: ' . implode(', ', $manaTrespass) : '')
);

/* Artículo III · Sustancia blindada: los guardias anteceden a la persistencia. */
$serviceSource = (string) file_get_contents($projectRoot . '/src/Services/ClanService.php');
preg_match(
    '/public function applyToClan\(.*?\{\s*(.*?)requireFreedomFromConvalescence/s',
    $serviceSource,
    $guardOrder
);
audit(
    isset($guardOrder[1])
        && str_contains($guardOrder[1], 'requireOathLineageForGesture')
        && str_contains($guardOrder[1], 'requireEligibleRank'),
    'Artículo III · los guardias (linaje, rango, Supremo) corren ANTES del descanso y la persistencia'
);

$repositorySource = (string) file_get_contents($projectRoot . '/src/Repositories/ClanApplicationRepository.php');
audit(
    str_contains($repositorySource, 'uq_clan_application_house') || str_contains($repositorySource, 'unique'),
    'Artículo III · la clausura por casa se apoya en el índice único físico'
);

/* Artículo IV · Velo Arcano: leyendas en noble castellano, sin jerga técnica. */
$englishUiLiterals = [];
foreach ($ceremonyFrontend as $uiSource) {
    if (!str_ends_with($uiSource, '.js')) {
        continue;
    }
    $source = (string) file_get_contents($projectRoot . '/' . $uiSource);
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '';
    if (preg_match_all('/[\'"]((?:The|Your|Please|Join|Leave|Submit|Invalid|Loading)\s[^\'"]{3,})[\'"]/', $stripped, $hits)) {
        $englishUiLiterals[] = basename($uiSource) . ' → ' . $hits[1][0];
    }
}
audit(
    $englishUiLiterals === [],
    'Artículo IV · la narrativa del Vestíbulo no filtra literales en inglés'
    . ($englishUiLiterals ? ' — hallados: ' . implode(' | ', $englishUiLiterals) : '')
);

/* Artículo IV bis · Sin referencias técnicas de spec visibles al usuario. */
$specRefLeak = [];
$legendSources = [
    'src/Services/ClanVestibuleService.php',
    'public/assets/js/api/vestibuleClient.js',
    'public/assets/js/components/vestibuleClanCardComponent.js',
    'public/assets/js/views/vestibuleView.js',
];
foreach ($legendSources as $legendSource) {
    $source = (string) file_get_contents($projectRoot . '/' . $legendSource);
    $stripped = preg_replace('#/\*.*?\*/|^\s*//.*$|^\s*\*.*$#m', '', $source) ?? '';
    if (preg_match_all('/[\'"]([^\'"]*(?:RF-\d{2}\.\d+|Art\.\s*III)[^\'"]*)[\'"]/', $stripped, $hits)) {
        $specRefLeak[] = basename($legendSource) . ' → ' . mb_substr($hits[1][0], 0, 60);
    }
}
audit(
    $specRefLeak === [],
    'Artículo IV/V · ninguna leyenda visible porta referencias técnicas (RF-x, Art. III)'
    . ($specRefLeak ? ' — hallados: ' . implode(' | ', $specRefLeak) : '')
);

/* Artículo V · SQL exclusivamente preparado en los repositorios de la ceremonia. */
$interpolatedSql = [];
foreach (['src/Repositories/ClanApplicationRepository.php', 'src/Repositories/ClanMemberRepository.php'] as $repositoryFile) {
    $source = (string) file_get_contents($projectRoot . '/' . $repositoryFile);
    if (preg_match('/->(?:query|exec)\s*\(\s*["\'][^"\']*\$/', $source)) {
        $interpolatedSql[] = basename($repositoryFile);
    }
}
audit(
    $interpolatedSql === [],
    'Artículo V · ningún repositorio de la ceremonia interpola variables en SQL'
    . ($interpolatedSql ? ' — infractores: ' . implode(', ', $interpolatedSql) : '')
);

/* Artículo V · El contrato JSON viaja en inglés camelCase. */
$vestibuleDtoSource = (string) file_get_contents($projectRoot . '/src/Dto/VestibuleClanDto.php');
preg_match_all("/['\"]([A-Za-z_]+)['\"]\s*=>/", $vestibuleDtoSource, $jsonKeys);
$snakeKeys = array_values(array_filter($jsonKeys[1], fn (string $k): bool => str_contains($k, '_')));
audit(
    $snakeKeys === [],
    'Artículo V · el contrato JSON del sobre viaja en inglés camelCase'
    . ($snakeKeys ? ' — halladas claves snake_case: ' . implode(', ', $snakeKeys) : '')
);

/* Artículo V · El plano persiste las columnas de la ceremonia en snake_case inglés. */
$schemaSource = (string) file_get_contents($projectRoot . '/database/schema.sql');
foreach (['clan_applications', 'verdict_motive', 'verdict_seen_at', 'uq_clan_application_house'] as $column) {
    audit(
        str_contains($schemaSource, $column),
        "Artículo V · el plano canoniza «{$column}»"
    );
}

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
$byDesignNonZero = [
    'test_spec07_closure.php',
    'test_spec08_closure.php',
    'test_spec09_closure.php',
    'test_spell_balance_bridge.php',
    // Este mismo arnés: su EXITO es el final del proceso, no una suite más.
    'test_spec10_closure.php',
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
    0  => ['Vista solemne voluntaria, doble vía, ermitaño legítimo', ['test_vestibule_view.mjs', 'test_vestibule_route_badge.mjs'], 'Vestíbulo'],
    1  => ['Aptitud conjuntiva derivada del instante (RF-01.7)', ['test_vestibule_service.php'], 'apt'],
    2  => ['Peregrino retenido por SPEC-09; anónimos en el Salón', ['test_vestibule_service.php', 'test_lineage_oath_controller.php'], 'LINEAGE_OATH_REQUIRED'],
    3  => ['Catálogo solo del linaje jurado; CLAN_LINEAGE_MISMATCH', ['test_clan_admission_guards.php'], 'CLAN_LINEAGE_MISMATCH'],
    4  => ['Tarjeta completa con Sello, plenitud, régimen y corona', ['test_vestibule_card_states.mjs'], 'censo'],
    5  => ['Modal solemne de ingreso con lealtad y convalecencia', ['test_admission_modal.mjs'], 'lealtad'],
    6  => ['Petición formal 20–500, límite 3, clausura y rótulo', ['test_petition_composer.mjs', 'test_clan_application_closure.php', 'test_petition_inventory.mjs'], '500'],
    7  => ['Residuales anuladas de oficio al nacer la membresía (RF-03.7)', ['test_clan_vestibule_audit.php'], 'RESIDUALS'],
    8  => ['Inventario consolidado con retirada directa (RF-03.8)', ['test_petition_inventory.mjs'], 'pendientes'],
    9  => ['Estados vedados con leyendas solemnes y días reales', ['test_vestibule_card_states.mjs', 'test_clan_admission_guards.php'], 'Convalecencia'],
    10 => ['Revalidación en servidor de cada gesto (RF-03.6)', ['test_clan_admission_race.php'], 'vacante'],
    11 => ['Bitácora con reparto por actor y dictamen con motivo', ['test_clan_vestibule_audit.php'], 'PATRIARCA'],
    12 => ['Dogma Vanilla, Velo Arcano, WCAG AA y Soberanía Lingüística', ['test_audit.php', 'test_css_coverage.mjs'], 'Dogma'],
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
    echo "RESULTADO: EXITO — SPEC-10 queda formalmente cerrada por la Tarea 9.3.\n";
    exit(0);
}
echo "RESULTADO: FALLO — SPEC-10 no puede declararse cerrada.\n";
exit(1);

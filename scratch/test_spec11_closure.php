<?php

/**
 * test_spec11_closure.php — Arnés de cierre formal de SPEC-11 (Tarea 9.3).
 *
 * Colección del Adepto: Tomo Personal y Elogio Popular. Seis fases, al
 * estilo de los cierres 07/08/10:
 *
 *   [1] Alineación de la tríada canónica (spec · plan · tasks): los 28
 *       requisitos declarados en la Sección 4 (23 RF + 5 RNF) quedan
 *       cubiertos por las tareas y por la Trazabilidad del plan
 *       (Sección 7); las 25 tareas quedan cerradas — la propia 9.3 se
 *       sella al declarar este arnés el EXITO.
 *   [2] Dogma Vanilla (Artículo I): cero npm, cero composer, cero CDN.
 *   [3] Tipificación estricta en PHP 8.2+ y compilación del código server
 *       de la colección.
 *   [4] Conformidad constitucional (Artículos I–V) sobre los módulos de
 *       la colección: el tomo jamás toca el maná (II), el servicio jamás
 *       invoca al Dominio ni escribe en `favorites` (III/plan §1.1), la
 *       narrativa viaja en noble castellano (IV) y SQL exclusivamente
 *       preparado (V).
 *   [5] Ejecución íntegra de la batería automatizada (PHP + Node).
 *   [6] Los 6 criterios de finalización y aceptación de la Sección 8 de
 *       la spec, uno a uno, cada uno con su suite de evidencia nombrada y
 *       ejecutada en vivo.
 *
 * Criterio «Hecho cuando» (Tarea 9.3): el arnés imprime «RESULTADO: EXITO
 * — SPEC-11 queda formalmente cerrada» con cero suites en rojo y los
 * criterios de la Sección 8 con evidencia nombrada.
 *
 * Ejecución: php scratch/test_spec11_closure.php   (exit 0 = SPEC-11 cerrada)
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
echo " CIERRE DE SPEC-11 · COLECCIÓN DEL ADEPTO (TOMO PERSONAL Y ELOGIO POPULAR)\n";
echo "══════════════════════════════════════════════════════════════════════\n\n";

/* ═══════════════════════════════════════════════════════════════════════
   FASE 1 · Alineación de la tríada canónica
   ═══════════════════════════════════════════════════════════════════════ */

echo "FASE 1 · Tríada canónica (spec · plan · tasks)\n";

$specPath  = $projectRoot . '/specs/11-adept-grimoire-collection.spec.md';
$planPath  = $projectRoot . '/specs/11-adept-grimoire-collection.plan.md';
$tasksPath = $projectRoot . '/specs/11-adept-grimoire-collection.tasks.md';

foreach ([$specPath, $planPath, $tasksPath] as $triadFile) {
    audit(is_file($triadFile), 'La pata de la tríada existe: ' . basename($triadFile));
}

$specSource  = (string) file_get_contents($specPath);
$planSource  = (string) file_get_contents($planPath);
$tasksSource = (string) file_get_contents($tasksPath);

/* 1.1 · Requisitos declarados en la Sección 4 de la especificación. */
preg_match_all('/^### RF-\d{2}:|^### RNF-\d{2} —/m', $specSource, $sectionHeads);
preg_match_all('/^- \*\*((?:RF-\d{2}\.\d+)|(?:RNF-\d{2}))\b/m', $specSource, $declaredRaw);
$declared = array_values(array_unique($declaredRaw[1]));
sort($declared);
audit(count($declared) === 28, 'La Sección 4 declara 28 requisitos (23 RF + 5 RNF); hallados ' . count($declared));

/**
 * Expande citas de requisitos en un texto: pares sueltos y rangos del
 * tipo «RF-01.1–RF-01.3» o «RF-04.1–04.5».
 */
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
    'La Trazabilidad del plan cubre todo requisito'
    . ($missingInPlan ? ' — huérfanos: ' . implode(', ', $missingInPlan) : '')
);

/* 1.4 · Estado de las tareas: las 25 cerradas (la propia 9.3 quedó sellada
 * al certificar su cierre; las enmiendas posteriores —hallazgo 13, §10.4—
 * viven en la tríada sin tareas nuevas). */
$doneTasks = preg_match_all('/^- \[x\] \*\*Tarea /m', $tasksSource);
$openTasks = preg_match_all('/^- \[ \] \*\*Tarea /m', $tasksSource);
audit($doneTasks === 25, "Las 25 tareas están cerradas (halladas {$doneTasks})");
audit($openTasks === 0, "Ninguna tarea queda abierta (abiertas: {$openTasks})");

/* 1.5 · Fases declaradas. */
$phases = preg_match_all('/^## Fase \d+/m', $tasksSource);
audit($phases === 9, "Las 9 fases del plan de tareas están presentes (halladas {$phases})");

/* 1.6 · Los criterios de la Sección 8 son 6. */
preg_match_all('/^- \[ \] (.+)$/m', $specSource, $criteriaRaw);
$criteria = array_map('trim', $criteriaRaw[1]);
audit(count($criteria) === 6, 'La Sección 8 declara 6 criterios de aceptación; hallados ' . count($criteria));

/* 1.7 · El Anexo A del plan (leyendas solemnes) está ratificado. */
audit(
    str_contains($planSource, '## Anexo A') && str_contains($planSource, '(RATIFICADAS)'),
    'El Anexo A de las leyendas solemnes está ratificado en el plan'
);

/* 1.8 · El registro de la tercera ronda (hallazgos del recorrido manual)
   queda cerrado: los cinco hallazgos 8–12 con su tabla §10.3. */
audit(
    str_contains($specSource, '### 10.3') && str_contains($specSource, 'Cierre de los hallazgos 8–12'),
    'El registro de la tercera ronda (§10.3) sella los hallazgos 8–12 del recorrido'
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

echo "\nFASE 3 · Tipificación estricta y compilación de los módulos de la colección\n";

$collectionPhp = [
    'src/Repositories/GrimoireCollectionRepository.php',
    'src/Services/GrimoireCollectionService.php',
    'src/Controllers/GrimoireCollectionController.php',
    'src/Dto/CollectionEntryDto.php',
    'src/Dto/CollectionPageDto.php',
];
$untyped = [];
foreach ($collectionPhp as $module) {
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
    'Todo módulo PHP de la colección declara `strict_types=1`'
    . ($untyped ? ' — infractores: ' . implode(', ', $untyped) : '')
);

$syntaxErrors = [];
foreach ($collectionPhp as $module) {
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
    'Todo el código servidor de la colección compila sin errores'
    . ($syntaxErrors ? ' — infractores: ' . implode(', ', $syntaxErrors) : '')
);

/* ═══════════════════════════════════════════════════════════════════════
   FASE 4 · Conformidad constitucional (Artículos I a V)
   ═══════════════════════════════════════════════════════════════════════ */

echo "\nFASE 4 · Constitución — Artículos I a V sobre la colección\n";

/* Artículo I · Módulos nativos de la colección. */
$collectionFrontend = [
    'public/assets/js/api/grimoireCollectionClient.js',
    'public/assets/js/views/grimoireCollectionView.js',
    'public/assets/js/components/discardTomeEntryModalComponent.js',
    'public/assets/js/components/spellCardComponent.js',
    'public/assets/css/components/grimoire-collection.css',
];
foreach ($collectionFrontend as $module) {
    audit(is_file($projectRoot . '/' . $module), 'Artículo I · módulo nativo presente: ' . basename($module));
}

/* Artículo II · La colección jamás toca el coste de maná. */
$manaTrespass = [];
foreach (array_merge(
    ['src/Services/GrimoireCollectionService.php', 'src/Repositories/GrimoireCollectionRepository.php'],
    $collectionFrontend
) as $module) {
    $source = (string) file_get_contents($projectRoot . '/' . $module);
    if (preg_match('/SpellBalanceService|calculateManaCost|manaCost\s*[=*\/+\-]/', $source)) {
        $manaTrespass[] = basename($module);
    }
}
audit(
    $manaTrespass === [],
    'Artículo II · el tomo jamás altera el coste de maná'
    . ($manaTrespass ? ' — infractores: ' . implode(', ', $manaTrespass) : '')
);

/* Artículo III / plan §1.1 · La frontera sagrada de la capa: el servicio
   de colección jamás invoca al servicio vivo de Dominio ni toca la mesa
   de votos `favorites`; solo el controlador la convoque. */
$serviceSource = (string) file_get_contents($projectRoot . '/src/Services/GrimoireCollectionService.php');
$serviceCode = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $serviceSource);
audit(
    !str_contains($serviceCode, 'WeeklyDominionService') && !str_contains($serviceCode, 'favorites'),
    'Artículo III · el servicio del tomo jamás invoca al Dominio ni toca `favorites`'
);
$controllerSource = (string) file_get_contents($projectRoot . '/src/Controllers/GrimoireCollectionController.php');
audit(
    str_contains($controllerSource, 'awardCommunityFavorite'),
    'Artículo III · la puerta REST del elogio convoca al servicio vivo de SPEC-07 sin modificarlo'
);
audit(
    str_contains($controllerSource, 'LINEAGE_OATH_REQUIRED'),
    'Artículo III · el linaje manda, no el rol: la puerta responde 403 con la voz de SPEC-09'
);

/* Artículo III bis · La Bitácora de los dos actos (RF-06). */
$auditEntrySource = (string) file_get_contents($projectRoot . '/src/Models/AuditEntry.php');
audit(
    str_contains($auditEntrySource, 'TOME_SEAL') && str_contains($auditEntrySource, 'TOME_PRAISE'),
    'Artículo III · los actos TOME_SEAL y TOME_PRAISE viven en el catálogo canónico de la Bitácora'
);

/* Artículo IV · Velo Arcano: la narrativa de la colección no filtra
   literales en inglés de interfaz ni jerga técnica. */
$englishUiLiterals = [];
foreach ($collectionFrontend as $uiSource) {
    if (!str_ends_with($uiSource, '.js')) {
        continue;
    }
    $source = (string) file_get_contents($projectRoot . '/' . $uiSource);
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? '';
    if (preg_match_all('/[\'"]((?:The|Your|Please|Join|Leave|Submit|Invalid|Loading|Add|Remove)\s[^\'"]{3,})[\'"]/', $stripped, $hits)) {
        $englishUiLiterals[] = basename($uiSource) . ' → ' . $hits[1][0];
    }
}
audit(
    $englishUiLiterals === [],
    'Artículo IV · la narrativa de la colección no filtra literales en inglés'
    . ($englishUiLiterals ? ' — hallados: ' . implode(' | ', $englishUiLiterals) : '')
);

$specRefLeak = [];
foreach ([
    'src/Services/GrimoireCollectionService.php',
    'public/assets/js/api/grimoireCollectionClient.js',
    'public/assets/js/views/grimoireCollectionView.js',
] as $legendSource) {
    $source = (string) file_get_contents($projectRoot . '/' . $legendSource);
    $stripped = preg_replace('#/\*.*?\*/|^\s*//.*$|^\s*\*.*$#ms', '', $source) ?? '';
    if (preg_match_all('/[\'"]([^\']*(?:RF-\d{2}\.\d+|RNF-\d{2})[^\']*)[\'"]/', $stripped, $hits)) {
        $specRefLeak[] = basename($legendSource) . ' → ' . mb_substr($hits[1][0], 0, 60);
    }
}
audit(
    $specRefLeak === [],
    'Artículo IV/V · ninguna leyenda visible porta referencias técnicas (RF-x/RNF-x)'
    . ($specRefLeak ? ' — hallados: ' . implode(' | ', $specRefLeak) : '')
);

/* Artículo V · SQL exclusivamente preparado en el repositorio del tomo. */
$repositorySource = (string) file_get_contents($projectRoot . '/src/Repositories/GrimoireCollectionRepository.php');
audit(
    !preg_match('/->(?:query|exec)\s*\(\s*["\'][^"\']*\$/', $repositorySource),
    'Artículo V · el repositorio del tomo jamás interpola variables en SQL'
);
audit(
    str_contains($repositorySource, 'prepare('),
    'Artículo V · el repositorio del tomo trabaja exclusivamente con consultas preparadas'
);

/* Artículo V · El contrato JSON viaja en inglés camelCase. */
$snakeKeys = [];
foreach (['src/Dto/CollectionEntryDto.php', 'src/Dto/CollectionPageDto.php'] as $dtoFile) {
    $dtoSource = (string) file_get_contents($projectRoot . '/' . $dtoFile);
    preg_match_all("/['\"]([A-Za-z_]+)['\"]\s*=>/", $dtoSource, $jsonKeys);
    foreach ($jsonKeys[1] as $key) {
        if (str_contains($key, '_')) {
            $snakeKeys[] = basename($dtoFile) . ' → ' . $key;
        }
    }
}
audit(
    $snakeKeys === [],
    'Artículo V · los contratos JSON del tomo viajan en inglés camelCase'
    . ($snakeKeys ? ' — halladas claves snake_case: ' . implode(', ', $snakeKeys) : '')
);

/* Artículo V · El plano canoniza la mesa y el índice del tomo. */
$schemaSource = (string) file_get_contents($projectRoot . '/database/schema.sql');
foreach (['grimoire_collections', 'idx_grimoire_collections_user_added'] as $artifact) {
    audit(
        str_contains($schemaSource, $artifact),
        "Artículo V · el plano canoniza «{$artifact}»"
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
    // Dialecto de la familia de colección: «N asertos en verde, 0 en rojo».
    if (preg_match_all('/(\d+)\s+asertos?\s+en\s+verde/i', $output, $greenHits)) {
        $asserts = max($asserts, (int) end($greenHits[1]));
    }
    // Dialecto del resto de la familia: «N pasan, 0 fallan».
    if (preg_match_all('/(\d+)\s+pasan,\s*\d+\s+fallan/i', $output, $passHits)) {
        $asserts = max($asserts, (int) end($passHits[1]));
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
    'test_spec10_closure.php',
    'test_spec11_closure.php',   // Este mismo arnés: su EXITO es el final del proceso.
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
    0  => ['RF-01 al RF-05 con cobertura de arnés propio, en verde y sin regresiones', [
        'test_grimoire_collection_repository.php',
        'test_grimoire_collection_service.php',
        'test_grimoire_collection_controller.php',
        'test_grimoire_collection_praise.php',
        'test_grimoire_collection_discard.php',
        'test_grimoire_collection_migration.php',
        'test_grimoire_collection_view.mjs',
        'test_spell_card_tome_gestures.mjs',
        'test_discard_tome_modal.mjs',
        'test_grimoire_collection_client.mjs',
        'test_intent_give_praise.mjs',
    ], 'pasan'],
    1  => ['Guard de soberanía lingüística extendido a los módulos de colección (RNF-03)', [
        'test_collection_language_sovereignty.php',
    ], 'soberanía'],
    2  => ['El arnés de latencia certifica el tomo bajo el presupuesto de RNF-01', [
        'test_collection_latency.php',
    ], 'pasan'],
    3  => ['Regresión completa del santuario en verde tras la integración', [
        'test_main_orchestrator.mjs',
        'test_main_auth_integration.mjs',
        'test_grimoire_query_service.php',
        'test_grimoire_controller.php',
    ], 'asertos'],
    4  => ['Recorrido manual en navegador documentado con evidencia nombrada', [
        'test_collection_latency.php',
    ], 'pasan'],
    5  => ['Los hallazgos de la QA previa quedan ratificados con su registro de resoluciones (Sección 9)', [
        'test_grimoire_collection_tome_marks.php',
        'test_tome_audit.php',
        'test_tome_summon_marks.mjs',
    ], 'pasan'],
];

foreach ($criteria as $index => $criterion) {
    $evidence = $criteriaEvidence[$index] ?? null;
    $label = 'Criterio ' . ($index + 1) . ' · ' . ($evidence[0] ?? mb_substr($criterion, 0, 42));
    if ($evidence === null) {
        audit(false, $label . ' — sin evidencia nombrada');
        continue;
    }
    [$title, $suites, $needle] = $evidence;

    // El criterio 5 (recorrido manual) exige además la evidencia escrita
    // y el cierre de los hallazgos del recorrido en la propia spec.
    $extraProof = true;
    if ($index === 4) {
        $extraProof = is_file($projectRoot . '/specs/11-adept-grimoire-collection.evidence-9.2.md')
            && str_contains($specSource, '### 10.3');
    }

    // Canon del cierre (SPEC-10): la coartada queda clavada cuando CUALQUIERA
    // de las suites de evidencia corre en verde y porta la aguja en su seno.
    $nailed = !$extraProof ? false : false;
    $exercise = [];
    foreach ($suites as $suite) {
        $path = $scratchDir . '/' . $suite;
        if (!is_file($path)) {
            continue;
        }
        $verdict = runSuite($scratchDir, $suite);
        $exercise[] = sprintf('%s (%d asertos, exit %d)', $suite, $verdict['asserts'], $verdict['exit']);
        if ($extraProof && $verdict['exit'] === 0 && mb_stripos($verdict['output'], $needle) !== false) {
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
    echo "RESULTADO: EXITO — SPEC-11 queda formalmente cerrada por la Tarea 9.3.\n";
    exit(0);
}
echo "RESULTADO: DENEGADO — SPEC-11 no puede declararse cerrada.\n";
exit(1);

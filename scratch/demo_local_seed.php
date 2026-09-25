<?php

/**
 * demo_local_seed.php — Fabrica la base de demostración para probar la web en local.
 *
 * NO es un artefacto de producción ni una suite de pruebas: es un sembrador de
 * escaparate. Levanta el esquema canónico (`database/schema.sql`) con sus
 * semillas (`database/seeds.sql`) y añade un mundo diminuto pero vivo, para que
 * el Salón de los Linajes, la ficha de hermandad, el Tomo y el Gran Portal
 * tengan algo que exhibir a la primera mirada:
 *
 *   · Tres hermandades activas, una de ellas proclamada Clan Regente por el
 *     último corte dominical (para ver la corona dorada y el ribete).
 *   · Sus Patriarcas y adeptos, con la afiliación inscrita en `clan_members`
 *     (la autoridad única) y el espejo de `users.clan_id` coherente.
 *   · Dos cortes semanales ya sellados, que nutren el Libro Mayor de Campeones
 *     y el Prestigio Histórico.
 *   · Cuatro conjuros ratificados con su `clan_id`, que pueblan el patrimonio
 *     de cada casa (y el ribete ceremonial del regente en el Tomo).
 *
 * Uso: php scratch/demo_local_seed.php [ruta.sqlite]
 * Después: GRIMORIO_DB_DSN="sqlite:<ruta>" php -S 127.0.0.1:8099 -t public public/index.php
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$target = $argv[1] ?? ($projectRoot . '/scratch/demo_live.sqlite');

if (is_file($target)) {
    unlink($target);
}

$pdo = new PDO('sqlite:' . $target, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec((string) file_get_contents($projectRoot . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($projectRoot . '/database/seeds.sql'));

echo "Esquema y semillas canónicas aplicados sobre {$target}\n";

/* ─────────────────────────────────────────────────────────────────────
   Hermandades: primera pasada sin Patriarca (la clave foránea es circular
   con `users.clan_id`, así que el tutor se ciñe después).
   ───────────────────────────────────────────────────────────────────── */

$clans = [
    ['cln_mares', 'mareas-de-aether', 'Mareas de Aether',
     'La marea no olvida a quien la escuchó una vez.', 'rune_tide_spiral',
     'celestialTides', 'byApplication', 'active', 340, 1200],
    ['cln_tempestad', 'tempestad-eterna', 'Tempestad Eterna',
     'Rugimos en la piedra y en el cielo.', 'rune_tempest_bolt',
     'eternalTempest', 'open', 'active', 290, 980],
];

$insertClan = $pdo->prepare(
    'INSERT INTO clans (id, slug, name, motto, created_at, coat_of_arms, lineage_type,
                        admission_mode, status, weekly_points, historical_points,
                        last_activity_at, updated_at)
     VALUES (:id, :slug, :name, :motto, :createdAt, :coatOfArms, :lineageType,
             :admissionMode, :status, :weeklyPoints, :historicalPoints,
             :lastActivityAt, :updatedAt)'
);

foreach ($clans as [$id, $slug, $name, $motto, $coat, $lineage, $mode, $status, $weekly, $historical]) {
    $insertClan->execute([
        ':id' => $id, ':slug' => $slug, ':name' => $name, ':motto' => $motto,
        ':createdAt' => '2026-02-01T00:00:00Z', ':coatOfArms' => $coat,
        ':lineageType' => $lineage, ':admissionMode' => $mode, ':status' => $status,
        ':weeklyPoints' => $weekly, ':historicalPoints' => $historical,
        ':lastActivityAt' => '2026-09-13T18:00:00Z', ':updatedAt' => '2026-09-13T18:00:00Z',
    ]);
}

// El linaje fundacional amanece con gloria propia: no arranca en cero.
$pdo->exec(
    "UPDATE clans SET weekly_points = 120, historical_points = 640, updated_at = '2026-09-13T18:00:00Z'
      WHERE id = 'cln_primordial'"
);

/* ─────────────────────────────────────────────────────────────────────
   Magos: `password_hash` es un marcador NO verificable, como en las
   semillas canónicas (el acceso real lo crea quien se consagra por la web).
   ───────────────────────────────────────────────────────────────────── */

$users = [
    ['usr_marea_pat', 'Alta Marea', 'marea@primordialis.arc', 'master', 'cln_mares', 'celestialTides'],
    ['usr_marea_adept', 'Brisa de Sal', 'brisa@primordialis.arc', 'editor', 'cln_mares', 'celestialTides'],
    ['usr_tempestad_pat', 'Trueno Errante', 'trueno@primordialis.arc', 'master', 'cln_tempestad', 'eternalTempest'],
    ['usr_tempestad_adept', 'Élitro de Ámbar', 'elitro@primordialis.arc', 'master', 'cln_tempestad', 'eternalTempest'],
];

$insertUser = $pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
     VALUES (:id, :alias, :email, :hash, :role, :clanId, :lineage, :createdAt, :createdAt)'
);

foreach ($users as [$id, $alias, $email, $role, $clanId, $lineage]) {
    $insertUser->execute([
        ':id' => $id, ':alias' => $alias, ':email' => $email,
        ':hash' => 'x', ':role' => $role, ':clanId' => $clanId,
        ':lineage' => $lineage,
        ':createdAt' => '2026-02-01T00:00:00Z',
    ]);
}

$pdo->exec("UPDATE clans SET patriarch_id = 'usr_marea_pat'      WHERE id = 'cln_mares'");
$pdo->exec("UPDATE clans SET patriarch_id = 'usr_tempestad_pat'  WHERE id = 'cln_tempestad'");

/* ─────────────────────────────────────────────────────────────────────
   Afiliación: `clan_members` es la AUTORIDAD (RF-01.1, Art. VII).
   ───────────────────────────────────────────────────────────────────── */

$memberships = [
    ['clm_mares_pat', 'cln_mares', 'usr_marea_pat', 'patriarch'],
    ['clm_mares_adept', 'cln_mares', 'usr_marea_adept', 'adept'],
    ['clm_tempestad_pat', 'cln_tempestad', 'usr_tempestad_pat', 'patriarch'],
    ['clm_tempestad_adept', 'cln_tempestad', 'usr_tempestad_adept', 'adept'],
];

$insertMember = $pdo->prepare(
    'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at)
     VALUES (:id, :clanId, :userId, :role, :joinedAt, NULL, NULL)'
);

foreach ($memberships as [$id, $clanId, $userId, $role]) {
    $insertMember->execute([
        ':id' => $id, ':clanId' => $clanId, ':userId' => $userId, ':role' => $role,
        ':joinedAt' => '2026-02-01T00:00:00Z',
    ]);
}

/* ─────────────────────────────────────────────────────────────────────
   Cortes dominicales ya sellados: nutren el Libro Mayor y el Prestigio.
   El ÚLTIMO corte (semana 36) corona a Mareas de Aether: he ahí el regente.
   ───────────────────────────────────────────────────────────────────── */

$cycles = [
    ['cyc_2026_35', 35, 2026, 'cln_tempestad', 610, 4, '2026-08-30T23:59:59Z'],
    ['cyc_2026_36', 36, 2026, 'cln_mares', 715, 5, '2026-09-06T23:59:59Z'],
];

$insertCycle = $pdo->prepare(
    'INSERT INTO weekly_cycles (id, week_number, cycle_year, regent_clan_id, winning_points,
                                winner_spell_count, closed_at)
     VALUES (:id, :week, :year, :regent, :points, :spells, :closedAt)'
);

foreach ($cycles as [$id, $week, $year, $regent, $points, $spells, $closedAt]) {
    $insertCycle->execute([
        ':id' => $id, ':week' => $week, ':year' => $year, ':regent' => $regent,
        ':points' => $points, ':spells' => $spells, ':closedAt' => $closedAt,
    ]);
}

/* ─────────────────────────────────────────────────────────────────────
   Patrimonio ratificado: se clonan los conjuros génesis cambiando
   identidad, autor y estandarte (así se respetan TODAS las restricciones
   NOT NULL del plano sin escribir treinta columnas a mano).
   ───────────────────────────────────────────────────────────────────── */

$cloneSpell = static function (PDO $pdo, string $sourceId, string $newId, string $slug, string $name,
                               string $authorId, string $clanId, string $validatedAt, string $affinity): void {
    $pdo->prepare(
        "INSERT INTO spells
         SELECT :newId, :slug, :name,
                :authorId, magic_school, :affinity, casting_time, mana_cost, circle,
                math_fingerprint, :clanId, summary, description,
                components_verbal, components_somatic, components_material,
                damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                has_verbal, has_somatic, has_material,
                'validated', validation_signatures_count, 3, 0,
                created_at, updated_at, :validatedAt
           FROM spells WHERE id = :sourceId"
    )->execute([
        ':newId' => $newId, ':slug' => $slug, ':name' => $name, ':authorId' => $authorId,
        ':affinity' => $affinity, ':clanId' => $clanId, ':validatedAt' => $validatedAt,
        ':sourceId' => $sourceId,
    ]);
};

$cloneSpell($pdo, 'spl_genesis_02', 'spl_mares_01', 'marea-serena', 'Marea Serena',
    'usr_marea_pat', 'cln_mares', '2026-09-05T12:00:00Z', 'water');
$cloneSpell($pdo, 'spl_genesis_03', 'spl_mares_02', 'brisa-de-sal', 'Brisa de Sal',
    'usr_marea_adept', 'cln_mares', '2026-09-06T12:00:00Z', 'wind');
$cloneSpell($pdo, 'spl_genesis_01', 'spl_tempestad_01', 'fragor-del-alto-cielo', 'Fragor del Alto Cielo',
    'usr_tempestad_pat', 'cln_tempestad', '2026-08-28T12:00:00Z', 'lightning');
$cloneSpell($pdo, 'spl_genesis_01', 'spl_tempestad_02', 'liturgia-del-relampago-mudo', 'Liturgia del Relámpago Mudo',
    'usr_tempestad_adept', 'cln_tempestad', '2026-08-29T12:00:00Z', 'lightning');

/* ─────────────────────────────────────────────────────────────────────
   Parte final: el mundo queda en pie y se declara.
   ───────────────────────────────────────────────────────────────────── */

echo "Mundo de demostración sembrado:\n";
foreach ($pdo->query(
    "SELECT c.name, c.lineage_type, c.status, c.weekly_points, c.historical_points,
            (SELECT COUNT(*) FROM clan_members m WHERE m.clan_id = c.id AND m.left_at IS NULL) AS members,
            (SELECT COUNT(*) FROM spells s WHERE s.clan_id = c.id AND s.status = 'validated') AS validated
       FROM clans c ORDER BY c.weekly_points DESC"
) as $row) {
    printf(
        "  · %-32s %-16s %-8s semanal=%-4s histórico=%-5s adeptos=%s conjuros=%s\n",
        $row['name'], $row['lineage_type'], $row['status'],
        $row['weekly_points'], $row['historical_points'], $row['members'], $row['validated']
    );
}
echo "  · Cortes sellados: " . $pdo->query('SELECT COUNT(*) FROM weekly_cycles')->fetchColumn() . "\n";
echo "  · Conjuros totales: " . $pdo->query('SELECT COUNT(*) FROM spells')->fetchColumn() . "\n";

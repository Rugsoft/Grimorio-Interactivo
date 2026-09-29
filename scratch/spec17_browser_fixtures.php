<?php

/**
 * spec17_browser_fixtures.php — Ajusta la base sandbox de navegador de SPEC-17.
 *
 * NO es un artefacto de producción ni una suite: es una sonda de una sola vez
 * que deja cuatro magos con frase de paso verificable en la copia efímera
 * `scratch/spec17_browser.sqlite`, uno por cada estado de CTA de RF-17.1:
 *
 *   · usr_spec17_peregrino  → peregrino (sin linaje)  → «Consagrar Linaje»
 *   · usr_spec17_jurado     → jurado sin hermandad    → «Vincularse a una Hermandad»
 *   · usr_spec17_hermandad  → con hermandad            → sin CTA
 *   · usr_spec17_custodio   → admin_supremo           → sin CTA
 *
 * Uso: php scratch/spec17_browser_fixtures.php scratch/spec17_browser.sqlite
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$target = $argv[1] ?? ($projectRoot . '/scratch/spec17_browser.sqlite');

if (!is_file($target)) {
    fwrite(STDERR, "No existe la base sandbox: {$target}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $target, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

$passphrase = 'Vigilia17!';
$hash = password_hash($passphrase, PASSWORD_BCRYPT);

$now = '2026-09-29T10:00:00Z';

$fixtures = [
    // alias, email, role, lineage, clan_id
    ['Espectro17', 'espectro17@primordialis.arc', 'editor', null, null],
    ['Acaro17', 'acaro17@primordialis.arc', 'editor', 'abyssalShadows', null],
    ['Hermano17', 'hermano17@primordialis.arc', 'editor', 'celestialTides', 'cln_mares'],
    ['Custodio17', 'custodio17@primordialis.arc', 'supremeAdmin', 'primordialFlame', null],
];

$upsert = $pdo->prepare(
    'INSERT INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
     VALUES (:id, :alias, :email, :hash, :role, :clanId, :lineage, :now, :now)
     ON CONFLICT(id) DO UPDATE SET
        password_hash = excluded.password_hash,
        role = excluded.role,
        clan_id = excluded.clan_id,
        lineage = excluded.lineage,
        updated_at = excluded.updated_at'
);

foreach ($fixtures as $index => [$alias, $email, $role, $lineage, $clanId]) {
    $upsert->execute([
        ':id'       => 'usr_spec17_' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ':alias'    => $alias,
        ':email'    => $email,
        ':hash'     => $hash,
        ':role'     => $role,
        ':clanId'   => $clanId,
        ':lineage'  => $lineage,
        ':now'      => $now,
    ]);
}

// El estado 4 necesita que `clan_members` (la autoridad, RF-01.1 Art. VII)
// coincida con `users.clan_id`; sin esta fila la API seguiría([], 'clan_id')
// y el CTA aparecería donde no debe.
$member = $pdo->prepare(
    'INSERT INTO clan_members (id, clan_id, user_id, role, joined_at)
     VALUES (:id, :clanId, :userId, :role, :joinedAt)
     ON CONFLICT(id) DO NOTHING'
);
$member->execute([
    ':id'        => 'clm_spec17_hermandad',
    ':clanId'    => 'cln_mares',
    ':userId'    => 'usr_spec17_02',
    ':role'      => 'adept',
    ':joinedAt'  => $now,
]);

echo "Sondas de SPEC-17 sembradas en {$target}\n";
echo "Frase de paso común: {$passphrase}\n";
foreach ($pdo->query('SELECT id, alias, role, lineage, clan_id FROM users WHERE id LIKE \'usr_spec17%\' ORDER BY id') as $row) {
    printf(
        "  · %-18s %-14s %-14s lin=%-16s clan=%s\n",
        $row['id'], $row['alias'], $row['role'],
        $row['lineage'] ?? 'NULL', $row['clan_id'] ?? 'NULL'
    );
}

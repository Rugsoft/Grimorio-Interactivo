<?php

declare(strict_types=1);

/**
 * demo_tome_seed.php — Dota de credenciales al mundo de demostración
 * para el recorrido manual de SPEC-11 (Tarea 9.2).
 *
 * NO es un artefacto de producción ni una suite de pruebas: es el remate
 * del sembrador de escaparate `demo_local_seed.php`, que deja las cuentas
 * con `password_hash` marcador («x», no verificable) porque el acceso real
 * lo crea quien se consagra por la web. Para recorrer el Tomo Personal en
 * el navegador hacen falta DOS cuentas con palabra de paso verificable:
 *
 *   · El Adepto linajado — `usr_custodio_primordial`, maestro del linaje
 *     Fuego Primordial y único morador de su casa: puede sellar y elogiar
 *     obras ajenas (gloria con sinergia) y recibe el recibo denegado al
 *     apuntar a la obra de su propia casa (militancia, RF-04.4).
 *   · El Peregrino sin Linaje — cuenta nueva SIN linaje jurado: toda ruta
 *     de gestión la retiene el juramento (SPEC-09) y el acto retenido se
 *     reanuda solo tras sellarlo (RF-01.4).
 *
 * Uso:
 *   php scratch/demo_local_seed.php  scratch/demo_tome.sqlite
 *   php scratch/demo_tome_seed.php   scratch/demo_tome.sqlite
 *   GRIMORIO_DB_DSN="sqlite:scratch/demo_tome.sqlite" php -S 127.0.0.1:8109 -t public public/index.php
 */

$projectRoot = dirname(__DIR__);
$target = $argv[1] ?? ($projectRoot . '/scratch/demo_tome.sqlite');

if (!is_file($target)) {
    fwrite(STDERR, "[FATAL] Falta la base de demostración {$target} — ejecuta antes demo_local_seed.php.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $target, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

// Palabras de paso del escaparate (bcrypt nativo, el mismo coste que el
// santuario: password_verify las aceptará sin atajos).
$passphrase = 'palabra-de-paso-demo';
$hash = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => 12]);

// El adepto linajado: maestro de la casa del Fuego Primordial, ya jurado
// (lineage ya viene sellado en las semillas canónicas de esa cuenta).
$updateAdept = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
$updateAdept->execute([':hash' => $hash, ':id' => 'usr_custodio_primordial']);

// El peregrino: cuenta SIN linaje (NULL) y sin membresía — la fase de vida
// que conduce a la ceremonia bloqueante del primer acceso.
$pdo->prepare(
    'INSERT OR REPLACE INTO users (id, alias, email, password_hash, role, clan_id, lineage, created_at, updated_at)
     VALUES (:id, :alias, :email, :hash, :role, NULL, NULL, :createdAt, :createdAt)'
)->execute([
    ':id' => 'usr_peregrino_demo',
    ':alias' => 'Peregrino Sin Linaje',
    ':email' => 'peregrino@primordialis.arc',
    ':hash' => $hash,
    ':role' => 'editor',
    ':createdAt' => '2026-09-20T00:00:00Z',
]);

echo "Credenciales del escaparate listas sobre {$target}\n";
echo "  · Adepto linajado : custodio@primordialis.arc / {$passphrase}\n";
echo "  · Peregrino       : peregrino@primordialis.arc / {$passphrase}\n";
foreach ($pdo->query(
    "SELECT u.id, u.alias, u.lineage, u.clan_id,
            (SELECT COUNT(*) FROM grimoire_collections g WHERE g.user_id = u.id) AS tome
       FROM users u WHERE u.id IN ('usr_custodio_primordial', 'usr_peregrino_demo')"
) as $row) {
    printf(
        "  · %-26s linaje=%-16s casa=%-16s tomo=%s\n",
        $row['alias'], (string) ($row['lineage'] ?? '—'), (string) ($row['clan_id'] ?? '—'), $row['tome']
    );
}

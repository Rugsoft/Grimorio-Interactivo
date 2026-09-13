<?php

/**
 * AuthService.php — Servicio de autenticación, hashing y recuperación.
 *
 * Tarea 2.3 (TASKS-03): lógica de consagración, vínculo (login), verificación
 * temporal constante, disolución individual/global y pergamino de
 * restablecimiento.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): password_hash/password_verify nativos
 *     con BCRYPT coste 12 (plan 4, Decisión 3), PDO puro, sin librerías.
 *   - Artículo III: la disolución global y el restablecimiento revocan
 *     todas las sesiones activas de la cuenta en base de datos.
 *   - Artículo V: identificadores en inglés camelCase, documentación y
 *     leyendas solemnes en castellano.
 *
 * Seguridad:
 *   - Verificación temporal constante (RF-03.1, plan 3.4): si la identidad
 *     no existe, se verifica contra un hash señuelo BCRYPT real, de modo
 *     que el tiempo de cómputo es idéntico y no se puede enumerar
 *     identidades midiendo latencias.
 *   - El token de recuperación jamás se persiste en claro: solo su SHA-256,
 *     con vigencia de 60 minutos y un solo uso.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use Grimorio\Core\ActiveSession;
use Grimorio\Core\SessionManager;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Gestión del ciclo de vida de las cuentas y sus vínculos.
 */
final class AuthService
{
    /** Longitud mínima de la frase de paso (RF-01.1: frases naturales sin símbolos forzados). */
    private const MIN_PASSPHRASE_LENGTH = 8;

    /** Rango canónico del alias de iniciado (RF-01.1). */
    private const MIN_ALIAS_LENGTH = 3;
    private const MAX_ALIAS_LENGTH = 30;

    /** Coste BCRYPT exigido por el plan (Decisión 3). */
    private const BCRYPT_COST = 12;

    /** Vigencia del pergamino de restablecimiento: 60 minutos (RF-04.1). */
    private const RECOVERY_TOKEN_MINUTES = 60;

    /** Hash señuelo BCRYPT coste 12 real: garantiza el mismo coste computacional que un hash legítimo. */
    private const DUMMY_HASH = '$2y$12$Xu9Bc1oVv7Oe2Pq0rTn5Y.dK3wZ8sH6gJ4fL2mN9qR1tC5vB8xWyK';

    /** Seudónimo solemne del registro anonimizado (RF-09.2, auditoría 5.3). */
    private const RENOUNCE_ANONYMOUS_ALIAS = 'Erudito Ancestral';

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Gestor del ciclo de vida de sesiones (Tarea 2.1). */
    private SessionManager $sessionManager;

    /**
     * Resultado del intento de vínculo (bind).
     */
    public function __construct(PDO $pdo, SessionManager $sessionManager)
    {
        $this->pdo = $pdo;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Consagra un nuevo iniciado (RF-01.1, RF-01.2): valida los datos,
     * comprueba la anti-enumeración (alias/correo ya reclamados responden
     * neutro), crea la cuenta con rol editor y vincula el clan.
     *
     * @throws InvalidArgumentException Si algún dato viola el canon.
     * @throws RuntimeException Si la identidad ya está reclamada (409 neutro).
     */
    public function consecrate(string $alias, string $email, string $passphrase, string $clanId, ?DateTimeImmutable $now = null): ConsecrationResult
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Canon del alias (RF-01.1): 3 a 30 caracteres.
        $aliasLength = mb_strlen(trim($alias));
        if ($aliasLength < self::MIN_ALIAS_LENGTH || $aliasLength > self::MAX_ALIAS_LENGTH) {
            throw new InvalidArgumentException('El alias debe tener entre 3 y 30 caracteres.');
        }

        // Canon de la frase de paso (RF-01.1): mínimo 8 caracteres, sin
        // símbolos forzados (se permiten frases naturales).
        if (strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new InvalidArgumentException('La frase de paso debe tener al menos 8 caracteres.');
        }

        // Canon del correo: filtro nativo de PHP.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo electrónico no es válido.');
        }

        // El clan es OBLIGATORIO (RF-01.1): debe existir en el catálogo.
        $clanStatement = $this->pdo->prepare('SELECT id FROM clans WHERE id = :clanId');
        $clanStatement->execute([':clanId' => $clanId]);
        if ($clanStatement->fetchColumn() === false) {
            throw new InvalidArgumentException('El linaje seleccionado no existe en el santuario.');
        }

        // Anti-enumeración (RF-01.3): si el alias o el correo ya viven en
        // el grimorio, la respuesta es neutra (409) sin revelar cuál de
        // los dos choca.
        $duplicateStatement = $this->pdo->prepare(
            'SELECT id FROM users WHERE alias = :alias OR email = :email LIMIT 1'
        );
        $duplicateStatement->execute([':alias' => $alias, ':email' => $email]);
        if ($duplicateStatement->fetchColumn() !== false) {
            throw new RuntimeException('La identidad solicitada ya fue reclamada por otro iniciado.');
        }

        $userId = 'usr_' . bin2hex(random_bytes(6));
        $passwordHash = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at)
             VALUES (:id, :alias, :email, :passwordHash, :role, :clanId, :createdAt, :updatedAt)'
        );
        $inserted = $insertStatement->execute([
            ':id'           => $userId,
            ':alias'        => $alias,
            ':email'        => $email,
            ':passwordHash' => $passwordHash,
            ':role'         => 'editor',   // Rol técnico por defecto (RF-01.2).
            ':clanId'       => $clanId,
            ':createdAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
            ':updatedAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
        ]);

        if (!$inserted) {
            throw new RuntimeException('La consagración no pudo inscribirse en el registro de iniciados.');
        }

        return new ConsecrationResult(userId: $userId);
    }

    /**
     * Renueva el vínculo de un iniciado (login, RF-02.1) con verificación
     * temporal constante (RF-03.1): las credenciales erróneas consumen el
     * mismo coste BCRYPT aunque la identidad no exista (hash señuelo).
     * El resultado neutro jamás revela si falló la identidad o la frase.
     */
    public function bind(string $identity, string $passphrase, ?DateTimeImmutable $now = null): BindResult
    {
        // La identidad puede llegar como alias o como correo (plan 2.2, Endpoint 2).
        $userStatement = $this->pdo->prepare(
            'SELECT id, password_hash FROM users WHERE email = :identity OR alias = :identity LIMIT 1'
        );
        $userStatement->execute([':identity' => $identity]);
        $userRow = $userStatement->fetch(PDO::FETCH_ASSOC);

        // Hash señuelo (plan 3.4): mismo algoritmo y coste que un hash real,
        // de modo que password_verify consuma el mismo tiempo en ambos caminos.
        $hashToVerify = is_array($userRow) ? (string) $userRow['password_hash'] : self::DUMMY_HASH;
        $passphraseMatches = password_verify($passphrase, $hashToVerify);

        if (!is_array($userRow) || !$passphraseMatches) {
            // Respuesta genérica: «Las runas no reconocen este vínculo...».
            return new BindResult(success: false, userId: null, session: null);
        }

        $userId = (string) $userRow['id'];
        $session = $this->sessionManager->createSession($userId, $now);

        return new BindResult(success: true, userId: $userId, session: $session);
    }

    /**
     * Disuelve el vínculo del dispositivo actual (RF-02.4).
     */
    public function dissolve(string $rawToken): bool
    {
        return $this->sessionManager->dissolveSession($rawToken);
    }

    /**
     * Disuelve TODOS los vínculos activos del titular del token (RF-02.4):
     * revocación inmediata en todos los dispositivos.
     */
    public function dissolveAll(string $rawToken): bool
    {
        $tokenHash = hash('sha256', $rawToken);
        $ownerStatement = $this->pdo->prepare(
            'SELECT user_id FROM user_sessions WHERE session_token_hash = :tokenHash'
        );
        $ownerStatement->execute([':tokenHash' => $tokenHash]);
        $ownerId = $ownerStatement->fetchColumn();

        if ($ownerId === false) {
            return false;
        }

        // Revocación en base de datos: todas las filas del titular caen.
        $purgeStatement = $this->pdo->prepare('DELETE FROM user_sessions WHERE user_id = :userId');
        $purgeStatement->execute([':userId' => $ownerId]);

        return true;
    }

    /**
     * Emite el Pergamino de Restablecimiento (RF-04.1): token de un solo
     * uso con vigencia de 60 minutos, persistido solo como SHA-256. Para
     * un correo ajeno NO se emite nada (anti-enumeración): la respuesta
     * pública es neutra en ambos casos.
     */
    public function requestRecovery(string $email, ?DateTimeImmutable $now = null): RecoveryResult
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $userStatement = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $userStatement->execute([':email' => $email]);
        $userId = $userStatement->fetchColumn();

        if ($userId === false) {
            // Respuesta neutra: sin token, sin pistas (RF-04.1 anti-enumeración).
            return new RecoveryResult(tokenIssued: false, recoveryToken: null);
        }

        $recoveryToken = bin2hex(random_bytes(32));

        $updateStatement = $this->pdo->prepare(
            'UPDATE users SET recovery_token_hash = :tokenHash, recovery_token_expires_at = :expiresAt, updated_at = :updatedAt WHERE id = :id'
        );
        $updateStatement->execute([
            ':tokenHash'  => hash('sha256', $recoveryToken),
            ':expiresAt'  => $instant->modify('+' . self::RECOVERY_TOKEN_MINUTES . ' minutes')->format('Y-m-d\TH:i:s\Z'),
            ':updatedAt'  => $instant->format('Y-m-d\TH:i:s\Z'),
            ':id'         => $userId,
        ]);

        return new RecoveryResult(tokenIssued: true, recoveryToken: $recoveryToken);
    }

    /**
     * Restablece la frase de paso mediante el pergamino (RF-04.2):
     * valida el token (un solo uso, 60 minutos), re-hashea la nueva frase
     * con BCRYPT coste 12 y revoca preventivamente todas las sesiones
     * activas de la cuenta.
     */
    public function resetPassword(string $recoveryToken, string $newPassphrase, ?DateTimeImmutable $now = null): bool
    {
        if (strlen($newPassphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new InvalidArgumentException('La nueva frase de paso debe tener al menos 8 caracteres.');
        }

        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tokenHash = hash('sha256', $recoveryToken);

        $userStatement = $this->pdo->prepare(
            'SELECT id, recovery_token_expires_at FROM users WHERE recovery_token_hash = :tokenHash'
        );
        $userStatement->execute([':tokenHash' => $tokenHash]);
        $userRow = $userStatement->fetch(PDO::FETCH_ASSOC);

        if ($userRow === false) {
            return false; // Token inexistente o ya consumido (un solo uso).
        }

        // Vigencia de 60 minutos (RF-04.1).
        $expiresAt = new DateTimeImmutable((string) $userRow['recovery_token_expires_at']);
        if ($instant->getTimestamp() > $expiresAt->getTimestamp()) {
            return false; // Pergamino caducado.
        }

        $userId = (string) $userRow['id'];
        $newHash = password_hash($newPassphrase, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        // Actualización atómica: nueva frase + pergamino consumido
        // (recovery_token_hash vacío = un solo uso).
        $resetStatement = $this->pdo->prepare(
            'UPDATE users
             SET password_hash = :passwordHash,
                 recovery_token_hash = \'\',
                 recovery_token_expires_at = NULL,
                 updated_at = :updatedAt
             WHERE id = :id'
        );
        $resetStatement->execute([
            ':passwordHash' => $newHash,
            ':updatedAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
            ':id'           => $userId,
        ]);

        // Revocación preventiva (RF-04.2): todas las sesiones previas caen.
        $purgeStatement = $this->pdo->prepare('DELETE FROM user_sessions WHERE user_id = :userId');
        $purgeStatement->execute([':userId' => $userId]);

        return true;
    }

    /**
     * Renuncia al Vínculo (RF-09.1): derecho al olvido con preservación
     * del legado (RF-09.2). La baja canónica (auditoría 5.3) NO borra la
     * fila — el RESTRICT de clan_history lo impediría con historia viva —
     * sino que la anonimiza:
     *   1. El pergamino activo queda purgado.
     *   2. El alias pasa al seudónimo solemne «Erudito Ancestral».
     *   3. Correo y hash se sustituyen por opacos irrecuperables.
     *   4. Todas las sesiones de la cuenta caen (disolución global implícita).
     * El rol y el linaje del registro permanecen estables para no romper
     * la puntuación histórica del linaje (RF-09.2, RF-07.3).
     *
     * @return bool true si la renuncia prosperó; false si el token no
     *              portaba un vínculo activo o la cuenta ya era anónima.
     */
    public function renounceAccount(string $rawToken, ?DateTimeImmutable $now = null): bool
    {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tokenHash = hash('sha256', $rawToken);

        // El token crudo jamás se persiste: el titular se resuelve por su
        // huella SHA-256 en user_sessions (Tarea 2.1).
        $ownerStatement = $this->pdo->prepare(
            'SELECT user_id FROM user_sessions WHERE session_token_hash = :tokenHash LIMIT 1'
        );
        $ownerStatement->execute([':tokenHash' => $tokenHash]);
        $ownerId = $ownerStatement->fetchColumn();

        if ($ownerId === false) {
            return false; // Sin vínculo portador: nada que renunciar.
        }

        $userId = (string) $ownerId;

        // Defensa anti-doble-renuncia: el seudónimo solemne identifica a
        // las cuentas ya anonimizadas; una segunda renuncia no prospera.
        $userStatement = $this->pdo->prepare('SELECT alias FROM users WHERE id = :userId');
        $userStatement->execute([':userId' => $userId]);
        $currentAlias = $userStatement->fetchColumn();

        if ($currentAlias === false || $currentAlias === self::RENOUNCE_ANONYMOUS_ALIAS) {
            return false;
        }

        // Purga del pergamino activo (si lo hubiera) y sustitución de
        // datos personales por opacos irrecuperables (RF-09.1).
        $anonymizeStatement = $this->pdo->prepare(
            'UPDATE users
             SET alias = :alias,
                 email = :email,
                 password_hash = :passwordHash,
                 recovery_token_hash = \'\',
                 recovery_token_expires_at = NULL,
                 updated_at = :updatedAt
             WHERE id = :userId'
        );
        $anonymizeStatement->execute([
            ':alias'        => self::RENOUNCE_ANONYMOUS_ALIAS,
            ':email'        => 'ancestral+' . $userId . '@olvidado.sanctuario',
            ':passwordHash' => str_repeat('0', 60), // No es un hash BCRYPT válido: jamás verificará.
            ':updatedAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
            ':userId'       => $userId,
        ]);

        // Disolución global implícita: todas las sesiones de la cuenta caen.
        $purgeStatement = $this->pdo->prepare('DELETE FROM user_sessions WHERE user_id = :userId');
        $purgeStatement->execute([':userId' => $userId]);

        return true;
    }
}

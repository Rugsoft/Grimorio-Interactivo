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
use Grimorio\Repositories\ClanMemberRepository;
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
     * neutro), crea la cuenta con rol editor y, si se solicita, lo vincula
     * a un linaje.
     *
     * El linaje es OPCIONAL desde la reconciliación de la afiliación
     * (Tarea 2.3, TASKS-07): un mago puede nacer sin hermandad, que es el
     * estado imprescindible para fundar la suya propia (RF-01.2). Cuando se
     * elige uno, la afiliación se inscribe en `clan_members` —la única
     * autoridad—, que a su vez refleja el vínculo en `users.clan_id`.
     *
     * @throws InvalidArgumentException Si algún dato viola el canon.
     * @throws RuntimeException         Si la identidad ya está reclamada (409 neutro).
     */
    public function consecrate(string $alias, string $email, string $passphrase, ?string $legacyClanId = null, ?DateTimeImmutable $now = null): ConsecrationResult
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

        // ENMIENDA SPEC-09 (RF-01.1, plan §5.8): la consagración ya no
        // vincula hermandad ni linaje alguno. Si un cliente en caché porta
        // `clanId`, se IGNORA EN SILENCIO — sin error, para no romper
        // pestañas abiertas durante el despliegue. El linaje se jurará en
        // la ceremonia bloqueante del primer acceso (SPEC-09); la
        // adhesión a clanes vive después, regida por SPEC-07.
        $legacyClanId = null;

        // Anti-enumeración (RF-01.3): si el alias o el correo ya viven en
        // el grimorio, la respuesta es neutra (409) sin revelar cuál de
        // los dos choca. La respuesta pública es uniforme; el aviso
        // discreto al titular se produce como contenido puro aparte (ver
        // buildDuplicateOwnerNotice): el transporte queda fuera del
        // santuario, pero el texto del pergamino es función de este
        // servicio y por tanto verificable por arnés.
        $duplicateStatement = $this->pdo->prepare(
            'SELECT id FROM users WHERE alias = :alias OR email = :email LIMIT 1'
        );
        $duplicateStatement->execute([':alias' => $alias, ':email' => $email]);
        if ($duplicateStatement->fetchColumn() !== false) {
            throw new RuntimeException('La identidad solicitada ya fue reclamada por otro iniciado.');
        }

        $userId = 'usr_' . bin2hex(random_bytes(6));
        $passwordHash = password_hash($passphrase, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        // La cuenta y su eventual afiliación se inscriben como un solo gesto:
        // o el iniciado nace con su linaje vigente, o no nace.
        $this->pdo->beginTransaction();

        try {
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
                // El espejo lo inscribe ClanMemberRepository, único escritor de
                // `users.clan_id`: aquí la cuenta nace aún sin linaje.
                ':clanId'       => null,
                ':createdAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
                ':updatedAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
            ]);

            if (!$inserted) {
                throw new RuntimeException('La consagración no pudo inscribirse en el registro de iniciados.');
            }

            // La cuenta y su afiliación ya no se inscriben juntas (enmienda
            // SPEC-09): `clan_members` solo se escribe por el flujo de
            // admisión de SPEC-07, jamás por el registro.

            $this->pdo->commit();
        } catch (\Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }

        // Toda cuenta consagrada nace PEREGRINA (RF-01.2 de SPEC-09): el
        // vínculo perpetuo solo lo forja el juramento, jamás el registro.
        return new ConsecrationResult(userId: $userId, lineage: null);
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
     * Contenido del aviso discreto destinado al titular de una identidad
     * reclamada (RF-01.3, segunda pata del requisito).
     *
     * La respuesta pública de la consagración es neutra (409 sin pistas);
     * este método produce el PERGAMINO DEL AVISO que el transporte fuera
     * de banda remitiría al correo de la cuenta original, «si
     * corresponde». Es una función PURA y determinista: la misma entrada
     * produce el mismo texto, sin consulta a la base de datos ni reloj
     * oculto (el instante viaja como argumento).
     *
     * Garantías que el arnés sella:
     *   - Nombra al titular (su alias y su correo), jamás al pretendiente:
     *     un observador del aviso no aprende quién intentó reclamar la
     *     identidad ni cuándo exactamente.
     *   - Difiere del mensaje público de rechazo: dos canales, dos tonos.
     *
     * Nota de Dogma (Artículo I): el santuario carece de servicio de
     * correo (ninguna dependencia externa); el texto vive aquí como
     * función pura para que el canal de notificación —cuando exista o en
     * la operatoria de custodios— consuma un contenido único, probado y
     * estable, sin improvisar leyendas en la capa de transporte.
     *
     * @param string                $ownerAlias  Alias del titular de la identidad.
     * @param string                $ownerEmail  Correo del titular (destino del aviso).
     * @param DateTimeImmutable|null $now        Instante de la colisión (inyectable en pruebas).
     *
     * @return string Texto noble del aviso discreto, en castellano (Art. V).
     * @throws InvalidArgumentException Si faltan la identidad o el correo del titular.
     */
    public function buildDuplicateOwnerNotice(string $ownerAlias, string $ownerEmail, ?DateTimeImmutable $now = null): string
    {
        $ownerAlias = trim($ownerAlias);
        $ownerEmail = trim($ownerEmail);
        if ($ownerAlias === '' || $ownerEmail === '') {
            throw new InvalidArgumentException('El aviso discreto exige la identidad y el correo del titular.');
        }

        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $journey = $instant->format('Y-m-d \a \l\a\s H:i UTC');

        // Sin datos del pretendiente, sin marcas de tiempo exactas al
        // segundo y con la fecha en texto noble: el aviso informa sin
        // exponer (Artículo IV/V).
        return sprintf(
            'Aviso discreto para %s (%s): alguien ha intentado consagrarse con una identidad que ya te pertenece. No has de hacer nada: tu identidad sigue bajo tu guardia y la solicitud ha sido desestimada. Aviso registrado el %s.',
            $ownerAlias,
            $ownerEmail,
            $journey,
        );
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

    /**
     * La Custodia de la Frase de Paso desde el panel (SPEC-12, RF-04.2,
     * RF-04.3; Tarea 3.1 de TASKS-12).
     *
     * Cambio CONSCIENTE de la frase de paso por el dueño autenticado.
     * Comparte con `resetPassphrase()` (el pergamino de recuperación) el
     * hasheo nativo BCRYPT coste 12 y la regla de solidez mínima, pero
     * su semántica de confianza difiere (plan §5.3): aquí el dueño está
     * presente, así que la sesión actual se CONSERVA y solo las demás
     * sesiones activas del adepto se disuelven — efecto inseparable del
     * acto de custodia, jamás una gestión independiente.
     *
     * La operación es una transacción atómica: o el hash cambia, las
     * demás sesiones caen y el asiento nace, o nada de ello ocurre
     * (RNF-05: el acto sin trazabilidad no existe).
     *
     * @param string $userId           Titular autenticado de la cuenta.
     * @param string $currentSessionId Identificador de la sesión actual
     *                                 (la única que sobrevive).
     * @param string $currentPassphrase Frase vigente presentada por el dueño.
     * @param string $newPassphrase    Nueva frase de paso.
     * @param string $newPassphraseRepeat Repetición de la nueva frase.
     * @param DateTimeImmutable|null $now Instante canónico (inyectable en arneses).
     *
     * @return array<string, mixed> Recibo del acto según plan §2.6:
     *         `['verdict' => 'changed', 'othersDissolvedCount' => N,
     *           'currentSessionPreserved' => true]`.
     *
     * @throws InvalidArgumentException Si la solidez de la nueva frase
     *         viola el canon (mínimo 8 caracteres, SPEC-03).
     */
    public function changePassphraseAuthenticated(
        string $userId,
        string $currentSessionId,
        string $currentPassphrase,
        string $newPassphrase,
        string $newPassphraseRepeat,
        ?DateTimeImmutable $now = null,
    ): array {
        $instant = $now ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // Regla de solidez compartida con el registro y el pergamino
        // (SPEC-03): mínimo 8 caracteres, sin símbolos forzados. La
        // evaluación es CIEGA (Tarea 3.2, RF-04.1): las tres causas
        // posibles responden con el MISMO veredicto que jamás revela
        // cuál falló (aviso sin pistas).
        if (strlen($newPassphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new \Grimorio\Exceptions\PassphraseChangeFailedException();
        }

        // El hash vigente de la fila (el dueño ya está autenticado, así
        // que la fila existe; si no existiera, no hay acto que custodiar).
        $userStatement = $this->pdo->prepare('SELECT password_hash, alias, role, updated_at FROM users WHERE id = :id');
        $userStatement->execute([':id' => $userId]);
        $userRow = $userStatement->fetch(PDO::FETCH_ASSOC);

        if ($userRow === false) {
            throw new RuntimeException('El titular de la custodia no habita el grimorio.');
        }

        // Verificación de la frase actual (Art. I: password_verify nativo).
        $storedHash = (string) $userRow['password_hash'];
        $currentPassphraseMatches = password_verify($currentPassphrase, $storedHash);
        $newMatchesCurrent = password_verify($newPassphrase, $storedHash);

        // Orden canónico del plan §3.3 (Tarea 3.3): la IDÉNTICA se
        // comprueba ANTES del fallo ciego — la coincidencia de la nueva
        // con la vigente es observable por el dueño sin revelar nada a
        // un tercero que no conozca la frase vigente (quien no la
        // conoce jamás puede fabricar una nueva que la alcance).
        if ($newMatchesCurrent) {
            if ($currentPassphraseMatches && $newPassphrase === $newPassphraseRepeat) {
                // Reenvío legítimo (caso límite 12, hallazgo 16 del
                // QA): la frase presentada como actual YA ES la nueva —
                // el dueño jamás recibe un aviso que mienta; recibo del
                // acto ya consumado con la estampa del cambio previo,
                // sin asiento ni mutación.
                return [
                    'verdict'   => 'idempotentReceipt',
                    'changedAt' => (string) $userRow['updated_at'],
                ];
            }

            // Primera salvedad honesta (RF-04.1, caso límite 17): la
            // nueva coincide con la vigente pero el envío no es un
            // reenvío legítimo — aviso noble específico, sin asiento
            // ni mutación, jamás el fallo ciego.
            throw new \Grimorio\Exceptions\PassphraseIdenticalException();
        }

        // A partir de aquí la nueva NO es la vigente: solo quedan el
        // éxito legítimo y el fallo ciego (RF-04.1).
        if (!$currentPassphraseMatches || $newPassphrase !== $newPassphraseRepeat) {
            throw new \Grimorio\Exceptions\PassphraseChangeFailedException();
        }

        // Transacción de la custodia (plan §3.3): UPDATE + DELETE + INSERT
        // viven o nada de ellos vive.
        $this->pdo->beginTransaction();

        try {
            // 1. El hash de la fila viste la nueva frase.
            $updateStatement = $this->pdo->prepare(
                'UPDATE users SET password_hash = :passwordHash, updated_at = :updatedAt WHERE id = :id'
            );
            $updateStatement->execute([
                ':passwordHash' => password_hash($newPassphrase, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
                ':updatedAt'    => $instant->format('Y-m-d\TH:i:s\Z'),
                ':id'           => $userId,
            ]);

            // 2. La desconfianza sanitaria: las demás moradas se vacían.
            //    La sesión actual sobrevive (el dueño está presente).
            $dissolveStatement = $this->pdo->prepare(
                'DELETE FROM user_sessions WHERE user_id = :userId AND id <> :currentSessionId'
            );
            $dissolveStatement->execute([':userId' => $userId, ':currentSessionId' => $currentSessionId]);
            $othersDissolvedCount = $dissolveStatement->rowCount();

            // 3. El asiento PASSPHRASE_SELF_CHANGED dentro de la
            //    transacción (RNF-05): la justification es leyenda fija
            //    del servicio (Art. IV) — jamás narra la frase presentada
            //    (RF-04.1: el cuerpo del acto no se registra).
            $auditStatement = $this->pdo->prepare(
                'INSERT INTO audit_log
                    (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
                 VALUES
                    (:actorUserId, :actorAlias, :actorRole, :actionType, :targetEntityType, :targetEntityId, :justification, :createdAt)'
            );
            $auditStatement->execute([
                ':actorUserId'      => $userId,
                ':actorAlias'       => (string) $userRow['alias'],
                ':actorRole'        => (string) $userRow['role'],
                ':actionType'       => 'PASSPHRASE_SELF_CHANGED',
                ':targetEntityType' => 'user',
                ':targetEntityId'   => $userId,
                ':justification'    => 'El adepto cambió su frase de paso desde su panel y las demás moradas quedaron vaciadas.',
                ':createdAt'        => $instant->format('Y-m-d\TH:i:s\Z'),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $custodyFailure) {
            $this->pdo->rollBack();
            throw $custodyFailure;
        }

        // Recibo del acto consumado (plan §2.6).
        return [
            'verdict'                => 'changed',
            'othersDissolvedCount'   => $othersDissolvedCount,
            'currentSessionPreserved' => true,
        ];
    }
}
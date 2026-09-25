<?php

/**
 * AvatarService.php — Ciclo de vida de la efigie del adepto
 * (SPEC-12, Tarea 2.1: el catálogo canónico y sus actos de elección).
 *
 * Cubre: RF-03.1 (catálogo del santuario, efecto inmediato y sin
 * moderación), RF-03.4 (el cambio surte efecto y el anterior deja de
 * referenciarse), RF-03.5 (retiro → canónico, la identidad jamás sin
 * efigie), RF-03.6 (asiento AVATAR_SELF_MODIFIED solo con efecto real;
 * los actos inocuos no se inscriben) y RNF-05 (trazabilidad).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, hash nativo, sin
 *     librerías de imágenes (el encuadre ceremonial llega con la
 *     Tarea 2.2 vía GD nativa).
 *   - Artículo IV (El Velo Arcano): la justificación del asiento es
 *     leyenda fija del servicio en noble castellano (plan §5.1: no
 *     editable, jamás narrada por el llamador).
 *   - Artículo V (Dualidad): identificadores en inglés camelCase,
 *     documentación en castellano.
 *
 * Naturaleza del catálogo (duda 5 sellada de SPEC-12): REUTILIZACIÓN
 * del arte canónico ya existente — los 8 sellos rúnicos de linaje de
 * SPEC-07/SPEC-09 (fuente única: `LineageSynergyService` y
 * `lineage_doctrines`), más la efigie del Custodio Fundacional y la del
 * Peregrino — sin encargo de arte nuevo. Las claves del catálogo son
 * contratos estables ('seal_<lineage>', 'custodian', 'pilgrim'); su
 * alta futura es acto fundacional ajeno al panel.
 *
 * La ÚNICA vía de escritura de la columna `avatar` sigue siendo el
 * repositorio del panel (UPDATE con guard); este servicio dicta CUÁNDO
 * y QUÉ se escribe, tras comparar hashes y juzgar el efecto real.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Repositories\UserPanelRepository;

final class AvatarService
{
    /** Veredicto del acto de elección: la efigie cambió (digna de asiento). */
    public const VERDICT_CHANGED = 'changed';

    /** Veredicto del acto inocuo: la efigie ya era la vigente (sin asiento). */
    public const VERDICT_IDENTICAL = 'identical';

    /** Prefijo de la referencia del contrato cerrado para efigies propias. */
    public const OWN_PREFIX = 'own:';

    /** Prefijo de la referencia del contrato cerrado para el catálogo. */
    public const CATALOG_PREFIX = 'catalog:';

    /** Formatos del canon de la subida (plan §5.2). */
    public const ALLOWED_FORMATS = ['png', 'jpg', 'webp'];

    /** Tope de peso de la imagen original, en bytes (2 MiB, plan §5.2). */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Techo de lados de la imagen original, en píxeles (plan §5.2). */
    public const MAX_SIDE = 1024;

    /** Lado del marco ceremonial efectivo tras el encuadre (plan §5.2). */
    public const FRAME_SIDE = 512;

    /**
     * El catálogo canónico en código (plan §1.1): reutilización del arte
     * existente por sello rúnico determinista. `heraldryKey` es la MISMA
     * clave que el Salón de Linajes y el distintivo consumen.
     *
     * @var list<array{id: string, kind: string, label: string, heraldryKey: string|null}>
     */
    private const CANONICAL_CATALOG = [
        ['id' => 'seal_primordialFlame', 'kind' => 'heraldry', 'label' => 'Llama Primordial', 'heraldryKey' => 'rune-ignis'],
        ['id' => 'seal_celestialTides', 'kind' => 'heraldry', 'label' => 'Mareas Celestiales', 'heraldryKey' => 'rune-aqua'],
        ['id' => 'seal_eternalTempest', 'kind' => 'heraldry', 'label' => 'Tempestad Eterna', 'heraldryKey' => 'rune-fulgur'],
        ['id' => 'seal_worldRoots', 'kind' => 'heraldry', 'label' => 'Raíces del Mundo', 'heraldryKey' => 'rune-terra'],
        ['id' => 'seal_dawnWinds', 'kind' => 'heraldry', 'label' => 'Vientos del Alba', 'heraldryKey' => 'rune-ventus'],
        ['id' => 'seal_solarCrown', 'kind' => 'heraldry', 'label' => 'Corona Solar', 'heraldryKey' => 'rune-lux'],
        ['id' => 'seal_abyssalShadows', 'kind' => 'heraldry', 'label' => 'Sombras Abisales', 'heraldryKey' => 'rune-tenebrae'],
        ['id' => 'seal_aetherWeavers', 'kind' => 'heraldry', 'label' => 'Tejedores del Éter', 'heraldryKey' => 'rune-arcana'],
        ['id' => 'custodian', 'kind' => 'effigy', 'label' => 'Custodio Fundacional', 'heraldryKey' => null],
        ['id' => 'pilgrim', 'kind' => 'effigy', 'label' => 'Peregrino del Umbral', 'heraldryKey' => null],
    ];

    /** El canal PDO del santuario (transacción del acto). */
    private \PDO $pdo;

    /** El repositorio del panel: la ÚNICA escritura de la columna. */
    private UserPanelRepository $panelRepository;

    /** Raíz física del almacenamiento de efigies propias (storage/avatars/). */
    private string $avatarsRoot;

    /** Proveedor del catálogo, inyectable para simular fallos en el arnés. */
    private ?\Closure $catalogProvider;

    /** Instante «ahora» inyectable para la aritmética de estampas. */
    private ?\DateTimeImmutable $now;

    public function __construct(
        \PDO $pdo,
        UserPanelRepository $panelRepository,
        string $avatarsRoot,
        ?\Closure $catalogProvider = null,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->pdo = $pdo;
        $this->panelRepository = $panelRepository;
        $this->avatarsRoot = $avatarsRoot;
        $this->catalogProvider = $catalogProvider;
        $this->now = $now;
    }

    /**
     * Sirve el catálogo canónico con el estado del adepto (plan §2.3).
     *
     * @return array{catalog: list<array{id: string, kind: string, label: string, heraldryKey: string|null}>, current: array{kind: string, reference: string|null}, ownAvatar: array{reference: string}|null, restricted: bool}
     *
     * @throws \RuntimeException Si el canon no pudiere servirse (el
     *                           controlador traduce el fallo en 500
     *                           AVATAR_CATALOG_UNAVAILABLE sin trazas).
     */
    public function catalogFor(string $userId, string $role, ?string $lineage): array
    {
        $catalog = $this->catalogProvider !== null
            ? ($this->catalogProvider)()
            : self::CANONICAL_CATALOG;
        if (!is_array($catalog)) {
            throw new \RuntimeException('El catálogo del santuario no pudo servirse.');
        }

        $vitals = $this->panelRepository->fetchUserVitals($userId);
        if ($vitals === null) {
            throw new \RuntimeException('El titular del catálogo no pudo confirmarse.');
        }

        $avatar = $this->resolveReadableAvatar($vitals['avatar'] ?? null);

        return [
            'catalog' => $catalog,
            'current' => ['kind' => $avatar['kind'], 'reference' => $avatar['reference']] + ($avatar['unavailable'] ? ['unavailable' => true] : []),
            'ownAvatar' => $avatar['kind'] === 'own' && $avatar['reference'] !== null && !$avatar['unavailable']
                ? ['reference' => $avatar['reference']]
                : null,
            'restricted' => $this->isPilgrim($role, $lineage),
        ];
    }

    /**
     * La lectura DEGRADABLE de la efigie (RF-03.5, Tarea 2.5, caso límite
     * 15): si la referencia es `own:<fileId>` y el fichero resulta
     * inaccesible o corrupto en el almacenamiento, la identidad degrada
     * al avatar canónico por defecto con la bandera discreta
     * `unavailable:true` — JAMÁS queda sin efigie ni la cámara rota. La
     * degradación es de SOLO-LECTURA: la fila no se muta, jamás se borra
     * la referencia ni se asienta acto alguno (un cataclismo no es un
     * acto de gobierno).
     *
     * @return array{kind: string, reference: string|null, isOwn: bool, unavailable: bool}
     */
    private function resolveReadableAvatar(mixed $rawReference): array
    {
        $avatar = self::parseReference($rawReference);

        if ($avatar['kind'] === 'own' && is_string($avatar['reference']) && $avatar['reference'] !== '') {
            $file = $this->avatarsRoot . DIRECTORY_SEPARATOR . basename($avatar['reference']);
            if (!is_file($file) || !is_readable($file) || filesize($file) === 0) {
                return ['kind' => 'default', 'reference' => null, 'isOwn' => false, 'unavailable' => true];
            }
        }

        $avatar['unavailable'] = false;

        return $avatar;
    }

    /**
     * Elige una efigie del catálogo con efecto inmediato (RF-03.1,
     * RF-03.4). Idempotente: re-elegir la vigente es acto inocuo y
     * responde `identical` sin asiento alguno (RF-03.6, caso límite 18).
     *
     * @return array{verdict: string, avatar: array{kind: string, reference: string|null, isOwn: bool}}
     *
     * @throws \InvalidArgumentException Si la efigie no vive en el canon.
     */
    public function chooseFromCatalog(string $userId, string $avatarId, string $updatedAtUtc): array
    {
        $known = null;
        foreach (self::CANONICAL_CATALOG as $entry) {
            if ($entry['id'] === $avatarId) {
                $known = $entry;
                break;
            }
        }
        if ($known === null) {
            throw new \InvalidArgumentException('Esa efigie no pertenece al canon del santuario.');
        }

        $reference = self::CATALOG_PREFIX . $avatarId;

        return $this->applyReference($userId, $reference, $updatedAtUtc);
    }

    /**
     * El alta de la efigie propia (RF-03.2, Tarea 2.2; plan §3.2 pasos 1–4).
     *
     * Maquinaria del encuadre ceremonial (Dogma Vanilla: GD nativa, sin
     * librerías externas):
     *   1. validación de formato ∈ {png, jpg, webp} ∧ peso ≤ 2 MiB ∧
     *      lados ≤ 1024 px — cada rechazo NOMBRA su motivo con una
     *      excepción propia (RF-03.2: la imagen no es secreto);
     *   2. recorte/encuadre al marco cuadrado 512×512 efectivos (centrado,
     *      una sola vez en el alta — sin redimensionado bajo demanda, §5.2);
     *   3. alta del fichero en storage/avatars/ con nombre aleatorio NO
     *      derivado del alias (RNF-04);
     *   4. escritura de la referencia `own:<fileId>` vía el acto común
     *      (hash del vigor: si la imagen resultante coincide con la
     *      vigente, el acto es inocuo y no se inscribe, RF-03.6).
     *
     * @param string $format Extensión declarada del fichero (png|jpg|webp).
     *
     * @return array{verdict: string, avatar: array{kind: string, reference: string|null, isOwn: bool}, fileId: string}
     *
     * @throws AvatarInvalidFormatException  Formato fuera del canon.
     * @throws AvatarTooLargeException       Peso sobre el tope de 2 MiB.
     * @throws AvatarDimensionsExceededException Lados sobre el techo de 1024 px.
     * @throws \RuntimeException             Fallo de decodificación o de almacenamiento.
     */
    public function uploadOwn(string $userId, string $sourcePath, string $format, string $updatedAtUtc): array
    {
        // --- Paso 1: las tres murallas de validación (RF-03.2) --------
        $normalizedFormat = strtolower(trim($format));
        if ($normalizedFormat === 'jpeg') {
            $normalizedFormat = 'jpg';
        }
        if (!in_array($normalizedFormat, self::ALLOWED_FORMATS, true)) {
            throw new \Grimorio\Exceptions\AvatarInvalidFormatException();
        }
        // La caché de stat de PHP puede servir el tamaño de una versión
        // anterior del fichero (reescrituras en el mismo proceso): el tope
        // se mide SIEMPRE contra el disco fresco.
        clearstatcache(true, $sourcePath);
        if (!is_file($sourcePath) || filesize($sourcePath) === false || filesize($sourcePath) > self::MAX_BYTES) {
            throw new \Grimorio\Exceptions\AvatarTooLargeException();
        }
        $dimensions = getimagesize($sourcePath);
        if ($dimensions === false) {
            throw new \RuntimeException('La imagen no pudo leerse.');
        }
        [$width, $height] = $dimensions;
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new \Grimorio\Exceptions\AvatarDimensionsExceededException();
        }

        // --- Paso 2: el encuadre ceremonial cuadrado (512×512) --------
        $decoder = [self::class, 'decodeImage' . ($normalizedFormat === 'jpg' ? 'Jpeg' : ucfirst($normalizedFormat))];
        $source = $decoder($sourcePath);
        if ($source === false) {
            throw new \RuntimeException('La imagen no pudo decodificarse.');
        }
        $frame = imagecreatetruecolor(self::FRAME_SIDE, self::FRAME_SIDE);
        // Recorte centrado cuadrado: el lado menor manda.
        $squareSide = min($width, $height);
        $cropX = (int) (($width - $squareSide) / 2);
        $cropY = (int) (($height - $squareSide) / 2);
        imagecopyresampled($frame, $source, 0, 0, $cropX, $cropY, self::FRAME_SIDE, self::FRAME_SIDE, $squareSide, $squareSide);
        imagedestroy($source);

        // --- Paso 3: el fichero con nombre aleatorio NO derivado del alias -
        if (!is_dir($this->avatarsRoot) && !@mkdir($this->avatarsRoot, 0777, true) && !is_dir($this->avatarsRoot)) {
            imagedestroy($frame);
            throw new \RuntimeException('El almacenamiento de efigies no está disponible.');
        }
        $fileId = bin2hex(random_bytes(12)) . '.png';
        $targetPath = $this->avatarsRoot . DIRECTORY_SEPARATOR . $fileId;
        if (!imagepng($frame, $targetPath)) {
            imagedestroy($frame);
            throw new \RuntimeException('La efigie no pudo escribirse.');
        }
        imagedestroy($frame);

        // --- Paso 3b: el juez del hash (plan §3.2 paso 3) ---------------
        // La re-subida idéntica se juzga por el CONTENIDO del resultado
        // encuadrado, no por el fichero original: dos imágenes distintas
        // con el mismo encuadre 512×512 son el mismo acto inocuo. El
        // fichero del candidato se purga SIEMPRE: o es sustituido por el
        // nuevo vigente (renombrado tras el commit) o era un duplicado.
        $candidateHash = hash_file('sha256', $targetPath);
        $currentHash = $this->vigentOwnHash($userId);
        if ($candidateHash !== null && $currentHash !== null && $candidateHash === $currentHash) {
            imagedestroy($frame);
            unlink($targetPath);
            throw new \Grimorio\Exceptions\AvatarIdenticalException();
        }

        // --- Paso 4: la escritura ATÓMICA (plan §3.2 pasos 4–6) --------
        try {
            $result = $this->atomicOwnAdoption($userId, $fileId, $targetPath, $updatedAtUtc);
        } catch (\Throwable $writeFailure) {
            // Atomicidad del alta (caso límite 16): o la imagen completa
            // validada entra, o nada cambia — el fichero huérfano se purga.
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            throw $writeFailure;
        }

        return [
            'verdict' => $result['verdict'],
            'avatar' => $result['avatar'],
            'fileId' => $fileId,
        ];
    }

    /**
     * La adopción ATÓMICA de la efigie propia (plan §3.2 pasos 4–6):
     * la transacción con el UPDATE de la columna, el reemplazo físico
     * del fichero (el anterior deja de existir, RF-03.4) y el INSERT del
     * asiento — si la Bitácora fracasa, TODO rueda atrás (RNF-05: el
     * acto sin trazabilidad no existe) y el huérfano se purga.
     *
     * @return array{verdict: string, avatar: array{kind: string, reference: string|null, isOwn: bool}}
     */
    private function atomicOwnAdoption(string $userId, string $fileId, string $targetPath, string $updatedAtUtc): array
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        $previousRaw = is_array($vitals) ? ($vitals['avatar'] ?? null) : null;
        $newReference = self::OWN_PREFIX . $fileId;

        $this->pdo->beginTransaction();
        try {
            // Paso 5: la ÚNICA escritura de la columna (guard del repositorio).
            $this->panelRepository->updateAvatar($userId, $newReference, $updatedAtUtc);

            // El reemplazo físico DENTRO de la transacción lógica: si algo
            // fracasa después, se restaura el fichero anterior.
            $previousOwnFile = is_string($previousRaw) && str_starts_with($previousRaw, self::OWN_PREFIX)
                ? substr($previousRaw, strlen(self::OWN_PREFIX))
                : null;
            $previousPath = $previousOwnFile !== null
                ? $this->avatarsRoot . DIRECTORY_SEPARATOR . basename($previousOwnFile)
                : null;
            $previousBackup = null;
            if ($previousPath !== null && is_file($previousPath)) {
                $previousBackup = $previousPath . '.reemplazado';
                if (!rename($previousPath, $previousBackup)) {
                    throw new \RuntimeException('El reemplazo físico de la efigie fracasó.');
                }
            }
            if (!rename($targetPath, $this->avatarsRoot . DIRECTORY_SEPARATOR . $fileId)) {
                throw new \RuntimeException('La efigie no pudo adoptarse.');
            }

            // Paso 6: el asiento de la Bitácora — DENTRO de la transacción
            // (plan §3.2: si falla 6 → ROLLBACK de 5, el acto sin
            // trazabilidad no existe, RNF-05). El commit solo llega cuando
            // columna, fichero y asiento viven o nada de ellos vive.
            $this->recordAuditEntry($userId, $newReference, $updatedAtUtc);
            $this->pdo->commit();

            // El anterior deja de referenciarse para siempre (RF-03.4).
            if ($previousBackup !== null) {
                @unlink($previousBackup);
            }
        } catch (\Throwable $adoptionFailure) {
            $this->pdo->rollBack();
            // Restauración física: el fichero anterior vuelve a su sitio.
            if (isset($previousBackup) && $previousBackup !== null && is_file($previousBackup)) {
                @rename($previousBackup, (string) $previousPath);
            }
            throw $adoptionFailure;
        }

        return [
            'verdict' => self::VERDICT_CHANGED,
            'avatar' => ['kind' => 'own', 'reference' => $fileId, 'isOwn' => true],
        ];
    }

    /**
     * ¿Es accesible el fichero de la efigie propia VIGENTE? (Tarea 2.5)
     *
     * Devuelve true si el adepto viste canónico o del catálogo (no hay
     * fichero propio que juzgar) o si su fichero vive y es legible;
     * false solo cuando la referencia es `own:<fileId>` y el fichero
     * resulta inaccesible o corrupto (caso límite 15). El controlador de
     * la vitrina lo consulta para vestir la degradación de solo-lectura.
     */
    public function isOwnAvatarFileAccessible(string $userId): bool
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        $currentRaw = is_array($vitals) ? ($vitals['avatar'] ?? null) : null;
        if (!is_string($currentRaw) || !str_starts_with($currentRaw, self::OWN_PREFIX)) {
            return true;
        }

        $file = $this->avatarsRoot . DIRECTORY_SEPARATOR
            . basename(substr($currentRaw, strlen(self::OWN_PREFIX)));

        return is_file($file) && is_readable($file) && filesize($file) !== 0;
    }

    /**
     * Hash SHA-256 del fichero de la efigie propia VIGENTE, o null si el
     * adepto viste canónico o del catálogo (el juez del hash solo rige
     * entre efigies propias).
     */
    private function vigentOwnHash(string $userId): ?string
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        $currentRaw = is_array($vitals) ? ($vitals['avatar'] ?? null) : null;
        if (!is_string($currentRaw) || !str_starts_with($currentRaw, self::OWN_PREFIX)) {
            return null;
        }

        $currentFile = $this->avatarsRoot . DIRECTORY_SEPARATOR
            . basename(substr($currentRaw, strlen(self::OWN_PREFIX)));
        if (!is_file($currentFile)) {
            return null;
        }

        return hash_file('sha256', $currentFile);
    }

    /** Decodificadores nativos GD del canon de formatos. */
    private static function decodeImagePng(string $path)
    {
        return imagecreatefrompng($path);
    }

    private static function decodeImageJpeg(string $path)
    {
        return imagecreatefromjpeg($path);
    }

    private static function decodeImageWebp(string $path)
    {
        return imagecreatefromwebp($path);
    }

    /**
     * Retira la efigie propia y vuelve al canónico por defecto
     * (RF-03.4, RF-03.5): el fichero físico se borra en el mismo acto
     * lógico y el anterior deja de referenciarse para siempre.
     *
     * @return array{verdict: string, avatar: array{kind: string, reference: string|null, isOwn: bool}}
     */
    public function removeOwn(string $userId, string $updatedAtUtc): array
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        $avatar = self::parseReference($vitals['avatar'] ?? null);

        // Ya viste el canónico: el retiro es inocuo (sin asiento ni mutación).
        if ($avatar['kind'] === 'default') {
            return [
                'verdict' => self::VERDICT_IDENTICAL,
                'avatar' => ['kind' => 'default', 'reference' => null, 'isOwn' => false],
            ];
        }

        // El borrado del fichero propio ANTES de la escritura: si el
        // retiro se consuma, el fichero jamás sobrevive a su olvido.
        if ($avatar['kind'] === 'own' && $avatar['reference'] !== null) {
            $this->deleteOwnFile($avatar['reference']);
        }

        return $this->applyReference($userId, null, $updatedAtUtc);
    }

    /**
     * El acto de escritura: efecto real → UPDATE + asiento; acto
     * inocuo → veredicto `identical` sin asiento ni mutación (RF-03.6).
     *
     * @return array{verdict: string, avatar: array{kind: string, reference: string|null, isOwn: bool}}
     */
    private function applyReference(string $userId, ?string $reference, string $updatedAtUtc): array
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);
        $currentRaw = is_array($vitals) ? ($vitals['avatar'] ?? null) : null;
        if ($currentRaw === $reference) {
            // Acto inocuo (RF-03.6): «la imagen ya viste tu identidad».
            // Ni escritura ni asiento: un acto sin efecto real no se
            // inscribe. La excepción porta el aviso noble específico.
            throw new \Grimorio\Exceptions\AvatarIdenticalException();
        }

        $this->panelRepository->updateAvatar($userId, $reference, $updatedAtUtc);
        $this->recordAuditEntry($userId, $reference, $updatedAtUtc);

        return [
            'verdict' => self::VERDICT_CHANGED,
            'avatar' => self::parseReference($reference),
        ];
    }

    /**
     * El asiento de Bitácora del acto (RF-03.6, plan §5.1): actor =
     * sujeto = el propio adepto; target_entity_type='user'; la
     * justification es leyenda fija del servicio, jamás del llamador.
     */
    private function recordAuditEntry(string $userId, ?string $reference, string $createdAtUtc): void
    {
        $entry = new \Grimorio\Models\AuditEntry(
            0,
            $userId,
            $this->aliasOf($userId),
            $this->roleOf($userId),
            'AVATAR_SELF_MODIFIED',
            'user',
            $userId,
            $reference === null
                ? 'El adepto retiró su efigie propia y vistió de nuevo el avatar canónico del santuario.'
                : 'El adepto vistió una nueva efigie de su identidad desde su panel.',
            $createdAtUtc,
        );
        $this->insertAuditRow($entry);
    }

    /** Inserta la fila del asiento por PDO preparado (la bitácora es inmutable). */
    private function insertAuditRow(\Grimorio\Models\AuditEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, actor_alias, actor_role, action_type, target_entity_type, target_entity_id, justification, created_at)
             VALUES (:actorUserId, :actorAlias, :actorRole, :actionType, :targetEntityType, :targetEntityId, :justification, :createdAt)'
        );
        $statement->execute([
            ':actorUserId' => $entry->getActorUserId(),
            ':actorAlias' => $entry->getActorAlias(),
            ':actorRole' => $entry->getActorRole(),
            ':actionType' => $entry->getActionType(),
            ':targetEntityType' => $entry->getTargetEntityType(),
            ':targetEntityId' => $entry->getTargetEntityId(),
            ':justification' => $entry->getJustification(),
            ':createdAt' => $entry->getCreatedAt(),
        ]);
    }

    /** Borra el fichero físico de la efigie propia (limpieza de huérfanos). */
    private function deleteOwnFile(string $fileId): void
    {
        $safeName = basename($fileId);
        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            return;
        }
        $path = $this->avatarsRoot . DIRECTORY_SEPARATOR . $safeName;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Traduce la referencia cruda de la columna al contrato del plan §2.1. */
    private static function parseReference(mixed $raw): array
    {
        if (is_string($raw) && str_starts_with($raw, self::CATALOG_PREFIX)) {
            return ['kind' => 'catalog', 'reference' => substr($raw, strlen(self::CATALOG_PREFIX)), 'isOwn' => false];
        }
        if (is_string($raw) && str_starts_with($raw, self::OWN_PREFIX)) {
            return ['kind' => 'own', 'reference' => substr($raw, strlen(self::OWN_PREFIX)), 'isOwn' => true];
        }

        return ['kind' => 'default', 'reference' => null, 'isOwn' => false];
    }

    /** ¿Es peregrino este vinculado (misma regla canónica del controlador)? */
    private function isPilgrim(string $role, ?string $lineage): bool
    {
        if ($role === 'supremeAdmin') {
            return false;
        }

        return $lineage === null || $lineage === '';
    }

    /** Alias y rol del adepto para el asiento (campos obligatorios de la bitácora). */
    private function aliasOf(string $userId): string
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);

        return is_array($vitals) ? (string) $vitals['alias'] : 'Adepto';
    }

    private function roleOf(string $userId): string
    {
        $vitals = $this->panelRepository->fetchUserVitals($userId);

        return is_array($vitals) ? (string) $vitals['role'] : 'reader';
    }

    /** Instante canónico de ahora (inyectable en los arneses). */
    private function nowUtc(): string
    {
        return ($this->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}

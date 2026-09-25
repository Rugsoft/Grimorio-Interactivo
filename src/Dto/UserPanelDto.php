<?php

/**
 * UserPanelDto.php — Carga útil de la vitrina del Panel del Adepto.
 *
 * Tarea 1.4 (TASKS-12): DTO inmutable y AUTOCONTENIDO que retrata la
 * identidad íntegra del vinculado para GET /api/v1/panel (SPEC-12,
 * plan §2.2). Se forja desde filas de base de datos —jamás instancia el
 * modelo `User`— al estilo de `GrimoirePageDto`, de modo que los arneses
 * lo cargan sin el autoload del front controller.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): serialización JSON nativa vía
 *     JsonSerializable + json_encode, sin librerías.
 *   - Artículo IV (El Velo Arcano): rótulos y leyendas en noble
 *     castellano, con la solemnidad del santuario.
 *   - Artículo V (Dualidad): claves técnicas en inglés camelCase,
 *     documentación en castellano. El panel JAMÁS imprime identificadores
 *     técnicos crudos (RF-02.1): el rol viaja como oficio castellano y la
 *     heráldica como sello rúnico del canon, jamás el identificador de
 *     usuario.
 *
 * Principio rector 2 de SPEC-12: el panel es vitrina de datos ya
 * ratificados; este DTO solo VISTE y NARRA, jamás computa verdad nueva.
 */

declare(strict_types=1);

namespace Grimorio\Dto;

use JsonSerializable;

/**
 * Vitrina del Panel del Adepto (contrato exacto del plan §2.2).
 */
final class UserPanelDto implements JsonSerializable
{
    /**
     * Mapa espejo canónico de los OCHO Linajes (el MISMO vocabulario del
     * distintivo y del Salón de Linajes): clave técnica → rótulo
     * castellano solemne (RNF-01). Fuente de verdad sembrada:
     * `lineage_doctrines` (SPEC-07/SPEC-09).
     */
    private const LINEAGE_LABELS = [
        'primordialFlame' => 'Linaje de la Llama Primordial',
        'celestialTides'  => 'Linaje de las Mareas Celestiales',
        'eternalTempest'  => 'Linaje de la Tempestad Eterna',
        'worldRoots'      => 'Linaje de las Raíces del Mundo',
        'dawnWinds'       => 'Linaje de los Vientos del Alba',
        'solarCrown'      => 'Linaje de la Corona Solar',
        'abyssalShadows'  => 'Linaje de las Sombras Abisales',
        'aetherWeavers'   => 'Linaje de los Tejedores del Éter',
    ];

    /**
     * Heráldica canónica por sello rúnico (la MISMA representación que
     * SPEC-07/SPEC-09 forjan, RF-02.1): glifo de la doctrina sembrada.
     */
    private const LINEAGE_HERALDRY = [
        'primordialFlame' => 'rune-ignis',
        'celestialTides'  => 'rune-aqua',
        'eternalTempest'  => 'rune-fulgur',
        'worldRoots'      => 'rune-terra',
        'dawnWinds'       => 'rune-ventus',
        'solarCrown'      => 'rune-lux',
        'abyssalShadows'  => 'rune-tenebrae',
        'aetherWeavers'   => 'rune-arcana',
    ];

    /**
     * Mapa espejo del oficio (RF-02.1, RF-02.4): el MISMO vocabulario del
     * `roleLegend` del distintivo de cabecera (commit 6c21b99). Jamás se
     * imprime el rol técnico ni identificadores crudos (Art. V).
     */
    private const ROLE_LABELS = [
        'reader'       => 'Lector',
        'editor'       => 'Adepto',
        'master'       => 'Maestro del Códice',
        'supremeAdmin' => 'Admin Supremo',
    ];

    /** Leyenda solemne del linaje no reconocido (RF-02.3, caso límite 6). */
    public const UNKNOWN_LINEAGE_LEGEND = 'Linaje jurado';

    /** Leyenda del peregrino sin linaje (RF-01.3). */
    public const PILGRIM_LEGEND = 'Peregrino sin Linaje';

    /** Claves técnicas de sesión del atributo User-Agent (RF-02.1). */
    private const DEVICE_LABELS = [
        'postmanruntime' => 'Enlace de prueba',
        'curl'           => 'Enlace de prueba',
        'python'         => 'Enlace de prueba',
        'php'            => 'Enlace de prueba',
        'mozillafirefox' => 'Atalaya del zorro',
        'firefox'        => 'Atalaya del zorro',
        'edg'            => 'Atalaya del borde',
        'chrome'         => 'Atalaya del explorador',
        'safari'         => 'Atalaya del navegante',
        'windows'        => 'Atalaya del explorador',
        'linux'          => 'Atalaya del explorador',
        'macintosh'      => 'Atalaya del navegante',
        'android'        => 'Atalaya errante',
        'iphone'         => 'Atalaya errante',
        'ipad'           => 'Atalaya errante',
    ];

    /** Etiqueta del vínculo desconocido. */
    private const DEVICE_FALLBACK = 'Enlace arcano';

    /** Preámbulo solemne de la retención del avatar (RF-01.3). */
    private const AVATAR_RESTRICTION_LEGEND = 'Tu efigie aguarda al juramento: la ceremonia te espera.';

    /**
     * @param array<string, mixed> $identity  La identidad íntegra (contrato §2.2).
     * @param array<string, mixed> $lineage   El linaje jurado o su retención.
     * @param array<string, mixed>|null $clan La hermandad o su silencio.
     * @param array<string, mixed> $session   El vínculo de sesión vivo.
     * @param array<string, mixed>|null $convalescence La penitencia o su silencio.
     * @param array<string, int>   $collection Contadores del tomo.
     * @param array<string, int>|null $masterDuties Los deberes del Maestro o null.
     * @param array<string, mixed>|null $weeklyGlory La gloria semanal o su leyenda.
     * @param bool  $avatarRestricted Retención del peregrino sobre el avatar.
     */
    private function __construct(
        public readonly array $identity,
        public readonly array $lineage,
        public readonly ?array $clan,
        public readonly array $session,
        public readonly ?array $convalescence,
        public readonly array $collection,
        public readonly ?array $masterDuties,
        public readonly ?array $weeklyGlory,
        public readonly bool $avatarRestricted,
    ) {
    }

    /**
     * Forja la vitrina desde las filas canónicas ya leídas por el
     * repositorio (patrón autocontenido, sin instanciar User).
     *
     * @param array<string, mixed>|null   $vitalsRow        Fila de `users` (fetchUserVitals).
     * @param array<string, mixed>|null   $membershipRow    Membresía activa o null.
     * @param array<string, mixed>|null   $clanRow          Fila de `clans` o null.
     * @param string|null                 $oathSwornAt      Estampa del juramento o null.
     * @param array<string, int>|null     $masterDuties     Deberes del Maestro o null.
     * @param array<string, mixed>|null   $weeklyGloryRow   Gloria semanal o null.
     * @param string                      $sessionCreatedAt Nacimiento del vínculo (ISO 8601).
     * @param string                      $sessionExpiresAt Expiración del vínculo (ISO 8601).
     * @param string                      $sessionDevice    Cliente declarado del vínculo.
     * @param bool                        $isCurrentSession ¿Es el vínculo de la petición viva?
     * @param int                         $sealedCount      Obras selladas en el tomo (RF-07.1).
     * @param int                         $praiseCount      Homenajes rendidos (RF-07.1).
     * @param bool                        $ownAvatarUnavailable Bandera discreta del fichero propio inaccesible (Tarea 2.5, caso límite 15).
     */
    public static function fromRows(
        ?array $vitalsRow,
        ?array $membershipRow,
        ?array $clanRow,
        ?string $oathSwornAt,
        ?array $masterDuties,
        ?array $weeklyGloryRow,
        string $sessionCreatedAt = '',
        string $sessionExpiresAt = '',
        string $sessionDevice = '',
        bool $isCurrentSession = true,
        int $sealedCount = 0,
        int $praiseCount = 0,
        bool $ownAvatarUnavailable = false,
    ): self {
        // --- La identidad íntegra (RF-02.1, RF-02.4) --------------------
        $role = (string) ($vitalsRow['role'] ?? 'reader');
        $lineageKey = isset($vitalsRow['lineage']) && is_string($vitalsRow['lineage']) && $vitalsRow['lineage'] !== ''
            ? $vitalsRow['lineage']
            : null;

        return new self(
            identity: [
                'alias' => (string) ($vitalsRow['alias'] ?? ''),
                'email' => (string) ($vitalsRow['email'] ?? ''),
                'roleLabel' => self::ROLE_LABELS[$role] ?? self::ROLE_LABELS['reader'],
                'avatar' => self::forgeAvatar($vitalsRow['avatar'] ?? null, $ownAvatarUnavailable),
            ],
            lineage: self::forgeLineage($lineageKey, $oathSwornAt),
            clan: self::forgeClan($clanRow, $membershipRow),
            session: self::forgeSession($sessionCreatedAt, $sessionExpiresAt, $sessionDevice, $isCurrentSession),
            convalescence: self::forgeConvalescence($membershipRow['convalescence_expires_at'] ?? null),
            collection: [
                'sealedCount' => max(0, $sealedCount),
                'praiseCount' => max(0, $praiseCount),
            ],
            masterDuties: $masterDuties === null
                ? null
                : [
                    'pendingCount'   => max(0, (int) ($masterDuties['pending'] ?? 0)),
                    'retractedCount' => max(0, (int) ($masterDuties['retracted'] ?? 0)),
                    'annulledCount'  => max(0, (int) ($masterDuties['annulled'] ?? 0)),
                ],
            weeklyGlory: self::forgeWeeklyGlory($weeklyGloryRow),
            avatarRestricted: self::isPilgrim($role, $lineageKey),
        );
    }

    /**
     * Colección sellada y homenajeada del tomo (RF-07.1): las cifras
     * llegan ya computadas por el repositorio, jamás de cómputos nuevos.
     */
    public function withCollection(int $sealedCount, int $praiseCount): self
    {
        return new self(
            identity: $this->identity,
            lineage: $this->lineage,
            clan: $this->clan,
            session: $this->session,
            convalescence: $this->convalescence,
            collection: ['sealedCount' => max(0, $sealedCount), 'praiseCount' => max(0, $praiseCount)],
            masterDuties: $this->masterDuties,
            weeklyGlory: $this->weeklyGlory,
            avatarRestricted: $this->avatarRestricted,
        );
    }

    /**
     * Serialización JSON nativa: EXACTAMENTE el contrato del plan §2.2.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'identity' => $this->identity,
            'lineage' => $this->lineage,
            'clan' => $this->clan,
            'session' => $this->session,
            'convalescence' => $this->convalescence,
            'collection' => $this->collection,
            'masterDuties' => $this->masterDuties,
            'weeklyGlory' => $this->weeklyGlory,
            'avatarRestricted' => $this->avatarRestricted,
        ];
    }

    /**
     * La efigie vigente (RF-03.x): la semántica cerrada de la columna
     * `avatar` se traduce aquí (NULL → canónico por defecto;
     * 'catalog:<id>' → efigie del catálogo; 'own:<fileId>' → efigie
     * propia), jamás en la base. Con el fichero propio inaccesible, la
     * degradación de solo-lectura (Tarea 2.5, caso límite 15) viste el
     * canónico con la bandera discreta `unavailable:true` — la identidad
     * jamás queda sin efigie ni la cámara rota.
     *
     * @return array{kind: string, reference: string|null, isOwn: bool, unavailable?: bool}
     */
    private static function forgeAvatar(mixed $rawAvatar, bool $ownAvatarUnavailable = false): array
    {
        if (is_string($rawAvatar) && str_starts_with($rawAvatar, 'catalog:')) {
            return ['kind' => 'catalog', 'reference' => substr($rawAvatar, 8), 'isOwn' => false];
        }

        if (is_string($rawAvatar) && str_starts_with($rawAvatar, 'own:')) {
            if ($ownAvatarUnavailable) {
                // Degradación de SOLO-LECTURA: la fila jamás se muta.
                return ['kind' => 'default', 'reference' => null, 'isOwn' => false, 'unavailable' => true];
            }

            return ['kind' => 'own', 'reference' => substr($rawAvatar, 4), 'isOwn' => true];
        }

        return ['kind' => 'default', 'reference' => null, 'isOwn' => false];
    }

    /**
     * El linaje jurado (RF-02.1, RF-02.3): rótulo del canon o leyenda
     * neutra ante linaje legado ajeno al catálogo, sin heráldica inventada.
     *
     * @return array{key: string|null, label: string, swornAt: string|null, heraldryKey: string|null, isUnknownLegacy: bool}
     */
    private static function forgeLineage(?string $lineageKey, ?string $oathSwornAt): array
    {
        if ($lineageKey === null) {
            return [
                'key' => null,
                'label' => self::PILGRIM_LEGEND,
                'swornAt' => null,
                'heraldryKey' => null,
                'isUnknownLegacy' => false,
            ];
        }

        $knownLegacy = isset(self::LINEAGE_LABELS[$lineageKey]);

        return [
            'key' => $lineageKey,
            'label' => $knownLegacy
                ? self::LINEAGE_LABELS[$lineageKey]
                : self::UNKNOWN_LINEAGE_LEGEND,
            'swornAt' => $oathSwornAt,
            'heraldryKey' => $knownLegacy ? self::LINEAGE_HERALDRY[$lineageKey] : null,
            'isUnknownLegacy' => !$knownLegacy,
        ];
    }

    /**
     * La hermandad (RF-02.2): el espejo `users.clan_id` es solo vía
     * rápida; la AUTORIDAD de la membresía es `clan_members` (SPEC-07).
     * Sin membresía activa no hay clan, aunque el espejo lo susurre.
     *
     * @param array<string, mixed>|null $clanRow
     * @param array<string, mixed>|null $membershipRow
     *
     * @return array{id: string, name: string, state: string, heraldryKey: string|null, joinedAt: string}|null
     */
    private static function forgeClan(?array $clanRow, ?array $membershipRow): ?array
    {
        if ($membershipRow === null || $clanRow === null) {
            return null;
        }

        $heraldryKey = (string) ($clanRow['coat_of_arms'] ?? '');
        if ($heraldryKey === '') {
            $heraldryKey = null;
        }

        return [
            // El identificador técnico del VÍNCULO propio viaja como clave
            // de contrato (camino de retorno del panel a la cámara del
            // clan, RF-08.2); jamás se imprime en rótulo visible alguno.
            'id' => (string) $clanRow['id'],
            'name' => (string) $clanRow['name'],
            'state' => (string) ($clanRow['status'] ?? 'active'),
            'heraldryKey' => $heraldryKey,
            'joinedAt' => (string) ($membershipRow['joined_at'] ?? ''),
        ];
    }

    /**
     * El vínculo de sesión vivo (RF-02.1): qué morada es la presente y
     * cuándo expira, narrado sin jerga técnica (Art. V/RNF-01).
     *
     * @return array{deviceLabel: string, createdAt: string, expiresAt: string, isCurrent: bool}
     */
    private static function forgeSession(string $createdAt, string $expiresAt, string $device, bool $isCurrentSession): array
    {
        $needle = strtolower(preg_replace('/[^a-z0-9]+/i', '', $device) ?? '');
        $deviceLabel = self::DEVICE_FALLBACK;
        foreach (self::DEVICE_LABELS as $signature => $label) {
            if ($signature !== '' && str_contains($needle, $signature)) {
                $deviceLabel = $label;
                break;
            }
        }

        return [
            'deviceLabel' => $deviceLabel,
            'createdAt' => $createdAt,
            'expiresAt' => $expiresAt,
            'isCurrent' => $isCurrentSession,
        ];
    }

    /**
     * La penitencia con fecha (RF-05.1): el conjunto EXACTO de
     * retenciones que SPEC-07 RF-01.6 ratifica — ingresar a otro clan o
     * fundar una nueva hermandad —, sin ampliarlo ni menguarlo.
     *
     * @return array{expiresAt: string, daysRemaining: int, causeLegend: string, previousClanName: string|null, retainedLegend: string}|null
     */
    private static function forgeConvalescence(mixed $expiresAtRaw): ?array
    {
        if (!is_string($expiresAtRaw) || $expiresAtRaw === '') {
            // Sin convalecencia: el silencio es el estado saludable (RF-05.3).
            return null;
        }

        $expiresAt = new \DateTimeImmutable($expiresAtRaw, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $daysRemaining = 0;
        if ($expiresAt > $now) {
            // Aritmética con alza al entero superior, espejo EXACTO de
            // ClanVestibuleService (plan §3.1): el backend y el banner
            // jamás disienten sobre «restan X».
            $daysRemaining = (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
        }

        return [
            'expiresAt' => $expiresAtRaw,
            'daysRemaining' => $daysRemaining,
            'causeLegend' => 'Descansas en Convalecencia Arcana tras partir de tu antigua hermandad.',
            'previousClanName' => null,
            'retainedLegend' => 'Mientras dure la penitencia no podrás ingresar a otro clan ni fundar una nueva hermandad.',
        ];
    }

    /**
     * La gloria semanal del clan (RF-07.3): SI existe cómputo vigente se
     * exhibe con su semana; SI no, la leyenda canónica sin cifras
     * fantasma (decisión sellada §9 de SPEC-12).
     *
     * @param array<string, mixed>|null $weeklyGloryRow
     *
     * @return array{weekLabel: string, points: int}|null
     */
    private static function forgeWeeklyGlory(?array $weeklyGloryRow): ?array
    {
        if ($weeklyGloryRow === null || (int) ($weeklyGloryRow['weekly_points'] ?? 0) <= 0) {
            return null;
        }

        $weekNumber = max(1, (int) ($weeklyGloryRow['week_number'] ?? 1));
        $cycleYear = max(2000, (int) ($weeklyGloryRow['cycle_year'] ?? (int) date('Y')));

        return [
            'weekLabel' => "Semana {$weekNumber} de {$cycleYear}",
            'points' => max(0, (int) $weeklyGloryRow['weekly_points']),
        ];
    }

    /** ¿Es peregrino este vinculado (retención de sustancia, SPEC-09)? */
    private static function isPilgrim(string $role, ?string $lineageKey): bool
    {
        // El Admin Supremo — con o sin linaje legado — accede sin
        // retención de ceremonia (RF-01.5, estado fundacional).
        if ($role === 'supremeAdmin') {
            return false;
        }

        return $lineageKey === null;
    }
}

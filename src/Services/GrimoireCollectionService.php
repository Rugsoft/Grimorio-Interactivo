<?php

/**
 * GrimoireCollectionService.php — El rito del Tomo Personal
 * (SPEC-11, Fase 2).
 *
 * Cubre: RF-01 (el gesto de adición), RF-02 (consulta y retirada),
 * RF-03.2 (el mapa único de estados a marcas solemnes) y RNF-02
 * (PDO preparado a través del repositorio).
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): sin dependencias; la persistencia vive
 *     en `GrimoireCollectionRepository` (PDO preparado).
 *   - Art. V (Dualidad): identificadores en inglés camelCase,
 *     documentación y leyendas en noble castellano.
 *
 * Reparto de responsabilidades: este servicio JUZGA y orquesta; el
 * repositorio mide y persiste. Aquí viven las reglas de negocio del
 * tomo —qué hechizo es sellable, qué marca solemne viste cada entrada—
 * y las guardias ordenadas de los ritos (Fases 2 y 3 de TASKS-11).
 *
 * EL MAPA ÚNICO (RF-03.2, hallazgos 12 y 21 de la QA): la traducción
 * del ciclo de vida del santuario (`draft`/`experimental`/`validated`/
 * `rejected`/`archived` en `spell_reviews.status`) a las marcas del
 * tomo vive UNA sola vez, en `tomeMarkForStatus()`. El frontend jamás
 * duplica la lógica — recibe la marca en el DTO — y si el ciclo de
 * vida de SPEC-08 mudara, este mapa es el único lugar que miente.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Exceptions\UniformSealVetoException;
use Grimorio\Models\User;
use Grimorio\Repositories\GrimoireCollectionRepository;
use InvalidArgumentException;
use PDO;

/**
 * Servicio del Tomo Personal: marcas solemnes y ritos del tomo.
 *
 * Las tres marcas canónicas son EXACTAMENTE tres (hallazgo 21): nada
 * de jerga de revisión ni de estados técnicos en la interfaz. Cada
 * marca viste un grupo de estados del ciclo de vida y ninguno queda
 * sin marca: la colección es memoria del adepto y jamás elimina una
 * entrada por detrás (caso límite 2, hallazgo 12).
 */
final class GrimoireCollectionService
{
    /** Obra viva del canon: convocatoria y lectura plenas (RF-03.1). */
    public const TOME_MARK_LIVING = 'living';

    /** Obra en maduración: la convocatoria aguarda su sellado (RF-03.2). */
    public const TOME_MARK_GESTATION = 'gestation';

    /** Obra apartada del canon: la memoria del adepto la conserva (RF-03.2). */
    public const TOME_MARK_WITHDRAWN = 'withdrawn';

    /** El estado que el Tribunal de las Tres Firmas consagra (SPEC-08). */
    public const SPELL_STATUS_VALIDATED = 'validated';

    /** Obra privada del autor, aún no expuesta al Atrio. */
    public const SPELL_STATUS_DRAFT = 'draft';

    /** Obra expuesta en el Atrio de Pruebas, en deliberación. */
    public const SPELL_STATUS_EXPERIMENTAL = 'experimental';

    /** Obra vetada por el Tribunal con observaciones. */
    public const SPELL_STATUS_REJECTED = 'rejected';

    /** Obra desterrada del canon o conservada como Herencia Ancestral. */
    public const SPELL_STATUS_ARCHIVED = 'archived';

    /** Los cinco estados del ciclo de vida (catálogo cerrado de SPEC-08). */
    private const CANONICAL_SPELL_STATUSES = [
        self::SPELL_STATUS_DRAFT,
        self::SPELL_STATUS_EXPERIMENTAL,
        self::SPELL_STATUS_VALIDATED,
        self::SPELL_STATUS_REJECTED,
        self::SPELL_STATUS_ARCHIVED,
    ];

    /** El repositorio del tomo: único canal de persistencia. */
    private GrimoireCollectionRepository $repository;

    /** La pluma de la Bitácora (contrato, no implementación: RF-06). */
    private AuditRecorderInterface $auditService;

    /** El canal PDO para la lectura viva del estado del hechizo. */
    private PDO $pdo;

    public function __construct(
        GrimoireCollectionRepository $repository,
        AuditRecorderInterface $auditService,
        PDO $pdo,
    ) {
        $this->repository = $repository;
        $this->auditService = $auditService;
        $this->pdo = $pdo;
    }

    /**
     * Traduce un estado del ciclo de vida a su marca solemne del tomo
     * (RF-03.2, mapa único del plan §3.1).
     *
     *   validated              → living      (convocatoria y lectura plenas)
     *   draft | experimental   → gestation   («obra en gestación»)
     *   rejected | archived    → withdrawn   («obra apartada del canon»)
     *
     * Un estado fuera del catálogo cerrado de SPEC-08 lanza: jamás se
     * inventa una marca que la especificación no conoce.
     *
     * @param string $status Estado de `spell_reviews.status`.
     * @return string La marca canónica ('living' | 'gestation' | 'withdrawn').
     * @throws InvalidArgumentException Ante un estado fuera del catálogo.
     */
    public static function tomeMarkForStatus(string $status): string
    {
        return match ($status) {
            self::SPELL_STATUS_VALIDATED => self::TOME_MARK_LIVING,
            self::SPELL_STATUS_DRAFT,
            self::SPELL_STATUS_EXPERIMENTAL => self::TOME_MARK_GESTATION,
            self::SPELL_STATUS_REJECTED,
            self::SPELL_STATUS_ARCHIVED => self::TOME_MARK_WITHDRAWN,
            default => throw new InvalidArgumentException(
                "Estado de conjuro fuera del catálogo del ciclo de vida: '{$status}'."
            ),
        };
    }

    /**
     * ¿Es este estado sellable en el tomo? (RF-01.1, RF-04.5)
     *
     * La guardia única que comparten el sellado y el elogio: solo la
     * obra consagrada por el Tribunal entra al tomo o recibe homenaje.
     * La leyenda que niega es UNIFORME (RF-01.2, hallazgo 4) y vive en
     * la interfaz; aquí solo se juzga el hecho.
     */
    public static function isSellableStatus(string $status): bool
    {
        return $status === self::SPELL_STATUS_VALIDATED;
    }

    /**
     * Leyenda UNIFORME del vedado de sellado (RF-01.2, hallazgo 4).
     *
     * Una sola voz ante CUALQUIER estado no validado: sin nombrar el
     * estado concreto ni filtrar por rol. Texto LITERAL del Anexo A
     * del plan (leyenda 5).
     */
    public const UNIFORM_SEAL_VETO_LEGEND = 'Solo lo que el Tribunal ha sellado entra al tomo.';

    /**
     * EL RITO DEL SELLADO con sus guardias ordenadas (RF-01, plan §3.2).
     *
     * El orden es contrato y no casualidad — cada guardia responde antes
     * de que la siguiente pueda hablar:
     *
     *   1. linaje jurado  → 403 LINEAGE_OATH_REQUIRED (el linaje manda,
     *      no el rol — hallazgo 16; la retención de SPEC-09 retoma el
     *      flujo desde el controlador).
     *   2. existencia     → 404 SPELL_NOT_FOUND.
     *   3. idempotencia   → sellado ya presente: `alreadyCollected = true`
     *      («Ya está en tu tomo», 200; jamás gloria — hallazgos 2-3).
     *   4. estado         → leyenda UNIFORME ante cualquier no validado
     *      (RF-01.2; 403 sin sellar).
     *   5. INSERT         → fila nueva; la muralla UNIQUE de la Tarea 1.1
     *      resuelve la carrera de doble pestaña (caso límite 5).
     *   6. Bitácora       → asiento TOME_SEAL con su estampa (RF-06.1).
     *
     * El sellado es un acto privado de estudio: JAMÁS acredita gloria ni
     * toca la mesa del Dominio (hallazgos 2-3).
     *
     * @param User  $adepto      El adepto que sella (sesión resuelta por
     *                            el middleware; el anónimo jamás llega).
     * @param string $spellId    Identificador del hechizo a sellar.
     * @param string $spellStatus Estado VIVO del hechizo en este instante
     *                            (leído por el controlador de `spells`).
     *
     * @return array{alreadyCollected: bool, addedAt: string} El eco del
     *         sellado: nuevo (201) o idempotente (200).
     *
     * @throws \Grimorio\Exceptions\LineageOathException Guardia 1 (403).
     * @throws \Grimorio\Exceptions\SpellNotFoundException Guardia 2 (404).
     */
    public function collectSpell(User $adepto, string $spellId, string $spellStatus): array
    {
        // ---- Guardia 1: el linaje manda, no el rol (hallazgo 16) --------
        // Peregrino, lector sin jurar, editor sin jurar: una sola regla
        // para todos los roles. La leyenda es la MISMA voz de SPEC-09.
        if ($adepto->getLineage() === null) {
            throw \Grimorio\Exceptions\LineageOathException::lineageOathRequired(
                'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.'
            );
        }

        // ---- Guardia 2: existencia del hechizo (404) -------------------
        $spellRow = $this->requireSpellRow($spellId);

        // ---- Guardia 3: idempotencia antes del juicio de estado --------
        // SI el hechizo ya vive en el tomo, responde «Ya está en tu tomo»
        // sin importar su estado actual: la fila existe y la memoria del
        // adepto no se re-escribe (RF-01.3). El estado solo se juzga
        // cuando el sellado sería NUEVO.
        if ($this->repository->existsForUser($adepto->getId(), $spellId)) {
            return [
                'alreadyCollected' => true,
                'addedAt' => $this->addedAtOf($adepto->getId(), $spellId),
            ];
        }

        // ---- Guardia 4: leyenda UNIFORME ante cualquier no validado ----
        // La leyenda jamás revela cuál de los cuatro estados falló
        // (hallazgo 4): el texto canónico del Anexo A, una sola voz.
        $status = (string) ($spellRow['status'] ?? $spellStatus);
        if (!self::isSellableStatus($status)) {
            throw new UniformSealVetoException();
        }

        // ---- Guardia 5-6: INSERT + asiento de Bitácora (RF-06.1) -------
        // La muralla UNIQUE de la Tarea 1.1 resuelve la carrera de doble
        // pestaña: si la base recibe el par duplicado, add() devuelve
        // false y el rito degenera en idempotencia (caso límite 5).
        $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $addedAtUtc = $instant->format(DateTimeInterface::ATOM);
        $wasNew = $this->repository->add($adepto->getId(), $spellId, $addedAtUtc);

        if ($wasNew) {
            // El asiento SOLO nace con el sellado original: el eco
            // idempotente jamás duplica el acto en la Bitácora (RF-06.1,
            // patrón de SPEC-10).
            $this->auditService->recordAction(
                actorUserId: $adepto->getId(),
                actorAlias: $adepto->getAlias(),
                actorRole: $adepto->getRole(),
                actionType: 'TOME_SEAL',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: "Selló el conjuro en su tomo personal.",
                now: $instant,
            );
            return ['alreadyCollected' => false, 'addedAt' => $addedAtUtc];
        }

        // Carrera perdida ante la muralla: respuesta idempotente.
        return [
            'alreadyCollected' => true,
            'addedAt' => $this->addedAtOf($adepto->getId(), $spellId),
        ];
    }

    /**
     * Lee la fila VIVA del hechizo con su estado (guardia de existencia
     * y fuente de verdad del ciclo de vida — `spells.status`, espejo
     * mantenido por SpellReviewRepository). Lanza 404 si no existe.
     *
     * @return array<string, mixed> Fila mínima del hechizo (id, status).
     */
    private function requireSpellRow(string $spellId): array
    {
        $statement = $this->pdo->prepare('SELECT id, status FROM spells WHERE id = :spellId');
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw \Grimorio\Exceptions\SpellNotFoundException::forSpellId($spellId);
        }

        return $row;
    }

    /**
     * LA RETIRADA DEL TOMO (RF-02.4, plan §3.5; casos límite 9 y 10).
     *
     * La muralla de intimidad vive en el repositorio (`remove()` exige
     * fila propia); aquí se juzga el resultado y se traduce a contrato:
     * retirada consumada → el nuevo total del tomo (200); fila ausente
     * → 409 `SPELL_NOT_IN_TOME` (el hechizo puede vivir en el catálogo:
     * lo que no existe es su entrada en ESTE tomo).
     *
     * Frontera sagrada (hallazgo 13): la retirada JAMÁS toca `favorites`
     * — el elogio es un acto de gloria para el clan del autor, no una
     * pata de la colección. Cada rito vive su vida.
     *
     * @param User   $adepto   El adepto dueño del tomo.
     * @param string $spellId  Identificador del hechizo a retirar.
     * @param string|null $elementalAffinity Filtro de afinidad activo en
     *        la vista (para que el total devuelto describa el conjunto
     *        exhibido, no otro).
     *
     * @return array{removed: bool, total: int} El eco de la retirada con
     *         el total ACTUALIZADO del tomo bajo el filtro vigente.
     *
     * @throws \Grimorio\Exceptions\SpellNotInTomeException 409 (fila ausente).
     */
    public function discardSpell(User $adepto, string $spellId, ?string $elementalAffinity = null): array
    {
        $removed = $this->repository->remove($adepto->getId(), $spellId);
        if (!$removed) {
            throw \Grimorio\Exceptions\SpellNotInTomeException::forSpell($spellId);
        }

        // El total viaja ACTUALIZADO bajo el filtro vigente (RF-02.4):
        // la vista actualiza el rótulo sin una segunda petición.
        return [
            'removed' => true,
            'total' => $this->repository->countForUser($adepto->getId(), $elementalAffinity),
        ];
    }

    /**
     * LA PAGINACIÓN VIVA (plan §3.5; caso límite 10).
     *
     * Tras una retirada, la página corriente puede haber quedado más
     * allá de la última página viva. Este cálculo decide el destino:
     * la página corriente si aún es válida, o la página válida MÁS
     * CERCANA si quedó vaciada — siempre conservando el filtro activo
     * y sin pantallas fantasma ni huecos.
     *
     * @param int $requestedPage La página que la vista está mostrando (base 1).
     * @param int $total         El total de entradas del tomo bajo el filtro vigente.
     * @param int $limit         El tamaño de página (50 en el contrato).
     *
     * @return int La página destino (base 1), siempre >= 1 y <= totalPages.
     */
    public static function resumePageFor(int $requestedPage, int $total, int $limit): int
    {
        // Candados del contrato: límite mínimo 1 y página mínima 1, jamás
        // OFFSET negativo ni división por cero (misma defensa del plan §3.5).
        $safeLimit = max(1, $limit);
        $totalPages = max(1, (int) ceil($total / $safeLimit));
        $safePage = max(1, $requestedPage);

        return min($safePage, $totalPages);
    }

    /**
     * Estado VIVO del hechizo para las guardias del controlador (plan §3.3,
     * puerta del elogio): existencia (404) y juicio de `validated` (409).
     *
     * @throws SpellNotFoundException Si el hechizo no existe (guardia 3).
     */
    public function spellStatusFor(string $spellId): string
    {
        return (string) $this->requireSpellRow($spellId)['status'];
    }

    /**
     * Heráldica de un hechizo para el asiento de Bitácora (plan §2.3,
     * RF-06.2): el TOME_PRAISE debe NOMBRAR obra y casa destinataria de la
     * gloria (hallazgo 20). Solo se llama tras la guardia de existencia,
     * de modo que la fila siempre está.
     *
     * @return array{spellName: string, clanName: string}
     */
    public function spellHeraldryFor(string $spellId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.name AS spellName, c.name AS clanName
               FROM spells s
               JOIN clans c ON c.id = s.clan_id
              WHERE s.id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw \Grimorio\Exceptions\SpellNotFoundException::forSpellId($spellId);
        }

        return [
            'spellName' => (string) $row['spellName'],
            'clanName'  => (string) $row['clanName'],
        ];
    }

    /** El instante original de un sellado ya presente (eco idempotente). */
    private function addedAtOf(string $userId, string $spellId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT added_at FROM grimoire_collections WHERE user_id = :userId AND spell_id = :spellId'
        );
        $statement->execute([':userId' => $userId, ':spellId' => $spellId]);

        return (string) $statement->fetchColumn();
    }
}

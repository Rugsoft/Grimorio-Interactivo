<?php

/**
 * WeeklyDominionService.php — Liquidación de PDA y proclamación dominical.
 *
 * Tarea 2.5 (TASKS-07): motor del Dominio Semanal. Acredita Puntos de Dominio
 * Arcano a las hermandades por los tres méritos canónicos, aplica la
 * bonificación de sinergia de linaje y ejecuta el corte dominical
 * determinista que corona al Clan Regente, pliega los contadores semanales
 * sobre la gloria histórica y los reinicia a cero.
 *
 * Cubre: RF-03.1, RF-03.2, RF-03.3, RF-03.5, RF-04.1 a RF-04.5, RF-05.1,
 * RNF-01 y RNF-02.
 *
 * Ampliación de SPEC-08 (Tarea 2.5): RF-04.4 faculta al Administrador Supremo
 * a deducir RETROACTIVAMENTE los PDA que un conjuro fraudulento otorgó a su
 * linaje. La operación inversa vive aquí, junto al otorgamiento, porque es el
 * mismo contador y el mismo libro: una deducción implementada en otro servicio
 * sería una segunda aritmética de la gloria, capaz de divergir de la primera.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): PDO nativo, cero librerías; ninguna gloria se
 *     acredita sin dejar su asiento en el libro, y ambos movimientos viajan
 *     dentro de una sola transacción.
 *   - Art. II: este servicio NO toca la forja ni el maná; solo mide gloria.
 *   - Art. IV: las leyendas de los recibos se redactan en noble castellano.
 *   - Art. V: identificadores en inglés camelCase, documentación en castellano.
 *
 * Disciplina temporal (RNF-01): el instante llega por parámetro y viaja
 * idéntico a repositorios y bitácora. El día del techo del simulador es la
 * fecha UTC del instante dado —de modo que el reinicio a las 00:00:00 UTC es
 * una propiedad estructural de la clave, no un temporizador que pueda
 * desviarse— y la semana del corte se deriva del último domingo concluido.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use DateTimeImmutable;
use DateTimeZone;
use Grimorio\Dto\ClanDto;
use Grimorio\Dto\DominionAwardDto;
use Grimorio\Dto\DominionReversalDto;
use Grimorio\Dto\WeeklyCycleDto;
use Grimorio\Exceptions\ClanGovernanceException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use Grimorio\Repositories\ClanMemberRepository;
use Grimorio\Repositories\ClanRepository;
use Grimorio\Repositories\WeeklyCycleRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Dominio Semanal: gloria de hermandades y corte dominical del santuario.
 */
final class WeeklyDominionService
{
    /** Gloria de una reacción de combo en la Cámara de Conjuración (RF-03.2). */
    public const SIMULATOR_COMBO_POINTS = DominionAwardDto::SIMULATOR_COMBO_POINTS;

    /** Techo diario de práctica por adepto, reiniciado a las 00:00:00 UTC (RNF-02). */
    public const DAILY_SIMULATOR_CAP = DominionAwardDto::DAILY_SIMULATOR_CAP;

    /** Gloria de un elogio comunitario computable (RF-03.3). */
    public const COMMUNITY_FAVORITE_POINTS = DominionAwardDto::COMMUNITY_FAVORITE_POINTS;

    /** Identidad del santuario cuando el acto lo dicta el canon (RNF-04). */
    private const SYSTEM_ACTOR_ID = 'sys_santuario';
    private const SYSTEM_ACTOR_ALIAS = 'El Santuario';
    private const SYSTEM_ACTOR_ROLE = 'system';

    /** Conexión PDO del santuario (Singleton del front controller). */
    private PDO $pdo;

    /** Hermandades, sus contadores de gloria y su pliegue histórico. */
    private ClanRepository $clanRepository;

    /** Autoridad de la afiliación: quién milita en qué casa (RF-03.2). */
    private ClanMemberRepository $memberRepository;

    /** Libro Mayor de Campeones del Salón de los Linajes (RF-04.4). */
    private WeeklyCycleRepository $cycleRepository;

    /** Sinergia temática de linaje: el +25% con redondeo aritmético (RF-03.4). */
    private LineageSynergyService $lineageService;

    /** Bitácora pública de auditoría; ausente en arneses aislados (RNF-04). */
    private ?AuditService $auditService;

    public function __construct(
        PDO $pdo,
        ?AuditService $auditService = null,
        ?LineageSynergyService $lineageService = null,
    ) {
        $this->pdo = $pdo;
        $this->clanRepository = new ClanRepository($pdo);
        $this->memberRepository = new ClanMemberRepository($pdo);
        $this->cycleRepository = new WeeklyCycleRepository($pdo);
        $this->lineageService = $lineageService ?? new LineageSynergyService();
        $this->auditService = $auditService;
    }

    /**
     * Acredita la gloria de un conjuro ratificado (RF-03.1, RF-03.5).
     *
     * La escala es PDA = 100 + (Círculo × 20) y el destino es SIEMPRE el
     * `spells.clan_id`: el linaje bajo cuyo estandarte el conjuro fue forjado
     * y sometido a moderación. Así, si su autor abandonó la hermandad antes
     * de que llegara la tercera firma, la gloria permanece íntegra en el clan
     * originario en lugar de seguir al desertor (RF-03.5, RF-05.1).
     *
     * Una misma validación paga UNA sola vez: el asiento del libro lleva la
     * unicidad (action_type, source_id) y un segundo intento devuelve un
     * recibo sin gloria en lugar de inflar la contienda (RNF-01).
     *
     * @throws SpellNotFoundException  Si el conjuro no existe.
     * @throws InvalidArgumentException Si el conjuro aún no fue ratificado.
     */
    public function awardValidatedSpell(string $spellId, ?DateTimeImmutable $now = null): DominionAwardDto
    {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $spell = $this->requireValidatedSpell($spellId);
        $clanId = (string) $spell['clan_id'];
        $authorId = (string) $spell['author_id'];
        $clanLineage = $this->lineageOf($clanId);

        $basePoints = DominionAwardDto::circleBasePoints((int) $spell['circle']);
        $element = $this->elementOf($spell);
        $hasSynergy = $this->lineageService->hasSynergy($clanLineage, $element);
        $awardedPoints = $this->lineageService->applySynergy($basePoints, $clanLineage, $element);

        // ¿Se disolvió el linaje durante la revisión? (RF-03.7 de SPEC-08) La
        // casa disuelta conserva su vinculación histórica y su gloria eterna,
        // pero deja de disputar el Dominio semanal.
        $originClan = $this->clanRepository->findById($clanId);
        $isAncestralHeritage = $originClan !== null
            && (string) ($originClan['status'] ?? '') === ClanRepository::STATUS_ARCHIVED;

        $receipted = $this->runAtomically(function () use (
            $clanId,
            $authorId,
            $basePoints,
            $awardedPoints,
            $hasSynergy,
            $spellId,
            $nowUtc,
            $isAncestralHeritage
        ): bool {
            $inscribed = $this->recordAward(
                $this->newIdentifier('awd'),
                $clanId,
                $authorId,
                DominionAwardDto::ACTION_SPELL_VALIDATED,
                $basePoints,
                $awardedPoints,
                $hasSynergy,
                $spellId,
                $nowUtc,
            );

            if (!$inscribed) {
                return false;
            }

            if ($isAncestralHeritage) {
                // El clan se disolvió durante la revisión (RF-03.7 de SPEC-08):
                // la casa ya no compite por el Dominio semanal, así que su
                // gloria se inscribe DIRECTAMENTE en el haber perpetuo. Sin
                // este ramal, una casa disuelta seguiría figurando en la
                // contienda semanal que ya no disputa.
                $this->clanRepository->addWeeklyAndHistoricalPoints($clanId, 0, $awardedPoints, $nowUtc);

                return true;
            }

            // Durante la contienda solo se acredita el marcador semanal: el
            // pliegue perpetuo lo ejecuta el cierre dominical (RF-04.3).
            $this->clanRepository->addWeeklyAndHistoricalPoints($clanId, $awardedPoints, 0, $nowUtc);

            return true;
        });

        if (!$receipted) {
            return $this->deniedReceipt(
                DominionAwardDto::ACTION_SPELL_VALIDATED,
                $basePoints,
                $hasSynergy,
                DominionAwardDto::REASON_ALREADY_AWARDED,
                $nowUtc,
            );
        }

        return new DominionAwardDto(
            actionType: DominionAwardDto::ACTION_SPELL_VALIDATED,
            basePoints: $basePoints,
            awardedPoints: $awardedPoints,
            hasSynergy: $hasSynergy,
            awardedAt: $nowUtc,
        );
    }

    /**
     * Deduce RETROACTIVAMENTE la gloria que un conjuro otorgó a su linaje
     * (RF-04.4 de SPEC-08, Tarea 2.5).
     *
     * La operación es el espejo exacto de `awardValidatedSpell()`: se lee el
     * asiento original del libro (`spellValidated` + conjuro) para conocer el
     * importe y el linaje, y se descuenta del contador que REALMENTE sostiene
     * esa gloria. El contador se designa así:
     *
     *   1. Si un cierre dominical posterior a la acreditación ya plegó el
     *      marcador semanal sobre el haber perpetuo, la gloria vive en
     *      `historical_points`.
     *   2. Si la casa estaba YA disuelta cuando acreditó, recibió la gloria como
     *      Herencia Ancestral, y también vive en `historical_points`.
     *   3. En cualquier otro caso sigue disputando la contienda de la semana:
     *      `weekly_points`.
     *
     * Los contadores reales mandan sobre esa designación: un decreto anterior
     * puede haber drenado parte del asiento, de modo que se drena primero el
     * contador designado y después el otro, y la casa NUNCA queda en números
     * rojos —la gloria no se debe—. La parte que ningún contador alcance a
     * cubrir viaja en el recibo como `outstanding`, para que la Bitácora pueda
     * decir la verdad exacta de lo ocurrido.
     *
     * La deducción NO se inscribe como asiento del libro de méritos: un asiento
     * negativo obligaría a torcer el `CHECK` de `dominion_awards` y el sentido
     * de un diario que registra MÉRITOS, y una sentencia no es un mérito. El
     * veredicto vive en `sovereign_decrees` con su Edicto Imperial, su efecto
     * en la Bitácora inmutable y su aritmética en este recibo (Artículo III.3).
     *
     * @param string                 $spellId Conjuro desterrado.
     * @param DateTimeImmutable|null $now     Instante de la deducción.
     *
     * @return DominionReversalDto Recibo con el importe, su reparto y la gloria no cubierta.
     *
     * @throws Throwable Si la escritura falla; la deducción se deshace entera.
     */
    public function revokeValidatedSpellGlory(string $spellId, ?DateTimeImmutable $now = null): DominionReversalDto
    {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        return $this->runAtomically(function () use ($spellId, $nowUtc, $instant): DominionReversalDto {
            $award = $this->findSpellValidatedAward($spellId);
            if ($award === null) {
                // El conjuro nunca pagó gloria: no hubo fraude que descontar.
                return DominionReversalDto::denied(
                    $spellId,
                    DominionReversalDto::REASON_NO_MERIT,
                    $nowUtc,
                );
            }

            $clanId = (string) $award['clan_id'];
            if ($this->clanRepository->findById($clanId) === null) {
                return DominionReversalDto::denied(
                    $spellId,
                    DominionReversalDto::REASON_CLAN_VANISHED,
                    $nowUtc,
                );
            }

            $debts = $this->drainGloryPointCounters(
                $clanId,
                (int) $award['awarded_points'],
                (string) $award['awarded_at'],
                $instant,
            );

            return DominionReversalDto::performed(
                $spellId,
                $clanId,
                $debts['weekly'],
                $debts['historical'],
                $debts['outstanding'],
                $nowUtc,
            );
        });
    }

    /**
     * Asiento original de la validación de un conjuro, o null si nunca pagó.
     *
     * La unicidad `(action_type, source_id)` del libro garantiza que haya a lo
     * sumo UNA fila: el mérito paga una sola vez y, por tanto, se revoca una
     * sola vez (RNF-01).
     *
     * @return array{clan_id: string, base_points: int, awarded_points: int, awarded_at: string}|null
     */
    private function findSpellValidatedAward(string $spellId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT clan_id, base_points, awarded_points, awarded_at
               FROM dominion_awards
              WHERE action_type = :actionType
                AND source_id = :spellId'
        );
        $statement->execute([
            ':actionType' => DominionAwardDto::ACTION_SPELL_VALIDATED,
            ':spellId'    => $spellId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'clan_id'        => (string) $row['clan_id'],
            'base_points'    => (int) $row['base_points'],
            'awarded_points' => (int) $row['awarded_points'],
            'awarded_at'     => (string) $row['awarded_at'],
        ];
    }

    /**
     * Descuenta la gloria del contador que la sostiene y devuelve su reparto.
     *
     * @param string            $clanId    Linaje que pierde la gloria.
     * @param int               $points    Gloria a deducir.
     * @param string            $awardedAt Marca UTC del asiento original.
     * @param DateTimeImmutable $instant   Instante de la deducción.
     *
     * @return array{weekly: int, historical: int, outstanding: int}
     */
    private function drainGloryPointCounters(
        string $clanId,
        int $points,
        string $awardedAt,
        DateTimeImmutable $instant,
    ): array {
        $clan = $this->clanRepository->findById($clanId);
        if ($clan === null || $points <= 0) {
            return ['weekly' => 0, 'historical' => 0, 'outstanding' => max(0, $points)];
        }

        $weekly = (int) ($clan['weekly_points'] ?? 0);
        $historical = (int) ($clan['historical_points'] ?? 0);

        // El reparto se intenta en el contador que sostiene la gloria y, solo
        // si no alcanza, en el otro.
        $order = $this->counterHoldingAward($clanId, $awardedAt) === DominionReversalDto::COUNTER_HISTORICAL
            ? ['historical' => $historical, 'weekly' => $weekly]
            : ['weekly' => $weekly, 'historical' => $historical];

        $debts = ['weekly' => 0, 'historical' => 0];
        $remaining = $points;
        foreach ($order as $counter => $available) {
            if ($remaining === 0) {
                break;
            }

            $take = min($remaining, max(0, $available));
            if ($take > 0) {
                $debts[$counter] = $take;
                $remaining -= $take;
            }
        }

        $this->clanRepository->addWeeklyAndHistoricalPoints(
            $clanId,
            -$debts['weekly'],
            -$debts['historical'],
            $this->formatInstant($instant),
        );

        return [
            'weekly'      => $debts['weekly'],
            'historical'  => $debts['historical'],
            'outstanding' => $remaining,
        ];
    }

    /**
     * ¿Qué contador sostiene hoy la gloria acreditada en el instante dado?
     *
     * @return string Uno de los dos contadores canónicos del recibo.
     */
    private function counterHoldingAward(string $clanId, string $awardedAt): string
    {
        // La casa disuelta conserva su gloria como Herencia Ancestral, que se
        // inscribe DIRECTAMENTE en el haber perpetuo (RF-03.7 de SPEC-08).
        $clan = $this->clanRepository->findById($clanId);
        if ($clan !== null && (string) ($clan['status'] ?? '') === ClanRepository::STATUS_ARCHIVED) {
            return DominionReversalDto::COUNTER_HISTORICAL;
        }

        // El pliegue dominical mueve el marcador semanal al haber perpetuo: si
        // alguna semana cerró después de la acreditación, la gloria está allí.
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM weekly_cycles WHERE closed_at >= :awardedAt'
        );
        $statement->execute([':awardedAt' => $awardedAt]);

        return (int) $statement->fetchColumn() > 0
            ? DominionReversalDto::COUNTER_HISTORICAL
            : DominionReversalDto::COUNTER_WEEKLY;
    }

    /**
     * Acredita la práctica del simulador, con su techo diario (RF-03.2).
     *
     * Diez PDA por reacción de combo consumada, acotados al cupo diario que
     * resta al adepto en esa casa. El cupo se mide contra la fecha UTC del
     * instante —nunca contra el reloj del proceso— de modo que el reinicio de
     * las 00:00:00 UTC es una propiedad de la clave `(user, clan, fecha)` y no
     * un temporizador que pueda desviarse.
     *
     * La guarda del incremento (`points_awarded + :points <= :cap`) reside en
     * la propia sentencia: dos prácticas simultáneas del mismo adepto no
     * pueden rebasar el techo por una condición de carrera (RNF-02).
     *
     * @param string $comboElement Afinidad elemental del combo ejecutado.
     *
     * @throws ClanGovernanceException Si el adepto no milita en esa casa.
     */
    public function awardSimulatorCombo(
        User $member,
        string $clanId,
        string $comboElement = '',
        ?DateTimeImmutable $now = null,
    ): DominionAwardDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);
        $cycleDate = $instant->format('Y-m-d');

        $clanLineage = $this->lineageOf($clanId);
        $this->requireMembership($member, $clanId);

        $consumed = $this->simulatorPointsFor($member->getId(), $clanId, $cycleDate);
        $available = self::DAILY_SIMULATOR_CAP - $consumed;

        if ($available <= 0) {
            return DominionAwardDto::capReached($nowUtc);
        }

        $basePoints = min(self::SIMULATOR_COMBO_POINTS, $available);
        $hasSynergy = $this->lineageService->hasSynergy($clanLineage, $comboElement);
        $awardedPoints = min(
            $this->lineageService->applySynergy($basePoints, $clanLineage, $comboElement),
            $available,
        );

        $practised = $this->runAtomically(function () use (
            $member,
            $clanId,
            $cycleDate,
            $awardedPoints,
            $nowUtc
        ): bool {
            if (!$this->registerSimulatorPractice($member->getId(), $clanId, $cycleDate, $awardedPoints)) {
                return false;
            }

            $this->clanRepository->addWeeklyAndHistoricalPoints($clanId, $awardedPoints, 0, $nowUtc);

            return true;
        });

        if (!$practised) {
            // Otra pluma colmó el cupo en el mismo aliento: la guarda mordió.
            return DominionAwardDto::capReached($nowUtc);
        }

        return new DominionAwardDto(
            actionType: DominionAwardDto::ACTION_SIMULATOR_COMBO,
            basePoints: $basePoints,
            awardedPoints: $awardedPoints,
            hasSynergy: $hasSynergy,
            awardedAt: $nowUtc,
            dailyQuotaRemaining: $available - $awardedPoints,
        );
    }

    /**
     * Acredita el elogio de un mago ajeno sobre un conjuro sellado (RF-03.3).
     *
     * Cinco PDA al linaje del conjuro, computables UNA sola vez por cuenta y
     * conjuro: la unicidad `(user_id, spell_id)` de la Libreta de Favoritos lo
     * convierte en imposible por construcción, no en una promesa del código
     * (RNF-02).
     *
     * El canon exige que el elogio provenga de un mago AJENO al clan: quien
     * milita en la casa del conjuro puede guardarlo en su libreta, pero su
     * elogio no computa —de lo contrario bastaría con auto-elogiarse para
     * granjear gloria—.
     *
     * @throws SpellNotFoundException  Si el conjuro no existe.
     * @throws InvalidArgumentException Si el conjuro aún no fue sellado.
     */
    public function awardCommunityFavorite(
        User $visitor,
        string $spellId,
        ?DateTimeImmutable $now = null,
    ): DominionAwardDto {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $spell = $this->requireValidatedSpell($spellId);
        $clanId = (string) $spell['clan_id'];
        $clanLineage = $this->lineageOf($clanId);

        $basePoints = self::COMMUNITY_FAVORITE_POINTS;
        $element = $this->elementOf($spell);
        $hasSynergy = $this->lineageService->hasSynergy($clanLineage, $element);

        // Un mago de la propia casa no puede granjear gloria para su linaje.
        $ownMembership = $this->memberRepository->findActiveMembership($visitor->getId());
        if ($ownMembership !== null && (string) $ownMembership['clan_id'] === $clanId) {
            return $this->deniedReceipt(
                DominionAwardDto::ACTION_COMMUNITY_FAVORITE,
                $basePoints,
                $hasSynergy,
                DominionAwardDto::REASON_OWN_CLAN_FAVORITE,
                $nowUtc,
            );
        }

        $awardedPoints = $this->lineageService->applySynergy($basePoints, $clanLineage, $element);
        $favoriteId = $this->newIdentifier('fav');

        $receipted = $this->runAtomically(function () use (
            $visitor,
            $spellId,
            $favoriteId,
            $clanId,
            $basePoints,
            $awardedPoints,
            $hasSynergy,
            $nowUtc
        ): bool {
            if (!$this->registerFavorite($favoriteId, $visitor->getId(), $spellId, $nowUtc)) {
                return false;
            }

            $this->recordAward(
                $this->newIdentifier('awd'),
                $clanId,
                $visitor->getId(),
                DominionAwardDto::ACTION_COMMUNITY_FAVORITE,
                $basePoints,
                $awardedPoints,
                $hasSynergy,
                $favoriteId,
                $nowUtc,
            );
            $this->clanRepository->addWeeklyAndHistoricalPoints($clanId, $awardedPoints, 0, $nowUtc);

            return true;
        });

        if (!$receipted) {
            return $this->deniedReceipt(
                DominionAwardDto::ACTION_COMMUNITY_FAVORITE,
                $basePoints,
                $hasSynergy,
                DominionAwardDto::REASON_DUPLICATE_FAVORITE,
                $nowUtc,
            );
        }

        return new DominionAwardDto(
            actionType: DominionAwardDto::ACTION_COMMUNITY_FAVORITE,
            basePoints: $basePoints,
            awardedPoints: $awardedPoints,
            hasSynergy: $hasSynergy,
            awardedAt: $nowUtc,
        );
    }

    /**
     * Cupo diario del simulador que resta a un adepto en una casa (RF-03.2).
     */
    public function dailyQuotaRemaining(
        string $userId,
        string $clanId,
        ?DateTimeImmutable $now = null,
    ): int {
        $cycleDate = $this->instant($now)->format('Y-m-d');
        $consumed = $this->simulatorPointsFor($userId, $clanId, $cycleDate);

        return max(0, self::DAILY_SIMULATOR_CAP - $consumed);
    }

    /**
     * Ejecuta el corte dominical y proclama al Clan Regente (RF-04.1 a RF-04.5).
     *
     * El corte que se consuma es el del último domingo de las 23:59:59 UTC ya
     * transcurrido respecto al instante dado, de modo que el cierre sea
     * determinista tanto si lo invoca el cron a las 23:59:59 como si lo
     * provoca la evaluación perezosa del lunes siguiente (plan 5, Decisión 1).
     *
     * Desenlace:
     *   1. Se ordenan las hermandades activas por PDA semanales descendentes.
     *   2. Primer desempate: mayor número de conjuros validados en la semana.
     *   3. Segundo desempate: la casa que alcanzó antes su puntuación, leída
     *      de `updated_at` —el instante de su última acreditación—, y, como
     *      último recurso inapelable, el identificador (RNF-01).
     *   4. Se inmortaliza el ciclo en el Libro Mayor, se pliegan los contadores
     *      semanales sobre la gloria histórica y se reinician a cero.
     *
     * @return WeeklyCycleDto|null El ciclo coronado, o null si no hay
     *                             hermandades activas a las que proclamar.
     */
    public function closeWeeklyCycle(?DateTimeImmutable $now = null): ?WeeklyCycleDto
    {
        $instant = $this->instant($now);
        $nowUtc = $this->formatInstant($instant);

        $closingInstant = $this->lastConcludedSunday($instant);
        $weekNumber = (int) $closingInstant->format('W');
        $cycleYear = (int) $closingInstant->format('o');

        // Cada semana se corona UNA sola vez: el repliegue de contadores es
        // irreversible y jamás debe repetirse por un segundo latido del cron.
        $inscribedCycle = $this->cycleRepository->findCycleForWeek($weekNumber, $cycleYear);
        if ($inscribedCycle !== null) {
            return $this->toCycleDto($inscribedCycle);
        }

        $activeClans = $this->clanRepository->findActiveOrderedByWeeklyPointsDesc();
        if ($activeClans === []) {
            return null;
        }

        [$weekStart, $weekEnd] = $this->isoWeekWindow($closingInstant);
        $regent = $this->selectRegent($activeClans, $weekStart, $weekEnd);
        if ($regent === null) {
            return null;
        }

        $winningPoints = (int) $regent['weekly_points'];
        $spellCount = $this->countValidatedSpellsInWeek((string) $regent['id'], $weekStart, $weekEnd);
        $closedAt = $this->formatInstant($closingInstant);

        $cycle = $this->runAtomically(function () use (
            $weekNumber,
            $cycleYear,
            $regent,
            $winningPoints,
            $spellCount,
            $closedAt,
            $nowUtc
        ): ?array {
            $recorded = $this->cycleRepository->recordClosedCycle(
                $this->newIdentifier('cyc'),
                $weekNumber,
                $cycleYear,
                (string) $regent['id'],
                $winningPoints,
                $spellCount,
                $closedAt,
            );

            if ($recorded === null) {
                return null;
            }

            // RF-04.3: el pliegue sobre la gloria histórica y el reinicio a
            // cero de TODAS las hermandades —también las disueltas, para que
            // su legado jamás pierda gloria— son una sola sentencia.
            $this->clanRepository->resetAllWeeklyPointsToZero($nowUtc);

            return $recorded;
        });

        if ($cycle === null) {
            $existing = $this->cycleRepository->findCycleForWeek($weekNumber, $cycleYear);

            return $existing === null ? null : $this->toCycleDto($existing);
        }

        $this->recordAudit(
            'DOMINION_WEEK_CONCLUDED',
            (string) $regent['id'],
            "Semana {$cycleYear}-W{$weekNumber}: «{$regent['name']}» se alza Clan Regente con {$winningPoints} PDA y {$spellCount} conjuros sellados.",
            $instant,
        );

        return $this->toCycleDto($cycle);
    }

    /**
     * Evaluación perezosa del cierre semanal (plan 5, Decisión 1).
     *
     * Salvaguarda del cron: si el programador de tareas del servidor sufre un
     * retraso, basta con que llegue una petición para que la semana vencida se
     * corone antes de que su gloria contamine la contienda en curso.
     *
     * @return WeeklyCycleDto|null El ciclo recién coronado, o null si la
     *                             semana vigente ya estaba proclamada.
     */
    public function ensureCycleIsCurrent(?DateTimeImmutable $now = null): ?WeeklyCycleDto
    {
        $instant = $this->instant($now);
        $closingInstant = $this->lastConcludedSunday($instant);

        $alreadyProclaimed = $this->cycleRepository->findCycleForWeek(
            (int) $closingInstant->format('W'),
            (int) $closingInstant->format('o'),
        );

        if ($alreadyProclaimed !== null) {
            return null;
        }

        return $this->closeWeeklyCycle($instant);
    }

    // ── Salón del Dominio: lectura del Endpoint 11 (Tarea 3.3) ──────────

    /**
     * El Salón del Dominio íntegro (plan 2.2, Endpoint 11): la clasificación
     * viva, el prestigio perpetuo, el Clan Regente vigente y el Libro Mayor.
     *
     * Antes de leer la contienda se consuma la salvaguarda perezosa (plan 5,
     * Decisión 1): si el cron dominical sufrió un retraso, la semana vencida se
     * corona AQUÍ, de modo que su gloria jamás contamine la contienda en curso
     * ni el podio exhiba una clasificación que ya debería estar liquidada. La
     * operación es idempotente: una semana ya proclamada no se pliega dos veces.
     *
     * @param DateTimeImmutable|null $now       «Ahora» inyectable (RNF-01).
     * @param int                    $fameLimit Cortes del Libro Mayor a exhibir; 0 = todos.
     */
    public function hallOfDominion(?DateTimeImmutable $now = null, int $fameLimit = 0): DominionHall
    {
        $this->ensureCycleIsCurrent($now);

        return new DominionHall(
            weeklyRanking: $this->weeklyRanking(),
            historicalRanking: $this->historicalRanking(),
            currentRegentClan: $this->currentRegentClan(),
            hallOfFameWeeks: $this->hallOfFameWeeks($fameLimit),
        );
    }

    /**
     * La contienda en curso: casas activas por PDA semanal descendente
     * (RF-06.1). El censo de adeptos viaja con cada estandarte.
     *
     * @return list<ClanDto>
     */
    public function weeklyRanking(): array
    {
        return array_map(
            static fn (array $row): ClanDto => ClanDto::fromDatabaseRow($row),
            $this->clanRepository->findActiveOrderedByWeeklyPointsDesc(),
        );
    }

    /**
     * El prestigio perpetuo: casas activas por gloria histórica descendente
     * (RF-06.1). Una casa disuelta conserva su gloria, pero solo las activas
     * figuran en la clasificación.
     *
     * @return list<ClanDto>
     */
    public function historicalRanking(): array
    {
        return array_map(
            static fn (array $row): ClanDto => ClanDto::fromDatabaseRow($row),
            $this->clanRepository->findActiveOrderedByHistoricalPointsDesc(),
        );
    }

    /**
     * El Clan Regente vigente: quien ciñó la corona en el último corte
     * dominical inscrito (RF-04.5), o null si el santuario aún no ha
     * proclamado semana alguna.
     */
    public function currentRegentClan(): ?ClanDto
    {
        $cycle = $this->cycleRepository->findCurrentRegentCycle();
        if ($cycle === null) {
            return null;
        }

        $regentRow = $this->clanRepository->findById((string) $cycle['regent_clan_id']);
        if ($regentRow === null) {
            return null;
        }

        // El censo no viaja en la lectura simple: se resuelve aquí para que el
        // estandarte del Regente exhiba su ocupación como cualquier otro.
        $regentRow['member_count'] = $this->memberRepository->countActiveMembers((string) $regentRow['id']);

        return ClanDto::fromDatabaseRow($regentRow);
    }

    /**
     * El Libro Mayor de Campeones (RF-04.4): los cortes dominicales ya
     * proclamados, del más reciente al más antiguo.
     *
     * @param int $limit Cortes a exhibir; 0 = la memoria íntegra del santuario.
     *
     * @return list<WeeklyCycleDto>
     */
    public function hallOfFameWeeks(int $limit = 0): array
    {
        $cycles = [];
        foreach ($this->cycleRepository->findCycleHistory($limit) as $row) {
            $cycles[] = $this->toCycleDto($row);
        }

        return $cycles;
    }

    // ── Cómputo interno del corte ────────────────────────────────────────

    /**
     * Escoge al Clan Regente con los dos desempates canónicos (RF-04.5).
     *
     * @param list<array<string, mixed>> $activeClans Censo activo por PDA descendente.
     *
     * @return array<string, mixed>|null La casa coronada.
     */
    private function selectRegent(
        array $activeClans,
        DateTimeImmutable $weekStart,
        DateTimeImmutable $weekEnd,
    ): ?array {
        $highestPoints = (int) $activeClans[0]['weekly_points'];
        $topClans = array_values(array_filter(
            $activeClans,
            static fn (array $clan): bool => (int) $clan['weekly_points'] === $highestPoints,
        ));

        $topSpellCount = 0;
        $spellCounts = [];
        foreach ($topClans as $clan) {
            $count = $this->countValidatedSpellsInWeek((string) $clan['id'], $weekStart, $weekEnd);
            $spellCounts[(string) $clan['id']] = $count;
            $topSpellCount = max($topSpellCount, $count);
        }

        $contenders = array_values(array_filter(
            $topClans,
            static fn (array $clan): bool => $spellCounts[(string) $clan['id']] === $topSpellCount,
        ));

        // Segundo desempate: quien alcanzó antes su puntuación. `updated_at`
        // porta el instante de su última acreditación, de modo que la marca
        // menor señala a la casa que llegó primero a ese total. Las estampas
        // viajan en ISO 8601 UTC, así que el orden lexicográfico coincide con
        // el cronológico.
        usort(
            $contenders,
            static function (array $left, array $right): int {
                $byTimestamp = strcmp((string) $left['updated_at'], (string) $right['updated_at']);

                return $byTimestamp !== 0 ? $byTimestamp : strcmp((string) $left['id'], (string) $right['id']);
            },
        );

        return $contenders[0] ?? null;
    }

    /**
     * Conjuros sellados que una casa aportó durante la semana concluida.
     */
    private function countValidatedSpellsInWeek(
        string $clanId,
        DateTimeImmutable $weekStart,
        DateTimeImmutable $weekEnd,
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM spells
              WHERE clan_id = :clanId
                AND status = :validatedStatus
                AND validated_at IS NOT NULL
                AND validated_at >= :weekStart
                AND validated_at < :weekEnd'
        );
        $statement->execute([
            ':clanId'           => $clanId,
            ':validatedStatus'  => 'validated',
            ':weekStart'        => $this->formatInstant($weekStart),
            ':weekEnd'          => $this->formatInstant($weekEnd),
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Ventana ISO [lunes 00:00:00, lunes siguiente 00:00:00) de la semana de
     * un domingo concluido, para acotar los conjuros de la contienda.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function isoWeekWindow(DateTimeImmutable $sunday): array
    {
        $weekStart = $sunday->setTime(0, 0, 0)->modify('monday this week');

        return [$weekStart, $weekStart->modify('+7 days')];
    }

    /**
     * El último domingo de las 23:59:59 UTC ya transcurrido (RF-04.1).
     *
     * Si el instante es el propio corte (domingo a las 23:59:59), el ciclo que
     * se consume es ése; en cualquier otro momento es el domingo anterior.
     */
    private function lastConcludedSunday(DateTimeImmutable $instant): DateTimeImmutable
    {
        $tonight = $instant->setTime(23, 59, 59);

        if ((int) $instant->format('N') === 7 && $instant >= $tonight) {
            return $tonight;
        }

        return $instant->modify('last sunday')->setTime(23, 59, 59);
    }

    // ── Libro de Gloria ─────────────────────────────────────────────────

    /**
     * Asienta una acreditación si y solo si ese mérito no había pagado aún.
     *
     * La guarda vive en la propia sentencia (`WHERE NOT EXISTS`), de modo que
     * dos acreditaciones simultáneas del mismo mérito no puedan duplicar la
     * gloria por una condición de carrera (RNF-01).
     */
    private function recordAward(
        string $awardId,
        string $clanId,
        string $userId,
        string $actionType,
        int $basePoints,
        int $awardedPoints,
        bool $hasSynergy,
        string $sourceId,
        string $awardedAt,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO dominion_awards (
                 id, clan_id, user_id, action_type, base_points,
                 awarded_points, has_synergy, source_id, awarded_at
             )
             SELECT :awardId, :clanId, :userId, :actionType, :basePoints,
                    :awardedPoints, :hasSynergy, :sourceId, :awardedAt
              WHERE NOT EXISTS (
                        SELECT 1 FROM dominion_awards
                         WHERE action_type = :actionType
                           AND source_id = :sourceId
                    )'
        );
        $statement->execute([
            ':awardId'       => $awardId,
            ':clanId'        => $clanId,
            ':userId'        => $userId,
            ':actionType'    => $actionType,
            ':basePoints'    => $basePoints,
            ':awardedPoints' => $awardedPoints,
            ':hasSynergy'    => $hasSynergy ? 1 : 0,
            ':sourceId'      => $sourceId,
            ':awardedAt'     => $awardedAt,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Gloria acumulada hoy por un adepto en una casa (tope 50 PDA).
     */
    private function simulatorPointsFor(string $userId, string $clanId, string $cycleDate): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(points_awarded), 0)
               FROM daily_simulator_tracker
              WHERE user_id = :userId
                AND clan_id = :clanId
                AND cycle_date = :cycleDate'
        );
        $statement->execute([
            ':userId'    => $userId,
            ':clanId'    => $clanId,
            ':cycleDate' => $cycleDate,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Suma una práctica al acumulador del día sin rebasar el techo (RF-03.2).
     *
     * El registro del día se abre con una guarda atómica y el incremento lleva
     * el techo en su propia condición: dos prácticas simultáneas no pueden
     * rebasar los cincuenta PDA diarios.
     */
    private function registerSimulatorPractice(
        string $userId,
        string $clanId,
        string $cycleDate,
        int $pointsAwarded,
    ): bool {
        $openDay = $this->pdo->prepare(
            'INSERT INTO daily_simulator_tracker (id, user_id, clan_id, cycle_date, points_awarded)
             SELECT :trackerId, :userId, :clanId, :cycleDate, 0
              WHERE NOT EXISTS (
                        SELECT 1 FROM daily_simulator_tracker
                         WHERE user_id = :userId
                           AND clan_id = :clanId
                           AND cycle_date = :cycleDate
                    )'
        );
        $openDay->execute([
            ':trackerId' => $this->newIdentifier('dst'),
            ':userId'    => $userId,
            ':clanId'    => $clanId,
            ':cycleDate' => $cycleDate,
        ]);

        $increment = $this->pdo->prepare(
            'UPDATE daily_simulator_tracker
                SET points_awarded = points_awarded + :pointsAwarded
              WHERE user_id = :userId
                AND clan_id = :clanId
                AND cycle_date = :cycleDate
                AND points_awarded + :pointsAwarded <= :dailyCap'
        );
        $increment->execute([
            ':pointsAwarded' => $pointsAwarded,
            ':userId'        => $userId,
            ':clanId'        => $clanId,
            ':cycleDate'     => $cycleDate,
            ':dailyCap'      => self::DAILY_SIMULATOR_CAP,
        ]);

        return $increment->rowCount() > 0;
    }

    /**
     * Inscribe un elogio si y solo si esa cuenta no había elogiado ya ese
     * conjuro. La unicidad `(user_id, spell_id)` es la garantía estructural
     * de RNF-02; la guarda la traduce a un resultado de negocio.
     */
    private function registerFavorite(
        string $favoriteId,
        string $userId,
        string $spellId,
        string $createdAt,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO favorites (id, user_id, spell_id, created_at)
             SELECT :favoriteId, :userId, :spellId, :createdAt
              WHERE NOT EXISTS (
                        SELECT 1 FROM favorites
                         WHERE user_id = :userId
                           AND spell_id = :spellId
                    )'
        );
        $statement->execute([
            ':favoriteId' => $favoriteId,
            ':userId'     => $userId,
            ':spellId'    => $spellId,
            ':createdAt'  => $createdAt,
        ]);

        return $statement->rowCount() > 0;
    }

    // ── Lecturas canónicas ───────────────────────────────────────────────

    /**
     * Recupera un conjuro ratificado o alza el motivo del rechazo.
     *
     * @return array<string, mixed> Fila cruda de `spells`.
     *
     * @throws SpellNotFoundException   Si el conjuro no existe.
     * @throws InvalidArgumentException Si aún no alcanzó el estado `validated`.
     */
    private function requireValidatedSpell(string $spellId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, author_id, clan_id, circle, status, elemental_affinity
               FROM spells
              WHERE id = :spellId'
        );
        $statement->execute([':spellId' => $spellId]);
        $spell = $statement->fetch(PDO::FETCH_ASSOC);

        if ($spell === false) {
            throw new SpellNotFoundException(
                "No obra conjuro alguno con el identificador «{$spellId}» en el Gran Tomo."
            );
        }

        if ((string) $spell['status'] !== 'validated') {
            throw new InvalidArgumentException(
                "El conjuro «{$spellId}» aún no ha sido ratificado: solo la gloria sellada computa para el Dominio."
            );
        }

        return $spell;
    }

    /**
     * Linaje rector de una hermandad, o vacío si la casa no existe.
     */
    private function lineageOf(string $clanId): string
    {
        $statement = $this->pdo->prepare('SELECT lineage_type FROM clans WHERE id = :clanId');
        $statement->execute([':clanId' => $clanId]);
        $lineage = $statement->fetchColumn();

        return is_string($lineage) ? $lineage : '';
    }

    /**
     * Afinidad elemental del conjuro, o cadena vacía si no porta ninguna.
     *
     * @param array<string, mixed> $spell Fila cruda de `spells`.
     */
    private function elementOf(array $spell): string
    {
        return is_string($spell['elemental_affinity'] ?? null) ? (string) $spell['elemental_affinity'] : '';
    }

    /**
     * Exige que el adepto milite en la casa destinataria de la gloria (RF-03.2).
     *
     * @throws ClanGovernanceException Si el adepto no milita en esa hermandad.
     */
    private function requireMembership(User $member, string $clanId): void
    {
        $membership = $this->memberRepository->findActiveMembership($member->getId());

        if ($membership === null || (string) $membership['clan_id'] !== $clanId) {
            throw ClanGovernanceException::notAMember($member->getId());
        }
    }

    /**
     * Forja el recibo de una gloria denegada: cero PDA y motivo canónico.
     */
    private function deniedReceipt(
        string $actionType,
        int $basePoints,
        bool $hasSynergy,
        string $reason,
        string $awardedAt,
    ): DominionAwardDto {
        return new DominionAwardDto(
            actionType: $actionType,
            basePoints: $basePoints,
            awardedPoints: 0,
            hasSynergy: $hasSynergy,
            awardedAt: $awardedAt,
            reason: $reason,
        );
    }

    /**
     * Forja el DTO del ciclo con el nombre de la casa coronada (RF-04.4).
     *
     * @param array<string, mixed> $cycle Fila cruda de `weekly_cycles`.
     */
    private function toCycleDto(array $cycle): WeeklyCycleDto
    {
        $clan = $this->clanRepository->findById((string) $cycle['regent_clan_id']);
        $cycle['regent_clan_name'] = $clan === null ? '' : (string) $clan['name'];

        return WeeklyCycleDto::fromDatabaseRow($cycle);
    }

    /**
     * Inscribe el corte dominical en la Bitácora pública (RNF-04).
     */
    private function recordAudit(
        string $actionType,
        string $clanId,
        string $justification,
        DateTimeImmutable $instant,
    ): void {
        $this->auditService?->recordAction(
            self::SYSTEM_ACTOR_ID,
            self::SYSTEM_ACTOR_ALIAS,
            self::SYSTEM_ACTOR_ROLE,
            $actionType,
            'clan',
            $clanId,
            $justification,
            $instant,
        );
    }

    /**
     * Ejecuta una operación como un solo gesto confirmable o reversible.
     */
    private function runAtomically(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }
    }

    /**
     * Instante canónico de la operación: el inyectado, o un único latido UTC.
     */
    private function instant(?DateTimeImmutable $now): DateTimeImmutable
    {
        return $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Estampa ISO 8601 UTC, idéntica en formato a la que siembran las semillas.
     */
    private function formatInstant(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Identificador textual canónico (`awd_`, `fav_`, `cyc_`, `dst_`).
     */
    private function newIdentifier(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}

<?php

/**
 * SpellManagementService.php — Ciclo de vida de conjuros y borradores.
 *
 * Tarea 3.1 (TASKS-04): gestión de borradores privados ('draft') con
 * determinismo ciego del backend (Artículo II): el maná, el círculo y la
 * huella matemática SIEMPRE se recalculan aquí con SpellBalanceService,
 * jamás se aceptan del cliente.
 *
 * Métodos de esta fase:
 *   - createDraft(User, SpellCreateDto):  crea un borrador del autor.
 *   - updateDraft(User, string, SpellCreateDto): edita el borrador propio.
 *   - deleteDraft(User, string):          retira el borrador propio.
 *   - listDrafts(User):                   lista los borradores del autor.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): PDO nativo, consultas preparadas.
 *   - Artículo II: el coste es computado por el servicio de balance.
 *   - Artículo III: la cuota dura de 10 borradores (RF-05.1) previene
 *     datos zombis; la titularidad es estricta (solo el autor opera).
 *   - Artículo V: identificadores en inglés camelCase, comentarios y
 *     errores en castellano.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\SpellCalculationInputDto;
use Grimorio\Dto\SpellCreateDto;
use Grimorio\Exceptions\DraftQuotaExceededException;
use Grimorio\Exceptions\SpellImmutableException;
use Grimorio\Exceptions\SpellNotFoundException;
use Grimorio\Models\User;
use PDO;
use RuntimeException;

/**
 * Gestión del ciclo de vida de los conjuros del santuario.
 */
final class SpellManagementService
{
    /** Cuota dura de borradores simultáneos por autor (RF-05.1). */
    public const MAX_DRAFTS_PER_AUTHOR = 10;

    /** Conexión PDO al plano arcano. */
    private PDO $pdo;

    /** Motor matemático puro del coste de maná (Tarea 2.2, Art. II). */
    private SpellBalanceService $balanceService;

    /** Bitácora inmutable de auditoría (RF-08.1, Art. III). */
    private AuditService $auditService;

    public function __construct(PDO $pdo, ?SpellBalanceService $balanceService = null)
    {
        $this->pdo            = $pdo;
        $this->balanceService = $balanceService ?? new SpellBalanceService();
        $this->auditService   = new AuditService($pdo);
    }

    /**
     * Crea un borrador privado ('draft') del autor (RF-05.1).
     *
     * El coste, el círculo y la huella se recalculan de forma ciega y
     * autoritativa (Art. II); el nombre canónico es único (UNIQUE del
     * esquema); la cuota de 10 borradores simultáneos es dura.
     *
     * @return array{id: string, slug: string, status: string, manaCost: int, circle: int, circleLabel: string, mathFingerprint: string, signaturesCount: int}
     *
     * @throws DraftQuotaExceededException Si el autor ya posee 10 borradores.
     * @throws RuntimeException Si el nombre canónico ya existe o la
     *         inserción falla por cualquier otra violación del esquema.
     */
    public function createDraft(User $author, SpellCreateDto $createDto): array
    {
        // Todo conjuro nace bajo un estandarte (RF-05.1): sin hermandad no
        // hay linaje al que atribuir el patrimonio, así que se rechaza antes
        // de tocar la base en vez de provocar una violación del esquema.
        $this->assertForgerBelongsToClan($author);

        // Cuota dura de borradores (RF-05.1, plan Decisión 3): el 11.º
        // intento se rechaza ANTES de cualquier escritura.
        $quotaStatement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM spells WHERE author_id = :authorId AND status = 'draft'"
        );
        $quotaStatement->execute([':authorId' => $author->getId()]);
        $activeDrafts = (int) $quotaStatement->fetchColumn();

        if ($activeDrafts >= self::MAX_DRAFTS_PER_AUTHOR) {
            throw new DraftQuotaExceededException();
        }

        // Determinismo ciego (Art. II): el backend recalcula TODO.
        $calculation    = $this->balanceService->calculate($createDto->calculationInput);
        $mathFingerprint = $this->balanceService->computeMathFingerprint($createDto->calculationInput);

        $spellId = 'spl_' . bin2hex(random_bytes(6));
        $slug    = $this->canonicalSlug($createDto->name);
        $nowUtc  = gmdate('Y-m-d\TH:i:s\Z');

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                                 mana_cost, circle, math_fingerprint, clan_id, summary, description,
                                 components_verbal, components_somatic, components_material,
                                 damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                                 has_verbal, has_somatic, has_material,
                                 status, validation_signatures_count, signatures_count, is_genesis_sample,
                                 created_at, updated_at)
             VALUES (:id, :slug, :name, :authorId, :magicSchool, :elementalAffinity, :castingTime,
                     :manaCost, :circle, :mathFingerprint, :clanId, :summary, :description,
                     :componentsVerbal, :componentsSomatic, :componentsMaterial,
                     :damage, :healing, :barrier, :crowdControlType, :rangeType, :areaType, :durationType,
                     :hasVerbal, :hasSomatic, :hasMaterial,
                     :status, 0, 0, 0,
                     :createdAt, :updatedAt)'
        );

        try {
            $insertStatement->execute([
                ':id'                 => $spellId,
                ':slug'               => $slug,
                ':name'               => $createDto->name,
                ':authorId'           => $author->getId(),
                ':magicSchool'        => $createDto->magicSchool,
                ':elementalAffinity'  => $createDto->elementalAffinity,
                ':castingTime'        => $createDto->castingTime,
                ':manaCost'           => $calculation->finalManaCost,
                ':circle'             => $calculation->circle,
                ':mathFingerprint'    => $mathFingerprint,
                ':clanId'             => $author->getClanId(),
                ':summary'            => mb_substr($createDto->description, 0, 140),
                ':description'        => $createDto->description,
                ':componentsVerbal'   => $createDto->calculationInput->hasVerbal ? $createDto->description : '',
                ':componentsSomatic'  => $createDto->calculationInput->hasSomatic ? $createDto->description : '',
                ':componentsMaterial' => $createDto->calculationInput->hasMaterial ? $createDto->description : '',
                ':damage'             => $createDto->calculationInput->damage,
                ':healing'            => $createDto->calculationInput->healing,
                ':barrier'            => $createDto->calculationInput->barrier,
                ':crowdControlType'   => $createDto->calculationInput->crowdControlType,
                ':rangeType'          => $createDto->calculationInput->rangeType,
                ':areaType'           => $createDto->calculationInput->areaType,
                ':durationType'       => $createDto->calculationInput->durationType,
                ':hasVerbal'          => (int) $createDto->calculationInput->hasVerbal,
                ':hasSomatic'         => (int) $createDto->calculationInput->hasSomatic,
                ':hasMaterial'        => (int) $createDto->calculationInput->hasMaterial,
                ':status'             => 'draft',
                ':createdAt'          => $nowUtc,
                ':updatedAt'          => $nowUtc,
            ]);
        } catch (RuntimeException $integrityViolation) {
            // El nombre canónico es único en todo el santuario (esquema).
            throw new RuntimeException('Ese nombre canónico ya habita el grimorio: elige otro.');
        }

        return [
            'id'              => $spellId,
            'slug'            => $slug,
            'status'          => 'draft',
            'manaCost'        => $calculation->finalManaCost,
            'circle'          => $calculation->circle,
            'circleLabel'     => $calculation->circleLabel,
            'mathFingerprint' => $mathFingerprint,
            'signaturesCount' => 0,
        ];
    }

    /**
     * Lista los borradores privados del autor (Endpoint 3 del plan):
     * únicamente los suyos, jamás los ajenos (RF-05.1, privacidad).
     *
     * @return list<array{id: string, slug: string, name: string, manaCost: int, circle: int, circleLabel: string, updatedAt: string}>
     */
    public function listDrafts(User $author): array
    {
        $listStatement = $this->pdo->prepare(
            "SELECT id, slug, name, mana_cost, circle, updated_at
             FROM spells
             WHERE author_id = :authorId AND status = 'draft'
             ORDER BY updated_at DESC, id DESC"
        );
        $listStatement->execute([':authorId' => $author->getId()]);

        $drafts = [];
        foreach ($listStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $drafts[] = [
                'id'          => (string) $row['id'],
                'slug'        => (string) $row['slug'],
                'name'        => (string) $row['name'],
                'manaCost'    => (int) $row['mana_cost'],
                'circle'      => (int) $row['circle'],
                'circleLabel' => SpellBalanceService::circleLabel((int) $row['circle']),
                'updatedAt'   => (string) $row['updated_at'],
            ];
        }

        return $drafts;
    }

    /**
     * Actualiza un borrador del titular (RF-05.1): los parámetros
     * matemáticos y narrativos se re-escriben y el maná/círculo/huella
     * se recalculan de forma ciega (Art. II). Un autor ajeno NO puede
     * actualizar un borrador que no es suyo.
     *
     * @return array{id: string, manaCost: int, circle: int, mathFingerprint: string}
     *
     * @throws RuntimeException Si el borrador no existe o es ajeno.
     */
    public function updateDraft(User $author, string $spellId, SpellCreateDto $createDto): array
    {
        $existing = $this->fetchOwnedDraft($author, $spellId);
        if ($existing === null) {
            // Inviolabilidad (RF-06.2, Tarea 3.4): si la ficha existe pero
            // está validada, el rechazo es el muro solemne del patrimonio
            // inmutable; si no existe o es ajena, 404 canónico.
            $this->rejectIfValidated($author, $spellId);
            throw new SpellNotFoundException('Ese borrador no existe o no habita tu grimorio.');
        }

        // Determinismo ciego (Art. II): recálculo autoritativo completo.
        $calculation     = $this->balanceService->calculate($createDto->calculationInput);
        $mathFingerprint = $this->balanceService->computeMathFingerprint($createDto->calculationInput);
        $nowUtc          = gmdate('Y-m-d\TH:i:s\Z');

        $updateStatement = $this->pdo->prepare(
            'UPDATE spells SET
                name = :name, magic_school = :magicSchool, elemental_affinity = :elementalAffinity,
                casting_time = :castingTime, mana_cost = :manaCost, circle = :circle,
                math_fingerprint = :mathFingerprint, summary = :summary, description = :description,
                damage = :damage, healing = :healing, barrier = :barrier,
                crowd_control_type = :crowdControlType, range_type = :rangeType,
                area_type = :areaType, duration_type = :durationType,
                has_verbal = :hasVerbal, has_somatic = :hasSomatic, has_material = :hasMaterial,
                updated_at = :updatedAt
             WHERE id = :spellId AND author_id = :authorId AND status = \'draft\''
        );
        $updateStatement->execute([
            ':name'              => $createDto->name,
            ':magicSchool'       => $createDto->magicSchool,
            ':elementalAffinity' => $createDto->elementalAffinity,
            ':castingTime'       => $createDto->castingTime,
            ':manaCost'          => $calculation->finalManaCost,
            ':circle'            => $calculation->circle,
            ':mathFingerprint'   => $mathFingerprint,
            ':summary'           => mb_substr($createDto->description, 0, 140),
            ':description'       => $createDto->description,
            ':damage'            => $createDto->calculationInput->damage,
            ':healing'           => $createDto->calculationInput->healing,
            ':barrier'           => $createDto->calculationInput->barrier,
            ':crowdControlType'  => $createDto->calculationInput->crowdControlType,
            ':rangeType'         => $createDto->calculationInput->rangeType,
            ':areaType'          => $createDto->calculationInput->areaType,
            ':durationType'      => $createDto->calculationInput->durationType,
            ':hasVerbal'         => (int) $createDto->calculationInput->hasVerbal,
            ':hasSomatic'        => (int) $createDto->calculationInput->hasSomatic,
            ':hasMaterial'       => (int) $createDto->calculationInput->hasMaterial,
            ':updatedAt'         => $nowUtc,
            ':spellId'           => $spellId,
            ':authorId'          => $author->getId(),
        ]);

        return [
            'id'              => $spellId,
            'manaCost'        => $calculation->finalManaCost,
            'circle'          => $calculation->circle,
            'mathFingerprint' => $mathFingerprint,
        ];
    }

    /**
     * Elimina un borrador del titular (RF-05.1). Un autor ajeno NO puede
     * borrar un borrador ajeno: la sentencia exige titularidad, y una
     * filas-afectadas = 0 se interpreta como inexistente/ajena.
     */
    public function deleteDraft(User $author, string $spellId): bool
    {
        $deleteStatement = $this->pdo->prepare(
            "DELETE FROM spells WHERE id = :spellId AND author_id = :authorId AND status = 'draft'"
        );
        $deleteStatement->execute([':spellId' => $spellId, ':authorId' => $author->getId()]);

        if ($deleteStatement->rowCount() !== 1) {
            // Inviolabilidad (RF-06.2, Tarea 3.4): un validado jamás se
            // elimina; el muro de 403 antecede a la leyenda de inexistencia.
            $this->rejectIfValidated($author, $spellId);
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Inviolabilidad de validados y derivación a Variantes
    // (Tarea 3.4, RF-06.2 y RF-06.3, Art. III).
    // -----------------------------------------------------------------

    /**
     * Muro de inviolabilidad (RF-06.2): si la ficha del titular existe en
     * estado 'validated', cualquier intento de edición o borrado lanza
     * SpellImmutableException (HTTP 403). El validado es patrimonio
     * inmutable de la biblioteca colectiva y del legado del clan.
     *
     * @throws SpellImmutableException Si la ficha del titular está validada.
     */
    /**
     * Exige que el forjador pertenezca a una hermandad antes de inscribir
     * cualquier conjuro (RF-05.1).
     *
     * La afiliación se resuelve contra su espejo `users.clan_id`, mantenido
     * por ClanMemberRepository; sin linaje no existe estandarte al que
     * atribuir el patrimonio, de modo que se rechaza aquí —con una leyenda
     * solemne— en lugar de dejar que el esquema responda con una violación
     * NOT NULL convertida en error del servidor.
     *
     * @throws RuntimeException Si el forjador no milita en ningún clan.
     */
    private function assertForgerBelongsToClan(User $author): void
    {
        $clanId = $author->getClanId();
        if ($clanId === null || trim($clanId) === '') {
            throw new RuntimeException(
                'Ningún conjuro puede forjarse sin estandarte: el mago ha de pertenecer a una hermandad del santuario.'
            );
        }
    }

    private function rejectIfValidated(User $author, string $spellId): void
    {
        $validatedStatement = $this->pdo->prepare(
            "SELECT id FROM spells WHERE id = :spellId AND author_id = :authorId AND status = 'validated'"
        );
        $validatedStatement->execute([':spellId' => $spellId, ':authorId' => $author->getId()]);

        if ($validatedStatement->fetch(PDO::FETCH_ASSOC) !== false) {
            throw SpellImmutableException::forValidatedSpell($spellId);
        }
    }

    /**
     * Deriva una Variante desde un conjuro validado (RF-06.3, Art. III):
     * clona la ficha completa del validado como un NUEVO borrador 'draft'
     * del invocante, con el sufijo solemne «(Variante)» en el nombre y un
     * identificador inédito. El original permanece congelado e intocable:
     * la evolución legítima nace aparte y renacerá por la Moderación en 2
     * pasos con firmas 0/3.
     *
     * El maná, el círculo y la huella se RECALCULAN de forma ciega con
     * SpellBalanceService a partir de los parámetros clonados (Art. II).
     *
     * @return array{id: string, slug: string, name: string, status: string, authorId: string, manaCost: int, circle: int, circleLabel: string, mathFingerprint: string, signaturesCount: int}
     *
     * @throws RuntimeException Si el origen no existe, es ajeno al
     *         invocante o no está en estado 'validated' (solo el validado
     *         engendra variantes).
     */
    public function createVariant(User $invoker, string $spellId): array
    {
        // La variante hereda el linaje del invocador (RF-05.1): sin
        // hermandad no hay estandarte bajo el que inscribirla.
        $this->assertForgerBelongsToClan($invoker);

        // Identidad del origen legítimo (se preserva para la bitácora:
        // el parámetro $spellId se reutiliza como id de la nueva variante).
        $validatedSourceId = $spellId;

        // Solo la ficha VALIDADA del propio autor es origen legítimo.
        $sourceStatement = $this->pdo->prepare(
            "SELECT slug, name, magic_school, elemental_affinity, casting_time, summary, description,
                    components_verbal, components_somatic, components_material,
                    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material
             FROM spells
             WHERE id = :spellId AND author_id = :authorId AND status = 'validated'"
        );
        $sourceStatement->execute([':spellId' => $spellId, ':authorId' => $invoker->getId()]);
        $source = $sourceStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($source)) {
            // Inexistente/ajeno (404) frente a no-validado (400): se
            // distinguen consultando la existencia de la ficha del autor.
            $existenceStatement = $this->pdo->prepare(
                'SELECT id FROM spells WHERE id = :spellId AND author_id = :authorId'
            );
            $existenceStatement->execute([':spellId' => $spellId, ':authorId' => $invoker->getId()]);

            if ($existenceStatement->fetch(PDO::FETCH_ASSOC) === false) {
                throw new SpellNotFoundException('Ese conjuro no existe o no habita tu grimorio.');
            }

            throw new RuntimeException(
                'Solo un conjuro validado de tu propia autoría puede engendrar una variante.'
            );
        }

        // Los 10 parámetros matemáticos clonados revalidan el dominio al
        // pasar por el DTO (defensa en profundidad) y recalculan el maná
        // de forma ciega (Art. II, RNF-03).
        $clonedInput = new SpellCalculationInputDto(
            damage: (int) $source['damage'],
            healing: (int) $source['healing'],
            barrier: (int) $source['barrier'],
            crowdControlType: (string) $source['crowd_control_type'],
            rangeType: (string) $source['range_type'],
            areaType: (string) $source['area_type'],
            durationType: (string) $source['duration_type'],
            hasVerbal: (bool) $source['has_verbal'],
            hasSomatic: (bool) $source['has_somatic'],
            hasMaterial: (bool) $source['has_material'],
        );
        $calculation     = $this->balanceService->calculate($clonedInput);
        $mathFingerprint = $this->balanceService->computeMathFingerprint($clonedInput);

        // La variante nace con identidad y nombre propios (el nombre
        // canónico es UNIQUE en todo el santuario). Ante colisión de
        // nombre, se resuelve con sufijo ordinal: «(Variante 2)», …
        $insertStatement = $this->pdo->prepare(
            'INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                                 mana_cost, circle, math_fingerprint, clan_id, summary, description,
                                 components_verbal, components_somatic, components_material,
                                 damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                                 has_verbal, has_somatic, has_material,
                                 status, validation_signatures_count, signatures_count, is_genesis_sample,
                                 created_at, updated_at)
             VALUES (:id, :slug, :name, :authorId, :magicSchool, :elementalAffinity, :castingTime,
                     :manaCost, :circle, :mathFingerprint, :clanId, :summary, :description,
                     :componentsVerbal, :componentsSomatic, :componentsMaterial,
                     :damage, :healing, :barrier, :crowdControlType, :rangeType, :areaType, :durationType,
                     :hasVerbal, :hasSomatic, :hasMaterial,
                     \'draft\', 0, 0, 0,
                     :createdAt, :updatedAt)'
        );

        $variantName   = $source['name'] . ' (Variante)';
        $spellId       = 'spl_' . bin2hex(random_bytes(6));
        $nowUtc        = gmdate('Y-m-d\TH:i:s\Z');
        $ordinalSuffix = 1;
        $inserted      = false;

        // Cada intento RECONSTRUYE el parámetro completo (un re-execute
        // con parámetros parciales violaría el contrato de PDO).
        do {
            $slug = $this->canonicalSlug($variantName);

            try {
                $insertStatement->execute([
                    ':id'                 => $spellId,
                    ':slug'               => $slug,
                    ':name'               => $variantName,
                    ':authorId'           => $invoker->getId(),
                    ':magicSchool'        => $source['magic_school'],
                    ':elementalAffinity'  => $source['elemental_affinity'],
                    ':castingTime'        => $source['casting_time'],
                    ':manaCost'           => $calculation->finalManaCost,
                    ':circle'             => $calculation->circle,
                    ':mathFingerprint'    => $mathFingerprint,
                    ':clanId'             => $invoker->getClanId(),
                    ':summary'            => $source['summary'],
                    ':description'        => $source['description'],
                    ':componentsVerbal'   => $source['components_verbal'],
                    ':componentsSomatic'  => $source['components_somatic'],
                    ':componentsMaterial' => $source['components_material'],
                    ':damage'             => $clonedInput->damage,
                    ':healing'            => $clonedInput->healing,
                    ':barrier'            => $clonedInput->barrier,
                    ':crowdControlType'   => $clonedInput->crowdControlType,
                    ':rangeType'          => $clonedInput->rangeType,
                    ':areaType'           => $clonedInput->areaType,
                    ':durationType'       => $clonedInput->durationType,
                    ':hasVerbal'          => (int) $clonedInput->hasVerbal,
                    ':hasSomatic'         => (int) $clonedInput->hasSomatic,
                    ':hasMaterial'        => (int) $clonedInput->hasMaterial,
                    ':createdAt'          => $nowUtc,
                    ':updatedAt'          => $nowUtc,
                ]);
                $inserted = true;
            } catch (RuntimeException $integrityViolation) {
                $ordinalSuffix++;
                $variantName = $source['name'] . ($ordinalSuffix === 1
                    ? ' (Variante)'
                    : " (Variante {$ordinalSuffix})");
                $spellId = 'spl_' . bin2hex(random_bytes(6));
            }
        } while (!$inserted && $ordinalSuffix <= self::MAX_DRAFTS_PER_AUTHOR);

        if (!$inserted) {
            throw new RuntimeException('El santuario no admite más variantes de ese nombre canónico.');
        }

        // Trazabilidad solemne (Art. III): la derivación queda en la
        // bitácora inmutable, con el linaje del validado como origen.
        $this->auditService->recordAction(
            actorUserId: $invoker->getId(),
            actorAlias: $invoker->getAlias(),
            actorRole: $invoker->getRole(),
            actionType: 'CREATE_VARIANT_FROM_VALIDATED',
            targetEntityType: 'spell',
            targetEntityId: $spellId,
            justification: "Derivación de Variante desde el conjuro validado '{$validatedSourceId}'. El original permanece inmutable; la evolución nace como borrador editable.",
        );

        return [
            'id'              => $spellId,
            'slug'            => $slug,
            'name'            => $variantName,
            'status'          => 'draft',
            'authorId'        => $invoker->getId(),
            'manaCost'        => $calculation->finalManaCost,
            'circle'          => $calculation->circle,
            'circleLabel'     => $calculation->circleLabel,
            'mathFingerprint' => $mathFingerprint,
            'signaturesCount' => 0,
        ];
    }

    // -----------------------------------------------------------------
    // Edición en moderación y antifraude de firmas (Tarea 3.3, RF-05.3/05.4).
    // -----------------------------------------------------------------

    /**
     * Actualiza un conjuro EN MODERACIÓN (status 'experimental') con la
     * salvaguarda antifraude de huella matemática (RF-05.3, RF-05.4,
     * Art. III): si los 10 parámetros matemáticos cambian (huella
     * distinta), las firmas de Maestros se RESETEAN a 0/3 de forma
     * estricta y el evento queda en la bitácora inmutable; si solo cambió
     * la narrativa (huella idéntica), las firmas se PRESERVAN y el
     * evento también se registra.
     *
     * El conjuro validado es patrimonio inmutable: cualquier intento
     * lanza SpellImmutableException (RF-06.2, Tarea 2.1).
     *
     * @return array{id: string, manaCost: int, circle: int, mathFingerprint: string, signaturesCount: int, signaturesReset: bool}
     *
     * @throws SpellImmutableException Si el conjuro ya está validado.
     * @throws RuntimeException Si el conjuro no existe o es ajeno.
     */
    public function updateExperimental(User $author, string $spellId, SpellCreateDto $createDto): array
    {
        // Titularidad: solo el autor edita su conjuro en moderación.
        $ownershipStatement = $this->pdo->prepare(
            'SELECT status, math_fingerprint FROM spells WHERE id = :spellId AND author_id = :authorId'
        );
        $ownershipStatement->execute([':spellId' => $spellId, ':authorId' => $author->getId()]);
        $existingRow = $ownershipStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($existingRow)) {
            throw new SpellNotFoundException('Ese conjuro no existe o no habita tu grimorio.');
        }

        $currentStatus = (string) $existingRow['status'];

        // Inviolabilidad del validado (RF-06.2, Art. III): muro estricto.
        if ($currentStatus === 'validated') {
            throw SpellImmutableException::forValidatedSpell($spellId);
        }

        if ($currentStatus !== 'experimental') {
            throw new RuntimeException('Solo los conjuros en moderación (experimental) admiten esta edición.');
        }

        // Antifraude (plan 3.2): la huella nueva de la carga vs. la huella
        // persistida del conjuro. La matemática decide, no la narrativa.
        $newFingerprint     = $this->balanceService->computeMathFingerprint($createDto->calculationInput);
        $oldFingerprint     = (string) $existingRow['math_fingerprint'];
        $mathChangeDetected = $newFingerprint !== $oldFingerprint;

        // Determinismo ciego (Art. II): el maná siempre se recalcula.
        $calculation = $this->balanceService->calculate($createDto->calculationInput);

        // Reseteo selectivo de firmas: solo ante cambio matemático.
        $signaturesCount = 0;
        if (!$mathChangeDetected) {
            $signaturesStatement = $this->pdo->prepare('SELECT signatures_count FROM spells WHERE id = :spellId');
            $signaturesStatement->execute([':spellId' => $spellId]);
            $signaturesCount = (int) $signaturesStatement->fetchColumn();
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE spells SET
                name = :name, magic_school = :magicSchool, elemental_affinity = :elementalAffinity,
                casting_time = :castingTime, mana_cost = :manaCost, circle = :circle,
                math_fingerprint = :mathFingerprint, summary = :summary, description = :description,
                damage = :damage, healing = :healing, barrier = :barrier,
                crowd_control_type = :crowdControlType, range_type = :rangeType,
                area_type = :areaType, duration_type = :durationType,
                has_verbal = :hasVerbal, has_somatic = :hasSomatic, has_material = :hasMaterial,
                signatures_count = :signaturesCount,
                updated_at = :updatedAt
             WHERE id = :spellId AND author_id = :authorId AND status = \'experimental\''
        );
        $updateStatement->execute([
            ':name'              => $createDto->name,
            ':magicSchool'       => $createDto->magicSchool,
            ':elementalAffinity' => $createDto->elementalAffinity,
            ':castingTime'       => $createDto->castingTime,
            ':manaCost'          => $calculation->finalManaCost,
            ':circle'            => $calculation->circle,
            ':mathFingerprint'   => $newFingerprint,
            ':summary'           => mb_substr($createDto->description, 0, 140),
            ':description'       => $createDto->description,
            ':damage'            => $createDto->calculationInput->damage,
            ':healing'           => $createDto->calculationInput->healing,
            ':barrier'           => $createDto->calculationInput->barrier,
            ':crowdControlType'  => $createDto->calculationInput->crowdControlType,
            ':rangeType'         => $createDto->calculationInput->rangeType,
            ':areaType'          => $createDto->calculationInput->areaType,
            ':durationType'      => $createDto->calculationInput->durationType,
            ':hasVerbal'         => (int) $createDto->calculationInput->hasVerbal,
            ':hasSomatic'        => (int) $createDto->calculationInput->hasSomatic,
            ':hasMaterial'       => (int) $createDto->calculationInput->hasMaterial,
            ':signaturesCount'   => $signaturesCount,
            ':updatedAt'         => gmdate('Y-m-d\TH:i:s\Z'),
            ':spellId'           => $spellId,
            ':authorId'          => $author->getId(),
        ]);

        // Trazabilidad solemne (Art. III, RF-08.1): ambos caminos quedan
        // imborrables en la bitácora, con su motivo en texto noble.
        if ($mathChangeDetected) {
            $this->auditService->recordAction(
                actorUserId: $author->getId(),
                actorAlias: $author->getAlias(),
                actorRole: $author->getRole(),
                actionType: 'RESET_SIGNATURES_MATH_CHANGE',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: 'Alteración de parámetros cuantitativos en moderación (fraude matemático detectado por huella). Firmas restablecidas a 0/3.',
            );
        } else {
            $this->auditService->recordAction(
                actorUserId: $author->getId(),
                actorAlias: $author->getAlias(),
                actorRole: $author->getRole(),
                actionType: 'UPDATE_DESCRIPTION_INTACT_SIGNATURES',
                targetEntityType: 'spell',
                targetEntityId: $spellId,
                justification: 'Actualización ortográfica/narrativa de conjuro experimental. Huella matemática intacta: firmas conservadas.',
            );
        }

        return [
            'id'              => $spellId,
            'manaCost'        => $calculation->finalManaCost,
            'circle'          => $calculation->circle,
            'mathFingerprint' => $newFingerprint,
            'signaturesCount' => $signaturesCount,
            'signaturesReset' => $mathChangeDetected,
        ];
    }

    // -----------------------------------------------------------------
    // Publicación a estado experimental (Tarea 3.2, RF-05.2).
    // -----------------------------------------------------------------

    /**
     * Publica un borrador del titular a estado 'experimental' (RF-05.2):
     * valida la autoría, REVALIDA la fórmula matemática de forma ciega
     * con SpellBalanceService (Art. II: el maná publicado es siempre el
     * del backend, jamás el persistido del cliente) y fija las firmas
     * en 0/3 para iniciar la Moderación en 2 pasos.
     *
     * @return array{id: string, status: string, manaCost: int, circle: int, mathFingerprint: string, signaturesCount: int}
     *
     * @throws RuntimeException Si el borrador no existe, es ajeno al
     *         invocante o ya no está en estado 'draft'.
     */
    public function publishToExperimental(User $author, string $spellId): array
    {
        // Titularidad y estado: SOLO el autor publica, y SOLO un draft.
        // Inexistente/ajeno → 404 canónico; ya publicado → RuntimeException
        // genérica (400 del plan), indistinguible aquí sin segunda consulta.
        $existing = $this->fetchOwnedDraft($author, $spellId);
        if ($existing === null) {
            $this->rejectIfValidated($author, $spellId);

            // La ficha existe y es propia pero ya no es borrador: 400.
            $stateStatement = $this->pdo->prepare('SELECT status FROM spells WHERE id = :spellId AND author_id = :authorId');
            $stateStatement->execute([':spellId' => $spellId, ':authorId' => $author->getId()]);
            if ($stateStatement->fetch(PDO::FETCH_ASSOC) !== false) {
                throw new RuntimeException('Ese conjuro ya no es un borrador: no puede publicarse de nuevo.');
            }

            throw new SpellNotFoundException('Ese borrador no existe o no habita tu grimorio.');
        }

        // Los parámetros matemáticos se releen de la fila persistida para
        // revalidarlos íntegramente en el servidor (Art. II, plan 5.2).
        $rowStatement = $this->pdo->prepare(
            'SELECT damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material
             FROM spells WHERE id = :spellId'
        );
        $rowStatement->execute([':spellId' => $spellId]);
        $quantitativeRow = $rowStatement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($quantitativeRow)) {
            throw new RuntimeException('Los parámetros del borrador no pudieron releerse del registro.');
        }

        $revalidatedInput = new SpellCalculationInputDto(
            damage: (int) $quantitativeRow['damage'],
            healing: (int) $quantitativeRow['healing'],
            barrier: (int) $quantitativeRow['barrier'],
            crowdControlType: (string) $quantitativeRow['crowd_control_type'],
            rangeType: (string) $quantitativeRow['range_type'],
            areaType: (string) $quantitativeRow['area_type'],
            durationType: (string) $quantitativeRow['duration_type'],
            hasVerbal: (bool) $quantitativeRow['has_verbal'],
            hasSomatic: (bool) $quantitativeRow['has_somatic'],
            hasMaterial: (bool) $quantitativeRow['has_material'],
        );

        // Revalidación ciega y autoritativa (RNF-03, plan 5.2): el coste
        // publicado es el que el motor determina AHORA, no el guardado.
        $calculation     = $this->balanceService->calculate($revalidatedInput);
        $mathFingerprint = $this->balanceService->computeMathFingerprint($revalidatedInput);

        $publishStatement = $this->pdo->prepare(
            "UPDATE spells
             SET status = 'experimental',
                 mana_cost = :manaCost,
                 circle = :circle,
                 math_fingerprint = :mathFingerprint,
                 signatures_count = 0,
                 updated_at = :updatedAt
             WHERE id = :spellId AND author_id = :authorId AND status = 'draft'"
        );
        $publishStatement->execute([
            ':manaCost'       => $calculation->finalManaCost,
            ':circle'         => $calculation->circle,
            ':mathFingerprint' => $mathFingerprint,
            ':updatedAt'      => gmdate('Y-m-d\TH:i:s\Z'),
            ':spellId'        => $spellId,
            ':authorId'       => $author->getId(),
        ]);

        return [
            'id'              => $spellId,
            'status'          => 'experimental',
            'manaCost'        => $calculation->finalManaCost,
            'circle'          => $calculation->circle,
            'mathFingerprint' => $mathFingerprint,
            'signaturesCount' => 0,
        ];
    }

    // -----------------------------------------------------------------
    // Ayudantes privados.
    // -----------------------------------------------------------------

    /**
     * Materializa el borrador SOLO si pertenece al titular invocante.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOwnedDraft(User $author, string $spellId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM spells WHERE id = :spellId AND author_id = :authorId AND status = 'draft'"
        );
        $statement->execute([':spellId' => $spellId, ':authorId' => $author->getId()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Slug canónico alfanumérico derivado del nombre (plan: slug único
     * para la URL rúnica). Los caracteres no latinos se descartan y los
     * espacios se convierten en guiones.
     */
    private function canonicalSlug(string $name): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $asciiName      = is_string($transliterated) ? $transliterated : $name;

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $asciiName));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'conjuro-sin-nombre';
    }
}

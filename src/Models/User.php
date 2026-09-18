<?php

/**
 * User.php — Entidad inmutable de dominio del miembro consagrado.
 *
 * Tarea 1.2 (TASKS-03): modelo tipado que materializa la tabla `users`
 * del esquema DDL (Tarea 1.1) y la jerarquía sagrada de roles (RF-05.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): entidad PHP pura, sin ORM ni librerías.
 *   - Artículo III: el clan es parte de la identidad del iniciado y
 *     alimenta la salvaguarda de conflicto de intereses (SPEC-03, RF-06).
 *   - Artículo V: propiedades y métodos en inglés camelCase,
 *     documentación en castellano.
 *
 * Seguridad:
 *   - El hash de la frase de paso es propiedad privada y jamás participa
 *     en la serialización JSON (criterio «Hecho cuando» de la tarea).
 *   - La inmutabilidad es total: sin setters, propiedades privadas
 *     inicializadas una única vez en el constructor.
 */

declare(strict_types=1);

namespace Grimorio\Models;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Miembro consagrado del santuario con su rol de la jerarquía sagrada.
 */
final class User implements JsonSerializable
{
    /** Roles canónicos de la jerarquía sagrada (RF-05.1, schema.sql). */
    private const CANONICAL_ROLES = ['reader', 'editor', 'master', 'supremeAdmin'];

    /**
     * @param string $id           Identificador textual (ej. 'usr_8f1a2b3c').
     * @param string $alias        Nombre de iniciado público (3 a 30 caracteres).
     * @param string $email        Correo electrónico validado.
     * @param string $role         Rol canónico de la jerarquía sagrada.
     * @param string $clanId       Identificador del linaje de afiliación.
     * @param string $passwordHash Hash BCRYPT de la frase de paso (jamás serializado).
     * @param string $createdAt    Marca temporal de alta (ISO 8601 UTC).
     * @param string $updatedAt    Marca temporal de última modificación (ISO 8601 UTC).
     *
     * @throws InvalidArgumentException Si el rol no pertenece al canon.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $alias,
        private readonly string $email,
        private readonly string $role,
        private readonly ?string $clanId,
        private readonly ?string $lineage = null,
        private readonly string $passwordHash = '',
        private readonly string $createdAt = '',
        private readonly string $updatedAt = '',
    ) {
        // Validación estricta en el nacimiento de la entidad: cualquier
        // rol fuera del canon deja la instancia inválida (criterio de la tarea).
        if (!in_array($this->role, self::CANONICAL_ROLES, true)) {
            throw new InvalidArgumentException(
                "Rol fuera del canon de la jerarquía sagrada: '{$this->role}'."
            );
        }
    }

    /** Identificador textual del iniciado. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Nombre de iniciado público. */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /** Correo electrónico validado. */
    public function getEmail(): string
    {
        return $this->email;
    }

    /** Rol técnico canónico ('reader', 'editor', 'master' o 'supremeAdmin'). */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Identificador del linaje de afiliación actual.
     *
     * Devuelve `null` cuando el iniciado no pertenece a ninguna hermandad:
     * es el estado canónico de quien aún no ha fundado un clan ni ha sido
     * admitido en uno (RF-01.2). La autoridad de la afiliación es
     * `clan_members`; este valor es su espejo denormalizado.
     */
    public function getClanId(): ?string
    {
        return $this->clanId;
    }

    /**
     * El vínculo perpetuo del Juramento de Linaje (SPEC-09, RF-04.3).
     *
     * Devuelve `null` cuando el adepto es «peregrino sin linaje»: la fase
     * de vida que la ceremonia bloqueante del primer acceso conduce al
     * juramento. Es VÍNCULO INDEPENDIENTE de la afiliación a clanes
     * (espejo `clanId`): la autoridad de aquella es `clan_members`;
     * la de este, el juramento sellado (RF-04.1).
     */
    public function getLineage(): ?string
    {
        return $this->lineage;
    }

    /**
     * Hash de la frase de paso. Canal exclusivo para el AuthService
     * (verificación temporal constante); jamás viaja en respuestas JSON.
     */
    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /** Marca temporal de alta (ISO 8601 UTC). */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /** Marca temporal de última modificación (ISO 8601 UTC). */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * Comprueba si el iniciado ejerce la jerarquía de Maestro.
     * El Admin Supremo hereda todas las capacidades del Maestro (RF-05.1).
     */
    public function isMaster(): bool
    {
        return $this->role === 'master' || $this->role === 'supremeAdmin';
    }

    /** Comprueba si el iniciado es el Admin Supremo. */
    public function isSupremeAdmin(): bool
    {
        return $this->role === 'supremeAdmin';
    }

    /**
     * Serialización JSON segura (RF-01.2, RNF-05): el hash de la frase
     * de paso se omite SIEMPRE. Las claves viajan en camelCase según el
     * contrato de la API (plan 2.2). La implementación nativa de
     * JsonSerializable garantiza que json_encode($user) invoque este
     * método (sin la interfaz, PHP serializaría las propiedades privadas
     * como objeto vacío o las expondría, según flags).
     *
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->id,
            'alias'     => $this->alias,
            'email'     => $this->email,
            'role'      => $this->role,
            'clanId'    => $this->clanId,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * Reconstruye la entidad desde una fila de base de datos
     * (snake_case de schema.sql, Tarea 1.1) mapeando a camelCase.
     *
     * @param array<string, null|string> $databaseRow Fila de la tabla users.
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        return new self(
            id: (string) ($databaseRow['id'] ?? ''),
            alias: (string) ($databaseRow['alias'] ?? ''),
            email: (string) ($databaseRow['email'] ?? ''),
            role: (string) ($databaseRow['role'] ?? ''),
            clanId: isset($databaseRow['clan_id']) && $databaseRow['clan_id'] !== null && (string) $databaseRow['clan_id'] !== ''
                ? (string) $databaseRow['clan_id']
                : null,
            lineage: isset($databaseRow['lineage']) && $databaseRow['lineage'] !== null && (string) $databaseRow['lineage'] !== ''
                ? (string) $databaseRow['lineage']
                : null,
            passwordHash: (string) ($databaseRow['password_hash'] ?? ''),
            createdAt: (string) ($databaseRow['created_at'] ?? ''),
            updatedAt: (string) ($databaseRow['updated_at'] ?? ''),
        );
    }
}

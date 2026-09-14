<?php

/**
 * LineageSynergyService.php — Servicio del catálogo de Linajes y de la
 * Sinergia Temática del Dominio Semanal.
 *
 * Tarea 2.2 (TASKS-07): consagra en el backend el mapa inmutable de los
 * ocho (8) Linajes Mágicos Canónicos (RF-02.1), con su elemento rector, su
 * glifo rúnico, su marco heráldico y su color de estandarte (RF-02.2), y
 * computa la bonificación de sinergia temática del +25% con redondeo
 * aritmético estándar (RF-03.4).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): solo PHP nativo; el catálogo es un dato
 *     litúrgico del santuario, no una decisión del motor.
 *   - Artículo II (Ley Universal del Maná): este servicio JAMÁS altera,
 *     descuenta ni sobrecarga el coste de maná de la forja (RF-02.3). Opera
 *     exclusivamente sobre gloria de clan (PDA); la neutralidad de la forja
 *     permanece inviolable y así se demuestra en su propio arnés.
 *   - Artículo IV (El Velo Arcano): nombres y prosa mitológica en noble
 *     castellano; los identificadores técnicos, en inglés camelCase.
 *   - Artículo V: métodos en inglés camelCase, documentación en castellano.
 *
 * Determinismo (RNF-01): el servicio es puro y sin efecto lateral — sin azar,
 * sin reloj del sistema y sin acceso a base de datos. Dos instancias
 * distintas forjan catálogos idénticos byte a byte y `applySynergy()` es una
 * función matemática de sus tres argumentos.
 */

declare(strict_types=1);

namespace Grimorio\Services;

use Grimorio\Dto\DominionAwardDto;
use Grimorio\Dto\LineageDto;
use InvalidArgumentException;

/**
 * Catálogo de los ocho Linajes Canónicos y motor de sinergia temática.
 */
final class LineageSynergyService
{
    /**
     * Bonificación de sinergia temática del Linaje (RF-03.4): +25% sobre el
     * valor base de la acción, con redondeo aritmético estándar.
     *
     * `DominionAwardDto::SYNERGY_MULTIPLIER` publica el mismo factor en el
     * contrato del recibo; el arnés de esta tarea comprueba que ambos
     * declaren idéntico valor para que jamás puedan divergir.
     */
    public const SYNERGY_MULTIPLIER = 1.25;

    /**
     * Marca canónica del conjuro sin afinidad elemental. Un conjuro sin
     * elemento rector (`none`) nunca devenga sinergia, pero tampoco es un
     * error: el Grimorio lo usa por defecto en las páginas sin afinidad.
     */
    public const NO_ELEMENT = 'none';

    /**
     * Los ocho Linajes Mágicos Canónicos (RF-02.1), con su elemento rector,
     * su sigilo heráldico, su color de estandarte y su marco distintivo
     * (RF-02.2). El orden es el del canon y el que expone el Endpoint 10.
     *
     * Los colores ceremoniales coinciden deliberadamente con la heráldica del
     * Códice Elemental (SPEC-06): un linaje y su elemento comparten estandarte.
     *
     * @var list<array{
     *   id: string, name: string, rulingElement: string, glyph: string,
     *   bannerColor: string, heraldicFrame: string, description: string
     * }>
     */
    private const CANONICAL_LINEAGES = [
        [
            'id'            => 'primordialFlame',
            'name'          => 'Linaje de la Llama Primordial',
            'rulingElement' => 'fire',
            'glyph'         => 'rune-ignis',
            'bannerColor'   => '#ff4500',
            'heraldicFrame' => 'phoenixShield',
            'description'   => 'Custodios de la chispa que precedió a toda forma; en su ceniza renace la llama inmortal.',
        ],
        [
            'id'            => 'celestialTides',
            'name'          => 'Linaje de las Mareas Celestiales',
            'rulingElement' => 'water',
            'glyph'         => 'rune-aqua',
            'bannerColor'   => '#00bfff',
            'heraldicFrame' => 'leviathanShield',
            'description'   => 'Tejedores de la marea y la escarcha, que ordenan el flujo eterno de las aguas del mundo.',
        ],
        [
            'id'            => 'eternalTempest',
            'name'          => 'Linaje de la Tempestad Eterna',
            'rulingElement' => 'lightning',
            'glyph'         => 'rune-fulgur',
            'bannerColor'   => '#9932cc',
            'heraldicFrame' => 'thunderbirdShield',
            'description'   => 'Portadores del fulgor que parte el cielo; su sentencia desciende en un parpadeo.',
        ],
        [
            'id'            => 'worldRoots',
            'name'          => 'Linaje de las Raíces del Mundo',
            'rulingElement' => 'earth',
            'glyph'         => 'rune-terra',
            'bannerColor'   => '#8b4513',
            'heraldicFrame' => 'worldtreeShield',
            'description'   => 'Guardianes de la piedra y la raíz, memoria mineral de cuanto el mundo ha sostenido.',
        ],
        [
            'id'            => 'dawnWinds',
            'name'          => 'Linaje de los Vientos del Alba',
            'rulingElement' => 'wind',
            'glyph'         => 'rune-ventus',
            'bannerColor'   => '#2e8b57',
            'heraldicFrame' => 'zephyrShield',
            'description'   => 'Mensajeros del aliento primero del amanecer, veloces como la promesa del día nuevo.',
        ],
        [
            'id'            => 'solarCrown',
            'name'          => 'Linaje de la Corona Solar',
            'rulingElement' => 'light',
            'glyph'         => 'rune-lux',
            'bannerColor'   => '#ffd700',
            'heraldicFrame' => 'sunwheelShield',
            'description'   => 'Heraldos de la luz que no admite sombra; su corona alumbra cuanto el juicio contempla.',
        ],
        [
            'id'            => 'abyssalShadows',
            'name'          => 'Linaje de las Sombras Abisales',
            'rulingElement' => 'darkness',
            'glyph'         => 'rune-tenebrae',
            'bannerColor'   => '#4b0082',
            'heraldicFrame' => 'voidSerpentShield',
            'description'   => 'Vigías del abismo silente, que custodian los secretos que la luz jamás osará preguntar.',
        ],
        [
            'id'            => 'aetherWeavers',
            'name'          => 'Linaje de los Tejedores del Éter',
            'rulingElement' => 'pureArcane',
            'glyph'         => 'rune-arcana',
            'bannerColor'   => '#4169e1',
            'heraldicFrame' => 'aetherloomShield',
            'description'   => 'Tramadores del éter primigenio, que urden la magia en estado puro antes de toda afinidad.',
        ],
    ];

    /** @var list<LineageDto>|null El catálogo forjado una sola vez por instancia. */
    private ?array $lineages = null;

    /**
     * Índice de búsqueda por clave canónica del linaje.
     *
     * @var array<string, LineageDto>|null
     */
    private ?array $lineageIndex = null;

    /**
     * El catálogo íntegro de los ocho Linajes Canónicos, en el orden del
     * canon (RF-02.1, RF-02.2): listo para serializarse en el Endpoint 10.
     *
     * @return list<LineageDto>
     */
    public function listLineages(): array
    {
        if ($this->lineages === null) {
            $this->lineages = array_values($this->lineageIndex());
        }

        return $this->lineages;
    }

    /**
     * Las ocho claves canónicas de los Linajes, en el orden del canon
     * (RF-02.1). Útil para validar la selección de linaje al fundar un clan.
     *
     * @return list<string>
     */
    public function canonicalLineageIds(): array
    {
        return array_keys($this->lineageIndex());
    }

    /**
     * El mapa inmutable de sinergia (plan 3.2): clave canónica del linaje
     * hacia su afinidad elemental rectora, en el orden del canon.
     *
     * Es una proyección derivada del catálogo, no una segunda fuente de
     * verdad: ambas no pueden divergir.
     *
     * @return array<string, string>
     */
    public function lineageElementMap(): array
    {
        $map = [];
        foreach ($this->listLineages() as $lineage) {
            $map[$lineage->id] = $lineage->rulingElement;
        }

        return $map;
    }

    /**
     * La ficha ceremonial de un linaje por su clave canónica (RF-02.1).
     *
     * @param string $lineageId Clave canónica en inglés camelCase (ej. 'primordialFlame').
     * @return LineageDto|null La ficha, o `null` si la clave es ajena al canon.
     */
    public function findLineage(string $lineageId): ?LineageDto
    {
        return $this->lineageIndex()[trim($lineageId)] ?? null;
    }

    /** ¿Es `$lineageId` una de las ocho claves canónicas del santuario? */
    public function hasLineage(string $lineageId): bool
    {
        return $this->findLineage($lineageId) !== null;
    }

    /**
     * El elemento rector de un linaje (RF-02.1).
     *
     * @param string $lineageId Clave canónica del linaje.
     *
     * @throws InvalidArgumentException Si el linaje no pertenece al canon.
     */
    public function rulingElementFor(string $lineageId): string
    {
        $lineage = $this->findLineage($lineageId);
        if ($lineage === null) {
            throw new InvalidArgumentException(
                "Linaje desconocido «{$lineageId}»: el código debe pertenecer al canon de los ocho Linajes Mágicos."
            );
        }

        return $lineage->rulingElement;
    }

    /**
     * ¿Devenga sinergia temática la coincidencia entre el linaje del clan y
     * el elemento de la obra? (RF-03.4)
     *
     * La comparación es estricta y ciega: un elemento ausente o marcado como
     * `none` nunca coincide, y un elemento ajeno al Códice tampoco (jamás se
     * lanza excepción por ello: un conjuro sin afinidad es legítimo).
     *
     * @param string      $lineageId Clave canónica del linaje rector del clan.
     * @param string|null $element   Afinidad elemental de la obra, o `null`.
     *
     * @throws InvalidArgumentException Si el linaje no pertenece al canon.
     */
    public function hasSynergy(string $lineageId, ?string $element): bool
    {
        if ($element === null) {
            return false;
        }

        $element = trim($element);
        if ($element === '' || $element === self::NO_ELEMENT) {
            return false;
        }

        return $this->rulingElementFor($lineageId) === $element;
    }

    /**
     * Aplica la sinergia temática del +25% al valor base de una acción de
     * dominio y devuelve los PDA finalmente acreditados (RF-03.4).
     *
     * Redondeo aritmético estándar al entero más próximo, con los decimales
     * iguales o superiores a 0.5 alzándose: `round(basePoints × 1.25)`.
     *   - 5 PDA × 1.25 = 6.25  → 6 PDA
     *   - 10 PDA × 1.25 = 12.5 → 13 PDA
     *
     * Sin coincidencia de afinidad, el valor base retorna sin alteración
     * alguna: la sinergia premia la especialización, jamás la penaliza.
     *
     * @param int         $basePoints PDA base de la acción (≥ 0).
     * @param string      $lineageId  Clave canónica del linaje rector del clan.
     * @param string|null $element    Afinidad elemental de la obra, o `null`.
     *
     * @throws InvalidArgumentException Si el valor base es negativo o el linaje es ajeno al canon.
     */
    public function applySynergy(int $basePoints, string $lineageId, ?string $element): int
    {
        if ($basePoints < 0) {
            throw new InvalidArgumentException('El valor base de una acción de dominio nunca es negativo.');
        }

        if (!$this->hasSynergy($lineageId, $element)) {
            return $basePoints;
        }

        return (int) round($basePoints * self::SYNERGY_MULTIPLIER);
    }

    /**
     * Gloria neta añadida por la sinergia temática: 0 cuando no la hay.
     *
     * Permite al Salón de los Linajes exhibir el diferencial de la
     * especialización elemental sin recalcular la fórmula.
     *
     * @throws InvalidArgumentException Si el valor base es negativo o el linaje es ajeno al canon.
     */
    public function synergyBonus(int $basePoints, string $lineageId, ?string $element): int
    {
        return $this->applySynergy($basePoints, $lineageId, $element) - $basePoints;
    }

    /**
     * ¿Declara el recibo de dominio canónico el mismo factor de sinergia que
     * este servicio? Salvaguarda contra la divergencia de ambas constantes.
     */
    public function agreesWithAwardContract(): bool
    {
        return DominionAwardDto::SYNERGY_MULTIPLIER === self::SYNERGY_MULTIPLIER;
    }

    /**
     * Forja el índice de linajes por clave canónica: la única fuente de
     * verdad del catálogo, materializada una sola vez por instancia.
     *
     * @return array<string, LineageDto>
     */
    private function lineageIndex(): array
    {
        if ($this->lineageIndex !== null) {
            return $this->lineageIndex;
        }

        $index = [];
        foreach (self::CANONICAL_LINEAGES as $specification) {
            $lineage = new LineageDto(
                id: $specification['id'],
                name: $specification['name'],
                rulingElement: $specification['rulingElement'],
                glyph: $specification['glyph'],
                bannerColor: $specification['bannerColor'],
                heraldicFrame: $specification['heraldicFrame'],
                description: $specification['description'],
            );

            $index[$lineage->id] = $lineage;
        }

        return $this->lineageIndex = $index;
    }
}

/**
 * spellCardComponent.js — Tarjeta del catálogo de hechizos (Tarea 4.2).
 *
 * RF-03.1: tarjeta con distintivos de escuela, coste de maná, clan y resumen.
 * RNF-03:  accesible por teclado — tabindex="0", role="article"; Enter/Space
 *          activan tanto como el click (criterio de la tarea).
 * Plan 8:  el clamp de 3 líneas es VISUAL (components.css, Tarea 2.4);
 *          el DOM porta el texto íntegro para los lectores de pantalla.
 *
 * Seguridad (AGENTS.md 6.1): TODO dato del DTO se renderiza vía
 * textContent — jamás innerHTML. El lore de usuarios no puede inyectar markup.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: etiquetas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

/** Clases de los sellos de estado (components.css, Tarea 2.4). */
export const SPELL_BADGE_KINDS = Object.freeze({
  genesis: 'spell-card__badge--genesis',
  unstable: 'spell-card__badge--unstable',
});

/**
 * Crea el elemento tarjeta a partir del SpellSummaryDto.
 *
 * @param {object} spellSummaryDto DTO del plan 2.1 (camelCase).
 * @param {object} componentOptions Opciones:
 *   - onSpellSelect(slug): callback de selección (click/Enter/Space).
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     las pruebas inyectan su DOM simulado).
 * @returns {HTMLElement} Nodo <article role="article" tabindex="0">.
 */
export function createSpellCardComponent(spellSummaryDto, componentOptions = {}) {
  const {
    onSpellSelect,
    elementFactory = (tagName) => document.createElement(tagName),
  } = componentOptions;

  /** Marcado de teclas activadoras (Enter/Space); otras se ignoran. */
  const ACTIVATION_KEYS = new Set(['Enter', ' ']);

  /** Raíz de la tarjeta (declarada antes de los handlers que la emiten). */
  let cardElement;

  /**
   * Manejador unificado de activación (click o teclado).
   * Emite el slug del hechizo al orquestador (criterio de la tarea).
   * @param {MouseEvent|KeyboardEvent} activationEvent Evento recibido.
   */
  function handleCardActivation(activationEvent) {
    if (activationEvent.type === 'keydown') {
      if (!ACTIVATION_KEYS.has(activationEvent.key)) {
        return; // Tab, letras, etc.: no activan.
      }
      // Enter/Space sobre un article enfocado no deben hacer scroll ni navegar.
      activationEvent.preventDefault();
    }

    // Emite el slug y, como segundo argumento, el nodo tarjeta (originElement):
    // el orquestador lo entrega a la ficha para el rescate de foco (RF-04.3).
    onSpellSelect?.(spellSummaryDto.slug, cardElement);
  }

  /**
   * Helper de construcción segura: nodo con clase y texto (textContent).
   * @param {string} tagName Etiqueta a crear.
   * @param {string} className Clase CSS del nodo.
   * @param {string} safeText Texto literal a alojar.
     */
  function createTextElement(tagName, className, safeText) {
    const element = elementFactory(tagName);
    element.className = className;
    // Solo textContent: el lore jamás se interpreta como markup (XSS).
    element.textContent = safeText;
    return element;
  }

  // --- Raíz de la tarjeta ---
  cardElement = elementFactory('article');
  cardElement.className = 'spell-card';
  cardElement.setAttribute('role', 'article');
  cardElement.setAttribute('tabindex', '0');
  cardElement.setAttribute('data-slug', spellSummaryDto.slug);
  // Nombre accesible: el lector anuncia el conjuro al enfocar (RNF-03).
  cardElement.setAttribute(
    'aria-label',
    `Hechizo ${spellSummaryDto.name}, escuela ${spellSummaryDto.magicSchoolLabel}, coste ${spellSummaryDto.manaCost} de maná`
  );

  // --- Nombre del conjuro ---
  cardElement.appendChild(
    createTextElement('h3', 'spell-card__name', spellSummaryDto.name)
  );

  // --- Insignias de escuela, maná y clan (RF-03.1) ---
  const badgesRow = elementFactory('div');
  badgesRow.className = 'spell-card__badges';

  const schoolBadge = createTextElement('span', 'spell-card__badge', spellSummaryDto.magicSchoolLabel);
  badgesRow.appendChild(schoolBadge);

  const manaBadge = createTextElement('span', 'spell-card__badge spell-card__badge--mana', `${spellSummaryDto.manaCost} maná`);
  badgesRow.appendChild(manaBadge);

  // --- Sello de estado (Art. III / RF-01.3 / RF-03.2) ---
  if (spellSummaryDto.isGenesisSample === true) {
    const genesisBadge = createTextElement('span', `spell-card__badge ${SPELL_BADGE_KINDS.genesis}`, 'Pergamino Primordial');
    badgesRow.appendChild(genesisBadge);
  } else if (spellSummaryDto.status === 'experimental') {
    const unstableBadge = createTextElement('span', `spell-card__badge ${SPELL_BADGE_KINDS.unstable}`, 'Inestabilidad Arcana');
    badgesRow.appendChild(unstableBadge);
  }

  cardElement.appendChild(badgesRow);

  // --- Resumen (texto íntegro en el DOM; el clamp visual lo recorta) ---
  cardElement.appendChild(
    createTextElement('p', 'spell-card__summary', spellSummaryDto.summary)
  );

  // --- Clan de origen ---
  cardElement.appendChild(
    createTextElement('p', 'spell-card__clan', spellSummaryDto.clanName)
  );

  // --- Activación por click y teclado (criterio) ---
  cardElement.addEventListener('click', handleCardActivation);
  cardElement.addEventListener('keydown', handleCardActivation);

  /** Retira los listeners (baja limpia al re-renderizar la rejilla). */
  function destroy() {
    cardElement.removeEventListener('click', handleCardActivation);
    cardElement.removeEventListener('keydown', handleCardActivation);
  }

  // El nodo expone destroy para el ciclo de vida de la rejilla (Tarea 5.2).
  cardElement.destroy = destroy;

  return cardElement;
}

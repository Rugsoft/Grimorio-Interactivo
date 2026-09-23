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

/**
 * Clase del ribete ceremonial dorado del Clan Regente (SPEC-07, RF-04.4).
 * Su vestidura vive en `clan-heraldry.css`.
 */
export const REGENT_RIBBON_CLASS = 'spell-card-regent-border';

// =====================================================================
// EL GESTO COMPARTIDO DEL TOMO (SPEC-11, Tarea 5.1, plan §4.2)
// ---------------------------------------------------------------------
// Una sola lógica de gestos para Biblioteca, Simulador y Tomo (RF-04.0):
// la tarjeta NACE sabiendo su estado —viaja en el DTO del backend— y
// decide el gesto UNA sola vez, jamás consultando al servidor por fila.
//
//   collected: false y estado validated → botón «Añadir al tomo».
//   collected: true                     → conmutador «Ya está en tu tomo»
//                                         (informativo, aria-pressed).
//   praised: true                       → conmutador «Ya rendiste homenaje».
//   militancia en la casa del hechizo   → gesto «Elogiar» ausente +
//                                         leyenda sobria (RF-04.4).
//   estado ≠ validated                  → ni «Añadir» ni «Elogiar»
//                                         (RF-04.5).
// =====================================================================

/** Eventos del bus del shell (plan §4.1): la tarjeta los EMITE; las
 *  vistas (libraryView, grimoireSimulatorView) delegan al cliente. */
export const TOME_CARD_EVENTS = Object.freeze({
  /** El adepto activa «Añadir al tomo» (RF-01.1). */
  tomeSeal: 'tome:seal',
  /** El adepto activa «Elogiar» (RF-04.1). */
  tomePraise: 'tome:praise',
});

/** Rótulos canónicos del gesto compartido (plan §4.3, Textos LITERALES). */
export const TOME_CARD_LABELS = Object.freeze({
  /** Botón de sellado (RF-01.1). */
  addToTome: 'Añadir al tomo',
  /** Conmutador informativo de colección (RF-01.3). */
  alreadyInTome: 'Ya está en tu tomo',
  /** Conmutador informativo de homenaje (RF-04.3). */
  alreadyPraised: 'Ya rendiste homenaje',
  /** Botón de elogio (RF-04.1). */
  praise: 'Elogiar',
  /** Leyenda sobria de militancia (RF-04.4, plan §4.3). */
  ownClanLegend: 'Un adepto de la casa no granjea gloria para su propio estandarte',
});

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
 *   - isRegent: ¿pertenece el conjuro al Clan Regente de la semana? Si lo es,
 *     la tarjeta luce el ribete ceremonial dorado (SPEC-07, RF-04.4).
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     las pruebas inyectan su DOM simulado).
 * @returns {HTMLElement} Nodo <article role="article" tabindex="0">.
 */
export function createSpellCardComponent(spellSummaryDto, componentOptions = {}) {
  const {
    onSpellSelect,
    onTomeGesture,
    isRegent = false,
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
  // El ribete dorado se COMPONE sobre la clase canónica: la tarjeta sigue
  // siendo la del Tomo —pergamino, tinta y tipografía intactos— y solo muda
  // su filo (SPEC-07, RF-04.4).
  cardElement.className = isRegent ? `spell-card ${REGENT_RIBBON_CLASS}` : 'spell-card';
  if (isRegent) {
    cardElement.setAttribute('data-regent', 'true');
  }
  cardElement.setAttribute('role', 'article');
  cardElement.setAttribute('tabindex', '0');
  cardElement.setAttribute('data-slug', spellSummaryDto.slug);
  // El linaje de origen viaja en el DOM para que el Tomo pueda ceñir el
  // ribete a posteriori, cuando el Salón revele quién reina (RF-04.4).
  cardElement.setAttribute('data-clan-id', String(spellSummaryDto.clanId ?? ''));
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

  // Insignia elemental (Tarea 3.2, RF-03.1/03.3): la afinidad se deriva
  // de la escuela mágica (mapa canónico; SPEC-06 la formalizará) y se
  // propaga como Custom Properties inline ligadas a los tokens de
  // tokens.css (color, fulgor y glifo rúnico del elemento).
  const SCHOOL_ELEMENT_AFFINITY = {
    evocation: 'fire',
    conjuration: 'water',
    divination: 'light',
    enchantment: 'wind',
    illusion: 'darkness',
    necromancy: 'darkness',
    transmutation: 'earth',
    abjuration: 'light'
  };
  const affinityName = SCHOOL_ELEMENT_AFFINITY[spellSummaryDto.magicSchool] ?? 'arcane';

  const elementalBadge = elementFactory('span');
  elementalBadge.className = `spell-card__badge spell-card__badge-elemental spell-card__badge-elemental--${affinityName}`;
  // Ligadura inline explícita a los tokens canónicos (color, fulgor y
  // glifo de tokens.css): robusta incluso sin la clase variante.
  elementalBadge.style.setProperty('--current-element', `var(--affinity-${affinityName})`);
  elementalBadge.style.setProperty('--current-glow', `var(--glow-affinity-${affinityName})`);
  elementalBadge.style.setProperty('--current-glyph', `var(--glyph-affinity-${affinityName})`);
  const elementalGlyph = elementFactory('span');
  elementalGlyph.className = 'spell-card__element-glyph';
  elementalGlyph.setAttribute('aria-hidden', 'true');
  elementalBadge.appendChild(elementalGlyph);
  elementalBadge.appendChild(createTextElement('span', 'spell-card__element-label', spellSummaryDto.elementalAffinityLabel ?? 'Arcano Puro'));
  badgesRow.appendChild(elementalBadge);

  const schoolBadge = createTextElement('span', 'spell-card__badge spell-card__badge-school', spellSummaryDto.magicSchoolLabel);
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

  // --- El gesto compartido del tomo (SPEC-11, Tarea 5.1, plan §4.2) ---
  // La lógica vive UNA sola vez (RF-04.0): derivada ÍNTEGRAMENTE del DTO.
  // Sin `adeptState` (anónimo, DTO legado) la tarjeta no pinta gesto: la
  // contemplación pública queda intocada (RF-05.1 de SPEC-03).
  const tomeStatusArea = createTomeStatusArea();
  if (tomeStatusArea !== null) {
    cardElement.appendChild(tomeStatusArea);
  }

  // --- Activación por click y teclado (criterio) ---
  cardElement.addEventListener('click', handleCardActivation);
  cardElement.addEventListener('keydown', handleCardActivation);

  /**
   * Forja la zona del gesto compartido (plan §4.2): UN botón de gesto
   * activo, O conmutadores informativos con aria-pressed, O leyenda de
   * vedación — según el DTO. null cuando nada debe pintarse (anónimo).
   *
   * Los gestos vedados JAMÁS llegan al bus: solo los botones activos
   * emiten `tome:seal` / `tome:praise` (criterio de la tarea).
   *
   * @returns {HTMLElement|null} La zona lista para anexar, o null.
   */
  function createTomeStatusArea() {
    const adeptState = spellSummaryDto.adeptState;
    if (adeptState === null || adeptState === undefined || typeof adeptState !== 'object') {
      return null; // Anónimo o DTO legado: contemplación sin gestos.
    }

    const status = String(spellSummaryDto.status ?? '');
    const collected = adeptState.collected === true;
    const praised = adeptState.praised === true;
    // Militancia veda el elogio (RF-04.4); el backend la deriva del
    // instante (la casa del hechizo es la del clan del DTO).
    const allowedToPraise = adeptState.praiseAllowed !== false;
    const isValidated = status === 'validated';

    const tomeArea = elementFactory('div');
    tomeArea.className = 'spell-card__tome';

    /** Emite el evento del bus con el DTO y el nodo origen (plan §4.1). */
    function emitTomeEvent(eventType) {
      if (typeof onTomeGesture === 'function') {
        onTomeGesture(eventType, {
          spellId: spellSummaryDto.id ?? null,
          slug: spellSummaryDto.slug,
          originElement: cardElement,
        });
      }
    }

    /** Botón activo de un gesto operativo (RNF-04: teclado y foco). */
    function createGestureButton(kind, label, eventType) {
      const button = elementFactory('button');
      button.type = 'button';
      button.className = `spell-card__tome-gesture spell-card__tome-gesture--${kind}`;
      button.textContent = label;
      button.addEventListener('click', () => emitTomeEvent(eventType));
      return button;
    }

    /** Conmutador informativo de un estado ya consumado (aria-pressed). */
    function createStatusToggle(kind, label) {
      const toggle = elementFactory('span');
      toggle.className = `spell-card__tome-toggle spell-card__tome-toggle--${kind}`;
      toggle.setAttribute('role', 'switch');
      toggle.setAttribute('aria-pressed', 'true');
      toggle.setAttribute('aria-label', label);
      toggle.textContent = label;
      return toggle;
    }

    // --- Conmutador de colección: siempre visible con sesión (RF-01.3) --
    if (collected) {
      tomeArea.appendChild(createStatusToggle('collected', TOME_CARD_LABELS.alreadyInTome));
    }

    // --- Conmutador de homenaje: el voto ya vive (RF-04.3) --------------
    if (praised) {
      tomeArea.appendChild(createStatusToggle('praised', TOME_CARD_LABELS.alreadyPraised));
    }

    // --- Gestos operativos: solo lo vedado queda ausente (RF-04.4/04.5) -
    if (!collected && isValidated) {
      tomeArea.appendChild(createGestureButton('seal', TOME_CARD_LABELS.addToTome, TOME_CARD_EVENTS.tomeSeal));
    }

    if (isValidated && !praised && allowedToPraise) {
      tomeArea.appendChild(createGestureButton('praise', TOME_CARD_LABELS.praise, TOME_CARD_EVENTS.tomePraise));
    }

    // --- Leyenda sobria de militancia (RF-04.4): el gesto AUSENTE -------
    // se comunica con su voz canónica — ni error ni silencio.
    if (isValidated && !praised && !allowedToPraise) {
      const ownClanLegend = elementFactory('p');
      ownClanLegend.className = 'spell-card__tome-vedado';
      ownClanLegend.textContent = TOME_CARD_LABELS.ownClanLegend;
      tomeArea.appendChild(ownClanLegend);
    }

    return tomeArea;
  }

  /** Retira los listeners (baja limpia al re-renderizar la rejilla). */
  function destroy() {
    cardElement.removeEventListener('click', handleCardActivation);
    cardElement.removeEventListener('keydown', handleCardActivation);
  }

  // El nodo expone destroy para el ciclo de vida de la rejilla (Tarea 5.2).
  cardElement.destroy = destroy;

  return cardElement;
}

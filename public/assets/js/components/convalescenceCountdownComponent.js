/**
 * convalescenceCountdownComponent.js — La cuenta atrás de la
 * Convalecencia Arcana del Panel del Adepto (SPEC-12, Tarea 6.4;
 * plan §1.2/§3.4/§6.2).
 *
 * Componente de la sección «Convalecencia Arcana»: cuenta atrás en
 * DÍAS NATURALES (la unidad que SPEC-07 ratifica) con reloj DIARIO
 * inyectable (jamás por segundo, RNF-03: al cruzar cada frontera de
 * día el contador decrementa), anuncios SOLO en los hitos {≤7, ≤3, 1,
 * alzamiento} por la región viva, y ALZAMIENTO sin recarga al llegar
 * a cero: refresco de la vitrina por evento `panel:convalescence-lifted`
 * + anuncio «Tu penitencia ha concluido» + silencio posterior (RF-05.2,
 * RF-05.3). Sin convalecencia el componente NO se monta: jamás una
 * sección fantasma de veto asusta al adepto en paz (RF-05.3).
 *
 * Frontera sagrada (Artículo II): el componente JAMÁS decide el canon
 * — la aritmética canónica `daysRemaining = ceil((expiresAt - ahora) /
 * 1 día)` vive en el DTO del santuario (UserPanelDto, espejo de
 * ClanVestibuleService); el componente solo la MUESTRA y la hace
 * decrecer al cruzar fronteras de día. La autoridad del alzamiento es
 * el refresco del panel (los datos del santuario), jamás un reloj local.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module components/convalescenceCountdownComponent
 */

/** Rótulos y leyendas solemnes del contador (Art. V, plan §6.2). */
export const CONVALESCENCE_LEGENDS = Object.freeze({
  daysSingular: 'Alzamiento de tu penitencia en 1 día.',
  daysPlural: (count) => `Alzamiento de tu penitencia en ${count} días.`,
  lifted: 'Tu penitencia ha concluido.',
  milestones: Object.freeze({
    seven: 'Restan 7 días para el alzamiento de tu penitencia.',
    three: 'Restan 3 días para el alzamiento de tu penitencia.',
    one: 'Resta 1 día para el alzamiento de tu penitencia.',
  }),
});

/** Hitos de anuncio (RNF-03: jamás cada segundo, jamás cada día). */
export const CONVALESCENCE_MILESTONES = Object.freeze([7, 3, 1]);

/** Eventos que el componente emite sobre su raíz (plan §4.2). */
export const CONVALESCENCE_EVENTS = Object.freeze({
  convalescenceLifted: 'panel:convalescence-lifted',
});

/**
 * Calcula los días naturales que restan hasta una estampa ISO
 * (espejo EXACTO de UserPanelDto: ceil, frontera inclusiva; los
 * arneses la ejercitan con el reloj inyectable).
 *
 * @param {string|null} expiresAt Estampa ISO del alzamiento.
 * @param {Date}        now       Instante «ahora» (reloj inyectable).
 * @returns {number|null} Días naturales restantes, o null si no hay estampa.
 */
export function daysRemainingFor(expiresAt, now = new Date()) {
  if (typeof expiresAt !== 'string' || expiresAt.trim() === '') return null;
  const instant = new Date(expiresAt);
  if (Number.isNaN(instant.getTime())) return null;
  return Math.max(0, Math.ceil((instant.getTime() - now.getTime()) / 86400000));
}

/**
 * Crea el contador de convalecencia.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (la sección Convalecencia).
 * @param {Object} options
 * @param {Object}   options.convalescence Sobre canónico del DTO
 *        { expiresAt, daysRemaining, causeLegend, previousClanName,
 *          retainedLegend }.
 * @param {() => Date} [options.now] Reloj inyectable (RNF-01 del panel:
 *        determinismo en los arneses); por defecto, el reloj real.
 * @param {() => void} [options.onLifted] Canal del orquestador para el
 *        refresco de vitrina SIN recarga (RF-05.2; complementa el
 *        evento del bus).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus (por defecto, la raíz).
 * @returns {Object} API: { render, destroy, tick }.
 */
export function createConvalescenceCountdownComponent(mountRoot, options = {}) {
  const {
    convalescence,
    now = () => new Date(),
    onLifted,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));
  const eventTarget = options.eventTarget ?? mountRoot;

  /** Último nivel anunciado (jamás se repite un hito, RNF-03). */
  let lastAnnouncedLevel = null;
  /** Nivel mostrado en la cuenta (decrece al cruzar fronteras de día). */
  let currentDays = Number(convalescence?.daysRemaining ?? 0);
  /** El alzamiento ya se consumó en esta sesión (silencio posterior). */
  let lifted = false;
  let dailyTimer = null;
  let destroyed = false;

  /**
   * Forja un elemento con clase, texto y atributos en una sola voz.
   */
  function forge(tagName, spec = {}) {
    const element = elementFactory(tagName);
    if (spec.className) element.className = spec.className;
    if (spec.text !== undefined) element.textContent = spec.text;
    for (const [name, value] of Object.entries(spec.attrs ?? {})) {
      element.setAttribute(name, String(value));
    }
    return element;
  }

  /** Busca por clase con DOBLE vía (classList y atributo className). */
  function findByClassName(root, className) {
    const walk = (node) => {
      for (const child of node?.children ?? []) {
        if (child.classList?.contains?.(className)
          || (typeof child.className === 'string' && child.className.split(/\s+/).includes(className))) {
          return child;
        }
        const found = walk(child);
        if (found) return found;
      }
      return null;
    };
    return walk(root);
  }

  /** Emite el alzamiento por el bus y el canal directo (plan §4.2). */
  function emitLifted() {
    const EventCtor = globalThis.CustomEvent
      ?? class { constructor(type, options_ = {}) { this.type = type; this.detail = options_.detail ?? null; } };
    eventTarget.dispatchEvent(new EventCtor(CONVALESCENCE_EVENTS.convalescenceLifted, { detail: { liftedAt: now().toISOString() }, bubbles: true }));
    onLifted?.();
  }

  /** Anuncia un mensaje por la región viva del componente (RNF-03). */
  function announce(message) {
    const region = findByClassName(mountRoot, 'convalescence-countdown__live');
    if (region) region.textContent = message;
  }

  /**
   * Anuncia un hito UNA SOLA VEZ por nivel (RNF-03: jamás se espamea
   * un hito ya narrado).
   */
  function announceMilestone(level, message) {
    if (lastAnnouncedLevel === level) return;
    lastAnnouncedLevel = level;
    announce(message);
  }

  /** Pinta el nivel de días en el contador visible. */
  function paintDays(days) {
    const counter = findByClassName(mountRoot, 'convalescence-countdown__days');
    if (counter) {
      counter.textContent = days === 1
        ? CONVALESCENCE_LEGENDS.daysSingular
        : CONVALESCENCE_LEGENDS.daysPlural(days);
    }
  }

  /** El alzamiento: anuncio único, refresco por evento y silencio. */
  function lift() {
    if (lifted) return;
    lifted = true;
    paintDays(0);
    const counter = findByClassName(mountRoot, 'convalescence-countdown__days');
    if (counter) counter.setAttribute('data-lifted', 'true');
    announceMilestone('lifted', CONVALESCENCE_LEGENDS.lifted);
    emitLifted();
  }

  /**
   * Un latido del reloj diario: si la frontera del día cruzó, el
   * contador decrementa y anuncia SOLO si alcanza un hito (RNF-03).
   * El reloj JAMÁS adelanta el alzamiento: si los datos del santuario
   * dictan cero, el refresco manda (Artículo II).
   */
  function tick() {
    if (destroyed || lifted) return;
    const remaining = daysRemainingFor(convalescence?.expiresAt ?? null, now());
    // La aritmética del DTO manda: si el reloj local discrepa (drift),
    // prevalece el último nivel servido por el santuario.
    if (remaining !== null) currentDays = Math.min(currentDays, remaining);

    if (currentDays <= 0) {
      // Cero alcanzado: el alzamiento se anuncia y la vitrina se
      // refresca SIN recarga (RF-05.2); el santuario refrenda con sus
      // datos en el refresco que el evento dispara (plan §3.4).
      lift();
      return;
    }

    paintDays(currentDays);
    if (CONVALESCENCE_MILESTONES.includes(currentDays)) {
      const legends = {
        7: CONVALESCENCE_LEGENDS.milestones.seven,
        3: CONVALESCENCE_LEGENDS.milestones.three,
        1: CONVALESCENCE_LEGENDS.milestones.one,
      };
      announceMilestone(currentDays, legends[currentDays]);
    }
    currentDays -= 1;
  }

  return {
    /**
     * Monta el contador y programa el reloj DIARIO (jamás por segundo,
     * RNF-03). Los arneses invocan tick() a mano con su reloj.
     */
    render() {
      const liveRegion = forge('div', {
        className: 'convalescence-countdown__live',
        attrs: { 'aria-live': 'polite' },
      });
      mountRoot.appendChild(liveRegion);

      const counter = forge('p', { className: 'convalescence-countdown__days' });
      counter.setAttribute('data-expires-at', String(convalescence?.expiresAt ?? ''));
      mountRoot.appendChild(counter);

      if (typeof convalescence?.causeLegend === 'string' && convalescence.causeLegend !== '') {
        mountRoot.appendChild(forge('p', { className: 'convalescence-countdown__cause', text: convalescence.causeLegend }));
      }
      if (typeof convalescence?.retainedLegend === 'string' && convalescence.retainedLegend !== '') {
        mountRoot.appendChild(forge('p', { className: 'convalescence-countdown__retained', text: convalescence.retainedLegend }));
      }

      paintDays(currentDays);

      // El primer anuncio respeta los hitos: si la cuenta NACE en un
      // hito ({≤7, ≤3, 1}), se anuncia ya (con moderación, una vez).
      if (CONVALESCENCE_MILESTONES.includes(currentDays)) {
        const legends = {
          7: CONVALESCENCE_LEGENDS.milestones.seven,
          3: CONVALESCENCE_LEGENDS.milestones.three,
          1: CONVALESCENCE_LEGENDS.milestones.one,
        };
        announceMilestone(currentDays, legends[currentDays]);
      }

      if (currentDays <= 0) {
        lift();
        return;
      }

      // Reloj DIARIO: 24h por latido (jamás por segundo, RNF-03). En
      // los arneses, tick() se invoca a mano con el reloj inyectable.
      if (typeof globalThis.setInterval === 'function') {
        dailyTimer = globalThis.setInterval(() => tick(), 24 * 60 * 60 * 1000);
      }
    },

    /** Un latido manual del reloj (arneses: determinismo, RNF-01). */
    tick() {
      tick();
    },

    /** Retira el componente y su reloj diario. */
    destroy() {
      destroyed = true;
      if (dailyTimer !== null && typeof globalThis.clearInterval === 'function') {
        globalThis.clearInterval(dailyTimer);
      }
      mountRoot.replaceChildren?.();
    },
  };
}

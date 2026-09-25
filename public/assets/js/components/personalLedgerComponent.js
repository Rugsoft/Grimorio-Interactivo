/**
 * personalLedgerComponent.js — La Lente de Bitácora Personal del Panel
 * del Adepto (SPEC-12, Tarea 6.3; plan §1.2/§6.2).
 *
 * Componente de la sección «Bitácora personal»: lista semántica de
 * asientos con estampas legibles (ISO → fecha castellana), paginación
 * por cursor con botón «Ver más» (20 por página, sin duplicados — el
 * cursor opaco lo sella el backend, Tarea 4.1) y leyenda solemne de
 * silencio ante `entries: []` (RF-06.3: jamás una página vacía cruda).
 *
 * Los asientos narran los actos CON terceros conforme a lo ya público
 * (RF-06.4): el entry porta actionLabel/actionType/createdAt/narrative/
 * targetKind — jamás identificadores crudos ni datos personales del
 * firmante más allá de la justification pública (contrato §2.7).
 *
 * Frontera sagrada (RF-06.2): la lente es PURA LECTURA — el componente
 * jamás escribe, duplica ni resume asientos: narra lo que el santuario
 * sirve, paginando por el cursor que el propio backend dicta.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module components/personalLedgerComponent
 */

/** Rótulos y leyendas solemnes de la lente (Art. V). */
export const PERSONAL_LEDGER_LEGENDS = Object.freeze({
  heading: 'Bitácora personal',
  emptyLegend: 'La bitácora aún no registra acto alguno que te concierna: tu historia aguarda su primera estampa.',
  moreButton: 'Ver más asientos',
  loading: 'Hojear los anales…',
  unavailable: 'La lente de la bitácora no puede iluminarse en este instante: inténtalo de nuevo en breve.',
  targetKinds: Object.freeze({
    user: 'Sobre tu identidad',
    spell: 'Sobre una obra',
    clan: 'Sobre una hermandad',
  }),
});

/** Eventos que el componente emite sobre su raíz (plan §4.2). */
export const PERSONAL_LEDGER_EVENTS = Object.freeze({
  ledgerPageLoaded: 'panel:ledger-page-loaded',
});

/**
 * Estampa temporal legible (plan §4.3): ISO 8601 → «el 12 de
 * septiembre de 2026». Degradación noble ante estampa ilegible.
 *
 * @param {string|null} iso Estampa temporal ISO 8601.
 * @returns {string|null} Fecha castellana, o null si no hay estampa.
 */
export function formatLedgerTimestamp(iso) {
  if (typeof iso !== 'string' || iso.trim() === '') return null;
  const instant = new Date(iso);
  if (Number.isNaN(instant.getTime())) return null;
  return new Intl.DateTimeFormat('es-ES', { dateStyle: 'long' }).format(instant);
}

/**
 * Crea la lente de bitácora personal.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (la sección Bitácora).
 * @param {Object} options
 * @param {Object} options.panelClient Cliente del panel (fetchLedger).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus (por defecto, la raíz).
 * @returns {Object} API: { render, destroy, loadMore }.
 */
export function createPersonalLedgerComponent(mountRoot, options = {}) {
  const {
    panelClient,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));
  const eventTarget = options.eventTarget ?? mountRoot;

  /** Cursor opaco de la última página servida (null = agotada). */
  let nextCursor = null;
  /** La bitácora ya se hojeó por completo (jamás petición fantasma). */
  let exhausted = false;
  /** Guardia de concurrencia: un solo recorrido por vez. */
  let loading = false;
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

  /** Emite el evento de página cargada (plan §4.2). */
  function emitPageLoaded(count) {
    const EventCtor = globalThis.CustomEvent
      ?? class { constructor(type, options_ = {}) { this.type = type; this.detail = options_.detail ?? null; } };
    eventTarget.dispatchEvent(new EventCtor(PERSONAL_LEDGER_EVENTS.ledgerPageLoaded, { detail: { count }, bubbles: true }));
  }

  /** Narra un asiento como <li> semántico (RF-06.1, plan §4.3). */
  function renderEntry(list, entry) {
    const item = forge('li', { className: 'panel-ledger__entry' });

    const stamp = forge('time', {
      className: 'panel-ledger__stamp',
      text: formatLedgerTimestamp(entry.createdAt) ?? 'Estampa no disponible',
      attrs: { datetime: String(entry.createdAt ?? '') },
    });
    item.appendChild(stamp);

    const action = forge('p', { className: 'panel-ledger__action' });
    action.appendChild(forge('strong', { text: entry.actionLabel ?? entry.actionType ?? 'Acto arcano' }));
    const targetKind = PERSONAL_LEDGER_LEGENDS.targetKinds[entry.targetKind] ?? null;
    if (targetKind !== null) {
      action.appendChild(forge('span', { className: 'panel-ledger__target', text: ` — ${targetKind}` }));
    }
    item.appendChild(action);

    if (typeof entry.narrative === 'string' && entry.narrative.trim() !== '') {
      item.appendChild(forge('p', { className: 'panel-ledger__narrative', text: entry.narrative }));
    }

    list.appendChild(item);
  }

  /**
   * Consume una página de la lente (la primera o la siguiente por
   * cursor) y la narra sobre la lista viva.
   */
  async function loadMore() {
    // Agotada la bitácora (o en pleno vuelo), la lente JAMÁS pide de más
    // (RF-06.2: pura lente; sin peticiones fantasma tras el agotamiento).
    if (loading || destroyed || exhausted) return false;
    loading = true;
    const button = findByClassName(mountRoot, 'panel-ledger__more');
    if (button) button.disabled = true;

    const envelope = await panelClient.fetchLedger(nextCursor);
    if (destroyed) return false;
    loading = false;
    if (button) button.disabled = false;

    const emptySlot = findByClassName(mountRoot, 'panel-ledger__empty');
    const unavailableSlot = findByClassName(mountRoot, 'panel-ledger__unavailable');

    // --- Fallo del velo (sin trazas, RF-06.2: la lente no rompe) ----
    if (envelope?.success !== true) {
      if (unavailableSlot) unavailableSlot.textContent = PERSONAL_LEDGER_LEGENDS.unavailable;
      return false;
    }
    if (unavailableSlot) unavailableSlot.textContent = '';

    const entries = Array.isArray(envelope.data?.entries) ? envelope.data.entries : [];
    nextCursor = envelope.data?.nextCursor ?? null;
    if (nextCursor === null) exhausted = true;

    if (entries.length === 0 && nextCursor === null) {
      // --- La leyenda de silencio (RF-06.3): jamás página cruda ----
      if (emptySlot) emptySlot.textContent = PERSONAL_LEDGER_LEGENDS.emptyLegend;
      // El «Ver más» se retira también ante el silencio.
      const silentButton = findByClassName(mountRoot, 'panel-ledger__more');
      if (silentButton) {
        silentButton.remove?.();
        silentButton.setAttribute?.('data-retired', 'true');
      }
      emitPageLoaded(0);
      return false;
    }

    const list = findByClassName(mountRoot, 'panel-ledger__list');
    for (const entry of entries) {
      if (list) renderEntry(list, entry);
    }
    if (emptySlot) emptySlot.textContent = '';

    emitPageLoaded(entries.length);

    // Agotada la bitácora: el «Ver más» se retira (jamás un botón muerto).
    if (nextCursor === null && button) {
      button.remove?.();
      button.setAttribute?.('data-retired', 'true');
    }
    return nextCursor !== null;
  }

  return {
    /**
     * Monta la lente y consume la primera página (la paginación es
     * perezosa, jamás sondea; RNF-06).
     */
    async render() {
      const liveRegion = forge('div', {
        className: 'panel-ledger__live',
        attrs: { 'aria-live': 'polite' },
      });
      mountRoot.appendChild(liveRegion);

      mountRoot.appendChild(forge('p', { className: 'panel-ledger__empty', attrs: { role: 'status' } }));
      mountRoot.appendChild(forge('p', { className: 'panel-ledger__unavailable', attrs: { role: 'alert' } }));

      const list = forge('ul', {
        className: 'panel-ledger__list',
        attrs: { 'aria-label': PERSONAL_LEDGER_LEGENDS.heading },
      });
      mountRoot.appendChild(list);

      const more = forge('button', {
        className: 'panel-ledger__more panel-cta',
        text: PERSONAL_LEDGER_LEGENDS.moreButton,
        attrs: { type: 'button' },
      });
      more.addEventListener('click', () => { void loadMore(); });
      mountRoot.appendChild(more);

      await loadMore();
    },

    /** Página siguiente por cursor (para el botón y para arneses). */
    loadMore() {
      return loadMore();
    },

    /** Retira el componente. */
    destroy() {
      destroyed = true;
      mountRoot.replaceChildren?.();
    },
  };
}

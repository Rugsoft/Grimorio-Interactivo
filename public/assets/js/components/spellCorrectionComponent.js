/**
 * spellCorrectionComponent.js — Libreta de Subsanación del Autor.
 *
 * Tarea 6.2 (TASKS-08). Panel PRIVADO del autor para conjuros vetados
 * (`rejected`): exhibe el pergamino ámbar con el dictamen de objeción
 * del Maestro —leído del expediente canónico, jamás resumido ni
 * reescrito (RF-06.2, Art. IV)— y el botón ceremonial
 * **«Reabrir como Borrador»** (`reopenAsDraft`, RF-01.4).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo, ES Modules, cero dependencias.
 *   - Artículo II (Ley del Maná): el componente JAMÁS aritmetiza estado
 *     ni dictamina: retrata lo que el servidor sirve (`spellStatus`,
 *     `lastVerdict`). El estado canónico lo fija la consulta, no la vista.
 *   - Artículo III: el panel del autor jamás lee `hasEthicalConflict` ni
 *     memoria de clanes; solo ve su propia obra y su dictamen.
 *   - RNF-03: leyendas ceremoniales en noble castellano.
 *   - AGENTS.md 6.1: cero `innerHTML` — todo texto viaja por `textContent`.
 *
 * Contrato de datos (camelCase, espejo del Dto `ObjectionVerdictDto`):
 *   item: {
 *     spellId, spellName, spellStatus: 'rejected',
 *     lastVerdict: { id, spellId, masterId, objectionReason,
 *                    reasonLength, objectedAt } | null,
 *   }
 */

/** Leyendas ceremoniales del panel (RNF-03). */
export const SPELL_CORRECTION_LEGENDS = Object.freeze({
  regionTitle: 'Libreta de Subsanación del Autor',
  rejectedBadge: 'Vetado — Obra rechazada',
  verdictScrollTitle: 'Dictamen del Maestro — Observaciones del Cónclave',
  verdictFields: Object.freeze({
    masterId: 'Maestro dictaminante',
    objectionReason: 'Observaciones del veto',
    reasonLength: 'Extensión del dictamen',
    objectedAt: 'Fecha del dictamen',
  }),
  noVerdictLegend: 'El Maestro aún no ha dejado constancia del motivo del veto.',
  reopenButton: 'Reabrir como Borrador',
  reopenHint: 'Tu obra volverá a la libreta como borrador: la edición quedará habilitada y el dictamen se conservará a la vista (RF-06.2).',
  reopenConfirmed: 'Tu obra vuelve a la libreta como borrador: la edición queda habilitada.',
  emptyLegend: 'No tienes conjuros vetados a la espera de subsanación.',
  outageLegend: 'Se ha interrumpido la corriente de maná. Vuelve a intentarlo cuando el flujo se restablezca.',
  retryButton: 'Reintentar la consulta',
});

/** Reserva de ética: veto sin dictamen visible (degradación elegante). */
export const SPELL_CORRECTION_RESERVES = Object.freeze({
  noVerdict: 'El pergamino del dictamen no ha llegado desde el santuario; consulta tu obra más tarde.',
});

/**
 * Fábrica del panel de subsanación.
 *
 * @param {HTMLElement|Object} mountRoot Contenedor anfitrión.
 * @param {Object} [options]
 *   - `onReopen`: callback del gesto de re-apertura, recibe `{spellId}`.
 *   - `elementFactory`: fábrica de nodos (arneses). Por defecto, `document`.
 *   - `documentRef`: documento anfitrión para el bus de eventos (arneses).
 * @returns {Object} API: { render, setSpellStatus, destroy }.
 */
export function createSpellCorrectionComponent(mountRoot, options = {}) {
  const {
    onReopen = null,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  const legends = SPELL_CORRECTION_LEGENDS;

  /** Nodos vivos del panel, para limpieza determinista. */
  const mountedNodes = [];

  /** El panel quedó destruido: nada vuelve a nacer ni a anunciar. */
  let isDestroyed = false;

  /** El expediente que retrata el panel: lo sirve el bus o el orquestador. */
  let currentItem = null;

  /** Alerta de corriente de maná vigente (degradación elegante). */
  let outageNode = null;

  /** Registra un nodo como hijo del panel para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Anuncia un gesto por el bus desacoplado (plan 4.1). */
  function emitBusEvent(eventName, detail) {
    try {
      const eventConstructor = documentRef.CustomEvent ?? globalThis.CustomEvent;
      documentRef.defaultView?.dispatchEvent?.(new eventConstructor(eventName, { detail }));
    } catch {
      // Sin CustomEvent (entornos mínimos): el callback portará la intención.
    }
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Añade un campo rotulado del dictamen (retrato literal, sin resumen). */
  function appendVerdictField(parent, fieldName, value) {
    const fieldNode = track(elementFactory('p'));
    fieldNode.className = 'spell-correction__verdict-field';
    const fieldLabel = fieldNode.appendChild(elementFactory('strong'));
    fieldLabel.textContent = `${legends.verdictFields[fieldName] ?? fieldName}: `;
    const fieldValue = fieldNode.appendChild(elementFactory('span'));
    fieldValue.setAttribute('data-verdict-field', fieldName);
    fieldValue.textContent = String(value ?? '');
    parent.appendChild(fieldNode);
    return fieldNode;
  }

  /**
   * Repinta el panel completo con el expediente servido.
   *
   * El estado canónico lo fija la consulta del servidor (Art. II): el
   * panel retrata `item.spellStatus === 'rejected'` tal y como llega; no
   * transiciona estados por su cuenta.
   *
   * @param {Object|null} item Expediente `{spellId, spellName,
   *   spellStatus, lastVerdict}`.
   */
  function render(item) {
    if (isDestroyed) return;
    clearOutage();
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    currentItem = item ?? null;

    // La región vive SIEMPRE en el montaje: aun sin obra vetada, la
    // leyenda de vacío se declara por sí sola (degradación elegante).
    const region = track(elementFactory('section'));
    region.className = 'spell-correction';
    region.setAttribute('data-spell-status', String(item?.spellStatus ?? ''));
    region.setAttribute('aria-label', legends.regionTitle);
    mountRoot.appendChild(region);

    if (!item || item.spellStatus !== 'rejected') {
      appendTextElement(region, 'p', 'spell-correction__empty', legends.emptyLegend);
      return;
    }

    appendTextElement(region, 'h3', 'spell-correction__title', String(item.spellName ?? ''));

    const badge = appendTextElement(region, 'p', 'spell-correction__status', legends.rejectedBadge);
    badge.setAttribute('data-role', 'correction-status');

    appendTextElement(region, 'h4', 'spell-correction__verdict-title', legends.verdictScrollTitle);

    const scroll = track(elementFactory('blockquote'));
    scroll.className = 'spell-correction__verdict-scroll';
    region.appendChild(scroll);

    const verdict = item.lastVerdict;
    if (verdict && typeof verdict === 'object' && verdict.objectionReason) {
      appendVerdictField(scroll, 'masterId', verdict.masterId);
      appendVerdictField(scroll, 'objectionReason', verdict.objectionReason);
      appendVerdictField(scroll, 'reasonLength', verdict.reasonLength);
      appendVerdictField(scroll, 'objectedAt', verdict.objectedAt);
    } else {
      appendTextElement(scroll, 'p', 'spell-correction__verdict-empty', legends.noVerdictLegend);
    }

    appendTextElement(region, 'p', 'spell-correction__reopen-hint', legends.reopenHint);

    const reopenButton = track(elementFactory('button'));
    reopenButton.type = 'button';
    reopenButton.className = 'spell-correction__reopen button button--primary';
    reopenButton.setAttribute('data-role', 'reopen-draft');
    reopenButton.textContent = legends.reopenButton;
    reopenButton.addEventListener('click', () => {
      const spellId = String(item.spellId ?? '');
      // Gesto DECLARADO: el panel jamás consulta ni transiciona por su
      // cuenta; anuncia la intención y el orquestador invoca al cliente
      // (plan 4.1: el evento no es opcional ni es un extra del callback).
      emitBusEvent('moderation:reopen-intent', { spellId });
      if (typeof onReopen === 'function') {
        onReopen({ spellId });
      }
    });
    region.appendChild(reopenButton);
  }

  /**
   * Retrata un cambio de estado ARRIBADO por evento (plan 4.1:
   * `moderation:rejected`), sin re-consulta.
   *
   * @param {string} spellId Conjuro afectado.
   * @param {string} status Estado canónico servido.
   */
  function setSpellStatus(spellId, status) {
    if (isDestroyed) return;
    if (!currentItem || currentItem.spellId !== spellId) return;
    render({ ...currentItem, spellStatus: status });
  }

  /** Pinta la alerta de corriente de maná con su reintento. */
  function showOutage(onRetry) {
    if (isDestroyed) return;
    clearOutage();
    outageNode = track(elementFactory('div'));
    outageNode.className = 'spell-correction__outage';
    outageNode.setAttribute('role', 'alert');
    const message = outageNode.appendChild(elementFactory('p'));
    message.textContent = legends.outageLegend;
    const retry = outageNode.appendChild(elementFactory('button'));
    retry.type = 'button';
    retry.textContent = legends.retryButton;
    retry.addEventListener('click', () => {
      clearOutage();
      if (typeof onRetry === 'function') onRetry();
    });
    mountRoot.appendChild(outageNode);
  }

  /** Retira la alerta de corriente de maná, si la hubiera. */
  function clearOutage() {
    outageNode?.remove?.();
    outageNode = null;
  }

  /** Retira el panel y libera los nodos. Idempotente. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    clearOutage();
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
  }

  return { render, setSpellStatus, showOutage, destroy };
}

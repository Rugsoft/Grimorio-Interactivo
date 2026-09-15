/**
 * objectionModalComponent.js — Modal solemne del Dictamen de Objeción.
 *
 * Tarea 6.1 (TASKS-08). Diálogo litúrgico que INTERCEPTA el veto de un
 * Maestro (RF-02.5) y recoge el gesto que la Torre de Deliberación anuncia
 * (`moderation:objection-intent` o `onObjectionIntent`, Tarea 5.3). Con
 * validador dinámico decreciente: el envío queda INHABILITADO mientras la
 * justificación no alcance los veinte caracteres en noble castellano.
 *
 * Contrato de datos: el modal solo REDACTA. El umbral canónico (veinte
 * caracteres) llega por options.minReasonLength o por options.canon
 * (`MasterDeliberationService::deliberationCanon()`, reservando 20); el
 * veredicto final lo dicta SIEMPRE el backend (422 OBJECTION_TOO_BRIEF), y
 * este modal es cortesía de la interfaz, jamás la ley (Art. II).
 *
 * Decisiones que costará reconstruir:
 *   1. El envío se INHABILITA por debajo del umbral y se habilita al
 *      alcanzarlo; el contador decreciente (restantes) vive en una región
 *      viva `role="status"` para que el lector de pantalla lo anuncie con
 *      cortesía mientras se redacta.
 *   2. La justificación se NORMALIZA como el backend la medirá: el umbral se
 *      evalúa sobre el texto RECORTADO (el servicio mide `trim()`), de modo
 *      que cuarenta espacios jamás lucen como justificación ni el borde de
 *      veinte engaña (plan 2.4: «cuarenta espacios no son justificación»).
 *   3. Un carácter a menos es un carácter a menos: el contador NO redondea
 *      hacia arriba ni admite el borde de diecinueve (la suite del backend
 *      probó 19 → 422 y 20 → 200).
 *   4. El veredicto viaja por el bus desacoplado (`moderation:objection-submitted`,
 *      plan 4.1) y por el veredicto de la promesa; el modal JAMÁS llama a la
 *      API por su cuenta — la Torre (Tarea 5.3) y el orquestador cursan el
 *      gesto por moderationClient (Tarea 5.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; textContent puro y `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1); cero librerías ni plantillas.
 *   - Artículo II: el modal jamás dicta el umbral: lo recibe de su fuente.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Accesibilidad: `alertdialog` modal con foco preso al nacer en el campo
 *     de redacción, Escape desiste, y el contador anunciado con cortesía.
 */

/** Umbral de reserva del dictamen (solo si nadie aporta canon ni minReasonLength). */
export const OBJECTION_MODAL_FALLBACK_MIN_LENGTH = 20;

/** Rótulos ceremoniales del modal (RNF-03). */
export const OBJECTION_MODAL_LEGENDS = Object.freeze({
  title: 'Dictamen de Objeción Fundamentada',
  notice: 'Tu justificación viajará íntegra al autor para que subsane su obra: redacta con la dignidad que el Velo Arcano exige (Artículo IV).',
  reasonLabel: 'Justificación solemne en castellano',
  submitLabel: 'Emitir el Dictamen',
  cancelLabel: 'Desistir del veto',
  pendingLegend: 'Faltan {remaining} caracteres para alcanzar la solemnidad de veinte.',
  readyLegend: 'La justificación alcanza la solemnidad requerida: el dictamen puede emitirse.',
  announce: 'Diálogo de objeción abierto: redacta la justificación fundamentada.',
});

/**
 * Crea el modal del Dictamen de Objeción.
 *
 * @param {HTMLElement} mountRoot Contenedor donde nacerá el diálogo.
 * @param {Object} options
 * @param {number} [options.minReasonLength] Umbral canónico del dictamen;
 *        por defecto, el que traiga `options.canon` o la reserva de 20.
 * @param {object} [options.canon] Canon servido por la Torre (Tarea 5.3);
 *        se lee `minObjectionLength` si `minReasonLength` no viaja.
 * @param {string} [options.spellId] Obra sobre la que se dicta (viaja en los
 *        anuncios del veredicto).
 * @param {(spellId: string, objectionReason: string) => void} [options.onSubmitted]
 *        Anuncio del veredicto al orquestador (que cursará el gesto).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @returns {Object} API: { open, destroy }.
 */
export function createObjectionModalComponent(mountRoot, options = {}) {
  const {
    minReasonLength,
    canon = null,
    spellId = '',
    onSubmitted = null,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  const servedThreshold = Number(canon?.minObjectionLength);
  const threshold = Number.isInteger(Number(minReasonLength))
    ? Number(minReasonLength)
    : (Number.isInteger(servedThreshold) ? servedThreshold : OBJECTION_MODAL_FALLBACK_MIN_LENGTH);

  /** Nodos vivos del modal, para limpieza determinista. */
  const mountedNodes = [];

  /** El diálogo quedó destruido: nada vuelve a nacer ni a anunciar. */
  let isDestroyed = false;

  /** Diálogo vigente (uno solo a la vez). */
  let overlayNode = null;

  /** Registra un nodo como hijo del modal para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Anuncia un veredicto por el bus desacoplado (plan 4.1). */
  function emitBusEvent(eventName, detail) {
    try {
      documentRef.defaultView?.dispatchEvent?.(new CustomEvent(eventName, { detail }));
    } catch {
      // Sin CustomEvent (entornos mínimos): el callback portará la intención.
    }
  }

  /**
   * Abre el modal y aguarda el veredicto del Maestro.
   *
   * @param {Object} [request] {spellId} — la obra sobre la que se dicta.
   * @returns {Promise<{submitted: boolean, objectionReason: string}>}
   *   `submitted: true` solo si el dictamen fue confirmado con su texto.
   */
  function open(request = {}) {
    if (isDestroyed) {
      return Promise.resolve({ submitted: false, objectionReason: '' });
    }
    if (overlayNode !== null) {
      // Un solo dictamen a la vez: el diálogo vigente manda.
      return Promise.resolve({ submitted: false, objectionReason: '' });
    }

    const decreeSpellId = typeof request?.spellId === 'string' && request.spellId !== ''
      ? request.spellId
      : spellId;

    return new Promise((resolve) => {
      const overlay = elementFactory('div');
      overlay.className = 'objection-modal';
      overlay.setAttribute('role', 'alertdialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'objectionModalTitle');
      overlay.setAttribute('aria-describedby', 'objectionModalNotice');
      overlay.setAttribute('data-spell-id', decreeSpellId);
      mountRoot.appendChild(overlay);
      overlayNode = track(overlay);

      const panel = elementFactory('section');
      panel.className = 'objection-modal__panel';
      overlay.appendChild(panel);
      track(panel);

      const title = appendTextElement(panel, 'h3', 'objection-modal__title', OBJECTION_MODAL_LEGENDS.title);
      title.setAttribute('id', 'objectionModalTitle');

      const notice = appendTextElement(panel, 'p', 'objection-modal__notice', OBJECTION_MODAL_LEGENDS.notice);
      notice.setAttribute('id', 'objectionModalNotice');

      // El campo de redacción: textarea nativo con su etiqueta asociada.
      const reasonLabel = appendTextElement(panel, 'label', 'objection-modal__reason-label', OBJECTION_MODAL_LEGENDS.reasonLabel);
      reasonLabel.setAttribute('for', 'objectionModalReason');
      const reasonField = track(elementFactory('textarea'));
      reasonField.className = 'objection-modal__reason';
      reasonField.setAttribute('id', 'objectionModalReason');
      reasonField.setAttribute('data-role', 'objection-reason');
      reasonField.setAttribute('rows', '5');
      panel.appendChild(reasonField);

      // El contador decreciente (criterio): región viva de cortesía.
      const counter = appendTextElement(panel, 'p', 'objection-modal__counter', '');
      counter.setAttribute('role', 'status');
      counter.setAttribute('aria-live', 'polite');
      counter.setAttribute('data-role', 'objection-counter');

      const actions = track(elementFactory('div'));
      actions.className = 'objection-modal__actions';
      panel.appendChild(actions);

      const cancelButton = track(elementFactory('button'));
      cancelButton.type = 'button';
      cancelButton.className = 'objection-modal__cancel button button--secondary';
      cancelButton.textContent = OBJECTION_MODAL_LEGENDS.cancelLabel;
      actions.appendChild(cancelButton);

      const submitButton = track(elementFactory('button'));
      submitButton.type = 'button';
      submitButton.className = 'objection-modal__submit button button--primary';
      submitButton.setAttribute('data-role', 'objection-submit');
      submitButton.textContent = OBJECTION_MODAL_LEGENDS.submitLabel;
      actions.appendChild(submitButton);

      /**
       * Valida la justificación y refresca el contador y el botón.
       *
       * El umbral se mide sobre el texto RECORTADO: es como el backend lo
       * medirá (`trim()`), y cuarenta espacios jamás lucen como justificación.
       */
      function refreshCounter() {
        const trimmedLength = String(reasonField.value ?? '').trim().length;
        const remaining = threshold - trimmedLength;

        if (remaining > 0) {
          submitButton.disabled = true;
          submitButton.setAttribute('aria-disabled', 'true');
          counter.textContent = OBJECTION_MODAL_LEGENDS.pendingLegend.replace('{remaining}', String(remaining));
        } else {
          submitButton.disabled = false;
          submitButton.removeAttribute('aria-disabled');
          counter.textContent = OBJECTION_MODAL_LEGENDS.readyLegend;
        }
      }

      /** Cierra el diálogo resolviendo el veredicto y devolviendo el foco. */
      const settle = (verdict) => {
        const submittedText = verdict === true ? String(reasonField.value ?? '').trim() : '';
        overlay.remove?.();
        overlayNode = null;
        overlay.removeEventListener?.('keydown', onKeydown);
        reasonField.removeEventListener?.('input', refreshCounter);
        submitButton.removeEventListener?.('click', onConfirm);
        cancelButton.removeEventListener?.('click', onCancel);

        if (verdict === true) {
          emitBusEvent('moderation:objection-submitted', { spellId: decreeSpellId, objectionReason: submittedText });
          if (typeof onSubmitted === 'function') {
            onSubmitted(decreeSpellId, submittedText);
          }
        }

        resolve({ submitted: verdict === true, objectionReason: submittedText });
      };

      function onConfirm() {
        // Doble guarda: el veredicto final lo dictará el backend, pero el
        // modal jamás entrega un texto por debajo del umbral que anuncia.
        const trimmedLength = String(reasonField.value ?? '').trim().length;
        if (trimmedLength < threshold) {
          refreshCounter();
          return;
        }
        settle(true);
      }

      function onCancel() {
        settle(false);
      }

      function onKeydown(event) {
        if (event?.key === 'Escape') {
          event.preventDefault?.();
          settle(false);
        }
      }

      reasonField.addEventListener('input', refreshCounter);
      submitButton.addEventListener('click', onConfirm);
      cancelButton.addEventListener('click', onCancel);
      overlay.addEventListener('keydown', onKeydown);

      // Estado inicial: el envío nace INHABILITADO y el foco nace en la
      // redacción, porque el gesto que sigue es escribir el dictamen.
      refreshCounter();
      reasonField.focus?.();
    });
  }

  /** Retira el diálogo vigente, si lo hubiera, y libera los nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    overlayNode?.remove?.();
    overlayNode = null;
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
  }

  return { open, destroy };
}

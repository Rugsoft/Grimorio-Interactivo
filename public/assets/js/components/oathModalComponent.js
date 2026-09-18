/**
 * oathModalComponent.js — Modal solemne de doble confirmación del Juramento
 * (SPEC-09, Tarea 4.2).
 *
 * RF-02.3: sobre un linaje expandido, presenta el texto íntegro del juramento
 *           en primera persona junto a la advertencia de perpetuidad — visible,
 *           explícita e INELUDIBLE (jamás letra menuda ni tooltip) — y exige
 *           una segunda confirmación explícita: «Sellar el juramento».
 * RNF-03:  el descarte (botón, × o Escape) no consume juramento alguno; el
 *           foco queda atrapado dentro mientras esté abierto y regresa al
 *           elemento originador al cerrar.
 * RNF-05:  anuncios por región viva (aria-live) de apertura y veredicto;
 *           foco visible y objetivo táctil completo.
 *
 * Eventos del plan §4 (CustomEvent sobre el diálogo anfitrión):
 *   - `oath:confirmation-opened`    { lineageId }
 *   - `oath:confirmation-dismissed` {}
 *   - `oath:confirmation-confirmed` { lineageId }
 *     (la vista consume este último para invocar al cliente del juramento;
 *      el veredicto final viaja como `oath:sealed`/`oath:failed` de la vista).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): <dialog> nativo y DOM por createElement con
 *     textContent puro — innerHTML PROHIBIDO (AGENTS.md 6.1). Cero dependencias.
 *   - Artículo IV: leyendas solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/oathModalComponent
 */

/** Leyendas solemnes del modal (RNF-02: castellano, voz ceremoniosa). */
export const OATH_MODAL_TITLE = 'El Juramento';
export const OATH_MODAL_PERPETUITY_LEGEND =
  'Este vínculo es perpetuo: jamás podrá cambiarse ni revocarse. Solo una cuenta purgada nace de nuevo sin linaje.';
export const OATH_MODAL_SEAL_LABEL = 'Sellar el juramento';
export const OATH_MODAL_DISMISS_LABEL = 'Rechazar por ahora';
export const OATH_MODAL_ANNOUNCE_OPEN = 'El modal de juramento está abierto. La decisión es perpetua.';
export const OATH_MODAL_ANNOUNCE_CONFIRMED = 'Juramento sellado.';
export const OATH_MODAL_ANNOUNCE_DISMISSED = 'Juramento descartado. Ningún vínculo fue consumado.';

/** El texto del juramento en primera persona: nombra SIEMPRE al linaje (RF-02.2). */
export function buildOathText(lineageName) {
  return `Yo, peregrino sin linaje, juro ante el canon el ${lineageName}: `
    + 'mi palabra se sella con la suya y mi camino queda tejido al suyo. '
    + 'Lo declaro con conocimiento de causa y sin coacción, para siempre.';
}

/**
 * Crea el modal solemne del juramento.
 *
 * @param {HTMLDialogElement} dialogElement Elemento `<dialog>` anfitrión.
 *        Puede venir del shell o forjarse en pruebas: el componente es
 *        agnóstico del origen.
 * @param {Object} componentOptions Opciones:
 *   - onConfirm(lineageId): la vista consume la segunda confirmación (invocará
 *     al cliente del juramento; el modal NO llama a la API jamás).
 *   - originElement: elemento originador (la tarjeta expandida); el foco
 *     regresa a él al cerrar (RNF-03).
 *   - documentRef / windowRef: inyecciones para pruebas.
 * @returns {Object} API: { open, close, isOpen, destroy }.
 */
export function createOathModalComponent(dialogElement, componentOptions = {}) {
  const {
    onConfirm,
    originElement = null,
    documentRef = globalThis.document,
    windowRef = globalThis.window,
  } = componentOptions;

  /** Guardia de cableado único y estado vivo. */
  let isWired = false;
  let isDestroyed = false;
  let isOpen = false;
  let currentLineageId = null;
  let currentOriginElement = originElement;

  /** Región viva (anuncios ARIA, RNF-05). */
  let liveRegion = null;

  /** Emite un evento del plan §4 sobre el diálogo (bus local de la ceremonia). */
  function emit(eventName, detail) {
    const CustomEventCtor = windowRef?.CustomEvent ?? globalThis.CustomEvent;
    dialogElement.dispatchEvent(new CustomEventCtor(eventName, { detail, bubbles: true }));
  }

  /** Localiza el primer botón enfocable del panel (foco inicial). */
  function findFirstButton(root) {
    for (const child of root.children ?? []) {
      if (child.tagName === 'BUTTON') return child;
      const found = findFirstButton(child);
      if (found) return found;
    }
    return null;
  }

  /** Descendientes enfocables (focus trap manual para el DOM simulado). */
  function findFocusables(root, found = []) {
    for (const child of root.children ?? []) {
      if (child.tagName === 'BUTTON' || child.getAttribute?.('tabindex') !== null) found.push(child);
      findFocusables(child, found);
    }
    return found;
  }

  /**
   * RNF-03 (focus trap): con showModal() el navegador atrapa el foco de forma
   * nativa; este listener cubre el caso límite de un Tab en el borde del
   * diálogo (DOM simulado incluido) y lo devuelve al primer botón.
   */
  function handleKeydown(keydownEvent) {
    if (keydownEvent.key === 'Escape') {
      // El navegador cierra el dialog con Escape (descarte seguro); 'close'
      // centraliza el resto. En DOM simulado, close() explícito lo cubre.
      return;
    }
    if (keydownEvent.key !== 'Tab') return;
    keydownEvent.preventDefault?.();
    const focusables = findFocusables(dialogElement);
    if (focusables.length === 0) return;
    const currentIndex = focusables.indexOf(keydownEvent.target);
    const nextIndex = keydownEvent.shiftKey
      ? (currentIndex <= 0 ? focusables.length - 1 : currentIndex - 1)
      : (currentIndex === -1 || currentIndex === focusables.length - 1 ? 0 : currentIndex + 1);
    focusables[nextIndex]?.focus();
  }

  /** Cierra como DESCARTE (botón/×/Escape): no consume juramento (RNF-03). */
  function dismiss() {
    if (!isOpen) return;
    close({ dismissed: true });
  }

  /** Segunda confirmación explícita: notifica a la vista y cierra. */
  function confirmSeal() {
    if (!isOpen || currentLineageId === null) return;
    const lineageId = currentLineageId;
    announce(OATH_MODAL_ANNOUNCE_CONFIRMED);
    emit('oath:confirmation-confirmed', { lineageId });
    if (typeof onConfirm === 'function') onConfirm(lineageId);
    close({ dismissed: false });
  }

  /**
   * Cierra el diálogo y devuelve el foco al originador (RNF-03).
   * @param {object} options `{ dismissed }` distingue descarte de confirmación.
   */
  function close({ dismissed = true } = {}) {
    if (!isOpen) return;
    isOpen = false;
    if (dismissed) {
      emit('oath:confirmation-dismissed', {});
      announce(OATH_MODAL_ANNOUNCE_DISMISSED);
    }
    if (dialogElement.open) {
      dialogElement.close(dismissed ? 'oath-dismissed' : 'oath-confirmed');
    }
    // El foco regresa a la tarjeta expandida que originó el gesto (RNF-03).
    currentOriginElement?.focus?.();
    currentLineageId = null;
  }

  /** Anuncia en la región viva (RNF-05). */
  function announce(message) {
    if (liveRegion) liveRegion.textContent = message;
  }

  /** Cablea el panel del modal UNA sola vez (re-aperturas idempotentes). */
  function wirePanel() {
    if (isWired) return;
    isWired = true;

    // El panel se forja si el shell aún no lo trae (arneses, pruebas).
    let panel = dialogElement.querySelector?.('.oath-modal__panel') ?? null;
    if (!panel) {
      panel = documentRef.createElement('article');
      panel.setAttribute('class', 'oath-modal__panel');
      dialogElement.appendChild(panel);
    }

    const title = documentRef.createElement('h2');
    title.setAttribute('class', 'oath-modal__title');
    title.textContent = OATH_MODAL_TITLE;
    panel.appendChild(title);

    // Región viva de anuncios (RNF-05): apertura, descarte y veredicto.
    liveRegion = documentRef.createElement('p');
    liveRegion.setAttribute('class', 'oath-modal__announce');
    liveRegion.setAttribute('aria-live', 'polite');
    panel.appendChild(liveRegion);

    // Texto del juramento: primera persona, nombrando al linaje (RF-02.2/02.3).
    const oathText = documentRef.createElement('blockquote');
    oathText.setAttribute('class', 'oath-modal__oath-text');
    panel.appendChild(oathText);

    // Advertencia de perpetuidad: VISIBLE, EXPLÍCITA E INELUDIBLE (RNF-03):
    // una alerta a cuerpo de modal, jamás letra menuda ni tooltip.
    const perpetuity = documentRef.createElement('p');
    perpetuity.setAttribute('class', 'oath-modal__perpetuity');
    perpetuity.setAttribute('role', 'alert');
    perpetuity.textContent = OATH_MODAL_PERPETUITY_LEGEND;
    panel.appendChild(perpetuity);

    // Segunda confirmación explícita (RF-02.3): «Sellar el juramento».
    const sealButton = documentRef.createElement('button');
    sealButton.setAttribute('type', 'button');
    sealButton.setAttribute('class', 'oath-modal__seal button button--primary');
    sealButton.textContent = OATH_MODAL_SEAL_LABEL;
    sealButton.addEventListener('click', confirmSeal);
    panel.appendChild(sealButton);

    // Descarte seguro: rechazar por ahora NO consume juramento (RNF-03).
    const dismissButton = documentRef.createElement('button');
    dismissButton.setAttribute('type', 'button');
    dismissButton.setAttribute('class', 'oath-modal__dismiss button button--secondary');
    dismissButton.textContent = OATH_MODAL_DISMISS_LABEL;
    dismissButton.addEventListener('click', dismiss);
    panel.appendChild(dismissButton);

    // Botón × del panel (degradación del shell): converge en dismiss().
    const closeButton = documentRef.createElement('button');
    closeButton.setAttribute('type', 'button');
    closeButton.setAttribute('class', 'oath-modal__close');
    closeButton.setAttribute('aria-label', 'Cerrar el modal del juramento');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', dismiss);
    panel.appendChild(closeButton);

    // Escape nativo → 'cancel'/'close' del dialog: descarte seguro.
    dialogElement.addEventListener('close', () => {
      if (isOpen) dismiss();
    });

    // Focus trap sobre el diálogo completo (RNF-03).
    dialogElement.addEventListener('keydown', handleKeydown);
  }

  /**
   * Abre el modal sobre un linaje expandido (RF-02.3). Idempotente: si ya
   * está abierto, solo actualiza el texto del linaje.
   *
   * @param {object} oathContext `{ lineageId, lineageName, originElement }`.
   */
  function open(oathContext = {}) {
    if (isDestroyed) return;
    // Un contexto null/undefined jamás debe romper la guardia (arneses, DOM real).
    const { lineageId, lineageName, originElement = null } = oathContext ?? {};
    const resolvedLineageId = typeof lineageId === 'string' && lineageId !== '' ? lineageId : null;
    if (resolvedLineageId === null) return; // Sin linaje no hay juramento.

    wirePanel();
    currentLineageId = resolvedLineageId;
    if (originElement) currentOriginElement = originElement;

    // El texto nombra SIEMPRE al linaje (RF-02.2): primera persona solemne.
    const oathTextElement = dialogElement.querySelector?.('.oath-modal__oath-text')
      ?? findDescendantByClass(dialogElement, 'oath-modal__oath-text');
    if (oathTextElement) oathTextElement.textContent = buildOathText(String(lineageName ?? resolvedLineageId));

    if (!isOpen) {
      isOpen = true;
      emit('oath:confirmation-opened', { lineageId: resolvedLineageId });
      announce(OATH_MODAL_ANNOUNCE_OPEN);
      dialogElement.showModal?.();
      // Foco inicial (RNF-05): el botón de sellado — la acción trascendente.
      findFirstButton(dialogElement)?.focus();
    }
  }

  /** Localizador por clase para DOM simulado (fallback de querySelector). */
  function findDescendantByClass(root, className, found = []) {
    for (const child of root.children ?? []) {
      if (child.classList?.contains?.(className)) found.push(child);
      findDescendantByClass(child, className, found);
    }
    return found[0] ?? null;
  }

  /** Baja limpia: cierra sin emitir descarte (apagado del orquestador). */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    if (isOpen) {
      isOpen = false;
      if (dialogElement.open) dialogElement.close('oath-destroyed');
    }
    dialogElement.removeEventListener?.('keydown', handleKeydown);
  }

  return {
    open,
    close: (options) => close({ dismissed: true, ...options }),
    isOpen: () => isOpen,
    destroy,
  };
}

/**
 * discardTomeEntryModalComponent.js — Modal solemne de retirada del tomo
 * (SPEC-11, Tarea 5.2).
 *
 * RF-02.4: al activar el gesto de retirada sobre una entrada del tomo,
 * presenta un modal que NOMBRA la obra, porta la leyenda canónica del
 * Anexo A —«Esta obra dejará tu tomo para siempre: medítalo antes de
 * firmar.»— y exige una confirmación explícita para consumar la
 * retirada. La retirada es PERPETUA: la confirmación se pide con la
 * solemnidad del rito, jamás como un gesto accidental.
 * RNF-04:  el descarte (botón, × o Escape) no muta NADA — el tomo queda
 *          íntegro y la vista operativa; el foco queda atrapado dentro
 *          mientras esté abierto y regresa al gesto de origen al cerrar;
 *          región viva de anuncios.
 * Caso límite 10: tras CONFIRMAR, la vista consume `onDiscard(spellId)`
 * y actualiza el conteo con el `total` de la respuesta — sin recargar
 * la página (plan §3.5, paginación viva).
 *
 * El modal JAMÁS llama a la API (Artículo II): la vista orquestadora
 * (Tarea 5.3) consume la confirmación y despacha el rito al backend.
 *
 * Eventos del plan §4 (CustomEvent sobre el diálogo anfitrión):
 *   - `tome:discard-opened`    { spellId }
 *   - `tome:discard-dismissed` {}
 *   - `tome:discard-confirmed` NO lo emite este componente: lo emite la
 *     vista cuando el santuario confirma la retirada.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): <dialog> nativo y DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO (AGENTS.md 6.1). Cero
 *     dependencias.
 *   - Artículo IV: leyendas solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/discardTomeEntryModalComponent
 */

/** Leyendas solemnes del modal (Anexo A del plan, RATIFICADO). */
export const DISCARD_TOME_MODAL_TITLE = 'Retirar del tomo';
export const DISCARD_TOME_MODAL_LEGEND =
  'Esta obra dejará tu tomo para siempre: medítalo antes de firmar.';
export const DISCARD_TOME_MODAL_CONFIRM_LABEL = 'Firmar la retirada';
export const DISCARD_TOME_MODAL_DISMISS_LABEL = 'Conservar la obra';
export const DISCARD_TOME_MODAL_ANNOUNCE_OPEN =
  'El modal de retirada está abierto. La retirada es perpetua.';
export const DISCARD_TOME_MODAL_ANNOUNCE_DISMISSED =
  'Retirada descartada. La obra permanece en tu tomo.';
export const DISCARD_TOME_MODAL_ANNOUNCE_CONFIRMED =
  'Retirada firmada. El santuario consuma el acto.';

/** Nombra SIEMPRE la obra en el cuerpo del modal (RF-02.4). */
export function buildDiscardIntro(spellName) {
  return `Vas a retirar «${String(spellName ?? 'la obra sin nombre')}» de tu tomo.`;
}

/**
 * Crea el modal solemne de retirada del tomo.
 *
 * @param {HTMLDialogElement} dialogElement Elemento `<dialog>` anfitrión.
 *        Puede venir del shell o forjarse en pruebas: el componente es
 *        agnóstico del origen.
 * @param {Object} componentOptions Opciones:
 *   - onDiscard(spellId): la vista consume la confirmación (invocará al
 *     cliente del tomo y aplicará la paginación viva; el modal NO llama
 *     a la API jamás).
 *   - documentRef / windowRef: inyecciones para pruebas.
 * @returns {Object} API: { open, close, isOpen, destroy }.
 */
export function createDiscardTomeEntryModalComponent(dialogElement, componentOptions = {}) {
  const {
    onDiscard,
    documentRef = globalThis.document,
    windowRef = globalThis.window,
  } = componentOptions;

  /** Guardia de cableado único y estado vivo. */
  let isWired = false;
  let isDestroyed = false;
  let isOpen = false;
  let currentSpellId = null;
  let currentOriginElement = null;

  /** Región viva (anuncios ARIA, RNF-04). */
  let liveRegion = null;

  /** Emite un evento del plan §4 sobre el diálogo (bus local del tomo). */
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
   * RNF-04 (focus trap, patrón de SPEC-02): con showModal() el navegador
   * atrapa el foco de forma nativa; este listener cubre el caso límite
   * de un Tab en el borde del diálogo y lo hace girar dentro.
   */
  function handleKeydown(keydownEvent) {
    if (keydownEvent.key === 'Escape') {
      // El navegador cierra el dialog con Escape (descarte seguro); el
      // listener 'close' centraliza el resto.
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

  /** Cierra como DESCARTE (botón/×/Escape): no muta nada (RF-02.4). */
  function dismiss() {
    if (!isOpen) return;
    close({ dismissed: true });
  }

  /** Confirmación explícita: notifica a la vista y cierra. */
  function confirmDiscard() {
    if (!isOpen || currentSpellId === null) return;
    const spellId = currentSpellId;
    announce(DISCARD_TOME_MODAL_ANNOUNCE_CONFIRMED);
    if (typeof onDiscard === 'function') onDiscard(spellId);
    close({ dismissed: false });
  }

  /**
   * Cierra el diálogo y devuelve el foco al gesto de origen (RNF-04).
   * @param {object} options `{ dismissed }` distingue descarte de confirmación.
   */
  function close({ dismissed = true } = {}) {
    if (!isOpen) return;
    isOpen = false;
    if (dismissed) {
      // El descarte no muta NADA (RF-02.4): solo avisa y libera.
      emit('tome:discard-dismissed', {});
      announce(DISCARD_TOME_MODAL_ANNOUNCE_DISMISSED);
    }
    if (dialogElement.open) {
      dialogElement.close(dismissed ? 'discard-dismissed' : 'discard-confirmed');
    }
    currentOriginElement?.focus?.();
    currentSpellId = null;
  }

  /** Anuncia en la región viva (RNF-04). */
  function announce(message) {
    if (liveRegion) liveRegion.textContent = message;
  }

  /** Localizador por clase para DOM simulado (fallback de querySelector). */
  function findDescendantByClass(root, className) {
    for (const child of root.children ?? []) {
      if (child.classList?.contains?.(className)) return child;
      const found = findDescendantByClass(child, className);
      if (found) return found;
    }
    return null;
  }

  /** Cablea el panel del modal UNA sola vez (re-aperturas idempotentes). */
  function wirePanel() {
    if (isWired) return;
    isWired = true;

    // El panel se forja si el shell aún no lo trae (arneses, pruebas).
    let panel = dialogElement.querySelector?.('.discard-tome-modal__panel') ?? null;
    const panelIsFresh = panel === null;
    if (panelIsFresh) {
      panel = documentRef.createElement('article');
      panel.setAttribute('class', 'discard-tome-modal__panel');
      dialogElement.appendChild(panel);
    }

    if (!panelIsFresh) {
      // Panel del shell: solo completar la región viva si falta.
      liveRegion = dialogElement.querySelector?.('.discard-tome-modal__announce')
        ?? findDescendantByClass(dialogElement, 'discard-tome-modal__announce');
      dialogElement.addEventListener('close', () => {
        if (isOpen) dismiss();
      });
      dialogElement.addEventListener('keydown', handleKeydown);
      return;
    }

    const title = documentRef.createElement('h2');
    title.setAttribute('class', 'discard-tome-modal__title');
    title.textContent = DISCARD_TOME_MODAL_TITLE;
    panel.appendChild(title);

    // Región viva de anuncios (RNF-04).
    liveRegion = documentRef.createElement('p');
    liveRegion.setAttribute('class', 'discard-tome-modal__announce');
    liveRegion.setAttribute('aria-live', 'polite');
    panel.appendChild(liveRegion);

    // La obra nombrada EN EL CUERPO (RF-02.4).
    const intro = documentRef.createElement('p');
    intro.setAttribute('class', 'discard-tome-modal__intro');
    panel.appendChild(intro);

    // La leyenda canónica del Anexo A: VISIBLE y a cuerpo de modal (RF-02.4).
    const legend = documentRef.createElement('p');
    legend.setAttribute('class', 'discard-tome-modal__legend');
    legend.setAttribute('role', 'alert');
    legend.textContent = DISCARD_TOME_MODAL_LEGEND;
    panel.appendChild(legend);

    // Confirmación explícita (RF-02.4): «Firmar la retirada».
    const confirmButton = documentRef.createElement('button');
    confirmButton.setAttribute('type', 'button');
    confirmButton.setAttribute('class', 'discard-tome-modal__confirm button button--danger');
    confirmButton.textContent = DISCARD_TOME_MODAL_CONFIRM_LABEL;
    confirmButton.addEventListener('click', confirmDiscard);
    panel.appendChild(confirmButton);

    // Descarte seguro: «Conservar la obra» NO muta nada (RNF-04).
    const dismissButton = documentRef.createElement('button');
    dismissButton.setAttribute('type', 'button');
    dismissButton.setAttribute('class', 'discard-tome-modal__dismiss button button--secondary');
    dismissButton.textContent = DISCARD_TOME_MODAL_DISMISS_LABEL;
    dismissButton.addEventListener('click', dismiss);
    panel.appendChild(dismissButton);

    // Botón × del panel (degradación del shell): converge en dismiss().
    const closeButton = documentRef.createElement('button');
    closeButton.setAttribute('type', 'button');
    closeButton.setAttribute('class', 'discard-tome-modal__close');
    closeButton.setAttribute('aria-label', 'Cerrar el modal de retirada');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', dismiss);
    panel.appendChild(closeButton);

    // Escape nativo → 'close' del dialog: descarte seguro.
    dialogElement.addEventListener('close', () => {
      if (isOpen) dismiss();
    });

    // Focus trap sobre el diálogo completo (RNF-04, patrón SPEC-02).
    dialogElement.addEventListener('keydown', handleKeydown);
  }

  /**
   * Abre el modal sobre el gesto de retirada de una entrada (RF-02.4).
   * Idempotente: si ya está abierto, solo actualiza la obra nombrada.
   *
   * @param {object} discardContext `{ spellId, spellName, originElement }`.
   */
  function open(discardContext = {}) {
    if (isDestroyed) return;
    const { spellId, spellName, originElement = null } = discardContext ?? {};
    const resolvedSpellId = typeof spellId === 'string' && spellId !== '' ? spellId : null;
    if (resolvedSpellId === null) return; // Sin obra no hay retirada.

    wirePanel();
    currentSpellId = resolvedSpellId;
    if (originElement) currentOriginElement = originElement;

    // La obra nombrada SIEMPRE (RF-02.4).
    const introElement = dialogElement.querySelector?.('.discard-tome-modal__intro')
      ?? findDescendantByClass(dialogElement, 'discard-tome-modal__intro');
    if (introElement) introElement.textContent = buildDiscardIntro(String(spellName ?? resolvedSpellId));

    if (!isOpen) {
      isOpen = true;
      emit('tome:discard-opened', { spellId: resolvedSpellId });
      announce(DISCARD_TOME_MODAL_ANNOUNCE_OPEN);
      dialogElement.showModal?.();
      // Foco inicial (RNF-04): el botón de conservación — la vía segura.
      findFirstButton(dialogElement)?.focus?.();
    }
  }

  /** Baja limpia: cierra sin emitir descarte (apagado del orquestador). */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    if (isOpen) {
      isOpen = false;
      if (dialogElement.open) dialogElement.close('discard-destroyed');
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

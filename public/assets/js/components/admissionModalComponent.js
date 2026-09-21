/**
 * admissionModalComponent.js — Modal solemne de confirmación del ingreso
 * inmediato (SPEC-10, Tarea 5.2).
 *
 * RF-02.1: al activar el gesto de ingreso sobre una casa de régimen `open`
 *          con vacantes, presenta un modal que NOMBRA la casa, expone la
 *          lealtad indivisible (un solo clan por mago, SPEC-07 RF-01.1) y
 *          advierte que marchar en el futuro activará la convalecencia de
 *          14 días — visible, explícita y EN EL CUERPO (jamás letra menuda
 *          ni tooltip, RF-02.1) — y exige una confirmación explícita para
 *          consumar el ingreso.
 * RNF-03:  el descarte (botón, × o Escape) no consume ingreso alguno y la
 *          ceremonia permanece operativa; el foco queda atrapado dentro
 *          mientras esté abierto y regresa a la tarjeta originadora al
 *          cerrar; región viva de anuncios.
 *
 * El modal JAMÁS llama a la API (Artículo II): la vista orquestadora
 * (Tarea 5.5) consume la confirmación y despacha el rito al backend.
 *
 * Eventos del plan §4 (CustomEvent sobre el diálogo anfitrión):
 *   - `vestibule:admission-opened`    { clanId, mode: 'join' }
 *   - `vestibule:admission-dismissed` {}
 *   - `vestibule:membership-created`  NO lo emite este componente: lo emite
 *     la vista cuando el santuario confirma la membresía.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): <dialog> nativo y DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO (AGENTS.md 6.1). Cero
 *     dependencias.
 *   - Artículo IV: leyendas solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/admissionModalComponent
 */

/** Leyendas solemnes del modal (voz ceremoniosa, castellano noble). */
export const ADMISSION_MODAL_TITLE = 'Solicitud de ingreso';
export const ADMISSION_MODAL_LOYALTY_LEGEND =
  'La lealtad mágica es indivisible: al entrar en esta casa renuncias a toda otra. Un solo estandarte por mago.';
export const ADMISSION_MODAL_CONVALESCENCE_LEGEND =
  'Si un día decides marchar, tu esencia entrará en convalecencia durante 14 días: no podrás alistarte en hermandad alguna hasta sanar.';
export const ADMISSION_MODAL_CONFIRM_LABEL = 'Confirmar el ingreso';
export const ADMISSION_MODAL_DISMISS_LABEL = 'Aún no';
export const ADMISSION_MODAL_ANNOUNCE_OPEN = 'El modal de ingreso está abierto. La lealtad es indivisible.';
export const ADMISSION_MODAL_ANNOUNCE_DISMISSED = 'Ingreso descartado. Nada fue consumado.';
export const ADMISSION_MODAL_ANNOUNCE_CONFIRMED = 'Ingreso confirmado. El santuario examinará tu petición.';

/** Nombra SIEMPRE a la casa en el cuerpo del modal (RF-02.1). */
export function buildAdmissionIntro(clanName) {
  return `Vas a pedir ingreso en «${String(clanName ?? 'la casa sin nombre')}».`;
}

/**
 * Crea el modal solemne del ingreso inmediato.
 *
 * @param {HTMLDialogElement} dialogElement Elemento `<dialog>` anfitrión.
 *        Puede venir del shell o forjarse en pruebas: el componente es
 *        agnóstico del origen.
 * @param {Object} componentOptions Opciones:
 *   - onConfirm(clanId): la vista consume la confirmación (invocará al
 *     cliente del Vestíbulo; el modal NO llama a la API jamás).
 *   - documentRef / windowRef: inyecciones para pruebas.
 * @returns {Object} API: { open, close, isOpen, destroy }.
 */
export function createAdmissionModalComponent(dialogElement, componentOptions = {}) {
  const {
    onConfirm,
    documentRef = globalThis.document,
    windowRef = globalThis.window,
  } = componentOptions;

  /** Guardia de cableado único y estado vivo. */
  let isWired = false;
  let isDestroyed = false;
  let isOpen = false;
  let currentClanId = null;
  let currentOriginElement = null;

  /** Región viva (anuncios ARIA, RNF-03). */
  let liveRegion = null;

  /** Emite un evento del plan §4 sobre el diálogo (bus local del Vestíbulo). */
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
   * RNF-03 (focus trap): con showModal() el navegador atrapa el foco de
   * forma nativa; este listener cubre el caso límite de un Tab en el borde
   * del diálogo (DOM simulado incluido) y lo devuelve al primer botón.
   */
  function handleKeydown(keydownEvent) {
    if (keydownEvent.key === 'Escape') {
      // El navegador cierra el dialog con Escape (descarte seguro); el
      // listener 'close' centraliza el resto. En DOM simulado, el descarte
      // explícito vía dismiss() cubre el caso.
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

  /** Cierra como DESCARTE (botón/×/Escape): no consume ingreso (RF-02.1). */
  function dismiss() {
    if (!isOpen) return;
    close({ dismissed: true });
  }

  /** Confirmación explícita: notifica a la vista y cierra. */
  function confirmAdmission() {
    if (!isOpen || currentClanId === null) return;
    const clanId = currentClanId;
    announce(ADMISSION_MODAL_ANNOUNCE_CONFIRMED);
    if (typeof onConfirm === 'function') onConfirm(clanId);
    close({ dismissed: false });
  }

  /**
   * Cierra el diálogo y devuelve el foco a la tarjeta originadora (RNF-03).
   * @param {object} options `{ dismissed }` distingue descarte de confirmación.
   */
  function close({ dismissed = true } = {}) {
    if (!isOpen) return;
    isOpen = false;
    if (dismissed) {
      // El descarte no muta NADA (RF-02.1): solo avisa y libera.
      emit('vestibule:admission-dismissed', {});
      announce(ADMISSION_MODAL_ANNOUNCE_DISMISSED);
    }
    if (dialogElement.open) {
      dialogElement.close(dismissed ? 'admission-dismissed' : 'admission-confirmed');
    }
    currentOriginElement?.focus?.();
    currentClanId = null;
  }

  /** Anuncia en la región viva (RNF-03). */
  function announce(message) {
    if (liveRegion) liveRegion.textContent = message;
  }

  /** Cablea el panel del modal UNA sola vez (re-aperturas idempotentes). */
  function wirePanel() {
    if (isWired) return;
    isWired = true;

    // El panel se forja si el shell aún no lo trae (arneses, pruebas).
    let panel = dialogElement.querySelector?.('.admission-modal__panel') ?? null;
    const panelIsFresh = panel === null;
    if (panelIsFresh) {
      panel = documentRef.createElement('article');
      panel.setAttribute('class', 'admission-modal__panel');
      dialogElement.appendChild(panel);
    }

    // Cableado de controles: SOLO sobre un panel recién forjado. Si el
    // panel ya portaba controles (instancia previa sobre el mismo dialog),
    // no se duplican — el panel es de UN solo modal a la vez (RNF-03).
    if (!panelIsFresh) {
      // La región viva puede faltar si el panel vino del shell sin ella.
      liveRegion = dialogElement.querySelector?.('.admission-modal__announce')
        ?? findDescendantByClass(dialogElement, 'admission-modal__announce');
      dialogElement.addEventListener('close', () => {
        if (isOpen) dismiss();
      });
      dialogElement.addEventListener('keydown', handleKeydown);
      return;
    }

    const title = documentRef.createElement('h2');
    title.setAttribute('class', 'admission-modal__title');
    title.textContent = ADMISSION_MODAL_TITLE;
    panel.appendChild(title);

    // Región viva de anuncios (RNF-03): apertura, descarte y confirmación.
    liveRegion = documentRef.createElement('p');
    liveRegion.setAttribute('class', 'admission-modal__announce');
    liveRegion.setAttribute('aria-live', 'polite');
    panel.appendChild(liveRegion);

    // La casa nombrada EN EL CUERPO (RF-02.1).
    const intro = documentRef.createElement('p');
    intro.setAttribute('class', 'admission-modal__intro');
    panel.appendChild(intro);

    // Lealtad indivisible: VISIBLE, EXPLÍCITA E INELUDIBLE (RF-02.1) —
    // una alerta a cuerpo de modal, jamás letra menuda ni tooltip.
    const loyalty = documentRef.createElement('p');
    loyalty.setAttribute('class', 'admission-modal__loyalty');
    loyalty.setAttribute('role', 'alert');
    loyalty.textContent = ADMISSION_MODAL_LOYALTY_LEGEND;
    panel.appendChild(loyalty);

    // Advertencia de convalecencia futura: también en el cuerpo (RF-02.1).
    const convalescence = documentRef.createElement('p');
    convalescence.setAttribute('class', 'admission-modal__convalescence');
    convalescence.setAttribute('role', 'alert');
    convalescence.textContent = ADMISSION_MODAL_CONVALESCENCE_LEGEND;
    panel.appendChild(convalescence);

    // Confirmación explícita (RF-02.1): «Confirmar el ingreso».
    const confirmButton = documentRef.createElement('button');
    confirmButton.setAttribute('type', 'button');
    confirmButton.setAttribute('class', 'admission-modal__confirm button button--primary');
    confirmButton.textContent = ADMISSION_MODAL_CONFIRM_LABEL;
    confirmButton.addEventListener('click', confirmAdmission);
    panel.appendChild(confirmButton);

    // Descarte seguro: «Aún no» NO consume ingreso (RNF-03).
    const dismissButton = documentRef.createElement('button');
    dismissButton.setAttribute('type', 'button');
    dismissButton.setAttribute('class', 'admission-modal__dismiss button button--secondary');
    dismissButton.textContent = ADMISSION_MODAL_DISMISS_LABEL;
    dismissButton.addEventListener('click', dismiss);
    panel.appendChild(dismissButton);

    // Botón × del panel (degradación del shell): converge en dismiss().
    const closeButton = documentRef.createElement('button');
    closeButton.setAttribute('type', 'button');
    closeButton.setAttribute('class', 'admission-modal__close');
    closeButton.setAttribute('aria-label', 'Cerrar el modal de ingreso');
    closeButton.textContent = '×';
    closeButton.addEventListener('click', dismiss);
    panel.appendChild(closeButton);

    // Escape nativo → 'close' del dialog: descarte seguro.
    dialogElement.addEventListener('close', () => {
      if (isOpen) dismiss();
    });

    // Focus trap sobre el diálogo completo (RNF-03).
    dialogElement.addEventListener('keydown', handleKeydown);
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

  /**
   * Abre el modal sobre el gesto de ingreso de una casa (RF-02.1).
   * Idempotente: si ya está abierto, solo actualiza la casa nombrada.
   *
   * @param {object} admissionContext `{ clanId, clanName, originElement }`.
   */
  function open(admissionContext = {}) {
    if (isDestroyed) return;
    // Un contexto null/undefined jamás debe romper la guardia (arneses, DOM real).
    const { clanId, clanName, originElement = null } = admissionContext ?? {};
    const resolvedClanId = typeof clanId === 'string' && clanId !== '' ? clanId : null;
    if (resolvedClanId === null) return; // Sin casa no hay ingreso.

    wirePanel();
    currentClanId = resolvedClanId;
    if (originElement) currentOriginElement = originElement;

    // La casa nombrada SIEMPRE (RF-02.1).
    const introElement = dialogElement.querySelector?.('.admission-modal__intro')
      ?? findDescendantByClass(dialogElement, 'admission-modal__intro');
    if (introElement) introElement.textContent = buildAdmissionIntro(String(clanName ?? resolvedClanId));

    if (!isOpen) {
      isOpen = true;
      emit('vestibule:admission-opened', { clanId: resolvedClanId, mode: 'join' });
      announce(ADMISSION_MODAL_ANNOUNCE_OPEN);
      dialogElement.showModal?.();
      // Foco inicial (RNF-03): el botón de confirmación — la acción trascendente.
      findFirstButton(dialogElement)?.focus();
    }
  }

  /** Baja limpia: cierra sin emitir descarte (apagado del orquestador). */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    if (isOpen) {
      isOpen = false;
      if (dialogElement.open) dialogElement.close('admission-destroyed');
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

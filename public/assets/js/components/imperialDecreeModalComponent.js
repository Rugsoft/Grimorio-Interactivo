/**
 * imperialDecreeModalComponent.js — Modal solemne del Edicto Imperial.
 *
 * Tarea 6.1 (TASKS-08). Diálogo litúrgico para los TRES decretos soberanos
 * del Administrador Supremo (RF-04.1 validación, RF-04.3 rescate, RF-04.4
 * destierro): recoge el gesto que el orquestador anuncia, exige el Edicto
 * Imperial de justificación (≥ 20 caracteres en castellano, RF-04.5) con
 * validador dinámico decreciente, y ofrece la orden de deducción como
 * DECISIÓN EXPLÍCITA en el destierro.
 *
 * Contrato de datos: el modal solo REDACTA. El umbral canónico (veinte
 * caracteres) llega por options.minDecreeLength o por options.canon
 * (`SovereignAdminService::sovereignCanon()`, reservando 20); el veredicto
 * final lo dicta SIEMPRE el backend (422 IMPERIAL_DECREE_TOO_SHORT), y este
 * modal es cortesía de la interfaz, jamás la ley (Art. II).
 *
 * Decisiones que costará reconstruir:
 *   1. El modal cubre los TRES decretos con una sola liturgia, porque el
 *      contrato es el mismo: objetivo en el cuerpo, edicto de veinte y —en el
 *      destierro— la orden de deducción. Lo que muda es el rótulo y el gesto
 *      (`decreeType`: sovereignValidation | rescueToExperimental |
 *      rescueToValidated | revokeAndArchive), y con el rótulo la leyenda.
 *   2. La orden de deducción es una LEY, no una preferencia (plan 3.3): en el
 *      destierro viaja una casilla EXPLÍCITA, desmarcada de nacimiento —que
 *      nadie deba gloria por omisión—, y su estado solo viaja cuando el
 *      decreto es un destierro. En validación y rescate no existe la casilla.
 *   3. El destino del rescate se elige como DECISIÓN DECLARADA: dos opciones
 *      canónicas (`experimental` reinicia limpio a 0/3; `validated` consagra
 *      y acredita la gloria negada), sin destino adivinado.
 *   4. El veredicto viaja por el bus desacoplado (`moderation:decree-submitted`,
 *      plan 4.1 `moderation:decreed`) y por el veredicto de la promesa; el
 *      modal JAMÁS llama a la API por su cuenta.
 *   5. El edicto se mide RECORTADO, como lo medirá el backend (`trim()`), y
 *      el envío nace inhabilitado hasta alcanzar la solemnidad.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; textContent puro y `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1); cero librerías ni plantillas.
 *   - Artículo II: el modal jamás dicta el umbral: lo recibe de su fuente.
 *   - Artículo III: la deducción solo viaja como decisión explícita del
 *     custodio (el silencio jamás decide sobre el patrimonio de una casa).
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Accesibilidad: `alertdialog` modal con foco preso en el edicto, Escape
 *     desiste, y el contador anunciado con cortesía.
 */

/** Umbral de reserva del edicto (solo si nadie aporta canon ni minDecreeLength). */
export const IMPERIAL_DECREE_MODAL_FALLBACK_MIN_LENGTH = 20;

/** Destinos canónicos del rescate (espejo de `CANONICAL_RESCUE_TARGETS`). */
export const IMPERIAL_DECREE_MODAL_RESCUE_TARGETS = Object.freeze(['experimental', 'validated']);

/** Rótulos ceremoniales de cada decreto (RNF-03). */
export const IMPERIAL_DECREE_MODAL_LEGENDS = Object.freeze({
  sovereignValidation: {
    title: 'Firma Soberana Instantánea',
    submitLabel: 'Consagrar de oficio',
    notice: 'La obra será consagrada en el Gran Tomo por decreto soberano, con tu Edicto Imperial inscrito en la Bitácora pública (RF-04.5).',
  },
  rescueToExperimental: {
    title: 'Rescate de Obra Vetada',
    submitLabel: 'Restituir a deliberación',
    notice: 'La obra vetada volverá a la Torre con la deliberación reiniciada en cero firmas (0/3), para un juicio fresco e imparcial (RF-04.3).',
  },
  rescueToValidated: {
    title: 'Rescate con Consagración Directa',
    submitLabel: 'Rescatar y consagrar',
    notice: 'La obra vetada será consagrada directamente en el Gran Tomo y se acreditará la gloria que el veto había negado (RF-04.3).',
  },
  revokeAndArchive: {
    title: 'Revocación y Archivo Póstumo',
    submitLabel: 'Desterrar del Gran Tomo',
    notice: 'La obra consagrada caerá del canon con tus avales anulados; tu Edicto Imperial quedará inscrito en la Bitácora pública (RF-04.4, RF-04.5).',
  },
  decreeLabel: 'Edicto Imperial de justificación (mínimo veinte caracteres)',
  deductionLabel: 'Ordenar la deducción retroactiva de los PDA acreditados al linaje originario',
  pendingLegend: 'Faltan {remaining} caracteres para la solemnidad del edicto.',
  readyLegend: 'El edicto alcanza la solemnidad requerida: el decreto puede emitirse.',
});

/** Leyenda para un tipo de decreto ajeno al canon (backend antiguo). */
export const IMPERIAL_DECREE_MODAL_UNKNOWN_TYPE_LEGEND = 'Decreto del Cónclave Supremo';

/**
 * Crea el modal del Edicto Imperial.
 *
 * @param {HTMLElement} mountRoot Contenedor donde nacerá el diálogo.
 * @param {Object} options
 * @param {number} [options.minDecreeLength] Umbral canónico del edicto;
 *        por defecto, el que traiga `options.canon` o la reserva de 20.
 * @param {object} [options.canon] Canon soberano servido por el backend
 *        (`sovereignCanon()`); se lee `minImperialDecreeLength` si
 *        `minDecreeLength` no viaja.
 * @param {string} [options.spellId] Obra sobre la que se dicta (viaja en los
 *        anuncios del veredicto).
 * @param {(decree: Object) => void} [options.onSubmitted] Anuncio del
 *        veredicto al orquestador (que cursará el decreto por moderationClient).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @returns {Object} API: { open, destroy }.
 */
export function createImperialDecreeModalComponent(mountRoot, options = {}) {
  const {
    minDecreeLength,
    canon = null,
    spellId = '',
    onSubmitted = null,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  const servedThreshold = Number(canon?.minImperialDecreeLength);
  const threshold = Number.isInteger(Number(minDecreeLength))
    ? Number(minDecreeLength)
    : (Number.isInteger(servedThreshold) ? servedThreshold : IMPERIAL_DECREE_MODAL_FALLBACK_MIN_LENGTH);

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

  /** Leyendas del decreto pedido, con reserva para un tipo ajeno al canon. */
  function legendsFor(decreeType) {
    return IMPERIAL_DECREE_MODAL_LEGENDS[decreeType]
      ?? {
        title: IMPERIAL_DECREE_MODAL_UNKNOWN_TYPE_LEGEND,
        submitLabel: 'Emitir el decreto',
        notice: 'Tu Edicto Imperial quedará inscrito en la Bitácora pública del santuario (RF-04.5).',
      };
  }

  /**
   * Abre el modal y aguarda el veredicto del Administrador Supremo.
   *
   * @param {Object} [request] {decreeType, spellId}.
   *   - `decreeType`: 'sovereignValidation' | 'rescueToExperimental' |
   *     'rescueToValidated' | 'revokeAndArchive'.
   * @returns {Promise<{submitted: boolean, decree: Object|null}>}
   *   En el veredicto afirmativo, `decree` porta {decreeType, spellId,
   *   imperialDecreeText, targetStatus?, deductPoints?}.
   */
  function open(request = {}) {
    if (isDestroyed) {
      return Promise.resolve({ submitted: false, decree: null });
    }
    if (overlayNode !== null) {
      // Un solo decreto a la vez: el diálogo vigente manda.
      return Promise.resolve({ submitted: false, decree: null });
    }

    const decreeType = typeof request?.decreeType === 'string' && request.decreeType !== ''
      ? request.decreeType
      : 'sovereignValidation';
    const decreeSpellId = typeof request?.spellId === 'string' && request.spellId !== ''
      ? request.spellId
      : spellId;
    const legends = legendsFor(decreeType);
    const isArchive = decreeType === 'revokeAndArchive';
    const isRescue = decreeType === 'rescueToExperimental' || decreeType === 'rescueToValidated';

    return new Promise((resolve) => {
      const overlay = elementFactory('div');
      overlay.className = 'imperial-decree-modal';
      overlay.setAttribute('role', 'alertdialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'imperialDecreeModalTitle');
      overlay.setAttribute('aria-describedby', 'imperialDecreeModalNotice');
      overlay.setAttribute('data-spell-id', decreeSpellId);
      overlay.setAttribute('data-decree-type', decreeType);
      mountRoot.appendChild(overlay);
      overlayNode = track(overlay);

      const panel = elementFactory('section');
      panel.className = 'imperial-decree-modal__panel';
      overlay.appendChild(panel);
      track(panel);

      const title = appendTextElement(panel, 'h3', 'imperial-decree-modal__title', legends.title);
      title.setAttribute('id', 'imperialDecreeModalTitle');

      const notice = appendTextElement(panel, 'p', 'imperial-decree-modal__notice', legends.notice);
      notice.setAttribute('id', 'imperialDecreeModalNotice');

      // El destino del rescate: dos opciones canónicas, decisión DECLARADA.
      let targetRadios = [];
      if (isRescue) {
        const targetGroup = track(elementFactory('fieldset'));
        targetGroup.className = 'imperial-decree-modal__target-group';
        const targetLegend = appendTextElement(targetGroup, 'legend', 'imperial-decree-modal__target-legend', 'Destino canónico del rescate');
        targetLegend.setAttribute('id', 'imperialDecreeModalTargetLegend');
        targetGroup.setAttribute('aria-labelledby', 'imperialDecreeModalTargetLegend');
        panel.appendChild(targetGroup);

        targetRadios = IMPERIAL_DECREE_MODAL_RESCUE_TARGETS.map((target, index) => {
          const optionLabel = appendTextElement(targetGroup, 'label', 'imperial-decree-modal__target-option', '');
          const radio = track(elementFactory('input'));
          radio.setAttribute('type', 'radio');
          radio.setAttribute('name', 'imperialDecreeModalTarget');
          radio.setAttribute('value', target);
          radio.setAttribute('data-role', 'rescue-target');
          // El rescate a deliberación limpia nace marcado: es el destino
          // ordinario del rescate (el directo al Tomo es la excepción).
          if (index === 0) {
            radio.checked = true;
          }
          optionLabel.appendChild(radio);
          const optionText = optionLabel.appendChild(elementFactory('span'));
          optionText.textContent = target === 'experimental'
            ? 'Restituir a deliberación (0/3 firmas)'
            : 'Consagrar directamente en el Gran Tomo';
          return radio;
        });
        targetGroup.appendChild(targetLegend);
      }

      // El campo del edicto: textarea nativo con su etiqueta asociada.
      const decreeLabel = appendTextElement(panel, 'label', 'imperial-decree-modal__decree-label', IMPERIAL_DECREE_MODAL_LEGENDS.decreeLabel);
      decreeLabel.setAttribute('for', 'imperialDecreeModalText');
      const decreeField = track(elementFactory('textarea'));
      decreeField.className = 'imperial-decree-modal__decree';
      decreeField.setAttribute('id', 'imperialDecreeModalText');
      decreeField.setAttribute('data-role', 'imperial-decree-text');
      decreeField.setAttribute('rows', '4');
      panel.appendChild(decreeField);

      // La orden de deducción: SOLO en el destierro, EXPLÍCITA y desmarcada
      // de nacimiento —que nadie deba gloria por omisión (Art. III.3)—.
      let deductionCheckbox = null;
      if (isArchive) {
        const deductionLabel = appendTextElement(panel, 'label', 'imperial-decree-modal__deduction-label', '');
        deductionCheckbox = track(elementFactory('input'));
        deductionCheckbox.setAttribute('type', 'checkbox');
        deductionCheckbox.setAttribute('data-role', 'deduct-points');
        deductionCheckbox.checked = false;
        deductionLabel.appendChild(deductionCheckbox);
        const deductionText = deductionLabel.appendChild(elementFactory('span'));
        deductionText.textContent = IMPERIAL_DECREE_MODAL_LEGENDS.deductionLabel;
      }

      // El contador decreciente (criterio): región viva de cortesía.
      const counter = appendTextElement(panel, 'p', 'imperial-decree-modal__counter', '');
      counter.setAttribute('role', 'status');
      counter.setAttribute('aria-live', 'polite');
      counter.setAttribute('data-role', 'decree-counter');

      const actions = track(elementFactory('div'));
      actions.className = 'imperial-decree-modal__actions';
      panel.appendChild(actions);

      const cancelButton = track(elementFactory('button'));
      cancelButton.type = 'button';
      cancelButton.className = 'imperial-decree-modal__cancel button button--secondary';
      cancelButton.textContent = 'Desistir del decreto';
      actions.appendChild(cancelButton);

      const submitButton = track(elementFactory('button'));
      submitButton.type = 'button';
      submitButton.className = 'imperial-decree-modal__submit button button--primary';
      submitButton.setAttribute('data-role', 'decree-submit');
      submitButton.textContent = legends.submitLabel;
      actions.appendChild(submitButton);

      /**
       * Valida el edicto y refresca el contador y el botón.
       *
       * El umbral se mide sobre el texto RECORTADO: es como el backend lo
       * medirá (`trim()`), y cuarenta espacios jamás son un edicto.
       */
      function refreshCounter() {
        const trimmedLength = String(decreeField.value ?? '').trim().length;
        const remaining = threshold - trimmedLength;

        if (remaining > 0) {
          submitButton.disabled = true;
          submitButton.setAttribute('aria-disabled', 'true');
          counter.textContent = IMPERIAL_DECREE_MODAL_LEGENDS.pendingLegend.replace('{remaining}', String(remaining));
        } else {
          submitButton.disabled = false;
          submitButton.removeAttribute('aria-disabled');
          counter.textContent = IMPERIAL_DECREE_MODAL_LEGENDS.readyLegend;
        }
      }

      /**Cierra el diálogo resolviendo el veredicto y devolviendo el foco. */
      const settle = (verdict) => {
        let decree = null;
        if (verdict === true) {
          decree = {
            decreeType,
            spellId: decreeSpellId,
            imperialDecreeText: String(decreeField.value ?? '').trim(),
          };
          // El destino del rescate viaja como el campo `targetStatus` que el
          // Endpoint 10 exige en el cuerpo.
          if (isRescue) {
            decree.targetStatus = targetRadios.find((radio) => radio.checked)?.getAttribute('value')
              ?? IMPERIAL_DECREE_MODAL_RESCUE_TARGETS[0];
          }
          // La orden de deducción viaja SOLO en el destierro y SOLO como
          // booleano explícito (plan 3.3: jamás se coacciona en silencio).
          if (isArchive) {
            decree.deductPoints = deductionCheckbox?.checked === true;
          }

          emitBusEvent('moderation:decree-submitted', { ...decree });
          if (typeof onSubmitted === 'function') {
            onSubmitted({ ...decree });
          }
        }

        overlay.remove?.();
        overlayNode = null;
        overlay.removeEventListener?.('keydown', onKeydown);
        decreeField.removeEventListener?.('input', refreshCounter);
        submitButton.removeEventListener?.('click', onConfirm);
        cancelButton.removeEventListener?.('click', onCancel);

        resolve({ submitted: verdict === true, decree });
      };

      function onConfirm() {
        // Doble guarda: el veredicto final lo dictará el backend, pero el
        // modal jamás entrega un edicto por debajo del umbral que anuncia.
        const trimmedLength = String(decreeField.value ?? '').trim().length;
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

      decreeField.addEventListener('input', refreshCounter);
      submitButton.addEventListener('click', onConfirm);
      cancelButton.addEventListener('click', onCancel);
      overlay.addEventListener('keydown', onKeydown);

      // Estado inicial: el envío nace INHABILITADO y el foco nace en el
      // edicto, porque el gesto que sigue es redactar la justificación.
      refreshCounter();
      decreeField.focus?.();
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

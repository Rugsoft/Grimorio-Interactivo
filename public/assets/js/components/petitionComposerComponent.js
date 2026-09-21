/**
 * petitionComposerComponent.js — Molde de la petición formal del Vestíbulo
 * (SPEC-10, Tarea 5.3).
 *
 * RF-03.1: al activar el gesto de postulación sobre una casa de régimen
 *          `byApplication`, ofrece la redacción de una petición formal en
 *          noble castellano — una motivación breve dirigida al Patriarca,
 *          acotada al molde de 20 a 500 caracteres con contador vivo
 *          (0/500) y leyenda solemne al exceder el molde (Anexo A 6) — y
 *          remite la petición al dictamen.
 * RF-03.6: el sistema NO juzga el tono del texto: la única regla mecánica
 *          es el MOLDE (20–500, medido tras recortar espacios de los
 *          bordes); el dictamen humano del Patriarca es la revisión.
 * RNF-03:  etiqueta asociada, contador y leyenda en región viva; foco
 *          visible heredado del kit de controles (SPEC-02 RF-08).
 *
 * La remisión delega en onSubmit(clanId, motivation) — el componente JAMÁS
 * llama a la API (Artículo II): la vista orquestadora (Tarea 5.5) despacha
 * la petición al santuario.
 *
 * Evento del plan §4 (CustomEvent sobre la raíz del componente):
 *   - `vestibule:petition-composed` { clanId, length } — cada edición.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `textContent` puro, innerHTML
 *     PROHIBIDO (AGENTS.md 6.1). El `textarea` consume el kit de controles
 *     (`.controls-textarea`, SPEC-02 RF-08.1): jamás estilos divergentes.
 *   - Artículo IV: leyendas solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/petitionComposerComponent
 */

/** Bordes del molde canónico de la motivación (RF-03.1). */
export const PETITION_MOTIVATION_MIN = 20;
export const PETITION_MOTIVATION_MAX = 500;

/** Leyendas solemnes del molde (Anexo A 6 del plan, RATIFICADO). */
export const PETITION_LEGEND_TOO_LONG = 'Tu petición desborda el pergamino: el Patriarca lee mejor lo breve.';
export const PETITION_LEGEND_TOO_SHORT = 'Apenas es un susurro: dale cuerpo a tu vocación.';
export const PETITION_LEGEND_READY = 'Tu vocación tiene cuerpo: el Patriarca aguarda tu palabra.';

/** Rótulos del compositor (voz solemne y activa). */
export const PETITION_TITLE_LABEL = 'Petición formal al Patriarca';
export const PETITION_SUBMIT_LABEL = 'Remitir la petición';
export const PETITION_CANCEL_LABEL = 'Descartar';

/** Identidad del molde ante el guard de cobertura CSS (SPEC-02 RF-08.6). */
const CLASSES = Object.freeze({
  root: 'petition-composer',
  title: 'petition-composer__title',
  label: 'petition-composer__label',
  textarea: 'petition-composer__textarea controls-textarea',
  footer: 'petition-composer__footer',
  counter: 'petition-composer__counter',
  counterOverflow: 'petition-composer__counter--overflow',
  legend: 'petition-composer__legend',
  legendVisible: 'petition-composer__legend--visible',
  actions: 'petition-composer__actions',
  submit: 'petition-composer__submit button button--primary',
  cancel: 'petition-composer__cancel button button--secondary',
});

/**
 * Crea el molde de la petición formal.
 *
 * @param {object} componentOptions Opciones:
 *   - clanId: identificador de la casa cortejada (obligatorio para remitir).
 *   - clanName: nombre canónico (para la etiqueta y la accesibilidad).
 *   - onSubmit(clanId, motivation): remisión — la vista llama al cliente del
 *     Vestíbulo; el componente jamás toca la red.
 *   - onCancel(): descarte del molde (sin mutación alguna).
 *   - elementFactory / documentRef: inyecciones para arneses.
 *   - windowRef: inyección del bus de eventos (CustomEvent).
 * @returns {Object} API: { element, getValue, setValue, isValid,
 *          refreshCounter, focus, destroy }.
 */
export function createPetitionComposerComponent(componentOptions = {}) {
  const {
    clanId = null,
    clanName = '',
    onSubmit,
    onCancel,
    documentRef = globalThis.document,
    windowRef = globalThis.window,
  } = componentOptions;

  // La fábrica por defecto deriva del documentRef INYECTADO (arneses sin
  // navegador): globalThis.document no existe bajo Node (RNF de arneses).
  const elementFactory = componentOptions.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));

  const houseLabel = String(clanName || clanId || 'la casa');

  // ---------------------------------------------------------------
  // Raíz y título.
  // ---------------------------------------------------------------
  const rootElement = elementFactory('section');
  rootElement.className = CLASSES.root;
  rootElement.setAttribute('data-clan-id', String(clanId ?? ''));

  const title = elementFactory('h3');
  title.className = CLASSES.title;
  title.textContent = PETITION_TITLE_LABEL;
  rootElement.appendChild(title);

  // ---------------------------------------------------------------
  // Textarea del KIT de controles (SPEC-02 RF-08.1) + etiqueta asociada.
  // ---------------------------------------------------------------
  const label = elementFactory('label');
  label.className = CLASSES.label;
  label.textContent = `Tu motivación ante «${houseLabel}»`;
  rootElement.appendChild(label);

  const textarea = elementFactory('textarea');
  textarea.className = CLASSES.textarea;
  textarea.setAttribute('id', 'petitionComposerTextarea');
  textarea.setAttribute('maxlength', String(PETITION_MOTIVATION_MAX));
  textarea.setAttribute('rows', '4');
  textarea.setAttribute('placeholder', 'Explica al Patriarca, en noble castellano, por qué ruegas entrar en su casa…');
  // Sin maxlength duro que silencie el exceso: el molde se anuncia con la
  // leyenda solemne (RF-03.1) y el contador; el maxlength solo recorta el
  // desbordamiento imposible (500 es el tope del backend).
  textarea.removeAttribute('maxlength');
  label.setAttribute('for', 'petitionComposerTextarea');
  rootElement.appendChild(textarea);

  // ---------------------------------------------------------------
  // Pie: contador vivo 0/500 + leyenda solemne del molde (región viva).
  // ---------------------------------------------------------------
  const footer = elementFactory('div');
  footer.className = CLASSES.footer;

  const counter = elementFactory('p');
  counter.className = CLASSES.counter;
  footer.appendChild(counter);

  const legend = elementFactory('p');
  legend.className = CLASSES.legend;
  legend.setAttribute('role', 'status');
  legend.setAttribute('aria-live', 'polite');
  footer.appendChild(legend);

  rootElement.appendChild(footer);

  // ---------------------------------------------------------------
  // Acciones: remitir (exige molde) y descartar (sin mutación).
  // ---------------------------------------------------------------
  const actions = elementFactory('div');
  actions.className = CLASSES.actions;

  const submitButton = elementFactory('button');
  submitButton.type = 'button';
  submitButton.className = CLASSES.submit;
  submitButton.textContent = PETITION_SUBMIT_LABEL;
  submitButton.addEventListener('click', handleSubmit);
  actions.appendChild(submitButton);

  const cancelButton = elementFactory('button');
  cancelButton.type = 'button';
  cancelButton.className = CLASSES.cancel;
  cancelButton.textContent = PETITION_CANCEL_LABEL;
  cancelButton.addEventListener('click', () => {
    if (typeof onCancel === 'function') onCancel();
  });
  actions.appendChild(cancelButton);

  rootElement.appendChild(actions);

  // ---------------------------------------------------------------
  // Lógica del molde (RF-03.1): medir TRAS recortar bordes; el texto
  // interior (con sus saltos y espacios dobles) viaja íntegro — ninguna
  // regla de estilo filtra nada (RF-03.6).
  // ---------------------------------------------------------------
  function motivationLength() {
    return String(textarea.value ?? '').trim().length;
  }

  function legendForLength(length) {
    if (length > PETITION_MOTIVATION_MAX) return PETITION_LEGEND_TOO_LONG;
    if (length < PETITION_MOTIVATION_MIN) return PETITION_LEGEND_TOO_SHORT;
    return PETITION_LEGEND_READY;
  }

  /** Refresca contador y leyenda (idempotente; llamado en cada edición). */
  function refreshCounter() {
    const length = motivationLength();
    counter.textContent = `${String(textarea.value ?? '').length}/${PETITION_MOTIVATION_MAX}`;

    const legendText = legendForLength(length);
    legend.textContent = legendText;
    const isOverflow = length > PETITION_MOTIVATION_MAX;
    if (isOverflow) {
      counter.classList.add(CLASSES.counterOverflow);
      legend.classList.add(CLASSES.legendVisible);
    } else if (length < PETITION_MOTIVATION_MIN) {
      counter.classList.remove(CLASSES.counterOverflow);
      legend.classList.add(CLASSES.legendVisible);
    } else {
      counter.classList.remove(CLASSES.counterOverflow);
      legend.classList.add(CLASSES.legendVisible);
    }

    // El botón de remisión exige el molde completo (20–500): disabled nativo.
    submitButton.disabled = !isMotivationValid();
    submitButton.setAttribute('aria-disabled', submitButton.disabled ? 'true' : 'false');

    // Evento del plan §4: cada edición notifica la longitud a la vista.
    const CustomEventCtor = windowRef?.CustomEvent ?? globalThis.CustomEvent;
    if (typeof CustomEventCtor === 'function') {
      rootElement.dispatchEvent(new CustomEventCtor('vestibule:petition-composed', {
        detail: { clanId, length },
        bubbles: true,
      }));
    }
  }

  /** El molde canónico: 20–500 caracteres tras recortar bordes (RF-03.1). */
  function isMotivationValid() {
    const length = motivationLength();
    return length >= PETITION_MOTIVATION_MIN && length <= PETITION_MOTIVATION_MAX;
  }

  /** Remisión: delega en onSubmit con el texto ÍNTEGRO (jamás filtrado). */
  function handleSubmit() {
    if (!isMotivationValid()) {
      refreshCounter();
      return;
    }
    if (typeof onSubmit === 'function') {
      onSubmit(clanId, String(textarea.value ?? '').trim());
    }
  }

  textarea.addEventListener('input', refreshCounter);
  refreshCounter();

  return {
    element: rootElement,
    getValue: () => String(textarea.value ?? ''),
    setValue: (nextValue) => {
      textarea.value = String(nextValue ?? '');
      refreshCounter();
    },
    isValid: isMotivationValid,
    refreshCounter,
    focus: () => textarea.focus?.(),
    destroy: () => {
      if (typeof rootElement.remove === 'function') rootElement.remove();
    },
  };
}

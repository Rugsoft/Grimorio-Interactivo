/**
 * clanFoundationModalComponent.js — El Umbral de la Fundación
 * (SPEC-07b, Tarea 1; enmienda de superficie de SPEC-07 RF-01.2).
 *
 * RF-10.1: el gesto «Fundar una hermandad propia» abre este `<dialog>`
 *          solemne. La decisión de QUIÉN ve el gesto habilitado vive en la
 *          vista orquestadora (RF-10.1); el componente es agnóstico del
 *          actor y exige su veredicto al santuario (RNF-04).
 * RF-10.2: TRES sellos + régimen — Nombre Canónico (4–50, contador
 *          visible), Lema Heráldico (obligatorio), Linaje Rector (los 8
 *          canónicos del Salón, con SOLO el jurado seleccionable) y el
 *          régimen de admisión. El blasón NO se pregunta (RF-10.8): el
 *          sello determinista neutro lo cumple y el Patriarca lo edita
 *          después desde su gobierno.
 * RF-10.5: los vetos del contrato (ClanGovernanceException) se traducen a
 *          leyendas temáticas en región viva; el modal permanece abierto y
 *          conserva lo escrito. Códigos no catalogados degradan a una
 *          leyenda genérica de fallo arcano (Artículo IV).
 * RF-10.7: el descarte (botón, × o Escape) conserva el borrador y no
 *          consume acción alguna; el foco queda atrapado mientras esté
 *          abierto (showModal nativo + refuerzo) y regresa al gesto
 *          originador al cerrar.
 *
 * El modal JAMÁS llama a la API (patrón de admissionModalComponent): la
 * vista orquestadora consume la confirmación y despacha el rito al
 * backend vía clanClient.foundClan. El veredicto regresa por
 * reportError()/closeAfterSuccess() desde la vista.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): `<dialog>` nativo y DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO (AGENTS.md 6.1). Cero
 *     dependencias.
 *   - Artículo IV: leyendas solemnes en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * @module components/clanFoundationModalComponent
 */

/* Leyendas solemnes del Umbral (voz ceremoniosa, castellano noble). */
export const FOUNDATION_MODAL_TITLE = 'Alzar una hermandad propia';
export const FOUNDATION_MODAL_HINT =
  'Tres sellos y un régimen forjan una casa: el estandarte brota de tu propia sangre arcana.';
export const FOUNDATION_NAME_LABEL = 'Nombre Canónico';
export const FOUNDATION_NAME_HINT = 'De 4 a 50 caracteres, único en el santuario.';
export const FOUNDATION_MOTTO_LABEL = 'Lema Heráldico';
export const FOUNDATION_MOTTO_HINT = 'El corazón de la casa, en noble castellano.';
export const FOUNDATION_LINEAGE_LABEL = 'Linaje Rector';
export const FOUNDATION_LINEAGE_OWN_HINT = 'Tu juramento brota sobre este linaje.';
export const FOUNDATION_LINEAGE_FOREIGN_HINT =
  'Tu sangre arcana jura otro linaje: el estandarte brota de tu propia casa.';
export const FOUNDATION_ADMISSION_LABEL = 'Régimen de admisión';
export const FOUNDATION_ADMISSION_OPEN_LABEL = 'Abierto';
export const FOUNDATION_ADMISSION_OPEN_HINT =
  'Cualquier mago apto de tu linaje entra de inmediato mientras haya vacantes (plenitud de treinta).';
export const FOUNDATION_ADMISSION_BY_APPLICATION_LABEL = 'Bajo Petición';
export const FOUNDATION_ADMISSION_BY_APPLICATION_HINT =
  'Los postulantes de tu linaje remiten una petición formal; tú dirimes cada veredicto.';
export const FOUNDATION_SUBMIT_LABEL = 'Alzar el estandarte';
export const FOUNDATION_SUBMIT_IN_FLIGHT_LABEL = 'Forjando la casa…';
export const FOUNDATION_DISMISS_LABEL = 'Aún no';
export const FOUNDATION_ANNOUNCE_OPEN =
  'El Umbral de la Fundación está abierto: tres sellos y un régimen forjan una casa.';
export const FOUNDATION_ANNOUNCE_DISMISSED = 'El Umbral se cerró: nada fue consumado.';
export const FOUNDATION_FALLBACK_ERROR =
  'El santuario de hermandades ha respondido con un presagio indescifrable.';

/* Los Ocho Linajes Canónicos (SPEC-07 RF-02.1, canon inmutable). */
export const CANONICAL_LINEAGE_TYPES = Object.freeze([
  'primordialFlame',
  'celestialTides',
  'eternalTempest',
  'worldRoots',
  'dawnWinds',
  'solarCrown',
  'abyssalShadows',
  'aetherWeavers',
]);

/**
 * Rótulos ceremoniales castellanos de los Ocho (RNF-01, soberanía
 * lingüística): espejo EXACTO del vocabulario del Salón de Linajes y del
 * distintivo (userProfileBadge.js, UserPanelDto::LINEAGE_LABELS, doctrina
 * sembrada en lineage_doctrines). Hallazgo de la verificación visual
 * 2026-09-28: el modal imprimía la clave técnica en inglés en vez del
 * rótulo solemne. La clave técnica viaja SOLO en el value del radio; el
 * label muestra el nombre ceremonial.
 */
export const CANONICAL_LINEAGE_LABELS = Object.freeze({
  primordialFlame: 'Linaje de la Llama Primordial',
  celestialTides: 'Linaje de las Mareas Celestiales',
  eternalTempest: 'Linaje de la Tempestad Eterna',
  worldRoots: 'Linaje de las Raíces del Mundo',
  dawnWinds: 'Linaje de los Vientos del Alba',
  solarCrown: 'Linaje de la Corona Solar',
  abyssalShadows: 'Linaje de las Sombras Abisales',
  aetherWeavers: 'Linaje de los Tejedores del Éter',
});

/* Leyendas temáticas de los vetos (RF-10.5), por código canónico del contrato. */
export const FOUNDATION_VETO_LEGENDS = Object.freeze({
  INSUFFICIENT_RANK: 'Los neófitos sin pluma no alzan estandartes: tu rango aún no alcanza la fundación.',
  INVALID_NAME: 'El nombre canónico no viste la túnica del canon (4 a 50 caracteres).',
  NAME_ALREADY_RESERVED: 'Ese nombre ya resuena bajo otro estandarte: elige otro para tu casa.',
  UNKNOWN_LINEAGE: 'El linaje invocado no habita el canon de los Ocho.',
  CLAN_LINEAGE_MISMATCH: 'El estandarte brota de tu propia sangre arcana: no puedes fundar bajo linaje ajeno.',
  CONVALESCENCE_ACTIVE: 'Tu esencia aún sana en Convalecencia Arcana: espera a que remita su hechizo.',
  ALREADY_AFFILIATED: 'La lealtad mágica es indivisible: ya militas bajo otro estandarte.',
  ADMIN_LINEAGE_REQUIRED: 'La Corona Suprema debe declarar su linaje rector antes de alzar la casa.',
  UNAUTHENTICATED: 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de alzar una casa.',
});

/* Canon del Nombre Canónico (ClanDto, SPEC-07 RF-01.2). */
export const FOUNDATION_NAME_MIN_LENGTH = 4;
export const FOUNDATION_NAME_MAX_LENGTH = 50;
export const FOUNDATION_MOTTO_MAX_LENGTH = 255;

/* Leyendas de la validación temprana (§9.4): el canon local antes del rito. */
export const FOUNDATION_NAME_LENGTH_LEGEND =
  'El nombre canónico no viste la túnica del canon (4 a 50 caracteres).';
export const FOUNDATION_MOTTO_REQUIRED_LEGEND =
  'La casa sin lema carece de corazón: escribe el suyo antes de alzarla.';

/* Clases BEM de la superficie (tokens de SPEC-02, RNF). */
const CSS = Object.freeze({
  root: 'foundation-modal',
  form: 'foundation-modal__form',
  field: 'foundation-modal__field',
  fieldLabel: 'foundation-modal__field-label',
  fieldHint: 'foundation-modal__field-hint',
  fieldCounter: 'foundation-modal__field-counter',
  fieldInput: 'foundation-modal__input',
  fieldCounterOver: 'foundation-modal__field-counter--over',
  lineageGroup: 'foundation-modal__lineage-group',
  lineageOption: 'foundation-modal__lineage-option',
  lineageOptionLabel: 'foundation-modal__lineage-option-label',
  lineageOptionHint: 'foundation-modal__lineage-option-hint',
  lineageOptionForeign: 'foundation-modal__lineage-option--foreign',
  admissionGroup: 'foundation-modal__admission-group',
  admissionOption: 'foundation-modal__admission-option',
  admissionOptionHint: 'foundation-modal__admission-option-hint',
  veto: 'foundation-modal__veto',
  actions: 'foundation-modal__actions',
  submit: 'foundation-modal__submit button button--primary',
  dismiss: 'foundation-modal__dismiss button button--secondary',
  close: 'foundation-modal__close',
});

/**
 * Búsqueda recursiva de un descendiente por clase (DOM real o simulado).
 * Patrón compartido con los modales hermanos del santuario.
 */
function findDescendantByClass(root, className) {
  if (typeof root.querySelector === 'function') {
    return root.querySelector('.' + className);
  }
  for (const child of root.children ?? []) {
    if (child.classes?.has(className)) return child;
    const found = findDescendantByClass(child, className);
    if (found) return found;
  }
  return null;
}

/**
 * Extrae los valores del evento de envío. El arnés entrega `event.fields`;
 * en el navegador real se leen los inputs por su atributo `name`.
 */
function extractFoundationPayload(event) {
  const fields = event.fields ?? {};
  const readField = (fieldName) => {
    if (fieldName in fields) return fields[fieldName];
    const inputs = event.target?.querySelectorAll?.(`[name="${fieldName}"]`);
    if (inputs) {
      // Radio groups: el valor vive en el radio MARCADO, jamás en el primero
      // del DOM (un radio vedado podría encabezar el grupo).
      for (const input of inputs) {
        if (input.type === 'radio') {
          if (input.checked) return input.value;
          continue;
        }
        return input.value;
      }
      return '';
    }
    const input = event.target?.querySelector?.(`[name="${fieldName}"]`);
    return input?.value ?? '';
  };
  return {
    name: String(readField('name') ?? ''),
    motto: String(readField('motto') ?? ''),
    lineageType: String(readField('lineageType') ?? ''),
    admissionMode: String(readField('admissionMode') ?? 'open'),
  };
}

/**
 * Crea el Umbral de la Fundación.
 *
 * @param {HTMLDialogElement} dialog Elemento `<dialog id="foundationModal">`.
 *        Puede venir del shell o forjarse en pruebas: el componente es
 *        agnóstico del origen.
 * @param {Object} componentOptions Opciones:
 *   - onConfirm(payload): la vista consume la confirmación y despacha el rito
 *     a clanClient.foundClan. El modal cierra tras notificar (RF-10.3).
 *   - onClose(): descarte por Escape/×/botón (RF-10.7).
 *   - userLineage: linaje jurado del fundador (string) — SOLO ese linaje es
 *     seleccionable (RF-10.2.3); ausente = ninguno seleccionable.
 *   - documentRef: documento inyectable (tests).
 * @returns {Object} API: { open, close, isOpen, reportError,
 *          closeAfterSuccess, getDraft, destroy }.
 */
export function createClanFoundationModalComponent(dialog, componentOptions = {}) {
  const {
    onConfirm = null,
    onClose = null,
    userLineage = '',
    documentRef = globalThis.document,
  } = componentOptions;

  /** El rito está en vuelo: el envío queda sellado (RF-10.3). */
  let isSubmitting = false;
  let isDestroyed = false;
  let isWired = false;

  /** Referencias vivas del formulario. */
  let form = null;
  let nameInput = null;
  let nameCounter = null;
  let mottoInput = null;
  let vetoRegion = null;
  let submitButton = null;

  /* -------------------------------------------------------------------
   * Forja del armazón (una sola vez): los nodos viven dentro del diálogo.
   * ------------------------------------------------------------------- */

  function forgeFieldLabel(parent, forId, text) {
    const label = documentRef.createElement('label');
    label.setAttribute('class', CSS.fieldLabel);
    label.setAttribute('for', forId);
    label.textContent = text;
    parent.appendChild(label);
    return label;
  }

  function forgeFieldHint(parent, text) {
    const hint = documentRef.createElement('p');
    hint.setAttribute('class', CSS.fieldHint);
    hint.textContent = text;
    parent.appendChild(hint);
    return hint;
  }

  function forgeTextInput(parent, inputId, inputName, maxLength) {
    const input = documentRef.createElement('input');
    input.type = 'text';
    input.setAttribute('class', CSS.fieldInput);
    input.setAttribute('id', inputId);
    input.setAttribute('name', inputName);
    input.setAttribute('maxlength', String(maxLength));
    input.setAttribute('autocomplete', 'off');
    parent.appendChild(input);
    return input;
  }

  /** Los 8 linajes: solo el jurado es seleccionable (RF-10.2.3). */
  function forgeLineageGroup(parent) {
    const group = documentRef.createElement('fieldset');
    group.setAttribute('class', CSS.lineageGroup);
    group.appendChild(forgeFieldLabel(group, 'foundationLineageLegend', FOUNDATION_LINEAGE_LABEL));

    for (const lineageType of CANONICAL_LINEAGE_TYPES) {
      const isOwn = lineageType === userLineage;

      const option = documentRef.createElement('div');
      option.setAttribute('class', isOwn ? CSS.lineageOption : `${CSS.lineageOption} ${CSS.lineageOptionForeign}`);

      const radio = documentRef.createElement('input');
      radio.type = 'radio';
      radio.name = 'lineageType';
      radio.value = lineageType;
      radio.id = `foundationLineage-${lineageType}`;
      if (!isOwn) {
        // El veto de sangre propia es REAL: el navegador jamás despacha un
        // linaje ajeno (RNF-04; el backend es la última muralla igualmente).
        radio.disabled = true;
      }
      option.appendChild(radio);

      const optionLabel = documentRef.createElement('label');
      optionLabel.setAttribute('class', CSS.lineageOptionLabel);
      optionLabel.setAttribute('for', radio.id);
      optionLabel.textContent = CANONICAL_LINEAGE_LABELS[lineageType] ?? lineageType;
      option.appendChild(optionLabel);

      const optionHint = documentRef.createElement('p');
      optionHint.setAttribute('class', CSS.lineageOptionHint);
      optionHint.textContent = isOwn ? FOUNDATION_LINEAGE_OWN_HINT : FOUNDATION_LINEAGE_FOREIGN_HINT;
      option.appendChild(optionHint);

      group.appendChild(option);
    }

    parent.appendChild(group);
    return group;
  }

  /** El régimen de admisión (RF-10.2.4): open por defecto. */
  function forgeAdmissionGroup(parent) {
    const group = documentRef.createElement('fieldset');
    group.setAttribute('class', CSS.admissionGroup);
    group.appendChild(forgeFieldLabel(group, 'foundationAdmissionLegend', FOUNDATION_ADMISSION_LABEL));

    const regimes = [
      { value: 'open', label: FOUNDATION_ADMISSION_OPEN_LABEL, hint: FOUNDATION_ADMISSION_OPEN_HINT },
      {
        value: 'byApplication',
        label: FOUNDATION_ADMISSION_BY_APPLICATION_LABEL,
        hint: FOUNDATION_ADMISSION_BY_APPLICATION_HINT,
      },
    ];

    for (const [index, regime] of regimes.entries()) {
      const option = documentRef.createElement('div');
      option.setAttribute('class', CSS.admissionOption);

      const radio = documentRef.createElement('input');
      radio.type = 'radio';
      radio.name = 'admissionMode';
      radio.value = regime.value;
      radio.id = `foundationAdmission-${regime.value}`;
      if (index === 0) radio.checked = true;
      option.appendChild(radio);

      const optionLabel = documentRef.createElement('label');
      optionLabel.setAttribute('class', CSS.admissionOptionHint.replace(/-hint$/, '-label'));
      optionLabel.setAttribute('for', radio.id);
      optionLabel.textContent = regime.label;
      option.appendChild(optionLabel);

      const optionHint = documentRef.createElement('p');
      optionHint.setAttribute('class', CSS.admissionOptionHint);
      optionHint.textContent = regime.hint;
      option.appendChild(optionHint);

      group.appendChild(option);
    }

    parent.appendChild(group);
    return group;
  }

  /** Construye el formulario dentro del diálogo (idempotente). */
  function ensureForm() {
    if (form !== null) return;

    form = documentRef.createElement('form');
    form.setAttribute('class', CSS.form);
    form.setAttribute('novalidate', 'true');
    dialog.appendChild(form);

    // Sello 1: Nombre Canónico con contador visible (RF-10.2.1).
    const nameField = documentRef.createElement('div');
    nameField.setAttribute('class', CSS.field);
    form.appendChild(nameField);
    forgeFieldLabel(nameField, 'foundationNameInput', FOUNDATION_NAME_LABEL);
    nameInput = forgeTextInput(
      nameField,
      'foundationNameInput',
      'name',
      FOUNDATION_NAME_MAX_LENGTH,
    );
    forgeFieldHint(nameField, FOUNDATION_NAME_HINT);
    nameCounter = documentRef.createElement('p');
    nameCounter.setAttribute('class', CSS.fieldCounter);
    nameCounter.textContent = `0 / ${FOUNDATION_NAME_MAX_LENGTH}`;
    nameField.appendChild(nameCounter);
    nameInput.addEventListener('input', () => {
      const length = String(nameInput.value ?? '').length;
      nameCounter.textContent = `${length} / ${FOUNDATION_NAME_MAX_LENGTH}`;
      if (length > FOUNDATION_NAME_MAX_LENGTH || (length > 0 && length < FOUNDATION_NAME_MIN_LENGTH)) {
        nameCounter.classList?.add?.(CSS.fieldCounterOver);
      } else {
        nameCounter.classList?.remove?.(CSS.fieldCounterOver);
      }
    });

    // Sello 2: Lema Heráldico (RF-10.2.2).
    const mottoField = documentRef.createElement('div');
    mottoField.setAttribute('class', CSS.field);
    form.appendChild(mottoField);
    forgeFieldLabel(mottoField, 'foundationMottoInput', FOUNDATION_MOTTO_LABEL);
    mottoInput = forgeTextInput(
      mottoField,
      'foundationMottoInput',
      'motto',
      FOUNDATION_MOTTO_MAX_LENGTH,
    );
    forgeFieldHint(mottoField, FOUNDATION_MOTTO_HINT);

    // Sello 3: Linaje Rector (RF-10.2.3) — sangre propia únicamente.
    forgeLineageGroup(form);

    // Régimen de admisión (RF-10.2.4).
    forgeAdmissionGroup(form);

    // Región viva de vetos (RF-10.5).
    vetoRegion = documentRef.createElement('p');
    vetoRegion.setAttribute('class', CSS.veto);
    vetoRegion.setAttribute('role', 'alert');
    vetoRegion.setAttribute('aria-live', 'assertive');
    vetoRegion.textContent = '';
    form.appendChild(vetoRegion);

    // Gestos (RF-10.3, RF-10.7).
    const actions = documentRef.createElement('div');
    actions.setAttribute('class', CSS.actions);
    form.appendChild(actions);

    submitButton = documentRef.createElement('button');
    submitButton.type = 'submit';
    submitButton.setAttribute('class', CSS.submit);
    submitButton.textContent = FOUNDATION_SUBMIT_LABEL;
    actions.appendChild(submitButton);

    const dismissButton = documentRef.createElement('button');
    dismissButton.type = 'button';
    dismissButton.setAttribute('class', CSS.dismiss);
    dismissButton.textContent = FOUNDATION_DISMISS_LABEL;
    dismissButton.addEventListener('click', () => close());
    actions.appendChild(dismissButton);

    form.addEventListener('submit', (event) => {
      event.preventDefault?.();
      if (isSubmitting) return; // El sello anti-doble-envío (RF-10.3).

      const payload = extractFoundationPayload(event);
      // Validación temprana (§9.4 del arnés): el canon del Nombre Canónico
      // (4–50, SPEC-07 RF-01.2) se guarda ANTES de despachar el rito — el
      // backend es la última muralla, no el primer filtro.
      const nameLength = payload.name.trim().length;
      if (nameLength < FOUNDATION_NAME_MIN_LENGTH || nameLength > FOUNDATION_NAME_MAX_LENGTH) {
        reportLocalVeto(FOUNDATION_NAME_LENGTH_LEGEND);
        return;
      }
      if (payload.motto.trim() === '') {
        reportLocalVeto(FOUNDATION_MOTTO_REQUIRED_LEGEND);
        return;
      }
      if (payload.lineageType === '') {
        // Validación temprana: sin linaje no hay rito (caso 4 del arnés).
        reportLocalVeto(FOUNDATION_VETO_LEGENDS.CLAN_LINEAGE_MISMATCH);
        return;
      }

      isSubmitting = true;
      submitButton.disabled = true;
      submitButton.textContent = FOUNDATION_SUBMIT_IN_FLIGHT_LABEL;
      if (typeof onConfirm === 'function') onConfirm(payload);
    });
  }

  /** Refuerzo del confinamiento de foco (RNF-02), patrón del modal de acceso. */
  function handleKeydown(event) {
    if (event.key !== 'Tab') return;
    if (event.target !== dialog && event.currentTarget !== dialog) return;
    event.preventDefault?.();
    const firstInput = findDescendantByClass(dialog, CSS.fieldInput);
    if (firstInput) firstInput.focus();
  }

  function handleClose() {
    if (isSubmitting) {
      // Un cierre mientras el rito viaja es imposible en el navegador real
      // (showModal bloquea Escape hacia el fondo), pero el DOM simulado
      // exige la guardia: el veredicto del santuario es la única vía de
      // cierre durante el vuelo.
      return;
    }
    resetSubmissionState();
    if (typeof onClose === 'function') onClose();
  }

  /** Devuelve el botón a su estado de reposo (borrador se CONSERVA). */
  function resetSubmissionState() {
    isSubmitting = false;
    if (submitButton !== null) {
      submitButton.disabled = false;
      submitButton.textContent = FOUNDATION_SUBMIT_LABEL;
    }
  }

  /** Abre el Umbral. Idempotente: si ya está abierto, no re-fuerza nada. */
  function open() {
    if (isDestroyed || dialog.open) return;

    ensureForm();
    // Botón × del shell si existiera (el cierre converge en dialog.close()).
    const closeButton = typeof dialog.querySelector === 'function'
      ? dialog.querySelector(`.${CSS.close}`)
      : null;
    closeButton?.addEventListener?.('click', () => close());

    dialog.addEventListener('keydown', handleKeydown);
    dialog.showModal();
    announce(FOUNDATION_ANNOUNCE_OPEN);

    // Foco inicial (RNF-02): primer campo del formulario.
    const firstInput = findDescendantByClass(dialog, CSS.fieldInput);
    if (firstInput) firstInput.focus();
  }

  /** Cierra el diálogo conservando el borrador (RF-10.7). */
  function close() {
    if (!dialog.open) return;
    dialog.close('foundation-dismissed');
    announce(FOUNDATION_ANNOUNCE_DISMISSED);
  }

  /** Anuncia en la región viva de vetos (RF-10.5). */
  function announce(legend) {
    if (vetoRegion === null) return;
    vetoRegion.textContent = legend;
  }

  /**
   * Recibe el veredicto del santuario (RF-10.5). El sobre estándar
   * { success: false, error: { code, message } } se traduce a su leyenda
   * temática; el modal permanece abierto con el borrador intacto.
   *
   * @param {Object|null} errorEnvelope Sobre de error del contrato REST.
   */
  function reportError(errorEnvelope) {
    const errorCode = errorEnvelope?.error?.code ?? '';
    // La leyenda del santuario tiene precedencia si el backend aportó una
    // propia; el catálogo del Umbral cubre los códigos canónicos.
    const serverLegend = typeof errorEnvelope?.error?.message === 'string'
      && errorEnvelope.error.message.trim() !== ''
      ? errorEnvelope.error.message
      : null;
    const legend = FOUNDATION_VETO_LEGENDS[errorCode] ?? serverLegend ?? FOUNDATION_FALLBACK_ERROR;

    resetSubmissionState();
    announce(legend);
  }

  /**
   * Veto LOCAL de la validación temprana (§9.4): anuncia la leyenda y
   * restaura el botón sin tocar el catálogo del contrato (el veto local no
   * proviene del santuario).
   */
  function reportLocalVeto(legend) {
    resetSubmissionState();
    announce(legend);
  }

  /** Veredicto favorable (RF-10.4): el modal cierra con el borrador limpio. */
  function closeAfterSuccess() {
    resetSubmissionState();
    if (form !== null) form.reset?.();
    if (nameCounter !== null) nameCounter.textContent = `0 / ${FOUNDATION_NAME_MAX_LENGTH}`;
    announce('');
    if (dialog.open) dialog.close('foundation-consumed');
  }

  /** Borrador vigente (RF-10.7: sobrevive a descartes en la misma visita). */
  function getDraft() {
    if (form === null) return { name: '', motto: '', lineageType: '', admissionMode: 'open' };
    const payload = extractFoundationPayload({ fields: {}, target: form });
    return payload;
  }

  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    dialog.removeEventListener('keydown', handleKeydown);
    dialog.removeEventListener('close', handleClose);
  }

  dialog.addEventListener('close', handleClose);

  return {
    open,
    close,
    isOpen: () => dialog.open,
    reportError,
    closeAfterSuccess,
    getDraft,
    destroy,
  };
}

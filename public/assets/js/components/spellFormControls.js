/**
 * spellFormControls.js — Controles táctiles del Taller arcano.
 *
 * Tarea 5.4 (TASKS-04): construye y gobierna el formulario del creador —
 * inputs numéricos (daño, cura, barrera), selector de control de masas,
 * botones de radio para alcance/área/duración y casillas de componentes —
 * emitiendo el evento personalizado 'spell:params-changed' en la raíz en
 * CADA pulsación (RF-04.1, tiempo real sin recargar la página ni bloquear
 * el hilo principal).
 *
 * El payload del evento porta los 10 parámetros canónicos del DTO de
 * entrada del backend (Tarea 1.2) con las MISMAS claves camelCase, de
 * modo que simulaMana() (Tarea 5.1) y el Endpoint /calculate (Tarea 4.1)
 * lo consuman sin transformación alguna.
 *
 * Seguridad (AGENTS.md 6.1): las etiquetas viajan vía textContent —
 * jamás innerHTML. Los valores numéricos sucios (negativos, texto) se
 * normalizan a 0 sin romper el flujo de eventos.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: etiquetas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 */

/** Canon de modificadores (espejo EXACTO de SpellCalculationInputDto). */

/** Modos canónicos de control de masas. */
const CROWD_CONTROL_TYPES = Object.freeze(['none', 'slow', 'root', 'stun']);
/** Alcances canónicos. */
const RANGE_TYPES = Object.freeze(['touch', 'short', 'medium', 'long']);
/** Geometrías de área canónicas. */
const AREA_TYPES = Object.freeze(['singleTarget', 'cone', 'line', 'sphere']);
/** Duraciones canónicas. */
const DURATION_TYPES = Object.freeze(['instant', 'concentration', 'sustained']);

/**
 * Normaliza un valor de input numérico a entero >= 0 (defensa anti-tipos
 * sucios: 'treinta', -10, 10.5 → 0; 30, '30' → 30).
 *
 * @param {string} rawValue Valor crudo del input.
 * @returns {number} Entero no negativo.
 */
function normalizeNonNegativeInteger(rawValue) {
  const parsed = Number(rawValue);
  if (!Number.isFinite(parsed) || parsed < 0) {
    return 0;
  }
  return Math.floor(parsed);
}

/**
 * Crea el formulario de controles del Taller.
 *
 * @param {object} componentOptions Opciones:
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     las pruebas inyectan su DOM simulado).
 * @returns {{
 *   root: HTMLElement,
 *   getParams: function(): object,
 *   setParams: function(object): void,
 * }} La raíz emisora del evento 'spell:params-changed', el lector
 *   consolidado de parámetros y el restaurador (para borradores).
 */
export function createSpellFormControls(componentOptions = {}) {
  const {
    elementFactory = (tagName) => document.createElement(tagName),
  } = componentOptions;

  /**
   * Helper de construcción segura: nodo con clase y texto (textContent).
   * @param {string} tagName Etiqueta del nodo.
   * @param {string} className Clase CSS del nodo.
   * @param {string} safeText Texto seguro de la etiqueta.
   * @returns {HTMLElement}
   */
  function createTextElement(tagName, className, safeText) {
    const element = elementFactory(tagName);
    element.className = className;
    element.textContent = safeText;
    return element;
  }

  /**
   * Crea un input numérico con su etiqueta (input + label asociada).
   * @param {string} name Nombre técnico del parámetro (DTO).
   * @param {string} labelText Etiqueta solemne en castellano.
   * @returns {{wrapper: HTMLElement, input: HTMLElement}}
   */
  function createNumberInput(name, labelText) {
    const wrapper = elementFactory('div');
    wrapper.className = 'spell-form-controls__field';

    const input = elementFactory('input');
    input.type = 'number';
    input.name = name;
    input.className = 'spell-form-controls__number';
    input.min = '0';
    input.step = '1';
    input.value = '0';
    input.setAttribute('id', `spell-form-${name}`);

    const label = elementFactory('label');
    label.className = 'spell-form-controls__label';
    label.setAttribute('for', `spell-form-${name}`);
    label.textContent = labelText;

    wrapper.appendChild(label);
    wrapper.appendChild(input);
    return { wrapper, input };
  }

  /**
   * Crea un selector (select) con opciones canónicas.
   * @param {string} name Nombre técnico del parámetro.
   * @param {string} labelText Etiqueta solemne.
   * @param {readonly string[]} options Canon de valores.
   * @param {string} defaultValue Valor inicial.
   * @returns {{wrapper: HTMLElement, select: HTMLElement}}
   */
  function createSelect(name, labelText, options, defaultValue) {
    const wrapper = elementFactory('div');
    wrapper.className = 'spell-form-controls__field';

    const select = elementFactory('select');
    select.name = name;
    select.className = 'spell-form-controls__select';
    select.setAttribute('id', `spell-form-${name}`);
    // El fake necesita una lista de opciones consultable; el select real
    // las porta como hijos <option> (read-only del DOM). La asignación solo
    // prospera en el DOM simulado de los arneses; en el navegador se captura
    // el TypeError (modo estricto) sin romper el flujo.
    try {
      select.options = [...options];
    } catch {
      // DOM real: la lista de opciones vive en los <option> añadidos abajo.
    }

    for (const optionValue of options) {
      const option = elementFactory('option');
      option.value = optionValue;
      option.textContent = optionValue;
      select.appendChild(option);
    }
    // El valor inicial se asienta tras poblar las opciones (en el DOM real
    // asignarlo antes no tiene efecto).
    select.value = defaultValue;

    const label = elementFactory('label');
    label.className = 'spell-form-controls__label';
    label.setAttribute('for', `spell-form-${name}`);
    label.textContent = labelText;

    wrapper.appendChild(label);
    wrapper.appendChild(select);
    return { wrapper, select };
  }

  /**
   * Crea un grupo fieldset de radios con el canon dado.
   * @param {string} name Nombre técnico del parámetro.
   * @param {string} legendText Leyenda solemne del grupo.
   * @param {readonly string[]} options Canon de valores.
   * @param {string} defaultValue Valor inicial marcado.
   * @returns {{wrapper: HTMLElement, radios: HTMLElement[]}}
   */
  function createRadioGroup(name, legendText, options, defaultValue) {
    const wrapper = elementFactory('fieldset');
    wrapper.className = 'spell-form-controls__group';

    const legend = elementFactory('legend');
    legend.className = 'spell-form-controls__legend';
    legend.textContent = legendText;
    wrapper.appendChild(legend);

    const radios = [];
    for (const optionValue of options) {
      const optionWrapper = elementFactory('div');
      optionWrapper.className = 'spell-form-controls__radio-option';

      const radio = elementFactory('input');
      radio.type = 'radio';
      radio.name = name;
      radio.value = optionValue;
      radio.className = 'spell-form-controls__radio';
      radio.checked = optionValue === defaultValue;
      radio.setAttribute('id', `spell-form-${name}-${optionValue}`);

      const label = elementFactory('label');
      label.className = 'spell-form-controls__radio-label';
      label.setAttribute('for', `spell-form-${name}-${optionValue}`);
      label.textContent = optionValue;

      optionWrapper.appendChild(radio);
      optionWrapper.appendChild(label);
      wrapper.appendChild(optionWrapper);
      radios.push(radio);
    }

    return { wrapper, radios };
  }

  /**
   * Crea una casilla de componente con su etiqueta.
   * @param {string} name Nombre técnico (hasVerbal/hasSomatic/hasMaterial).
   * @param {string} labelText Etiqueta solemne.
   * @returns {{wrapper: HTMLElement, checkbox: HTMLElement}}
   */
  function createCheckbox(name, labelText) {
    const wrapper = elementFactory('div');
    wrapper.className = 'spell-form-controls__field spell-form-controls__field--checkbox';

    const checkbox = elementFactory('input');
    checkbox.type = 'checkbox';
    checkbox.name = name;
    checkbox.className = 'spell-form-controls__checkbox';
    checkbox.checked = false;
    checkbox.setAttribute('id', `spell-form-${name}`);

    const label = elementFactory('label');
    label.className = 'spell-form-controls__label';
    label.setAttribute('for', `spell-form-${name}`);
    label.textContent = labelText;

    wrapper.appendChild(checkbox);
    wrapper.appendChild(label);
    return { wrapper, checkbox };
  }

  // -----------------------------------------------------------------
  // Construcción del formulario.
  // -----------------------------------------------------------------
  const root = elementFactory('section');
  root.className = 'spell-form-controls';

  // --- Efectos base (RF-01.2) ---
  const damageControl = createNumberInput('damage', 'Daño');
  const healingControl = createNumberInput('healing', 'Curación');
  const barrierControl = createNumberInput('barrier', 'Barrera');
  root.appendChild(damageControl.wrapper);
  root.appendChild(healingControl.wrapper);
  root.appendChild(barrierControl.wrapper);

  // --- Control de masas (RF-01.3) ---
  const crowdControlSelectControl = createSelect('crowdControlType', 'Control de masas', CROWD_CONTROL_TYPES, 'none');
  root.appendChild(crowdControlSelectControl.wrapper);

  // --- Alcance, área y duración como radios (RF-01.4 / RF-01.5) ---
  const rangeGroup = createRadioGroup('rangeType', 'Alcance', RANGE_TYPES, 'touch');
  const areaGroup = createRadioGroup('areaType', 'Área', AREA_TYPES, 'singleTarget');
  const durationGroup = createRadioGroup('durationType', 'Duración', DURATION_TYPES, 'instant');
  root.appendChild(rangeGroup.wrapper);
  root.appendChild(areaGroup.wrapper);
  root.appendChild(durationGroup.wrapper);

  // --- Componentes atenuadores (RF-01.5) ---
  const verbalControl = createCheckbox('hasVerbal', 'Componente verbal (−10%)');
  const somaticControl = createCheckbox('hasSomatic', 'Componente somático (−10%)');
  const materialControl = createCheckbox('hasMaterial', 'Componente material (−10%)');
  root.appendChild(verbalControl.wrapper);
  root.appendChild(somaticControl.wrapper);
  root.appendChild(materialControl.wrapper);

  /**
   * Lee el parámetro marcado de un grupo de radios.
   * @param {HTMLElement[]} radios Radios del grupo.
   * @returns {string} Valor marcado (o el primero del canon si ninguno).
   */
  function checkedValue(radios) {
    const checked = radios.find((radio) => radio.checked === true);
    return checked ? checked.value : radios[0]?.value;
  }

  /**
   * Lee el valor marcado de un select (el fake porta .value directo).
   * @param {HTMLElement} select Selector.
   * @returns {string}
   */
  function selectedValue(select) {
    return select.value ?? CROWD_CONTROL_TYPES[0];
  }

  /**
   * Consolida los 10 parámetros canónicos del DTO desde el estado del
   * formulario (normalizando los numéricos).
   * @returns {object} Payload { damage, healing, barrier, crowdControlType,
   *   rangeType, areaType, durationType, hasVerbal, hasSomatic, hasMaterial }.
   */
  function getParams() {
    return {
      damage: normalizeNonNegativeInteger(damageControl.input.value),
      healing: normalizeNonNegativeInteger(healingControl.input.value),
      barrier: normalizeNonNegativeInteger(barrierControl.input.value),
      crowdControlType: selectedValue(crowdControlSelectControl.select),
      rangeType: checkedValue(rangeGroup.radios),
      areaType: checkedValue(areaGroup.radios),
      durationType: checkedValue(durationGroup.radios),
      hasVerbal: verbalControl.checkbox.checked === true,
      hasSomatic: somaticControl.checkbox.checked === true,
      hasMaterial: materialControl.checkbox.checked === true,
    };
  }

  /**
   * Restaura el formulario a un conjunto de parámetros (borradores
   * guardados, Tarea 5.5). Los campos ausentes conservan su valor.
   * @param {object} params Parámetros canónicos (payload del evento).
   */
  function setParams(params) {
    if (params === null || typeof params !== 'object') {
      return;
    }
    if (params.damage !== undefined) damageControl.input.value = String(params.damage);
    if (params.healing !== undefined) healingControl.input.value = String(params.healing);
    if (params.barrier !== undefined) barrierControl.input.value = String(params.barrier);
    if (params.crowdControlType !== undefined) crowdControlSelectControl.select.value = params.crowdControlType;

    const radioGroups = [
      ['rangeType', rangeGroup],
      ['areaType', areaGroup],
      ['durationType', durationGroup],
    ];
    for (const [paramName, group] of radioGroups) {
      if (params[paramName] !== undefined) {
        for (const radio of group.radios) {
          radio.checked = radio.value === params[paramName];
        }
      }
    }

    if (params.hasVerbal !== undefined) verbalControl.checkbox.checked = params.hasVerbal === true;
    if (params.hasSomatic !== undefined) somaticControl.checkbox.checked = params.hasSomatic === true;
    if (params.hasMaterial !== undefined) materialControl.checkbox.checked = params.hasMaterial === true;
  }

  /**
   * Emite 'spell:params-changed' en la raíz con el payload consolidado.
   * En el navegador usa CustomEvent (detail.payload); el fake del arnés
   * recibe { type, detail } equivalente.
   * @param {string} eventType Tipo de evento DOM que originó la emisión.
   */
  function emitParamsChanged(eventType) {
    const payload = getParams();
    const customEvent = {
      type: eventType,
      detail: { payload },
    };
    // CustomEvent real en el navegador (con dispatchEvent); el DOM
    // simulado del arnés porta sus listeners y recibe el objeto equivalente.
    if (typeof root.dispatchEvent === 'function') {
      root.dispatchEvent(new CustomEvent('spell:params-changed', { detail: { payload } }));
    } else {
      for (const listener of root.listeners['spell:params-changed'] ?? []) {
        listener(customEvent);
      }
    }
  }

  // Cada pulsación emite en TIEMPO REAL (RF-04.1): 'input' para los
  // numéricos (cada tecla) y 'change' para selectores y casillas.
  for (const numericInput of [damageControl.input, healingControl.input, barrierControl.input]) {
    numericInput.addEventListener('input', () => emitParamsChanged('input'));
    numericInput.addEventListener('change', () => emitParamsChanged('change'));
  }
  crowdControlSelectControl.select.addEventListener('change', () => emitParamsChanged('change'));
  for (const radio of [...rangeGroup.radios, ...areaGroup.radios, ...durationGroup.radios]) {
    radio.addEventListener('change', () => emitParamsChanged('change'));
  }
  for (const checkbox of [verbalControl.checkbox, somaticControl.checkbox, materialControl.checkbox]) {
    checkbox.addEventListener('change', () => emitParamsChanged('change'));
  }

  return { root, getParams, setParams };
}

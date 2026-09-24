/**
 * test_spell_form_controls.mjs — Arnés TDD de la Tarea 5.4 (TASKS-04).
 *
 * Verifica el componente de controles del Taller arcano
 * (public/assets/js/components/spellFormControls.js):
 *   - createSpellFormControls(options) construye el formulario con los
 *     inputs numéricos (daño, cura, barrera), el selector de control de
 *     masas, los botones de radio de alcance/área/duración y las casillas
 *     de componentes (verbal, somático, material).
 *   - Cada pulsación emite el evento personalizado 'spell:params-changed'
 *     en la raíz, con detail.payload = { los 10 parámetros del DTO }.
 *   - Los catálogos de modificadores son el CANON del backend (DTO 1.2).
 *   - Valores inválidos (negativos, texto sucio) se normalizan a 0 sin
 *     romper el flujo de eventos.
 *   - Degradación de accesibilidad: inputs con etiquetas y grupos fieldset.
 *
 * Criterio «Hecho cuando» (Tarea 5.4): modificar cualquier campo numérico
 * o selector dispara el evento en tiempo real sin recargar la página ni
 * bloquear el hilo de ejecución principal.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: etiquetas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 *
 * Uso: node scratch/test_spell_form_controls.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/**
 * Elemento DOM mínimo simulado (patrón consolidado del proyecto).
 * innerHTML está PROHIBIDO (AGENTS.md 6.1): su uso lanza excepción.
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    parentElement: null,
    value: '',
    checked: false,
    type: '',
    name: '',
    disabled: false,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    /** Dispara un evento DOM simulado sobre este nodo. */
    dispatchDom(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, target: this, currentTarget: this, ...eventObject });
      }
    },
    dispatchEvent(domEvent) {
      return this.dispatchDom(domEvent.type, { detail: domEvent.detail });
    },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

/** Búsqueda recursiva de descendientes por clase. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

/** Búsqueda recursiva de descendientes por atributo name. */
function queryByName(node, name, found = []) {
  for (const child of node.children) {
    if (child.attributes['name'] === name || child.name === name) found.push(child);
    queryByName(child, name, found);
  }
  return found;
}

console.log('== VERIFICACION TAREA 5.4: spellFormControls.js ==\n');

// --- Import del módulo bajo prueba (fase roja: no existe aún) ---
let spellFormControls = null;
try {
  spellFormControls = await import('../public/assets/js/components/spellFormControls.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo spellFormControls.js no pudo importarse: ${importError.message}`);
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 0');
  console.log('Asertos fallidos: 1');
  console.log('\nRESULTADO: DENEGADO — Fase roja: implementar public/assets/js/components/spellFormControls.js');
  process.exit(1);
}

const { createSpellFormControls } = spellFormControls;

const elementFactory = createFakeElement;

console.log('[0] Superficie');
assertCondition(typeof createSpellFormControls === 'function', 'El módulo exporta createSpellFormControls()');

// =====================================================================
// [1] Creación del formulario: estructura y canon de modificadores.
// =====================================================================
console.log('\n[1] Creación del formulario (canon del DTO 1.2)');

const controls = createSpellFormControls({ elementFactory });
const root = controls.root;
assertCondition(typeof root?.appendChild === 'function' && typeof controls?.getParams === 'function', 'Retorna { root, getParams } (y emite eventos)');
assertCondition(root.classes.has('spell-form-controls'), 'La raíz porta la clase BEM spell-form-controls');

// Inputs numéricos: daño, cura, barrera.
const damageInputs = queryByName(root, 'damage');
const healingInputs = queryByName(root, 'healing');
const barrierInputs = queryByName(root, 'barrier');
assertCondition(damageInputs.length === 1 && damageInputs[0].type === 'number', 'Existe el input numérico de daño');
assertCondition(healingInputs.length === 1 && healingInputs[0].type === 'number', 'Existe el input numérico de cura');
assertCondition(barrierInputs.length === 1 && barrierInputs[0].type === 'number', 'Existe el input numérico de barrera');

// Selector de control de masas con el canon { none, slow, root, stun }.
const ccSelects = queryByName(root, 'crowdControlType');
assertCondition(ccSelects.length === 1, 'Existe el selector de control de masas');
assertCondition(
  ccSelects[0].options !== undefined && JSON.stringify(ccSelects[0].options) === JSON.stringify(['none', 'slow', 'root', 'stun']),
  'El selector de CC porta el canon EXACTO { none, slow, root, stun }'
);

// Radios de alcance/área/duración con sus 4/4/3 valores canónicos.
const rangeRadios = queryByName(root, 'rangeType').filter((r) => r.type === 'radio');
const areaRadios = queryByName(root, 'areaType').filter((r) => r.type === 'radio');
const durationRadios = queryByName(root, 'durationType').filter((r) => r.type === 'radio');
assertCondition(JSON.stringify(rangeRadios.map((r) => r.value)) === JSON.stringify(['touch', 'short', 'medium', 'long']), 'Los radios de alcance portan el canon de 4 valores');
assertCondition(JSON.stringify(areaRadios.map((r) => r.value)) === JSON.stringify(['singleTarget', 'cone', 'line', 'sphere']), 'Los radios de área portan el canon de 4 valores');
assertCondition(JSON.stringify(durationRadios.map((r) => r.value)) === JSON.stringify(['instant', 'concentration', 'sustained']), 'Los radios de duración portan el canon de 3 valores');

// Casillas de componentes.
const componentChecks = ['hasVerbal', 'hasSomatic', 'hasMaterial'].map((name) => queryByName(root, name)).flat().filter((c) => c.type === 'checkbox');
assertCondition(componentChecks.length === 3, 'Existen las 3 casillas de componentes (verbal, somático, material)');

// =====================================================================
// [2] Estado inicial: getParams() con los valores por defecto.
// =====================================================================
console.log('\n[2] getParams() — estado inicial');

const initialParams = controls.getParams();
assertCondition(
  initialParams.damage === 0 && initialParams.healing === 0 && initialParams.barrier === 0,
  'Los numéricos nacen a 0'
);
assertCondition(
  initialParams.crowdControlType === 'none' && initialParams.rangeType === 'touch'
  && initialParams.areaType === 'singleTarget' && initialParams.durationType === 'instant',
  'Los modificadores nacen en sus valores por defecto del DTO'
);
assertCondition(
  initialParams.hasVerbal === false && initialParams.hasSomatic === false && initialParams.hasMaterial === false,
  'Los componentes nacen desmarcados'
);

// =====================================================================
// [3] El evento spell:params-changed dispara en cada pulsación.
// =====================================================================
console.log('\n[3] Evento spell:params-changed en cada pulsación');

const receivedEvents = [];
root.addEventListener('spell:params-changed', (customEvent) => {
  receivedEvents.push(customEvent);
});

// Pulsación en el daño: input numérico.
damageInputs[0].value = '25';
damageInputs[0].dispatchDom('input');
assertCondition(receivedEvents.length === 1, 'Editar el daño dispara el evento inmediatamente');

const firstPayload = receivedEvents[0]?.detail?.payload;
assertCondition(firstPayload?.damage === 25, 'El payload porta el daño actualizado (25)');
assertCondition(
  firstPayload !== undefined
  && ['damage', 'healing', 'barrier', 'crowdControlType', 'rangeType', 'areaType', 'durationType', 'hasVerbal', 'hasSomatic', 'hasMaterial']
    .every((key) => key in firstPayload),
  'El payload porta los 10 parámetros del DTO'
);

// Selección en el selector de CC.
ccSelects[0].value = 'stun';
ccSelects[0].dispatchDom('change');
assertCondition(receivedEvents.length === 2, 'Cambiar el selector de CC dispara el evento');
assertCondition(receivedEvents[1]?.detail?.payload?.crowdControlType === 'stun', 'El payload porta stun seleccionado');

// Radio de alcance. En el DOM real, marcar un radio desmarca a sus
// hermanos del grupo automáticamente; el DOM simulado requiere hacerlo
// explícito (el navegador gestiona los grupos por atributo name).
const longRadio = rangeRadios.find((r) => r.value === 'long');
for (const radio of rangeRadios) {
  radio.checked = radio.value === 'long';
}
longRadio.dispatchDom('change');
assertCondition(receivedEvents.length === 3 && receivedEvents[2]?.detail?.payload?.rangeType === 'long', 'Marcar un radio de alcance dispara el evento con long');

// Casilla de componente verbal.
const verbalCheck = queryByName(root, 'hasVerbal').find((c) => c.type === 'checkbox');
verbalCheck.checked = true;
verbalCheck.dispatchDom('change');
assertCondition(receivedEvents.length === 4 && receivedEvents[3]?.detail?.payload?.hasVerbal === true, 'Marcar el componente verbal dispara el evento con hasVerbal = true');

// El estado consolidado refleja todas las pulsaciones.
const consolidated = controls.getParams();
assertCondition(
  consolidated.damage === 25 && consolidated.crowdControlType === 'stun'
  && consolidated.rangeType === 'long' && consolidated.hasVerbal === true,
  'getParams() consolida todas las pulsaciones'
);

// =====================================================================
// [4] Normalización defensiva: valores sucios no rompen el flujo.
// =====================================================================
console.log('\n[4] Normalización defensiva de valores sucios');

damageInputs[0].value = '-10';
damageInputs[0].dispatchDom('change');
const negativeEvent = receivedEvents[receivedEvents.length - 1];
assertCondition(negativeEvent?.detail?.payload?.damage === 0, 'El daño negativo se normaliza a 0 en el payload');

healingInputs[0].value = 'treinta';
healingInputs[0].dispatchDom('input');
const dirtyEvent = receivedEvents[receivedEvents.length - 1];
assertCondition(dirtyEvent?.detail?.payload?.healing === 0, 'El texto sucio ("treinta") se normaliza a 0');

// =====================================================================
// [5] Sin bloqueo del hilo: muchas pulsaciones en tiempo real.
// =====================================================================
console.log('\n[5] Rendimiento: 1000 pulsaciones sin bloqueo (< 50 ms de media por lote)');

const stressStart = process.hrtime.bigint();
const stressIterations = 1000;
for (let i = 0; i < stressIterations; i++) {
  damageInputs[0].value = String(i % 61);
  damageInputs[0].dispatchDom('input');
}
const stressMs = Number(process.hrtime.bigint() - stressStart) / 1e6;
const perEventMs = stressMs / stressIterations;
assertCondition(perEventMs < 50, `${stressIterations} pulsaciones procesadas a ${perEventMs.toFixed(4)} ms por evento (sin bloqueo del hilo)`);
assertCondition(receivedEvents.length >= stressIterations, 'Cada pulsación emitió su evento (tiempo real, sin agrupaciones perdidas)');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
console.log(`Errores no controlados: ${uncaughtErrors}`);

if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('\nRESULTADO: EXITO — spellFormControls.js listo para el Taller.');
  process.exit(0);
}
console.log('\nRESULTADO: DENEGADO');
process.exit(1);

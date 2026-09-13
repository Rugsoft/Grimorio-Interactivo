/**
 * test_spell_creator_view.mjs — Arnés TDD de la Tarea 5.5 (TASKS-04).
 *
 * Verifica la vista principal del Taller de Hechizos
 * (public/assets/js/views/spellCreatorView.js) orquestando los módulos
 * de las Tareas 5.1-5.4:
 *   - Render en dos zonas: formulario de forja (spellFormControls) y
 *     panel de desglose pedagógico (manaBreakdownComponent).
 *   - Desglose EN VIVO: cada spell:params-changed recalcula con el
 *     simulador en cliente (Tarea 5.1) y actualiza el panel (< 50 ms).
 *   - Cajón de borradores: listDrafts() pinta la lista privada con
 *     acciones de carga y retirada; restauración del formulario.
 *   - Acciones: saveDraft (guardar), publishSpell (publicar) con sobres
 *     controlados y degradación elegante ante errores canónicos.
 *   - Almacenamiento temporal volátil en localStorage ante pérdida de
 *     red: el estado del formulario persiste y se restaura.
 *   - Seguridad: textContent en todo el renderizado (AGENTS.md 6.1).
 *
 * Criterio «Hecho cuando» (Tarea 5.5): el usuario puede diseñar un
 * conjuro viendo el coste fluctuar en vivo, guardarlo como borrador
 * privado, restaurarlo tras refrescar la página y publicarlo para
 * moderación.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo II: el coste vivo es PREVISUALIZACIÓN; la palabra final
 *     la tiene el backend (spellCreatorClient, Tarea 5.2).
 *   - Artículo IV/V: solemnidad castellana; identificadores camelCase.
 *
 * Uso: node scratch/test_spell_creator_view.mjs
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

/** Elemento DOM mínimo simulado (patrón consolidado del proyecto). */
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
    focusCount: 0,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount++; },
    dispatchDom(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, target: this, currentTarget: this, ...eventObject });
      }
    },
    dispatchEvent(domEvent) { return this.dispatchDom(domEvent.type, { detail: domEvent.detail }); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

/** Búsqueda recursiva por clase. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

/** Texto acumulado del subárbol (semántica del textContent real). */
function subtreeText(node) {
  let text = node.textContent ?? '';
  for (const child of node.children) {
    text += subtreeText(child);
  }
  return text;
}

/** LocalStorage simulado (ante pérdida de red / navegador restrictivo). */
function createFakeLocalStorage() {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => { map.set(key, String(value)); },
    removeItem: (key) => { map.delete(key); },
    clear: () => map.clear(),
  };
}

console.log('== VERIFICACION TAREA 5.5: spellCreatorView.js ==\n');

// --- Import del módulo bajo prueba (fase roja: no existe aún) ---
let spellCreatorView = null;
try {
  spellCreatorView = await import('../public/assets/js/views/spellCreatorView.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo spellCreatorView.js no pudo importarse: ${importError.message}`);
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 0');
  console.log('Asertos fallidos: 1');
  console.log('\nRESULTADO: FALLO — Fase roja: implementar public/assets/js/views/spellCreatorView.js');
  process.exit(1);
}

const { createSpellCreatorView } = spellCreatorView;

console.log('[0] Superficie');
assertCondition(typeof createSpellCreatorView === 'function', 'El módulo exporta createSpellCreatorView()');

// =====================================================================
// Escenario: vista montada con cliente simulado y localStorage fake.
// =====================================================================
const mountRoot = createFakeElement('main');

/** Cliente HTTP simulado con respuestas programables. */
function createFakeSpellCreatorClient() {
  return {
    calls: [],
    nextDraftList: { success: true, status: 200, data: [] },
    nextSaveResult: { success: true, status: 201, data: { id: 'spl_new1', slug: 'nuevo', status: 'draft' } },
    nextPublishResult: { success: true, status: 200, data: { status: 'experimental', signaturesCount: 0 } },
    async listDrafts() { this.calls.push('listDrafts'); return this.nextDraftList; },
    async saveDraft(payload) { this.calls.push(['saveDraft', payload]); return this.nextSaveResult; },
    async publishSpell(id) { this.calls.push(['publishSpell', id]); return this.nextPublishResult; },
    async deleteDraft(id) { this.calls.push(['deleteDraft', id]); return { success: true, status: 200, data: { id, deleted: true } }; },
    async updateDraft(id, payload) { this.calls.push(['updateDraft', id]); return { success: true, status: 200, data: { id } }; },
    async updateExperimental(id, payload) { this.calls.push(['updateExperimental', id]); return { success: true, status: 200, data: { id } }; },
    async createVariant(id) { this.calls.push(['createVariant', id]); return { success: true, status: 201, data: { id: 'spl_var' } }; },
    async calculateSpell(payload) { this.calls.push(['calculateSpell']); return { success: true, status: 200, data: {} }; },
  };
}

const fakeStorage = createFakeLocalStorage();
const client = createFakeSpellCreatorClient();

const view = createSpellCreatorView(mountRoot, {
  spellCreatorClient: client,
  localStorage: fakeStorage,
  elementFactory: createFakeElement,
});

// =====================================================================
// [1] Render: dos zonas (formulario + desglose) y cajón de borradores.
// =====================================================================
console.log('\n[1] Render del Taller (formulario + desglose + cajón)');

view.render();
// render() lanza refreshDrafts() de forma asíncrona: se espera el microtask.
await new Promise((resolve) => setImmediate(resolve));

assertCondition(queryByClass(mountRoot, 'spell-creator').length >= 1, 'La vista se monta con su raíz BEM spell-creator');
assertCondition(queryByClass(mountRoot, 'spell-form-controls').length === 1, 'La zona de forja porta el formulario (Tarea 5.4)');
assertCondition(queryByClass(mountRoot, 'mana-breakdown').length === 1, 'El panel de desglose pedagógico está presente (Tarea 5.3)');
assertCondition(queryByClass(mountRoot, 'spell-creator__drafts').length === 1, 'El cajón de borradores está presente');
assertCondition(queryByClass(mountRoot, 'spell-creator__save').length >= 1, 'Existe la acción de Guardar Borrador');
assertCondition(queryByClass(mountRoot, 'spell-creator__publish').length >= 1, 'Existe la acción de Publicar');
assertCondition(client.calls.some((entry) => entry === 'listDrafts' || (Array.isArray(entry) && entry[0] === 'listDrafts')), 'El cajón pide los borradores del autor al montar');

// =====================================================================
// [2] Desglose en vivo: cada pulsación fluctúa el coste (< 50 ms).
// =====================================================================
console.log('\n[2] Desglose en vivo: el coste fluctúa sin recargar');

// El caso canónico exige medium/sphere/instant con verbal+somático: se
// configuran los modificadores ANTES del daño (los radios del DOM simulado
// no se desmarcan solos).
function findInputByName(node, name, type) {
  for (const child of node.children) {
    if (child.name === name && (type === undefined || child.type === type)) return child;
    const found = findInputByName(child, name, type);
    if (found) return found;
  }
  return null;
}
function findAllByName(node, name, type, found = []) {
  for (const child of node.children) {
    if (child.name === name && (type === undefined || child.type === type)) found.push(child);
    findAllByName(child, name, type, found);
  }
  return found;
}

function selectRadio(mount, groupName, value) {
  for (const radio of findAllByName(mount, groupName, 'radio')) {
    radio.checked = radio.value === value;
  }
  const selected = findAllByName(mount, groupName, 'radio').find((r) => r.checked);
  selected.dispatchDom('change');
}

selectRadio(mountRoot, 'rangeType', 'medium');
selectRadio(mountRoot, 'areaType', 'sphere');
selectRadio(mountRoot, 'durationType', 'instant');
const verbalCheckbox = findAllByName(mountRoot, 'hasVerbal', 'checkbox')[0];
verbalCheckbox.checked = true;
verbalCheckbox.dispatchDom('change');
const somaticCheckbox = findAllByName(mountRoot, 'hasSomatic', 'checkbox')[0];
somaticCheckbox.checked = true;
somaticCheckbox.dispatchDom('change');

// El formulario emite: 30 de daño, medium/sphere, verbal+somático → 48.
const damageInput = findInputByName(mountRoot, 'damage', 'number');

const liveStart = process.hrtime.bigint();
damageInput.value = '30';
damageInput.dispatchDom('input');
const liveMs = Number(process.hrtime.bigint() - liveStart) / 1e6;

assertCondition(liveMs < 50, `El desglose reacciona en ${liveMs.toFixed(3)} ms (< 50 ms)`);
assertCondition(subtreeText(mountRoot).includes('48'), 'El coste en vivo muestra 48 (30 daño, medium/sphere, v+s)');
assertCondition(subtreeText(mountRoot).includes('Círculo III (Magister)'), 'El Círculo Arcano en vivo es el III (Magister)');

// Segunda pulsación: el coste FLUCTÚA (60 daño → sobrecarga visual).
damageInput.value = '150';
damageInput.dispatchDom('input');
assertCondition(queryByClass(mountRoot, 'mana-breakdown__overload').length === 1, 'Al superar 200, el cartel de Sobrecarga Arcana se activa en vivo');

// Vuelta a un valor sano.
damageInput.value = '30';
damageInput.dispatchDom('input');
assertCondition(subtreeText(mountRoot).includes('48'), 'El desglose vuelve a 48 al corregir el daño');

// =====================================================================
// [3] Guardar borrador: acción → saveDraft → cajón refrescado.
// =====================================================================
console.log('\n[3] Guardar como borrador privado (RF-05.1)');

// El formulario necesita un nombre: la vista porta su input de nombre.
const nameInput = (function findFirstByClass(node, className) {
  if (node.classes.has(className)) return node;
  for (const child of node.children) {
    const found = findFirstByClass(child, className);
    if (found) return found;
  }
  return null;
})(mountRoot, 'spell-creator__name');
assertCondition(nameInput !== null, 'La vista porta el campo de nombre del conjuro');

client.nextDraftList = {
  success: true,
  status: 200,
  data: [{ id: 'spl_new1', name: 'Esfera Ígnea', manaCost: 48, circleLabel: 'Círculo III (Magister)', updatedAt: '2026-09-13T21:00:00Z' }],
};

const saveButton = queryByClass(mountRoot, 'spell-creator__save')[0];
// El nombre del conjuro se escribe ANTES de guardar (el campo de la vista).
nameInput.value = 'Esfera Ígnea';
saveButton.dispatchDom('click');
await new Promise((resolve) => setImmediate(resolve));

const saveCall = client.calls.find(([name]) => name === 'saveDraft');
assertCondition(saveCall !== undefined, 'Guardar dispara saveDraft del cliente HTTP');
assertCondition(saveCall?.[1]?.name === 'Esfera Ígnea', 'El payload porta el nombre del conjuro');
assertCondition(typeof saveCall?.[1]?.damage === 'number' && saveCall[1].damage === 30, 'El payload porta los parámetros matemáticos del formulario');

// El borrador aparece en el cajón.
const draftItems = queryByClass(mountRoot, 'spell-creator__draft-item');
assertCondition(draftItems.length === 1, 'El borrador guardado aparece en el cajón');
assertCondition(subtreeText(draftItems[0] ?? mountRoot).includes('Esfera Ígnea'), 'El cajón muestra el nombre del borrador');
assertCondition(subtreeText(draftItems[0] ?? mountRoot).includes('Círculo III (Magister)'), 'El cajón muestra el círculo del borrador');

// =====================================================================
// [4] Restaurar: cargar un borrador rellena el formulario (refresco).
// =====================================================================
console.log('\n[4] Restaurar borrador tras refrescar (RF-06.1)');

const loadButton = draftItems[0] ? queryByClass(draftItems[0], 'spell-creator__draft-load')[0] : null;
assertCondition(loadButton !== null, 'Cada borrador porta su acción de carga');

client.nextDraftList = { success: true, status: 200, data: [] };
// La carga restaura el formulario (el cliente fake porta getSpellDraft? no:
// la vista usa los datos de la lista ya recibidos; simulamos un borrador
// con parámetros completos).
client.nextDraftList = {
  success: true,
  status: 200,
  data: [{
    id: 'spl_saved9', name: 'Lanza Restaurada', manaCost: 64, circleLabel: 'Círculo III (Magister)',
    updatedAt: '2026-09-13T21:30:00Z',
    elementalAffinity: 'fire', magicSchool: 'evocation', castingTime: 'action',
    description: 'Restaurada.',
    damage: 40, healing: 0, barrier: 0, crowdControlType: 'none',
    rangeType: 'medium', areaType: 'sphere', durationType: 'instant',
    hasVerbal: true, hasSomatic: true, hasMaterial: false,
  }],
};
await view.refreshDrafts();

const draftItemsAfter = queryByClass(mountRoot, 'spell-creator__draft-item');
const loadButton2 = draftItemsAfter[0] ? queryByClass(draftItemsAfter[0], 'spell-creator__draft-load')[0] : null;
loadButton2.dispatchDom('click');
await new Promise((resolve) => setImmediate(resolve));

assertCondition(subtreeText(mountRoot).includes('Lanza Restaurada'), 'El nombre restaurado aparece en la zona de forja');
// El formulario restaurado debe calcular 64 (40 daño × 2.0 − 20%).
assertCondition(subtreeText(mountRoot).includes('64'), 'El desglose en vivo recalcula el borrador restaurado (64)');

// =====================================================================
// [5] Publicar: acción → publishSpell con sobre controlado.
// =====================================================================
console.log('\n[5] Publicar para moderación (RF-05.2)');

const publishButton = queryByClass(mountRoot, 'spell-creator__publish')[0];
await publishButton.dispatchDom('click') ?? publishButton.listeners['click']?.[0]?.({ type: 'click' });
await new Promise((resolve) => setImmediate(resolve));

const publishCall = client.calls.find(([name]) => name === 'publishSpell');
assertCondition(publishCall !== undefined, 'Publicar dispara publishSpell del cliente HTTP');
assertCondition(publishCall?.[1] === 'spl_saved9', 'Publica el borrador cargado (id spl_saved9)');

// El estado de publicación se anuncia en la vista.
assertCondition(subtreeText(mountRoot).includes('experimental') || queryByClass(mountRoot, 'spell-creator__status').length >= 1, 'La vista anuncia el estado experimental tras publicar');

// =====================================================================
// [6] localStorage: respaldo volátil ante pérdida de red.
// =====================================================================
console.log('\n[6] Respaldo volátil en localStorage (pérdida de red)');

const storedSnapshot = fakeStorage.getItem('grimorio.spellCreatorDraft');
assertCondition(storedSnapshot !== null, 'Cada cambio de formulario persiste un respaldo en localStorage');
assertCondition(JSON.parse(storedSnapshot ?? '{}').damage === 40, 'El respaldo porta los parámetros vigentes del formulario (daño 40 del borrador restaurado)');

// Una vista NUEVA (simula refrescar la página) restaura el borrador volátil.
const mountRoot2 = createFakeElement('main');
const view2 = createSpellCreatorView(mountRoot2, {
  spellCreatorClient: createFakeSpellCreatorClient(),
  localStorage: fakeStorage,
  elementFactory: createFakeElement,
});
view2.render();
assertCondition(subtreeText(mountRoot2).includes('40'), 'Tras el refresco simulado, el respaldo restaura el desglose (daño 40)');

// =====================================================================
// [7] Degradación: errores canónicos no revientan la vista.
// =====================================================================
console.log('\n[7] Degradación elegante ante errores (403 cuota, red cortada)');

const failingClient = createFakeSpellCreatorClient();
failingClient.nextSaveResult = {
  success: false, status: 403,
  error: { code: 'DRAFT_QUOTA_EXCEEDED', message: 'La forja solo admite 10 borradores…', recoveryAction: 'DELETE_OR_PUBLISH_DRAFT' },
};
const mountRoot3 = createFakeElement('main');
const view3 = createSpellCreatorView(mountRoot3, {
  spellCreatorClient: failingClient,
  localStorage: createFakeLocalStorage(),
  elementFactory: createFakeElement,
});
view3.render();

const saveButton3 = queryByClass(mountRoot3, 'spell-creator__save')[0];
await saveButton3.dispatchDom('click') ?? saveButton3.listeners['click']?.[0]?.({ type: 'click' });
await new Promise((resolve) => setImmediate(resolve));

assertCondition(uncaughtErrors === 0, 'El error 403 de cuota no lanza excepción sin control');
assertCondition(subtreeText(mountRoot3).includes('DRAFT_QUOTA_EXCEEDED') || subtreeText(mountRoot3).includes('10 borradores'), 'La vista muestra el mensaje canónico del error de cuota');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
console.log(`Errores no controlados: ${uncaughtErrors}`);

if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('\nRESULTADO: EXITO — spellCreatorView.js listo para el enrutador.');
  process.exit(0);
}
console.log('\nRESULTADO: FALLO');
process.exit(1);

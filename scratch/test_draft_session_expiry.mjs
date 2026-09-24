/**
 * test_draft_session_expiry.mjs — Arnés del hueco de cobertura del
 * caso límite 3 de SPEC-03: «Expiración de la sesión durante la
 * redacción de un conjuro».
 *
 * El criterio del spec: si la sesión expira mientras se redacta un
 * conjuro, el cliente DEBE retener el borrador (memoria local) y
 * permitir reanudar la acción sin pérdida de texto.
 *
 * La auditoría de estabilización halló que el respaldo del Taller en
 * localStorage estaba probado ante pérdida de red (Fase 6 de
 * test_spell_creator_view) pero NADIE ejercitaba la secuencia completa
 * del caso límite: 401 del backend → respaldo intacto → reanudación del
 * vínculo → restauración del texto sin pérdida.
 *
 * Escenarios verificados:
 *   [1] Redacción en curso: el texto y los parámetros viajan al
 *       respaldo en CADA pulsación (materia del caso límite).
 *   [2] El 401 del backend (listDrafts con sesión expirada) NO destruye
 *       el respaldo: la vista narra el corte y el borrador sigue vivo.
 *   [3] El 401 tampoco corrompe el estado del formulario: lo escrito
 *       permanece en los campos (sin blanqueo).
 *   [4] Reanudación: tras re-vincular, la misma vista restaura el
 *       respaldo íntegro (nombre, descripción, daño, componentes).
 *   [5] Guardar tras reanudar envía el TEXTO RESTAURADO (cero pérdida).
 *   [6] El guardado del respaldo sobrevive a un localStorage caprichoso
 *       (cuota llena): la vista no explota y el taller sigue operativo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): arnés nativo Node con DOM simulado.
 *   - Artículo V: identificadores en inglés camelCase; asertos castellanos.
 *
 * Uso: node scratch/test_draft_session_expiry.mjs
 */

import { createSpellCreatorView } from '../public/assets/js/views/spellCreatorView.js';

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
    _value: '',
    _checked: false,
    parentElement: null,
    disabled: false,
    type: '',
    name: '',
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`; },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    get value() { return this._value; },
    set value(v) { this._value = String(v); },
    get checked() { return this._checked; },
    set checked(c) { this._checked = Boolean(c); },
    focus() {},
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;

function queryByAttribute(node, attribute, value, found = []) {
  for (const child of node.children) {
    if (child.getAttribute(attribute) === value) found.push(child);
    queryByAttribute(child, attribute, value, found);
  }
  return found;
}

/** LocalStorage simulado (inyectable, con modo avería opcional). */
function createFakeLocalStorage({ broken = false } = {}) {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => {
      if (broken) throw new Error('QuotaExceededError: el respaldo no cabe');
      map.set(key, String(value));
    },
    removeItem: (key) => { map.delete(key); },
    clear: () => map.clear(),
  };
}

/** Localiza un input por atributo name dentro del subárbol. */
function findInputByName(node, name, type) {
  for (const child of node.children) {
    if (child.name === name && (type === undefined || child.type === type)) return child;
    const found = findInputByName(child, name, type);
    if (found) return found;
  }
  return null;
}

/** Localiza un nodo por clase (el Taller identifica sus campos por clase/id). */
function findInputByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    findInputByClass(child, className, found);
  }
  return found;
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
  const selected = findAllByName(mount, groupName, 'radio').find((radio) => radio.checked);
  selected.dispatch('change');
}

/** Cliente HTTP simulado con sesión programable. */
function createFakeSessionClient() {
  return {
    calls: [],
    sessionAlive: true,
    nextSaveResult: { success: true, status: 201, data: { id: 'spl_reanudado', slug: 'reanudado', status: 'draft' } },
    async listDrafts() {
      this.calls.push('listDrafts');
      // Sesión expirada: el backend responde 401 UNAUTHENTICATED
      // (contrato real del SpellCreatorController).
      if (!this.sessionAlive) {
        return {
          success: false,
          status: 401,
          error: { code: 'UNAUTHENTICATED', message: 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de forjar conjuros.' },
        };
      }
      return { success: true, status: 200, data: [] };
    },
    async saveDraft(payload) {
      this.calls.push(['saveDraft', payload]);
      if (!this.sessionAlive) {
        return { success: false, status: 401, error: { code: 'UNAUTHENTICATED', message: 'El vínculo arcano no está activo.' } };
      }
      return this.nextSaveResult;
    },
    async updateDraft(id) { this.calls.push(['updateDraft', id]); return { success: true, status: 200, data: { id } }; },
    async deleteDraft(id) { this.calls.push(['deleteDraft', id]); return { success: true, status: 200, data: { id } }; },
    async publishSpell(id) { this.calls.push(['publishSpell', id]); return { success: true, status: 200, data: { status: 'experimental' } }; },
    async updateExperimental(id) { this.calls.push(['updateExperimental', id]); return { success: true, status: 200, data: { id } }; },
    async createVariant(id) { this.calls.push(['createVariant', id]); return { success: true, status: 201, data: { id } }; },
    async calculateSpell() { this.calls.push('calculateSpell'); return { success: true, status: 200, data: {} }; },
  };
}

/** Monta una vista nueva con el respaldo dado y devuelve sus nodos clave. */
function mountView(storage, client) {
  const mountRoot = createFakeElement('main');
  const view = createSpellCreatorView(mountRoot, {
    spellCreatorClient: client,
    localStorage: storage,
    elementFactory: createFakeElement,
  });
  view.render();
  return { mountRoot, view };
}

const STORAGE_KEY = 'grimorio.spellCreatorDraft';
const DRAFT_NAME = 'Lamento de la Marea';
const DRAFT_DESCRIPTION = 'Un conjuro escrito a medias cuando el vínculo se apagó.';

console.log('== ARNES caso límite 3 (SPEC-03): el borrador sobrevive a la expiración del vínculo ==\n');

// ---------------------------------------------------------------------
// [1] Redacción en curso: cada pulsación respalda el texto y los parámetros.
// ---------------------------------------------------------------------
console.log('[1] Redacción en curso con respaldo en cada pulsación');

const client1 = createFakeSessionClient();
const storage1 = createFakeLocalStorage();
const session1 = mountView(storage1, client1);

const nameInput1 = findInputByClass(session1.mountRoot, 'spell-creator__name')[0] ?? null;
const descriptionInput1 = findInputByClass(session1.mountRoot, 'spell-creator__description')[0] ?? null;
assertCondition(nameInput1 !== null && descriptionInput1 !== null, 'La forja porta el nombre y la descripción narrativa');

nameInput1.value = DRAFT_NAME;
nameInput1.dispatch('input');
descriptionInput1.value = DRAFT_DESCRIPTION;
descriptionInput1.dispatch('input');

const damageInput1 = findInputByName(session1.mountRoot, 'damage', 'number');
damageInput1.value = '30';
damageInput1.dispatch('input');

const snapshot1 = JSON.parse(storage1.getItem(STORAGE_KEY));
assertCondition(snapshot1?.name === DRAFT_NAME, 'El nombre del conjuro vive en el respaldo tras la pulsación');
assertCondition(snapshot1?.description === DRAFT_DESCRIPTION, 'La descripción narrativa vive en el respaldo');
assertCondition(snapshot1?.damage === 30, 'Los parámetros matemáticos viajan en el respaldo');

// ---------------------------------------------------------------------
// [2] El 401 del backend NO destruye el respaldo.
// ---------------------------------------------------------------------
console.log('\n[2] La sesión expira: el 401 jamás borra el borrador retenido');

// La sesión muere EN SERVIDOR: el siguiente listado de borradores
// responde 401 (p. ej. al refrescar el cajón tras un gesto).
client1.sessionAlive = false;
await session1.view.refreshDrafts();
await new Promise((resolve) => setImmediate(resolve));

const snapshotAfter401 = JSON.parse(storage1.getItem(STORAGE_KEY));
assertCondition(snapshotAfter401 !== null, 'El respaldo sigue vivo tras el 401 (la vista no lo limpia)');
assertCondition(snapshotAfter401?.name === DRAFT_NAME, 'El nombre sobrevive al corte de sesión');
assertCondition(snapshotAfter401?.description === DRAFT_DESCRIPTION, 'La descripción sobrevive al corte');

// ---------------------------------------------------------------------
// [3] El 401 no blanquea el formulario vivo.
// ---------------------------------------------------------------------
console.log('\n[3] El formulario vivo conserva lo escrito pese al 401');

assertCondition(nameInput1.value === DRAFT_NAME, 'El nombre permanece en el campo tras el 401');
assertCondition(descriptionInput1.value === DRAFT_DESCRIPTION, 'La descripción permanece en el campo tras el 401');
assertCondition(
  byClass(session1.mountRoot, 'spell-creator__status')?.textContent.includes('borradores') === true
    || byClass(session1.mountRoot, 'spell-creator__status--error') !== null,
  'La línea de estado narra el corte del cajón sin quedar muda',
);
assertCondition(uncaughtErrors === 0, 'El 401 no lanza excepción sin control');

// ---------------------------------------------------------------------
// [4] Reanudación: nueva vista (página recargada tras re-vincular)
//     restaura el respaldo íntegro.
// ---------------------------------------------------------------------
console.log('\n[4] Reanudación tras re-vincular: restauración íntegra del borrador');

const client2 = createFakeSessionClient(); // El vínculo renace: listDrafts 200.
const storage2 = createFakeLocalStorage();
// El navegador real conserva localStorage entre recargas: el respaldo de
// la sesión expirada está donde la vista nueva lo leerá.
storage2.setItem(STORAGE_KEY, storage1.getItem(STORAGE_KEY));

const session2 = mountView(storage2, client2);
await new Promise((resolve) => setImmediate(resolve)); // render() lanza refreshDrafts asíncrono.

const nameInput2 = findInputByClass(session2.mountRoot, 'spell-creator__name')[0] ?? null;
const descriptionInput2 = findInputByClass(session2.mountRoot, 'spell-creator__description')[0] ?? null;
const damageInput2 = findInputByName(session2.mountRoot, 'damage', 'number');

assertCondition(nameInput2.value === DRAFT_NAME, 'El nombre se restaura sin pérdida');
assertCondition(descriptionInput2.value === DRAFT_DESCRIPTION, 'La descripción narrativa se restaura sin pérdida');
assertCondition(Number(damageInput2.value) === 30, 'El daño del borrador se restaura (parámetro matemático)');
assertCondition(uncaughtErrors === 0, 'La restauración no lanza excepción alguna');

// ---------------------------------------------------------------------
// [5] Guardar tras reanudar envía el TEXTO RESTAURADO (cero pérdida).
// ---------------------------------------------------------------------
console.log('\n[5] El guardado tras reanudar porta el texto restaurado');

const saveButton2 = queryByClass(session2.mountRoot, 'spell-creator__save')[0];
saveButton2.dispatch('click');
await new Promise((resolve) => setImmediate(resolve));

const saveCall = client2.calls.find((entry) => Array.isArray(entry) && entry[0] === 'saveDraft');
assertCondition(saveCall !== undefined, 'El gesto de guardar llamó al backend');
assertCondition(saveCall?.[1]?.name === DRAFT_NAME, 'El payload porta el nombre restaurado (sin pérdida de texto)');
assertCondition(saveCall?.[1]?.description === DRAFT_DESCRIPTION, 'El payload porta la descripción restaurada');
assertCondition(saveCall?.[1]?.damage === 30, 'El payload porta el daño restaurado');
assertCondition(uncaughtErrors === 0, 'El ciclo completo 401 → reanudación → guardado no explota');

// ---------------------------------------------------------------------
// [6] localStorage averiado: el taller sigue operativo (degradación).
// ---------------------------------------------------------------------
console.log('\n[6] Respaldo averiado (cuota llena): el taller no se rompe');

const client3 = createFakeSessionClient();
const brokenStorage = createFakeLocalStorage({ broken: true });
const session3 = mountView(brokenStorage, client3);
await new Promise((resolve) => setImmediate(resolve));

const nameInput3 = findInputByClass(session3.mountRoot, 'spell-creator__name')[0] ?? null;
nameInput3.value = 'Conjuro sin respaldo';
nameInput3.dispatch('input');
assertCondition(uncaughtErrors === 0, 'El fallo de cuota del respaldo no lanza excepción');
assertCondition(findInputByClass(session3.mountRoot, 'spell-creator__name')[0].value === 'Conjuro sin respaldo', 'El campo sigue editando sin respaldo (el respaldo es copia, jamás fuente de verdad)');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
console.log(`Errores no controlados: ${uncaughtErrors}`);

if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('\nRESULTADO: EXITO — El borrador retiene el vínculo expirado y se reanuda sin pérdida (caso límite 3).');
  process.exit(0);
}
console.log('\nRESULTADO: DENEGADO');
process.exit(1);

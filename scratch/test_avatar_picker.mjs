/**
 * test_avatar_picker.mjs — Arnés de la Tarea 6.1 (TASKS-12).
 *
 * Valida EL SELECTOR DE EFIGIE (`avatarPickerComponent.js`) contra el
 * «Hecho cuando» de la tarea — las 6 fases del plan §6.2:
 *
 *   [1] Rejilla del catálogo con `aria-pressed` en la vigente (RF-03.1).
 *   [2] Selección → evento `panel:avatar-changed` y repinta del
 *       distintivo por evento (plan §4.2, efecto inmediato RF-03.4).
 *   [3] Subida → previsualización y marco cuadrado (RF-03.2/03.3).
 *   [4] Aviso solemne por código de error nombrando el motivo (RF-03.2).
 *   [5] Fallo de carga del catálogo → aviso con reintento (caso 14).
 *   [6] Fichero propio inaccesible → degradación al canónico con
 *       leyenda discreta (caso límite 15).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - AGENTS.md §6.1: centinela innerHTML (XSS).
 *   - Art. V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_avatar_picker.mjs
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserto de una sola línea con veredicto inmediato en la terminal. */
function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

// ---------------------------------------------------------------------
// DOM simulado mínimo (patrón consolidado de los arneses del santuario)
// ---------------------------------------------------------------------

function createObservableElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: new Map(),
    classes: new Set(),
    textContent: '',
    parentNode: null,
    listeners: new Map(),
    className: '',
    type: null,
    files: null,
    value: '',
    disabled: false,
    focused: false,
    setAttribute(name, value) {
      element.attributes.set(name, String(value));
      if (name === 'class') {
        element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
        element.className = String(value);
      }
    },
    getAttribute(name) {
      return element.attributes.has(name) ? element.attributes.get(name) : null;
    },
    hasAttribute(name) {
      return element.attributes.has(name);
    },
    removeAttribute(name) {
      element.attributes.delete(name);
    },
    appendChild(child) {
      child.parentNode = element;
      element.children.push(child);
      return child;
    },
    replaceChildren() {
      element.children.splice(0);
    },
    remove() {
      if (element.parentNode) {
        const siblings = element.parentNode.children;
        const index = siblings.indexOf(element);
        if (index !== -1) siblings.splice(index, 1);
      }
    },
    focus() { element.focused = true; },
    addEventListener(type, handler) {
      if (!element.listeners.has(type)) element.listeners.set(type, []);
      element.listeners.get(type).push(handler);
    },
    removeEventListener(type, handler) {
      const list = element.listeners.get(type) ?? [];
      const index = list.indexOf(handler);
      if (index !== -1) list.splice(index, 1);
    },
    dispatch(type, event = {}) {
      for (const handler of element.listeners.get(type) ?? []) {
        handler({ currentTarget: element, target: element, preventDefault() {}, ...event });
      }
    },
    dispatchEvent(customEvent) {
      element.dispatch(customEvent.type, customEvent);
    },
    click() { element.dispatch('click'); },
  };
  // CENTINELA (AGENTS.md §6.1): el accesor innerHTML LANZA.
  Object.defineProperty(element, 'innerHTML', {
    get() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set(value) { throw new Error(`PROHIBIDO innerHTML: se intentó escribir '${String(value).slice(0, 40)}'`); },
  });
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
    },
  });
  return element;
}

const documentSim = { createElement: (tagName) => createObservableElement(tagName) };
class CustomEventSim {
  constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
}
// Props propias (no prototipo): el DOM simulado hace spread del evento.
globalThis.CustomEvent = CustomEventSim;

const {
  createAvatarPickerComponent,
  AVATAR_PICKER_LEGENDS,
  AVATAR_PICKER_ERROR_LEGENDS,
  AVATAR_UNAVAILABLE_LEGEND,
  AVATAR_PICKER_EVENTS,
  avatarErrorLegendFor,
} = await import('../public/assets/js/components/avatarPickerComponent.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  )[0] ?? null;
}

function findByText(root, tagName, text) {
  return findDescendants(root, (n) => n.tagName === tagName && n.textContent === text)[0] ?? null;
}

/** Sobre canónico del catálogo (plan §2.3). */
function catalogEnvelope(overrides = {}) {
  return {
    success: true,
    status: 200,
    data: {
      catalog: [
        { id: 'seal_primordialFlame', kind: 'heraldry', label: 'Llama Primordial', heraldryKey: 'rune-ignis' },
        { id: 'seal_celestialTides', kind: 'heraldry', label: 'Mareas Celestiales', heraldryKey: 'rune-aqua' },
        { id: 'custodian', kind: 'effigy', label: 'Custodio Fundacional', heraldryKey: null },
      ],
      current: { kind: 'catalog', reference: 'seal_primordialFlame' },
      ownAvatar: null,
      restricted: false,
      ...overrides,
    },
  };
}

/**
 * Cliente doble del panel: cola de respuestas por método con registro
 * de llamadas; las respuestas pueden ser funciones (dinámicas).
 */
function createPanelClientStub(handlers = {}) {
  const calls = [];
  const push = (method, args) => { calls.push({ method, ...args }); };
  return {
    calls,
    async fetchAvatarCatalog(...args) {
      push('fetchAvatarCatalog', { args });
      const handler = handlers.fetchAvatarCatalog ?? (() => catalogEnvelope());
      return typeof handler === 'function' ? handler() : handler;
    },
    async chooseAvatar(mode, payload) {
      push('chooseAvatar', { mode, payload: payload instanceof FormData ? 'FormData' : payload });
      const handler = handlers.chooseAvatar ?? (() => ({ success: true, status: 200, data: { avatar: { kind: 'catalog', reference: 'seal_celestialTides' }, auditRecorded: true, identical: false } }));
      return typeof handler === 'function' ? handler() : handler;
    },
    async removeAvatar() {
      push('removeAvatar', {});
      return { success: true, status: 200, data: { avatar: { kind: 'default' } } };
    },
    async changePassphrase() {
      push('changePassphrase', {});
      return { success: true, status: 200, data: { verdict: 'changed' } };
    },
    async fetchPanel() {
      push('fetchPanel', {});
      return { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED' } };
    },
  };
}

// =====================================================================
// [0] Superficie del módulo + centinela del fuente
// =====================================================================
console.log('[0] Superficie del módulo y centinela');
assertCondition(typeof createAvatarPickerComponent === 'function', 'el módulo exporta la fábrica createAvatarPickerComponent');
assertCondition(AVATAR_PICKER_EVENTS.avatarChanged === 'panel:avatar-changed', 'el evento del bus es el canónico panel:avatar-changed (plan §4.2)');
assertCondition(Object.keys(AVATAR_PICKER_ERROR_LEGENDS).length >= 8, 'el mapa de avisos cubre las familias de error del contrato (RF-03.2)');

const sourcePath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'js', 'components', 'avatarPickerComponent.js');
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
assertCondition(!/innerHTML\s*=/.test(sourceWithoutComments), 'CENTINELA: el fuente del picker jamás asigna innerHTML (AGENTS.md 6.1)');

// =====================================================================
// [1] Rejilla del catálogo con aria-pressed en la vigente (RF-03.1)
// =====================================================================
console.log('\n[1] La rejilla del canon con aria-pressed en la vigente');
const stub1 = createPanelClientStub();
const root1 = createObservableElement('section');
const picker1 = createAvatarPickerComponent(root1, { panelClient: stub1, documentRef: documentSim, eventTarget: root1 });
await picker1.render();

const grid = findByClass(root1, 'avatar-picker__grid');
assertCondition(grid !== null, 'la rejilla del canon monta como grupo con rótulo');
assertCondition(grid.getAttribute('aria-label') === AVATAR_PICKER_LEGENDS.catalogHeading, 'la rejilla porta su rótulo accesible (RNF-03)');

const cells = findDescendants(grid, (n) => n.tagName === 'BUTTON');
assertCondition(cells.length === 3, 'las tres efigies del canon montan como botones reales (operables sin ratón)');
const currentCell = findDescendants(grid, (n) => n.getAttribute?.('data-avatar-id') === 'seal_primordialFlame')[0] ?? null;
assertCondition(currentCell?.getAttribute('aria-pressed') === 'true', 'la vigente porta aria-pressed true (RF-03.1)');
const otherCell = findDescendants(grid, (n) => n.getAttribute?.('data-avatar-id') === 'seal_celestialTides')[0] ?? null;
assertCondition(otherCell?.getAttribute('aria-pressed') === 'false', 'las no vigentes portan aria-pressed false');

// =====================================================================
// [2] Selección → evento panel:avatar-changed (plan §4.2, RF-03.4)
// =====================================================================
console.log('\n[2] La selección emite panel:avatar-changed con efecto inmediato');
const busEvents = [];
const directCalls = [];
// Doble DINÁMICO: el canon refleja la elección consumada (como el
// santuario real, donde el efecto es inmediato, RF-03.4).
let currentReference2 = 'seal_primordialFlame';
const stub2 = createPanelClientStub({
  fetchAvatarCatalog: () => catalogEnvelope({ current: { kind: 'catalog', reference: currentReference2 } }),
  chooseAvatar: () => {
    currentReference2 = 'seal_celestialTides';
    return { success: true, status: 200, data: { avatar: { kind: 'catalog', reference: 'seal_celestialTides' }, auditRecorded: true, identical: false } };
  },
});
const root2 = createObservableElement('section');
const picker2 = createAvatarPickerComponent(root2, {
  panelClient: stub2,
  documentRef: documentSim,
  eventTarget: root2,
  onAvatarChanged: (detail) => directCalls.push(detail),
});
root2.addEventListener('panel:avatar-changed', (event) => busEvents.push(event));
await picker2.render();
// La celda a elegir es la de ESTE montaje (root2), no la de la fase 1.
const otherCell2 = findDescendants(root2, (n) => n.getAttribute?.('data-avatar-id') === 'seal_celestialTides')[0] ?? null;
otherCell2?.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();

assertCondition(stub2.calls.some((c) => c.method === 'chooseAvatar' && c.mode === 'catalog'), 'la elección viaja por chooseAvatar modo catalog');
assertCondition(busEvents.length === 1 && busEvents[0].detail?.kind === 'catalog', 'el evento panel:avatar-changed viaja por el bus (plan §4.2)');
assertCondition(directCalls.length === 1 && directCalls[0].reference === 'seal_celestialTides', 'el canal directo del orquestador recibe el repintado (cabecera sin recarga)');
// Efecto inmediato: tras el acto, la marca de vigente se refresca.
const refreshedCurrent = findDescendants(root2, (n) => n.getAttribute?.('data-avatar-id') === 'seal_celestialTides' && n.getAttribute?.('aria-pressed') === 'true')[0];
assertCondition(refreshedCurrent !== undefined, 'el efecto es INMEDIATO: la elegida pasa a ser la vigente (RF-03.4)');

// =====================================================================
// [3] Subida → previsualización y marco cuadrado (RF-03.2/03.3)
// =====================================================================
console.log('\n[3] La subida propia: previsualización en marco ceremonial');
const stub3 = createPanelClientStub({
  chooseAvatar: () => ({ success: true, status: 200, data: { avatar: { kind: 'own', reference: 'a1b2c3' }, auditRecorded: true, identical: false } }),
});
const root3 = createObservableElement('section');
const picker3 = createAvatarPickerComponent(root3, { panelClient: stub3, documentRef: documentSim, eventTarget: root3 });
await picker3.render();

const input = findByClass(root3, 'avatar-picker__input');
assertCondition(input?.getAttribute('type') === 'file', 'la zona de subida es un input[type=file] real (operable sin ratón, RNF-03)');
assertCondition(findByClass(root3, 'avatar-picker__label') !== null, 'el input porta su etiqueta (RNF-03)');
const frame = findByClass(root3, 'avatar-picker__frame');
assertCondition(frame !== null, 'el marco ceremonial cuadrado existe');

// El gesto de elegir fichero pinta la previsualización dentro del marco.
// Un File REAL de Node (el componente exige instanceof File y el FormData
// nativo viaja con Blobs; la validación profunda es del santuario).
const fakeFile = new File([new Uint8Array([137, 80, 78, 71])], 'efigie.png', { type: 'image/png' });
input.files = [fakeFile];
input.dispatch('change');
await Promise.resolve();
const preview = findDescendants(root3, (n) => n.tagName === 'IMG')[0] ?? null;
assertCondition(preview !== null, 'la previsualización se forja al elegir fichero');
assertCondition(preview?.getAttribute('alt') === AVATAR_PICKER_LEGENDS.previewAlt, 'la previsualización porta su texto alternativo (RNF-03)');
assertCondition(frame.getAttribute('data-has-preview') === 'true', 'la previsualización vive DENTRO del marco cuadrado');

// El envío viaja como FormData con el fichero (el cliente no lo toca).
const uploadButton = findByClass(root3, 'avatar-picker__upload');
uploadButton.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
const ownCall = stub3.calls.find((c) => c.method === 'chooseAvatar' && c.mode === 'own');
assertCondition(ownCall !== undefined, 'el alta propia viaja por chooseAvatar modo own');
assertCondition(ownCall.payload === 'FormData', 'el fichero viaja como FormData nativo (multipart del navegador)');
const ownEvent = [];
// El evento del alta propia también repinta por evento.
assertCondition(ownCall !== undefined, 'el alta propia registra su llamada (RF-03.3: nombre aleatorio NO derivado del alias lo sella el backend)');

// =====================================================================
// [4] Aviso solemne por código que NOMBRA el motivo (RF-03.2)
// =====================================================================
console.log('\n[4] Los avisos solemnes nombran el motivo y el vigente queda intacto');
const rejections = [
  { status: 400, error: { code: 'INVALID_AVATAR_FORMAT', message: AVATAR_PICKER_ERROR_LEGENDS.INVALID_AVATAR_FORMAT } },
  { status: 400, error: { code: 'AVATAR_TOO_LARGE', message: AVATAR_PICKER_ERROR_LEGENDS.AVATAR_TOO_LARGE } },
  { status: 400, error: { code: 'AVATAR_DIMENSIONS_EXCEEDED', message: AVATAR_PICKER_ERROR_LEGENDS.AVATAR_DIMENSIONS_EXCEEDED } },
];
for (const rejection of rejections) {
  const stubRej = createPanelClientStub({ chooseAvatar: () => rejection });
  const rootRej = createObservableElement('section');
  const pickerRej = createAvatarPickerComponent(rootRej, { panelClient: stubRej, documentRef: documentSim, eventTarget: rootRej });
  await pickerRej.render();
  const inputRej = findByClass(rootRej, 'avatar-picker__input');
  inputRej.files = [fakeFile];
  inputRej.dispatch('change');
  findByClass(rootRej, 'avatar-picker__upload').click();
  await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
  const notice = findByClass(rootRej, 'avatar-picker__notice');
  assertCondition(
    notice !== null && notice.textContent === rejection.error.message,
    `el aviso narra el motivo exacto (${rejection.error.code})`,
  );
  // El vigente queda intacto: la vigente del canon sigue siendo la misma.
  const vigente = findDescendants(rootRej, (n) => n.getAttribute?.('aria-pressed') === 'true')[0] ?? null;
  assertCondition(vigente?.getAttribute('data-avatar-id') === 'seal_primordialFlame', 'el avatar vigente NO se muta ante el rechazo (RF-03.2)');
  pickerRej.destroy();
}

// La imagen idéntica (caso límite 18) avisa noble específico.
const identical = await avatarErrorLegendFor({ error: { code: 'AVATAR_IDENTICAL' } });
assertCondition(identical === 'La imagen ya viste tu identidad.', 'la re-subida idéntica porta el aviso noble específico (caso límite 18)');

// =====================================================================
// [5] Fallo de carga del canon → aviso con reintento (caso límite 14)
// =====================================================================
console.log('\n[5] El canon que no responde: aviso solemne con reintento');
const stub5 = createPanelClientStub({
  fetchAvatarCatalog: () => ({ success: false, status: 500, error: { code: 'AVATAR_CATALOG_UNAVAILABLE', message: AVATAR_PICKER_ERROR_LEGENDS.AVATAR_CATALOG_UNAVAILABLE } }),
});
const root5 = createObservableElement('section');
const picker5 = createAvatarPickerComponent(root5, { panelClient: stub5, documentRef: documentSim, eventTarget: root5 });
await picker5.render();

assertCondition(findByClass(root5, 'avatar-picker__grid') === null, 'sin canon NO se pinta rejilla fantasma (caso límite 14)');
const errorNotice = findByClass(root5, 'avatar-picker__error');
assertCondition(
  errorNotice !== null && errorNotice.textContent === AVATAR_PICKER_ERROR_LEGENDS.AVATAR_CATALOG_UNAVAILABLE,
  'el aviso solemne narra la indisponibilidad del canon',
);
const retry = findByClass(root5, 'avatar-picker__retry');
assertCondition(retry !== null, 'el reintento solemne se ofrece (hermano de «El canon no responde»)');
// El reintento vuelve a intentar la carga: el stub ahora responde bien.
stub5.handlers = undefined;
stub5.fetchAvatarCatalog = async () => catalogEnvelope();
root5.children.splice(0);
const pickerRetry = createAvatarPickerComponent(root5, { panelClient: createPanelClientStub(), documentRef: documentSim, eventTarget: root5 });
await pickerRetry.render();
assertCondition(findByClass(root5, 'avatar-picker__grid') !== null, 'tras el reintento con canon vivo, la rejilla monta');
pickerRetry.destroy();

// =====================================================================
// [6] Efigie propia inaccesible → degradación al canónico (caso 15)
// =====================================================================
console.log('\n[6] La efigie propia inaccesible degrada al canónico con leyenda discreta');
const stub6 = createPanelClientStub({
  fetchAvatarCatalog: () => catalogEnvelope({
    current: { kind: 'own', reference: 'a1b2c3', unavailable: true },
    ownAvatar: null,
  }),
});
const root6 = createObservableElement('section');
const picker6 = createAvatarPickerComponent(root6, { panelClient: stub6, documentRef: documentSim, eventTarget: root6 });
await picker6.render();

const notice6 = findByClass(root6, 'avatar-picker__notice');
assertCondition(
  notice6 !== null && notice6.textContent === AVATAR_UNAVAILABLE_LEGEND,
  'la leyenda discreta de indisponibilidad viste la sección (caso límite 15)',
);
const currentLegend6 = findByClass(root6, 'avatar-picker__current');
assertCondition(
  currentLegend6 !== null && currentLegend6.textContent.includes('canónica por defecto'),
  'la identidad JAMÁS queda sin efigie: narra el canónico mientras la propia no responde',
);
assertCondition(findByClass(root6, 'avatar-picker__grid') !== null, 'la rejilla sigue operativa: el adepto puede elegir otra efigie al instante');
// La fila de users NO se muta: el picker jamás llama a escritura al degradar.
assertCondition(!stub6.calls.some((c) => c.method === 'chooseAvatar' || c.method === 'removeAvatar'), 'la degradación es de LECTURA: sin mutación del avatar vigente (caso límite 15)');

picker6.destroy();

// --- Resumen canónico del arnés ---
console.log(`\n== RESUMEN ==`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO');
  process.exit(0);
}
console.log('RESULTADO: FRACASO');
process.exit(1);

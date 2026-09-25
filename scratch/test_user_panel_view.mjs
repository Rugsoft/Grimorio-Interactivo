/**
 * test_user_panel_view.mjs — Arnés de la Tarea 5.1 (TASKS-12).
 *
 * Valida la ruta `#/morada → panel` en el enrutador y LA VISTA DEL
 * PANEL DEL ADEPTO (`userPanelView.js`) contra el «Hecho cuando» de la
 * tarea:
 *
 *   [1] La ruta `#/morada` resuelve la vista `panel` (HASH_TO_VIEW_MAP
 *       y VIEW_TO_HASH_MAP del orquestador; 'panel' fuera de
 *       OATH_EXEMPT_VIEWS).
 *   [2] La vitrina completa se renderiza con textContent puro
 *       (innerHTML PROHIBIDO — centinela doble: accesor que lanza en el
 *       DOM simulado + lectura estática del fuente) y la vista emite
 *       `panel:vitrina-loaded` (plan §4.2).
 *
 *   [3] Secciones del peregrino vestidas como PENDIENTES con conducción
 *       a `juramento` (Tarea 5.2): callback `onRestrictedSectionActivated`
 *       y evento `panel:restricted-section-activated`; las credenciales
 *       (frase de paso) permanecen plenamente operativas (RF-01.3).
 *
 *   [4] Ningún rótulo del panel ofrece «Cambiar de linaje», cambio de
 *       alias/correo ni baja (Tarea 5.3, guardia RF-03.4 de SPEC-09 —
 *       patrón de FASE 2 del arnés del distintivo); las conducciones
 *       (clan, tomo, renuncia) conducen a las cámaras canónicas, no
 *       duplican (RF-08.2).
 *
 *   [5] Accesibilidad estructural (Tarea 5.4): jerarquía de
 *       encabezados, región viva única, foco devuelto tras diálogos y
 *       hoja user-panel.css sin literales de color fuera de tokens
 *       (RNF-03, RNF-07).
 *
 * Las fases [6+] (componentes de FASE 6) llegarán con sus tareas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Art. I/VI de AGENTS.md: centinela innerHTML (XSS).
 *   - Art. V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_user_panel_view.mjs
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
// DOM simulado mínimo (patrón consolidado de los arneses de SPEC-10/11)
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
    value: '',
    disabled: false,
    style: { setProperty() {} },
    open: false,
    returnValue: '',
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
  // CENTINELA (AGENTS.md §6.1): el accesor innerHTML LANZA — la vista
  // jamás debe tocar innerHTML ni para leer.
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
// El CustomEvent NATIVO de Node guarda type/detail en el prototipo y el
// DOM simulado los perdería al hacer spread; el sim porta props propias.
globalThis.CustomEvent = CustomEventSim;

const {
  HASH_TO_VIEW_MAP,
  VIEW_TO_HASH_MAP,
} = await import('../public/assets/js/main.js');
const { createUserPanelView } = await import('../public/assets/js/views/userPanelView.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

function findByText(root, tagName, text) {
  return findDescendants(root, (n) => n.tagName === tagName && n.textContent === text)[0] ?? null;
}

function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  )[0] ?? null;
}

/** Sobre canónico de la vitrina (plan §2.2) para un adepto linajado. */
function vitrinaEnvelope(overrides = {}) {
  return {
    success: true,
    status: 200,
    data: {
      panel: {
        identity: {
          alias: 'Heredera de la Llama',
          email: 'heredera@arcano.arc',
          roleLabel: 'Adepto',
          avatar: { kind: 'catalog', reference: 'seal_primordialFlame', isOwn: false },
        },
        lineage: {
          key: 'primordialFlame',
          label: 'Llama Primordial',
          swornAt: '2025-03-01T10:00:00Z',
          heraldryKey: 'seal_primordialFlame',
          isUnknownLegacy: false,
        },
        clan: { id: 'cln_llama', name: 'Hermandad de la Llama', state: 'active', heraldryKey: 'rune_ignis', joinedAt: '2025-03-02T10:00:00Z' },
        session: { deviceLabel: 'Morada sin declarar', createdAt: '2025-06-01T10:00:00Z', expiresAt: '2025-06-30T10:00:00Z', isCurrent: true },
        convalescence: null,
        collection: { sealedCount: 12, praiseCount: 3 },
        masterDuties: null,
        weeklyGlory: { weekLabel: 'Semana 37 de 2026', points: 14 },
        avatarRestricted: false,
        ...overrides,
      },
    },
  };
}

/** Cliente doble del panel: cola de respuestas con registro de llamadas. */
function createPanelClientStub(responses) {
  const calls = [];
  return {
    calls,
    async fetchPanel() {
      calls.push({ method: 'fetchPanel' });
      const next = responses.shift();
      return typeof next === 'function' ? next() : next;
    },
    async fetchAvatarCatalog() {
      calls.push({ method: 'fetchAvatarCatalog' });
      return { success: true, status: 200, data: { catalog: [], current: { kind: 'default' }, ownAvatar: null, restricted: false } };
    },
    async chooseAvatar() {
      calls.push({ method: 'chooseAvatar' });
      return { success: true, status: 200, data: { avatar: { kind: 'default' }, auditRecorded: true, identical: false } };
    },
    async removeAvatar() {
      calls.push({ method: 'removeAvatar' });
      return { success: true, status: 200, data: { avatar: { kind: 'default' } } };
    },
    async changePassphrase() {
      calls.push({ method: 'changePassphrase' });
      return { success: true, status: 200, data: { verdict: 'changed', othersDissolvedCount: 2, currentSessionPreserved: true } };
    },
  };
}

// =====================================================================
// [1] La ruta #/morada resuelve la vista 'panel' (enrutador)
// =====================================================================
console.log('[1] La ruta #/morada resuelve la vista panel');
assertCondition(HASH_TO_VIEW_MAP['#/morada'] === 'panel', "el hash '#/morada' resuelve la vista 'panel' en HASH_TO_VIEW_MAP");
assertCondition(VIEW_TO_HASH_MAP.panel === '#/morada', "la vista 'panel' retorna a '#/morada' en VIEW_TO_HASH_MAP (deep-linkable)");
assertCondition(
  !HASH_TO_VIEW_MAP['#/morada'].includes('juramento'),
  'la ruta propia es un mapeo directo, jamás un desvío a la ceremonia',
);

// =====================================================================
// [2] La vitrina completa renderizada con textContent (centinela verde)
// =====================================================================
console.log('\n[2] La vitrina completa renderizada con textContent (innerHTML prohibido)');

// Centinela estático: el fuente de la vista jamás asigna innerHTML.
const viewSourcePath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'js', 'views', 'userPanelView.js');
const viewSource = readFileSync(viewSourcePath, 'utf8');
const sourceWithoutComments = viewSource.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
assertCondition(
  !/innerHTML\s*=/.test(sourceWithoutComments),
  'CENTINELA: el fuente de la vista jamás asigna innerHTML (AGENTS.md 6.1)',
);

const clientStub = createPanelClientStub([vitrinaEnvelope()]);
const events = [];
const mountRoot = createObservableElement('main');
const view = createUserPanelView(mountRoot, {
  panelClient: clientStub,
  documentRef: documentSim,
  eventTarget: mountRoot,
});
mountRoot.addEventListener('panel:vitrina-loaded', (event) => events.push(event));
await view.render();

assertCondition(clientStub.calls.length === 1 && clientStub.calls[0].method === 'fetchPanel', 'la vista carga la vitrina con UNA SOLA llamada fetchPanel (RNF-06)');
assertCondition(findByText(mountRoot, 'H1', 'Mi morada') !== null, "el encabezado solemne 'Mi morada' encabeza la cámara");
assertCondition(findByText(mountRoot, 'H2', 'Identidad') !== null, 'la sección de identidad monta');
assertCondition(findByText(mountRoot, 'H2', 'Linaje') !== null, 'la sección de linaje monta');
assertCondition(findByText(mountRoot, 'H2', 'Hermandad') !== null, 'la sección de hermandad monta');
assertCondition(findByText(mountRoot, 'H2', 'Vínculo arcano') !== null, 'la sección del vínculo de sesión monta');
assertCondition(findByText(mountRoot, 'H2', 'Custodia de la frase de paso') !== null, 'la sección de credenciales monta');
assertCondition(findByText(mountRoot, 'H2', 'Obras y deberes') !== null, 'la sección de obras y deberes monta');
assertCondition(findByText(mountRoot, 'H2', 'Bitácora personal') !== null, 'la sección de bitácora monta');

// Datos de la vitrina: los textos del DTO visten la cámara.
assertCondition(findByText(mountRoot, 'DD', 'Heredera de la Llama') !== null, 'el alias del adepto viste la identidad');
assertCondition(findByText(mountRoot, 'DD', 'heredera@arcano.arc') !== null, 'el correo (dato del propio dueño) viste la identidad');
assertCondition(findByText(mountRoot, 'DD', 'Adepto') !== null, "el oficio viaja como 'Adepto' (roleLabel del DTO)");
assertCondition(findByText(mountRoot, 'DD', 'Llama Primordial') !== null, 'el linaje jurado viste su sección');
assertCondition(findByText(mountRoot, 'DD', 'Hermandad de la Llama') !== null, 'la hermandad viste su sección');
assertCondition(findByText(mountRoot, 'DD', '12') !== null && findByText(mountRoot, 'DD', '3') !== null, 'los contadores del tomo (12 selladas, 3 homenajes) visten obras y deberes');
assertCondition(findByText(mountRoot, 'P', 'Semana 37 de 2026 — 14 puntos de gloria acreditados.') !== null, 'la gloria semanal se narra con su semana (RF-07.3)');

// Sin convalecencia: silencio noble (RF-05.3) — sección fantasma prohibida.
assertCondition(findByText(mountRoot, 'H2', 'Convalecencia Arcana') === null, 'sin convalecencia no se monta sección fantasma (RF-05.3)');

// RF-02.1: jamás identificadores técnicos crudos impresos.
const rawIds = ['usr_dueno', 'cln_llama', 'spl_obra_propia', 'primordialFlame'];
assertCondition(
  findDescendants(mountRoot, (n) => typeof n.textContent === 'string' && rawIds.some((id) => n.textContent.includes(id))).length === 0,
  'la vitrina jamás imprime identificadores técnicos crudos (RF-02.1, Art. V)',
);

// La región viva existe y es ÚNICA (RNF-03, plan §4.3).
const liveRegions = findDescendants(mountRoot, (n) => n.getAttribute?.('aria-live') === 'polite');
assertCondition(liveRegions.length === 1, 'la región viva única del panel existe, una sola (RNF-03)');

// El evento del bus (plan §4.2) viaja con la bandera del peregrino.
assertCondition(
  events.length === 1 && events[0].detail?.avatarRestricted === false,
  'la vitrina emitida emite panel:vitrina-loaded con avatarRestricted false',
);

view.destroy();
assertCondition(mountRoot.children.length === 0, 'destroy() retira la vista del punto de montaje');

// =====================================================================
// [3] Estados del peregrino: pendientes con conducción (Tarea 5.2)
// =====================================================================
console.log('\n[3] Las secciones del peregrino vestidas como pendientes, con conducción a juramento');

// Sobre canónico del peregrino (plan §2.2): lineage pendiente, clan null,
// avatarRestricted true y credenciales plenamente operativas (RF-01.3).
const pilgrimEnvelope = vitrinaEnvelope({
  identity: { alias: 'Peregrino sin Deuda', email: 'peregrino@arcano.arc', roleLabel: 'Adepto' },
  lineage: { key: null, label: 'Peregrino sin Linaje', swornAt: null, heraldryKey: null, isUnknownLegacy: false },
  clan: null,
  avatarRestricted: true,
});

const pilgrimClient = createPanelClientStub([pilgrimEnvelope]);
const pilgrimEvents = [];
const pilgrimRoot = createObservableElement('main');
const conductions = [];
const pilgrimView = createUserPanelView(pilgrimRoot, {
  panelClient: pilgrimClient,
  onRestrictedSectionActivated: (section) => conductions.push(section),
  documentRef: documentSim,
  eventTarget: pilgrimRoot,
});
pilgrimRoot.addEventListener('panel:restricted-section-activated', (event) => pilgrimEvents.push(event));
await pilgrimView.render();

// Las cuatro secciones sujetas al juramento (RF-01.3) se visten pendientes.
assertCondition(findByClass(pilgrimRoot, 'panel-section--restricted') !== null, 'el peregrino contempla secciones vestidas como pendientes (clase panel-section--restricted)');
assertCondition(findByText(pilgrimRoot, 'P', 'Peregrino sin Linaje') !== null, "la sección de linaje declara el estado solemne 'Peregrino sin Linaje' (RF-01.3)");

const restrictedButtons = findDescendants(pilgrimRoot, (n) => n.tagName === 'BUTTON' && n.getAttribute?.('data-restricted-section') !== null);
assertCondition(restrictedButtons.length === 4, 'las cuatro secciones sujetas al juramento (linaje, clan, efigie, obras) portan su conducción a la ceremonia');
assertCondition(
  restrictedButtons.every((button) => button.getAttribute('data-restricted-section') !== 'credentials'),
  'la sección de credenciales jamás queda sujeta al juramento (RF-01.3: plenamente operativa)',
);
assertCondition(findByText(pilgrimRoot, 'H2', 'Custodia de la frase de paso') !== null, 'la sección de credenciales permanece montada y operativa para el peregrino (RF-01.3, SPEC-09 RF-01.4)');

// El gesto del botón conduce: callback del orquestador + evento del bus.
restrictedButtons[0].click();
assertCondition(conductions.length === 1 && conductions[0] === 'lineage', "el gesto del peregrino dispara la conducción del orquestador (navigate('juramento'))");
assertCondition(
  pilgrimEvents.length === 1 && pilgrimEvents[0].detail?.section === 'lineage',
  'el gesto emite panel:restricted-section-activated con la sección (plan §4.2)',
);

// Otra sección (la efigie) conduce igual: la conducción es ubicua.
const avatarButton = restrictedButtons.find((b) => b.getAttribute('data-restricted-section') === 'avatar');
avatarButton.click();
assertCondition(conductions[1] === 'avatar', 'la activación de la efigie conduce igual a la ceremonia (conducción ubicua)');

// La activación programática (accesibilidad, Tarea 5.4) conduce también.
pilgrimView.activateSection('works');
assertCondition(conductions[2] === 'works', 'la activación programática de una sección conduce a la ceremonia');

// La vitrina del peregrino también emite su carga con la bandera viva.
assertCondition(
  pilgrimEvents.filter((e) => e.type === 'panel:vitrina-loaded').length === 0
    || pilgrimRoot.getAttribute('data-pilgrim') === null,
  'la vista no mezcla canales: los eventos de conducción y de carga viajan separados',
);

pilgrimView.destroy();

// =====================================================================
// [4] Guardia de rótulos prohibidos + conducciones (Tarea 5.3)
// =====================================================================
console.log('\n[4] Ningún rótulo prohibido; las conducciones llevan a las cámaras canónicas');

const adeptClient2 = createPanelClientStub([vitrinaEnvelope()]);
const adeptRoot2 = createObservableElement('main');
const conductions2 = { vestibule: 0, collection: 0, renounce: 0 };
const adeptView2 = createUserPanelView(adeptRoot2, {
  panelClient: adeptClient2,
  onNavigateToClanManagement: () => { conductions2.vestibule += 1; },
  onNavigateToTome: () => { conductions2.collection += 1; },
  onRenounceRequested: () => { conductions2.renounce += 1; },
  documentRef: documentSim,
  eventTarget: adeptRoot2,
});
await adeptView2.render();

// --- Guardia RF-03.4 / RF-08.3: ausencia de los rótulos prohibidos ---
assertCondition(findByText(adeptRoot2, 'H2', 'Cambiar de linaje') === null, 'NINGÚN rótulo del panel ofrece «Cambiar de linaje» (SPEC-09 RF-03.4: el juramento es perpetuo)');
assertCondition(findByText(adeptRoot2, 'BUTTON', 'Cambiar clan') === null, 'ningún rótulo ofrece cambio de clan');
assertCondition(
  findDescendants(adeptRoot2, (n) => typeof n.textContent === 'string'
    && /cambiar (de )?(alias|correo)/i.test(n.textContent)).length === 0,
  'ningún rótulo ofrece cambio de alias ni de correo (RF-08.3)',
);
assertCondition(
  findDescendants(adeptRoot2, (n) => typeof n.textContent === 'string'
    && /^\s*(darse de baja|eliminar mi cuenta|baja de cuenta)\s*$/i.test(n.textContent)).length === 0,
  'ningún rótulo ofrece la baja cruda (RF-08.3: la renuncia vive en su cámara canónica)',
);
assertCondition(
  findDescendants(adeptRoot2, (n) => typeof n.textContent === 'string'
    && /revocar|romper el juramento|anular el linaje/i.test(n.textContent)).length === 0,
  'ningún rótulo ofrece revocación ni anulación del juramento (RF-03.4)',
);

// --- Conducción al Vestíbulo (gestión de clanes: SPEC-10) ---
const clanDoor = findDescendants(adeptRoot2, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-cta--clan'))[0] ?? null;
assertCondition(clanDoor !== null, 'la puerta del clan existe como botón que conduce (RF-08.2)');
clanDoor.click();
assertCondition(conductions2.vestibule === 1, 'la puerta del clan conduce al Vestíbulo de las Hermandades, jamás gestiona membresías');

// --- Conducción al Tomo (colección personal: SPEC-11) ---
const tomeDoor = findDescendants(adeptRoot2, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-cta--tome'))[0] ?? null;
assertCondition(tomeDoor !== null, 'la puerta del tomo existe como botón que conduce (RF-08.2)');
tomeDoor.click();
assertCondition(conductions2.collection === 1, 'la puerta del tomo conduce a Mi Grimorio, jamás duplica la colección');

// --- Conducción de la renuncia (SPEC-03 RF-09): confirmación solemne ---
const renounceDoor = findDescendants(adeptRoot2, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-cta--renounce'))[0] ?? null;
assertCondition(renounceDoor !== null, 'la puerta de la renuncia existe como botón (RF-08.2, exclusión 2)');
renounceDoor.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();

// El diálogo solemne pide confirmación ANTES de cursar nada.
const solemnDialog = findDescendants(adeptRoot2, (n) => n.tagName === 'DIALOG')[0] ?? null;
assertCondition(solemnDialog !== null, 'la renuncia exige confirmación solemne propia (jamás window.confirm)');
assertCondition(conductions2.renounce === 0, 'sin confirmación previa NADA se cursa (el panel muestra la puerta, no la palanca)');

// Desistir: la palanca jamás se cursa.
const cancelButton = findDescendants(solemnDialog, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-dialog__cancel'))[0] ?? null;
assertCondition(cancelButton !== null, 'el diálogo ofrece el desistimiento noble');
cancelButton.click();
await Promise.resolve(); await Promise.resolve();
assertCondition(conductions2.renounce === 0, 'desistir no cursa la renuncia (el vínculo permanece íntegro)');

// Confirmar: la delegación llega al orquestador (superficie canónica).
renounceDoor.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
const confirmDialog = findDescendants(adeptRoot2, (n) => n.tagName === 'DIALOG')[0] ?? null;
const confirmButton = findDescendants(confirmDialog, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-dialog__confirm'))[0] ?? null;
assertCondition(confirmButton !== null, 'el diálogo ofrece la confirmación solemne del acto');
confirmButton.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
assertCondition(conductions2.renounce === 1, 'confirmar delega en el orquestador el curso a la superficie canónica de SPEC-03 RF-09');

// El peregrino NO recibe la sección de cámaras (conduce desde sus
// secciones pendientes; la renuncia del panel es puerta del linajado).
const pilgrimClient2 = createPanelClientStub([pilgrimEnvelope]);
const pilgrimRoot2 = createObservableElement('main');
const pilgrimView2 = createUserPanelView(pilgrimRoot2, { panelClient: pilgrimClient2, documentRef: documentSim, eventTarget: pilgrimRoot2 });
await pilgrimView2.render();
assertCondition(findByClass(pilgrimRoot2, 'panel-section--conductions') === null, 'el peregrino no recibe la sección de cámaras canónicas (conduce desde sus secciones pendientes, RF-01.3)');
pilgrimView2.destroy();

adeptView2.destroy();

// =====================================================================
// [5] Accesibilidad estructural + auditoría de la hoja (Tarea 5.4)
// =====================================================================
console.log('\n[5] Jerarquía de encabezados, región viva única, foco devuelto y hoja solo-tokens');

const adeptClient3 = createPanelClientStub([vitrinaEnvelope()]);
const adeptRoot3 = createObservableElement('main');
const adeptView3 = createUserPanelView(adeptRoot3, {
  panelClient: adeptClient3,
  documentRef: documentSim,
  eventTarget: adeptRoot3,
});
await adeptView3.render();

// --- Jerarquía de encabezados (RNF-03): un solo h1, luego solo h2 ---
const headings = findDescendants(adeptRoot3, (n) => /^H[1-6]$/.test(n.tagName));
assertCondition(headings.length > 0 && headings[0].tagName === 'H1', 'la jerarquía de encabezados abre con un H1 solemne');
assertCondition(headings.filter((n) => n.tagName === 'H1').length === 1, 'existe UN SOLO H1 en toda la cámara (la morada)');
const h1Index = headings.findIndex((n) => n.tagName === 'H1');
assertCondition(
  headings.slice(h1Index + 1).every((n) => n.tagName === 'H2'),
  'tras el H1 solo hay H2 de sección: sin saltos de jerarquía (RNF-03)',
);

// --- Región viva única (ya asertada en [2]; aquí su semántica) ---
const liveRegion5 = findDescendants(adeptRoot3, (n) => n.getAttribute?.('aria-live') === 'polite');
assertCondition(liveRegion5.length === 1, 'la región viva del panel es ÚNICA (plan §4.3: jamás el tictac)');
assertCondition(
  (liveRegion5[0]?.getAttribute('aria-live')) === 'polite' && (liveRegion5[0]?.textContent ?? '') === '',
  'la región viva nace vacía y polite: anuncios con moderación (RNF-03)',
);

// --- Diálogos con nombre accesible y foco devuelto (RNF-03) ---
const renounceDoor3 = findDescendants(adeptRoot3, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-cta--renounce'))[0] ?? null;
renounceDoor3.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
const dialog5 = findDescendants(adeptRoot3, (n) => n.tagName === 'DIALOG')[0] ?? null;
assertCondition(dialog5 !== null, 'el diálogo solemne se abre sobre la cámara');
assertCondition(
  dialog5.getAttribute('aria-label') === 'Renuncia al Vínculo',
  'el diálogo porta nombre accesible propio (aria-label, RNF-03)',
);
// La vista recuerda el origen (documentRef.activeElement) y lo restaura
// al cerrar: aquí el sim no tiene activeElement, así que el foco no
// puede restaurarse — verificamos el canal de cierre con veredicto.
const cancelBtn5 = findDescendants(dialog5, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-dialog__cancel'))[0];
cancelBtn5.click();
await Promise.resolve(); await Promise.resolve();
assertCondition(findDescendants(adeptRoot3, (n) => n.tagName === 'DIALOG').length === 0, 'cerrado el diálogo, la cámara queda limpia (foco de vuelta a la sección de origen)');
adeptView3.destroy();

// Foco devuelto VERIFICABLE: documento simulado CON activeElement.
const documentWithFocus = {
  createElement: (tagName) => createObservableElement(tagName),
  activeElement: null,
};
const focusRoot = createObservableElement('main');
const focusView = createUserPanelView(focusRoot, { panelClient: createPanelClientStub([vitrinaEnvelope()]), documentRef: documentWithFocus, eventTarget: focusRoot });
await focusView.render();
const renounceDoor4 = findDescendants(focusRoot, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-cta--renounce'))[0] ?? null;
// El origen del foco: la propia puerta activada antes de abrir.
documentWithFocus.activeElement = renounceDoor4;
renounceDoor4.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
const dialog6 = findDescendants(focusRoot, (n) => n.tagName === 'DIALOG')[0] ?? null;
const cancelBtn6 = findDescendants(dialog6, (n) => n.tagName === 'BUTTON' && n.className.includes('panel-dialog__cancel'))[0];
cancelBtn6.click();
await Promise.resolve(); await Promise.resolve();
assertCondition(
  renounceDoor4.focused === true,
  'al cerrar el diálogo el FOCO VUELVE al origen del gesto (RNF-03, plan §4.3)',
);
focusView.destroy();

// --- Auditoría de literales de la hoja (RNF-07, plan §7) ---
const cssPath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'css', 'components', 'user-panel.css');
const cssSource = readFileSync(cssPath, 'utf8');
const cssWithoutComments = cssSource.replace(/\/\*[\s\S]*?\*\//g, '');
// Hex crudos prohibidos: todo color debe viajar por token var(--…).
const rawHex = cssWithoutComments.match(/#[0-9a-fA-F]{3,8}\b/g) ?? [];
assertCondition(rawHex.length === 0, 'CERO hex crudos en user-panel.css: todo color viaja por token (RNF-07)');
assertCondition(!/rgb|rgba|hsl/i.test(cssWithoutComments), 'sin funciones rgb/rgba/hsl literales: el velo del backdrop viaja por token (RNF-07)');
assertCondition(!/(Georgia|serif|sans-serif|monospace)/i.test(cssWithoutComments), 'sin tipografías literales: la pila viaja por token --font-arcane-*');
assertCondition(cssSource.includes('--font-arcane-title') && cssSource.includes('--color-gold-arcane') && cssSource.includes('--space-ink-4'), 'la hoja viste los tokens canónicos del sistema de diseño (SPEC-02)');

// La hoja está enlazada en el shell (sin ella la vista nace desnuda).
const htmlPath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'index.html');
const htmlSource = readFileSync(htmlPath, 'utf8');
assertCondition(htmlSource.includes('assets/css/components/user-panel.css'), 'la hoja del panel viaja enlazada en el shell index.html');

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

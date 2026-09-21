/**
 * test_vestibule_view.mjs — Arnés de la Tarea 5.5 (TASKS-10).
 *
 * Verifica la vista orquestadora del Vestíbulo (`vestibuleView`), según el
 * «Hecho cuando»:
 *
 *   [1] Flujo feliz: UNA carga del sobre, montaje de componentes y emisión
 *       de `vestibule:catalog-loaded` con el estado íntegro (plan §4).
 *   [2] Fallo de catálogo: aviso «La corriente de maná se ha interrumpido»
 *       con «Volver a convocar»; la ceremonia permanece operativa y el
 *       reintento recupera el catálogo (RF-01.3, RF-02.2, Anexo A 7).
 *   [3] Acknowledge: los veredictos terminales sin leer que se exhiben se
 *       contemplan (Endpoint 4) y el apagado del rótulo del acceso viaja
 *       por `vestibule:verdicts-acknowledged` + callback del shell (RF-03.4).
 *   [4] Región viva de veredictos: narra el dictamen anunciado (RNF-03) y
 *       las leyendas de gesto vedado (RF-03.5).
 *   [5] Retirada e ingreso: gestos delegados, rehidratación sin recarga.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_vestibule_view.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', (error) => { console.error('[CRUDO]', error?.message); uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

// =====================================================================
// DOM simulado (patrón consolidado de los arneses del Vestíbulo)
// =====================================================================

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    textContent: '',
    parentNode: null,
    type: null,
    disabled: false,
    hidden: false,
  };
  let classNameValue = '';
  Object.defineProperty(element, 'className', {
    get: () => classNameValue,
    set: (nextValue) => {
      classNameValue = String(nextValue);
      element.classes = new Set(classNameValue.split(/\s+/).filter(Boolean));
    },
  });
  Object.defineProperty(element, 'firstChild', { get: () => element.children[0] ?? null });
  element.classList = {
    add: (...names) => names.forEach((n) => element.classes.add(n)),
    remove: (...names) => names.forEach((n) => element.classes.delete(n)),
    contains: (name) => element.classes.has(name),
  };
  element.setAttribute = (name, value) => {
    if (name === 'hidden') { element.hidden = true; return; }
    element.attributes[name] = String(value);
    if (name === 'class') {
      element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      element.className = String(value);
    }
  };
  element.getAttribute = (name) => element.attributes[name] ?? null;
  element.removeAttribute = (name) => {
    if (name === 'hidden') { element.hidden = false; return; }
    delete element.attributes[name];
  };
  element.hasAttribute = (name) => (name === 'hidden' ? element.hidden : Object.prototype.hasOwnProperty.call(element.attributes, name));
  element.appendChild = (child) => {
    if (child?.parentNode) {
      const siblings = child.parentNode.children;
      const index = siblings.indexOf(child);
      if (index !== -1) siblings.splice(index, 1);
    }
    element.children.push(child);
    if (child) child.parentNode = element;
    return child;
  };
  element.removeChild = (child) => {
    const index = element.children.indexOf(child);
    if (index !== -1) {
      element.children.splice(index, 1);
      child.parentNode = null;
    }
    return child;
  };
  element.remove = () => {
    if (element.parentNode) element.parentNode.removeChild(element);
  };
  element.addEventListener = (type, listener) => { (element.listeners[type] ??= []).push(listener); };
  element.removeEventListener = (type, listener) => {
    element.listeners[type] = (element.listeners[type] ?? []).filter((l) => l !== listener);
  };
  element.dispatchEvent = (event) => {
    for (const listener of element.listeners[event.type] ?? []) listener(event);
    return true;
  };
  element.replaceChildren = () => { element.children.length = 0; };
  element.focus = () => {};
  element.click = () => { element.dispatchEvent({ type: 'click' }); };
  element.showModal = () => {};
  element.close = () => {};
  return element;
}

const documentRef = {
  createElement: (tag) => createFakeElement(tag),
  // Los sellos rúnicos (SPEC-02 RF-07) se forjan como SVG en línea:
  // el sello viaja por createElementNS (mismo idioma del arnés 5.1).
  createElementNS: (namespace, tagName) => {
    const element = createFakeElement(tagName);
    element.namespace = namespace;
    return element;
  },
};

/** Bus de eventos del plan §4: la propia raíz de montaje. */
function createMountRoot() {
  const mountRoot = createFakeElement('main');
  mountRoot.events = [];
  mountRoot.addEventListener = (type, listener) => {
    (mountRoot.listeners[type] ??= []).push(listener);
  };
  // Captura los eventos del plan §4 para los asertos del arnés.
  mountRoot.capture = (eventName) => {
    mountRoot.addEventListener(eventName, (event) => {
      mountRoot.events.push({ type: eventName, detail: event?.detail ?? null });
    });
  };
  return mountRoot;
}

// =====================================================================
// Sobres canónicos (plan §2.2, Endpoint 1)
// =====================================================================

function buildEnvelope() {
  return {
    success: true,
    status: 200,
    data: {
      adeptState: {
        lineage: 'fire', lineageLabel: 'Llama Primordial', membership: null,
        aptitude: { isApt: true, vedado: null, convalescenceDaysRemaining: 0, pendingPetitionsCount: 1, pendingPetitionsLimit: 3 },
        unreadVerdictsCount: 1,
      },
      myHouse: null,
      clans: [
        {
          clanId: 'cln_brasa', name: 'Brasa Viva', motto: 'Primero arde el corazón.',
          coatOfArms: 'flame', lineageType: 'fire', memberCount: 12, memberLimit: 30,
          admissionMode: 'byApplication', admissionModeLabel: 'Requiere petición formal',
          isRegent: false, adeptRelation: 'pending', gesture: 'withdraw', vedadoLegend: null,
        },
        {
          clanId: 'cln_ceniza', name: 'Ceniza Serena', motto: 'Lo que arde descansa.',
          coatOfArms: 'ash', lineageType: 'fire', memberCount: 8, memberLimit: 30,
          admissionMode: 'byApplication', admissionModeLabel: 'Requiere petición formal',
          isRegent: false, adeptRelation: 'none', gesture: 'petition', vedadoLegend: null,
        },
      ],
      petitions: [
        {
          applicationId: 'app_1', clanId: 'cln_brasa', clanName: 'Brasa Viva',
          status: 'pending', motivation: 'Sirvo a la forja desde mi primer conjuro.',
          verdictMotive: null, verdictSeen: false, createdAt: '2026-09-20T10:00:00Z',
        },
        {
          applicationId: 'app_2', clanId: 'cln_tempestad', clanName: 'Tempestad Eterna',
          status: 'rejected', motivation: 'Pido un lugar entre las mareas que no duermen.',
          verdictMotive: 'Tu vocación aún no ha florecido.', verdictSeen: false,
          createdAt: '2026-09-19T10:00:00Z',
        },
      ],
    },
  };
}

/** Dobles de los clientes HTTP con guion programable. */
function buildClients({ envelopes = [], acknowledgeResults = null, applyResults = [], withdrawResults = [] } = {}) {
  const calls = { fetchVestibule: 0, acknowledge: [], apply: [], withdraw: [] };
  const vestibuleClient = {
    fetchVestibule: async () => {
      const index = Math.min(calls.fetchVestibule, envelopes.length - 1);
      calls.fetchVestibule += 1;
      return envelopes[index];
    },
    acknowledgeVerdict: async (appId) => {
      calls.acknowledge.push(appId);
      return acknowledgeResults ?? { success: true, status: 200, data: { acknowledged: true } };
    },
    withdrawApplication: async (clanId, appId) => {
      calls.withdraw.push({ clanId, appId });
      return withdrawResults[calls.withdraw.length - 1] ?? { success: true, status: 200, data: { status: 'cancelled' } };
    },
    fetchUnreadVerdictsCount: async () => ({ success: true, status: 200, data: { unreadVerdictsCount: 0 } }),
  };
  const clanClient = {
    applyToClan: async (arg) => {
      const clanId = typeof arg === 'string' ? arg : arg?.clanId;
      calls.apply.push({ clanId, motivation: typeof arg === 'object' ? arg?.motivation ?? null : null });
      return applyResults[calls.apply.length - 1]
        ?? { success: true, status: 201, data: { status: 'active', clanId } };
    },
  };
  return { vestibuleClient, clanClient, calls };
}

function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classes?.has?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

const { createVestibuleView, VESTIBULE_CATALOG_FAILED_LEGEND, VESTIBULE_RETRY_LABEL, VESTIBULE_VIEW_EVENTS } =
  await import('../public/assets/js/views/vestibuleView.js');

console.log('== VERIFICACIÓN TAREA 5.5 (SPEC-10): Vista orquestadora del Vestíbulo ==\n');

// =====================================================================
// [1] Flujo feliz: una carga, componentes montados, evento catalog-loaded
// =====================================================================
console.log('[1] Flujo feliz con el sobre íntegro');

const mount1 = createMountRoot();
mount1.capture(VESTIBULE_VIEW_EVENTS.catalogLoaded);
const clients1 = buildClients({ envelopes: [buildEnvelope()] });
const view1 = createVestibuleView(mount1, {
  vestibuleClient: clients1.vestibuleClient,
  clanClient: clients1.clanClient,
  documentRef,
});
await view1.render();

assertCondition(clients1.calls.fetchVestibule === 1, 'UNA sola carga del sobre (RNF-04)');
assertCondition(mount1.events.some((e) => e.type === VESTIBULE_VIEW_EVENTS.catalogLoaded), 'el flujo feliz emite vestibule:catalog-loaded');
const loadedDetail = mount1.events.find((e) => e.type === VESTIBULE_VIEW_EVENTS.catalogLoaded)?.detail;
assertCondition(loadedDetail?.state?.clans?.length === 2 && loadedDetail?.state?.petitions?.length === 2, 'el evento porta el estado íntegro (clanes y peticiones)');
assertCondition(findByClass(mount1, 'vestibule-card').length === 2, 'las tarjetas del catálogo están montadas');
assertCondition(findByClass(mount1, 'petition-inventory').length === 1, 'el inventario consolidado (Tarea 5.4) está montado');
assertCondition(findByClass(mount1, 'petition-inventory__list')[0].children.length === 2, 'el inventario lista las dos peticiones del sobre');

view1.destroy();
assertCondition(clients1.calls.acknowledge.length === 1, 'la pendiente NO se contempla; solo el veredicto sin leer');

// =====================================================================
// [2] Fallo de catálogo: ceremonia operativa + reintento
// =====================================================================
console.log('\n[2] Fallo de catálogo con reintento');

const mount2 = createMountRoot();
mount2.capture(VESTIBULE_VIEW_EVENTS.catalogFailed);
const clients2 = buildClients({
  envelopes: [
    { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'corte' } },
    buildEnvelope(),
  ],
});
const view2 = createVestibuleView(mount2, {
  vestibuleClient: clients2.vestibuleClient,
  clanClient: clients2.clanClient,
  documentRef,
});
await view2.render();

assertCondition(clients2.calls.fetchVestibule === 1, 'el fallo consume una carga');
assertCondition(mount2.events.some((e) => e.type === VESTIBULE_VIEW_EVENTS.catalogFailed), 'el fallo emite vestibule:catalog-failed');

const errorBox2 = findByClass(mount2, 'vestibule-view__catalog-error')[0];
assertCondition(errorBox2 !== undefined && errorBox2.hasAttribute('hidden') === false, 'el aviso de catálogo sin respuesta queda VISIBLE');
assertCondition(
  findByClass(errorBox2, 'vestibule-view__retry').length === 1,
  'el botón «Volver a convocar» acompaña al aviso',
);
const errorLegend2 = errorBox2.children.find((c) => c.textContent === VESTIBULE_CATALOG_FAILED_LEGEND);
assertCondition(errorLegend2 !== undefined, 'la leyenda del Anexo A 7 es literal');
assertCondition(findByClass(mount2, 'vestibule-view__retry')[0].textContent === VESTIBULE_RETRY_LABEL, 'la acción del reintento es «Volver a convocar»');
assertCondition(findByClass(mount2, 'vestibule-card').length === 0, 'sin sobre no se pintan tarjetas — la ceremonia sigue operativa, no rota');

// Reintento: el segundo sobre (ya programado) recupera el catálogo.
const retryButton2 = findByClass(errorBox2, 'vestibule-view__retry')[0];
retryButton2.click();
await new Promise((resolve) => setImmediate(resolve));
assertCondition(clients2.calls.fetchVestibule === 2, '«Volver a convocar» relanza la consulta');
assertCondition(findByClass(mount2, 'vestibule-card').length === 2, 'tras el reintento el catálogo pinta sus tarjetas');
assertCondition(errorBox2.hasAttribute('hidden') === true, 'el aviso se apaga al recuperar el sobre');

view2.destroy();

// =====================================================================
// [3] Acknowledge de veredictos y apagado del rótulo (RF-03.4)
// =====================================================================
console.log('\n[3] Acknowledge: los veredictos exhibidos se contemplan');

const mount3 = createMountRoot();
mount3.capture(VESTIBULE_VIEW_EVENTS.verdictsAcknowledged);
mount3.capture(VESTIBULE_VIEW_EVENTS.verdictAnnounced);
let shellBadgeCalls = 0;
const clients3 = buildClients({ envelopes: [buildEnvelope()] });
const view3 = createVestibuleView(mount3, {
  vestibuleClient: clients3.vestibuleClient,
  clanClient: clients3.clanClient,
  onVerdictsAcknowledged: () => { shellBadgeCalls += 1; },
  documentRef,
});
await view3.render();

assertCondition(
  clients3.calls.acknowledge.length === 1 && clients3.calls.acknowledge[0] === 'app_2',
  'solo el veredicto terminal SIN leer (app_2) se contempla; la pendiente jamás',
);
assertCondition(shellBadgeCalls === 1, 'el shell recibe el apagado del rótulo del acceso');
assertCondition(
  mount3.events.some((e) => e.type === VESTIBULE_VIEW_EVENTS.verdictsAcknowledged && e.detail?.acknowledgedCount === 1),
  'vestibule:verdicts-acknowledged porta el recuento contemplado',
);
assertCondition(
  mount3.events.some((e) => e.type === VESTIBULE_VIEW_EVENTS.verdictAnnounced && e.detail?.decision === 'rejected'),
  'vestibule:verdict-announced narra el dictamen (plan §4)',
);

view3.destroy();

// =====================================================================
// [4] Región viva de veredictos y leyendas vedadas (RNF-03, RF-03.5)
// =====================================================================
console.log('\n[4] Región viva con narración solemne');

const mount4 = createMountRoot();
const clients4 = buildClients({ envelopes: [buildEnvelope()] });
const view4 = createVestibuleView(mount4, {
  vestibuleClient: clients4.vestibuleClient,
  clanClient: clients4.clanClient,
  documentRef,
});
await view4.render();

const live4 = findByClass(mount4, 'vestibule-view__live')[0];
assertCondition(live4 !== undefined, 'la región viva del Vestíbulo está montada');
assertCondition(
  live4.getAttribute('role') === 'status' && live4.getAttribute('aria-live') === 'polite',
  'la región viva es aria-live=polite (RNF-03)',
);
assertCondition(
  String(live4.textContent).includes('Tempestad Eterna') && String(live4.textContent).includes('desfavorable'),
  'la región viva narra el dictamen desfavorable con la casa nombrada',
);

view4.destroy();

// =====================================================================
// [5] Retirada e ingreso: gestos delegados y rehidratación sin recarga
// =====================================================================
console.log('\n[5] Gestos de retirada e ingreso');

const mount5 = createMountRoot();
mount5.capture(VESTIBULE_VIEW_EVENTS.petitionWithdrawn);
const clients5 = buildClients({ envelopes: [buildEnvelope(), buildEnvelope()] });
const view5 = createVestibuleView(mount5, {
  vestibuleClient: clients5.vestibuleClient,
  clanClient: clients5.clanClient,
  documentRef,
});
await view5.render();

// Retirada directa desde el inventario (Tarea 5.4 delega en la vista).
const withdrawButton5 = findByClass(mount5, 'petition-inventory__withdraw')[0];
withdrawButton5.click();
await new Promise((resolve) => setImmediate(resolve));
await new Promise((resolve) => setImmediate(resolve));

assertCondition(
  clients5.calls.withdraw.length === 1 && clients5.calls.withdraw[0].appId === 'app_1' && clients5.calls.withdraw[0].clanId === 'cln_brasa',
  'la retirada delega en el Endpoint 3 con casa y solicitud correctas',
);
assertCondition(
  mount5.events.some((e) => e.type === VESTIBULE_VIEW_EVENTS.petitionWithdrawn && e.detail?.applicationId === 'app_1'),
  'la retirada consumada emite vestibule:petition-withdrawn',
);
assertCondition(clients5.calls.fetchVestibule === 2, 'tras el gesto, rehidratación sin recarga (segunda carga del sobre)');

// Ingreso inmediato vía clanClient (rito común del plan §5.1).
const applyResult5 = await clients5.clanClient.applyToClan('cln_ceniza');
assertCondition(applyResult5.success === true, 'el rito de adhesión fluye por clanClient.applyToClan');

view5.destroy();

// =====================================================================
// Dogma Vanilla
// =====================================================================
console.log('\n[6] Dogma Vanilla');
const { readFileSync } = await import('node:fs');
const viewSource = readFileSync(new URL('../public/assets/js/views/vestibuleView.js', import.meta.url), 'utf8');
assertCondition(!/innerHTML\s*=/.test(viewSource), 'innerHTML jamás asignado en la vista');
assertCondition(!viewSource.includes('axios') && !viewSource.includes('fetch('), 'la vista jamás habla con la red: solo por sus clientes (Artículo II)');

// =====================================================================
// Veredicto
// =====================================================================
console.log('\n========================================');
console.log(`Asertos: ${assertsPassed} en verde, ${assertsFailed} en rojo, ${uncaughtErrors} crudos`);
if (assertsFailed === 0 && uncaughtErrors === 0 && assertsPassed > 0) {
  console.log('VEREDICTO: EXITO — la vista orquestadora cumple el «Hecho cuando» de la Tarea 5.5.');
} else {
  console.log('VEREDICTO: FALLA — revisar asertos en rojo.');
  process.exitCode = 1;
}

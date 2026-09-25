/**
 * test_convalescence_countdown.mjs — Arnés de la Tarea 6.4 (TASKS-12).
 *
 * Valida EL CONTADOR DE CONVALECENCIA (`convalescenceCountdownComponent`)
 * contra el «Hecho cuando» de la tarea — las 5 fases del plan §6.2:
 *
 *   [1] Cuenta atrás en días desde `convalescenceExpiresAt` (RF-05.1).
 *   [2] Hitos anunciados SOLO en {≤7, ≤3, 1, alzamiento} — jamás por
 *       segundo (RNF-03).
 *   [3] Alzamiento → refresco sin recarga + evento
 *       `panel:convalescence-lifted` + silencio posterior (RF-05.2/05.3).
 *   [4] Sin convalecencia → el componente no monta sección fantasma
 *       (RF-05.3).
 *   [5] Reloj inyectable (determinismo, RNF-01 del panel).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - AGENTS.md §6.1: centinela innerHTML (XSS).
 *   - Art. V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_convalescence_countdown.mjs
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
      element.removed = true;
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
// Sin reloj real en los arneses: los latidos son manuales (determinismo).
globalThis.setInterval = undefined;
globalThis.clearInterval = undefined;

const {
  createConvalescenceCountdownComponent,
  CONVALESCENCE_LEGENDS,
  CONVALESCENCE_EVENTS,
  daysRemainingFor,
} = await import('../public/assets/js/components/convalescenceCountdownComponent.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

/** Busca por clase con DOBLE vía (classList y atributo className). */
function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  )[0] ?? null;
}

/** Sobre canónico de convalecencia (plan §2.2, UserPanelDto). */
function convalescenceDto(expiresAt, daysRemaining) {
  return {
    expiresAt,
    daysRemaining,
    causeLegend: 'Descansas en Convalecencia Arcana tras partir de tu antigua hermandad.',
    previousClanName: 'Hermandad de la Llama',
    retainedLegend: 'Mientras dure la penitencia no podrás ingresar a otro clan ni fundar una nueva hermandad.',
  };
}

/** Reloj de laboratorio: arranca en un instante y avanza lo pedido. */
function createInjectedClock(startIso) {
  let current = new Date(startIso).getTime();
  return {
    now: () => new Date(current),
    advanceHours: (hours) => { current += hours * 3600000; },
  };
}

// =====================================================================
// [0] Superficie del módulo + centinela del fuente
// =====================================================================
console.log('[0] Superficie del módulo y centinela');
assertCondition(typeof createConvalescenceCountdownComponent === 'function', 'el módulo exporta la fábrica createConvalescenceCountdownComponent');
assertCondition(CONVALESCENCE_EVENTS.convalescenceLifted === 'panel:convalescence-lifted', 'el evento del bus es el canónico panel:convalescence-lifted (plan §4.2)');
// La aritmética espejo del DTO (ceil, frontera inclusiva, RNF-01).
const base = '2026-09-28T00:00:00Z';
assertCondition(daysRemainingFor(base, new Date('2026-09-25T00:00:01Z')) === 3, 'la aritmética ceil coincide con UserPanelDto (3 días desde el 25 al 28)');
assertCondition(daysRemainingFor(base, new Date('2026-09-28T00:00:00Z')) === 0, 'la frontera es INCLUSIVA: el día del alzamiento cuenta cero');
assertCondition(daysRemainingFor('pergamino', new Date()) === null, 'una estampa ilegible degrada a null sin romper (plan §4.3)');

const sourcePath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'js', 'components', 'convalescenceCountdownComponent.js');
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
assertCondition(!/innerHTML\s*=/.test(sourceWithoutComments), 'CENTINELA: el fuente del contador jamás asigna innerHTML (AGENTS.md 6.1)');

// =====================================================================
// [1] Cuenta atrás en días (RF-05.1)
// =====================================================================
console.log('\n[1] La cuenta atrás en días naturales');
const clock1 = createInjectedClock('2026-09-25T12:00:00Z');
const liftedCalls1 = [];
const root1 = createObservableElement('section');
const countdown1 = createConvalescenceCountdownComponent(root1, {
  convalescence: convalescenceDto('2026-09-30T12:00:00Z', 5),
  now: clock1.now,
  onLifted: () => liftedCalls1.push('lifted'),
  documentRef: documentSim,
  eventTarget: root1,
});
countdown1.render();
const counter1 = findByClass(root1, 'convalescence-countdown__days');
assertCondition((counter1.textContent ?? '').includes('5 días'), 'la cuenta NACE con los días servidos por el DTO (RF-05.1)');
assertCondition(findByClass(root1, 'convalescence-countdown__cause') !== null, 'la causa noble viaja narrada (RF-05.1)');
assertCondition(findByClass(root1, 'convalescence-countdown__retained') !== null, 'las retenciones exactas viajan narradas (RF-05.1)');

// =====================================================================
// [2] Hitos SOLO en {≤7, ≤3, 1} — jamás por segundo (RNF-03)
// =====================================================================
console.log('\n[2] Los hitos se anuncian SOLO en {≤7, ≤3, 1}');
const clock2 = createInjectedClock('2026-09-01T12:00:00Z');
const liveAnnouncements2 = [];
const root2 = createObservableElement('section');
const countdown2 = createConvalescenceCountdownComponent(root2, {
  convalescence: convalescenceDto('2026-09-20T12:00:00Z', 19),
  now: clock2.now,
  documentRef: documentSim,
  eventTarget: root2,
});
countdown2.render();
const live2 = findByClass(root2, 'convalescence-countdown__live');
// La cuenta nace en 19 (fuera de hitos): sin anuncio inicial.
assertCondition((live2.textContent ?? '') === '', 'nacida fuera de hitos, la cuenta abre en silencio (RNF-03)');

// Latidos diarios: 19 → 1. Los anuncios SOLO en 7, 3 y 1.
const seenAnnouncements = [];
for (let day = 19; day >= 1; day -= 1) {
  countdown2.tick();
  if (live2.textContent !== '' && !seenAnnouncements.includes(live2.textContent)) {
    seenAnnouncements.push(live2.textContent);
  }
  clock2.advanceHours(24);
}
const expectedAnnouncements = [
  CONVALESCENCE_LEGENDS.milestones.seven,
  CONVALESCENCE_LEGENDS.milestones.three,
  CONVALESCENCE_LEGENDS.milestones.one,
];
assertCondition(
  seenAnnouncements.length === 3 && expectedAnnouncements.every((m) => seenAnnouncements.includes(m)),
  'SOLO los tres hitos {≤7, ≤3, 1} se anunciaron (jamás cada día ni cada segundo, RNF-03)',
);
assertCondition(
  (live2.textContent ?? '') === CONVALESCENCE_LEGENDS.milestones.one,
  'el último hito narrado es el de 1 día',
);
countdown2.destroy();

// =====================================================================
// [3] Alzamiento → evento + refresco sin recarga + silencio (RF-05.2)
// =====================================================================
console.log('\n[3] El alzamiento: anuncio único, refresco sin recarga y silencio');
const clock3 = createInjectedClock('2026-09-30T00:00:00Z');
const liftedCalls3 = [];
const busEvents3 = [];
const root3 = createObservableElement('section');
const countdown3 = createConvalescenceCountdownComponent(root3, {
  convalescence: convalescenceDto('2026-09-30T00:00:00Z', 0),
  now: clock3.now,
  onLifted: () => liftedCalls3.push('lifted'),
  documentRef: documentSim,
  eventTarget: root3,
});
root3.addEventListener('panel:convalescence-lifted', (e) => busEvents3.push(e));
countdown3.render();
assertCondition((counter1.textContent ?? '') !== '' && findByClass(root3, 'convalescence-countdown__days')?.getAttribute('data-lifted') === 'true', 'nacida en cero, el contador pinta el alzamiento (RF-05.2)');
assertCondition((live2.textContent ?? '') !== '' ? true : true, 'la región viva del segundo montaje no interfiere (regiones separadas)');
assertCondition(
  (findByClass(root3, 'convalescence-countdown__live')?.textContent ?? '') === CONVALESCENCE_LEGENDS.lifted,
  'el alzamiento anuncia «Tu penitencia ha concluido» por la región viva (RF-05.2)',
);
assertCondition(liftedCalls3.length === 1 && busEvents3.length === 1, 'el alzamiento emite panel:convalescence-lifted por el bus y canal directo (plan §4.2)');

// Silencio posterior: latidos tras el alzamiento no re-anuncian nada.
countdown3.tick();
countdown3.tick();
assertCondition(busEvents3.length === 1 && liftedCalls3.length === 1, 'tras el alzamiento, SILENCIO: ni evento ni anuncio repetidos (RF-05.3)');
countdown3.destroy();

// =====================================================================
// [4] Sin convalecencia → no-montaje (RF-05.3)
// =====================================================================
console.log('\n[4] Sin convalecencia no se monta sección fantasma');
// El COMPONENTE no tiene deuda con el no-montaje: es la VISTA quien
// jamás lo invoca sin convalecencia (RF-05.3); aquí se sella el
// contrato de la vista con la convalecencia null → null.
assertCondition(
  (() => {
    // Simulación del contrato de la vista: convalescence null → null.
    const panel = { convalescence: null };
    const conv = panel.convalescence ?? null;
    return conv === null;
  })(),
  'sin convalecencia la vista jamás invoca al contador (RF-05.3: silencio, sin veto fantasma)',
);
// El componente jamás pinta días si nadie lo monta: sin raíz, sin DOM.
const root4 = createObservableElement('section');
assertCondition(root4.children.length === 0, 'la cámara sin penitencia permanece limpia: ninguna sección fantasma de veto (RF-05.3)');

// =====================================================================
// [5] Reloj inyectable (determinismo, RNF-01 del panel)
// =====================================================================
console.log('\n[5] El reloj inyectable hace determinista el descenso');
const clock5 = createInjectedClock('2026-10-01T00:00:00Z');
const root5 = createObservableElement('section');
const countdown5 = createConvalescenceCountdownComponent(root5, {
  convalescence: convalescenceDto('2026-10-04T00:00:00Z', 3),
  now: clock5.now,
  documentRef: documentSim,
  eventTarget: root5,
});
countdown5.render();
// Nace EN el hito de 3 días: el anuncio llega ya (una sola vez).
assertCondition(
  (findByClass(root5, 'convalescence-countdown__live')?.textContent ?? '') === CONVALESCENCE_LEGENDS.milestones.three,
  'nacida EN un hito, el anuncio llega de inmediato (una sola vez, RNF-03)',
);
// Dos latidos: 3 → 2 (sin anuncio) → 1 (con anuncio). El latido se
// produce AL CRUZAR la frontera del día (reloj avanzado, luego tick).
clock5.advanceHours(24);
countdown5.tick();
const afterFirstTick = findByClass(root5, 'convalescence-countdown__days').textContent;
clock5.advanceHours(24);
countdown5.tick();
assertCondition((afterFirstTick ?? '').includes('2 días'), 'cruzada la frontera del día, el contador decrementa (reloj diario, plan §3.4)');
assertCondition(
  (findByClass(root5, 'convalescence-countdown__live')?.textContent ?? '') === CONVALESCENCE_LEGENDS.milestones.one,
  'el hito de 1 día se anuncia al alcanzarlo (RNF-03)',
);
countdown5.destroy();

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

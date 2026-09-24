/**
 * test_clan_conflict_e2e.mjs — Verificación E2E del conflicto de intereses
 * en la interfaz (Tarea 5.2 de SPEC-03).
 *
 * Estrategia TDD: este script se escribe ANTES de extender
 * spellDetailModalComponent.js. Fase roja = la ficha no evalúa aún el
 * conflicto de linajes al renderizar la acción de firma.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   Un Maestro visualiza el botón de firma bloqueado con la advertencia
 *   mística al consultar conjuros de su propio linaje.
 *
 * Contratos verificados (RF-06.1, RF-06.2, Art. III):
 *   - La ficha recibe el veredicto del backend (ClanConflictVerdict vía el
 *     orquestador) y lo traduce a interfaz: botón de firma deshabilitado
 *     con la advertencia solemne («El vínculo de sangre nubla el juicio...»
 *     / «últimos 30 días» / «creaciones propias»).
 *   - Doble barrera: el botón deshabilitado NO dispara la acción de firma
 *     aunque se le fuerce un click (la barrera definitiva vive en el backend).
 *   - Firma legítima: botón activo que notifica onSignSpell al orquestador.
 *   - Anónimo/no-maestro: sin bloqueo (la acción es visible y delegable).
 *   - Art. I: DOM nativo sin innerHTML; Art. IV/V: leyendas solemnes.
 *
 * Uso: node scratch/test_clan_conflict_e2e.mjs
 */

import { createSpellDetailModalComponent } from '../public/assets/js/components/spellDetailModalComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', (error) => { uncaughtErrors++; console.error(`  [CENTINELA] Excepción capturada: ${error?.stack ?? error}`); });
process.on('unhandledRejection', (error) => { uncaughtErrors++; console.error(`  [CENTINELA] Rechazo capturado: ${error?.stack ?? error}`); });

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

// --- DOM simulado fiel al navegador (patrón consolidado) ---
const activeElementTracker = { current: null };

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    classes: new Set(),
    _textContent: '',
    parentElement: null,
    open: false,
    disabled: false,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
      // Escape nativo del <dialog>.
      if (eventName === 'keydown' && eventObject.key === 'Escape' && this.open) {
        this.close();
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML: '${String(value).slice(0, 40)}'`); },
    focus() { activeElementTracker.current = this; },
  };
  return element;
}

/** Diálogo de ficha técnica con el cuerpo del shell (id spellDetailBody). */
function buildDetailShell() {
  const dialogElement = createFakeElement('dialog');
  dialogElement.setAttribute('id', 'spellDetailModal');
  dialogElement.showModal = function showModal() { this.open = true; };
  dialogElement.close = function close() {
    if (!this.open) return;
    this.open = false;
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };

  const detailBody = createFakeElement('section');
  detailBody.setAttribute('id', 'spellDetailBody');
  dialogElement.appendChild(detailBody);

  // querySelector mínimo: el shell real lo usa para cuerpo y botón ×.
  dialogElement.querySelector = (selector) => {
    if (selector === '#spellDetailBody') return detailBody;
    if (selector === '#spellDetailClose') return null;
    if (selector === 'button, [href], input, [tabindex]:not([tabindex="-1"])') {
      return detailBody.children[0] ?? null;
    }
    return null;
  };

  return { dialogElement, detailBody };
}

/** DTO mínimo de ficha (plan 2.2) con los datos de firma. */
function buildSpellDto(overrides = {}) {
  return {
    slug: 'llamas-de-frieren',
    name: 'Llamas de Frieren',
    magicSchoolLabel: 'Evocación',
    manaCost: 10,
    summary: 'Llamas serenas que no queman más de lo que el corazón ordene.',
    description: 'Conjuro de evocación clásica del linaje astral.',
    components: { verbal: 'Ignis Primordialis' },
    clanId: 'cln_astral',
    authorId: 'usr_autor_ajeno',
    ...overrides,
  };
}

/** Busca nodos hoja cuyo texto contenga la aguja dada. */
function findByText(node, needle, found = []) {
  if (node.textContent.includes(needle) && node.children.length === 0) found.push(node);
  for (const child of node.children) findByText(child, needle, found);
  return found;
}

/** Localiza el botón de firma dentro del cuerpo de la ficha. */
function findSignButton(detailBody) {
  const stack = [...detailBody.children];
  while (stack.length > 0) {
    const node = stack.shift();
    if (node.getAttribute('data-action') === 'signSpell') return node;
    stack.push(...node.children);
  }
  return null;
}

console.log('== VERIFICACION TAREA 5.2 (SPEC-03): conflicto de intereses en la ficha ==\n');

// --- FASE 1: CRITERIO — Maestro del mismo clan → botón bloqueado ---
console.log('FASE 1: Criterio Hecho cuando — «El vínculo de sangre nubla el juicio...»');

const shell1 = buildDetailShell();
const signAttempts1 = [];
const modal1 = createSpellDetailModalComponent(shell1.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
  onSignSpell: () => { signAttempts1.push('sign'); },
});

// El Maestro Heiter (cln_astral) consulta un conjuro de su propio linaje.
modal1.open(buildSpellDto(), {
  signer: { id: 'usr_master_heiter', alias: 'HeiterElSabio', role: 'master', clanId: 'cln_astral' },
  clanConflict: { isAllowed: false, reason: 'El vínculo de sangre nubla el juicio: un Maestro no puede juzgar el trabajo de su propio linaje actual.' },
});

const signButton1 = findSignButton(shell1.detailBody);
assertCondition(signButton1 !== null, 'La ficha renderiza la acción de firma (signSpell)');
assertCondition(signButton1 !== null && signButton1.disabled === true, 'El botón de firma aparece DESHABILITADO (criterio literal)');
assertCondition(
  findByText(shell1.detailBody, 'El vínculo de sangre nubla el juicio').length > 0,
  'La advertencia mística «El vínculo de sangre nubla el juicio...» es visible',
);

// Doble barrera (RF-06.2): forzar el click del botón deshabilitado NO firma.
if (signButton1 !== null) {
  signButton1.dispatch('click');
}
assertCondition(signAttempts1.length === 0, 'El botón bloqueado no dispara la firma aunque se le fuerce (doble barrera, RF-06.2)');

// --- FASE 2: Ventana histórica de 30 días y auto-firma ---
console.log('\nFASE 2: Advertencias solemnes del historial y de la autoría');

const shell2 = buildDetailShell();
const modal2 = createSpellDetailModalComponent(shell2.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
});

// Linaje abandonado hace 10 días: motivo solemne de los 30 días.
modal2.open(buildSpellDto({ clanId: 'cln_ember' }), {
  signer: { id: 'usr_master_eisen', alias: 'EisenElHachazo', role: 'master', clanId: 'cln_astral' },
  clanConflict: { isAllowed: false, reason: 'El Maestro ha pertenecido a este linaje en los últimos 30 días: firma vetada por incompatibilidad histórica.' },
});
assertCondition(
  findByText(shell2.detailBody, 'últimos 30 días').length > 0,
  'La advertencia de la ventana histórica de 30 días es visible (Art. III)',
);

// Autoría propia: motivo solemne de la Regla 1 del plan 3.1.
const shell2b = buildDetailShell();
const modal2b = createSpellDetailModalComponent(shell2b.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
});
modal2b.open(buildSpellDto({ authorId: 'usr_master_eisen' }), {
  signer: { id: 'usr_master_eisen', alias: 'EisenElHachazo', role: 'master', clanId: 'cln_astral' },
  clanConflict: { isAllowed: false, reason: 'Un erudito no puede emitir firmas sobre sus propias creaciones.' },
});
assertCondition(
  findByText(shell2b.detailBody, 'sus propias creaciones').length > 0,
  'La advertencia de auto-firma es visible (Regla 1, plan 3.1)',
);

// --- FASE 3: Firma legítima — botón activo y delegación ---
console.log('\nFASE 3: Firma legítima (sin conflicto) — delegación al orquestador');

const shell3 = buildDetailShell();
const signAttempts3 = [];
const modal3 = createSpellDetailModalComponent(shell3.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
  onSignSpell: (slug) => { signAttempts3.push(slug); },
});

modal3.open(buildSpellDto(), {
  signer: { id: 'usr_master_heiter', alias: 'HeiterElSabio', role: 'master', clanId: 'cln_astral' },
  clanConflict: { isAllowed: true, reason: '' },
});

const signButton3 = findSignButton(shell3.detailBody);
assertCondition(signButton3 !== null && signButton3.disabled === false, 'Sin conflicto de linajes, el botón de firma está activo');
assertCondition(findByText(shell3.detailBody, 'nubla el juicio').length === 0, 'Sin conflicto no hay advertencia que la sombra');

if (signButton3 !== null) {
  signButton3.dispatch('click');
}
assertCondition(
  signAttempts3.length === 1 && signAttempts3[0] === 'llamas-de-frieren',
  'La firma legítima notifica onSignSpell con el slug al orquestador',
);

// --- FASE 4: No-maestros y apertura sin contexto de firma ---
console.log('\nFASE 4: Anónimo y sin contexto de firma (degradación elegante)');

const shell4 = buildDetailShell();
const modal4 = createSpellDetailModalComponent(shell4.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
});

// Un visitante anónimo consulta la ficha: sin veredicto, sin bloqueo de firma.
modal4.open(buildSpellDto(), {});
const anonButton = findSignButton(shell4.detailBody);
assertCondition(anonButton === null, 'Sin contexto de firma (anónimo), la ficha no renderiza bloqueo de firma alguno');

// Sin veredicto pero con maestro: el backend manda; la ficha no inventa veto.
const shell4b = buildDetailShell();
const modal4b = createSpellDetailModalComponent(shell4b.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
});
modal4b.open(buildSpellDto(), {
  signer: { id: 'usr_master_heiter', alias: 'HeiterElSabio', role: 'master', clanId: 'cln_astral' },
});
assertCondition(findSignButton(shell4b.detailBody) === null, 'Sin veredicto del backend, la ficha no predice el veto (fuente única: el servidor)');

// --- FASE 5: Prohibiciones y robustez ---
console.log('\nFASE 5: Prohibiciones (innerHTML) y advertencia accesible');

const shell5 = buildDetailShell();
const modal5 = createSpellDetailModalComponent(shell5.dialogElement, {
  documentRef: { createElement: (tag) => createFakeElement(tag), activeElement: activeElementTracker.current },
});
let innerHTMLRejected = false;
try {
  shell5.detailBody.innerHTML = '<p>pacto oscuro</p>';
} catch {
  innerHTMLRejected = true;
}
assertCondition(innerHTMLRejected, 'innerHTML está prohibido en el árbol de la ficha (Art. I, AGENTS.md 6.1)');

// La advertencia viaja como texto accesible asociado al botón (RNF-03).
modal5.open(buildSpellDto(), {
  signer: { id: 'usr_master_heiter', alias: 'HeiterElSabio', role: 'master', clanId: 'cln_astral' },
  clanConflict: { isAllowed: false, reason: 'El vínculo de sangre nubla el juicio: un Maestro no puede juzgar el trabajo de su propio linaje actual.' },
});
const blockedButton5 = findSignButton(shell5.detailBody);
assertCondition(
  blockedButton5 !== null && (blockedButton5.getAttribute('aria-disabled') === 'true' || blockedButton5.disabled === true),
  'El bloqueo es accesible (aria-disabled o disabled nativo, RNF-03)',
);

// --- Centinela y resumen ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (exige 0)`);

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

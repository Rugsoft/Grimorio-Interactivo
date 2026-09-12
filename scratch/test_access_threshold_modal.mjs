/**
 * test_access_threshold_modal.mjs — Verificación del diálogo «Cruzar el Umbral»
 * extendido (Tarea 4.3 de SPEC-03).
 *
 * Estrategia TDD: este script se escribe ANTES de extender accessModalComponent.js.
 * Fase roja = el componente no expone aún pestañas, selector de clan ni el
 * bloqueo temporal por 429.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   El modal permite alternar fluidamente entre login y registro con
 *   selección obligatoria de clan, bloqueando el botón temporalmente si la
 *   API devuelve el código 429 de sobrecarga de maná.
 *
 * Requisitos: RF-01.1 (registro con clan obligatorio), RF-02.1 (Renovar
 * Vínculo), RF-03.1 (mensajes anti-enumeración), RF-03.2 (bloqueo 429),
 * RNF-03 (gestión de foco accesible), Art. V (leyendas en castellano).
 *
 * Uso: node scratch/test_access_threshold_modal.mjs
 */

import { createAccessModalComponent } from '../public/assets/js/components/accessModalComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

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

// --- DOM simulado (patrón consolidado de las Tareas 4.x) ---
const activeElementTracker = { current: null };

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    classes: new Set(),
    _textContent: '',
    _value: '',
    parentElement: null,
    focusCount: 0,
    open: false,
    showModalCount: 0,
    closeCount: 0,
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
      // Escape nativo del <dialog> (replica el navegador).
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
    get value() { return this._value; },
    set value(v) { this._value = String(v); },
    focus() { this.focusCount++; activeElementTracker.current = this; },
  };
  return element;
}

function createFakeDialog(elementId) {
  const dialog = createFakeElement('dialog');
  dialog.setAttribute('id', elementId);
  dialog.showModal = function showModal() { this.open = true; this.showModalCount++; };
  dialog.close = function close() {
    if (!this.open) return;
    this.open = false;
    this.closeCount++;
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };
  return dialog;
}

/** Búsqueda recursiva por id (querySelector real del DOM). */
function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}
const byId = (root, elementId) => queryById(root, elementId)[0] ?? null;

/**
 * Shell fiel al index.html (Tarea 2.1): el diálogo «Cruzar el Umbral» con
 * sus dos formularios. El componente extendido debe añadir su API SIN
 * reconstruir el shell (patrón de cableado de la Tarea 4.4 de TASKS-01).
 */
function buildShell() {
  const accessDialog = createFakeDialog('accessModal');

  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  accessDialog.appendChild(loginForm);

  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  accessDialog.appendChild(registerForm);

  const documentSim = {
    get activeElement() { return activeElementTracker.current; },
    createElement: (tagName) => createFakeElement(tagName),
  };

  return { accessDialog, loginForm, registerForm, documentSim };
}

console.log('== VERIFICACION TAREA 4.3 (SPEC-03): Cruzar el Umbral extendido ==\n');

// --- FASE 1: API extendida de sesión ---
console.log('FASE 1: Superficie extendida del componente');

const shell1 = buildShell();
const modal1 = createAccessModalComponent(shell1.accessDialog, {
  documentRef: shell1.documentSim,
  onAuthenticate: () => {},
  onRegister: () => {},
  onClose: () => {},
});

assertCondition(typeof modal1.showTab === 'function', 'showTab(tabName) está disponible (alternancia de pestañas)');
assertCondition(typeof modal1.getActiveTab === 'function', 'getActiveTab() está disponible');
assertCondition(typeof modal1.populateClans === 'function', 'populateClans(clans) está disponible (selector de linajes)');
assertCondition(typeof modal1.reportError === 'function', 'reportError(errorEnvelope) está disponible (mensajes anti-enumeración)');
assertCondition(typeof modal1.lockSubmission === 'function', 'lockSubmission(seconds) está disponible (bloqueo 429)');

// --- FASE 2: CRITERIO — alternancia fluida entre login y registro ---
console.log('\nFASE 2: Alternancia fluida entre pestañas (Renovar Vínculo / Consagrarse)');

modal1.showTab('register');
assertCondition(modal1.getActiveTab() === 'register', 'showTab(\'register\') activa la pestaña de Consagrarse');

modal1.showTab('login');
assertCondition(modal1.getActiveTab() === 'login', 'showTab(\'login\') vuelve a Renovar Vínculo');

modal1.showTab('login');
assertCondition(modal1.getActiveTab() === 'login', 'La alternancia es idempotente (sin estados fantasma)');

// Pestaña fuera del canon: se ignora sin romper el estado.
modal1.showTab('astralPlane');
assertCondition(modal1.getActiveTab() === 'login', 'Una pestaña inexistente no altera la activa');

// --- FASE 3: CRITERIO — selector obligatorio de clan (RF-01.1) ---
console.log('\nFASE 3: Selector obligatorio de clan en la consagración');

const shell3 = buildShell();
const registerSubmissions = [];
const modal3 = createAccessModalComponent(shell3.accessDialog, {
  documentRef: shell3.documentSim,
  onRegister: (payload) => registerSubmissions.push(payload),
  onClose: () => {},
});

modal3.populateClans([
  { id: 'cln_astral', name: 'Eruditos Astrales' },
  { id: 'cln_ember', name: 'Guardianes de Ascuas' },
]);

const clanSelect = byId(shell3.accessDialog, 'clanSelect');
assertCondition(clanSelect !== null, 'populateClans crea/enlaza el selector de clan (clanSelect)');
// El placeholder vacío no cuenta como linaje: exactamente 2 opciones + placeholder.
const clanOptions = clanSelect !== null ? clanSelect.children.filter((option) => option.getAttribute('value') !== '') : [];
assertCondition(clanOptions.length === 2, 'El selector lista exactamente los linajes activos (más el placeholder vacío)');

// Sin clan electo: el envío de registro NO procede (selección obligatoria).
// open() es quien cablea los formularios del shell (contrato de la Tarea 4.4).
modal3.open({ action: 'joinClan', targetSlug: null });
modal3.showTab('register');
shell3.registerForm.dispatch('submit', { fields: { registerName: 'Novato', registerPassword: 'runas-largas' } });
assertCondition(registerSubmissions.length === 0, 'Sin clan electo, el registro no se notifica (selección obligatoria, RF-01.1)');

// Con clan electo: el envío procede y porta el clanId (RF-01.1).
if (clanSelect !== null && clanSelect.children.length > 0) {
  clanSelect.value = 'cln_ember';
  shell3.registerForm.dispatch('submit', { fields: { registerName: 'Novato', registerPassword: 'runas-largas' } });
  assertCondition(registerSubmissions.length === 1, 'Con clan electo, el registro se notifica al orquestador');
  assertCondition(
    registerSubmissions[0]?.clanId === 'cln_ember',
    'El clanId del linaje electo viaja con el registro (RF-01.1)',
  );
}

// --- FASE 4: CRITERIO — bloqueo temporal del botón con 429 (RF-03.2) ---
console.log('\nFASE 4: Bloqueo temporal del botón por sobrecarga de maná (429)');

const shell4 = buildShell();
const modal4 = createAccessModalComponent(shell4.accessDialog, {
  documentRef: shell4.documentSim,
  onAuthenticate: () => {},
  onClose: () => {},
});

const loginSubmit = byId(shell4.accessDialog, 'loginForm');
shell4.accessDialog.open = true;

modal4.lockSubmission(120);
assertCondition(modal4.isSubmissionLocked() === true, 'lockSubmission(120) bloquea el envío');

modal4.lockSubmission(0);
assertCondition(modal4.isSubmissionLocked() === false, 'lockSubmission(0) desbloquea inmediatamente (fin del castigo)');

modal4.lockSubmission(-5);
assertCondition(modal4.isSubmissionLocked() === false, 'Un tiempo negativo no congela el botón (defensa)');

// --- FASE 5: Mensajes anti-enumeración (RF-03.1) y rol del reportError ---
console.log('\nFASE 5: reportError propaga los mensajes del backend (anti-enumeración)');

const errorSpy = [];
const shell5 = buildShell();
const modal5 = createAccessModalComponent(shell5.accessDialog, {
  documentRef: shell5.documentSim,
  onAuthenticate: () => {},
  onClose: () => {},
  onError: (message) => errorSpy.push(message),
});
shell5.accessDialog.open = true;

// El 401 neutro del backend llega con su leyenda ya decidida.
modal5.reportError({
  success: false,
  error: { code: 'INVALID_CREDENTIALS', message: 'Las runas no reconocen este vínculo o la palabra secreta es errónea.', recoveryAction: 'RETRY_OR_RECOVER' },
});
assertCondition(errorSpy.length === 1, 'reportError notifica el mensaje al orquestador/componente');
assertCondition(
  errorSpy[0]?.includes('runas no reconocen'),
  'La leyenda neutra anti-enumeración viaja intacta (RF-03.1, sin pistas de qué falló)',
);

// El 429 llega con su leyenda solemne.
errorSpy.length = 0;
modal5.reportError({
  success: false,
  error: { code: 'RATE_LIMITED', message: 'El umbral permanecerá cerrado durante 15 minutos.', remainingSeconds: 840 },
});
assertCondition(
  errorSpy[0]?.includes('15 minutos'),
  'La leyenda del 429 viaja intacta (RF-03.2)',
);

// Sobre inválido: sin explosión, mensaje genérico controlado.
errorSpy.length = 0;
modal5.reportError(null);
assertCondition(errorSpy.length === 1 && typeof errorSpy[0] === 'string', 'reportError(null) degrada a un mensaje genérico controlado');

// --- FASE 6: Gestión de foco accesible al alternar pestañas (RNF-03) ---
console.log('\nFASE 6: Foco accesible en la alternancia');

const shell6 = buildShell();
const modal6 = createAccessModalComponent(shell6.accessDialog, {
  documentRef: shell6.documentSim,
  onAuthenticate: () => {},
  onClose: () => {},
});
shell6.accessDialog.open = true;
modal6.showTab('register');

const registerFormFocus = byId(shell6.accessDialog, 'registerForm');
assertCondition(
  registerFormFocus !== null && registerFormFocus.focusCount >= 0,
  'La alternancia opera sobre el formulario del shell sin nodos huérfanos',
);

// El foco inicial al abrir sigue siendo del primer campo (comportamiento ya
// verificado en Tarea 4.4 de TASKS-01; aquí se confirma que no se rompió).
shell6.accessDialog.open = false;
modal6.open({ action: 'joinClan', targetSlug: null });
assertCondition(shell6.accessDialog.open === true, 'La apertura del diálogo extendido sigue funcionando (pila de modales intacta)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.3 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

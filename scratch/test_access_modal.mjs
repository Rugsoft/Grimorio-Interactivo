/**
 * test_access_modal.mjs — Verificación del diálogo «Cruzar el Umbral» (Tarea 4.4).
 *
 * Estrategia TDD: este script se escribe ANTES que accessModalComponent.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Al invocar el diálogo de acceso con la ficha de detalle abierta, ambos
 *   modales coexisten en el DOM y cerrar el de acceso restaura inmediatamente
 *   la interacción con la ficha técnica.
 *
 * Requisitos: RF-05.1 (opciones Renovar Vínculo / Consagrarse),
 * RF-05.2 (pila sobre la ficha sin destruirla), RF-05.3 (autenticación
 * exitosa reanuda la acción pendiente), RNF-03 (trap de foco propio).
 *
 * Uso: node scratch/test_access_modal.mjs
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

// --- DOM simulado (patrón consolidado de las Tareas 4.1-4.3) ---
const activeElementTracker = { current: null };

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    _textContent: '',
    parentElement: null,
    focusCount: 0,
    open: false,
    showModalCount: 0,
    closeCount: 0,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
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
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML: '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount++; activeElementTracker.current = this; },
  };
  element.classes = new Set();
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

/** Búsqueda recursiva de formularios por id (para submit simulado). */
function findForm(dialog, formId) {
  return queryById(dialog, formId)[0] ?? null;
}

/**
 * Shell con LOS DOS modales del plan 4.2 apilables: la ficha (nivel 1)
 * y el acceso (nivel 2, z-index superior).
 */
function buildFakeShell() {
  const detailDialog = createFakeDialog('spellDetailModal');
  const accessDialog = createFakeDialog('accessModal');

  // Formularios del accessModal (RF-05.1, ids del shell Tarea 2.1).
  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  accessDialog.appendChild(loginForm);
  accessDialog.appendChild(registerForm);

  const documentSim = {
    get activeElement() { return activeElementTracker.current; },
    createElement: (tagName) => createFakeElement(tagName),
    getElementById: (elementId) => {
      if (elementId === 'spellDetailModal') return detailDialog;
      if (elementId === 'accessModal') return accessDialog;
      return null;
    },
  };

  return { detailDialog, accessDialog, loginForm, registerForm, documentSim };
}

console.log('== VERIFICACION TAREA 4.4: accessModalComponent.js ==\n');

// --- FASE 1: Apertura superpuesta a la ficha (RF-05.1/05.2) ---
console.log('FASE 1: Apertura con la ficha abierta (coexistencia)');

const shell1 = buildFakeShell();
const closeCallbacks1 = [];
const accessModal1 = createAccessModalComponent(shell1.accessDialog, {
  documentRef: shell1.documentSim,
  onClose: () => closeCallbacks1.push('closed'),
});

// Escenario del plan 7.2: la ficha de detalle ya está abierta.
shell1.detailDialog.open = true;
shell1.detailDialog.showModalCount = 1;

// El visitante pulsó «Añadir a mi Grimorio» (intercepción de la Tarea 4.3):
const focusBeforeOpen = createFakeElement('button'); // botón de la ficha que tenía el foco.
focusBeforeOpen.focus();

accessModal1.open({ action: 'addToGrimoire', targetSlug: 'llamas-de-frieren' });

assertCondition(shell1.accessDialog.open === true, 'open() despliega el diálogo de acceso (showModal)');
assertCondition(shell1.detailDialog.open === true, 'La ficha de detalle SIGUE abierta: ambos modales coexisten (criterio, RF-05.2)');
assertCondition(shell1.accessDialog.showModalCount === 1, 'El acceso se apila con showModal nativo (z-index superior del plan 4.2)');

// La intención quedó retenida para reanudarla tras autenticarse (RF-05.2/3.2):
const retainedIntent = accessModal1.getPendingIntent();
assertCondition(
  retainedIntent?.action === 'addToGrimoire' && retainedIntent?.targetSlug === 'llamas-de-frieren',
  'La intención interceptada queda retenida (getPendingIntent)'
);

// --- FASE 2: Opciones visibles — Renovar Vínculo y Consagrarse (RF-05.1) ---
console.log('\nFASE 2: Formularios de Renovar Vínculo y Consagrarse');

// El componente debe cablear AMBOS formularios del shell.
const loginSubmissions = [];
const registerSubmissions = [];
const accessModal2 = createAccessModalComponent(shell1.accessDialog, {
  documentRef: shell1.documentSim,
  onAuthenticate: (credentials, intent) => loginSubmissions.push({ credentials, intent }),
  onRegister: (credentials, intent) => registerSubmissions.push({ credentials, intent }),
  onClose: () => {},
});

// open de nuevo con la nueva instancia (re-bind limpio).
shell1.accessDialog.open = false;
accessModal2.open({ action: 'addToGrimoire', targetSlug: 'llamas-de-frieren' });

// Envío del formulario de login (Renovar Vínculo):
const liveLoginForm = findForm(shell1.accessDialog, 'loginForm');
liveLoginForm.dispatch('submit', { fields: { loginName: 'friki', loginPassword: 'mana' } });

assertCondition(loginSubmissions.length === 1, 'loginForm envía credenciales vía onAuthenticate (RF-05.1)');
assertCondition(
  loginSubmissions[0]?.credentials?.loginName === 'friki' && loginSubmissions[0]?.credentials?.loginPassword === 'mana',
  'Las credenciales llegan íntegras sin exponerse en la URL'
);
assertCondition(
  loginSubmissions[0]?.intent?.action === 'addToGrimoire',
  'La intención retenida acompaña a la autenticación (para reanudarla, RF-05.3)'
);
assertCondition(shell1.accessDialog.open === false, 'Tras el envío, el diálogo se cierra (flujo completo)');

// La ficha sigue viva debajo:
assertCondition(shell1.detailDialog.open === true, 'La ficha permanece abierta tras autenticar (pila intacta)');

// Envío del formulario de registro (Consagrarse):
accessModal2.open({ action: 'openCreator', targetSlug: null });
const liveRegisterForm = findForm(shell1.accessDialog, 'registerForm');
liveRegisterForm.dispatch('submit', { fields: { registerName: 'novato', registerPassword: 'runas' } });

assertCondition(registerSubmissions.length === 1, 'registerForm envía credenciales vía onRegister (RF-05.1)');
assertCondition(
  registerSubmissions[0]?.credentials?.registerName === 'novato',
  'Las credenciales de consagración llegan íntegras'
);
assertCondition(shell1.accessDialog.open === false, 'Tras consagrarse, el diálogo se cierra');

// --- FASE 3: CRITERIO — cerrar el acceso restaura la ficha inmediatamente ---
console.log('\nFASE 3: Criterio (cierre restaura la interacción con la ficha)');

const shell3 = buildFakeShell();
let restoredFocusTarget = null;
let closeCount3 = 0; // Contador del onClose (declarado antes de usarse).
const accessModal3 = createAccessModalComponent(shell3.accessDialog, {
  documentRef: shell3.documentSim,
  onClose: () => {
    // El orquestador (Tarea 6.1) restaurará el foco al contexto previo:
    restoredFocusTarget = shell3.detailDialog;
    closeCount3++;
  },
});

shell3.detailDialog.open = true; // ficha abierta debajo.
const previousFocusButton = createFakeElement('button');
previousFocusButton.focus(); // el foco vivía en la ficha antes del acceso.

accessModal3.open({ action: 'signSpell', targetSlug: 'manto-de-niebla' });
assertCondition(shell3.accessDialog.open === true && shell3.detailDialog.open === true, 'Escenario montado: ficha + acceso apilados');

// Cierre por Escape (nativo del dialog):
shell3.accessDialog.dispatch('keydown', { key: 'Escape' });

assertCondition(shell3.accessDialog.open === false, 'Escape cierra SOLO el diálogo de acceso');
assertCondition(shell3.detailDialog.open === true, 'La ficha de detalle sigue abierta e interactiva (criterio)');
assertCondition(closeCount3 === 1, 'El cierre notificó al orquestador (onClose), exactamente una vez');

assertCondition(restoredFocusTarget === shell3.detailDialog, 'El orquestador restaura el contexto hacia la ficha (sin reinicios de página)');

// El foco regresa al contexto previo (plan 4.2: restitución inmediata).
accessModal3.restorePreviousFocus?.(previousFocusButton);
assertCondition(previousFocusButton.focusCount === 1, 'El foco vuelve al control de la ficha que tenía el usuario (plan 4.2)');

// --- FASE 4: Cancelación sin autenticarse — la intención puede conservarse ---
console.log('\nFASE 4: Cancelación (Escape sin enviar formularios)');

const shell4 = buildFakeShell();
const authCalls4 = [];
const accessModal4 = createAccessModalComponent(shell4.accessDialog, {
  documentRef: shell4.documentSim,
  onAuthenticate: (credentials, intent) => authCalls4.push({ credentials, intent }),
  onClose: () => {},
});

shell4.detailDialog.open = true;
accessModal4.open({ action: 'joinClan', targetSlug: 'cln_primordial' });
shell4.accessDialog.dispatch('keydown', { key: 'Escape' });

assertCondition(shell4.accessDialog.open === false, 'El cierre por cancelación funciona sin formularios enviados');
assertCondition(authCalls4.length === 0, 'Sin envío no hay autenticación (la intención queda en el store, no aquí)');
assertCondition(
  accessModal4.getPendingIntent()?.action === 'joinClan',
  'La intención retenida sobrevive a la cancelación (el orquestador decide, RF-05.4)'
);

// --- FASE 5: Sin ficha debajo (apertura directa desde la portada, RF-01.4) ---
console.log('\nFASE 5: Apertura directa sin ficha subyacente');

const shell5 = buildFakeShell();
const accessModal5 = createAccessModalComponent(shell5.accessDialog, {
  documentRef: shell5.documentSim,
  onClose: () => {},
});

accessModal5.open({ action: 'joinClan', targetSlug: null });

assertCondition(shell5.accessDialog.open === true, 'El acceso se abre igualmente desde la portada (RF-01.4)');
assertCondition(shell5.detailDialog.open === false, 'Sin ficha debajo, nada más queda abierto');
shell5.accessDialog.close();
assertCondition(shell5.accessDialog.open === false, 'El cierre directo funciona sin contexto previo');

// --- FASE 6: Trap de foco propio (RNF-03) ---
console.log('\nFASE 6: Atrapamiento de foco en el diálogo de acceso');

const shell6 = buildFakeShell();
const accessModal6 = createAccessModalComponent(shell6.accessDialog, {
  documentRef: shell6.documentSim,
  onClose: () => {},
});
accessModal6.open({ action: 'openCreator', targetSlug: null });

const focusableInAccess = findForm(shell6.accessDialog, 'loginForm');
activeElementTracker.current = focusableInAccess;
let accessTrapPrevented = false;
shell6.accessDialog.dispatch('keydown', { key: 'Tab', preventDefault() { accessTrapPrevented = true; } });
assertCondition(accessTrapPrevented === true, 'El Tab en el borde del acceso se intercepta (trap propio, RNF-03)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.4 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

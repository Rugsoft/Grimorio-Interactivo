/**
 * test_recovery_modal.mjs — Verificación del componente de recuperación
 * de credenciales (Tarea 4.4 de SPEC-03).
 *
 * Estrategia TDD: este script se escribe ANTES que recoveryModalComponent.js.
 * Fase roja = el módulo public/assets/js/components/recoveryModalComponent.js
 * no existe todavía.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   El usuario puede solicitar el restablecimiento y visualizar la
 *   notificación temática de que el pergamino ha sido remitido.
 *
 * Contratos verificados (RF-04.1, RF-04.2, plan Endpoint 5):
 *   - Solicitud: `requestRecovery({ email })` → POST /auth/recovery/request
 *     con respuesta SIEMPRE neutra e idéntica exista o no el correo
 *     (anti-enumeración): la notificación temática se muestra en ambos casos.
 *   - Restablecimiento: con token en el diálogo (enlace de correo),
 *     `submitReset(token, newPassphrase)` → POST /auth/recovery/reset;
 *     éxito = notificación de sellado (sesiones revocadas en el backend);
 *     token podrido = 400 RECOVERY_TOKEN_INVALID mostrado sin explosión.
 *   - Art. I: DOM y fetch nativos (prohibición de innerHTML verificada).
 *   - Art. IV/V: leyendas solemnes en castellano; identificadores camelCase.
 *
 * Uso: node scratch/test_recovery_modal.mjs
 */

import { createRecoveryModalComponent } from '../public/assets/js/components/recoveryModalComponent.js';

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

// --- DOM simulado (patrón consolidado de las Tareas 4.x) ---
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
    focus() {},
  };
  return element;
}

function createFakeDialog(elementId) {
  const dialog = createFakeElement('dialog');
  dialog.setAttribute('id', elementId);
  dialog.querySelector = null; // El arnés usa búsqueda recursiva por id.
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

/** Extrae el mensaje mostrado por el componente (nodo de notificación). */
function getNoticeText(dialog) {
  return byId(dialog, 'recoveryNotice')?.textContent ?? '';
}

console.log('== VERIFICACION TAREA 4.4 (SPEC-03): recoveryModalComponent.js ==\n');

// --- FASE 0: Existencia y superficie exportada ---
console.log('FASE 0: Superficie del componente');

assertCondition(typeof createRecoveryModalComponent === 'function', 'createRecoveryModalComponent() está exportada');

const shell0 = { dialog: createFakeDialog('recoveryModal'), documentSim: { createElement: (tag) => createFakeElement(tag) } };
const modal0 = createRecoveryModalComponent(shell0.dialog, {
  documentRef: shell0.documentSim,
  onRequestRecovery: () => {},
  onSubmitReset: () => {},
});

assertCondition(typeof modal0.open === 'function', 'open() está disponible');
assertCondition(typeof modal0.openResetMode === 'function', 'openResetMode(token) está disponible (enlace con token del correo)');
assertCondition(typeof modal0.close === 'function', 'close() está disponible');
assertCondition(typeof modal0.isOpen === 'function', 'isOpen() está disponible');
assertCondition(typeof modal0.destroy === 'function', 'destroy() está disponible');

// --- FASE 1: CRITERIO — solicitud del pergamino con notificación temática ---
console.log('\nFASE 1: Solicitud de restablecimiento (RF-04.1)');

const requests1 = [];
const shell1 = { dialog: createFakeDialog('recoveryModal'), documentSim: { createElement: (tag) => createFakeElement(tag) } };
const modal1 = createRecoveryModalComponent(shell1.dialog, {
  documentRef: shell1.documentSim,
  onRequestRecovery: (email) => requests1.push(email),
});

modal1.open();
assertCondition(shell1.dialog.open === true, 'open() despliega el diálogo de recuperación (modo solicitud)');

// El componente forja el formulario de solicitud (shell SPEC-03 aún sin markup).
const requestForm1 = byId(shell1.dialog, 'recoveryRequestForm');
assertCondition(requestForm1 !== null, 'El formulario de solicitud existe (recoveryRequestForm)');
assertCondition(byId(shell1.dialog, 'recoveryEmail') !== null, 'El campo de correo existe (recoveryEmail)');

// Envío con correo registrado.
requestForm1.dispatch('submit', { fields: { email: 'frieren@sanctuario.arc' } });
assertCondition(requests1.length === 1 && requests1[0] === 'frieren@sanctuario.arc', 'El envío notifica el correo al orquestador (onRequestRecovery)');

// La notificación temática neutra llega por reportRecoveryNotice: en ambos
// caminos (correo registrado o no) el mensaje es idéntico (anti-enumeración).
modal1.reportRecoveryNotice({
  success: true,
  data: { message: 'Si ese correo habita el santuario, el pergamino de restablecimiento ha sido remitido.' },
});
assertCondition(
  getNoticeText(shell1.dialog).includes('pergamino de restablecimiento ha sido remitido'),
  'La notificación temática del pergamino remitido se muestra (criterio literal)',
);

// Correo ajeno: la respuesta del backend es idéntica → misma notificación.
requests1.length = 0;
requestForm1.dispatch('submit', { fields: { email: 'fantasma@sanctuario.arc' } });
modal1.reportRecoveryNotice({
  success: true,
  data: { message: 'Si ese correo habita el santuario, el pergamino de restablecimiento ha sido remitido.' },
});
assertCondition(
  getNoticeText(shell1.dialog).includes('pergamino de restablecimiento ha sido remitido'),
  'Con correo ajeno, la notificación es idéntica (anti-enumeración, RF-04.1)',
);

// Correo vacío: el envío no procede (defensa local antes de gastar la API).
requests1.length = 0;
requestForm1.dispatch('submit', { fields: { email: '' } });
assertCondition(requests1.length === 0, 'Un correo vacío no se notifica (defensa local, RF-01.1)');

// --- FASE 2: Modo token — nueva frase de paso (RF-04.2) ---
console.log('\nFASE 2: Restablecimiento con token del enlace (RF-04.2)');

const resets2 = [];
const shell2 = { dialog: createFakeDialog('recoveryModal'), documentSim: { createElement: (tag) => createFakeElement(tag) } };
const modal2 = createRecoveryModalComponent(shell2.dialog, {
  documentRef: shell2.documentSim,
  onSubmitReset: (token, newPassphrase) => resets2.push({ token, newPassphrase }),
});

modal2.openResetMode('tok_sec_1234567890');
assertCondition(shell2.dialog.open === true, 'openResetMode(token) despliega el diálogo en modo restablecimiento');
assertCondition(byId(shell2.dialog, 'recoveryResetForm') !== null, 'El formulario de restablecimiento existe (recoveryResetForm)');
assertCondition(byId(shell2.dialog, 'recoveryToken') !== null, 'El campo de token existe (recoveryToken)');
assertCondition(byId(shell2.dialog, 'recoveryNewPassphrase') !== null, 'El campo de nueva frase existe (recoveryNewPassphrase)');

// Envío completo: token + nueva frase viajan al orquestador.
const resetForm2 = byId(shell2.dialog, 'recoveryResetForm');
resetForm2.dispatch('submit', {
  fields: { token: 'tok_sec_1234567890', newPassphrase: 'nueva-palabra-arcana-678' },
});
assertCondition(
  resets2.length === 1 && resets2[0].token === 'tok_sec_1234567890' && resets2[0].newPassphrase === 'nueva-palabra-arcana-678',
  'El envío notifica token y nueva frase al orquestador (onSubmitReset)',
);

// Frase corta: defensa local antes de gastar la API (canon de 8 caracteres).
resets2.length = 0;
resetForm2.dispatch('submit', { fields: { token: 'tok_sec_1234567890', newPassphrase: 'corta' } });
assertCondition(resets2.length === 0, 'Una frase de menos de 8 caracteres no se notifica (canon RF-01.1)');

// Éxito del restablecimiento: notificación de sellado.
modal2.reportResetSuccess();
assertCondition(
  getNoticeText(shell2.dialog).includes('sellada') || getNoticeText(shell2.dialog).includes('nueva frase'),
  'El éxito muestra la notificación de frase sellada con sesiones revocadas',
);

// Token podrido: 400 del backend mostrado sin explosión.
modal2.reportError({
  success: false,
  error: { code: 'RECOVERY_TOKEN_INVALID', message: 'El pergamino no es válido, ya fue consumido o su tinta se ha secado (caducó).' },
});
assertCondition(
  getNoticeText(shell2.dialog).includes('ya fue consumido') || getNoticeText(shell2.dialog).includes('no es válido'),
  'El 400 RECOVERY_TOKEN_INVALID se muestra con su leyenda (RF-04.2, sin excepción)',
);

// --- FASE 3: Cierre y limpieza ---
console.log('\nFASE 3: Cierre, limpiezas y prohibiciones');

const shell3 = { dialog: createFakeDialog('recoveryModal'), documentSim: { createElement: (tag) => createFakeElement(tag) } };
const modal3 = createRecoveryModalComponent(shell3.dialog, {
  documentRef: shell3.documentSim,
  onRequestRecovery: () => {},
});
modal3.open();
modal3.close();
assertCondition(shell3.dialog.open === false, 'close() retira el diálogo');
assertCondition(modal3.isOpen() === false, 'isOpen() refleja el estado cerrado');

// Destroy: el componente queda inerte sin fugas.
modal3.destroy();
let destroyedInert = true;
try {
  modal3.open();
  if (shell3.dialog.open) destroyedInert = false;
} catch {
  destroyedInert = false;
}
assertCondition(destroyedInert, 'Tras destroy(), open() es inerte (sin fugas de listeners)');

// --- Centinela y resumen ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (exige 0)`);

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.4 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

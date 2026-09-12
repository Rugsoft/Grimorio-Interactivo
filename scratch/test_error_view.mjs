/**
 * test_error_view.mjs — Verificación de las Vistas de Error y Rescate (Tarea 5.5).
 *
 * Estrategia TDD: este script se escribe ANTES que errorView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Una búsqueda sin coincidencias muestra la leyenda temática con botón de
 *   reseteo, y un error 404 ofrece un enlace directo de retorno a la biblioteca.
 *
 * Requisitos: RF-06.1 (búsqueda vacía), RF-06.2 (404 «Pergamino desvanecido
 * en el éter», sobre estándar del plan 2.4), RF-06.3 (corte de maná con
 * reintento), integración con API_ERROR_CODES del cliente (Tarea 3.3).
 *
 * Uso: node scratch/test_error_view.mjs
 */

import { createErrorView, ERROR_RECOVERY_ACTIONS } from '../public/assets/js/views/errorView.js';
import { API_ERROR_CODES } from '../public/assets/js/api/spellClient.js';

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

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 5.1-5.4). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    parentElement: null,
    focusCount: 0,
    href: '',
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
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount++; },
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
const allByClass = (root, className) => queryByClass(root, className);

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Sobre de 404 exacto al contrato del plan 2.4. */
const notFoundEnvelope = {
  success: false,
  error: {
    code: API_ERROR_CODES.scrollLost,
    message: 'El pergamino que buscas se ha desvanecido en el éter.',
    details: "No se encontró ningún conjuro activo registrado bajo el identificador 'trueno-prohibido'.",
    recoveryAction: 'RETURN_TO_LIBRARY',
  },
};

/** Sobre de corte de red del cliente HTTP (Tarea 3.3). */
const networkEnvelope = {
  success: false,
  error: {
    code: API_ERROR_CODES.networkError,
    message: 'La corriente de maná se ha interrumpido.',
    recoveryAction: 'RETRY',
  },
};

console.log('== VERIFICACION TAREA 5.5: errorView.js ==\n');

// --- FASE 1: CRITERIO — búsqueda vacía con leyenda temática y reseteo ---
console.log('FASE 1: Criterio (búsqueda vacía → leyenda + botón de reseteo)');

const resets1 = [];
const root1 = createFakeElement('main');
const error1 = createErrorView(root1, {
  elementFactory: fakeElementFactory,
  onResetFilters: () => resets1.push('reset'),
  onReturnToLibrary: () => {},
  onRetry: () => {},
});

error1.render({
  kind: 'emptySearch',
  message: 'Ningún conjuro responde a esas runas.',
});

assertCondition(byClass(root1, 'error-view') !== null, 'La vista de error monta su raíz (RF-06.1)');
assertCondition(byClass(root1, 'error-view') !== null && byClass(root1, 'error-view').getAttribute('data-kind') === 'emptySearch', 'La raíz declara el tipo de estado (data-kind) para el CSS temático');
const message1 = byClass(root1, 'error-view__message');
assertCondition(message1 !== null && message1.textContent.includes('Ningún conjuro responde a esas runas'), 'La leyenda temática exacta de búsqueda vacía se muestra (RF-06.1, criterio)');

const resetButton1 = byClass(root1, 'error-view__reset');
assertCondition(resetButton1 !== null, 'El botón de restablecimiento existe (criterio, RF-06.1)');
resetButton1.dispatch('click');
assertCondition(resets1.length === 1, 'El clic en el reseteo notifica al orquestador (onResetFilters)');
assertCondition(resetButton1 !== null && resetButton1.textContent.length > 0, 'El botón de reseteo porta texto visible (Art. IV)');

// --- FASE 2: CRITERIO — 404 con enlace directo de retorno a la biblioteca ---
console.log('\nFASE 2: Criterio (404 → «Pergamino desvanecido» + retorno directo)');

const returns2 = [];
const root2 = createFakeElement('main');
const error2 = createErrorView(root2, {
  elementFactory: fakeElementFactory,
  onResetFilters: () => {},
  onReturnToLibrary: () => returns2.push('library'),
  onRetry: () => {},
});

error2.render({ errorEnvelope: notFoundEnvelope });

const message2 = byClass(root2, 'error-view__message');
assertCondition(message2 !== null && message2.textContent.includes('desvanecido en el éter'), 'El mensaje del 404 porta «Pergamino desvanecido en el éter» (RF-06.2, criterio)');

const details2 = byClass(root2, 'error-view__details');
assertCondition(details2 !== null && details2.textContent.includes('trueno-prohibido'), 'Los `details` del sobre (plan 2.4) se muestran como texto seguro');

const returnLink2 = byClass(root2, 'error-view__return');
assertCondition(returnLink2 !== null, 'El enlace directo de retorno a la biblioteca existe (criterio, RF-06.2)');
assertCondition(returnLink2 !== null && returnLink2.getAttribute('href') === '#/biblioteca', 'El enlace apunta a la ruta de la biblioteca (retorna directo)');
returnLink2.dispatch('click');
assertCondition(returns2.length === 1, 'El clic en el enlace de retorno notifica al orquestador (onReturnToLibrary)');

// --- FASE 3: Corte de maná con reintento (RF-06.3) ---
console.log('\nFASE 3: Corte de maná → mensaje + botón de reintento');

const retries3 = [];
const root3 = createFakeElement('main');
const error3 = createErrorView(root3, {
  elementFactory: fakeElementFactory,
  onResetFilters: () => {},
  onReturnToLibrary: () => {},
  onRetry: () => retries3.push('retry'),
});

error3.render({ errorEnvelope: networkEnvelope });

const message3 = byClass(root3, 'error-view__message');
assertCondition(message3 !== null && message3.textContent.includes('corriente de maná'), 'El mensaje del corte de red se muestra (RF-06.3)');
const retryButton3 = byClass(root3, 'error-view__retry');
assertCondition(retryButton3 !== null, 'El botón de reintento existe (RF-06.3)');
retryButton3.dispatch('click');
assertCondition(retries3.length === 1, 'El clic en el reintento notifica al orquestador (onRetry)');

// Enlace de rescate alternativo disponible también en la caída:
assertCondition(byClass(root3, 'error-view__return') !== null, 'La caída ofrece también el retorno a la biblioteca como rescate alternativo');

// --- FASE 4: Robustez — sobre desconocido, sanitización y ciclo de vida ---
console.log('\nFASE 4: Robustez y ciclo de vida');

const root4 = createFakeElement('main');
const error4 = createErrorView(root4, {
  elementFactory: fakeElementFactory,
  onResetFilters: () => {},
  onReturnToLibrary: () => {},
  onRetry: () => {},
});

// Sobre con código no catalogado: mensaje genérico, sin explotar.
error4.render({
  errorEnvelope: { success: false, error: { code: 'CURSE_UNKNOWN', message: 'Algo arcano falló.' } },
});
assertCondition(byClass(root4, 'error-view') !== null && byClass(root4, 'error-view__message') !== null, 'Un código de error desconocido muestra un estado genérico sin explotar');
assertCondition(byClass(root4, 'error-view__retry') !== null, 'El estado genérico ofrece reintento (degradación elegante)');

// Markup hostil en los detalles: viaja como texto (textContent), nunca markup.
const root4b = createFakeElement('main');
const error4b = createErrorView(root4b, {
  elementFactory: fakeElementFactory,
  onResetFilters: () => {},
  onReturnToLibrary: () => {},
  onRetry: () => {},
});
error4b.render({
  errorEnvelope: { success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'Fallo', details: '<img src=x onerror=alert(1)>' } },
});
const hostileDetails = byClass(root4b, 'error-view__details');
assertCondition(hostileDetails !== null && hostileDetails.textContent.includes('<img'), 'El markup hostil viaja como TEXTO literal (cero innerHTML, AGENTS.md 6.1)');

// Re-render idempotente y destroy():
error4b.render({ kind: 'emptySearch', message: 'Vacío de nuevo.' });
const renderedRoots = allByClass(root4b, 'error-view');
assertCondition(renderedRoots.length === 1, 'Un segundo render reemplaza el estado anterior sin duplicarlo (idempotente)');
error4b.destroy();
assertCondition(allByClass(root4b, 'error-view').length === 0, 'destroy() limpia la vista del montaje');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.5 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

/**
 * test_convalescence_banner_component.mjs — Arnés de la Tarea 5.3 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/components/convalescenceBannerComponent.js`
 * el criterio «Hecho cuando»:
 *   «Un usuario en convalecencia ve en su perfil el contador de días restantes
 *    y las opciones de afiliación a nuevos clanes aparecen deshabilitadas con
 *    la leyenda ceremonial.»
 *
 * Fases:
 *   [1]  Superficie del módulo y Dogma Vanilla (cero dependencias, cero sondeo).
 *   [2]  Aritmética de los 14 días, espejo exacto del DTO del backend (RNF-01).
 *   [3]  El aviso del perfil: contador de días y leyenda ceremonial (RF-01.7).
 *   [4]  Barra decreciente y re-render idempotente.
 *   [5]  Veto de afiliación: bloqueo terminante (RF-01.6).
 *   [6]  Fin de la convalecencia: el veto se levanta y el estado previo vuelve.
 *   [7]  Bus de eventos del plan 4.1.
 *   [8]  Ciclo de vida: destroy, escrituras tardías y cero fugas.
 *   [9]  XSS: el perfil hostil viaja como texto literal (AGENTS.md 6.1).
 *   [10] Integración con la franja del perfil y el orquestador.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createConvalescenceBannerComponent,
  convalescenceDaysRemaining,
  convalescenceLegend,
  isInConvalescence,
  CONVALESCENCE_DAYS,
  CONVALESCENCE_TITLE,
  AFFILIATION_VETO_LEGEND,
  AFFILIATION_VETO_MARK,
  CONVALESCENCE_STARTED_EVENT,
  CONVALESCENCE_ENDED_EVENTS,
} from '../public/assets/js/components/convalescenceBannerComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/**
 * Elemento DOM mínimo simulado (patrón consolidado de los arneses previos).
 * El accesor `innerHTML` LANZA: cualquier intento de pintar con innerHTML
 * rompe la suite (AGENTS.md 6.1).
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    disabled: false,
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName) {
      // Un único objeto de evento compartido por todos los oyentes, como en
      // el navegador: así `defaultPrevented` es observable aguas abajo.
      const event = {
        type: eventName,
        defaultPrevented: false,
        propagationStopped: false,
        currentTarget: this,
        target: this,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
      };
      for (const listener of [...(this.listeners[eventName] ?? [])]) listener(event);
      return event;
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
    get textContent() {
      return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() {},
    querySelector() { return null; },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Barrido recursivo por clase sobre el DOM simulado. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;

/** Búsqueda recursiva por atributo id. */
function findById(root, elementId) {
  for (const child of root.children) {
    if (child.getAttribute('id') === elementId) return child;
    const found = findById(child, elementId);
    if (found) return found;
  }
  return null;
}

/**
 * Simula un gesto de usuario sobre un control: el navegador NO entrega clics
 * a un control `disabled` (por eso el veto con `disabled` es terminante).
 *
 * @returns {object|null} El evento despachado, o null si el gesto fue vedado
 *          por el propio navegador.
 */
function dispatchUserGesture(control) {
  if (control.disabled === true || control.hasAttribute('disabled')) return null;
  return control.dispatch('click');
}

/** Bus de eventos simulado (plan 4.1): registra y emite. */
function createFakeBus() {
  const listeners = new Map();
  return {
    addEventListener(name, listener) { (listeners.get(name) ?? listeners.set(name, []).get(name)).push(listener); },
    removeEventListener(name, listener) {
      listeners.set(name, (listeners.get(name) ?? []).filter((l) => l !== listener));
    },
    listenerCount(name) { return (listeners.get(name) ?? []).length; },
    emit(name, detail) { for (const listener of [...(listeners.get(name) ?? [])]) listener({ type: name, detail }); },
  };
}

/** Instante fijo del arnés: nada lee el reloj del sistema (RNF-01). */
const NOW_ISO = '2026-09-01T12:00:00Z';
const NOW_MILLIS = Date.parse(NOW_ISO);

/** Marca ISO 8601 UTC a `days` días + `hours` horas del instante del arnés. */
function isoFromNow(days, hours = 0) {
  return new Date(NOW_MILLIS + days * 86400000 + hours * 3600000).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** Sobre de membresía cerrada (ClanMemberDto del backend, Endpoints 7 y 8). */
function closedMembership(days, extra = {}) {
  return {
    id: 'mem_cerrada',
    clanId: 'cln_ember',
    userId: 'usr_penitente',
    userAlias: 'Frieren',
    role: 'adept',
    joinedAt: '2026-06-01T00:00:00Z',
    leftAt: NOW_ISO,
    convalescenceExpiresAt: isoFromNow(days),
    isActive: false,
    ...extra,
  };
}

/** Forja un control de afiliación con su contador de ejecuciones. */
function createAffiliationControl(tagName, label) {
  const control = createFakeElement(tagName);
  control.setAttribute('data-clan-action', label);
  control.actionCount = 0;
  control.addEventListener('click', () => { control.actionCount += 1; });
  return control;
}

// =====================================================================
// FASE 1 — Superficie del módulo y Dogma Vanilla
// =====================================================================
console.log('\n[FASE 1] Superficie del módulo y Dogma Vanilla (Artículo I)');

const componentSource = await readFile(
  new URL('../public/assets/js/components/convalescenceBannerComponent.js', import.meta.url),
  'utf8',
);
const stylesSource = await readFile(
  new URL('../public/assets/css/components.css', import.meta.url),
  'utf8',
);
const shellSource = await readFile(new URL('../public/index.html', import.meta.url), 'utf8');
const orchestratorSource = await readFile(new URL('../public/assets/js/main.js', import.meta.url), 'utf8');

assertCondition(CONVALESCENCE_DAYS === 14, 'La convalecencia canónica dura catorce (14) días naturales (RF-01.6)');
assertCondition(CONVALESCENCE_TITLE === 'Convalecencia Arcana', 'El título ceremonial del aviso es «Convalecencia Arcana»');
assertCondition(typeof createConvalescenceBannerComponent === 'function', 'El módulo exporta la fábrica del componente');
assertCondition((componentSource.match(/^\s*import\s/gm) ?? []).length === 0, 'El Dogma Vanilla manda: el componente no importa módulo alguno');
assertCondition(!/\bfetch\s*\(/.test(componentSource), 'El componente jamás consulta la API por su cuenta (su perfil se lo entrega el orquestador)');
assertCondition(!/\.innerHTML\s*=/.test(componentSource), 'Cero asignaciones a innerHTML (AGENTS.md 6.1): el DOM se forja con textContent');
assertCondition(!/(setInterval|setTimeout)\s*\(/.test(componentSource), 'Sin sondeo periódico (RNF-02): la cuenta se refresca al remontar el aviso');

// =====================================================================
// FASE 2 — Aritmética de los catorce días (espejo del backend)
// =====================================================================
console.log('\n[FASE 2] Aritmética de los 14 días, espejo del DTO del backend (RNF-01)');

assertCondition(convalescenceDaysRemaining(isoFromNow(14), NOW_MILLIS) === 14, 'A catorce días exactos del fin: restan 14 días naturales');
assertCondition(convalescenceDaysRemaining(isoFromNow(5), NOW_MILLIS) === 5, 'A cinco días del fin: restan 5 días');
assertCondition(convalescenceDaysRemaining(isoFromNow(0, 2), NOW_MILLIS) === 1, 'Dos horas de purga se alzan al día entero: resta 1 día (ceil, no floor)');
assertCondition(convalescenceDaysRemaining(isoFromNow(13, 2), NOW_MILLIS) === 14, 'Trece días y dos horas se alzan a catorce: dos horas equivalen a un día');
assertCondition(convalescenceDaysRemaining(NOW_ISO, NOW_MILLIS) === null, 'La frontera es inclusiva: en el instante exacto del fin ya no hay convalecencia');
assertCondition(convalescenceDaysRemaining(isoFromNow(-1), NOW_MILLIS) === null, 'Una marca pasada no acredita convalecencia alguna');
assertCondition(convalescenceDaysRemaining(null, NOW_MILLIS) === null, 'Marca nula (adepto no convaleciente) devuelve null, jamás cero');
assertCondition(convalescenceDaysRemaining('', NOW_MILLIS) === null, 'Marca vacía devuelve null');
assertCondition(convalescenceDaysRemaining('no-es-una-fecha', NOW_MILLIS) === null, 'Marca ilegible degrada a null en vez de romper el perfil');
assertCondition(convalescenceDaysRemaining(undefined, NOW_MILLIS) === null, 'Marca ausente devuelve null');
assertCondition(
  convalescenceDaysRemaining(isoFromNow(5), isoFromNow(0)) === 5,
  'El instante de consulta puede llegar como marca ISO (espejo de DateTimeImmutable)',
);
assertCondition(
  convalescenceDaysRemaining(isoFromNow(5), new Date(NOW_MILLIS)) === 5,
  'El instante también puede llegar como Date nativo',
);
assertCondition(
  convalescenceDaysRemaining(isoFromNow(7).replace('Z', '+00:00'), NOW_MILLIS) === 7,
  'La notación con desplazamiento (+00:00) se interpreta igual que la canónica Z',
);
assertCondition(isInConvalescence(isoFromNow(1), NOW_MILLIS) === true, 'isInConvalescence() es verdadero mientras la marca sea futura');
assertCondition(isInConvalescence(NOW_ISO, NOW_MILLIS) === false, 'isInConvalescence() es falso en la frontera exacta');
assertCondition(
  convalescenceLegend(5) === 'En Convalecencia Arcana: restan 5 días de meditación',
  'La leyenda reproduce la fórmula canónica de RF-01.7',
);
assertCondition(
  convalescenceLegend(1) === 'En Convalecencia Arcana: resta 1 día de meditación',
  'El último día concuerda en singular',
);
assertCondition(convalescenceLegend(0).includes('restan 0 días'), 'Sin días restantes la leyenda no miente con un singular');

// =====================================================================
// FASE 3 — El aviso del perfil
// =====================================================================
console.log('\n[FASE 3] El aviso del perfil: contador de días y leyenda (RF-01.7, RNF-03)');

const profileRoot = createFakeElement('div');
let vetoAttempts = [];
const notice = createConvalescenceBannerComponent(profileRoot, {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
  onVetoedAttempt: (control) => vetoAttempts.push(control),
});

const membership = closedMembership(5, { clanName: 'Custodios de la Llama' });
const shown = notice.setConvalescence(membership);

assertCondition(shown === true, 'Un mago con convalecencia vigente despliega el aviso y devuelve true');
assertCondition(notice.isActive() === true, 'isActive() confirma la purga vigente');
assertCondition(notice.daysRemaining() === 5, 'daysRemaining() publica los 5 días restantes');

const banner = findById(profileRoot, 'convalescenceBanner');
assertCondition(banner !== null, 'El aviso se incrusta en el perfil del mago (contenedor del orquestador)');
assertCondition(banner?.getAttribute('role') === 'region', 'El aviso es una región ARIA con nombre accesible (RNF-03)');
assertCondition(banner?.getAttribute('aria-labelledby') === 'convalescenceBannerTitle', 'La región se etiqueta con el título del aviso');
assertCondition(banner?.getAttribute('data-days-remaining') === '5', 'La región publica los días restantes como dato del contrato');
assertCondition(
  findById(profileRoot, 'convalescenceBannerTitle')?.textContent === 'Convalecencia Arcana',
  'El título del aviso es el ceremonial',
);

const legendNode = byClass(profileRoot, 'convalescence-banner__legend');
assertCondition(
  legendNode?.textContent === 'En Convalecencia Arcana: restan 5 días de meditación',
  'El contador se lee «En Convalecencia Arcana: restan 5 días de meditación»',
);
assertCondition(legendNode?.getAttribute('role') === 'status', 'El contador es un estado anunciado por cortesía');
assertCondition(legendNode?.getAttribute('aria-live') === 'polite', 'El anuncio es cortés (aria-live=polite)');
assertCondition(legendNode?.getAttribute('aria-atomic') === 'true', 'El anuncio es atómico: no se lee a medias');

const penitentNode = byClass(profileRoot, 'convalescence-banner__penitent');
assertCondition(
  penitentNode?.textContent === 'El mago Frieren medita su retorno tras partir de la casa «Custodios de la Llama».',
  'El aviso nombra al mago y la casa de la que partió (visibilidad pública, RF-01.7)',
);
assertCondition(
  byClass(profileRoot, 'convalescence-banner__veto')?.textContent === AFFILIATION_VETO_LEGEND,
  'El aviso declara por escrito el veto de afiliación (RF-01.6)',
);

// =====================================================================
// FASE 4 — Barra decreciente y re-render idempotente
// =====================================================================
console.log('\n[FASE 4] Barra decreciente y re-render idempotente');

let progress = byClass(profileRoot, 'convalescence-banner__progress');
assertCondition(progress?.getAttribute('role') === 'progressbar', 'La barra decreciente es un progressbar accesible');
assertCondition(progress?.getAttribute('aria-valuemin') === '0', 'La barra declara su mínimo: 0 días');
assertCondition(progress?.getAttribute('aria-valuemax') === '14', 'La barra declara su máximo: los 14 días canónicos');
assertCondition(progress?.getAttribute('aria-valuenow') === '5', 'La barra declara el valor vivo: 5 días');
assertCondition(
  progress?.getAttribute('aria-label') === 'Días de meditación restantes: 5 de 14',
  'La barra se nombra para lectores de pantalla',
);
assertCondition(
  progress?.getAttribute('style') === '--convalescence-progress: 36%',
  'La barra viste su medida por Custom Property (5/14 = 36%), no por estilo en línea de color',
);
assertCondition(
  byClass(profileRoot, 'convalescence-banner__progress-fill')?.getAttribute('aria-hidden') === 'true',
  'El relleno es ornamento: su medida ya viaja en el valor accesible de la barra',
);

notice.setConvalescence(membership);
assertCondition(profileRoot.children.length === 1, 'El re-render es idempotente: un solo aviso en el perfil');
assertCondition(byClass(profileRoot, 'convalescence-banner__progress')?.getAttribute('aria-valuenow') === '5', 'Tras re-renderizar, el valor de la barra sigue siendo correcto');

notice.setConvalescence(closedMembership(1, { clanName: 'Custodios de la Llama' }));
progress = byClass(profileRoot, 'convalescence-banner__progress');
assertCondition(progress?.getAttribute('aria-valuenow') === '1', 'En el último día la barra baja a 1');
assertCondition(progress?.getAttribute('style') === '--convalescence-progress: 7%', 'La barra decrece: 1/14 = 7%');
assertCondition(
  byClass(profileRoot, 'convalescence-banner__legend')?.textContent === 'En Convalecencia Arcana: resta 1 día de meditación',
  'El contador del último día concuerda en singular',
);

notice.setConvalescence(closedMembership(14, { clanName: 'Custodios de la Llama' }));
progress = byClass(profileRoot, 'convalescence-banner__progress');
assertCondition(progress?.getAttribute('aria-valuenow') === '14', 'Al abrirse la purga el contador arranca en 14 días');
assertCondition(progress?.getAttribute('style') === '--convalescence-progress: 100%', 'La barra arranca llena (14/14 = 100%)');

// =====================================================================
// FASE 5 — Veto de afiliación (RF-01.6)
// =====================================================================
console.log('\n[FASE 5] Veto de afiliación: bloqueo terminante (RF-01.6)');

const foundButton = createAffiliationControl('button', 'foundClan');
const applyButton = createAffiliationControl('button', 'applyToClan');
const anchorAction = createFakeElement('a');
anchorAction.setAttribute('data-clan-action', 'applyToClan');
anchorAction.actionCount = 0;
anchorAction.addEventListener('click', () => { anchorAction.actionCount += 1; });

notice.setAffiliationControls([foundButton, applyButton, anchorAction]);
assertCondition(notice.getAffiliationControls().length === 3, 'El componente registra las tres acciones de afiliación del perfil');

const sealedMembership = closedMembership(3, { clanName: 'Custodios de la Llama' });
notice.setConvalescence(sealedMembership);

assertCondition(foundButton.hasAttribute('disabled') === true, '«Fundar hermandad» queda deshabilitado mientras dura la purga');
assertCondition(applyButton.hasAttribute('disabled') === true, '«Postular a otra casa» también queda deshabilitado');
assertCondition(applyButton.getAttribute('aria-disabled') === 'true', 'El deshabilitado se anuncia con aria-disabled');
assertCondition(
  foundButton.getAttribute('data-affiliation-veto') === AFFILIATION_VETO_MARK,
  'El veto se marca en el DOM para que cualquier vista pueda consultarlo',
);
assertCondition(foundButton.getAttribute('title') === AFFILIATION_VETO_LEGEND, 'El control veda con la leyenda ceremonial como pista');
assertCondition(dispatchUserGesture(foundButton) === null, 'El navegador no entrega el gesto a un control disabled: el veto es terminante');
assertCondition(foundButton.actionCount === 0, 'La acción vedada JAMÁS se ejecuta (bloqueo terminante de la fundación)');
assertCondition(applyButton.actionCount === 0, 'La postulación vedada tampoco se ejecuta');

// Sonda aguas abajo: el componente neutraliza el evento para los oyentes
// posteriores (red de seguridad para controles sin semántica de disabled).
let probeSaw = null;
anchorAction.addEventListener('click', (event) => { probeSaw = event.defaultPrevented; });
anchorAction.dispatch('click');
assertCondition(probeSaw === true, 'Sobre un control sin `disabled` el componente neutraliza el clic (preventDefault)');
assertCondition(vetoAttempts.length === 1 && vetoAttempts[0] === anchorAction, 'El intento vedado se notifica al orquestador con el control implicado');

// =====================================================================
// FASE 6 — Fin de la convalecencia
// =====================================================================
console.log('\n[FASE 6] Fin de la convalecencia: el veto se levanta y el estado previo vuelve');

const titledButton = createAffiliationControl('button', 'foundClan');
titledButton.setAttribute('title', 'Fundar una hermandad');
const alreadySealedButton = createAffiliationControl('button', 'foundClan');
alreadySealedButton.setAttribute('disabled', '');
alreadySealedButton.disabled = true;

const releaseNotice = createConvalescenceBannerComponent(createFakeElement('div'), {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
});
releaseNotice.setAffiliationControls([titledButton, alreadySealedButton]);

assertCondition(releaseNotice.setConvalescence(closedMembership(2)) === true, 'Segundo escenario: el aviso vuelve a desplegarse');
assertCondition(titledButton.getAttribute('title') === AFFILIATION_VETO_LEGEND, 'Durante la purga la pista ceremonial sustituye a la propia');

const finished = releaseNotice.setConvalescence(closedMembership(-1));
assertCondition(finished === false, 'Con la marca de fin ya vencida el aviso NO se despliega');
assertCondition(releaseNotice.isActive() === false, 'isActive() confirma que la purga terminó');
assertCondition(releaseNotice.daysRemaining() === null, 'Sin purga vigente daysRemaining() es null');
assertCondition(titledButton.hasAttribute('disabled') === false, 'La acción recupera su libertad al terminar la meditación');
assertCondition(titledButton.getAttribute('aria-disabled') === null, 'El aria-disabled se retira');
assertCondition(titledButton.getAttribute('data-affiliation-veto') === null, 'La marca del veto se retira');
assertCondition(titledButton.getAttribute('title') === 'Fundar una hermandad', 'La pista propia del control se restituye literalmente');
assertCondition(dispatchUserGesture(titledButton) !== null, 'El gesto vuelve a entregarse y la fundación es posible de nuevo');
assertCondition(titledButton.actionCount === 1, 'La acción ya se ejecuta tras la purga');
assertCondition(alreadySealedButton.hasAttribute('disabled') === true, 'Un control vedado por otra causa permanece vedado: el componente solo libera lo suyo');
assertCondition(alreadySealedButton.getAttribute('data-affiliation-veto') === null, 'Aun así se retira la marca de ESTE veto');

releaseNotice.clear();
assertCondition(alreadySealedButton.hasAttribute('title') === false, 'clear() retira una pista ceremonial que el control no tenía antes');

// =====================================================================
// FASE 7 — Bus de eventos del plan 4.1
// =====================================================================
console.log('\n[FASE 7] Bus de eventos del plan 4.1');

const bus = createFakeBus();
const busRoot = createFakeElement('div');
const busNotice = createConvalescenceBannerComponent(busRoot, {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
  bus,
});
const busButton = createAffiliationControl('button', 'foundClan');
busNotice.setAffiliationControls([busButton]);

assertCondition(bus.listenerCount(CONVALESCENCE_STARTED_EVENT) === 1, 'El componente se suscribe al suceso de inicio de convalecencia');
assertCondition(CONVALESCENCE_ENDED_EVENTS.length === 2, 'El canon declara dos sucesos de fin (ingreso y fundación)');

bus.emit(CONVALESCENCE_STARTED_EVENT, closedMembership(9, { clanName: 'Mareas Celestiales' }));
assertCondition(busNotice.isActive() === true, '`clan:convalescence-started` despliega el aviso sin recargar la página');
assertCondition(busNotice.daysRemaining() === 9, 'El suceso transporta la marca del DTO y el contador se ajusta a 9 días');
assertCondition(busButton.hasAttribute('disabled') === true, 'El aviso desplegado por evento veta de inmediato las acciones de afiliación');

bus.emit('clan:member-joined');
assertCondition(busNotice.isActive() === false, '`clan:member-joined` retira el aviso: el mago ya milita en otra casa');
assertCondition(busButton.hasAttribute('disabled') === false, 'Y levanta el veto al instante');

bus.emit(CONVALESCENCE_STARTED_EVENT, closedMembership(4));
assertCondition(busNotice.isActive() === true, 'El suceso puede volver a desplegar el aviso');
bus.emit('clan:created');
assertCondition(busNotice.isActive() === false, '`clan:created` retira el aviso: fundar una casa termina la purga');
assertCondition(busRoot.children.length === 0, 'El perfil queda limpio cuando la convalecencia cesa');

// =====================================================================
// FASE 8 — Ciclo de vida
// =====================================================================
console.log('\n[FASE 8] Ciclo de vida: destroy, escrituras tardías y cero fugas');

bus.emit(CONVALESCENCE_STARTED_EVENT, closedMembership(6));
assertCondition(busRoot.children.length === 1, 'El aviso está desplegado antes de destruir');
busNotice.destroy();
assertCondition(busRoot.children.length === 0, 'destroy() retira el aviso del perfil');
assertCondition(busNotice.isActive() === false, 'destroy() deja el componente inactivo');
assertCondition(busButton.hasAttribute('disabled') === false, 'destroy() levanta el veto de las acciones registradas');
assertCondition(bus.listenerCount(CONVALESCENCE_STARTED_EVENT) === 0, 'destroy() desuscribe el suceso de inicio (cero fugas)');
assertCondition(bus.listenerCount('clan:member-joined') === 0, 'destroy() desuscribe los sucesos de fin');
assertCondition(busNotice.setConvalescence(closedMembership(2)) === false, 'Una escritura tardía tras destroy() no pinta nada');
assertCondition(busRoot.children.length === 0, 'El perfil permanece limpio tras la escritura tardía');
busNotice.destroy();
assertCondition(busRoot.children.length === 0, 'destroy() es idempotente');

const noopRoot = createFakeElement('div');
const noopNotice = createConvalescenceBannerComponent(noopRoot, {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
});
assertCondition(noopNotice.setConvalescence(null) === false, 'Un perfil sin sobre de convalecencia no despliega aviso alguno');
assertCondition(noopNotice.setConvalescence({ id: 'mem', convalescenceExpiresAt: null }) === false, 'Marca nula: sin aviso, sin error (degradación elegante)');
assertCondition(noopRoot.children.length === 0, 'Sin purga el perfil queda intacto');
noopNotice.destroy();

// =====================================================================
// FASE 9 — XSS
// =====================================================================
console.log('\n[FASE 9] XSS: el perfil hostil viaja como texto literal (AGENTS.md 6.1)');

const hostileAlias = '</span><script>alert("xss")</script>';
const hostileClan = 'Casa "Traidora" <img src=x onerror=alert(1)>';
const hostileRoot = createFakeElement('div');
const hostileNotice = createConvalescenceBannerComponent(hostileRoot, {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
});
hostileNotice.setConvalescence({
  convalescenceExpiresAt: isoFromNow(2),
  userAlias: hostileAlias,
  clanName: hostileClan,
});

const hostilePenitent = byClass(hostileRoot, 'convalescence-banner__penitent');
assertCondition(
  hostilePenitent?.textContent.includes(hostileAlias) === true,
  'El alias hostil se exhibe como TEXTO literal, jamás interpretado como marcado',
);
assertCondition(
  hostilePenitent?.textContent.includes(`«${hostileClan}»`) === true,
  'El nombre de casa hostil también viaja literal',
);
assertCondition(hostileRoot.children.length === 1, 'El marcado hostil no forja nodos ajenos: el aviso sigue siendo uno');
assertCondition(
  hostileRoot.children[0].children.every((child) => ['H2', 'P', 'DIV'].includes(child.tagName)),
  'Solo se forjan los nodos ceremoniales del aviso (ni script ni img)',
);
hostileNotice.destroy();

// =====================================================================
// FASE 10 — Integración con la franja del perfil y el orquestador
// =====================================================================
console.log('\n[FASE 10] Integración con la franja del perfil y el orquestador');

const headerIndex = shellSource.indexOf('<header');
const headerEndIndex = shellSource.indexOf('</header>');
const noticeSlotIndex = shellSource.indexOf('id="arcaneNoticeSlot"');
assertCondition(noticeSlotIndex > -1, 'El shell declara la franja del perfil del mago (#arcaneNoticeSlot)');
assertCondition(
  noticeSlotIndex > headerIndex && noticeSlotIndex < headerEndIndex,
  'La franja vive en la cabecera, junto al distintivo de sesión del mago',
);
assertCondition(
  /class="arcane-notice-slot"[^>]*id="arcaneNoticeSlot"|id="arcaneNoticeSlot"[^>]*class="arcane-notice-slot"/.test(shellSource),
  'La franja porta la clase del sistema de diseño',
);
assertCondition(
  orchestratorSource.includes("from './components/convalescenceBannerComponent.js'"),
  'El orquestador importa el componente (ES Modules nativos, sin empaquetadores)',
);
assertCondition(
  orchestratorSource.includes('createConvalescenceBannerComponent(arcaneNoticeRoot'),
  'El orquestador lo monta sobre la franja del shell (degradación elegante si falta)',
);
assertCondition(
  orchestratorSource.includes('convalescenceNotice?.setConvalescence(user)')
  && orchestratorSource.includes('convalescenceNotice?.setConvalescence(sessionUser)'),
  'El perfil se despliega con el sobre de sesión (login, consagración y verificación de arranque)',
);
assertCondition(
  orchestratorSource.includes('convalescenceNotice?.clear()'),
  'La disolución del vínculo retira el aviso',
);
assertCondition(
  orchestratorSource.includes('convalescenceNotice?.destroy?.()'),
  'destroy() desmonta el aviso junto al resto de la infraestructura',
);
assertCondition(
  orchestratorSource.includes('bus: windowRef'),
  'El componente queda suscrito al bus de eventos del plan 4.1',
);

const stylesSection = stylesSource.slice(stylesSource.indexOf('8. Aviso de Convalecencia Arcana'));
assertCondition(stylesSection.includes('.convalescence-banner'), 'La hoja auditable del sistema viste el aviso');
assertCondition(
  stylesSection.includes('--convalescence-progress'),
  'La barra decreciente se viste desde la Custom Property que fija el componente',
);
assertCondition(
  (stylesSection.match(/#[0-9a-fA-F]{3,6}\b/g) ?? []).length === 0,
  'Cero literales de color en la vestidura: todo son tokens de diseño (Artículo I)',
);

// Integración simulada: el sobre de sesión desembarca en el perfil.
const integratedRoot = createFakeElement('div');
const integratedNotice = createConvalescenceBannerComponent(integratedRoot, {
  now: () => NOW_MILLIS,
  elementFactory: fakeElementFactory,
});
const sessionUser = {
  id: 'usr_penitente',
  alias: 'Frieren',
  role: 'editor',
  clanId: null,
  clanName: '',
  convalescenceExpiresAt: isoFromNow(3),
};
assertCondition(integratedNotice.setConvalescence(sessionUser) === true, 'El sobre de sesión con marca de purga despliega el aviso en el perfil');
assertCondition(
  byClass(integratedRoot, 'convalescence-banner__legend')?.textContent === 'En Convalecencia Arcana: restan 3 días de meditación',
  'Y el perfil muestra los días restantes al instante',
);
integratedNotice.destroy();

// =====================================================================
// Veredicto
// =====================================================================
console.log(`\n${'-'.repeat(64)}`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
console.log(`Veredicto: ${assertsFailed === 0 ? 'TODO EN ORDEN — el criterio «Hecho cuando» se cumple' : 'HAY FALLOS'}`);
process.exit(assertsFailed === 0 ? 0 : 1);

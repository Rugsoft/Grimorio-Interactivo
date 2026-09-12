/**
 * test_spell_card.mjs — Verificación de la tarjeta de hechizo (Tarea 4.2).
 *
 * Estrategia TDD: este script se escribe ANTES que spellCardComponent.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Presionar la tecla Enter o hacer clic sobre la tarjeta dispara el evento
 *   de selección emitiendo el identificador/slug del hechizo.
 *
 * Requisitos: RF-03.1 (tarjeta de catálogo), RNF-03 (accesibilidad por
 * teclado: tabindex="0" según tasks.md), plan 8 (texto íntegro solo en la
 * ficha; tarjeta con clamp de 3 líneas ya estilizado en components.css).
 *
 * Seguridad XSS (AGENTS.md 6.1): el contenido del DTO debe renderizarse vía
 * textContent (no innerHTML) — se verifica que el markup hostil no survive.
 *
 * Uso: node scratch/test_spell_card.mjs
 */

import { createSpellCardComponent, SPELL_BADGE_KINDS } from '../public/assets/js/components/spellCardComponent.js';

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

/**
 * Elemento DOM mínimo simulado (mismo patrón de la Tarea 4.1).
 * textContent se modela como nodo de texto seguro: guardar/leer es idempotente
 * y JAMÁS interpreta markup (a diferencia de innerHTML).
 */
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
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        // El tipo de evento SIEMPRE refleja el canal despachado (el
        // componente discrimina click/keydown por event.type).
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) {
      // Simulación fiel: asignar textContent nunca genera nodos/HTML.
      this._textContent = String(value);
    },
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

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** DTO de muestra del plan 2.1 (hechizo validado de usuario). */
const sampleSpell = {
  id: 'spl_9f8b2c1a',
  slug: 'llamas-de-frieren',
  name: 'Llamas de Frieren',
  magicSchool: 'evocation',
  magicSchoolLabel: 'Evocación',
  manaCost: 45,
  clanName: 'Eruditos Astrales',
  summary: 'Proyecta una ráfaga continua de fuego purificador que calcina barreras mágicas.',
  status: 'validated',
  isGenesisSample: false,
  validatedAt: '2026-09-10T14:30:00Z',
};

/** DTO de génesis (plan 2.3). */
const genesisSpell = {
  ...sampleSpell,
  slug: 'chispa-de-ignicion',
  name: 'Chispa de Ignición',
  isGenesisSample: true,
  status: 'validated',
};

/** DTO experimental (Art. III). */
const experimentalSpell = {
  ...sampleSpell,
  slug: 'boceto-prohibido',
  name: 'Boceto Prohibido',
  status: 'experimental',
};

console.log('== VERIFICACION TAREA 4.2: spellCardComponent.js ==\n');

// --- FASE 1: Render del contrato SpellSummaryDto (plan 2.1) ---
console.log('FASE 1: Render del DTO de tarjeta');

const cardRoot = createSpellCardComponent(sampleSpell, { elementFactory: fakeElementFactory });

assertCondition(cardRoot.tagName === 'ARTICLE', 'La tarjeta es un <article> (tasks.md: role="article")');
assertCondition(cardRoot.getAttribute('role') === 'article', 'Porta role="article" (criterio de tasks.md)');
assertCondition(cardRoot.getAttribute('tabindex') === '0', 'Porta tabindex="0" (activable por teclado, criterio de tasks.md)');
assertCondition(cardRoot.getAttribute('data-slug') === 'llamas-de-frieren', 'Porta data-slug con el identificador del hechizo');

// Nombre y resumen como nodos de texto seguros.
const cardText = collectText(cardRoot);
assertCondition(cardText.includes('Llamas de Frieren'), 'El nombre del conjuro aparece en la tarjeta');
assertCondition(cardText.includes(sampleSpell.summary), 'El resumen aparece íntegro (el clamp visual lo recorta, no el DOM)');
assertCondition(cardText.includes('Evocación'), 'La etiqueta castellana de la escuela está presente (Art. IV)');
assertCondition(cardText.includes('Eruditos Astrales'), 'El clan de origen figura en la tarjeta');

/** Recolecta el textContent del árbol simulado. */
function collectText(node) {
  let text = node.textContent ?? '';
  for (const child of node.children) {
    text += ' ' + collectText(child);
  }
  return text;
}

/** Busca recursivamente elementos con una clase dada. */
function findByClass(node, className, found = []) {
  if (node.classes?.has?.(className)) found.push(node);
  for (const child of node.children) findByClass(child, className, found);
  return found;
}

// Badges de escuela y maná (RF-03.1):
const badges = findByClass(cardRoot, 'spell-card__badge');
assertCondition(badges.length >= 2, `Renderiza badges de escuela y maná (${badges.length} badges)`);

// --- FASE 2: Sellos de estado (RF-01.3 génesis / RF-03.2 inestabilidad) ---
console.log('\nFASE 2: Sellos de génesis e inestabilidad');

const genesisCard = createSpellCardComponent(genesisSpell, { elementFactory: fakeElementFactory });
const genesisBadges = findByClass(genesisCard, 'spell-card__badge--genesis');
assertCondition(genesisBadges.length >= 1, 'El pergamino primordial porta el sello de génesis (RF-01.3)');

const experimentalCard = createSpellCardComponent(experimentalSpell, { elementFactory: fakeElementFactory });
const unstableBadges = findByClass(experimentalCard, 'spell-card__badge--unstable');
assertCondition(unstableBadges.length >= 1, 'El experimental porta el sello de inestabilidad arcana (RF-03.2)');

const validatedBadges = findByClass(cardRoot, 'spell-card__badge--unstable');
assertCondition(validatedBadges.length === 0, 'El hechizo validado NO porta sello de inestabilidad');

assertCondition(
  SPELL_BADGE_KINDS?.genesis === 'spell-card__badge--genesis' && SPELL_BADGE_KINDS?.unstable === 'spell-card__badge--unstable',
  'SPELL_BADGE_KINDS exporta las clases de sello para errorView/landingView'
);

// --- FASE 3: CRITERIO — click y Enter emiten el slug ---
console.log('\nFASE 3: Criterio (activación por click y teclado)');

const selectedSlugs = [];
const interactiveCard = createSpellCardComponent(sampleSpell, {
  elementFactory: fakeElementFactory,
  onSpellSelect: (slug) => selectedSlugs.push(slug),
});

// Click:
interactiveCard.dispatch('click');
assertCondition(selectedSlugs.length === 1 && selectedSlugs[0] === 'llamas-de-frieren', 'El click dispara el evento de selección emitiendo el slug (criterio)');

// Enter:
interactiveCard.dispatch('keydown', { key: 'Enter' });
assertCondition(selectedSlugs.length === 2 && selectedSlugs[1] === 'llamas-de-frieren', 'La tecla Enter dispara el evento de selección emitiendo el slug (criterio)');

// Space también activa (teclados estándar, RNF-03).
interactiveCard.dispatch('keydown', { key: ' ' });
assertCondition(selectedSlugs.length === 3, 'La tecla Space también activa la tarjeta (RNF-03)');

// Otras teclas no activan.
interactiveCard.dispatch('keydown', { key: 'Tab' });
interactiveCard.dispatch('keydown', { key: 'a' });
assertCondition(selectedSlugs.length === 3, 'Teclas ajenas (Tab, letras) no disparan selecciones');

// preventDefault sobre Enter: no debe propagar navegación.
let defaultPrevented = false;
interactiveCard.dispatch('keydown', { key: 'Enter', preventDefault: () => { defaultPrevented = true; } });
assertCondition(defaultPrevented === true, 'Enter ejecuta preventDefault (sin navegación parásita)');

// --- FASE 4: Seguridad — contenido hostil del DTO (AGENTS.md 6.1) ---
console.log('\nFASE 4: Sanitización (textContent, nunca innerHTML)');

const hostileSpell = {
  ...sampleSpell,
  slug: 'conjuro-malicioso',
  name: '<img src=x onerror=alert(1)>',
  summary: '<script>alert("xss")</script> Resumen inocente.',
};
const hostileCard = createSpellCardComponent(hostileSpell, { elementFactory: fakeElementFactory });
const hostileText = collectText(hostileCard);
assertCondition(hostileText.includes('<img src=x onerror=alert(1)>'), 'El markup hostil viaja como TEXTO literal (textContent), no como nodos');
assertCondition(hostileCard.children.every((child) => child.tagName !== 'IMG' && child.tagName !== 'SCRIPT'), 'Ningún nodo IMG/SCRIPT fue creado desde el DTO (sin XSS)');

// --- FASE 5: Accesibilidad — nombre accesible y foco ---
console.log('\nFASE 5: Accesibilidad y ciclo de vida');

const accessibleCard = createSpellCardComponent(sampleSpell, { elementFactory: fakeElementFactory });
assertCondition(
  (accessibleCard.getAttribute('aria-label') ?? '').includes('Llamas de Frieren'),
  'La tarjeta porta aria-label con el nombre del conjuro (lectores de pantalla)'
);

// destroy(): retirar listeners (baja limpia para la rejilla re-renderizada).
const cleanupCard = createSpellCardComponent(sampleSpell, {
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
cleanupCard.destroy();
let listenersRemoved = true;
// Tras destroy, dispatch no debe invocar nada (listeners vacíos).
cleanupCard.dispatch('click');
assertCondition(listenersRemoved === true, 'destroy() retira los listeners sin errores');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);

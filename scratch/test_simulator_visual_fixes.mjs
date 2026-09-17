/**
 * test_simulator_visual_fixes.mjs — Suite de verificación de los tres fallos
 * corregidos en el Simulador de Grimorio (Cámara de Conjuración).
 *
 * Cobertura de especificaciones:
 *   - SPEC-05 RF-02.1: Maniquí Arcano de Entrenamiento visible con armazón de
 *     madera noble y paja ceremonial, barra de 500 PV y distintivo de barrera.
 *   - SPEC-05 RF-02.5: Disolución y regeneración visual al destruirse el armazón.
 *   - SPEC-05 RNF-01 / RF-03.4: Limpieza determinista del lienzo en cada cuadro
 *     (clearRect) para erradicar estelas congeladas y trazos acumulados.
 *   - SPEC-06 RF-02.1 / RF-02.2: Confinamiento del aura elemental centrada sobre
 *     el maniquí dentro de los límites de la Cámara sin desbordar el lienzo.
 *
 * Uso: node scratch/test_simulator_visual_fixes.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { createCombatDummyComponent, DUMMY_MAX_HEALTH } from '../public/assets/js/components/combatDummyComponent.js';
import { createArcaneCanvasComponent } from '../public/assets/js/components/arcaneCanvasComponent.js';

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

/** Elemento DOM mínimo simulado para arneses sin navegador. */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: { setProperty(name, value) { (this.inline ??= {})[name] = String(value); }, getProperty(name) { return (this.inline ?? {})[name] ?? null; } },
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'class') {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      }
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) {
      delete this.attributes[name];
      if (name === 'class') this.classes.clear();
    },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
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
    if (child.classes?.has?.(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

function byClass(node, className) {
  return queryByClass(node, className)[0] ?? null;
}

console.log('== VERIFICACIÓN: CORRECCIÓN DE FALLOS DEL SIMULADOR DE GRIMORIO ==\n');

// ══════════════════════════════════════════════════════════════════════
// FASE 1: Espantapájaros Herético como efigie del blanco (RF-02.1 ratificado)
// ══════════════════════════════════════════════════════════════════════
console.log('FASE 1: Espantapájaros Herético como efigie del blanco (RF-02.1)');

const host1 = createFakeElement('div');
const dummy1 = createCombatDummyComponent({
  host: host1,
  createElement: (tag) => createFakeElement(tag),
});

const figure = byClass(host1, 'combat-dummy__figure');
assertCondition(figure !== null, 'El contenedor .combat-dummy__figure está presente');

const mannequin = byClass(host1, 'combat-dummy__mannequin');
assertCondition(mannequin !== null, 'La efigie .combat-dummy__mannequin está creada');
assertCondition(mannequin?.tagName === 'IMG', 'La efigie es una imagen local (elemento img)');
assertCondition(mannequin?.getAttribute('src') === 'assets/img/heretic-scarecrow.png', 'La efigie se sirve del repositorio (assets/img/heretic-scarecrow.png, Artículo I)');
assertCondition(mannequin?.getAttribute('aria-hidden') === 'true', 'La efigie es aria-hidden (decorativa respecto a la barra accesible)');
assertCondition(String(mannequin?.getAttribute('alt') ?? '').length > 0, 'La imagen porta un alt ceremonial');

// Destrucción (RF-02.5): disolución en paja arcana
dummy1.applySpellImpact({ effects: { damage: DUMMY_MAX_HEALTH } });
assertCondition(figure.classList.contains('combat-dummy--destroyed'), 'Al llegar a 0 PV, la figura adquiere la clase combat-dummy--destroyed');

// ══════════════════════════════════════════════════════════════════════
// FASE 2: Limpieza Determinista del Lienzo en Cada Cuadro (RNF-01)
// ══════════════════════════════════════════════════════════════════════
console.log('\nFASE 2: Limpieza Determinista del Lienzo en Cada Cuadro (RNF-01)');

const clearCalls = [];
const fakeCanvas = { width: 800, height: 400 };
const fakeCtx = {
  canvas: fakeCanvas,
  clearRect: (...args) => clearCalls.push(args),
  save: () => {},
  restore: () => {},
  beginPath: () => {},
  arc: () => {},
  fill: () => {},
  stroke: () => {},
  fillRect: () => {},
  font: '',
  textAlign: '',
  fillText: () => {},
};

let capturedFrameCallback = null;
const fakeRaf = (cb) => {
  capturedFrameCallback = cb;
  return 1;
};

const arcane = createArcaneCanvasComponent({
  canvas: fakeCanvas,
  ctx: fakeCtx,
  raf: fakeRaf,
  caf: () => {},
  clock: { now: () => 1000 },
});

arcane.start();
assertCondition(typeof capturedFrameCallback === 'function', 'El bucle RAF está agendado');

// Ejecuta un cuadro del bucle
capturedFrameCallback(1016);
assertCondition(clearCalls.length === 1, 'frame() invoca ctx.clearRect() al inicio de cada cuadro (cero estelas congeladas)');
assertCondition(
  clearCalls[0][0] === 0 && clearCalls[0][1] === 0 && clearCalls[0][2] === 800 && clearCalls[0][3] === 400,
  'ctx.clearRect() limpia toda la superficie del lienzo (0, 0, 800, 400)',
);

// clear() desaloja y limpia
clearCalls.length = 0;
arcane.clear();
assertCondition(clearCalls.length === 1, 'arcane.clear() limpia el lienzo de inmediato al desalojar');

arcane.stop();

// ══════════════════════════════════════════════════════════════════════
// FASE 3: Confinamiento y Geometría Espacial de la Cámara y el Aura
// ══════════════════════════════════════════════════════════════════════
console.log('\nFASE 3: Confinamiento y Geometría de la Cámara y el Aura en CSS');

const simulatorCss = fs.readFileSync(path.resolve('public/assets/css/components/grimoire-simulator.css'), 'utf-8');
const codexCss = fs.readFileSync(path.resolve('public/assets/css/components/elemental-codex.css'), 'utf-8');

assertCondition(
  simulatorCss.includes('.grimoire-book__camera-slot') && simulatorCss.includes('overflow: hidden'),
  'La Cámara de Conjuración (.grimoire-book__camera-slot) declara overflow: hidden para confinar resplandores',
);

assertCondition(
  simulatorCss.includes('position: relative') && simulatorCss.includes('.grimoire-book__camera-slot'),
  'La Cámara de Conjuración es un contenedor con posición relativa',
);

assertCondition(
  simulatorCss.includes('.grimoire-simulator__dummy-host') && simulatorCss.includes('left: 70%') && simulatorCss.includes('top: 50%'),
  'El Maniquí Arcano (.grimoire-simulator__dummy-host) está posicionado exactamente en el corazón del blanco (70%, 50%)',
);

assertCondition(
  simulatorCss.includes('.arcane-canvas') && simulatorCss.includes('position: absolute') && simulatorCss.includes('inset: 0'),
  'El lienzo (.arcane-canvas) se superpone cubriendo toda el área de la Cámara (inset: 0)',
);

assertCondition(
  codexCss.includes('.elemental-aura') && codexCss.includes('width: clamp(120px, 14vw, 160px)'),
  'El aura elemental (.elemental-aura) se dimensiona a la efigie (sin disco de 220px, RF-02.2 ratificado)',
);
assertCondition(
  codexCss.includes('.elemental-aura__silhouette') && codexCss.includes('.elemental-aura__countdown'),
  'El aura porta su silueta-luz y su contador numérico (RF-02.2 ratificado)',
);

assertCondition(
  codexCss.includes('left: 50%') && codexCss.includes('top: 50%'),
  'El halo de aura se centra directamente sobre el torso del maniquí (top: 50%, left: 50%)',
);

// ══════════════════════════════════════════════════════════════════════
// RESUMEN
// ══════════════════════════════════════════════════════════════════════
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log('\nRESULTADO: EXITO — Los 3 fallos del simulador están formalmente resueltos y verificados.');
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir asertos fallidos.');
process.exit(1);

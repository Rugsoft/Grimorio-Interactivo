/**
 * test_floating_combat_text.mjs — Arnés TDD de la Tarea 3.2 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/floatingCombatTextComponent.js`:
 *   [1] Fábrica y API: componente montable con lienzo y reloj inyectables.
 *   [2] Escalonamiento espacial (plan 3.3 / RF-05.2): daño en el torso
 *       (carmesí #e63946, retardo 0), curación/barrera desplazada (+35 px,
 *       esmeralda #2a9d8f o zafiro #457b9d, retardo 50 ms) y CC en corona
 *       superior (−55 px, oro rúnico #d4af37, retardo 150 ms).
 *   [3] Rótulos canónicos: «−45 PV», «+30 PV», «[+25 Barrera]»,
 *       «¡Aturdido!», «[Salud Plena]».
 *   [4] Animación: flotación hacia arriba y disolución suave (alfa→0)
 *       integrada por dt; expiración y limpieza.
 *   [5] Sin empaste: tres textos de un impacto mixto conviven con sus
 *       desfases, y antiguos expirados se purgan solos.
 *   [6] RNF-03/RNF-04: contrastes legibles y textos solemnes castellanos.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, Canvas 2D puro.
 *   - Artículo IV/V: leyendas solemnes; identificadores en inglés camelCase.
 *
 * Uso: node scratch/test_floating_combat_text.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Reloj inyectable para los retardos escalonados (0/50/150 ms). */
function createFakeClock() {
  let now = 500_000;
  return {
    get now() { return now; },
    advance(ms) { now += ms; },
  };
}

/** Contexto 2D falso: registra cada fillText con su estado actual.
 * Sin mutación retrospectiva: cada entrada congela el fillStyle/alfa
 * vigentes en el instante del trazado (semántica del Canvas real). */
function createFakeCtx() {
  const texts = [];
  return {
    texts,
    save() {}, restore() {},
    _fillStyle: '#000000',
    _alpha: 1,
    set fillStyle(value) { this._fillStyle = value; },
    get fillStyle() { return this._fillStyle; },
    set globalAlpha(value) { this._alpha = value; },
    get globalAlpha() { return this._alpha; },
    font: '',
    textAlign: '',
    fillText(text, x, y) {
      texts.push({ text: String(text), x, y, fillStyle: this._fillStyle, alpha: this._alpha });
    },
  };
}

console.log('== ARNÉS TDD — Tarea 3.2: Textos Flotantes Arcanos Escalonados ==');

let module;
try {
  module = await import('../public/assets/js/components/floatingCombatTextComponent.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar floatingCombatTextComponent.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createFloatingCombatTextComponent, TEXT_COLORS: TEXT_COLORS_EXPORT } = module;

const TORSO = { x: 500, y: 300 };

/** Impacto mixto canónico (daño + curación + barrera + CC). */
function mixedImpact() {
  return {
    spellName: 'Llamas del Alba',
    damage: 45,
    healing: 30,
    barrier: 25,
    crowdControlType: 'stun',
    crowdControlLabel: '¡Aturdido!',
    fullHealthLegend: false,
  };
}

console.log('[1] Fábrica y API');

const clock = createFakeClock();
const ctx = createFakeCtx();
const component = createFloatingCombatTextComponent({ ctx, clock });

assertCondition(typeof createFloatingCombatTextComponent === 'function', 'exporta createFloatingCombatTextComponent(options)');
assertCondition(typeof component.spawnImpactTexts === 'function', 'expone spawnImpactTexts(impact, torso)');
assertCondition(typeof component.updateAndRender === 'function', 'expone updateAndRender(dt)');
assertCondition(typeof component.clear === 'function', 'expone clear()');
assertCondition(typeof component.getActiveCount === 'function', 'expone getActiveCount()');

console.log('[2] Escalonamiento espacial y temporal (plan 3.3)');

{
  const spawnClock = createFakeClock();
  const spawnCtx = createFakeCtx();
  const c = createFloatingCombatTextComponent({ ctx: spawnCtx, clock: spawnClock });

  c.spawnImpactTexts(mixedImpact(), TORSO);
  const scheduled = c.getPendingCount();
  assertCondition(scheduled === 3, `un impacto mixto programa 3 rótulos (daño, mejora, CC) — ${scheduled}`);

  // Retardo 0 ms: el daño ya está activo; 50 y 150 aún no.
  c.updateAndRender(0.016);
  const first = spawnCtx.texts.map((t) => t.text);
  assertCondition(first.some((text) => text.includes('−45') || text.includes('-45')), `el daño brota sin retardo (texto: ${first.join(' | ')})`);
  assertCondition(spawnCtx.texts.length === 1, 'solo el daño está visible en el primer cuadro (retardo 0 ms)');

  // 50 ms: aparece la mejora lateral (curación o barrera). El daño
  // redibuja cada cuadro (estela), así que comprobamos la ENTRADA NUEVA.
  spawnClock.advance(60);
  c.updateAndRender(0.016);
  const secondNew = spawnCtx.texts[spawnCtx.texts.length - 1];
  assertCondition(secondNew.text.includes('+') || secondNew.text.includes('Barrera'), 'a los 50 ms brota el segundo rótulo (curación/barrera)');

  // 150 ms: aparece el rótulo de CC en la corona.
  spawnClock.advance(100);
  c.updateAndRender(0.016);
  const thirdNew = spawnCtx.texts[spawnCtx.texts.length - 1];
  assertCondition(thirdNew.text.includes('¡'), 'a los 150 ms brota el rótulo de CC');

  const texts = spawnCtx.texts;
  const damage = texts.find((t) => t.text.includes('45'));
  const support = texts.find((t) => t.text.includes('+') || t.text.includes('Barrera'));
  const cc = texts.find((t) => t.text.includes('¡'));

  assertCondition(damage && Math.abs(damage.x - TORSO.x) < 2 && Math.abs(damage.y - TORSO.y) < 2, 'el daño nace en el torso central (X₀, Y₀)');
  assertCondition(support && support.x >= TORSO.x + 30, `la mejora nace desplazada lateralmente (+35 px) — dx = ${(support.x - TORSO.x).toFixed(0)}`);
  assertCondition(cc && cc.y <= TORSO.y - 50, `el CC nace en la corona superior (−55 px) — dy = ${(cc.y - TORSO.y).toFixed(0)}`);

  // RNF-03: los tonos del plan se aclaran a variantes con contraste AA.
  assertCondition(damage.fillStyle === '#ef5560', `daño en carmesí AA (#ef5560) — ${damage.fillStyle}`);
  assertCondition(['#2a9d8f', '#5b93b8'].includes(support.fillStyle), `mejora en esmeralda o zafiro AA — ${support.fillStyle}`);
  assertCondition(cc.fillStyle === '#d4af37', `CC en oro rúnico (#d4af37) — ${cc.fillStyle}`);
}

console.log('[3] Rótulos canónicos');

{
  const t = createFakeClock();
  const cx = createFakeCtx();
  const c = createFloatingCombatTextComponent({ ctx: cx, clock: t });

  // Curación pura → «+30 PV» en esmeralda.
  c.spawnImpactTexts({ spellName: 'Rocío', damage: 0, healing: 30, barrier: 0, crowdControlType: null }, TORSO);
  t.advance(60);
  c.updateAndRender(0.016);
  assertCondition(cx.texts.some((x) => x.text.includes('+30')), 'curación rendida como «+30 PV»');
  assertCondition(cx.texts.some((x) => x.fillStyle === '#2a9d8f'), 'curación en esmeralda');

  // Barrera pura → «[+25 Barrera]» en zafiro.
  cx.texts.length = 0;
  c.clear();
  c.spawnImpactTexts({ spellName: 'Égida', damage: 0, healing: 0, barrier: 25, crowdControlType: null }, TORSO);
  t.advance(60);
  c.updateAndRender(0.016);
  assertCondition(cx.texts.some((x) => x.text.includes('[+25 Barrera]')), 'barrera rendida como «[+25 Barrera]»');
  assertCondition(cx.texts.some((x) => x.fillStyle === '#5b93b8'), 'barrera en azul zafiro AA');

  // CC por tipo → rótulos solemnes.
  cx.texts.length = 0;
  c.clear();
  c.spawnImpactTexts({ spellName: 'Raíz', damage: 0, healing: 0, barrier: 0, crowdControlType: 'root' }, TORSO);
  t.advance(160);
  c.updateAndRender(0.016);
  assertCondition(cx.texts.some((x) => x.text === '¡Enraizado!'), 'CC root rendido como «¡Enraizado!»');

  // Leyenda de salud plena.
  cx.texts.length = 0;
  c.clear();
  c.spawnImpactTexts({ spellName: 'Bendición', damage: 0, healing: 40, barrier: 0, crowdControlType: null, fullHealthLegend: true }, TORSO);
  t.advance(60);
  c.updateAndRender(0.016);
  assertCondition(cx.texts.some((x) => x.text.includes('[Salud Plena]')), 'curación con salud plena rinde «[Salud Plena]»');
}

console.log('[4] Animación — flotación y disolución suave');

{
  const t = createFakeClock();
  const cx = createFakeCtx();
  const c = createFloatingCombatTextComponent({ ctx: cx, clock: t });

  c.spawnImpactTexts({ spellName: 'Golpe', damage: 45, healing: 0, barrier: 0, crowdControlType: null }, TORSO);
  c.updateAndRender(0.016);
  const born = cx.texts[cx.texts.length - 1];
  const bornY = born.y;
  const bornAlpha = born.alpha;

  t.advance(500);
  c.updateAndRender(0.016);
  const mid = cx.texts[cx.texts.length - 1];
  assertCondition(mid.y < bornY, `el texto flota hacia arriba (Δy = ${(mid.y - bornY).toFixed(1)} px)`);
  assertCondition(mid.alpha < bornAlpha, `el texto se disuelve (alfa ${bornAlpha} → ${mid.alpha.toFixed(2)})`);

  // Vida total: el texto expira y el contador baja a 0.
  assertCondition(c.getActiveCount() === 1, 'un rótulo activo durante su vida');
  t.advance(3000);
  c.updateAndRender(0.016);
  assertCondition(c.getActiveCount() === 0, 'el rótulo expira y se purga al final de su vida');
}

console.log('[5] Sin empaste — convivencia y purga automática');

{
  const t = createFakeClock();
  const cx = createFakeCtx();
  const c = createFloatingCombatTextComponent({ ctx: cx, clock: t });

  // Dos impactos mixtos consecutivos: 6 rótulos programados.
  c.spawnImpactTexts(mixedImpact(), TORSO);
  t.advance(200);
  c.spawnImpactTexts(mixedImpact(), TORSO);
  assertCondition(c.getPendingCount() + c.getActiveCount() >= 6, 'dos impactos mixtos conviven sin empastarse');

  t.advance(5000);
  c.updateAndRender(0.016);
  assertCondition(c.getActiveCount() === 0, 'los rótulos activos expiran y se purgan solos');
  assertCondition(c.getPendingCount() === 0, 'ningún rótulo queda pendiente de nacer');

  c.clear();
  assertCondition(c.getActiveCount() === 0, 'clear() vacía el escenario al instante');
}

console.log('[6] Degradación y contraste (RNF-03/RNF-04)');

{
  const t = createFakeClock();
  const c = createFloatingCombatTextComponent({ ctx: createFakeCtx(), clock: t });

  // Impacto sin efectos no programa nada.
  c.spawnImpactTexts({ spellName: 'Nada', damage: 0, healing: 0, barrier: 0, crowdControlType: null }, TORSO);
  assertCondition(c.getPendingCount() === 0 && c.getActiveCount() === 0, 'un impacto sin efectos no emite rótulos');

  // Impacto incompleto (campos ausentes) no revienta el componente.
  let threw = false;
  try {
    c.spawnImpactTexts({ spellName: 'Incompleto' }, TORSO);
  } catch { threw = true; }
  assertCondition(!threw, 'un impacto incompleto se degrada sin lanzar');

  // Contrastes WCAG AA sobre fondo oscuro del grimorio (#17120e).
  // Los tonos del plan (e63946, 457b9d) NO llegan a 4.5:1: RNF-03 manda,
  // así que el componente usa variantes aclaradas canónicas.
  const luminance = (hex) => {
    const ch = (v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const r = ch(parseInt(hex.slice(1, 3), 16));
    const g = ch(parseInt(hex.slice(3, 5), 16));
    const b = ch(parseInt(hex.slice(5, 7), 16));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const BG = '#17120e';
  const contrast = (hex) => {
    const l1 = luminance(hex);
    const l2 = luminance(BG);
    const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1];
    return (hi + 0.05) / (lo + 0.05);
  };
  for (const [name, hex] of Object.entries(TEXT_COLORS_EXPORT ?? {})) {
    assertCondition(contrast(hex) >= 4.5, `${name} (${hex}) ≥ 4.5:1 — ${contrast(hex).toFixed(2)}`);
  }
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Textos Flotantes Escalonados listos (Tarea 3.2).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — hay asertos incumplidos.');
  process.exit(1);
}

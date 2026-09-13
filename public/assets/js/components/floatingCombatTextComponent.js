/**
 * floatingCombatTextComponent.js — Textos Flotantes Arcanos Escalonados.
 *
 * Tarea 3.2 (TASKS-05): proyecta sobre el lienzo los rótulos animados de
 * cada impacto con desfase espacial y temporal (plan 3.3) para preservar
 * la legibilidad ante efectos mixtos (RF-05.2):
 *   - Daño: torso central, carmesí #e63946, retardo 0 ms.
 *   - Curación/Barrera: desplazamiento lateral +35 px, esmeralda
 *     #2a9d8f o zafiro #457b9d, retardo 50 ms.
 *   - Control de masas: corona superior (−55 px), oro rúnico #d4af37,
 *     retardo 150 ms.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Canvas 2D nativo, ES Modules puros.
 *   - Artículo IV/V: rótulos solemnes castellanos; API en inglés camelCase.
 *
 * Cobertura: RF-05.2 (escalonamiento espacial/temporal), RNF-03
 * (contraste ≥ 4.5:1 sobre el fondo del grimorio), RNF-04 (solemnidad).
 */

/** Cronograma canónico de retardos (ms) del plan 3.3. */
export const DAMAGE_DELAY_MS = 0;
export const SUPPORT_DELAY_MS = 50;
export const CC_DELAY_MS = 150;

/**
 * Paleta canónica con contraste AA (≥ 4.5:1, RNF-03) sobre el fondo del
 * grimorio (#17120e). Los tonos literales del plan (e63946 carmesí y
 * 457b9d zafiro) no alcanzan el umbral (4.46 y 4.05): se emplean
 * variantes aclaradas que preservan la intención cromática.
 */
export const TEXT_COLORS = {
  damage: '#ef5560',    // carmesí encendido (aclarado: 4.46 → ≥ 4.5)
  healing: '#2a9d8f',   // esmeralda (5.59)
  barrier: '#5b93b8',   // azul zafiro (aclarado: 4.05 → ≥ 4.5)
  crowdControl: '#d4af37', // oro rúnico (8.84)
};

/** Desfases espaciales canónicos (px) respecto al torso (X₀, Y₀). */
export const SUPPORT_OFFSET_X = 35;
export const SUPPORT_OFFSET_Y = -10;
export const CC_OFFSET_Y = -55;

/** Rótulos solemnes por tipo de control de masas (RNF-04). */
const CC_LABELS = {
  stun: '¡Aturdido!',
  root: '¡Enraizado!',
  slow: '¡Ralentizado!',
};

/** Duración de vida de cada rótulo (ms) y ascenso (px/s). */
const TEXT_LIFETIME_MS = 1600;
const FLOAT_SPEED_PX_PER_S = 55;

/**
 * Crea el componente de textos flotantes.
 *
 * @param {object} options
 *   - ctx: contexto Canvas 2D donde proyectar los rótulos.
 *   - clock: reloj inyectable ({ get now(), advance(ms) }); por defecto
 *     Date.now() (los arneses controlan el tiempo con el suyo).
 * @returns {object} API: spawnImpactTexts, updateAndRender, clear,
 *   getActiveCount, getPendingCount.
 */
export function createFloatingCombatTextComponent(options = {}) {
  const ctx = options.ctx;
  const clock = options.clock ?? { now: () => Date.now() };
  const now = () => (typeof clock.now === 'function' ? clock.now() : clock.now);

  /** Rótulos programados (esperan su retardo escalonado). */
  let pending = [];
  /** Rótulos activos (ya nacidos, flotando y disolviéndose). */
  let active = [];

  /**
   * Programa y emite los rótulos de un impacto según su cronograma.
   *
   * @param {object} impact - { spellName, damage, healing, barrier,
   *   crowdControlType, crowdControlLabel, fullHealthLegend }.
   * @param {{x: number, y: number}} torso - centro del torso (X₀, Y₀).
   * @returns {number} rótulos programados en esta invocación.
   */
  function spawnImpactTexts(impact, torso) {
    const damage = Number(impact?.damage ?? 0) || 0;
    const healing = Number(impact?.healing ?? 0) || 0;
    const barrier = Number(impact?.barrier ?? 0) || 0;
    const ccType = impact?.crowdControlType && impact.crowdControlType !== 'none'
      ? impact.crowdControlType
      : null;
    const fullHealth = Boolean(impact?.fullHealthLegend);
    const instant = now();
    let scheduled = 0;

    // 1. Daño — torso central, carmesí, retardo 0 ms.
    if (damage > 0) {
      pending.push({
        text: `−${damage} PV`,
        x: torso.x,
        y: torso.y,
        color: TEXT_COLORS.damage,
        bornAt: instant + DAMAGE_DELAY_MS,
      });
      scheduled++;
    }

    // 2. Curación o barrera — desplazadas +35 px, retardo 50 ms.
    if (healing > 0 || barrier > 0) {
      let text;
      let color;
      if (healing > 0) {
        text = fullHealth ? '[Salud Plena]' : `+${healing} PV`;
        color = TEXT_COLORS.healing;
      } else {
        text = `[+${barrier} Barrera]`;
        color = TEXT_COLORS.barrier;
      }
      pending.push({
        text,
        x: torso.x + SUPPORT_OFFSET_X,
        y: torso.y + SUPPORT_OFFSET_Y,
        color,
        bornAt: instant + SUPPORT_DELAY_MS,
      });
      scheduled++;
    }

    // 3. Control de masas — corona superior, oro rúnico, retardo 150 ms.
    if (ccType) {
      pending.push({
        text: impact?.crowdControlLabel ?? CC_LABELS[ccType] ?? '¡Sometido!',
        x: torso.x,
        y: torso.y + CC_OFFSET_Y,
        color: TEXT_COLORS.crowdControl,
        bornAt: instant + CC_DELAY_MS,
      });
      scheduled++;
    }

    return scheduled;
  }

  /**
   * Promueve los rótulos cuyo retardo escalonado ha vencido, integra la
   * flotación (ascenso ΔY = −60 px aprox. a lo largo de la vida) y dibuja
   * los activos con disolución suave (alfa lineal → 0).
   */
  function updateAndRender(dt) {
    const instant = now();

    // Nacimiento de los programados cuyo retardo ha vencido.
    if (pending.length > 0) {
      const stillWaiting = [];
      for (const item of pending) {
        if (instant >= item.bornAt) {
          active.push({
            text: item.text,
            x: item.x,
            y: item.y,
            color: item.color,
            bornAt: item.bornAt,
            // La vida cuenta desde su nacimiento PROGRAMADO: un rótulo
            // promovido tarde (cuadro congelado) nace ya disuelto.
            expiresAt: item.bornAt + TEXT_LIFETIME_MS,
          });
        } else {
          stillWaiting.push(item);
        }
      }
      pending = stillWaiting;
    }

    // Integración, purga de expirados y dibujo.
    if (ctx) {
      ctx.save();
      ctx.font = 'bold 18px Georgia, serif';
      ctx.textAlign = 'center';
    }
    const survivors = [];
    for (const item of active) {
      if (instant >= item.expiresAt) {
        continue; // Expirado: purga silenciosa.
      }
      item.y -= FLOAT_SPEED_PX_PER_S * dt; // flotación hacia arriba
      const lifeRatio = (item.expiresAt - instant) / TEXT_LIFETIME_MS;
      if (ctx) {
        ctx.globalAlpha = Math.max(0, Math.min(1, lifeRatio * 1.4)); // disolución suave
        ctx.fillStyle = item.color;
        ctx.fillText(item.text, item.x, item.y);
      }
      survivors.push(item);
    }
    if (ctx) {
      ctx.globalAlpha = 1;
      ctx.restore();
    }
    active = survivors;
  }

  /** Vacía el escenario (cambio de página, restauración del maniquí). */
  function clear() {
    pending = [];
    active = [];
  }

  /** Rótulos visibles en este instante. */
  function getActiveCount() {
    return active.length;
  }

  /** Rótulos esperando su retardo escalonado. */
  function getPendingCount() {
    return pending.length;
  }

  return { spawnImpactTexts, updateAndRender, clear, getActiveCount, getPendingCount };
}

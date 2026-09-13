/**
 * arcaneCanvasComponent.js — Contenedor del Lienzo Arcano.
 *
 * Tarea 3.3 (TASKS-05): gestiona el lienzo Canvas 2D y el bucle
 * `requestAnimationFrame` de la Cámara de Conjuración, con:
 *   - Suspensión inmediata al ocultar la pestaña (`visibilitychange`)
 *     junto con la pausa de la síntesis vocal, y reanudación al volver
 *     (RF-06.3).
 *   - Soporte de `prefers-reduced-motion`: sin proyectiles móviles; el
 *     impacto se representa con una pulsación lumínica estática rúnica
 *     de 400 ms y el texto flotante brota de inmediato (RF-06.1).
 *   - Monitor de FPS con auto-throttle: dos muestras consecutivas de 1 s
 *     por debajo de 30 FPS activan el modo de bajo consumo, reduciendo
 *     la densidad de partículas a 0.4 en futuras invocaciones (RF-06.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): Canvas 2D y RAF nativos; todo
 *     inyectable (document, raf/caf, clock, speechSynthesis) para que el
 *     componente sea falsable en arneses sin navegador.
 *   - Artículo V: identificadores en inglés camelCase, leyendas castellanas.
 *
 * Cobertura: RF-06.1, RF-06.2, RF-06.3, RF-05.1 (despacho del impacto),
 * RNF-01 (60 FPS mediante reciclado FIFO del motor de partículas).
 */

// Motor de partículas de producción (Tareas 2.1–2.3): import estático
// nativo, válido por igual en el navegador y en los arneses de Node.
import { ParticlePool, ELEMENTAL_PROFILES } from '../utils/particleEngine.js';

/** Umbrales canónicos del plan 4.2. */
export const FPS_MONITOR_WINDOW_MS = 1000;
export const FPS_THROTTLE_THRESHOLD = 30;
export const THROTTLED_DENSITY_SCALE = 0.4;
export const REDUCED_MOTION_FLASH_MS = 400;

/** Umbral de impacto: distancia al blanco en px que cuenta como golpe. */
const IMPACT_RADIUS_PX = 24;

/**
 * Crea el contenedor del lienzo arcánico.
 *
 * @param {object} options
 *   - canvas: elemento canvas (solo para dimensiones).
 *   - ctx: contexto Canvas 2D (inyectable para arneses).
 *   - document: documento (visibilidad; inyectable).
 *   - raf / caf: requestAnimationFrame / cancelAnimationFrame inyectables.
 *   - clock: reloj inyectable ({ get now(), advance(ms) }).
 *   - motionQuery: objeto con `.matches` de `prefers-reduced-motion`.
 *   - speechSynthesis: síntesis vocal para pausar/reanudar (RF-06.3).
 *   - onImpact(detail): callback cuando el conjuro alcanza el blanco.
 * @returns {object} API del contenedor.
 */
export function createArcaneCanvasComponent(options = {}) {
  const ctx = options.ctx;
  const doc = options.document ?? (typeof document !== 'undefined' ? document : null);
  const raf = options.raf ?? ((cb) => requestAnimationFrame(cb));
  const caf = options.caf ?? ((id) => cancelAnimationFrame(id));
  const clock = options.clock ?? { now: () => performance.now() };
  const now = () => (typeof clock.now === 'function' ? clock.now() : clock.now);
  const motionQuery = options.motionQuery ?? null;
  const speechSynthesis = options.speechSynthesis ?? null;
  const onImpact = typeof options.onImpact === 'function' ? options.onImpact : () => {};

  // Pool propio del lienzo (instanciación única en el arranque, RNF-01).
  // El arnés puede inyectar uno propio vía opciones.pool.
  const pool = options.pool ?? new ParticlePool();

  /** Estado del bucle y del rendimiento. */
  const runtime = {
    rafId: 0,
    running: false,
    pool,
    densityScale: 1,          // RF-06.2: 1.0 → 0.4 con el throttle
    framesInWindow: 0,
    windowStart: now(),
    slowSamples: 0,           // muestras consecutivas < 30 FPS
    throttled: false,
    // Proyectiles en vuelo: { spell, geometry, origin, target, launchAt, flightMs }
    flights: [],
    // Impactos pendientes de reduced-motion: { spell, target, expiresAt }
    staticFlashes: [],
    lastTimestamp: 0,
  };

  /** ¿Movimiento reducido activo? (RF-06.1) */
  function prefersReducedMotion() {
    return Boolean(motionQuery?.matches);
  }

  /** ¿El bucle está en marcha? */
  function isRunning() {
    return runtime.running;
  }

  /** Escala de densidad vigente (1.0 canónico, 0.4 con throttle). */
  function getDensityScale() {
    return runtime.densityScale;
  }

  /** Partículas activas en el pool (para arneses y diagnóstico). */
  function getActiveParticleCount() {
    return pool.getActiveCount();
  }

  /** Despacha el impacto sobre el maniquí (RF-05.1). */
  function dispatchImpact(spell, target) {
    onImpact({
      spell: { ...spell },
      targetCoordinates: { x: target.x, y: target.y },
    });
  }

  /**
   * Invoca un conjuro sobre el lienzo (RF-03):
   *   - Movimiento normal: emite partículas (con la densidad vigente) y
   *     agenda el vuelo hacia el blanco; al alcanzarlo, impacto.
   *   - Movimiento reducido: cero partículas; destello estático rúnico
   *     de 400 ms e impacto inmediato (el texto flotante brota al punto).
   *
   * @returns {number} partículas emitidas (0 en movimiento reducido).
   */
  function castSpell(spell, castOptions = {}) {
    const origin = castOptions.origin ?? { x: 100, y: 400 };
    const target = castOptions.target ?? { x: 560, y: 250 };
    const geometry = castOptions.geometry ?? 'singleTarget';
    const baseCount = castOptions.count ?? 12;

    if (prefersReducedMotion()) {
      // RF-06.1: pulsación lumínica estática + impacto inmediato.
      runtime.staticFlashes.push({
        x: target.x,
        y: target.y,
        color: ELEMENTAL_PROFILES?.[spell?.elementalAffinity]?.palette?.[0] ?? '#d4af37',
        expiresAt: now() + REDUCED_MOTION_FLASH_MS,
      });
      dispatchImpact(spell, target);
      return 0;
    }

    const emitted = pool.emitSpell(geometry, origin, target, {
      color: ELEMENTAL_PROFILES?.[spell?.elementalAffinity]?.palette?.[0] ?? '#ffffff',
      count: Math.max(1, Math.round(baseCount * runtime.densityScale)),
      speed: 260,
      size: 3.2,
      life: 3,
      element: spell?.elementalAffinity,
      circle: spell?.circle,
    });

    // Vuelo estimado: distancia / velocidad (260 px/s como la emisión).
    const distance = Math.hypot(target.x - origin.x, target.y - origin.y);
    runtime.flights.push({
      spell: { ...spell },
      target: { x: target.x, y: target.y },
      launchAt: now(),
      flightMs: (distance / 260) * 1000,
      dispatched: false,
    });
    return emitted;
  }

  /** Un cuadro del bucle: integra partículas, vuelos y monitor de FPS. */
  function frame(timestamp) {
    const instant = now();
    const dt = runtime.lastTimestamp ? Math.min((instant - runtime.lastTimestamp) / 1000, 0.05) : 0.016;
    runtime.lastTimestamp = instant;

    // Integra y dibuja las partículas activas.
    if (ctx) {
      pool.updateAndRender(ctx, dt);
    }

    // Destellos estáticos de reduced-motion (pulsación lumínica).
    if (ctx) {
      runtime.staticFlashes = runtime.staticFlashes.filter((flash) => {
        if (instant >= flash.expiresAt) return false;
        ctx.save();
        ctx.globalAlpha = 0.5;
        ctx.strokeStyle = flash.color;
        ctx.beginPath();
        ctx.arc(flash.x, flash.y, 46, 0, Math.PI * 2);
        ctx.stroke();
        ctx.beginPath();
        ctx.arc(flash.x, flash.y, 30, 0, Math.PI * 2);
        ctx.stroke();
        ctx.restore();
        return true;
      });
    }

    // Vuelos: al alcanzar el blanco, impacto único (RF-05.1).
    runtime.flights = runtime.flights.filter((flight) => {
      if (flight.dispatched) return false;
      if (instant - flight.launchAt >= flight.flightMs) {
        flight.dispatched = true;
        dispatchImpact(flight.spell, flight.target);
        return false;
      }
      return true;
    });

    // Monitor de FPS (RF-06.2): ventana de 1 s, dos muestras lentas.
    runtime.framesInWindow += 1;
    if (instant - runtime.windowStart >= FPS_MONITOR_WINDOW_MS) {
      const fps = (runtime.framesInWindow * 1000) / (instant - runtime.windowStart);
      runtime.framesInWindow = 0;
      runtime.windowStart = instant;
      if (fps < FPS_THROTTLE_THRESHOLD) {
        runtime.slowSamples += 1;
        if (runtime.slowSamples >= 2 && !runtime.throttled) {
          runtime.throttled = true;
          runtime.densityScale = THROTTLED_DENSITY_SCALE; // bajo consumo
        }
      } else {
        runtime.slowSamples = 0;
      }
    }

    // Re-agenda mientras siga corriendo.
    if (runtime.running) {
      runtime.rafId = raf(frame);
    }
  }

  /** Pone el bucle en marcha (idempotente). */
  function start() {
    if (runtime.running) return;
    runtime.running = true;
    runtime.lastTimestamp = 0;
    runtime.windowStart = now();
    runtime.rafId = raf(frame);
  }

  /** Detiene el bucle y cancela el vuelo pendiente. */
  function stop() {
    if (!runtime.running) return;
    runtime.running = false;
    caf(runtime.rafId);
  }

  /** Suspende por visibilidad (RF-06.3): bucle fuera + voz en pausa. */
  function suspend() {
    stop();
    if (speechSynthesis && typeof speechSynthesis.pause === 'function') {
      speechSynthesis.pause();
    }
  }

  /** Reanuda tras recuperar la visibilidad (RF-06.3). */
  function resume() {
    if (speechSynthesis && typeof speechSynthesis.resume === 'function') {
      speechSynthesis.resume();
    }
    start();
  }

  // RF-06.3: escucha nativa del cambio de visibilidad.
  if (doc && typeof doc.addEventListener === 'function') {
    doc.addEventListener('visibilitychange', () => {
      if (doc.hidden) {
        suspend();
      } else {
        resume();
      }
    });
  }

  return {
    start,
    stop,
    castSpell,
    isRunning,
    getDensityScale,
    getActiveParticleCount,
  };
}

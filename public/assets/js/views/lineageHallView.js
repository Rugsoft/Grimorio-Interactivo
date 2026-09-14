/**
 * lineageHallView.js — Vista general del Salón de los Linajes (Tarea 6.3,
 * TASKS-07).
 *
 * RF-06.1: monta el pabellón del Salón —clasificación semanal en vivo,
 *   prestigio histórico perpetuo y Libro Mayor de Campeones— en la ruta
 *   pública de linajes. La contemplación es LIBRE: ni un solo control exige
 *   vínculo arcano (RF-06.1).
 * RF-06.2: sirve el catálogo de los 8 Linajes Canónicos que viste la barra de
 *   filtros elementales del componente.
 *
 * La vista es la única responsable de hablar con el santuario: consulta en
 * paralelo el catálogo de linajes (Endpoint 10) y el Salón del Dominio
 * (Endpoint 11) y entrega los sobres al componente, que solo contempla y
 * pinta (Artículo II: la gloria la computa el servidor, jamás el cliente).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM puro; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): si el catálogo de linajes falla, el
 *     Salón sigue en pie con las claves técnicas y sin filtros; si el Salón
 *     falla, error temático con reintento; guardia anti-carreras para que una
 *     respuesta tardía de una consulta antigua jamás pinte.
 */

import { createLineageHallComponent } from '../components/lineageHallComponent.js';

/**
 * Crea la vista del Salón de los Linajes.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.dominionClient Cliente HTTP del Salón (Tarea 5.1),
 *        con `fetchLineages` y `fetchLeaderboard`.
 * @param {Object} [options.store] Almacén reactivo; la vista registra
 *        `currentView: 'clans'` al montar.
 * @param {(clanId: string) => void} [options.onClanSelect] Selección de una
 *        casa del podio o del histórico (la ficha llegará con la Tarea 6.4).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @returns {Object} API: { render, destroy, retry, setDominionClient }.
 */
export function createLineageHallView(mountRoot, options = {}) {
  const {
    dominionClient,
    store = null,
    onClanSelect,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
  } = options;

  /** Cliente HTTP vivo (conmutable en reintentos tras un fallo). */
  let activeDominionClient = dominionClient;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo la consulta más reciente pinta el Salón. */
  let fetchSequence = 0;

  /** El Salón quedó desmontado: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas. */
  let viewRoot = null;
  let hall = null;

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Consulta los dos endpoints públicos y alimenta el componente. */
  async function load() {
    const currentSequence = ++fetchSequence;

    if (typeof activeDominionClient?.fetchLeaderboard !== 'function') {
      hall?.setError(null);
      return;
    }

    // En paralelo: el catálogo de linajes es best-effort, el Salón es la autoridad.
    const lineagesPromise = typeof activeDominionClient.fetchLineages === 'function'
      ? activeDominionClient.fetchLineages()
      : Promise.resolve({ success: false });

    const [lineagesResult, hallResult] = await Promise.all([
      lineagesPromise.catch(() => ({ success: false })),
      activeDominionClient.fetchLeaderboard().catch((error) => ({
        success: false,
        status: 0,
        error: {
          code: 'MANA_STREAM_INTERRUPTED',
          message: error instanceof Error ? error.message : String(error),
        },
      })),
    ]);

    // Respuesta tardía o vista desmontada: jamás se pinta.
    if (currentSequence !== fetchSequence || isDestroyed || hall === null) return;

    if (!hallResult?.success) {
      hall.setError(hallResult?.error ?? null);
      return;
    }

    // Degradación elegante: sin catálogo, el Salón sigue en pie.
    if (lineagesResult?.success && Array.isArray(lineagesResult.data)) {
      hall.setLineages(lineagesResult.data);
    }

    hall.setHall(hallResult.data ?? null);
  }

  /** Reintenta la última consulta tras un corte de corriente (RNF). */
  async function retry() {
    fetchSequence += 1; // invalida respuestas en vuelo del cliente fallido.
    hall?.render();
    await load();
  }

  /** Conmuta el cliente del Salón (reintentos con otro canal). */
  function setDominionClient(nextDominionClient) {
    activeDominionClient = nextDominionClient;
  }

  /**
   * Monta la vista completa. Idempotente y de SOLO LECTURA: ningún control
   * exige sesión (RF-06.1).
   */
  async function render() {
    if (isDestroyed) return;

    destroy(false);

    viewRoot = track(elementFactory('section'));
    // El tomo central acota el Salón a la anchura canónica del santuario.
    viewRoot.className = 'lineage-hall-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // El Salón pasa a ser la vista activa del store (plan 4.1).
    store?.setState?.({ currentView: 'clans' });

    hall = createLineageHallComponent(viewRoot, {
      onRetry: () => {
        void retry();
      },
      onClanSelect: typeof onClanSelect === 'function' ? onClanSelect : undefined,
      elementFactory,
    });
    hall.render();

    await load();
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromMount=true] El render interno lo invoca con
   *        false para limpiar la pintura previa antes de volver a montar.
   */
  function destroy(removeFromMount = true) {
    fetchSequence += 1; // invalida respuestas en vuelo.
    hall?.destroy?.();
    hall = null;

    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;

    if (removeFromMount) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('lineage-hall-view')) {
          child.remove?.();
        }
      }
    }

    if (removeFromMount) isDestroyed = true;
  }

  return { render, destroy, retry, setDominionClient };
}

/**
 * clansPreviewView.js — Salón de Linajes en Lectura Pública (Tarea 5.6).
 *
 * RF-02.2 / HU-05: un visitante NO autenticado puede consultar el listado
 * público de linajes y la clasificación semanal de Dominio del Grimorio sin
 * recibir bloqueos de acceso — esta vista no monta controles que exijan
 * sesión (solo lectura pura; la inscripción vivirá en SPEC-07).
 *
 * Artículo III: el linaje fundacional 'cln_primordial' es NEUTRO y no
 * compite por el Dominio; el backend lo entrega con domainPoints a 0 y esta
 * vista lo marca visualmente como no competidor.
 *
 * Contrato de datos: `GET /clans/preview` vía fetchClansPreview (Tarea 3.3).
 * Cada ClanDto porta { id, slug, name, motto, domainPoints } — el backend
 * ya ordena por Dominio descendente (ORDER BY weekly_points DESC).
 *
 * Un solo contador de gloria (Tarea 2.6): `domainPoints` conserva su nombre
 * canónico de SPEC-01, pero su valor procede del contador semanal de SPEC-07
 * (`clans.weekly_points`), único contador de la contienda en curso.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; tabla semántica estándar.
 *   - Artículo IV: leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Seguridad (AGENTS.md 6.1): TODO dato del DTO (incluidos nombre y lema
 * aportados por usuarios) viaja por textContent — jamás innerHTML.
 */

/** Identificador del linaje fundacional neutro (database/seeds.sql, Art. III). */
const NEUTRAL_CLAN_ID = 'cln_primordial';

/**
 * Crea la vista del Salón de Linajes.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.store Almacén reactivo (Tarea 3.2).
 * @param {Object} options.spellClient Cliente HTTP (Tarea 3.3; necesita fetchClansPreview).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @returns {Object} API: { render, destroy, retry, setSpellClient }.
 */
export function createClansPreviewView(mountRoot, options) {
  const {
    store,
    spellClient,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  /** Cliente HTTP vivo (conmutable en reintentos tras fallo). */
  let activeSpellClient = spellClient;

  /** Nodos vivos de la vista, para limpieza determinista en destroy(). */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo la última petición pinta el salón. */
  let fetchSequence = 0;

  /** Referencias a nodos vivos. */
  let viewRoot = null;
  let rosterContainer = null;

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Búsqueda recursiva por clase sobre el DOM (real o simulado). */
  function byClass(root, className) {
    // DOM real: querySelector nativo. Simulado: barrido con classes.has().
    if (typeof root.querySelector === 'function') {
      return root.querySelector('.' + className);
    }
    const found = [];
    (function walk(node) {
      for (const child of node.children ?? []) {
        if (child.classes?.has(className)) found.push(child);
        walk(child);
      }
    })(root);
    return found[0] ?? null;
  }
  function allByClass(root, className) {
    if (typeof root.querySelectorAll === 'function') {
      return [...root.querySelectorAll('.' + className)];
    }
    const found = [];
    (function walk(node) {
      for (const child of node.children ?? []) {
        if (child.classes?.has(className)) found.push(child);
        walk(child);
      }
    })(root);
    return found;
  }

  /** Añade un elemento de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Elimina el bloque de error, si existe. */
  function clearErrorState() {
    viewRoot ? byClass(viewRoot, 'clans-view__error')?.remove?.() : null;
  }

  /** Monta el estado de error temático con reintento (RF-06.3). */
  function renderErrorState(errorEnvelope) {
    clearErrorState();
    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'clans-view__error';
    errorBlock.setAttribute('role', 'alert');

    appendTextElement(
      errorBlock,
      'p',
      'clans-view__error-message',
      errorEnvelope?.message ?? 'La corriente de maná se ha interrumpido: el Salón de Linajes no responde.',
    );

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'clans-view__error-retry button button--secondary';
    retryButton.textContent = 'Reintentar invocación';
    retryButton.addEventListener('click', () => retry());
    errorBlock.appendChild(retryButton);

    rosterContainer?.appendChild(errorBlock);
  }

  /**
   * Construye la tarjeta de un linaje (lectura pura: sin acciones de sesión).
   * @param {object} clanDto DTO del contrato de ClanController (Tarea 1.5).
   */
  function buildClanCard(clanDto) {
    const card = track(elementFactory('article'));
    card.className = 'clans-view__card';
    card.setAttribute('data-clan-id', clanDto.id);

    appendTextElement(card, 'h3', 'clans-view__card-name', clanDto.name);
    appendTextElement(card, 'p', 'clans-view__card-motto', `«${clanDto.motto}»`);

    const isNeutralClan = clanDto.id === NEUTRAL_CLAN_ID;
    appendTextElement(
      card,
      'p',
      isNeutralClan ? 'clans-view__card-domain clans-view__card-domain--neutral' : 'clans-view__card-domain',
      isNeutralClan
        ? 'Linaje neutro: custodio del canon, no compite por el Dominio (Art. III).'
        : `Dominio semanal: ${clanDto.domainPoints}`,
    );

    return card;
  }

  /**
   * Construye la tabla de clasificación semanal (HU-05). El orden lo trae el
   * backend (Dominio descendente); la neutra se marca como no competidora.
   * @param {Array<object>} clans Lista de ClanDto.
   */
  function buildRankingTable(clans) {
    const table = track(elementFactory('table'));
    table.className = 'clans-view__ranking';
    table.setAttribute('aria-label', 'Clasificación semanal de Dominio del Grimorio');

    const headerRow = elementFactory('tr');
    headerRow.className = 'clans-view__ranking-header-row';
    for (const headerText of ['Posición', 'Linaje', 'Dominio semanal']) {
      const headerCell = elementFactory('th');
      headerCell.className = 'clans-view__ranking-header';
      headerCell.setAttribute('scope', 'col');
      headerCell.textContent = headerText;
      headerRow.appendChild(headerCell);
    }
    const tableHead = elementFactory('thead');
    tableHead.appendChild(headerRow);
    table.appendChild(tableHead);

    const tableBody = elementFactory('tbody');
    clans.forEach((clanDto, index) => {
      const row = elementFactory('tr');
      row.className = clanDto.id === NEUTRAL_CLAN_ID
        ? 'clans-view__ranking-row clans-view__ranking-row--neutral'
        : 'clans-view__ranking-row';
      row.setAttribute('data-clan-id', clanDto.id);

      const positionCell = elementFactory('td');
      positionCell.className = 'clans-view__ranking-position';
      positionCell.textContent = clanDto.id === NEUTRAL_CLAN_ID ? '—' : String(index + 1);
      row.appendChild(positionCell);

      const nameCell = elementFactory('td');
      nameCell.className = 'clans-view__ranking-name';
      nameCell.textContent = clanDto.id === NEUTRAL_CLAN_ID
        ? `${clanDto.name} (neutro)`
        : clanDto.name;
      row.appendChild(nameCell);

      const domainCell = elementFactory('td');
      domainCell.className = 'clans-view__ranking-domain';
      domainCell.textContent = clanDto.id === NEUTRAL_CLAN_ID
        ? 'Neutro'
        : String(clanDto.domainPoints);
      row.appendChild(domainCell);

      tableBody.appendChild(row);
      track(row);
    });
    table.appendChild(tableBody);

    return table;
  }

  /**
   * Consulta el listado y pinta el salón. Guardia anti-carreras: una
   * respuesta tardía de una consulta antigua no pinta.
   */
  async function fetchAndRenderClans() {
    const currentSequence = ++fetchSequence;
    const result = await activeSpellClient.fetchClansPreview();

    if (currentSequence !== fetchSequence) return;
    if (!viewRoot || !mountedNodes.includes(viewRoot)) return; // vista destruida.

    if (!result.success) {
      renderErrorState(result.error);
      return;
    }

    clearErrorState();
    rosterContainer.replaceChildren?.();
    if (!rosterContainer.replaceChildren) {
      for (const child of [...rosterContainer.children]) child.remove();
    }

    if (result.data.length === 0) {
      appendTextElement(
        rosterContainer,
        'p',
        'clans-view__empty',
        'El Salón de Linajes aguarda: aún ningún linaje ha sido forjado.',
      );
      return;
    }

    const roster = track(elementFactory('div'));
    roster.className = 'clans-view__roster';
    for (const clanDto of result.data) {
      roster.appendChild(buildClanCard(clanDto));
    }
    rosterContainer.appendChild(roster);
    rosterContainer.appendChild(buildRankingTable(result.data));
  }

  /** Reintenta la última consulta tras un fallo de red (RF-06.3). */
  function setSpellClient(nextSpellClient) {
    activeSpellClient = nextSpellClient;
  }

  async function retry() {
    fetchSequence++; // invalida respuestas en vuelo del cliente fallido.
    await fetchAndRenderClans();
  }

  /**
   * Monta la vista completa. Idempotente y de solo lectura: ningún control
   * exige sesión (criterio RF-02.2).
   */
  async function render() {
    destroy(false);

    viewRoot = track(elementFactory('section'));
    // El tomo central acota el salón a 1280 px (Tarea 2.1, RF-05.1).
    viewRoot.className = 'clans-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // El salón pasa a ser la vista activa del store (plan 4.1).
    store.setState({ currentView: 'clans' });

    appendTextElement(viewRoot, 'h1', 'clans-view__title', 'Salón de Linajes');
    appendTextElement(
      viewRoot,
      'p',
      'clans-view__intro',
      'Los linajes que custodian el grimorio, y su Dominio semanal forjado con hechizos validados. La lectura es libre para todo visitante del santuario.',
    );

    rosterContainer = track(elementFactory('div'));
    rosterContainer.className = 'clans-view__list';
    viewRoot.appendChild(rosterContainer);

    const loading = appendTextElement(rosterContainer, 'p', 'clans-view__loading', 'Convocando a los linajes…');
    await fetchAndRenderClans();
    loading.remove();
  }

  /**
   * Retira la vista del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromRoot=true] El render interno lo invoca con
   *        false para limpiar la renderización previa antes de volver a montar.
   */
  function destroy(removeFromRoot = true) {
    fetchSequence++; // invalida respuestas en vuelo.
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;
    rosterContainer = null;
    if (removeFromRoot) {
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('clans-view')) {
          child.remove?.();
        }
      }
    }
  }

  return { render, destroy, retry, setSpellClient };
}

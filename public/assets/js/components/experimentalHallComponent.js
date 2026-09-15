/**
 * experimentalHallComponent.js — Pabellón público del Atrio de los Arcanos
 * Experimentales.
 *
 * Tarea 5.2 (TASKS-08). Renderiza en el catálogo comunitario los conjuros en
 * deliberación (`experimental`), cada uno con su medidor circular de firmas
 * (0/3, 1/3, 2/3) y su botón de prueba en la Cámara de Conjuración
 * (RF-05.1, RF-05.2, RF-05.3, RNF-03).
 *
 * Contrato de datos: `GET /api/v1/moderation/experimental` vía moderationClient
 * (Tarea 5.1), cuyo sobre porta:
 *   - `data.hallWarning` — la INSIGNIA del Atrio: { code, legend, article,
 *     pointsBlocked }. Es atributo del ATRIO, no de cada obra: el marco rúnico
 *     de advertencia se declara UNA vez en el encabezado del pabellón y el
 *     frontend lo exhibe tal cual, jamás lo reescribe (plan 2.2, nota 3 de la
 *     Tarea 3.1: una copia por tarjeta sería una mentira a punto de divergir).
 *   - `data.items` — ModerationQueueItemDto[]: spellId, spellName, authorAlias,
 *     signaturesCount, signaturesIndicator, elementalAffinity, magicSchool,
 *     originClanName, submittedAt, waitingDays…
 *   - `data.pagination` — el censo real de la cola.
 *
 * Decisiones que costará reconstruir:
 *   1. El componente CONTEMPLA; no juzga. El medidor viaja servido por el
 *      expediente canónico (`signaturesIndicator`), la advertencia viaja en
 *      `hallWarning.legend` —su fuente única— y el bloqueo de PDA en
 *      `hallWarning.pointsBlocked` (RF-05.3): ninguna aritmética del Cónclave
 *      se reimplementa en el navegador (Art. II).
 *   2. El catálogo solo puede contener obras en deliberación: la consulta fija
 *      `status = experimental` en el SERVIDOR y el cliente jamás pide otro
 *      estado (RF-05.1: `draft` y `rejected` jamás aparecen). El componente,
 *      por defensa, descarta cualquier elemento sin identidad.
 *   3. La prueba en el simulador es un GESTO DECLARADO: el botón emite
 *      `onSpellTest(spellId)` —o el evento `moderation:hall-test`— y el
 *      orquestador abre la Cámara de Conjuración (RF-05.2). El componente
 *      jamás navega por su cuenta.
 *   4. Actualización dirigida por evento (plan 4.1): `setHallItems()` repinta
 *      el pabellón cuando el bus anuncia `moderation:submitted`,
 *      `moderation:signed` o `moderation:consecrated`, y
 *      `setSignatureProgress()` sube el medidor de UNA tarjeta sin re-consulta
 *      —el contador jamás se suma aquí: viaja el valor ya contado en servidor.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; textContent puro y `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1); cero librerías ni plantillas.
 *   - Artículo II: el componente jamás calcula maná, firmas ni cupos.
 *   - Artículo III: el Atrio es público —un visitante anónimo no milita en
 *     hermandad alguna—, de modo que `hasEthicalConflict` jamás se lee ni se
 *     pinta aquí: la memoria de clanes no llega al navegador.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): sin insignia del backend, el marco
 *     de advertencia usa su leyenda de reserva declarada una sola vez; sin
 *     corriente de maná, un error temático con reintento.
 */

/**
 * Leyenda del Atrio vacío: no es un error, es un santuario sin obras en prueba.
 */
export const EXPERIMENTAL_HALL_EMPTY_LEGEND =
  'El Atrio aguarda en silencio: ninguna obra se halla en Deliberación Arcana.';

/** Leyenda del corte de corriente (AGENTS.md 6.1: error controlado). */
export const EXPERIMENTAL_HALL_ERROR_LEGEND =
  'La corriente de maná se ha interrumpido: el Atrio de Pruebas no responde.';

/**
 * Insignia de reserva del Atrio (solo si el backend llegara sin `hallWarning`).
 *
 * La fuente canónica de la leyenda vive en `ModerationController::HALL_WARNING_LEGEND`;
 * esta copia jamás se emplea en operación ordinaria, y su única misión es que
 * la interfaz no presente un marco desnudo ante un backend antiguo.
 */
export const EXPERIMENTAL_HALL_FALLBACK_WARNING = Object.freeze({
  code: 'UNDER_ARCANE_DELIBERATION',
  legend: 'En Deliberación Arcana — Obra en Fase de Prueba',
  pointsBlocked: true,
});

/** Texto del botón de prueba en la Cámara de Conjuración (RF-05.2). */
const TEST_BUTTON_LABEL = 'Poner a prueba en el simulador';

/** Rotulo del linaje de un ermitaño: la obra sin estandarte también se exhibe. */
const HERMIT_LINEAGE_LEGEND = 'Obra de ermitaño: sin estandarte';

/**
 * Crea el componente del Atrio de los Arcanos Experimentales.
 *
 * @param {HTMLElement} mountRoot Contenedor del catálogo público.
 * @param {Object} options
 * @param {Object} options.moderationClient Cliente HTTP (Tarea 5.1; necesita
 *        fetchExperimentalHall).
 * @param {(spellId: string) => void} [options.onSpellTest] Gesto de prueba en
 *        el simulador (RF-05.2); el orquestador abre la Cámara de Conjuración.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión (arneses).
 * @returns {Object} API: { render, setHallItems, setSignatureProgress, destroy }.
 */
export function createExperimentalHallComponent(mountRoot, options = {}) {
  const {
    moderationClient,
    onSpellTest,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Nodos vivos del componente, para limpieza determinista. */
  const mountedNodes = [];

  /** Insignia del Atrio servida por el backend (o su reserva). */
  let hallWarning = EXPERIMENTAL_HALL_FALLBACK_WARNING;

  /** Guardia anti-carreras: solo la petición más reciente pinta el pabellón. */
  let fetchSequence = 0;

  /** El componente quedó destruido: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas del montaje actual. */
  let hallRoot = null;
  let statusRegion = null;

  /** Registra un nodo como hijo del componente para su ciclo de vida. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /** Retira del árbol los nodos del montaje previo (re-render idempotente). */
  function clearPainting() {
    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    hallRoot = null;
    statusRegion = null;
  }

  /** Normaliza la insignia del Atrio ante un backend que no la trajera. */
  function adoptHallWarning(servedWarning) {
    if (servedWarning === null || servedWarning === undefined || typeof servedWarning !== 'object') {
      hallWarning = EXPERIMENTAL_HALL_FALLBACK_WARNING;
      return;
    }

    hallWarning = {
      code: typeof servedWarning.code === 'string' && servedWarning.code !== ''
        ? servedWarning.code
        : EXPERIMENTAL_HALL_FALLBACK_WARNING.code,
      legend: typeof servedWarning.legend === 'string' && servedWarning.legend.trim() !== ''
        ? servedWarning.legend
        : EXPERIMENTAL_HALL_FALLBACK_WARNING.legend,
      pointsBlocked: servedWarning.pointsBlocked !== false,
    };
  }

  /**
   * Monta el armazón del pabellón: el marco rúnico de advertencia —UNA sola
   * vez para todo el catálogo— y la región viva de anuncios.
   */
  function buildHallShell() {
    hallRoot = track(elementFactory('section'));
    hallRoot.className = 'experimental-hall';
    hallRoot.setAttribute('id', 'experimentalHall');
    hallRoot.setAttribute('data-hall-warning-code', hallWarning.code);
    // El bloqueo de PDA es del ATRIO (RF-05.3): se declara en el marco, una vez.
    hallRoot.setAttribute('data-points-blocked', hallWarning.pointsBlocked === true ? 'true' : 'false');
    hallRoot.setAttribute('role', 'region');
    hallRoot.setAttribute('aria-labelledby', 'experimentalHallTitle');

    const heading = appendTextElement(hallRoot, 'h2', 'experimental-hall__title', 'Atrio de los Arcanos Experimentales');
    heading.setAttribute('id', 'experimentalHallTitle');

    // El MARCO RÚNICO de advertencia (RF-05.1): una sola leyenda para el
    // pabellón entero, tomada de su fuente única (hallWarning.legend).
    const warningFrame = track(elementFactory('p'));
    warningFrame.className = 'experimental-hall__warning';
    warningFrame.setAttribute('data-hall-warning', hallWarning.code);
    warningFrame.setAttribute('role', 'note');
    warningFrame.textContent = hallWarning.legend;
    hallRoot.appendChild(warningFrame);

    if (hallWarning.pointsBlocked === true) {
      // El bloqueo de gloria se PROCLAMA (RF-05.3): probar aquí no devenga PDA.
      const pointsNotice = appendTextElement(
        hallRoot,
        'p',
        'experimental-hall__points-notice',
        'Las obras en deliberación no devengarán puntos de dominio: la gloria se reserva a la consagración.',
      );
      pointsNotice.setAttribute('data-points-blocked', 'true');
    }

    statusRegion = appendTextElement(hallRoot, 'p', 'experimental-hall__announcement', '');
    statusRegion.setAttribute('role', 'status');
    statusRegion.setAttribute('aria-live', 'polite');
    statusRegion.setAttribute('aria-atomic', 'true');

    mountRoot.appendChild(hallRoot);

    return hallRoot;
  }

  /**
   * Pinta el medidor circular de firmas de UNA tarjeta (RF-05.1).
   *
   * El valor viaja SERVIDO por el expediente (firmas contadas en servidor);
   * aquí solo se retrata con su clave de datos y su nombre accesible.
   *
   * @param {object} itemDto Elemento de la cola del Atrio.
   * @returns {HTMLElement} El medidor montado.
   */
  function buildSignatureMeter(itemDto) {
    const signaturesCount = Number.isInteger(Number(itemDto.signaturesCount))
      ? Number(itemDto.signaturesCount)
      : 0;
    const indicator = typeof itemDto.signaturesIndicator === 'string' && itemDto.signaturesIndicator !== ''
      ? itemDto.signaturesIndicator
      : `${signaturesCount}/3`;

    const meter = track(elementFactory('span'));
    meter.className = 'experimental-hall__meter';
    meter.setAttribute('data-signatures', String(signaturesCount));
    meter.setAttribute('data-signatures-required', '3');
    meter.setAttribute('data-consignable', signaturesCount >= 3 ? 'false' : 'true');
    // Medidor circular: su geometría la dibuja la hoja de estilos (Tarea 6.3)
    // a partir de las claves de datos; el componente jamás aritmetiza el arco.
    meter.setAttribute('role', 'img');
    meter.setAttribute(
      'aria-label',
      signaturesCount === 0
        ? 'Ninguna firma de consagración aún: el medidor marca cero de tres.'
        : `${signaturesCount} de 3 firmas de consagración reunidas.`,
    );
    meter.textContent = indicator;

    return meter;
  }

  /**
   * Pinta la tarjeta de UNA obra en deliberación (RF-05.1, RF-05.2).
   *
   * @param {object} itemDto ModerationQueueItemDto servido por la cola.
   */
  function paintSpellCard(itemDto) {
    const spellId = String(itemDto.spellId ?? '');
    const spellName = String(itemDto.spellName ?? '');

    const card = track(elementFactory('article'));
    card.className = 'experimental-hall__card';
    card.setAttribute('data-spell-id', spellId);
    // El estado no viaja en la tarjeta: TODO el catálogo está en deliberación
    // porque la consulta del servidor lo fija así (RF-05.1).
    card.setAttribute('data-status', 'experimental');
    card.setAttribute('aria-label', `Obra en Deliberación Arcana: ${spellName}.`);

    const nameHeading = appendTextElement(card, 'h3', 'experimental-hall__spell-name', spellName);
    nameHeading.setAttribute('id', `experimentalHallSpell-${spellId}`);
    card.setAttribute('aria-labelledby', `experimentalHallSpell-${spellId}`);

    if (typeof itemDto.spellSlug === 'string' && itemDto.spellSlug !== '') {
      card.setAttribute('data-spell-slug', itemDto.spellSlug);
    }

    const authorAlias = String(itemDto.authorAlias ?? '');
    if (authorAlias !== '') {
      appendTextElement(card, 'p', 'experimental-hall__author', `Forjada por ${authorAlias}`);
    }

    // Linaje patrimonial: la obra del ermitaño se exhibe sin estandarte (plan
    // 3.1: un LEFT JOIN la conserva en el Atrio, justo la que ningún veto alcanza).
    const originClanName = typeof itemDto.originClanName === 'string' && itemDto.originClanName !== ''
      ? itemDto.originClanName
      : null;
    const lineageParagraph = appendTextElement(
      card,
      'p',
      'experimental-hall__lineage',
      originClanName ?? HERMIT_LINEAGE_LEGEND,
    );
    lineageParagraph.setAttribute('data-origin-clan', originClanName ?? '');

    card.appendChild(buildSignatureMeter(itemDto));

    if (Number.isInteger(Number(itemDto.waitingDays))) {
      const waitingDays = Number(itemDto.waitingDays);
      appendTextElement(
        card,
        'p',
        'experimental-hall__waiting',
        waitingDays === 0
          ? 'Recién elevada a la Torre de Moderación.'
          : `Aguarda veredicto desde hace ${waitingDays} ${waitingDays === 1 ? 'día' : 'días'}.`,
      );
    }

    // El gesto de prueba (RF-05.2): el componente declara la intención y el
    // orquestador abre la Cámara de Conjuración. Jamás navega por su cuenta.
    const testButton = track(elementFactory('button'));
    testButton.type = 'button';
    testButton.className = 'experimental-hall__test-button button button--secondary';
    testButton.setAttribute('data-action', 'testSpell');
    testButton.textContent = TEST_BUTTON_LABEL;
    testButton.addEventListener('click', () => announceSpellTest(spellId));
    card.appendChild(testButton);

    hallRoot.appendChild(card);
  }

  /** Anuncia el gesto de prueba por el bus de eventos y por callback (plan 4.1). */
  function announceSpellTest(spellId) {
    if (spellId === '') return;

    // El anuncio viaja SIEMPRE por el bus desacoplado: otra vista (el
    // simulador de SPEC-05) puede listening sin acoplarse a este componente,
    // con o sin callback declarado en el montaje.
    try {
      documentRef.defaultView?.dispatchEvent?.(
        new CustomEvent('moderation:hall-test', { detail: { spellId } }),
      );
    } catch {
      // Sin CustomEvent (entornos mínimos): el callback portará la intención.
    }

    if (typeof onSpellTest === 'function') {
      onSpellTest(spellId);
    }
  }

  /** Pinta el Atrio vacío: no es un error, es un santuario sin obras en prueba. */
  function paintEmptyHall() {
    const empty = appendTextElement(hallRoot, 'p', 'experimental-hall__empty', EXPERIMENTAL_HALL_EMPTY_LEGEND);
    empty.setAttribute('role', 'status');
    statusRegion.textContent = '';
  }

  /** Pinta el corte de corriente con su reintento ceremonial (AGENTS.md 6.1). */
  function paintErrorState() {
    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'experimental-hall__error';
    errorBlock.setAttribute('role', 'alert');

    appendTextElement(errorBlock, 'p', 'experimental-hall__error-message', EXPERIMENTAL_HALL_ERROR_LEGEND);

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'experimental-hall__error-retry button button--secondary';
    retryButton.textContent = 'Reintentar invocación';
    retryButton.addEventListener('click', () => render());
    errorBlock.appendChild(retryButton);

    hallRoot.appendChild(errorBlock);
    statusRegion.textContent = '';
  }

  /**
   * Pinta el catálogo a partir de los elementos YA SERVIDOS. Filtra por
   * identidad (defensa, no juicio): la condición `experimental` la fija la
   * consulta del servidor y el componente jamás pide otro estado.
   *
   * @param {object[]} items Elementos de la cola del Atrio.
   */
  function paintItems(items) {
    const presentableItems = (Array.isArray(items) ? items : []).filter(
      (itemDto) => String(itemDto?.spellId ?? '') !== '' && String(itemDto?.spellName ?? '') !== '',
    );

    if (presentableItems.length === 0) {
      paintEmptyHall();
      return;
    }

    for (const itemDto of presentableItems) {
      paintSpellCard(itemDto);
    }

    statusRegion.textContent = `${presentableItems.length} ${presentableItems.length === 1 ? 'obra aguarda' : 'obras aguardan'} en Deliberación Arcana.`;
  }

  /**
   * Consulta el Atrio y pinta el pabellón. Guardia anti-carreras: una
   * respuesta tardía de una consulta antigua jamás pinta.
   */
  async function render() {
    if (isDestroyed) return;
    if (typeof moderationClient?.fetchExperimentalHall !== 'function') {
      clearPainting();
      buildHallShell();
      paintErrorState();
      return;
    }

    const currentSequence = ++fetchSequence;

    clearPainting();
    buildHallShell();

    const result = await moderationClient.fetchExperimentalHall();

    // La respuesta llega tarde o el componente ya no vive: no se pinta.
    if (currentSequence !== fetchSequence || isDestroyed || hallRoot === null) return;

    if (!result?.success) {
      paintErrorState();
      return;
    }

    adoptHallWarning(result.data?.hallWarning);
    paintItems(result.data?.items ?? []);
  }

  /**
   * Repinta el pabellón con elementos ya conocidos (dirigido por evento,
   * plan 4.1: `moderation:submitted`, `moderation:signed`,
   * `moderation:consecrated`), sin consulta alguna.
   *
   * La insignia viaja de nuevo porque un pabellón repintado debe seguir
   * proclamando la misma advertencia con la misma fuente única.
   *
   * @param {object[]} items Elementos de la cola del Atrio.
   * @param {object} [servedHallWarning] Insignia servida por el backend.
   */
  function setHallItems(items, servedHallWarning) {
    if (isDestroyed) return;

    fetchSequence++; // invalida respuestas en vuelo.
    adoptHallWarning(servedHallWarning ?? hallWarning);
    clearPainting();
    buildHallShell();
    paintItems(items);
  }

  /**
   * Sube el medidor de UNA tarjeta sin re-consulta (plan 4.1).
   *
   * El valor viaja YA CONTADO desde el servidor (la firma lo recalcula desde
   * las firmas vivas, Tarea 2.4); aquí jamás se suma uno a ciegas.
   *
   * @param {string} spellId Identificador de la obra firmada.
   * @param {number} signaturesCount Firmas vivas ya contadas en servidor.
   */
  function setSignatureProgress(spellId, signaturesCount) {
    if (isDestroyed || hallRoot === null) return;

    const card = hallRoot.children.find(
      (child) => child.getAttribute?.('data-spell-id') === String(spellId),
    );
    if (card === undefined || card === null) return;

    const count = Number.isInteger(Number(signaturesCount)) ? Number(signaturesCount) : 0;
    const meter = card.children.find((child) => child.classes?.has('experimental-hall__meter'));
    if (meter === undefined || meter === null) return;

    meter.setAttribute('data-signatures', String(count));
    meter.setAttribute('data-consignable', count >= 3 ? 'false' : 'true');
    meter.setAttribute(
      'aria-label',
      count === 0
        ? 'Ninguna firma de consagración aún: el medidor marca cero de tres.'
        : `${count} de 3 firmas de consagración reunidas.`,
    );
    meter.textContent = `${count}/3`;
  }

  /** Retira el pabellón del montaje y libera sus nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    fetchSequence++; // invalida respuestas en vuelo.
    clearPainting();
  }

  return { render, setHallItems, setSignatureProgress, destroy };
}

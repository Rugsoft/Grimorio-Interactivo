/**
 * mastersTowerComponent.js — Panel de la Torre de Deliberación.
 *
 * Tarea 5.3 (TASKS-08). Panel EXCLUSIVO de los Maestros de la Torre y del
 * Administrador Supremo (RF-05.4): la cola de espera de conjuros
 * experimentales ordenada por antigüedad, filtros por elemento y escuela, y
 * sobre cada tarjeta el veredicto del Artículo III ya resuelto en servidor,
 * que inhabilita el botón de firma con su alerta ceremonial (RF-02.1,
 * RF-02.2, RF-03.3, RNF-03).
 *
 * Contrato de datos: `GET /api/v1/moderation/queue` vía moderationClient
 * (Tarea 5.1), cuyo sobre porta:
 *   - `data.canon` — los umbrales del Cónclave declarados por su autoridad
 *     (`MasterDeliberationService::deliberationCanon()`): signaturesRequired,
 *     maxGlossLength, minObjectionLength, judgeRole. El panel JAMÁS escribe un
 *     tres, un doscientos cincuenta o un veinte a mano (Art. II).
 *   - `data.items` — ModerationQueueItemDto[] con el veredicto ético YA
 *     RESUELTO: `hasEthicalConflict` y `ethicalVeto: {code, legend}`, donde el
 *     código es `ownAuthorship` o `clanIncompatibility` y la leyenda ceremonial
 *     viaja desde `CONFLICT_OF_INTEREST_MESSAGE`, su fuente única (plan 3.2,
 *     nota 2). El navegador jamás deduce la ley de un `true`: la memoria de
 *     clanes no llega al cliente (Art. III).
 *   - `data.pagination` — el censo real de la cola.
 *
 * Decisiones que costará reconstruir:
 *   1. La Torre admite DOS rangos; el panel no verifica ninguno. Que el
 *      consultante sea Maestro o soberano lo dicta el backend (403
 *      INSUFFICIENT_RANK_TO_JUDGE); el panel pinta la cola que el santuario
 *      le sirva y propaga sus veredictos tal cual (Art. II, III).
 *   2. La CAUSA del veto se declara, no se adivina: cada tarjeta vetada
 *      porta `data-ethical-veto-code` y su leyenda literal, y el botón de
 *      firma nace inhabilitado con la alerta. El rótulo por defecto
 *      («Veto Constitucional: Hermandad Incompatible (Art. III)») es la
 *      RESERVA del plan 4.2; la leyenda del servidor —presente desde la
 *      Tarea 3.2— tiene precedencia al pintarse siempre que viaja.
 *   3. El objetivo del veto muda el rótulo: `ownAuthorship` proclama que
 *      «la propia pluma no es juicio» y `clanIncompatibility` el veto de
 *      hermandad, para que el Maestro sepa si es la pluma o el estandarte.
 *   4. Los filtros son de la TORRE y viven en su interfaz; su acotación
 *      canónica (minSignatures ≤ techo, paginación positiva) la dicta el
 *      backend con su 400. El panel repinta con lo que el santuario sirva.
 *   5. Firma y objeción son GESTOS DECLARADOS: `onSignatureIntent(spellId)`
 *      y `onObjectionIntent(spellId)` —más los eventos `moderation:sign-intent`
 *      y `moderation:objection-intent` en el bus— anuncian la intención; los
 *      modales litúrgicos de la Tarea 6.1 los recogen. El panel jamás redacta
 *      glosa ni dictamen.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; textContent puro y `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1); cero librerías ni plantillas.
 *   - Artículo II: el componente jamás calcula umbrales, firmas ni cupos.
 *   - Artículo III: el veto llega RESUELTO; la memoria de clanes jamás se
 *     filtra ni se deduce en el navegador.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): sin canon del backend, el panel usa
 *     su reserva declarada una sola vez; sin corriente de maná, alerta con
 *     reintento; el Maestro del ermitaño y el de hermandad ajena firman, el
 *     vetado ve su causa.
 */

import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';

/** Índice de elementos canónicos por clave técnica (espejo del Códice). */
const ELEMENT_BY_ID = Object.freeze(
  Object.fromEntries(ELEMENTAL_MATRIX_ELEMENTS.map((element) => [element.id, element])),
);

/**
 * Reserva del canon del Cónclave (solo si el backend llegara sin `canon`).
 *
 * La fuente canónica vive en `MasterDeliberationService::deliberationCanon()`;
 * esta copia jamás se emplea en operación ordinaria, y su única misión es que
 * el panel no presente umbrales desnudos ante un backend antiguo.
 */
export const MASTERS_TOWER_FALLBACK_CANON = Object.freeze({
  signaturesRequired: 3,
  maxGlossLength: 250,
  minObjectionLength: 20,
  judgeRole: 'master',
});

/**
 * Leyendas ceremoniales de los códigos de veto ético (RNF-03).
 *
 * El rótulo canónico del plan 4.2 («Veto Constitucional: Hermandad
 * Incompatible») vive aquí como REVERVA para el veto de hermandad; la leyenda
 * del servidor (`ethicalVeto.legend`, presente desde la Tarea 3.2) tiene
 * precedencia al pintarse siempre que viaja.
 */
export const MASTERS_TOWER_VETO_LEGENDS = Object.freeze({
  clanIncompatibility: 'Veto Constitucional: Hermandad Incompatible (Art. III)',
  ownAuthorship: 'Veto Constitucional: la propia pluma no es juicio (Art. III)',
});

/** Leyenda del veto de causa desconocida (un backend antiguo sin leyenda). */
export const MASTERS_TOWER_VETO_UNKNOWN_LEGEND =
  'Veto Constitucional: tu vínculo con esta obra veda el juicio (Art. III)';

/** Leyenda del Atrio de la Torre vacío: no es error, es una cola despejada. */
export const MASTERS_TOWER_EMPTY_LEGEND =
  'La Torre aguarda en silencio: ninguna obra espera veredicto del Cónclave.';

/** Leyenda del corte de corriente (AGENTS.md 6.1: error controlado). */
export const MASTERS_TOWER_ERROR_LEGEND =
  'La corriente de maná se ha interrumpido: la Torre de Deliberación no responde.';

/** Texto del botón de firma (RF-02.2). */
const SIGN_BUTTON_LABEL = 'Estampar Firma de Consagración';

/** Texto del botón de objeción (RF-02.5). */
const OBJECT_BUTTON_LABEL = 'Emitir Dictamen de Objeción';

/** Rotulo del linaje de un ermitaño: la obra sin estandarte también se juzga. */
const HERMIT_LINEAGE_LEGEND = 'Obra de ermitaño: sin estandarte';

/** Etiqueta de la sección de filtros de la Torre (RF-05.4). */
const FILTERS_LEGEND = 'Refinar la cola del Cónclave';

/**
 * Catálogo de escuelas mágicas para el filtro (RF-05.4).
 *
 * Espejo del canon `magic_schools` (SPEC-01); el backend dicta la acotación
 * real y una escuela ajena al Códice devuelve una cola vacía, no un error.
 */
const MAGIC_SCHOOL_OPTIONS = Object.freeze([
  { slug: 'abjuration', name: 'Abjuración' },
  { slug: 'conjuration', name: 'Conjuración' },
  { slug: 'divination', name: 'Adivinación' },
  { slug: 'enchantment', name: 'Encantamiento' },
  { slug: 'evocation', name: 'Evocación' },
  { slug: 'illusion', name: 'Ilusión' },
  { slug: 'necromancy', name: 'Nigromancia' },
  { slug: 'transmutation', name: 'Transmutación' },
]);

/**
 * Crea el panel de la Torre de Deliberación.
 *
 * @param {HTMLElement} mountRoot Contenedor de la vista solemne (Tarea 6.4).
 * @param {Object} options
 * @param {Object} options.moderationClient Cliente HTTP (Tarea 5.1; necesita
 *        fetchDeliberationQueue).
 * @param {(spellId: string) => void} [options.onSignatureIntent] Gesto de
 *        firma (RF-02.2); el orquestador abre el modal de glosa.
 * @param {(spellId: string) => void} [options.onObjectionIntent] Gesto de
 *        objeción (RF-02.5); el orquestador abre el modal del dictamen.   * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
   *        inyectable (arneses sin navegador).
   * @param {Document} [options.documentRef] Documento anfitrión (arneses).
   * @param {(filters: object) => void} [options.onFiltersChanged] Gesto de
   *        cambio de filtros (RF-05.4); el orquestador re-consulta la cola.
   * @returns {Object} API: { render, setQueueItems, setFilters, destroy }.
   */
export function createMastersTowerComponent(mountRoot, options = {}) {
  const {
    moderationClient,
    onSignatureIntent,
    onObjectionIntent,
    onFiltersChanged,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Nodos vivos del componente, para limpieza determinista. */
  const mountedNodes = [];

  /** Canon del Cónclave servido por el backend (o su reserva). */
  let deliberationCanon = MASTERS_TOWER_FALLBACK_CANON;

  /** Filtros activos de la cola (RF-05.4). */
  let activeFilters = { element: '', school: '', minSignatures: 0 };

  /** Guardia anti-carreras: solo la petición más reciente pinta el panel. */
  let fetchSequence = 0;

  /** El componente quedó destruido: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas del montaje actual. */
  let towerRoot = null;
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
    towerRoot = null;
    statusRegion = null;
  }

  /** Normaliza el canon ante un backend que no lo trajera. */
  function adoptDeliberationCanon(servedCanon) {
    if (servedCanon === null || servedCanon === undefined || typeof servedCanon !== 'object') {
      deliberationCanon = MASTERS_TOWER_FALLBACK_CANON;
      return;
    }

    deliberationCanon = {
      signaturesRequired: Number.isInteger(Number(servedCanon.signaturesRequired))
        ? Number(servedCanon.signaturesRequired)
        : MASTERS_TOWER_FALLBACK_CANON.signaturesRequired,
      maxGlossLength: Number.isInteger(Number(servedCanon.maxGlossLength))
        ? Number(servedCanon.maxGlossLength)
        : MASTERS_TOWER_FALLBACK_CANON.maxGlossLength,
      minObjectionLength: Number.isInteger(Number(servedCanon.minObjectionLength))
        ? Number(servedCanon.minObjectionLength)
        : MASTERS_TOWER_FALLBACK_CANON.minObjectionLength,
      judgeRole: typeof servedCanon.judgeRole === 'string' && servedCanon.judgeRole !== ''
        ? servedCanon.judgeRole
        : MASTERS_TOWER_FALLBACK_CANON.judgeRole,
    };
  }

  /** Anuncia un gesto por el bus desacoplado (plan 4.1). */
  function emitBusEvent(eventName, detail) {
    try {
      documentRef.defaultView?.dispatchEvent?.(new CustomEvent(eventName, { detail }));
    } catch {
      // Sin CustomEvent (entornos mínimos): el callback portará la intención.
    }
  }

  /**
   * Monta el armazón del panel: el título, la región viva de anuncios y los
   * filtros de la Torre (RF-05.4).
   */
  function buildTowerShell() {
    towerRoot = track(elementFactory('section'));
    towerRoot.className = 'masters-tower';
    towerRoot.setAttribute('id', 'mastersTower');
    towerRoot.setAttribute('data-judge-role', deliberationCanon.judgeRole);
    towerRoot.setAttribute('data-signatures-required', String(deliberationCanon.signaturesRequired));
    towerRoot.setAttribute('role', 'region');
    towerRoot.setAttribute('aria-labelledby', 'mastersTowerTitle');

    const heading = appendTextElement(towerRoot, 'h2', 'masters-tower__title', 'Torre de Deliberación');
    heading.setAttribute('id', 'mastersTowerTitle');

    appendTextElement(
      towerRoot,
      'p',
      'masters-tower__notice',
      'Recinto del Cónclave: solo los Maestros deliberan aquí, y jamás sobre las obras de su propio estandarte, linajes recientes o propia pluma.',
    );

    statusRegion = appendTextElement(towerRoot, 'p', 'masters-tower__announcement', '');
    statusRegion.setAttribute('role', 'status');
    statusRegion.setAttribute('aria-live', 'polite');
    statusRegion.setAttribute('aria-atomic', 'true');

    buildFilters();

    mountRoot.appendChild(towerRoot);

    return towerRoot;
  }

  /**
   * Construye los filtros de la cola (RF-05.4): por elemento, por escuela y
   * por firmas mínimas. Sus valores viajan al backend, que dicta su acotación.
   */
  function buildFilters() {
    const filtersBlock = track(elementFactory('div'));
    filtersBlock.className = 'masters-tower__filters';
    filtersBlock.setAttribute('role', 'group');
    filtersBlock.setAttribute('aria-label', FILTERS_LEGEND);

    // Filtro por afinidad elemental: los ocho elementos del Códice (SPEC-06).
    const elementLabel = appendTextElement(filtersBlock, 'label', 'masters-tower__filter-label', 'Elemento');
    const elementSelect = track(elementFactory('select'));
    elementSelect.className = 'masters-tower__filter-element';
    elementSelect.setAttribute('data-filter', 'element');
    elementLabel.setAttribute('for', 'mastersTowerFilterElement');
    elementSelect.setAttribute('id', 'mastersTowerFilterElement');
    const allElementsOption = track(elementFactory('option'));
    allElementsOption.value = '';
    allElementsOption.textContent = 'Todos los elementos';
    elementSelect.appendChild(allElementsOption);
    for (const element of ELEMENTAL_MATRIX_ELEMENTS) {
      const option = track(elementFactory('option'));
      option.value = element.id;
      option.textContent = element.name;
      elementSelect.appendChild(option);
    }
    elementSelect.value = activeFilters.element;
    elementSelect.addEventListener('change', () => {
      activeFilters = { ...activeFilters, element: elementSelect.value };
      emitFiltersChanged();
    });
    filtersBlock.appendChild(elementSelect);

    // Filtro por escuela mágica: el canon de `magic_schools` (SPEC-01).
    const schoolLabel = appendTextElement(filtersBlock, 'label', 'masters-tower__filter-label', 'Escuela');
    const schoolSelect = track(elementFactory('select'));
    schoolSelect.className = 'masters-tower__filter-school';
    schoolSelect.setAttribute('data-filter', 'school');
    schoolLabel.setAttribute('for', 'mastersTowerFilterSchool');
    schoolSelect.setAttribute('id', 'mastersTowerFilterSchool');
    for (const school of MAGIC_SCHOOL_OPTIONS) {
      const option = track(elementFactory('option'));
      option.value = school.slug;
      option.textContent = school.name;
      schoolSelect.appendChild(option);
    }
    schoolSelect.value = activeFilters.school;
    schoolSelect.addEventListener('change', () => {
      activeFilters = { ...activeFilters, school: schoolSelect.value };
      emitFiltersChanged();
    });
    filtersBlock.appendChild(schoolSelect);

    // Filtro por firmas mínimas: acotado 0..techo contra el canon servido.
    const signaturesLabel = appendTextElement(filtersBlock, 'label', 'masters-tower__filter-label', 'Firmas mínimas');
    const signaturesSelect = track(elementFactory('select'));
    signaturesSelect.className = 'masters-tower__filter-min-signatures';
    signaturesSelect.setAttribute('data-filter', 'minSignatures');
    signaturesLabel.setAttribute('for', 'mastersTowerFilterMinSignatures');
    signaturesSelect.setAttribute('id', 'mastersTowerFilterMinSignatures');
    for (let threshold = 0; threshold <= deliberationCanon.signaturesRequired; threshold += 1) {
      const option = track(elementFactory('option'));
      option.value = String(threshold);
      option.textContent = threshold === 0
        ? 'Cualquier número de firmas'
        : `${threshold} o más firmas`;
      signaturesSelect.appendChild(option);
    }
    signaturesSelect.value = String(activeFilters.minSignatures);
    signaturesSelect.addEventListener('change', () => {
      activeFilters = { ...activeFilters, minSignatures: Number(signaturesSelect.value) };
      emitFiltersChanged();
    });
    filtersBlock.appendChild(signaturesSelect);

    towerRoot.appendChild(filtersBlock);
  }

  /** Anuncia el cambio de filtros por callback y por el bus (plan 4.1). */
  function emitFiltersChanged() {
    if (typeof onFiltersChanged === 'function') {
      onFiltersChanged({ ...activeFilters });
    }
    emitBusEvent('moderation:tower-filters-changed', { ...activeFilters });
  }

  /**
   * Pinta el medidor de firmas de UNA tarjeta de la Torre (RF-05.1, RF-05.4).
   *
   * El valor viaja SERVIDO por el expediente; aquí solo se retrata con su
   * clave de datos y su nombre accesible.
   *
   * @param {object} itemDto Elemento de la cola.
   * @returns {HTMLElement} El medidor montado.
   */
  function buildSignatureMeter(itemDto) {
    const signaturesCount = Number.isInteger(Number(itemDto.signaturesCount))
      ? Number(itemDto.signaturesCount)
      : 0;
    const required = Number.isInteger(Number(itemDto.signaturesRequired))
      ? Number(itemDto.signaturesRequired)
      : deliberationCanon.signaturesRequired;

    const meter = track(elementFactory('span'));
    meter.className = 'masters-tower__meter';
    meter.setAttribute('data-signatures', String(signaturesCount));
    meter.setAttribute('data-signatures-required', String(required));
    meter.setAttribute('role', 'img');
    meter.setAttribute(
      'aria-label',
      signaturesCount === 0
        ? 'Ninguna firma de consagración aún: el medidor marca cero de tres.'
        : `${signaturesCount} de ${required} firmas de consagración reunidas.`,
    );
    meter.textContent = `${signaturesCount}/${required}`;

    return meter;
  }

  /**
   * Resuelve la leyenda ceremonial del veto de UNA tarjeta.
   *
   * La leyenda del SERVIDOR tiene precedencia (fuente única desde la Tarea
   * 3.2); las reservas del panel rotulan solo lo que el backend no trajo.
   *
   * @param {object} itemDto Elemento de la cola con su veredicto resuelto.
   * @returns {string} La leyenda a pintar.
   */
  function vetoLegendFor(itemDto) {
    const servedLegend = typeof itemDto.ethicalVeto?.legend === 'string' && itemDto.ethicalVeto.legend.trim() !== ''
      ? itemDto.ethicalVeto.legend
      : null;
    if (servedLegend !== null) {
      return servedLegend;
    }

    const vetoCode = String(itemDto.ethicalVeto?.code ?? '');
    if (vetoCode === '') {
      return MASTERS_TOWER_VETO_UNKNOWN_LEGEND;
    }

    return MASTERS_TOWER_VETO_LEGENDS[vetoCode] ?? MASTERS_TOWER_VETO_UNKNOWN_LEGEND;
  }

  /**
   * Pinta la tarjeta de UNA obra en la cola de la Torre (RF-05.4).
   *
   * Cada tarjeta evalúa el veredicto ético YA RESUELTO en servidor: si media
   * veto, el botón de firma nace inhabilitado con la alerta ceremonial que
   * declara la CAUSA (RF-03.3, plan 4.2).
   *
   * @param {object} itemDto ModerationQueueItemDto servido por la cola.
   */
  function paintSpellCard(itemDto) {
    const spellId = String(itemDto.spellId ?? '');
    const spellName = String(itemDto.spellName ?? '');
    const hasEthicalConflict = itemDto.hasEthicalConflict === true;

    const card = track(elementFactory('article'));
    card.className = 'masters-tower__card';
    card.setAttribute('data-spell-id', spellId);
    card.setAttribute('data-status', 'experimental');
    card.setAttribute('data-ethical-conflict', hasEthicalConflict ? 'true' : 'false');
    card.setAttribute('aria-label', `Obra en deliberación ante el Cónclave: ${spellName}.`);

    const nameHeading = appendTextElement(card, 'h3', 'masters-tower__spell-name', spellName);
    nameHeading.setAttribute('id', `mastersTowerSpell-${spellId}`);
    card.setAttribute('aria-labelledby', `mastersTowerSpell-${spellId}`);

    if (typeof itemDto.spellSlug === 'string' && itemDto.spellSlug !== '') {
      card.setAttribute('data-spell-slug', itemDto.spellSlug);
    }

    const authorAlias = String(itemDto.authorAlias ?? '');
    if (authorAlias !== '') {
      appendTextElement(card, 'p', 'masters-tower__author', `Forjada por ${authorAlias}`);
    }

    // Linaje patrimonial: la obra del ermitaño se juzga sin estandarte.
    const originClanName = typeof itemDto.originClanName === 'string' && itemDto.originClanName !== ''
      ? itemDto.originClanName
      : null;
    const lineageParagraph = appendTextElement(
      card,
      'p',
      'masters-tower__lineage',
      originClanName ?? HERMIT_LINEAGE_LEGEND,
    );
    lineageParagraph.setAttribute('data-origin-clan', originClanName ?? '');

    // Elemento y escuela: con nombre ceremonial y su clave técnica para el filtro.
    const element = ELEMENT_BY_ID[String(itemDto.elementalAffinity ?? '')] ?? null;
    const elementParagraph = appendTextElement(
      card,
      'p',
      'masters-tower__element',
      element === null
        ? 'Afinidad neutra'
        : `Afinidad: ${element.name}`,
    );
    elementParagraph.setAttribute('data-element', String(itemDto.elementalAffinity ?? ''));

    const magicSchool = String(itemDto.magicSchool ?? '');
    if (magicSchool !== '') {
      const schoolParagraph = appendTextElement(card, 'p', 'masters-tower__school', `Escuela: ${magicSchool}`);
      schoolParagraph.setAttribute('data-school', magicSchool);
    }

    card.appendChild(buildSignatureMeter(itemDto));

    if (Number.isInteger(Number(itemDto.waitingDays))) {
      const waitingDays = Number(itemDto.waitingDays);
      appendTextElement(
        card,
        'p',
        'masters-tower__waiting',
        waitingDays === 0
          ? 'Recién elevada a la Torre de Moderación.'
          : `Aguarda veredicto desde hace ${waitingDays} ${waitingDays === 1 ? 'día' : 'días'}.`,
      );
    }

    // Los botones del Cónclave: firma (RF-02.2) y objeción (RF-02.5).
    const signButton = track(elementFactory('button'));
    signButton.type = 'button';
    signButton.className = 'masters-tower__sign-button button button--primary';
    signButton.setAttribute('data-action', 'signSpell');
    signButton.textContent = SIGN_BUTTON_LABEL;
    signButton.addEventListener('click', () => emitSignatureIntent(spellId));
    card.appendChild(signButton);

    const objectButton = track(elementFactory('button'));
    objectButton.type = 'button';
    objectButton.className = 'masters-tower__object-button button button--secondary';
    objectButton.setAttribute('data-action', 'objectSpell');
    objectButton.textContent = OBJECT_BUTTON_LABEL;
    objectButton.addEventListener('click', () => emitObjectionIntent(spellId));
    card.appendChild(objectButton);

    if (hasEthicalConflict) {
      // La CAUSA del veto se DECLARA (plan 3.2, nota 2): el botón nace
      // inhabilitado y la alerta porta su código y su leyenda literal.
      const vetoCode = String(itemDto.ethicalVeto?.code ?? 'unknown');
      signButton.disabled = true;
      signButton.setAttribute('aria-disabled', 'true');
      signButton.setAttribute('data-ethical-veto', vetoCode);

      const vetoAlert = appendTextElement(
        card,
        'p',
        'masters-tower__ethical-veto',
        vetoLegendFor(itemDto),
      );
      vetoAlert.setAttribute('role', 'alert');
      vetoAlert.setAttribute('data-ethical-veto-code', vetoCode);
    }

    towerRoot.appendChild(card);
  }

  /** Anuncia el gesto de firma por el bus y por callback (plan 4.1). */
  function emitSignatureIntent(spellId) {
    if (spellId === '') return;

    emitBusEvent('moderation:sign-intent', { spellId });

    if (typeof onSignatureIntent === 'function') {
      onSignatureIntent(spellId);
    }
  }

  /** Anuncia el gesto de objeción por el bus y por callback (plan 4.1). */
  function emitObjectionIntent(spellId) {
    if (spellId === '') return;

    emitBusEvent('moderation:objection-intent', { spellId });

    if (typeof onObjectionIntent === 'function') {
      onObjectionIntent(spellId);
    }
  }

  /** Pinta la Torre vacía: no es error, es una cola despejada. */
  function paintEmptyTower() {
    const empty = appendTextElement(towerRoot, 'p', 'masters-tower__empty', MASTERS_TOWER_EMPTY_LEGEND);
    empty.setAttribute('role', 'status');
    statusRegion.textContent = '';
  }

  /** Pinta el corte de corriente con su reintento ceremonial (AGENTS.md 6.1). */
  function paintErrorState() {
    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'masters-tower__error';
    errorBlock.setAttribute('role', 'alert');

    appendTextElement(errorBlock, 'p', 'masters-tower__error-message', MASTERS_TOWER_ERROR_LEGEND);

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'masters-tower__error-retry button button--secondary';
    retryButton.textContent = 'Reintentar invocación';
    retryButton.addEventListener('click', () => render());
    errorBlock.appendChild(retryButton);

    towerRoot.appendChild(errorBlock);
    statusRegion.textContent = '';
  }

  /**
   * Pinta la cola a partir de los elementos YA SERVIDOS. Filtra por identidad
   * (defensa, no juicio): la condición `experimental` la fija la consulta del
   * servidor y el panel jamás pide otro estado.
   *
   * @param {object[]} items Elementos de la cola de la Torre.
   */
  function paintItems(items) {
    const presentableItems = (Array.isArray(items) ? items : []).filter(
      (itemDto) => String(itemDto?.spellId ?? '') !== '' && String(itemDto?.spellName ?? '') !== '',
    );

    if (presentableItems.length === 0) {
      paintEmptyTower();
      return;
    }

    for (const itemDto of presentableItems) {
      paintSpellCard(itemDto);
    }

    statusRegion.textContent = `${presentableItems.length} ${presentableItems.length === 1 ? 'obra aguarda' : 'obras aguardan'} veredicto del Cónclave.`;
  }

  /**
   * Consulta la cola y pinta el panel. Guardia anti-carreras: una respuesta
   * tardía de una consulta antigua jamás pinta.
   */
  async function render() {
    if (isDestroyed) return;
    if (typeof moderationClient?.fetchDeliberationQueue !== 'function') {
      clearPainting();
      buildTowerShell();
      paintErrorState();
      return;
    }

    const currentSequence = ++fetchSequence;

    clearPainting();
    buildTowerShell();

    const result = await moderationClient.fetchDeliberationQueue({ ...activeFilters });

    // La respuesta llega tarde o el componente ya no vive: no se pinta.
    if (currentSequence !== fetchSequence || isDestroyed || towerRoot === null) return;

    if (!result?.success) {
      paintErrorState();
      return;
    }

    adoptDeliberationCanon(result.data?.canon);
    paintItems(result.data?.items ?? []);
  }

  /**
   * Repinta el panel con elementos ya conocidos (dirigido por evento,
   * plan 4.1: `moderation:signed`, `moderation:consecrated`,
   * `moderation:rejected`), sin consulta alguna.
   *
   * El canon viaja de nuevo porque un panel repintado debe seguir declarando
   * los umbrales de su autoridad.
   *
   * @param {object[]} items Elementos de la cola de la Torre.
   * @param {object} [servedCanon] Canon servido por el backend.
   */
  function setQueueItems(items, servedCanon) {
    if (isDestroyed) return;

    fetchSequence++; // invalida respuestas en vuelo.
    adoptDeliberationCanon(servedCanon ?? deliberationCanon);
    clearPainting();
    buildTowerShell();
    paintItems(items);
  }

  /**
   * Establece los filtros activos de la cola (RF-05.4) y pide el repintado.
   *
   * La acotación canónica (umbral ≤ techo, paginación positiva) la dicta el
   * backend: el panel cursa lo que recibe y el santuario responde 400 a lo
   * imposible.
   *
   * @param {{element?: string, school?: string, minSignatures?: number}} filters
   * @param {boolean} [refetch] Si re-consulta la cola (por defecto, sí).
   */
  function setFilters(filters, refetch = true) {
    if (isDestroyed) return;

    activeFilters = {
      element: typeof filters?.element === 'string' ? filters.element : activeFilters.element,
      school: typeof filters?.school === 'string' ? filters.school : activeFilters.school,
      minSignatures: Number.isInteger(Number(filters?.minSignatures))
        ? Number(filters.minSignatures)
        : activeFilters.minSignatures,
    };

    if (refetch) {
      render();
    }
  }

  /** Retira el panel del montaje y libera sus nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    fetchSequence++; // invalida respuestas en vuelo.
    clearPainting();
  }

  return { render, setQueueItems, setFilters, destroy };
}

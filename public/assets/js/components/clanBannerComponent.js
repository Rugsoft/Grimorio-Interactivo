/**
 * clanBannerComponent.js — Blasón del Clan Regente en el Gran Portal.
 *
 * Tarea 5.2 (TASKS-07). Renderiza en la cabecera del Gran Portal (SPEC-01) el
 * escudo heráldico del Clan Soberano de la semana en curso, con su lema
 * solemne, su linaje elemental rector y la corona dorada ceremonial
 * (RF-04.4, RF-02.2, RNF-03).
 *
 * Contrato de datos: `GET /api/v1/dominion/leaderboard` vía dominionClient
 * (Tarea 5.1), de donde se toma `data.currentRegentClan` — un ClanDto— y
 * `GET /api/v1/lineages`, cuyo catálogo aporta el nombre ceremonial del
 * linaje, su elemento rector, su glifo rúnico y el color de su estandarte.
 *
 * Nota de lore (RF-04.4): el corte dominical REINICIA a cero el contador
 * semanal de todas las casas, incluida la recién coronada. Por eso el blasón
 * jamás exhibe «PDA de la semana» como cifra del reinado: mostraría cero
 * mientras el clan reina. La gloria alcanzada queda inmortalizada en el acta
 * del Salón, y aquí se proclama el reinado.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; textContent puro y `innerHTML`
 *     PROHIBIDO (AGENTS.md 6.1); cero librerías ni plantillas.
 *   - Artículo II: el componente JAMÁS calcula PDA ni ordena podios: solo
 *     contempla lo que el Salón proclama.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): sin catálogo de linajes, el blasón
 *     sigue en pie con la clave técnica del linaje; sin regente, exhibe el
 *     trono vacío; sin corriente de maná, un error temático con reintento.
 */

import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';
import { createRuneSeal, RUNE_SEAL_STATES } from './runeSealComponent.js';

/** Leyenda del trono vacío: aún nadie ha contendido por el Dominio. */
export const CLAN_BANNER_EMPTY_LEGEND =
  'El trono aguarda: ningún linaje ha contendido aún por el Dominio en esta semana.';

/** Leyenda del corte de corriente (AGENTS.md 6.1: error controlado). */
export const CLAN_BANNER_ERROR_LEGEND =
  'La corriente de maná se ha interrumpido: el blasón del Clan Regente no responde.';

/** Índice de elementos canónicos por clave técnica (espejo del Códice). */
const ELEMENT_BY_ID = Object.freeze(
  Object.fromEntries(ELEMENTAL_MATRIX_ELEMENTS.map((element) => [element.id, element])),
);

/**
 * Crea el componente del blasón del Clan Regente.
 *
 * @param {HTMLElement} mountRoot Contenedor de la cabecera del Gran Portal.
 * @param {Object} options
 * @param {Object} options.dominionClient Cliente HTTP (Tarea 5.1; necesita
 *        fetchLeaderboard y, opcionalmente, fetchLineages).
 * @param {(clanId: string) => void} [options.onRegentSelect] Selección del
 *        blasón (el orquestador abrirá la ficha del linaje reinante).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión del sello
 *        forjado (arneses sin navegador).
 * @returns {Object} API: { render, setRegent, destroy }.
 */
export function createClanBannerComponent(mountRoot, options = {}) {
  const {
    dominionClient,
    onRegentSelect,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Nodos vivos del componente, para limpieza determinista. */
  const mountedNodes = [];

  /** Catálogo de linajes por clave canónica (se consulta una sola vez). */
  let lineageCatalog = new Map();

  /** Bandera: el catálogo ya se intentó consultar (aunque fallara). */
  let lineageCatalogRequested = false;

  /** Guardia anti-carreras: solo la petición más reciente pinta el blasón. */
  let fetchSequence = 0;

  /** El componente quedó destruido: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Referencias vivas del montaje actual. */
  let bannerRoot = null;
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
    bannerRoot = null;
    statusRegion = null;
  }

  /** Consulta el catálogo de linajes una sola vez (best-effort). */
  async function ensureLineageCatalog() {
    if (lineageCatalogRequested) return;
    lineageCatalogRequested = true;

    if (typeof dominionClient?.fetchLineages !== 'function') return;

    const result = await dominionClient.fetchLineages();
    if (!result?.success || !Array.isArray(result.data)) return;

    // Degradación elegante: sin catálogo, el blasón usa la clave técnica.
    lineageCatalog = new Map(result.data.map((lineage) => [lineage.id, lineage]));
  }

  /**
   * Monta el armazón ceremonial del blasón (región etiquetada + proclamación
   * anunciada por lector de pantalla).
   *
   * @returns {HTMLElement} El contenedor donde pintar el estado.
   */
  function buildBannerShell() {
    bannerRoot = track(elementFactory('aside'));
    bannerRoot.className = 'clan-banner';
    bannerRoot.setAttribute('id', 'clanBanner');
    // Landmark con nombre accesible: la región se anuncia correctamente.
    bannerRoot.setAttribute('role', 'region');
    bannerRoot.setAttribute('aria-labelledby', 'clanBannerTitle');

    const title = appendTextElement(bannerRoot, 'h2', 'clan-banner__title', 'Clan Regente del Santuario');
    title.setAttribute('id', 'clanBannerTitle');

    statusRegion = appendTextElement(
      bannerRoot,
      'p',
      'clan-banner__proclamation',
      '',
    );
    // Proclamación anunciada por cortesía cuando cambia el reinante (plan 4.1).
    statusRegion.setAttribute('role', 'status');
    statusRegion.setAttribute('aria-live', 'polite');
    statusRegion.setAttribute('aria-atomic', 'true');

    mountRoot.appendChild(bannerRoot);

    return bannerRoot;
  }

  /** Resuelve el nombre ceremonial y el elemento del linaje rector. */
  function describeLineage(lineageType) {
    const lineage = lineageCatalog.get(lineageType) ?? null;
    const element = lineage ? ELEMENT_BY_ID[lineage.rulingElement] ?? null : null;

    return {
      ceremonialName: lineage?.name ?? lineageType,
      rulingElement: lineage?.rulingElement ?? null,
      elementName: element?.name ?? null,
      elementGlyph: element?.glyph ?? null,
      bannerColor: lineage?.bannerColor ?? null,
      heraldicFrame: lineage?.heraldicFrame ?? null,
    };
  }

  /**
   * Pinta el blasón del linaje reinante (RF-04.4).
   *
   * @param {object} regentClanDto ClanDto del Salón: name, motto, coatOfArms,
   *        lineageType, patriarchId, memberCount.
   */
  function paintRegent(regentClanDto) {
    const clanName = String(regentClanDto.name ?? '');
    const motto = String(regentClanDto.motto ?? '');
    const coatOfArms = String(regentClanDto.coatOfArms ?? '');
    const lineage = describeLineage(String(regentClanDto.lineageType ?? ''));

    const article = elementFactory('article');
    article.className = 'clan-banner__regent';
    article.setAttribute('data-clan-id', String(regentClanDto.id ?? ''));
    article.setAttribute('data-regent', 'true');
    // Descripción ceremonial completa en el nombre accesible del blasón.
    article.setAttribute(
      'aria-label',
      `Clan Regente del Santuario: ${clanName}, del ${lineage.ceremonialName}. Porta la corona dorada del Dominio.`,
    );
    bannerRoot.appendChild(article);
    track(article);

    // Sello Rúnico forjado (SPEC-02 RF-07, SPEC-07 RF-02.4): el blasón se
    // forja, jamás se imprime. El reinado se declara con oro vivo y cera.
    const shield = track(createRuneSeal({
      houseName: clanName,
      coatOfArms,
      rulingElement: lineage.rulingElement ?? '',
      lineageName: lineage.ceremonialName,
      state: RUNE_SEAL_STATES.REGENT,
      document: documentRef,
    }));
    shield.classList.add('clan-banner__shield');
    if (lineage.bannerColor !== null) {
      // El color del estandarte viaja como custom property: la paleta vive en el sistema.
      shield.setAttribute('style', `--banner-tint: ${lineage.bannerColor}`);
    }
    article.appendChild(shield);

    // Corona dorada ceremonial: es CONTENIDO (el reinado), no adorno.
    const crown = elementFactory('span');
    crown.className = 'clan-banner__crown clan-banner__crown--golden';
    crown.setAttribute('data-crown', 'golden');
    crown.setAttribute('role', 'img');
    crown.setAttribute('aria-label', 'Corona dorada del Clan Regente');
    crown.textContent = '♛';
    article.appendChild(crown);
    track(crown);

    const nameHeading = appendTextElement(article, 'h3', 'clan-banner__clan-name', clanName);
    nameHeading.setAttribute('id', 'clanBannerRegentName');

    appendTextElement(article, 'p', 'clan-banner__motto', `«${motto}»`);

    // Linaje elemental rector: nombre ceremonial y elemento (RF-02.2).
    const lineageLegend = lineage.elementName === null
      ? lineage.ceremonialName
      : `${lineage.ceremonialName} · elemento rector: ${lineage.elementName}`;
    const lineageParagraph = appendTextElement(article, 'p', 'clan-banner__lineage', lineageLegend);
    lineageParagraph.setAttribute('data-lineage', String(regentClanDto.lineageType ?? ''));

    if (lineage.elementGlyph !== null) {
      const glyph = appendTextElement(article, 'span', 'clan-banner__element-glyph', lineage.elementGlyph);
      // Glifo ornamental: su significado ya viaja en el párrafo del linaje.
      glyph.setAttribute('aria-hidden', 'true');
    }

    appendTextElement(
      article,
      'p',
      'clan-banner__reign',
      'Porta la corona dorada del Dominio durante los siete días de su mandato.',
    );

    // Blasón accionable solo si el orquestador declaró interés en él.
    if (typeof onRegentSelect === 'function') {
      article.setAttribute('data-action', 'selectRegent');
      article.addEventListener('click', () => onRegentSelect(String(regentClanDto.id ?? '')));
      article.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault?.();
          onRegentSelect(String(regentClanDto.id ?? ''));
        }
      });
    }

    statusRegion.textContent = `«${clanName}» reina el santuario como Clan Regente de la semana en curso.`;
  }

  /** Pinta el trono vacío: no es un error, es un santuario sin contienda. */
  function paintEmptyThrone() {
    const empty = appendTextElement(bannerRoot, 'p', 'clan-banner__empty', CLAN_BANNER_EMPTY_LEGEND);
    empty.setAttribute('role', 'status');
    statusRegion.textContent = '';
  }

  /** Pinta el corte de corriente con su reintento ceremonial (AGENTS.md 6.1). */
  function paintErrorState() {
    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'clan-banner__error';
    // role="alert" porque el fallo interrumpe la contemplación del blasón.
    errorBlock.setAttribute('role', 'alert');

    appendTextElement(errorBlock, 'p', 'clan-banner__error-message', CLAN_BANNER_ERROR_LEGEND);

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'clan-banner__error-retry button button--secondary';
    retryButton.textContent = 'Reintentar invocación';
    retryButton.addEventListener('click', () => render());
    errorBlock.appendChild(retryButton);

    bannerRoot.appendChild(errorBlock);
    statusRegion.textContent = '';
  }

  /**
   * Consulta el Salón y pinta el blasón. Guardia anti-carreras: una respuesta
   * tardía de una consulta antigua jamás pinta.
   */
  async function render() {
    if (isDestroyed) return;
    if (typeof dominionClient?.fetchLeaderboard !== 'function') {
      clearPainting();
      buildBannerShell();
      paintErrorState();
      return;
    }

    const currentSequence = ++fetchSequence;

    clearPainting();
    buildBannerShell();

    await ensureLineageCatalog();
    const result = await dominionClient.fetchLeaderboard();

    // La respuesta llega tarde o el componente ya no vive: no se pinta.
    if (currentSequence !== fetchSequence || isDestroyed || bannerRoot === null) return;

    if (!result?.success) {
      paintErrorState();
      return;
    }

    const regentClanDto = result.data?.currentRegentClan ?? null;
    if (regentClanDto === null || regentClanDto === undefined) {
      paintEmptyThrone();
      return;
    }

    paintRegent(regentClanDto);
  }

  /**
   * Pinta el blasón a partir de un ClanDto ya conocido (proclamación
   * dirigida por evento, plan 4.1: `dominion:week-closed`).
   *
   * @param {object|null} regentClanDto ClanDto del nuevo reinante, o null
   *        para exhibir el trono vacío.
   */
  function setRegent(regentClanDto) {
    if (isDestroyed) return;

    fetchSequence++; // invalida respuestas en vuelo.
    clearPainting();
    buildBannerShell();

    if (regentClanDto === null || regentClanDto === undefined) {
      paintEmptyThrone();
      return;
    }

    paintRegent(regentClanDto);
  }

  /** Retira el blasón del montaje y libera sus nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    fetchSequence++; // invalida respuestas en vuelo.
    clearPainting();
  }

  return { render, setRegent, destroy };
}

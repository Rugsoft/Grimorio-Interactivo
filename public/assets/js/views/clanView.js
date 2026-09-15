/**
 * clanView.js — Ficha de Detalle de una Hermandad y su Legado Ancestral.
 *
 * Tarea 6.4 (TASKS-07). Retrata una casa del santuario y su patrimonio:
 *
 *   RF-01.2: la fundación exige Nombre Canónico, lema, blasón y uno de los
 *     ocho linajes; la ficha los exhibe y ofrece el gesto de fundar cuando el
 *     visitante aún no milita en casa alguna.
 *   RF-01.4: el censo se muestra como ocupación X/30 y la afiliación se veta
 *     cuando la casa ha colmado su plenitud.
 *   RF-01.5: el gesto de ingreso se rotula según el régimen vigente: «Unirse»
 *     en régimen abierto y «Postular» bajo petición.
 *   RF-05.1: los conjuros ratificados son patrimonio inviolable del clan bajo
 *     cuyo estandarte fueron concebidos; la ficha los muestra con el crédito
 *     de su autor original aunque este ya no milite en la casa.
 *   RF-05.3: si la casa yace disuelta (`archived`), la ficha la sella como
 *     «Herencia Ancestral» y preserva su catálogo de conjuros.
 *   RF-05.4: una casa disuelta no admite adeptos ni gobierno.
 *
 * Contratos consultados:
 *   - Endpoint 3  · `GET /api/v1/clans/{id}`        → ficha, Patriarca y censo.
 *   - Endpoint 13 · `GET /api/v1/clans/{id}/spells` → legado sellado.
 *   - Endpoint 10 · `GET /api/v1/lineages`          → nombre ceremonial del
 *     linaje rector, su elemento y su marco heráldico (best-effort).
 *   - Endpoints 5 y 7 (postular / renunciar) se cursan desde esta misma vista,
 *     y el panel del Patriarca (Tarea 6.2) se monta cuando el vinculado ciñe
 *     la corona.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `innerHTML` PROHIBIDO
 *     (AGENTS.md 6.1) — todo dato de usuario viaja por `textContent`.
 *   - Artículo II: la vista JAMÁS decide el canon: no computa PDA, no ordena
 *     censos ni recalcula cupos; solo muestra lo que el santuario responde y
 *     cursa las órdenes cuyo resultado jamás interpreta por su cuenta.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *   - Degradación elegante (AGENTS.md 8): sin catálogo de linajes la ficha usa
 *     la clave técnica; sin legado, una leyenda de vacío; sin corriente, error
 *     temático con reintento.
 */

import { createClanManagementComponent } from '../components/clanManagementComponent.js';
import { createRuneSeal, RUNE_SEAL_STATES } from '../components/runeSealComponent.js';
import { ELEMENTAL_MATRIX_ELEMENTS } from '../utils/comboResolver.js';
import { ceremonialLegendFor } from '../api/clanClient.js';

/** Título ceremonial de la ficha. */
export const CLAN_VIEW_TITLE = 'Ficha de la Hermandad';

/** Sello honorífico de las casas disueltas (RF-05.3). */
export const HERITAGE_ANCESTRAL_SEAL = 'Herencia Ancestral';

/** Leyenda del sello ancestral. */
export const HERITAGE_ANCESTRAL_LEGEND =
  'Casa disuelta: su Nombre Canónico queda inmortalizado y reservado a perpetuidad, y sus conjuros ratificados se preservan como Herencia Ancestral (Art. III).';

/** Título del catálogo de conjuros sellados. */
export const LEGACY_TITLE = 'Conjuros Sellados';
export const LEGACY_TITLE_ANCESTRAL = 'Herencia Ancestral de la Casa';

/** Leyenda del legado vacío. */
export const LEGACY_EMPTY_LEGEND =
  'Aún ningún conjuro ha sido ratificado bajo este estandarte: el legado aguarda su primera obra.';

/** Rúbrica del crédito perpetuo del autor (RF-05.1). */
export const LEGACY_AUTHOR_PREFIX = 'Forjado por';

/** Sello de los Pergaminos Primordiales de génesis. */
export const GENESIS_SEAL = 'Pergamino Primordial';

/** Leyendas de los estados de la ficha. */
export const CLAN_VIEW_LOADING_LEGEND = 'Invocando los anales de la hermandad…';
export const CLAN_VIEW_ERROR_LEGEND =
  'La corriente de maná se ha interrumpido: la ficha de la hermandad no responde.';
export const CLAN_VIEW_RETRY_LABEL = 'Reintentar invocación';

/** Etiquetas canónicas de los roles de la casa (RF-01.3). */
export const ROLE_LABELS = Object.freeze({
  patriarch: 'Patriarca / Matriarca',
  adept: 'Adepto del Linaje',
});

/** Etiquetas canónicas de los regímenes de admisión (RF-01.5). */
export const ADMISSION_LABELS = Object.freeze({
  open: 'Régimen Abierto',
  byApplication: 'Bajo Petición',
});

/** Leyendas de los vetos y de los gestos de afiliación. */
export const CLAN_VIEW_LEGENDS = Object.freeze({
  archived: 'La hermandad yace disuelta como Herencia Ancestral: su estandarte ya no admite adeptos.',
  quotaFull:
    'La hermandad ha alcanzado su plenitud de treinta hermanos: no es posible admitir nuevos adeptos en este ciclo.',
  convalescence:
    'Tu esencia mágica aún se encuentra en convalecencia tras disolver tu juramento anterior.',
  alreadyAffiliated: 'La lealtad mágica es indivisible: ya militas bajo otro estandarte.',
  crownMustTransfer: 'Antes de partir debes ceder la corona a otro adepto de la casa.',
  joinOpen: 'Unirse a la hermandad',
  joinByApplication: 'Postular al ingreso',
  leave: 'Renunciar a la hermandad',
  visitor: 'Cruzar el Umbral para postular',
  member: 'Ya militas bajo este estandarte.',
  pendingApplication: 'Tu postulación obra en la cola del Patriarca: aguarda su veredicto.',
  founder: 'Fundar una hermandad propia',
});

/** Índice de elementos canónicos por clave técnica (espejo del Códice). */
const ELEMENT_BY_ID = Object.freeze(
  Object.fromEntries(ELEMENTAL_MATRIX_ELEMENTS.map((element) => [element.id, element])),
);

/** Etiqueta ordinal de la carrera arcana. */
const CIRCLE_LABEL = 'Círculo';

/** Formatea un instante ISO en fecha castellana legible, o cadena vacía. */
function formatMoment(isoStamp) {
  if (typeof isoStamp !== 'string' || isoStamp.trim() === '') return '';

  const moment = new Date(isoStamp);
  if (Number.isNaN(moment.getTime())) return '';

  return moment.toISOString().slice(0, 10);
}

/**
 * Crea la vista de detalle de una hermandad.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.clanClient Cliente del gobierno (Tarea 5.1); necesita
 *        `fetchClan`, `fetchClanSpells`, `applyToClan` y `leaveClan`.
 * @param {string} options.clanId Identificador de la casa (cln_*).
 * @param {Object} [options.store] Almacén reactivo: la vista lee de él la
 *        sesión vigente (`currentUser`, `userClan`, `isAuthenticated`).
 * @param {Object} [options.dominionClient] Cliente del Salón, solo para el
 *        catálogo ceremonial de los 8 linajes (best-effort).
 * @param {string|null} [options.convalescenceExpiresAt] Marca ISO de la
 *        convalecencia del vinculado; si falta, se lee del sobre de sesión.
 * @param {(action: string, target: string|null) => void} [options.onReservedAction]
 *        Acción reservada para el visitante anónimo («Cruzar el Umbral»).
 * @param {(slug: string) => void} [options.onSpellSelect] Selección de una obra
 *        del legado (el orquestador abre su ficha).
 * @param {(envelope: Object|null) => void} [options.onMembershipChanged]
 *        El vínculo del vinculado mudó (ingreso o renuncia consumados).
 * @param {(legend: string) => void} [options.onError] Fallo de una acción.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión del sello
 *        forjado de la casa (arneses sin navegador).
 * @returns {Object} API: { render, destroy, retry, setClanClient, getClan }.
 */
export function createClanView(mountRoot, options = {}) {
  const {
    clanClient = null,
    clanId = '',
    store = null,
    dominionClient = null,
    convalescenceExpiresAt = null,
    onReservedAction = null,
    onSpellSelect = null,
    onMembershipChanged = null,
    onError = null,
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Cliente vivo (conmutable en reintentos tras un fallo). */
  let activeClanClient = clanClient;

  /** Nodos vivos de la vista, para limpieza determinista. */
  const mountedNodes = [];

  /** Guardia anti-carreras: solo la consulta más reciente pinta la ficha. */
  let fetchSequence = 0;

  /** El vinculado cuya acción está en vuelo: dos gestos no cursan dos órdenes. */
  let actionInFlight = false;

  /** La vista quedó desmontada: ninguna respuesta tardía pinta nada. */
  let isDestroyed = false;

  /** Catálogo ceremonial de los 8 linajes (se consulta una sola vez). */
  let lineageCatalog = new Map();
  let lineageCatalogRequested = false;

  /** Últimos sobres recibidos del santuario. */
  let clanDetail = null;
  let legacy = null;

  /** Referencias vivas del montaje. */
  let viewRoot = null;
  let alertRegion = null;
  let managementPanel = null;

  /**
   * Proclama vigente de la ficha (fallo o consumación). Sobrevive al repintado:
   * una orden consumada obliga a recargar la ficha, y la leyenda no debe
   * desvanecerse con el repintado.
   */
  let proclamation = '';

  /** Registra un nodo como hijo de la vista para su ciclo de vida. */
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

  /** Vacía los hijos de un nodo (real o simulado). */
  function clearChildren(node) {
    if (node === null || node === undefined) return;
    if (typeof node.replaceChildren === 'function') {
      node.replaceChildren();
      return;
    }
    for (const child of [...(node.children ?? [])]) {
      child.remove?.();
    }
  }

  /** Estado de sesión vigente (o el del visitante anónimo). */
  function viewerState() {
    const session = store?.getState?.() ?? {};
    const user = session?.currentUser ?? null;

    return {
      isAuthenticated: session?.isAuthenticated === true,
      viewer: user,
      viewerId: typeof user?.id === 'string' ? user.id : '',
      viewerClanId: typeof session?.userClan?.id === 'string' ? session.userClan.id : '',
      isConvalescent: hasActiveConvalescence(
        convalescenceExpiresAt ?? user?.convalescenceExpiresAt ?? null,
      ),
    };
  }

  /** ¿Sigue viva la convalecencia marcada por el instante ISO dado? */
  function hasActiveConvalescence(isoStamp) {
    if (typeof isoStamp !== 'string' || isoStamp.trim() === '') return false;

    const expiry = new Date(isoStamp);
    if (Number.isNaN(expiry.getTime())) return false;

    return expiry.getTime() > Date.now();
  }

  /** Consulta el catálogo ceremonial de los 8 linajes una sola vez. */
  async function ensureLineageCatalog() {
    if (lineageCatalogRequested) return;
    lineageCatalogRequested = true;

    if (typeof dominionClient?.fetchLineages !== 'function') return;

    const result = await dominionClient.fetchLineages();
    if (!result?.success || !Array.isArray(result.data)) return;

    lineageCatalog = new Map(result.data.map((lineage) => [lineage.id, lineage]));
  }

  /** Datos ceremoniales del linaje rector de una casa. */
  function describeLineage(lineageType) {
    const lineage = lineageCatalog.get(lineageType) ?? null;
    const element = lineage ? ELEMENT_BY_ID[lineage.rulingElement] ?? null : null;

    return {
      ceremonialName: lineage?.name ?? (lineageType === '' ? 'Linaje no declarado' : lineageType),
      rulingElement: lineage?.rulingElement ?? null,
      elementName: element?.name ?? null,
      glyph: lineage?.glyph ?? null,
      bannerColor: lineage?.bannerColor ?? null,
      heraldicFrame: lineage?.heraldicFrame ?? null,
    };
  }

  /* =====================================================================
     Acciones de afiliación (Endpoints 5 y 7)
     ===================================================================== */

  /** Proclama un fallo de acción sin quebrar la ficha (AGENTS.md 6.1). */
  function reportActionFailure(envelope) {
    const legend = ceremonialLegendFor(envelope);
    proclaim(legend);
    if (typeof onError === 'function') onError(envelope ?? null, legend);
  }

  /** Proclama una consumación (o un fallo) en la región de estado de la ficha. */
  function proclaim(legend) {
    proclamation = String(legend ?? '');
    if (alertRegion !== null) alertRegion.textContent = proclamation;
  }

  /** Cursa una orden de afiliación y repinta con el veredicto del santuario. */
  async function runAffiliation(action) {
    if (isDestroyed || actionInFlight) return null;
    if (activeClanClient === null) {
      reportActionFailure(null);
      return null;
    }

    actionInFlight = true;

    try {
      const envelope = action === 'leave'
        ? await activeClanClient.leaveClan(clanId)
        : await activeClanClient.applyToClan(clanId);

      if (envelope?.success !== true) {
        reportActionFailure(envelope);
        return envelope ?? null;
      }

      proclaim(
        action === 'leave'
          ? 'Tu renuncia quedó inscrita en los anales: se abre tu Convalecencia Arcana de catorce días.'
          : 'Tu postulación quedó sellada: el santuario ha tomado nota de tu intención.',
      );
      if (typeof onMembershipChanged === 'function') onMembershipChanged(envelope);

      await load();
      return envelope;
    } catch {
      // El cliente jamás lanza por contrato; esta red es defensiva.
      reportActionFailure(null);
      return null;
    } finally {
      actionInFlight = false;
    }
  }

  /* =====================================================================
     Pintado
     ===================================================================== */

  /**
   * Veredicto de la afiliación para el vinculado en curso.
   *
   * Artículo II: la ficha NO decide por su cuenta — este veredicto solo
   * gobierna la botonera; quien dicta el canon definitivo es el santuario,
   * cuyos códigos canónicos (409 CLAN_QUOTA_EXCEEDED, 403 CONVALESCENCE_ACTIVE)
   * se traducen aquí si llegaran a manifestarse.
   *
   * @returns {{ label: string, action: string|null, enabled: boolean, legend: string }}
   */
  function judgeAffiliation(clan, state) {
    const isArchived = clan.status === 'archived';

    if (isArchived) {
      return { label: CLAN_VIEW_LEGENDS.joinOpen, action: null, enabled: false, legend: CLAN_VIEW_LEGENDS.archived };
    }

    if (!state.isAuthenticated) {
      return {
        label: CLAN_VIEW_LEGENDS.visitor,
        action: 'reserved',
        enabled: true,
        legend: 'Conságrate o vincula tu identidad para postular al ingreso de una hermandad.',
      };
    }

    if (state.viewerClanId === clan.id) {
      const isCrowned = state.viewerId !== '' && state.viewerId === String(clan.patriarchId ?? '');
      if (isCrowned) {
        return {
          label: CLAN_VIEW_LEGENDS.leave,
          action: null,
          enabled: false,
          legend: CLAN_VIEW_LEGENDS.crownMustTransfer,
        };
      }

      return {
        label: CLAN_VIEW_LEGENDS.leave,
        action: 'leave',
        enabled: true,
        legend: CLAN_VIEW_LEGENDS.member,
      };
    }

    if (state.viewerClanId !== '') {
      return {
        label: CLAN_VIEW_LEGENDS.joinOpen,
        action: null,
        enabled: false,
        legend: CLAN_VIEW_LEGENDS.alreadyAffiliated,
      };
    }

    if (state.isConvalescent) {
      return {
        label: CLAN_VIEW_LEGENDS.joinOpen,
        action: null,
        enabled: false,
        legend: CLAN_VIEW_LEGENDS.convalescence,
      };
    }

    const memberLimit = Number(clan.memberLimit ?? 30);
    if (Number(clan.memberCount ?? 0) >= memberLimit) {
      return {
        label: CLAN_VIEW_LEGENDS.joinOpen,
        action: null,
        enabled: false,
        legend: CLAN_VIEW_LEGENDS.quotaFull,
      };
    }

    const isOpen = clan.admissionMode === 'open';

    return {
      label: isOpen ? CLAN_VIEW_LEGENDS.joinOpen : CLAN_VIEW_LEGENDS.joinByApplication,
      action: 'apply',
      enabled: true,
      legend: isOpen
        ? 'El régimen abierto admite tu ingreso de inmediato mientras existan vacantes.'
        : 'Bajo Petición: tu solicitud quedará en la cola de deliberación del Patriarca.',
    };
  }

  /** Pinta el blasón, el lema y el linaje rector de la casa. */
  function paintHeraldry(section, clan) {
    const lineage = describeLineage(String(clan.lineageType ?? ''));

    const banner = track(elementFactory('div'));
    banner.className = 'clan-view__banner';
    if (lineage.bannerColor !== null) {
      // El tinte del linaje viaja como Custom Property: la paleta vive en tokens.
      banner.setAttribute('style', `--clan-tint: ${lineage.bannerColor}`);
    }
    if (lineage.heraldicFrame !== null) {
      banner.setAttribute('data-heraldic-frame', String(lineage.heraldicFrame));
    }
    section.appendChild(banner);

    // Sello Rúnico forjado (SPEC-02 RF-07, SPEC-07 RF-02.4): la casa disuelta
    // viste bronce y anillo roto; la viva, oro antiguo.
    const shield = track(createRuneSeal({
      houseName: String(clan.name ?? ''),
      coatOfArms: String(clan.coatOfArms ?? ''),
      rulingElement: lineage.rulingElement ?? '',
      lineageName: lineage.ceremonialName,
      state: clan.status === 'archived' ? RUNE_SEAL_STATES.ARCHIVED : RUNE_SEAL_STATES.ACTIVE,
      document: documentRef,
    }));
    shield.classList.add('clan-view__shield');
    banner.appendChild(shield);

    const identity = track(elementFactory('div'));
    identity.className = 'clan-view__identity';
    banner.appendChild(identity);

    const title = appendTextElement(identity, 'h1', 'clan-view__name', String(clan.name ?? ''));
    title.setAttribute('id', 'clanViewTitle');

    appendTextElement(identity, 'p', 'clan-view__motto', `«${String(clan.motto ?? '')}»`);

    const lineageLegend = lineage.elementName === null
      ? lineage.ceremonialName
      : `${lineage.ceremonialName} · elemento rector: ${lineage.elementName}`;
    const lineageNode = appendTextElement(identity, 'p', 'clan-view__lineage', lineageLegend);
    lineageNode.setAttribute('data-lineage', String(clan.lineageType ?? ''));
  }

  /** Pinta el sello de Herencia Ancestral (RF-05.3, RF-05.4). */
  function paintHeritageSeal(section) {
    const seal = appendTextElement(section, 'p', 'clan-view__heritage-seal', HERITAGE_ANCESTRAL_SEAL);
    seal.setAttribute('role', 'status');
    seal.setAttribute('data-heritage', 'ancestral');
    appendTextElement(section, 'p', 'clan-view__heritage-legend', HERITAGE_ANCESTRAL_LEGEND);
  }

  /** Pinta la ocupación X/30, el régimen y los contadores de gloria. */
  function paintFacts(section, clan) {
    const facts = track(elementFactory('div'));
    facts.className = 'clan-view__facts';
    section.appendChild(facts);

    const memberCount = Number(clan.memberCount ?? 0);
    const memberLimit = Number(clan.memberLimit ?? 30);

    const occupancy = track(elementFactory('div'));
    occupancy.className = 'clan-view__occupancy';
    occupancy.setAttribute('role', 'progressbar');
    occupancy.setAttribute('aria-valuemin', '0');
    occupancy.setAttribute('aria-valuemax', String(memberLimit));
    occupancy.setAttribute('aria-valuenow', String(memberCount));
    occupancy.setAttribute(
      'aria-valuetext',
      `${memberCount} / ${memberLimit} adeptos`,
    );
    facts.appendChild(occupancy);
    appendTextElement(occupancy, 'span', 'clan-view__occupancy-figure', `${memberCount} / ${memberLimit} adeptos`);

    appendTextElement(
      facts,
      'p',
      'clan-view__admission',
      `${ADMISSION_LABELS[String(clan.admissionMode ?? '')] ?? String(clan.admissionMode ?? '')}`,
    );
    appendTextElement(
      facts,
      'p',
      'clan-view__dominion',
      `Dominio de la semana: ${Number(clan.weeklyPoints ?? 0)} PDA · Gloria perpetua: ${Number(clan.historicalPoints ?? 0)}`,
    );
  }

  /** Pinta al Patriarca en funciones (o su ausencia). */
  function paintPatriarch(section, detail) {
    const patriarch = detail?.patriarch ?? null;

    const block = track(elementFactory('div'));
    block.className = 'clan-view__patriarch';
    section.appendChild(block);

    appendTextElement(block, 'h2', 'clan-view__section-title', 'Corona de la Casa');

    if (patriarch === null) {
      appendTextElement(
        block,
        'p',
        'clan-view__patriarch-absent',
        'La casa quedó acéfala: el santuario proveerá su sucesión (RF-01.9).',
      );
      return;
    }

    appendTextElement(block, 'p', 'clan-view__patriarch-name', String(patriarch.userAlias ?? ''));
    appendTextElement(block, 'p', 'clan-view__patriarch-role', ROLE_LABELS.patriarch);
  }

  /** Pinta el censo nominal de adeptos activos. */
  function paintMembers(section, detail) {
    const members = Array.isArray(detail?.members) ? detail.members : [];

    const block = track(elementFactory('div'));
    block.className = 'clan-view__members';
    section.appendChild(block);

    appendTextElement(block, 'h2', 'clan-view__section-title', 'Censo de Adeptos');

    if (members.length === 0) {
      appendTextElement(block, 'p', 'clan-view__members-empty', 'El censo aguarda: aún ningún adepto milita en esta casa.');
      return;
    }

    const roster = track(elementFactory('ul'));
    roster.className = 'clan-view__roster';
    block.appendChild(roster);

    for (const member of members) {
      const item = track(elementFactory('li'));
      item.className = 'clan-view__roster-item';
      item.setAttribute('data-user-id', String(member?.userId ?? ''));
      roster.appendChild(item);

      appendTextElement(item, 'span', 'clan-view__roster-name', String(member?.userAlias ?? ''));
      appendTextElement(
        item,
        'span',
        'clan-view__roster-role',
        ROLE_LABELS[String(member?.role ?? '')] ?? String(member?.role ?? ''),
      );
      const joinedAt = formatMoment(member?.joinedAt);
      if (joinedAt !== '') {
        appendTextElement(item, 'span', 'clan-view__roster-joined', `Juramento: ${joinedAt}`);
      }
    }
  }

  /** Pinta el gesto de afiliación con su veredicto y su leyenda. */
  function paintAffiliation(section, clan, state) {
    const verdict = judgeAffiliation(clan, state);

    const block = track(elementFactory('div'));
    block.className = 'clan-view__affiliation';
    section.appendChild(block);

    const button = track(elementFactory('button'));
    button.type = 'button';
    button.className = verdict.enabled
      ? 'clan-view__action button button--primary'
      : 'clan-view__action clan-view__action--vetoed button button--secondary';
    button.textContent = verdict.label;
    button.setAttribute('data-affiliation-action', verdict.action ?? 'none');
    if (!verdict.enabled) {
      // El navegador no entrega gestos a un control deshabilitado: el veto
      // es real, no una simple advertencia visual (RF-01.4, RF-01.6).
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
    }
    button.addEventListener('click', () => {
      if (verdict.action === 'reserved' && typeof onReservedAction === 'function') {
        onReservedAction('joinClan', String(clan.id ?? ''));
        return;
      }
      if (verdict.action === 'apply' || verdict.action === 'leave') {
        void runAffiliation(verdict.action);
      }
    });
    block.appendChild(button);

    appendTextElement(block, 'p', 'clan-view__affiliation-legend', verdict.legend);
  }

  /** Pinta el panel de gobierno cuando el vinculado ciñe la corona (Tarea 6.2). */
  function paintGovernment(section, clan, detail, state) {
    const isCrowned = state.viewerId !== '' && state.viewerId === String(clan.patriarchId ?? '');
    const isGovernable = clan.status !== 'archived';

    if (!isCrowned || !isGovernable || activeClanClient === null) {
      managementPanel = null;
      return;
    }

    const block = track(elementFactory('div'));
    block.className = 'clan-view__government';
    section.appendChild(block);

    managementPanel = createClanManagementComponent(block, {
      clanClient: activeClanClient,
      viewer: state.viewer,
      onError: (envelope, legend) => {
        reportActionFailure(envelope ?? null);
        if (typeof legend === 'string' && legend !== '') proclaim(legend);
      },
      onClanMutated: () => {
        void load();
      },
      elementFactory,
    });
    managementPanel.setContext({
      clan,
      patriarch: detail?.patriarch ?? null,
      members: Array.isArray(detail?.members) ? detail.members : [],
      applications: Array.isArray(detail?.applications) ? detail.applications : [],
      viewer: state.viewer,
    });
    managementPanel.render();
  }

  /** Pinta una pieza del legado sellado (RF-05.1). */
  function buildLegacySpell(spellDto) {
    const item = track(elementFactory('article'));
    item.className = 'clan-view__spell';
    item.setAttribute('data-spell-id', String(spellDto.id ?? ''));
    item.setAttribute('data-spell-slug', String(spellDto.slug ?? ''));
    item.setAttribute('data-circle', String(spellDto.circle ?? ''));

    appendTextElement(item, 'h3', 'clan-view__spell-name', String(spellDto.name ?? ''));
    appendTextElement(
      item,
      'p',
      'clan-view__spell-stats',
      `${String(spellDto.magicSchoolLabel ?? '')} · ${CIRCLE_LABEL} ${Number(spellDto.circle ?? 0)} · ${Number(spellDto.manaCost ?? 0)} de maná`,
    );
    // RF-05.1: el crédito del autor original jamás se pierde, aunque ya no
    // milite en la casa.
    appendTextElement(
      item,
      'p',
      'clan-view__spell-author',
      `${LEGACY_AUTHOR_PREFIX} ${String(spellDto.authorAlias ?? '')}`,
    );

    if (spellDto.isGenesisSample === true) {
      appendTextElement(item, 'p', 'clan-view__spell-genesis', GENESIS_SEAL);
    }

    if (typeof onSpellSelect === 'function') {
      item.setAttribute('data-action', 'selectSpell');
      item.setAttribute('role', 'button');
      item.setAttribute('tabindex', '0');
      item.addEventListener('click', () => onSpellSelect(String(spellDto.slug ?? '')));
      item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault?.();
          onSpellSelect(String(spellDto.slug ?? ''));
        }
      });
    }

    return item;
  }

  /** Pinta el catálogo de conjuros sellados, o su leyenda de vacío. */
  function paintLegacy(section, clan, legacyEnvelope) {
    const isArchived = clan.status === 'archived';
    const spells = Array.isArray(legacyEnvelope?.spells) ? legacyEnvelope.spells : [];

    const block = track(elementFactory('section'));
    block.className = 'clan-view__legacy';
    block.setAttribute('role', 'region');
    block.setAttribute('aria-labelledby', 'clanViewLegacyTitle');
    section.appendChild(block);

    const heading = appendTextElement(
      block,
      'h2',
      'clan-view__section-title',
      isArchived ? LEGACY_TITLE_ANCESTRAL : LEGACY_TITLE,
    );
    heading.setAttribute('id', 'clanViewLegacyTitle');

    if (isArchived) {
      // El sello ancestral acompaña también al catálogo (RF-05.3).
      block.setAttribute('data-heritage', 'ancestral');
      appendTextElement(block, 'p', 'clan-view__legacy-heritage', HERITAGE_ANCESTRAL_SEAL);
    }

    if (spells.length === 0) {
      appendTextElement(block, 'p', 'clan-view__legacy-empty', LEGACY_EMPTY_LEGEND);
      return;
    }

    // Concordancia ceremonial: el patrimonio de una sola obra se canta en
    // singular (noble castellano, AGENTS.md 7).
    const legacyCountLegend =
      spells.length === 1
        ? '1 conjuro ratificado compone el patrimonio de la casa.'
        : `${spells.length} conjuros ratificados componen el patrimonio de la casa.`;

    appendTextElement(block, 'p', 'clan-view__legacy-count', legacyCountLegend);

    const list = track(elementFactory('div'));
    list.className = 'clan-view__legacy-list';
    block.appendChild(list);

    for (const spellDto of spells) {
      list.appendChild(buildLegacySpell(spellDto));
    }
  }

  /** Pinta la ficha completa (o el estado de error/lo que proceda). */
  function paint() {
    if (viewRoot === null) return;

    clearChildren(viewRoot);
    managementPanel = null;

    alertRegion = null;

    if (clanDetail === null) {
      appendTextElement(viewRoot, 'p', 'clan-view__loading', CLAN_VIEW_LOADING_LEGEND);
      return;
    }

    const clan = clanDetail.clan ?? {};
    const state = viewerState();
    const isArchived = clan.status === 'archived';

    const article = track(elementFactory('article'));
    article.className = isArchived ? 'clan-view__sheet clan-view__sheet--ancestral' : 'clan-view__sheet';
    article.setAttribute('data-clan-id', String(clan.id ?? ''));
    article.setAttribute('data-status', String(clan.status ?? ''));
    viewRoot.appendChild(article);

    // Región anunciada: la ficha se declara como un todo contemplable.
    article.setAttribute('role', 'region');
    article.setAttribute('aria-labelledby', 'clanViewTitle');

    if (isArchived) paintHeritageSeal(article);
    paintHeraldry(article, clan);
    paintFacts(article, clan);
    paintAffiliation(article, clan, state);
    paintPatriarch(article, clanDetail);
    paintMembers(article, clanDetail);
    paintGovernment(article, clan, clanDetail, state);
    paintLegacy(article, clan, legacy);

    alertRegion = appendTextElement(article, 'p', 'clan-view__proclamation', proclamation);
    alertRegion.setAttribute('role', 'status');
    alertRegion.setAttribute('aria-live', 'polite');
    alertRegion.setAttribute('aria-atomic', 'true');
  }

  /** Pinta el corte de corriente con su reintento ceremonial. */
  function paintErrorState(errorEnvelope) {
    clearChildren(viewRoot);
    managementPanel = null;
    alertRegion = null;
    proclamation = '';

    const errorBlock = track(elementFactory('div'));
    errorBlock.className = 'clan-view__error';
    // role="alert": el fallo interrumpe la contemplación de la ficha.
    errorBlock.setAttribute('role', 'alert');
    viewRoot.appendChild(errorBlock);

    const legend = typeof errorEnvelope?.message === 'string' && errorEnvelope.message.trim() !== ''
      ? errorEnvelope.message
      : CLAN_VIEW_ERROR_LEGEND;
    appendTextElement(errorBlock, 'p', 'clan-view__error-message', legend);

    const retryButton = track(elementFactory('button'));
    retryButton.type = 'button';
    retryButton.className = 'clan-view__error-retry button button--secondary';
    retryButton.textContent = CLAN_VIEW_RETRY_LABEL;
    retryButton.addEventListener('click', () => {
      void retry();
    });
    errorBlock.appendChild(retryButton);
  }

  /* =====================================================================
     Ciclo de vida
     ===================================================================== */

  /**
   * Consulta la ficha y el legado, y pinta. Guardia anti-carreras: una
   * respuesta tardía de una consulta antigua jamás pinta.
   */
  async function load() {
    if (isDestroyed || viewRoot === null) return;

    const currentSequence = ++fetchSequence;

    if (activeClanClient === null || typeof activeClanClient.fetchClan !== 'function') {
      paintErrorState(null);
      return;
    }

    await ensureLineageCatalog();

    const [detailResult, legacyResult] = await Promise.all([
      activeClanClient.fetchClan(clanId).catch(() => ({ success: false, error: null })),
      typeof activeClanClient.fetchClanSpells === 'function'
        ? activeClanClient.fetchClanSpells(clanId).catch(() => ({ success: false, data: null }))
        : Promise.resolve({ success: false, data: null }),
    ]);

    if (currentSequence !== fetchSequence || isDestroyed || viewRoot === null) return;

    if (detailResult?.success !== true) {
      clanDetail = null;
      legacy = null;
      paintErrorState(detailResult?.error ?? null);
      return;
    }

    clanDetail = detailResult.data ?? null;
    // El legado es best-effort: si su consulta cayera, la ficha sigue en pie.
    legacy = legacyResult?.success === true ? (legacyResult.data ?? null) : null;

    paint();
  }

  /** Reintenta la consulta completa tras un corte de corriente. */
  async function retry() {
    fetchSequence += 1; // invalida respuestas en vuelo.
    clanDetail = null;
    legacy = null;
    if (viewRoot !== null) {
      clearChildren(viewRoot);
      appendTextElement(viewRoot, 'p', 'clan-view__loading', CLAN_VIEW_LOADING_LEGEND);
    }
    await load();
  }

  /** Monta la ficha. Idempotente. */
  async function render() {
    if (isDestroyed) return;

    destroy(false);
    proclamation = ''; // Montaje nuevo: ninguna leyenda de un ciclo anterior.

    viewRoot = track(elementFactory('section'));
    // El tomo central acota la ficha a la anchura canónica del santuario.
    viewRoot.className = 'clan-view grimoire-tomo-container';
    mountRoot.appendChild(viewRoot);

    // La ficha pasa a ser la vista activa del store (plan 4.1).
    store?.setState?.({ currentView: 'clan' });

    appendTextElement(viewRoot, 'p', 'clan-view__loading', CLAN_VIEW_LOADING_LEGEND);

    await load();
  }

  /**
   * Retira la ficha del punto de montaje y libera sus nodos.
   * @param {boolean} [removeFromMount=true] El render interno lo invoca con
   *        false para limpiar la pintura previa antes de volver a montar.
   */
  function destroy(removeFromMount = true) {
    fetchSequence += 1; // invalida respuestas en vuelo.
    managementPanel?.destroy?.();
    managementPanel = null;
    if (removeFromMount) proclamation = '';

    for (const node of mountedNodes.splice(0)) {
      node.remove?.();
    }
    viewRoot = null;
    alertRegion = null;
    clanDetail = null;
    legacy = null;

    if (removeFromMount) {
      isDestroyed = true;
      for (const child of [...(mountRoot.children ?? [])]) {
        if (String(child.className ?? '').includes('clan-view')) {
          child.remove?.();
        }
      }
    }
  }

  /** Conmuta el cliente del gobierno (reintentos con otro canal). */
  function setClanClient(nextClanClient) {
    activeClanClient = nextClanClient;
  }

  /** La casa retratada en este instante, o null. */
  function getClan() {
    return clanDetail?.clan ?? null;
  }

  return { render, destroy, retry, setClanClient, getClan };
}

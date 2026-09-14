/**
 * clanManagementComponent.js — Panel de Gobierno del Patriarca.
 *
 * Tarea 6.2 (TASKS-07). RF-01.3, RF-01.4, RF-01.5, RF-01.9.
 *
 * Es el panel EXCLUSIVO de quien ciñe la corona: muda el lema y el blasón,
 * conmuta el régimen de admisión, contempla la ocupación de la casa (x/30),
 * delibera la cola de solicitudes pendientes, expulsa adeptos y cede la
 * corona del liderazgo a otro hermano (RF-01.3). El cupo de treinta adeptos
 * se exhibe en todo momento y jamás se rebasa (RF-01.4); el régimen admite
 * los dos modos canónicos (RF-01.5); y la corona puede cederse en vida, sin
 * esperar al velatorio de los cuarenta y cinco días (RF-01.9).
 *
 * Contrato de datos: el sobre del Endpoint 3 (`data.clan`, `data.patriarch`,
 * `data.members`, `data.applications`) más el vinculado en curso (`viewer`),
 * que la vista de la casa inyecta desde el store de sesión (SPEC-03). Las
 * mutaciones se cursan SIEMPRE por `clanClient` (Tarea 5.1) contra los
 * Endpoints 4, 6, 8 y 9.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; los nodos se forjan con
 *     createElement y textContent — innerHTML está PROHIBIDO (AGENTS.md 6.1).
 *   - Artículo II (Ley Universal del Maná): el panel JAMÁS calcula el canon.
 *     No cuenta días, no dirime cupos ni rangos: porta la intención, exhibe
 *     el veredicto del santuario y respeta su autoridad. El cupo de treinta
 *     que se muestra es un ESPEJO del recuento que sirve la API, y si el
 *     santuario rechaza una admisión por plenitud, la leyenda es la suya.
 *   - Artículo III: el panel no permite expulsar a quien ciñe la corona (ni
 *     siquiera ofrece el control) y cede la corona por designación expresa.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en
 *     inglés camelCase; comentarios en castellano.
 *   - RNF-03: regiones etiquetadas, estados anunciados (role="status"), fallos
 *     como alertas, diálogo de confirmación como `alertdialog` con foco preso,
 *     y zona táctil mínima en cada control.
 *
 * Degradación elegante (AGENTS.md 8): sin cliente inyectado el panel se
 * exhibe en modo contemplativo (sin acciones); un fallo del santuario jamás
 * lanza hacia la vista, solo enciende la alerta con la leyenda ceremonial.
 */

import { ceremonialLegendFor } from '../api/clanClient.js';

/** Cupo canónico de adeptos, espejo de `ClanDto::MEMBER_LIMIT` (RF-01.4). */
export const MEMBER_LIMIT = 30;

/** Régimen abierto: el ingreso es inmediato mientras haya vacante (RF-01.5). */
export const ADMISSION_OPEN = 'open';

/** Régimen bajo petición: la corona delibera cada postulación (RF-01.5). */
export const ADMISSION_BY_APPLICATION = 'byApplication';

/** Veredictos canónicos de la deliberación (plan 2.2, Endpoint 6). */
export const DECISION_APPROVE = 'approve';
export const DECISION_REJECT = 'reject';

/** Código del corte de maná: contrato de error del proyecto (AGENTS.md 6.1). */
export const NETWORK_ERROR_CODE = 'MANA_STREAM_INTERRUPTED';

/** Extensión del lema y del blasón, espejo del esquema DDL. */
export const MOTTO_MAX_LENGTH = 255;
export const COAT_OF_ARMS_MAX_LENGTH = 100;

/** Leyenda del régimen de admisión en cada uno de sus dos modos (RF-01.5). */
export const ADMISSION_MODE_LABELS = Object.freeze({
  [ADMISSION_OPEN]: 'Abierta: todo caminante apto ingresa sin petición previa.',
  [ADMISSION_BY_APPLICATION]: 'Bajo petición: la corona delibera cada postulación.',
});

/** Título ceremonial del panel. */
export const MANAGEMENT_TITLE = 'Gobierno de la Hermandad';

/** Proclama del panel reservado: solo quien ciñe la corona gobierna (RF-01.3). */
export const CROWN_RESERVED_NOTICE =
  'El gobierno de la casa pertenece a quien ciñe la corona de Patriarca.';

/** Proclama de plenitud: la casa ha colmado su cupo de treinta (RF-01.4). */
export const QUOTA_FULL_NOTICE =
  'La hermandad ha alcanzado su plenitud de treinta adeptos: los anales no admiten uno más.';

/** Proclama de la casa disuelta: su memoria es Herencia Ancestral. */
export const ARCHIVED_NOTICE =
  'La hermandad yace disuelta como Herencia Ancestral: su memoria perdura, su gobierno no.';

/** Leyendas locales del panel (validaciones previas, no canon del backend). */
export const PANEL_LEGENDS = Object.freeze({
  mottoRequired: 'El lema heráldico no puede quedar en blanco.',
  mottoTooLong: `El lema heráldico no puede exceder ${MOTTO_MAX_LENGTH} caracteres.`,
  coatRequired: 'El blasón rúnico no puede quedar en blanco.',
  coatTooLong: `El blasón rúnico no puede exceder ${COAT_OF_ARMS_MAX_LENGTH} caracteres.`,
  journalEmpty: 'No aguarda postulación alguna el veredicto de la corona.',
  rosterEmpty: 'El estandarte aún no cobija adepto alguno.',
  noClient: 'El panel contempla la casa, mas no hay corriente de maná que curse órdenes.',
});

/**
 * Crea el componente del panel de gobierno del Patriarca.
 *
 * @param {HTMLElement} mountRoot Contenedor de la vista de la casa (Fase 6.4).
 * @param {Object} options
 * @param {Object} [options.clanClient] Cliente del gobierno (Tarea 5.1).
 * @param {Object} [options.context] Sobre del Endpoint 3 + `viewer`.
 * @param {Object} [options.viewer] Vinculado en curso (id, alias, role); la
 *        vista lo inyecta desde el store de sesión (SPEC-03).
 * @param {(request: Object) => Promise<boolean>} [options.confirm] Diálogo de
 *        confirmación inyectable; sin él se forja el `alertdialog` propio.
 * @param {(clan: Object) => void} [options.onClanMutated] La ficha mudó.
 * @param {(envelope: Object, legend: string) => void} [options.onError] Fallo
 *        del santuario (la vista puede elevar el aviso a su propia voz).
 * @param {(proclamation: string) => void} [options.onProclamation] Proclama.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica
 *        inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento inyectable (tests).
 * @returns {Object} API: { render, setContext, getClan, isCrowned, destroy }.
 */
export function createClanManagementComponent(mountRoot, options = {}) {
  const {
    clanClient = null,
    viewer = null,
    confirm = null,
    onClanMutated = null,
    onError = null,
    onProclamation = null,
    elementFactory = (tagName) => globalThis.document?.createElement?.(tagName) ?? null,
  } = options;

  /** Nodos vivos del panel, para limpieza determinista (re-render idempotente). */
  let mountedNodes = [];

  /** Sobre vigente del Endpoint 3, más el vinculado en curso. */
  let clan = null;
  let members = [];
  let applications = [];
  let activeViewer = viewer;

  /** Campos heráldicos forjados (se leen al enviar el formulario). */
  let mottoInput = null;
  let coatOfArmsInput = null;

  /** Región de proclamas y alerta de fallos. */
  let statusRegion = null;
  let alertRegion = null;

  /** Guardia de acción en vuelo: dos gestos no cursan dos órdenes (RNF-01). */
  let actionInFlight = false;

  /** Diálogo de confirmación vigente (uno solo a la vez). */
  let confirmationNode = null;

  /** El componente quedó destruido: nada vuelve a pintarse. */
  let isDestroyed = false;

  // -----------------------------------------------------------------
  // Ciclo de vida del panel
  // -----------------------------------------------------------------

  /** Retira del árbol los nodos del panel previo (re-render idempotente). */
  function clearPainting() {
    for (const node of mountedNodes) {
      node?.remove?.();
    }
    mountedNodes = [];
    mottoInput = null;
    coatOfArmsInput = null;
    statusRegion = null;
    alertRegion = null;
  }

  /** Marca legible de un instante; jamás una fecha vacía tras la preposición. */
  function describeInstant(instant) {
    const text = String(instant ?? '').trim();

    return text === '' ? 'una fecha no inscrita en los anales' : text;
  }

  /** Añade un nodo de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    if (node === null) return null;
    if (className !== null) node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    mountedNodes.push(node);
    return node;
  }

  /** Registra un nodo forjado para su limpieza. */
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Número de adeptos activos del espejo vigente (RF-01.4). */
  function activeMemberCount() {
    const activeMembers = members.filter((member) => member?.isActive !== false);
    if (activeMembers.length > 0) return activeMembers.length;
    const served = Number(clan?.memberCount ?? 0);

    return Number.isFinite(served) ? served : 0;
  }

  /** ¿Está la casa en su plenitud de treinta adeptos? */
  function isAtFullQuota() {
    return activeMemberCount() >= MEMBER_LIMIT;
  }

  /** ¿Ciñe el vinculado en curso la corona de esta casa? (RF-01.3) */
  function isCrowned() {
    const viewerId = typeof activeViewer?.id === 'string' ? activeViewer.id : '';
    const patriarchId = typeof clan?.patriarchId === 'string' ? clan.patriarchId : '';

    return viewerId !== '' && viewerId === patriarchId;
  }

  /** ¿Admite la casa gobierno alguno? Una casa disuelta no. */
  function isGovernable() {
    return clan !== null && clan.status !== 'archived';
  }

  /** ¿Puede el panel cursar órdenes contra el santuario? */
  function canCommand() {
    return clanClient !== null && typeof clanClient.updateClan === 'function';
  }

  // -----------------------------------------------------------------
  // Proclamas y alertas
  // -----------------------------------------------------------------

  /** Proclama solemne (role="status"): el santuario habló y el panel lo narra. */
  function proclaim(message) {
    if (statusRegion !== null) statusRegion.textContent = message;
    if (typeof onProclamation === 'function') onProclamation(message);
  }

  /** Alerta ceremonial (role="alert"): jamás una traza técnica. */
  function alertPlayer(envelope, legend = null) {
    const message = legend ?? ceremonialLegendFor(envelope);
    if (alertRegion !== null) alertRegion.textContent = message;
    if (typeof onError === 'function') onError(envelope, message);

    return message;
  }

  // -----------------------------------------------------------------
  // Diálogo solemne de confirmación
  // -----------------------------------------------------------------

  /**
   * Abre la confirmación solemne del gesto y aguarda su veredicto.
   *
   * @param {Object} request {title, message, confirmLabel, cancelLabel}.
   * @returns {Promise<boolean>} `true` solo si el gesto fue confirmado.
   */
  function openConfirmation(request) {
    if (typeof confirm === 'function') {
      return Promise.resolve(confirm(request)).then((verdict) => verdict === true);
    }

    return new Promise((resolve) => {
      const overlay = elementFactory('div');
      if (overlay === null) {
        resolve(false);
        return;
      }
      overlay.className = 'clan-management__confirmation';
      overlay.setAttribute('role', 'alertdialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'clanConfirmationTitle');
      overlay.setAttribute('aria-describedby', 'clanConfirmationMessage');
      mountRoot.appendChild(overlay);
      confirmationNode = overlay;

      const dialog = elementFactory('section');
      dialog.className = 'clan-management__confirmation-panel';
      overlay.appendChild(dialog);

      const title = appendTextElement(dialog, 'h3', 'clan-management__confirmation-title', request.title);
      title?.setAttribute('id', 'clanConfirmationTitle');
      const message = appendTextElement(
        dialog,
        'p',
        'clan-management__confirmation-message',
        request.message,
      );
      message?.setAttribute('id', 'clanConfirmationMessage');

      /** Cierra el diálogo resolviendo el veredicto y devolviendo el foco. */
      const settle = (verdict) => {
        overlay.remove?.();
        confirmationNode = null;
        overlay.removeEventListener?.('keydown', onKeydown);
        resolve(verdict);
      };

      function onKeydown(event) {
        if (event?.key === 'Escape') {
          event.preventDefault?.();
          settle(false);
        }
      }

      const cancelButton = elementFactory('button');
      if (cancelButton !== null) {
        cancelButton.type = 'button';
        cancelButton.className = 'clan-management__confirmation-cancel button button--secondary';
        cancelButton.textContent = request.cancelLabel ?? 'Desistir del gesto';
        cancelButton.addEventListener('click', () => settle(false));
        dialog.appendChild(cancelButton);
        track(cancelButton);
      }

      const confirmButton = elementFactory('button');
      if (confirmButton !== null) {
        confirmButton.type = 'button';
        confirmButton.className = 'clan-management__confirmation-accept button';
        confirmButton.textContent = request.confirmLabel ?? 'Confirmar el gesto';
        confirmButton.addEventListener('click', () => settle(true));
        dialog.appendChild(confirmButton);
        track(confirmButton);
      }

      // Escape desiste; el foco nace en la confirmación (RNF-03).
      overlay.addEventListener('keydown', onKeydown);

      // El foco inicial: la confirmación, para que un tecleo no dispare el gesto.
      confirmButton?.focus?.();
    });
  }

  // -----------------------------------------------------------------
  // Pintura del panel
  // -----------------------------------------------------------------

  /**
   * Forja el armazón del panel: región etiquetada con el nombre de la casa.
   *
   * @returns {HTMLElement} El contenedor donde viven las secciones.
   */
  function buildShell() {
    const shell = track(elementFactory('section'));
    shell.className = 'clan-management';
    shell.setAttribute('id', 'clanManagement');
    shell.setAttribute('role', 'region');
    shell.setAttribute('aria-labelledby', 'clanManagementTitle');
    shell.setAttribute('data-clan-id', String(clan?.id ?? ''));
    mountRoot.appendChild(shell);

    const title = appendTextElement(shell, 'h2', 'clan-management__title', MANAGEMENT_TITLE);
    title?.setAttribute('id', 'clanManagementTitle');
    appendTextElement(
      shell,
      'p',
      'clan-management__house',
      `${String(clan?.name ?? '')} · linaje ${String(clan?.lineageType ?? '')}`,
    );

    return shell;
  }

  /** Pinta la región reservada a quien no ciñe la corona (o a la casa disuelta). */
  function paintSealedNotices(shell, notice) {
    const sealed = appendTextElement(
      shell,
      'p',
      'clan-management__sealed',
      notice,
    );
    sealed?.setAttribute('role', 'status');
    sealed?.setAttribute('aria-live', 'polite');
  }

  /** Sección del censo: ocupación x/30 con su barra y su proclama (RF-01.4). */
  function buildOccupancy(shell) {
    const section = track(elementFactory('section'));
    section.className = 'clan-management__occupancy';
    section.setAttribute('aria-label', 'Ocupación de la hermandad');

    appendTextElement(section, 'h3', 'clan-management__section-title', 'Censo de la Hermandad');

    const count = activeMemberCount();
    const legend = appendTextElement(
      section,
      'p',
      'clan-management__quota',
      `${count} / ${MEMBER_LIMIT} adeptos`,
    );
    legend?.setAttribute('data-member-count', String(count));
    legend?.setAttribute('data-member-limit', String(MEMBER_LIMIT));
    legend?.setAttribute('id', 'clanQuotaLegend');

    const progress = track(elementFactory('div'));
    progress.className = 'clan-management__quota-bar';
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', String(MEMBER_LIMIT));
    progress.setAttribute('aria-valuenow', String(count));
    progress.setAttribute('aria-labelledby', 'clanQuotaLegend');
    progress.setAttribute('style', `--quota-fill: ${Math.round((count / MEMBER_LIMIT) * 100)}%`);
    section.appendChild(progress);

    const fill = track(elementFactory('span'));
    fill.className = 'clan-management__quota-fill';
    fill.setAttribute('aria-hidden', 'true');
    progress.appendChild(fill);

    if (isAtFullQuota()) {
      const full = appendTextElement(section, 'p', 'clan-management__quota-full', QUOTA_FULL_NOTICE);
      full?.setAttribute('data-quota-state', 'full');
    }

    shell.appendChild(section);

    return section;
  }

  /** Sección del régimen de admisión: conmutador canónico (RF-01.5). */
  function buildAdmissionSection(shell) {
    const section = track(elementFactory('section'));
    section.className = 'clan-management__admission';
    section.setAttribute('aria-label', 'Régimen de admisión');

    appendTextElement(section, 'h3', 'clan-management__section-title', 'Régimen de Admisión');

    const current = clan?.admissionMode === ADMISSION_BY_APPLICATION
      ? ADMISSION_BY_APPLICATION
      : ADMISSION_OPEN;

    const group = track(elementFactory('div'));
    group.className = 'clan-management__admission-group';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', 'Régimen de admisión de la casa');
    section.appendChild(group);

    for (const mode of [ADMISSION_OPEN, ADMISSION_BY_APPLICATION]) {
      const button = track(elementFactory('button'));
      button.type = 'button';
      button.className = `clan-management__admission-option clan-management__admission-option--${mode === ADMISSION_OPEN ? 'open' : 'by-application'}`;
      button.setAttribute('data-mode', mode);
      button.setAttribute('aria-pressed', mode === current ? 'true' : 'false');
      button.textContent = mode === ADMISSION_OPEN ? 'Régimen Abierto' : 'Bajo Petición';
      button.addEventListener('click', () => {
        void changeAdmissionMode(mode);
      });
      group.appendChild(button);
    }

    appendTextElement(
      section,
      'p',
      'clan-management__admission-legend',
      ADMISSION_MODE_LABELS[current],
    );

    shell.appendChild(section);

    return section;
  }

  /** Sección heráldica: lema y blasón (RF-01.3). */
  function buildHeraldrySection(shell) {
    const section = track(elementFactory('section'));
    section.className = 'clan-management__heraldry';
    section.setAttribute('aria-label', 'Heráldica de la hermandad');

    appendTextElement(section, 'h3', 'clan-management__section-title', 'Heráldica del Estandarte');

    const form = track(elementFactory('form'));
    form.className = 'clan-management__heraldry-form';
    form.setAttribute('novalidate', '');
    section.appendChild(form);

    const mottoLabel = appendTextElement(form, 'label', 'clan-management__field-label', 'Lema heráldico');
    mottoLabel?.setAttribute('for', 'clanMottoInput');
    mottoInput = track(elementFactory('input'));
    mottoInput.type = 'text';
    mottoInput.className = 'clan-management__input';
    mottoInput.setAttribute('id', 'clanMottoInput');
    mottoInput.setAttribute('name', 'motto');
    mottoInput.setAttribute('maxlength', String(MOTTO_MAX_LENGTH));
    mottoInput.value = String(clan?.motto ?? '');
    form.appendChild(mottoInput);

    const coatLabel = appendTextElement(form, 'label', 'clan-management__field-label', 'Blasón rúnico');
    coatLabel?.setAttribute('for', 'clanCoatInput');
    coatOfArmsInput = track(elementFactory('input'));
    coatOfArmsInput.type = 'text';
    coatOfArmsInput.className = 'clan-management__input';
    coatOfArmsInput.setAttribute('id', 'clanCoatInput');
    coatOfArmsInput.setAttribute('name', 'coatOfArms');
    coatOfArmsInput.setAttribute('maxlength', String(COAT_OF_ARMS_MAX_LENGTH));
    coatOfArmsInput.value = String(clan?.coatOfArms ?? '');
    form.appendChild(coatOfArmsInput);

    const submit = track(elementFactory('button'));
    submit.type = 'submit';
    submit.className = 'clan-management__submit button';
    submit.setAttribute('data-action', 'updateHeraldry');
    submit.textContent = 'Mudar la heráldica';
    form.appendChild(submit);

    form.addEventListener('submit', (event) => {
      event?.preventDefault?.();
      void updateHeraldry();
    });

    shell.appendChild(section);

    return section;
  }

  /** Sección de solicitudes pendientes: la cola que aguarda veredicto (RF-01.5). */
  function buildApplicationsSection(shell) {
    const section = track(elementFactory('section'));
    section.className = 'clan-management__applications';
    section.setAttribute('aria-label', 'Solicitudes pendientes de ingreso');

    appendTextElement(
      section,
      'h3',
      'clan-management__section-title',
      `Solicitudes Pendientes (${applications.length})`,
    );

    if (applications.length === 0) {
      appendTextElement(section, 'p', 'clan-management__journal-empty', PANEL_LEGENDS.journalEmpty);
      shell.appendChild(section);

      return section;
    }

    const journal = track(elementFactory('ul'));
    journal.className = 'clan-management__journal';
    section.appendChild(journal);

    for (const application of applications) {
      const item = track(elementFactory('li'));
      item.className = 'clan-management__application';
      item.setAttribute('data-application-id', String(application?.id ?? ''));
      item.setAttribute('data-applicant-id', String(application?.userId ?? ''));
      journal.appendChild(item);

      appendTextElement(
        item,
        'p',
        'clan-management__applicant',
        String(application?.userAlias ?? '').trim() === ''
          ? 'Postulante sin nombre inscrito'
          : String(application.userAlias),
      );
      appendTextElement(
        item,
        'p',
        'clan-management__application-date',
        `Postulación inscrita el ${describeInstant(application?.createdAt)}`,
      );

      const approveButton = track(elementFactory('button'));
      approveButton.type = 'button';
      approveButton.className = 'clan-management__decision clan-management__decision--approve button';
      approveButton.setAttribute('data-decision', DECISION_APPROVE);
      approveButton.setAttribute('data-application-id', String(application?.id ?? ''));
      approveButton.textContent = 'Aceptar el juramento';
      approveButton.addEventListener('click', () => {
        void resolve(application, DECISION_APPROVE);
      });
      item.appendChild(approveButton);

      const rejectButton = track(elementFactory('button'));
      rejectButton.type = 'button';
      rejectButton.className = 'clan-management__decision clan-management__decision--reject button button--secondary';
      rejectButton.setAttribute('data-decision', DECISION_REJECT);
      rejectButton.setAttribute('data-application-id', String(application?.id ?? ''));
      rejectButton.textContent = 'Rechazar la postulación';
      rejectButton.addEventListener('click', () => {
        void resolve(application, DECISION_REJECT);
      });
      item.appendChild(rejectButton);
    }

    shell.appendChild(section);

    return section;
  }

  /** Sección del censo nominal: adeptos, corona, expulsión y cesión (RF-01.3). */
  function buildRosterSection(shell) {
    const section = track(elementFactory('section'));
    section.className = 'clan-management__roster';
    section.setAttribute('aria-label', 'Adeptos de la hermandad');

    appendTextElement(section, 'h3', 'clan-management__section-title', 'Adeptos del Estandarte');

    if (members.length === 0) {
      appendTextElement(section, 'p', 'clan-management__roster-empty', PANEL_LEGENDS.rosterEmpty);
      shell.appendChild(section);

      return section;
    }

    const roster = track(elementFactory('ul'));
    roster.className = 'clan-management__members';
    section.appendChild(roster);

    for (const member of members) {
      const isPatriarch = member?.role === 'patriarch' || member?.userId === clan?.patriarchId;
      const item = track(elementFactory('li'));
      item.className = isPatriarch
        ? 'clan-management__member clan-management__member--patriarch'
        : 'clan-management__member';
      item.setAttribute('data-member-id', String(member?.userId ?? ''));
      item.setAttribute('data-member-role', isPatriarch ? 'patriarch' : 'adept');
      roster.appendChild(item);

      appendTextElement(
        item,
        'p',
        'clan-management__member-alias',
        String(member?.userAlias ?? '').trim() === '' ? 'Adepto sin nombre inscrito' : String(member.userAlias),
      );
      const rank = appendTextElement(
        item,
        'p',
        'clan-management__member-role',
        isPatriarch ? 'Patriarca o Matriarca — ciñe la corona' : 'Adepto del Linaje',
      );
      rank?.setAttribute('data-role', isPatriarch ? 'patriarch' : 'adept');
      appendTextElement(
        item,
        'p',
        'clan-management__member-joined',
        `Ingresó el ${describeInstant(member?.joinedAt)}`,
      );

      // La corona no se expulsa a sí misma (Artículo III): al Patriarca no se
      // le ofrece control de expulsión alguno.
      if (isPatriarch) {
        const crown = appendTextElement(item, 'span', 'clan-management__crown', '♛');
        crown?.setAttribute('role', 'img');
        crown?.setAttribute('aria-label', 'Corona de Patriarca');
        continue;
      }

      const expelButton = track(elementFactory('button'));
      expelButton.type = 'button';
      expelButton.className = 'clan-management__expel button button--secondary';
      expelButton.setAttribute('data-action', 'expelMember');
      expelButton.setAttribute('data-member-id', String(member?.userId ?? ''));
      expelButton.textContent = 'Dictar expulsión';
      expelButton.addEventListener('click', () => {
        void expel(member);
      });
      item.appendChild(expelButton);

      const transferButton = track(elementFactory('button'));
      transferButton.type = 'button';
      transferButton.className = 'clan-management__transfer button';
      transferButton.setAttribute('data-action', 'transferLeadership');
      transferButton.setAttribute('data-member-id', String(member?.userId ?? ''));
      transferButton.textContent = 'Ceder la corona';
      transferButton.addEventListener('click', () => {
        void cedeCrown(member);
      });
      item.appendChild(transferButton);
    }

    shell.appendChild(section);

    return section;
  }

  /** Regiones de proclama y alerta: nacen siempre, incluso sin acciones. */
  function buildStatusRegions(shell) {
    statusRegion = appendTextElement(shell, 'p', 'clan-management__status', '');
    statusRegion?.setAttribute('role', 'status');
    statusRegion?.setAttribute('aria-live', 'polite');
    statusRegion?.setAttribute('aria-atomic', 'true');

    alertRegion = appendTextElement(shell, 'p', 'clan-management__alert', '');
    alertRegion?.setAttribute('role', 'alert');
  }

  /**
   * Pinta el panel completo a partir del sobre vigente.
   *
   * @returns {boolean} `true` si el panel de gobierno quedó desplegado.
   */
  function paint() {
    if (clan === null) return false;

    const shell = buildShell();

    if (clan.status === 'archived') {
      paintSealedNotices(shell, ARCHIVED_NOTICE);
      buildStatusRegions(shell);

      return false;
    }

    if (isCrowned() === false) {
      paintSealedNotices(shell, CROWN_RESERVED_NOTICE);
      buildStatusRegions(shell);

      return false;
    }

    buildOccupancy(shell);
    buildAdmissionSection(shell);
    buildHeraldrySection(shell);
    buildApplicationsSection(shell);
    buildRosterSection(shell);
    buildStatusRegions(shell);

    if (canCommand() === false) {
      // El panel contempla la casa, pero ninguna orden puede cursarse.
      if (alertRegion !== null) alertRegion.textContent = PANEL_LEGENDS.noClient;
    }

    return true;
  }

  /**
   * Monta el panel sobre el sobre del Endpoint 3.
   *
   * @param {Object|null} context {clan, patriarch, members, applications, viewer}.
   * @returns {boolean} `true` si el panel de gobierno quedó desplegado.
   */
  function render(context = null) {
    if (isDestroyed) return false;

    if (context !== null && typeof context === 'object') {
      setContext(context);
    }

    clearPainting();

    return paint();
  }

  /**
   * Asienta el sobre vigente sin repintar (la vista puede alimentar el panel
   * y decidir cuándo renderizar).
   *
   * @param {Object} context {clan, patriarch, members, applications, viewer}.
   */
  function setContext(context = {}) {
    if (context === null || typeof context !== 'object') return;

    if (context.clan !== undefined) clan = context.clan;
    if (Array.isArray(context.members)) {
      members = context.members;
    } else if (context.patriarch !== undefined && context.patriarch !== null) {
      // Sin censo explícito, el Patriarca es el único morador que la API sirvió.
      members = [context.patriarch];
    }
    if (Array.isArray(context.applications)) applications = context.applications;
    if (context.viewer !== undefined && context.viewer !== null) activeViewer = context.viewer;
  }

  // -----------------------------------------------------------------
  // Acciones de gobierno (los cuatro Endpoints mutables)
  // -----------------------------------------------------------------

  /**
   * Ejecuta una orden del panel con guardia de concurrencia (RNF-01) y red
   * de seguridad: el cliente del santuario jamás lanza por contrato, pero
   * ninguna excepción escapa hacia la vista (AGENTS.md 6.1).
   */
  async function runAction(action) {
    if (isDestroyed || actionInFlight) return null;
    if (canCommand() === false) {
      if (alertRegion !== null) alertRegion.textContent = PANEL_LEGENDS.noClient;
      return null;
    }

    actionInFlight = true;
    try {
      return await action();
    } catch {
      // Corte inesperado (promesa rechazada): se traduce a la leyenda del
      // corte de maná y JAMÁS escapa hacia la vista.
      alertPlayer({ error: { code: NETWORK_ERROR_CODE } });

      return null;
    } finally {
      actionInFlight = false;
    }
  }  /**
   * Muda el régimen de admisión (RF-01.5, Endpoint 4).
   *
   * @param {string} mode `open` | `byApplication`.
   * @returns {Promise<object|null>} Sobre del santuario, o null si nada se cursó.
   */
  async function changeAdmissionMode(mode) {
    if (mode !== ADMISSION_OPEN && mode !== ADMISSION_BY_APPLICATION) return null;
    if (clan?.admissionMode === mode) return null;

    const clanId = String(clan?.id ?? '');

    return runAction(async () => {
      const envelope = await clanClient.updateClan(clanId, { admissionMode: mode });

      if (envelope?.success !== true) {
        alertPlayer(envelope);
        return envelope ?? null;
      }

      // La ficha devuelta por el santuario es la autoridad del nuevo estado.
      if (envelope.data !== undefined && envelope.data !== null) {
        clan = envelope.data;
      } else {
        clan = { ...clan, admissionMode: mode };
      }
      render();
      proclaim(`El régimen de admisión queda mudado: ${ADMISSION_MODE_LABELS[mode]}`);
      if (typeof onClanMutated === 'function') onClanMutated(clan);

      return envelope;
    });
  }

  /**
   * Muda lema y blasón (RF-01.3, Endpoint 4), con validación previa local.
   *
   * @returns {Promise<object|null>} Sobre del santuario, o null.
   */
  async function updateHeraldry() {
    const motto = String(mottoInput?.value ?? '').trim();
    const coatOfArms = String(coatOfArmsInput?.value ?? '').trim();

    /** Enciende la alerta con la leyenda local y declina cursar la orden. */
    const refuse = (legend) => {
      alertPlayer(null, legend);

      return null;
    };

    // Defensas locales antes de gastar la corriente de maná: no son canon,
    // solo evitan una petición condenada (la autoridad sigue siendo el backend).
    if (motto === '') return refuse(PANEL_LEGENDS.mottoRequired);
    if (motto.length > MOTTO_MAX_LENGTH) return refuse(PANEL_LEGENDS.mottoTooLong);
    if (coatOfArms === '') return refuse(PANEL_LEGENDS.coatRequired);
    if (coatOfArms.length > COAT_OF_ARMS_MAX_LENGTH) return refuse(PANEL_LEGENDS.coatTooLong);

    const clanId = String(clan?.id ?? '');

    return runAction(async () => {
      const envelope = await clanClient.updateClan(clanId, { motto, coatOfArms });

      if (envelope?.success !== true) {
        alertPlayer(envelope);
        return envelope ?? null;
      }

      if (envelope.data !== undefined && envelope.data !== null) {
        clan = envelope.data;
      } else {
        clan = { ...clan, motto, coatOfArms };
      }
      render();
      proclaim('La heráldica de la casa queda mudada en los anales.');

      return envelope;
    });
  }

  /**
   * Dirime una solicitud pendiente (RF-01.5, Endpoint 6).
   *
   * En la aprobación, el cupo a la vista se actualiza con la membresía que
   * devuelve el santuario; si la casa está colmada, la leyenda del 409 es la
   * que habla y la solicitud permanece aguardando (RF-01.4).
   *
   * @param {object} application Solicitud (app_*).
   * @param {string} decision    `approve` | `reject`.
   * @returns {Promise<object|null>} Sobre del santuario, o null.
   */
  async function resolve(application, decision) {
    const applicationId = String(application?.id ?? '');
    const clanId = String(clan?.id ?? '');
    if (applicationId === '' || clanId === '') return null;

    return runAction(async () => {
      const envelope = await clanClient.resolveApplication(clanId, applicationId, decision);

      if (envelope?.success !== true) {
        alertPlayer(envelope);
        return envelope ?? null;
      }

      // La cola pierde la solicitud deliberada, sea cual sea el veredicto.
      applications = applications.filter((pending) => String(pending?.id ?? '') !== applicationId);

      const membership = envelope.data?.membership ?? null;
      if (decision === DECISION_APPROVE && membership !== null) {
        // El censo crece a la vista, con la membresía que sirvió el santuario.
        members = [...members, membership];
        clan = { ...clan, memberCount: activeMemberCount() };
      }

      render();
      proclaim(
        decision === DECISION_APPROVE
          ? `El juramento queda aceptado: ${String(membership?.userAlias ?? application?.userAlias ?? 'el postulante')} milita ya bajo el estandarte.`
          : `La postulación de ${String(application?.userAlias ?? 'el caminante')} queda rechazada.`,
      );
      if (typeof onClanMutated === 'function') onClanMutated(clan);

      return envelope;
    });
  }

  /**
   * Expulsa a un adepto del estandarte (Endpoint 8) tras confirmación solemne.
   *
   * @param {object} member Adepto a expulsar.
   * @returns {Promise<object|null>} Sobre del santuario, o null.
   */
  async function expel(member) {
    const userId = String(member?.userId ?? '');
    const alias = String(member?.userAlias ?? 'el adepto');
    const clanId = String(clan?.id ?? '');
    if (userId === '' || clanId === '') return null;

    const confirmed = await openConfirmation({
      title: 'Dictar Expulsión',
      message: `¿Expulsar a ${alias} de la hermandad? Su partida abrirá catorce días de Convalecencia Arcana.`,
      confirmLabel: 'Dictar expulsión',
      cancelLabel: 'Desistir',
    });
    if (confirmed !== true || isDestroyed) return null;

    return runAction(async () => {
      const envelope = await clanClient.expelMember(clanId, userId);

      if (envelope?.success !== true) {
        alertPlayer(envelope);
        return envelope ?? null;
      }

      members = members.filter((brethren) => String(brethren?.userId ?? '') !== userId);
      clan = { ...clan, memberCount: activeMemberCount() };
      render();
      proclaim(`${alias} queda apartado del estandarte y purgará su convalecencia.`);
      if (typeof onClanMutated === 'function') onClanMutated(clan);

      return envelope;
    });
  }

  /**
   * Cede la corona del liderazgo (RF-01.3, Endpoint 9) tras confirmación
   * solemne: el gesto es irrevocable y el cedente desciende a adepto.
   *
   * @param {object} member Heredero designado.
   * @returns {Promise<object|null>} Sobre del santuario, o null.
   */
  async function cedeCrown(member) {
    const successorId = String(member?.userId ?? '');
    const alias = String(member?.userAlias ?? 'el adepto');
    const clanId = String(clan?.id ?? '');
    if (successorId === '' || clanId === '') return null;

    const confirmed = await openConfirmation({
      title: 'Ceder la Corona',
      message: `La corona del Patriarca pasará a ${alias} y tú descenderás a Adepto del Linaje. El gesto es irrevocable.`,
      confirmLabel: 'Ceder la corona',
      cancelLabel: 'Conservar la corona',
    });
    if (confirmed !== true || isDestroyed) return null;

    return runAction(async () => {
      const envelope = await clanClient.transferLeadership(clanId, successorId);

      if (envelope?.success !== true) {
        alertPlayer(envelope);
        return envelope ?? null;
      }

      // La corona muda de manos: el nuevo Patriarca asciende y el cedente
      // desciende a adepto en el espejo del panel.
      members = members.map((brethren) => {
        const brethrenId = String(brethren?.userId ?? '');
        if (brethrenId === successorId) return { ...brethren, role: 'patriarch' };
        if (brethrenId === String(clan?.patriarchId ?? '')) return { ...brethren, role: 'adept' };

        return brethren;
      });
      clan = { ...clan, patriarchId: successorId };
      render();
      proclaim(`${alias} ciñe ya la corona de la hermandad.`);
      if (typeof onClanMutated === 'function') onClanMutated(clan);

      return envelope;
    });
  }

  /** Retira el panel, el diálogo vigente y libera sus nodos. */
  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    confirmationNode?.remove?.();
    confirmationNode = null;
    clearPainting();
    clan = null;
    members = [];
    applications = [];
  }

  /** Ficha vigente del panel (la vista puede consultarla sin repintar). */
  function getClan() {
    return clan;
  }

  // Montaje inicial opcional: la vista puede pasar el sobre en las opciones.
  if (options.context !== undefined) {
    setContext(options.context);
  }

  return {
    render,
    setContext,
    getClan,
    isCrowned,
    destroy,
  };
}

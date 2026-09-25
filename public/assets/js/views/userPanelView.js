/**
 * userPanelView.js — La vista «Mi morada»: el Panel del Adepto
 * (SPEC-12, Tarea 5.1; plan §1.2).
 *
 * Vista orquestadora de la cámara privada: una carga del sobre de la
 * vitrina (plan §2.2) viste sus secciones — identidad, linaje, clan,
 * vínculo de sesión, convalecencia, contadores del tomo, deberes del
 * Maestro y gloria semanal — y declara los estados del peregrino
 * (RF-01.3): las secciones sujetas al juramento se visten como
 * PENDIENTES y su activación emite `panel:restricted-section-activated`
 * para que el orquestador conduzca a la ceremonia (plan §4.2).
 *
 * La vista es la ÚNICA que habla con el santuario en esta tarea (Artículo
 * II): los componentes del picker, la custodia, la lente y el contador
 * llegarán en las Tareas 6.1–6.4 como slot inyectables por sección.
 *
 * Ciclo (plan §3.1):
 *   montaje → fetchPanel()
 *     ├─ 200 (linajado) → vitrina completa
 *     ├─ 200 (peregrino) → secciones pendientes + credenciales vivas
 *     ├─ 401 → degradación solemne con conducción al umbral (RF-01.2)
 *     └─ 500 → aviso solemne con reintento (caso límite 11)
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO (centinela del arnés);
 *     cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module views/userPanelView
 */

/** Rótulos solemnes de la vista (Art. V). */
export const USER_PANEL_VIEW_TITLE = 'Mi morada';
export const USER_PANEL_EXPIRED_LEGEND =
  'Tu vínculo con el santuario ha expirado: renuévalo y tu morada aguardará donde la dejaste.';
export const USER_PANEL_RETRY_LABEL = 'Intentarlo de nuevo';
export const USER_PANEL_BACK_LABEL = 'Volver al portal';

/** Secciones sujetas al juramento (RF-01.3): vestidas como pendientes. */
export const PANEL_RESTRICTED_SECTIONS = Object.freeze([
  { section: 'avatar', label: 'Efigie', pendingLabel: 'Aguarda el juramento de linaje' },
  { section: 'lineage', label: 'Linaje', pendingLabel: 'Peregrino sin Linaje' },
  { section: 'clan', label: 'Hermandad', pendingLabel: 'Aguarda el juramento de linaje' },
  { section: 'works', label: 'Obras y deberes', pendingLabel: 'Aguarda el juramento de linaje' },
]);

/** Leyenda canónica del peregrino (espejo del DTO, RF-01.3). */
export const PANEL_PILGRIM_LINEAGE_LABEL = 'Peregrino sin Linaje';

/** Eventos que la vista emite sobre su raíz (plan §4.2). */
export const USER_PANEL_VIEW_EVENTS = Object.freeze({
  restrictedSectionActivated: 'panel:restricted-section-activated',
  vitrinaLoaded: 'panel:vitrina-loaded',
  vitrinaFailed: 'panel:vitrina-failed',
  renounceRequested: 'panel:renounce-requested',
});

/** Etiquetas castellanas de los contadores del tomo (RF-07.1). */
const COLLECTION_LEGENDS = Object.freeze({
  sealed: 'Obras selladas en tu tomo',
  praise: 'Homenajes rendidos',
});

/** Leyenda canónica sin cómputo vigente (RF-07.3: jamás cifras fantasma). */
const GLORY_SILENT_LEGEND = 'Tu hermandad aún no ha grabado gloria en esta semana.';

/** Leyendas de los deberes del Maestro (RF-07.2). */
const MASTER_DUTY_LEGENDS = Object.freeze({
  pending: 'Firmas en deliberación',
  retracted: 'Firmas retiradas',
  annulled: 'Firmas anuladas',
});

/**
 * Estampa temporal legible (plan §4.3): ISO 8601 → castellano.
 * Degradación noble ante estampa ausente o ilegible.
 *
 * @param {string|null} iso Estampa temporal ISO 8601.
 * @returns {string|null} Texto castellano, o null si no hay estampa.
 */
export function formatPanelTimestamp(iso) {
  if (typeof iso !== 'string' || iso.trim() === '') return null;
  const instant = new Date(iso);
  if (Number.isNaN(instant.getTime())) return null;
  return new Intl.DateTimeFormat('es-ES', { dateStyle: 'long' }).format(instant);
}

/**
 * Crea la vista «Mi morada».
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.panelClient Cliente del panel (Tarea 5.1):
 *        fetchPanel, fetchAvatarCatalog, chooseAvatar, removeAvatar,
 *        changePassphrase.
 * @param {(section: string) => void} [options.onRestrictedSectionActivated]
 *        Conducción del peregrino (Tarea 5.2, RF-01.3, plan §3.1): el
 *        orquestador recibe la sección activada y conduce a la ceremonia
 *        vía navigate('juramento'). La vista emite además el evento del
 *        bus `panel:restricted-section-activated` (plan §4.2).
 * @param {() => void} [options.onNavigateToClanManagement] Puerta a la
 *        cámara canónica del clan (Tarea 5.3, RF-08.2): el Vestíbulo
 *        de las Hermandades (SPEC-10). El panel solo conduce, jamás
 *        gestiona membresías.
 * @param {() => void} [options.onNavigateToTome] Puerta al Tomo (Tarea
 *        5.3, RF-08.2): «Mi Grimorio» (SPEC-11), cámara canónica de la
 *        colección personal.
 * @param {(reason: string) => void} [options.onRenounceRequested] Puerta
 *        a la renuncia de cuenta (Tarea 5.3, RF-08.2; exclusión 2):
 *        conduce a la superficie canónica de SPEC-03 RF-09 (endpoint
 *        renounce-account). El panel JAMÁS ejecuta la baja: solo muestra
 *        la puerta tras su confirmación solemne propia; el orquestador
 *        decide cómo cursar la superficie canónica.
 * @param {() => void} [options.onSessionExpired] Conducción al umbral
 *        ante 401 (RF-01.2): el shell reabre el diálogo de acceso.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus del plan §4 (por defecto,
 *        la raíz de montaje).
 * @returns {Object} API: { render, destroy, retry, activateSection }.
 */
export function createUserPanelView(mountRoot, options = {}) {
  const {
    panelClient,
    onRestrictedSectionActivated,
    onSessionExpired,
    onNavigateToClanManagement,
    onNavigateToTome,
    onRenounceRequested,
    documentRef = globalThis.document,
  } = options;

  // El default de la fábrica deriva del documento INYECTADO (los arneses
  // carecen de `globalThis.document`; lección de los arneses de SPEC-10).
  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));
  const eventTarget = options.eventTarget ?? mountRoot;

  let destroyed = false;

  /**
   * Forja un elemento con clase, texto y atributos en una sola voz.
   * @param {string} tagName Etiqueta nativa.
   * @param {object} [spec] { className, text, attrs }.
   * @returns {HTMLElement} El elemento forjado.
   */
  function forge(tagName, spec = {}) {
    const element = elementFactory(tagName);
    if (spec.className) element.className = spec.className;
    if (spec.text !== undefined) element.textContent = spec.text;
    for (const [name, value] of Object.entries(spec.attrs ?? {})) {
      element.setAttribute(name, String(value));
    }
    return element;
  }

  /**
   * La región viva única del panel (RNF-03, plan §4.3): por ella se
   * anuncian —con moderación, jamás el tictac— los hitos de
   * convalecencia, veredictos de la custodia y recibos de efigie que
   * montarán los componentes de FASE 6.
   */
  function forgeLiveRegion() {
    const region = forge('div', {
      className: 'panel-live-region',
      attrs: { 'aria-live': 'polite' },
    });
    return region;
  }

  /** Anuncia un mensaje por la región viva del panel (RNF-03, plan §4.3). */
  function announce(message) {
    const region = findLiveRegion();
    if (region) region.textContent = message;
  }

  /** Busca la región viva entre los descendientes de la raíz. */
  function findLiveRegion() {
    return findDescendant(mountRoot, (node) => node.getAttribute?.('aria-live') === 'polite');
  }

  /** Primer descendiente que satisface el predicado (DOM nativo y simulado). */
  function findDescendant(root, predicate) {
    for (const child of root?.children ?? []) {
      if (predicate(child)) return child;
      const found = findDescendant(child, predicate);
      if (found !== null) return found;
    }
    return null;
  }

  /** Emite un evento del bus del panel (plan §4.2). */
  function emit(type, detail) {
    const EventCtor = globalThis.CustomEvent
      ?? (eventTarget?.ownerDocument?.defaultView?.CustomEvent)
      ?? class { constructor(type_, options = {}) { this.type = type_; this.detail = options.detail ?? null; } };
    eventTarget.dispatchEvent(new EventCtor(type, { detail, bubbles: true }));
  }

  // -------------------------------------------------------------------
  // Secciones de la vitrina (cada una, una función autocontenida)
  // -------------------------------------------------------------------

  /** Sección de identidad: efigie, alias, correo y oficio (RF-02.1). */
  function identitySection(panel) {
    const identity = panel.identity ?? {};
    const section = forge('article', { className: 'panel-section panel-section--identity' });
    section.appendChild(forge('h2', { text: 'Identidad' }));

    const row = forge('dl', { className: 'panel-identity' });
    const alias = forge('dt', { text: 'Alias' });
    alias.appendChild(forge('dd', { text: identity.alias ?? '' }));
    const email = forge('dt', { text: 'Correo' });
    email.appendChild(forge('dd', { text: identity.email ?? '' }));
    const role = forge('dt', { text: 'Oficio' });
    const roleLabel = identity.roleLabel ?? 'Lector';
    role.appendChild(forge('dd', { text: roleLabel }));
    // RF-02.4: el oficio de moderación se anuncia también en el nombre
    // accesible de la sección (misma solemnidad que el distintivo).
    if (roleLabel === 'Maestro del Códice' || roleLabel === 'Admin Supremo') {
      section.setAttribute('aria-label', `Identidad — ${roleLabel}, facultado para moderar`);
    }
    row.appendChild(alias);
    row.appendChild(email);
    row.appendChild(role);
    section.appendChild(row);
    return section;
  }

  /** Sección de linaje: jurado, legado o pendiente del juramento (RF-02.1/02.3, RF-01.3). */
  function lineageSection(panel, isRestricted) {
    const lineage = panel.lineage ?? null;
    const section = forge('article', { className: 'panel-section panel-section--lineage' });
    section.appendChild(forge('h2', { text: 'Linaje' }));

    if (isRestricted || lineage === null) {
      // Peregrino: la sección se viste como PENDIENTE (RF-01.3, plan §3.1).
      const pending = forge('p', { className: 'panel-section__pending', text: PANEL_PILGRIM_LINEAGE_LABEL });
      section.appendChild(pending);
      return section;
    }

    const row = forge('dl', { className: 'panel-lineage' });
    const label = forge('dt', { text: 'Linaje jurado' });
    label.appendChild(forge('dd', { text: lineage.label ?? '' }));
    const sworn = forge('dt', { text: 'Juramento sellado' });
    sworn.appendChild(forge('dd', { text: formatPanelTimestamp(lineage.swornAt) ?? 'Estampa no disponible' }));
    row.appendChild(label);
    row.appendChild(sworn);
    section.appendChild(row);
    return section;
  }

  /** Sección de hermandad: clan activo, archivado o pendiente (RF-02.2). */
  function clanSection(panel, isRestricted) {
    const clan = panel.clan ?? null;
    const section = forge('article', { className: 'panel-section panel-section--clan' });
    section.appendChild(forge('h2', { text: 'Hermandad' }));

    if (isRestricted || clan === null) {
      const pending = forge('p', {
        className: 'panel-section__pending',
        text: clan === null && !isRestricted
          ? 'Sin hermandad: el Vestíbulo de las Hermandades te espera.'
          : 'Aguarda el juramento de linaje',
      });
      section.appendChild(pending);
      return section;
    }

    const row = forge('dl', { className: 'panel-clan' });
    const name = forge('dt', { text: 'Hermandad' });
    name.appendChild(forge('dd', { text: clan.name ?? '' }));
    const joined = forge('dt', { text: 'Ingreso' });
    joined.appendChild(forge('dd', { text: formatPanelTimestamp(clan.joinedAt) ?? 'Estampa no disponible' }));
    row.appendChild(name);
    row.appendChild(joined);
    section.appendChild(row);
    return section;
  }

  /** Sección del vínculo: dispositivo, nacimiento y expiración (RF-02.1). */
  function sessionSection(panel) {
    const session = panel.session ?? null;
    const section = forge('article', { className: 'panel-section panel-section--session' });
    section.appendChild(forge('h2', { text: 'Vínculo arcano' }));

    const row = forge('dl', { className: 'panel-session' });
    const device = forge('dt', { text: 'Dispositivo' });
    device.appendChild(forge('dd', { text: session?.deviceLabel ?? 'Morada sin declarar' }));
    const created = forge('dt', { text: 'Abierto' });
    created.appendChild(forge('dd', { text: formatPanelTimestamp(session?.createdAt) ?? 'Estampa no disponible' }));
    const expires = forge('dt', { text: 'Expira' });
    expires.appendChild(forge('dd', { text: formatPanelTimestamp(session?.expiresAt) ?? 'Estampa no disponible' }));
    row.appendChild(device);
    row.appendChild(created);
    row.appendChild(expires);
    section.appendChild(row);
    return section;
  }

  /** Sección de convalecencia o silencio noble (RF-05.1, RF-05.3). */
  function convalescenceSection(panel) {
    const convalescence = panel.convalescence ?? null;
    // RF-05.3: sin convalecencia no se monta sección fantasma alguna.
    if (convalescence === null) return null;

    const section = forge('article', { className: 'panel-section panel-section--convalescence' });
    section.appendChild(forge('h2', { text: 'Convalecencia Arcana' }));
    const days = forge('p', {
      className: 'panel-convalescence__days',
      text: `Alzamiento de tu penitencia en ${convalescence.daysRemaining ?? 0} día(s).`,
    });
    const cause = forge('p', { text: convalescence.causeLegend ?? '' });
    const retained = forge('p', { text: convalescence.retainedLegend ?? '' });
    section.appendChild(days);
    section.appendChild(cause);
    section.appendChild(retained);
    return section;
  }

  /** Sección de credenciales: la custodia de la frase (RF-04, plan §2.6). */
  function credentialsSection() {
    const section = forge('article', { className: 'panel-section panel-section--credentials' });
    section.appendChild(forge('h2', { text: 'Custodia de la frase de paso' }));
    // Tarea 6.2: el componente passphraseChangerComponent se monta aquí
    // como slot; la vista declara la cámara y el aviso solemne del acto.
    section.appendChild(forge('p', {
      className: 'panel-section__slot',
      text: 'La cámara de la custodia abre con el próximo sello de FASE 6.',
    }));
    return section;
  }

  /**
   * Sección de cámaras canónicas (Tarea 5.3, RF-08.2): las puertas que
   * CONDUCEN, jamás duplican la gestión. Tres enlaces: clan → Vestíbulo
   * (SPEC-10), tomo → Mi Grimorio (SPEC-11) y renuncia → confirmación
   * solemne propia + delegación en la superficie canónica de SPEC-03
   * RF-09.
   */
  function conductionsSection() {
    const section = forge('article', { className: 'panel-section panel-section--conductions' });
    section.appendChild(forge('h2', { text: 'Cámaras canónicas' }));

    const clanDoor = forge('button', {
      className: 'panel-cta panel-cta--clan',
      text: 'Gestionar tu hermandad en el Vestíbulo',
      attrs: { type: 'button' },
    });
    clanDoor.addEventListener('click', () => onNavigateToClanManagement?.());
    section.appendChild(clanDoor);

    const tomeDoor = forge('button', {
      className: 'panel-cta panel-cta--tome',
      text: 'Abrir tu Tomo en Mi Grimorio',
      attrs: { type: 'button' },
    });
    tomeDoor.addEventListener('click', () => onNavigateToTome?.());
    section.appendChild(tomeDoor);

    const renounceDoor = forge('button', {
      className: 'panel-cta panel-cta--renounce',
      text: 'Renunciar al Vínculo (cámara canónica)',
      attrs: { type: 'button' },
    });
    renounceDoor.addEventListener('click', () => { void requestRenounce(); });
    section.appendChild(renounceDoor);

    return section;
  }

  /** Sección de obras y deberes: tomo, homenajes, firmas y gloria (RF-07). */
  function worksSection(panel) {
    const section = forge('article', { className: 'panel-section panel-section--works' });
    section.appendChild(forge('h2', { text: 'Obras y deberes' }));

    const collection = panel.collection ?? { sealedCount: 0, praiseCount: 0 };
    const row = forge('dl', { className: 'panel-works' });
    const sealed = forge('dt', { text: COLLECTION_LEGENDS.sealed });
    sealed.appendChild(forge('dd', { text: String(collection.sealedCount ?? 0) }));
    const praise = forge('dt', { text: COLLECTION_LEGENDS.praise });
    praise.appendChild(forge('dd', { text: String(collection.praiseCount ?? 0) }));
    row.appendChild(sealed);
    row.appendChild(praise);

    const masterDuties = panel.masterDuties ?? null;
    if (masterDuties !== null) {
      const pending = forge('dt', { text: MASTER_DUTY_LEGENDS.pending });
      pending.appendChild(forge('dd', { text: String(masterDuties.pendingCount ?? 0) }));
      const retracted = forge('dt', { text: MASTER_DUTY_LEGENDS.retracted });
      retracted.appendChild(forge('dd', { text: String(masterDuties.retractedCount ?? 0) }));
      const annulled = forge('dt', { text: MASTER_DUTY_LEGENDS.annulled });
      annulled.appendChild(forge('dd', { text: String(masterDuties.annulledCount ?? 0) }));
      row.appendChild(pending);
      row.appendChild(retracted);
      row.appendChild(annulled);
    }
    section.appendChild(row);

    const weeklyGlory = panel.weeklyGlory ?? null;
    section.appendChild(forge('p', {
      className: 'panel-works__glory',
      // RF-07.3: sin cómputo vigente, leyenda canónica sin cifras fantasma.
      text: weeklyGlory === null
        ? GLORY_SILENT_LEGEND
        : `${weeklyGlory.weekLabel ?? ''} — ${weeklyGlory.points ?? 0} puntos de gloria acreditados.`,
    }));
    return section;
  }

  /** Sección de bitácora: la lente personal (RF-06, Tarea 6.3). */
  function ledgerSection() {
    const section = forge('article', { className: 'panel-section panel-section--ledger' });
    section.appendChild(forge('h2', { text: 'Bitácora personal' }));
    // Tarea 6.3: el componente personalLedgerComponent se monta aquí
    // como slot; la vista declara la cámara de la lente.
    section.appendChild(forge('p', {
      className: 'panel-section__slot',
      text: 'La lente de la bitácora abre con el próximo sello de FASE 6.',
    }));
    return section;
  }

  /**
   * Conducción del peregrino (Tarea 5.2, RF-01.3, plan §3.1): el
   * callback directo del orquestador (navigate('juramento')) y el
   * evento del bus para cualquier otro oyente (plan §4.2). El gesto es
   * inocuo si el callback no fue inyectado: el evento queda como
   * registro. ÚNICO canal para el clic y la activación programática.
   *
   * @param {string} sectionName Nombre canónico de la sección activada.
   */
  function requestRestrictedSection(sectionName) {
    onRestrictedSectionActivated?.(sectionName);
    emit(USER_PANEL_VIEW_EVENTS.restrictedSectionActivated, { section: sectionName });
  }

  /**
   * Diálogo de confirmación solemne propio de la vista (Tarea 5.3,
   * RF-08.2; patrón del panel de gobierno de SPEC-07: jamás
   * window.confirm). Forja un <dialog> nativo — el foco queda preso en
   * él mientras esté abierto — y al cerrarse DEVUELVE EL FOCO al
   * origen (Tarea 5.4, RNF-03, patrón del modal del juramento).
   *
   * @param {string} title   Rótulo solemne del diálogo.
   * @param {string} message Narración del acto que se dispone a cursar.
   * @param {string} confirmLabel  Rótulo del botón de confirmación.
   * @param {string} cancelLabel   Rótulo del botón de desistimiento.
   * @returns {Promise<{confirmed: boolean}>} El veredicto del adepto.
   */
  function forgeSolemnDialog(title, message, confirmLabel, cancelLabel) {
    // El origen del foco se recuerda ANTES de abrir: al cerrar vuelve a
    // él (RNF-03, plan §4.3). Con DOM simulado sin activeElement, el
    // gesto es inocuo.
    const focusOrigin = documentRef?.activeElement ?? null;
    const dialog = forge('dialog', { className: 'panel-dialog', attrs: { 'aria-label': title } });
    dialog.appendChild(forge('h2', { text: title }));
    dialog.appendChild(forge('p', { text: message }));

    return new Promise((resolve) => {
      let settled = false;
      /** Devuelve el foco a su origen tras el cierre (RNF-03). */
      function restoreFocus() {
        if (focusOrigin && typeof focusOrigin.focus === 'function') {
          focusOrigin.focus();
        }
      }

      /** Sella el veredicto una sola vez y retira el diálogo. */
      function settle(confirmed) {
        if (settled) return;
        settled = true;
        if (typeof dialog.close === 'function') dialog.close();
        dialog.remove?.();
        restoreFocus();
        resolve({ confirmed });
      }

      // Cualquier otra vía de cierre (teclado Escape en navegador real)
      // devuelve el foco igualmente, sin mutar el veredicto ya sellado.
      dialog.addEventListener?.('close', restoreFocus);

      const confirmButton = forge('button', {
        className: 'panel-dialog__confirm',
        text: confirmLabel,
        attrs: { type: 'button' },
      });
      confirmButton.addEventListener('click', () => settle(true));

      const cancelButton = forge('button', {
        className: 'panel-dialog__cancel',
        text: cancelLabel,
        attrs: { type: 'button' },
      });
      cancelButton.addEventListener('click', () => settle(false));

      dialog.appendChild(confirmButton);
      dialog.appendChild(cancelButton);
      mountRoot.appendChild(dialog);
      if (typeof dialog.showModal === 'function') dialog.showModal();
    });
  }

  /**
   * La puerta de la renuncia (Tarea 5.3, RF-08.2, exclusión 2): el
   * panel muestra la puerta, jamás la palanca — pide confirmación
   * solemne propia y DELEGA en el orquestador el curso a la superficie
   * canónica de SPEC-03 RF-09 (endpoint renounce-account).
   */
  async function requestRenounce() {
    const verdict = await forgeSolemnDialog(
      'Renuncia al Vínculo',
      'Al renunciar, tu identidad se purgará del santuario y tu legado vivirá anónimo. Este acto es irreversible: ¿deseas cursarlo en su cámara canónica?',
      'Cursar la renuncia en su cámara',
      'Desistir',
    );
    announce(verdict.confirmed
      ? 'La renuncia se cursa en su cámara canónica.'
      : 'Has desistido: tu vínculo permanece íntegro.');
    if (verdict.confirmed) {
      onRenounceRequested?.('RENOUNCE_CONFIRMED');
      emit(USER_PANEL_VIEW_EVENTS.renounceRequested, { reason: 'RENOUNCE_CONFIRMED' });
    }
  }

  /** Sección pendiente del peregrino (RF-01.3, plan §3.1). */
  function restrictedSection(sectionName) {
    const definition = PANEL_RESTRICTED_SECTIONS.find((item) => item.section === sectionName) ?? null;
    const section = forge('article', { className: `panel-section panel-section--restricted panel-section--${sectionName}` });
    section.appendChild(forge('h2', { text: definition?.label ?? sectionName }));
    section.appendChild(forge('p', {
      className: 'panel-section__pending',
      text: definition?.pendingLabel ?? 'Aguarda el juramento de linaje',
    }));
    // RF-01.3: la activación conduce a la ceremonia; el orquestador
    // escucha `panel:restricted-section-activated` (plan §4.2).
    const activate = forge('button', {
      className: 'panel-section__cta',
      text: 'Acudir a la ceremonia',
      attrs: { type: 'button', 'data-restricted-section': sectionName },
    });
    activate.addEventListener('click', () => {
      requestRestrictedSection(sectionName);
    });
    section.appendChild(activate);
    return section;
  }

  // -------------------------------------------------------------------
  // Render de la vitrina y estados solemnes
  // -------------------------------------------------------------------

  /** Pinta la vitrina completa según el sobre del DTO (plan §2.2). */
  function renderVitrina(panel) {
    const isRestricted = panel.avatarRestricted === true;
    const isSupremeAdmin = (panel.identity?.roleLabel ?? '') === 'Admin Supremo';

    mountRoot.appendChild(forge('h1', { text: USER_PANEL_VIEW_TITLE }));

    const sections = forge('div', { className: 'user-panel' });
    // La región viva encabeza la cámara para que todo anuncio posterior
    // (hitos, veredictos, recibos) viaje por el ÚNICO canal accesible.
    sections.appendChild(forgeLiveRegion());
    sections.appendChild(identitySection(panel));

    // Linaje: el Supremo fundacional (RF-01.5) no recibe sección fantasma
    // de linaje; el linajado la recibe viva; el peregrino, pendiente.
    if (isSupremeAdmin && panel.lineage === null) {
      sections.appendChild(forge('p', {
        className: 'panel-foundation-legend',
        text: 'Estado fundacional: el Admin Supremo antecede a los linajes.',
      }));
    } else {
      sections.appendChild(isRestricted ? restrictedSection('lineage') : lineageSection(panel, false));
    }

    // Hermandad: pendiente del peregrino, silencio noble sin clan (RF-02.2).
    sections.appendChild(isRestricted ? restrictedSection('clan') : clanSection(panel, false));

    sections.appendChild(sessionSection(panel));

    const convalescence = convalescenceSection(panel);
    if (convalescence !== null) sections.appendChild(convalescence);

    // Efigie: pendiente del peregrino (principio rector 3); para el
    // linajado, el avatarPickerComponent (Tarea 6.1) montará su slot.
    if (isRestricted) {
      sections.appendChild(restrictedSection('avatar'));
    } else {
      const avatarSection = forge('article', { className: 'panel-section panel-section--avatar' });
      avatarSection.appendChild(forge('h2', { text: 'Efigie' }));
      avatarSection.appendChild(forge('p', {
        className: 'panel-section__slot',
        text: 'La cámara de la efigie abre con el próximo sello de FASE 6.',
      }));
      sections.appendChild(avatarSection);
    }

    sections.appendChild(credentialsSection());

    // Las cámaras canónicas: solo para quien puede pisarlas (el
    // peregrino conduce desde sus secciones pendientes; jamás desde aquí).
    if (!isRestricted) {
      sections.appendChild(conductionsSection());
    }

    // Obras y deberes: pendiente del peregrino (RF-01.3), viva en el resto.
    sections.appendChild(isRestricted ? restrictedSection('works') : worksSection(panel));

    sections.appendChild(ledgerSection());
    mountRoot.appendChild(sections);
  }

  /** El umbral del anónimo (RF-01.2): aviso solemne y conducción. */
  function renderUnauthenticated() {
    mountRoot.appendChild(forge('h1', { text: USER_PANEL_VIEW_TITLE }));
    const notice = forge('section', { className: 'user-panel user-panel--expired' });
    notice.setAttribute('role', 'alert');
    notice.appendChild(forge('p', { text: USER_PANEL_EXPIRED_LEGEND }));
    const login = forge('button', {
      className: 'panel-cta panel-cta--login',
      text: 'Reabrir el umbral',
      attrs: { type: 'button' },
    });
    login.addEventListener('click', () => {
      announce('Reabriendo el umbral del santuario.');
      onSessionExpired?.();
    });
    notice.appendChild(login);
    mountRoot.appendChild(notice);
    announce(USER_PANEL_EXPIRED_LEGEND);
  }

  /** El velo arcano (caso límite 11): aviso con reintento solemne. */
  function renderUnavailable() {
    mountRoot.appendChild(forge('h1', { text: USER_PANEL_VIEW_TITLE }));
    const notice = forge('section', { className: 'user-panel user-panel--unavailable' });
    notice.appendChild(forge('p', {
      text: 'El panel no puede iluminarse en este instante: inténtalo de nuevo en breve.',
    }));
    const retry = forge('button', {
      className: 'panel-cta panel-cta--retry',
      text: USER_PANEL_RETRY_LABEL,
      attrs: { type: 'button' },
    });
    retry.addEventListener('click', () => { void retryLoad(); });
    notice.appendChild(retry);
    mountRoot.appendChild(notice);
    announce('El panel no puede iluminarse en este instante.');
  }

  /** Carga la vitrina y viste la cámara según el veredicto (plan §3.1). */
  async function loadVitrina() {
    const envelope = await panelClient.fetchPanel();
    if (destroyed) return;

    if (envelope?.success === true && envelope.data?.panel) {
      renderVitrina(envelope.data.panel);
      emit(USER_PANEL_VIEW_EVENTS.vitrinaLoaded, { avatarRestricted: envelope.data.panel.avatarRestricted === true });
      return;
    }

    if (envelope?.status === 401) {
      // RF-01.2: el anónimo queda retenido en el umbral; la vista declara
      // el aviso solemne y conduce, sin exponer traza técnica (RF-01.4).
      renderUnauthenticated();
      emit(USER_PANEL_VIEW_EVENTS.vitrinaFailed, { status: 401 });
      return;
    }

    // 500 PANEL_UNAVAILABLE y cualquier otro velo: reintento solemne
    // (caso límite 11: jamás ceros ni errores crudos).
    renderUnavailable();
    emit(USER_PANEL_VIEW_EVENTS.vitrinaFailed, { status: envelope?.status ?? 0 });
  }

  /** Reintento solemne: limpia y vuelve a intentar la carga (caso 11). */
  async function retryLoad() {
    if (typeof mountRoot.replaceChildren === 'function') {
      mountRoot.replaceChildren();
    } else {
      for (const child of [...(mountRoot.children ?? [])]) child.remove?.();
    }
    await loadVitrina();
  }

  return {
    /** Monta la vista y carga la vitrina en una sola carga (RNF-06). */
    async render() {
      await loadVitrina();
    },

    /** Retira la vista del punto de montaje y sus oyentes. */
    destroy() {
      destroyed = true;
      if (typeof mountRoot.replaceChildren === 'function') {
        mountRoot.replaceChildren();
      } else {
        for (const child of [...(mountRoot.children ?? [])]) child.remove?.();
      }
    },

    /** Reintento solemne expuesto (caso límite 11). */
    retry() {
      return retryLoad();
    },

    /**
     * Activación programática de una sección restringida (equivale al
     * gesto del botón; útil para arneses y conducción por teclado):
     * recorre el MISMO canal de conducción que el clic (Tarea 5.2).
     *
     * @param {string} sectionName Nombre canónico de la sección.
     */
    activateSection(sectionName) {
      requestRestrictedSection(String(sectionName ?? ''));
    },
  };
}

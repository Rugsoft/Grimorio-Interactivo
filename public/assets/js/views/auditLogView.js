/**
 * auditLogView.js — Vista pública de la Bitácora de Auditoría Arcana.
 *
 * Tarea 5.1 (TASKS-03): tabla paginada de decisiones de moderación con
 * sellos de clan, identidades de maestros y motivos solemnes de cada
 * veredicto, alimentada por fetchAuditLog() del authClient (Tarea 4.1).
 *
 * Criterio de la tarea: cualquier usuario puede consultar la bitácora
 * pública, filtrar por clan y leer las justificaciones de cada
 * validación o veto — sin credenciales, sin distinción de escalafón
 * (Artículo III: transparencia igualitaria).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, DOM estándar; innerHTML PROHIBIDO
 *     (AGENTS.md 6.1): todo dato del backend viaja como textContent.
 *   - Artículo III: los motivos solemnes se muestran íntegros y sin
 *     edición — la transparencia no admite resúmenes sesgados.
 *   - Artículo IV (El Velo Arcano): leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Diseño (plan 4.1): la vista delega SIEMPRE en el cliente inyectado y
 * notifica al orquestador; la paginación es incremental (anterior/
 * siguiente) con los metadatos page/limit/totalItems/totalPages del
 * Endpoint 6. El fallo de red degrada a un estado de rescate con reintento.
 */

/** Límite de la página por defecto (plan Endpoint 6). */
const DEFAULT_PAGE_LIMIT = 25;

/**
 * Crea la vista de la bitácora de auditoría.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.auditClient Cliente HTTP con fetchAuditLog(params)
 *        (authClient, Tarea 4.1; inyectable para pruebas).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable.
 * @param {(viewName: string) => void} [options.onNavigate] Navegación (rescate → biblioteca).
 * @returns {Object} API: { render, destroy, setClanFilter, refresh }.
 */
export function createAuditLogView(mountRoot, options = {}) {
  const {
    auditClient,
    elementFactory = (tagName) => document.createElement(tagName),
    onNavigate,
  } = options;

  /** Página y filtro vivos de la vista (fuente de las peticiones). */
  let currentPage = 1;
  let currentClanId = null;

  /** Última página de metadatos recibida (paginación). */
  let lastPagination = null;

  /** Guardia anti-carreras: solo la última petición pinta la tabla. */
  let fetchSequence = 0;

  /** Nodos vivos para limpieza determinista en destroy(). */
  const mountedNodes = [];
  function track(node) {
    mountedNodes.push(node);
    return node;
  }

  /** Añade un elemento de texto seguro (nunca innerHTML, AGENTS.md 6.1). */
  function appendTextElement(parent, tagName, className, text) {
    const node = elementFactory(tagName);
    node.className = className;
    node.textContent = text;
    parent.appendChild(node);
    return track(node);
  }

  /**
   * Etiqueta solemne de cada acción canónica (Art. IV).
   *
   * El mapa ha de cubrir TODO el catálogo de la bitácora: un acto sin etiqueta
   * se imprimiría en la lengua del código (MODERATION_SUBMITTED), y la
   * Bitácora es pública —la lee cualquiera—, de modo que dejar caer un
   * identificador técnico sobre el pergamino sería una grieta del Velo Arcano.
   * Las acciones de gobierno de linajes y del Dominio Semanal (SPEC-07) y las
   * de la moderación en dos pasos (SPEC-08) están aquí con su nombre en
   * castellano.
   */
  function actionLabel(actionType) {
    const labels = {
      SIGN_VALIDATE: 'Firma de Validación',
      SIGN_REJECT: 'Firma de Rechazo',
      ADMIN_VETO: 'Veto del Admin Supremo',
      PROMOTE_MASTER: 'Ascenso a Maestro',
      DEMOTE_MASTER: 'Degradación de Maestro',
      CLAN_MODIFY: 'Sello de Linaje',
      // Actos de gobierno de hermandades (SPEC-07).
      CLAN_FOUNDED: 'Fundación de Linaje',
      CLAN_MEMBER_LEFT: 'Partida de un Adepto',
      CLAN_MEMBER_EXPELLED: 'Expulsión de un Adepto',
      PATRIARCH_TRANSFERRED: 'Traspaso de la Corona',
      PATRIARCH_INACTIVITY_SUCCESSION: 'Sucesión por Inactividad',
      CLAN_ARCHIVED_BY_PATRIARCH: 'Disolución del Linaje',
      CLAN_ARCHIVED_EMPTY_SUCCESSION: 'Disolución por Orfandad',
      DOMINION_WEEK_CONCLUDED: 'Cierre de la Semana Arcana',
      ACC_LINK_RENOUNCED: 'Renuncia al Vínculo de la Cuenta',
      RESET_SIGNATURES_MATH_CHANGE: 'Reinicio de Firmas por Enmienda Matemática',
      UPDATE_DESCRIPTION_INTACT_SIGNATURES: 'Enmienda del Pergamino con Firmas Intactas',
      CREATE_VARIANT_FROM_VALIDATED: 'Variante de una Obra Consagrada',
      // Actos de la moderación solemne en dos pasos (SPEC-08).
      MODERATION_SUBMITTED: 'Elevación a Deliberación Arcana',
      MODERATION_WITHDRAWN: 'Retiro a la Libreta del Autor',
      MODERATION_REOPENED: 'Reapertura como Borrador',
      SIGNATURE_RETRACTED: 'Retractación de una Firma',
      SIGNATURE_ANNULMENT: 'Anulación de Oficio de una Firma',
      SPELL_CONSECRATED: 'Consagración por Tercera Firma',
      MODERATION_EXPIRED: 'Caducidad por Letargo Colegiado',
      SOVEREIGN_VALIDATION: 'Firma Soberana del Cónclave',
      SOVEREIGN_RESCUE: 'Rescate Soberano de una Obra',
      SOVEREIGN_ARCHIVE: 'Destierro Soberano del Canon',
      SOVEREIGN_POINTS_DEDUCTED: 'Deducción Retroactiva de Gloria',
      // Actos del Juramento de Linaje (SPEC-09): la identidad arcana sellada
      // en la ceremonia del primer acceso queda inscrita en la Bitácora.
      LINEAGE_OATH_SWORN: 'Juramento de Linaje sellado',
      // Actos del Vestíbulo de las Hermandades (SPEC-10): los actos del
      // postulante (ingreso, remisión, retirada y residuales anuladas) se
      // leen con su nombre propio; el dictamen queda en el lado deliberante
      // del reparto por actor.
      CLAN_MEMBER_JOINED: 'Ingreso en una Hermandad',
      CLAN_APPLICATION_SUBMITTED: 'Remisión de una Petición de Ingreso',
      CLAN_APPLICATION_WITHDRAWN: 'Retirada de una Petición de Ingreso',
      CLAN_APPLICATION_VERDICT: 'Dictamen sobre una Petición de Ingreso',
      CLAN_APPLICATION_RESIDUALS_ANNULLED: 'Anulación de Peticiones Huérfanas',
      // Actos del Tomo Personal (SPEC-11): el sellado íntimo y el elogio
      // que mueve gloria se leen con su nombre propio (plan §2.3).
      TOME_SEAL: 'Sellado en el Tomo Personal',
      TOME_PRAISE: 'Elogio con Gloria Acreditada',
    };
    return labels[actionType] ?? String(actionType);
  }

  /**
   * Rótulo en noble castellano del rol del actuante (Art. V: los roles
   * técnicos del DTO permanecen en inglés; la bitácora los declara en
   * la lengua del santuario).
   */
  function roleLabel(actorRole) {
    const labels = {
      lector: 'Lector del Tomo',
      editor: 'Editor Arcano',
      master: 'Maestro del Cónclave',
      supremeAdmin: 'Admin Supremo',
      system: 'Custodio Automático del Santuario',
    };
    return labels[actorRole] ?? String(actorRole);
  }

  /** Etiqueta de la entidad objetivo. */
  function targetLabel(entityType) {
    const labels = {
      spell: 'Conjuro',
      clan: 'Linaje',
      user: 'Iniciado',
    };
    return labels[entityType] ?? String(entityType);
  }

  /**
   * Solicita la página actual (con filtro de clan si lo hay) y pinta la
   * tabla. Guardia anti-carreras: la respuesta obsoleta se descarta.
   */
  async function fetchAndRender() {
    const requestSequence = ++fetchSequence;
    const requestParams = { page: currentPage, limit: DEFAULT_PAGE_LIMIT };
    if (currentClanId !== null && currentClanId !== '') {
      requestParams.clanId = currentClanId;
    }

    const result = await auditClient.fetchAuditLog(requestParams);

    // Respuesta obsoleta (el usuario ya pidió otra página/filtro): descartada.
    if (requestSequence !== fetchSequence) return;

    if (!result.success) {
      renderErrorState(result.error ?? {});
      return;
    }

    lastPagination = result.data?.pagination ?? null;
    renderTable(result.data?.items ?? []);
  }

  /**
   * Pinta (o repinta) la tabla de veredictos con sus controles.
   *
   * @param {Array<object>} entries Ítems del Endpoint 6.
   */
  function renderTable(entries) {
    // Re-render completo pero en memoria y con textContent (sin innerHTML).
    for (const node of mountedNodes.splice(0)) {
      node.remove();
    }

    const section = track(elementFactory('section'));
    section.className = 'audit-log';
    section.setAttribute('aria-label', 'Bitácora de Auditoría Arcana');
    mountRoot.appendChild(section);

    appendTextElement(section, 'h2', 'audit-log__title', 'Bitácora de Auditoría Arcana');
    appendTextElement(section, 'p', 'audit-log__hint', 'Registro inmutable de los veredictos del santuario: cada firma, veto y sello de linaje queda aquí expuesto con su motivo solemne (Artículo III).');

    // --- Filtro de clan (sellos de linaje, RF-08.2) ---
    const clanFilterInput = track(elementFactory('input'));
    clanFilterInput.className = 'audit-log__clan-filter';
    clanFilterInput.setAttribute('id', 'auditClanFilter');
    clanFilterInput.setAttribute('type', 'text');
    clanFilterInput.setAttribute('name', 'clanId');
    clanFilterInput.setAttribute('placeholder', 'Filtrar por linaje del santuario');
    clanFilterInput.setAttribute('aria-label', 'Filtrar la bitácora por linaje');
    // Conserva el valor entre re-renders (el filtro es estado de la vista).
    if (currentClanId !== null) {
      clanFilterInput.value = currentClanId;
    }
    section.appendChild(clanFilterInput);

    const applyFilterButton = track(elementFactory('button'));
    applyFilterButton.className = 'audit-log__apply-filter';
    applyFilterButton.setAttribute('type', 'button');
    applyFilterButton.setAttribute('id', 'auditApplyFilter');
    applyFilterButton.textContent = 'Aplicar el filtro de linaje';
    applyFilterButton.addEventListener('click', () => {
      const electedClan = String(clanFilterInput.value ?? '').trim();
      setClanFilter(electedClan === '' ? null : electedClan);
    });
    section.appendChild(applyFilterButton);

    // --- Tabla de veredictos (identidades, acciones, objetivos, motivos) ---
    if (entries.length === 0) {
      appendTextElement(section, 'p', 'audit-log__empty', 'La bitácora no guarda veredictos para esta consulta todavía.');
    } else {
      const table = track(elementFactory('table'));
      table.className = 'audit-log__table';
      section.appendChild(table);

      const tableHead = track(elementFactory('thead'));
      table.appendChild(tableHead);
      const headRow = track(elementFactory('tr'));
      tableHead.appendChild(headRow);
      for (const headerLabel of ['Actuante', 'Rol', 'Veredicto', 'Objetivo', 'Motivo solemne', 'Estampa']) {
        const headCell = track(elementFactory('th'));
        headCell.setAttribute('scope', 'col');
        headCell.textContent = headerLabel;
        headRow.appendChild(headCell);
      }

      const tableBody = track(elementFactory('tbody'));
      tableBody.className = 'audit-log__body';
      table.appendChild(tableBody);

      for (const entry of entries) {
        const bodyRow = track(elementFactory('tr'));
        tableBody.appendChild(bodyRow);

        // Identidad del actuante (alias público en el instante de la acción).
        appendTextElement(bodyRow, 'td', 'audit-log__actor', String(entry.actorAlias ?? 'Anónimo del pasado'));
        // Rol técnico activo, declarado en noble castellano.
        appendTextElement(bodyRow, 'td', 'audit-log__role', roleLabel(entry.actorRole ?? ''));
        // Acción canónica: rótulo solemne en castellano. El código
        // técnico viaja como atributo data para la trazabilidad con el
        // catálogo del backend sin mancillar la lengua visible (Art. V).
        const actionCell = track(elementFactory('td'));
        actionCell.className = 'audit-log__action';
        actionCell.textContent = actionLabel(entry.actionType);
        actionCell.setAttribute('data-action-type', String(entry.actionType ?? ''));
        bodyRow.appendChild(actionCell);
        // Objetivo: sello de entidad + identificador.
        appendTextElement(
          bodyRow,
          'td',
          'audit-log__target',
          `${targetLabel(entry.targetEntityType)} — ${String(entry.targetEntityId ?? '')}`,
        );
        // MOTIVO SOLEMNE íntegro (Art. III: sin recortes ni ediciones).
        appendTextElement(bodyRow, 'td', 'audit-log__justification', String(entry.justification ?? ''));
        // Estampa temporal UTC.
        appendTextElement(bodyRow, 'td', 'audit-log__created-at', String(entry.createdAt ?? ''));
      }
    }

    // --- Controles de paginación (metadatos del plan Endpoint 6) ---
    const paginationNav = track(elementFactory('nav'));
    paginationNav.className = 'audit-log__pagination';
    paginationNav.setAttribute('aria-label', 'Paginación de la bitácora');
    section.appendChild(paginationNav);

    const previousButton = track(elementFactory('button'));
    previousButton.className = 'audit-log__page-previous';
    previousButton.setAttribute('id', 'auditPreviousPage');
    previousButton.setAttribute('type', 'button');
    previousButton.textContent = 'Anterior';
    previousButton.disabled = currentPage <= 1;
    previousButton.addEventListener('click', () => {
      if (currentPage > 1) {
        currentPage -= 1;
        fetchAndRender();
      }
    });
    paginationNav.appendChild(previousButton);

    const pageInfo = lastPagination ?? { page: currentPage, totalPages: 1 };
    appendTextElement(
      paginationNav,
      'span',
      'audit-log__page-status',
      `Página ${pageInfo.page} de ${pageInfo.totalPages}`,
    );

    const nextButton = track(elementFactory('button'));
    nextButton.className = 'audit-log__page-next';
    nextButton.setAttribute('id', 'auditNextPage');
    nextButton.setAttribute('type', 'button');
    nextButton.textContent = 'Siguiente';
    nextButton.disabled = lastPagination !== null && currentPage >= (lastPagination.totalPages ?? 1);
    nextButton.addEventListener('click', () => {
      if (lastPagination === null || currentPage < (lastPagination.totalPages ?? 1)) {
        currentPage += 1;
        fetchAndRender();
      }
    });
    paginationNav.appendChild(nextButton);
  }

  /**
   * Estado de rescate ante fallo de corriente (contrato del plan 2.4):
   * leyenda del error y reintento (recoveryAction RETRY).
   *
   * @param {object} errorEnvelope Error del sobre estándar.
   */
  function renderErrorState(errorEnvelope) {
    for (const node of mountedNodes.splice(0)) {
      node.remove();
    }

    const section = track(elementFactory('section'));
    section.className = 'audit-log audit-log--error';
    mountRoot.appendChild(section);

    appendTextElement(section, 'h2', 'audit-log__title', 'La bitácora está fuera del alcance');
    appendTextElement(
      section,
      'p',
      'audit-log__error',
      typeof errorEnvelope.message === 'string' && errorEnvelope.message !== ''
        ? errorEnvelope.message
        : 'La corriente de maná se ha interrumpido.',
    );

    const retryButton = track(elementFactory('button'));
    retryButton.className = 'audit-log__retry';
    retryButton.setAttribute('id', 'auditRetryButton');
    retryButton.setAttribute('type', 'button');
    retryButton.textContent = 'Reintentar la consulta';
    retryButton.addEventListener('click', () => {
      fetchAndRender();
    });
    section.appendChild(retryButton);
  }

  /**
   * CRITERIO T5.1: acota la bitácora al linaje electo (RF-08.2).
   * El parámetro clanId viaja al Endpoint 6, que devuelve únicamente los
   * veredictos cuyo objetivo es ese linaje (sellos CLAN_MODIFY y demás).
   * Retorna la promesa de la consulta para verificabilidad determinista.
   *
   * @param {string|null} clanId Identificador del linaje (null = sin filtro).
   * @returns {Promise<void>} Resuelve cuando la tabla ya está repintada.
   */
  function setClanFilter(clanId) {
    currentClanId = clanId !== null && clanId !== '' ? String(clanId) : null;
    currentPage = 1; // Un filtro nuevo empieza una lectura nueva.
    return fetchAndRender();
  }

  /** Reejecuta la consulta actual (reintento del orquestador). */
  function refresh() {
    return fetchAndRender();
  }

  /**
   * Monta la vista: primera consulta pública de la bitácora.
   * No exige sesión ni consulta el estado de autenticación (Art. III).
   */
  async function render() {
    await fetchAndRender();
  }

  /** Desmonta la vista dejando el punto de montaje limpio (RNF-05). */
  function destroy() {
    for (const node of mountedNodes.splice(0)) {
      node.remove();
    }
    fetchSequence += 1; // Invalida respuestas en vuelo.
  }

  return {
    render,
    destroy,
    setClanFilter,
    refresh,
  };
}

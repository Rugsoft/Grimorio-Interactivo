/**
 * petitionInventoryComponent.js — Inventario consolidado de peticiones del
 * Vestíbulo de las Hermandades (SPEC-10, Tarea 5.4).
 *
 * RF-03.8: apéndice solemne «Tus peticiones pendientes: N de 3» que lista
 *          cada solicitud del adepto con su casa y estado (pendiente,
 *          dictamen recibido), con retirada directa desde la lista; el
 *          límite de 3 y la clausura por casa (RF-03.1) permanecen
 *          visibles en él.
 * RF-03.3: cada petición pendiente expone la retirada directa, que delega
 *          en la vista orquestadora (Tarea 5.5) — este componente jamás
 *          consume la API (Artículo II).
 * RF-03.4: los veredictos terminales SIN leer (`verdictSeen === false`)
 *          nacen marcados para que la vista los contemple al mostrarlos
 *          (acknowledge) y apague el rótulo del acceso (plan §3.5).
 *
 * La actualización «sin recarga» del «Hecho cuando» corre por dos vías:
 * `removePetition(applicationId)` tras una retirada consumada y
 * `setPetitions(petitions)` tras cualquier rehidratación del sobre.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo; `textContent` puro,
 *     `innerHTML` PROHIBIDO (AGENTS.md 6.1). Cero dependencias.
 *   - Artículo IV (El Velo Arcano): rótulos y leyendas en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * @module components/petitionInventoryComponent
 */

/** Rótulo canónico del apéndice (Anexo A 10): se interpola N y el límite. */
export const PETITION_INVENTORY_TITLE_PATTERN = 'Tus peticiones pendientes: {count} de {limit}.';

/** Leyenda solemne que hace visible la clausura por casa (RF-03.1, RF-03.8). */
export const PETITION_INVENTORY_CLOSURE_LEGEND =
  'Una petición rechazada o retirada clausura la casa para nuevas peticiones de tu cuenta.';

/** Rótulos castellanos de cada estado canónico del inventario. */
export const PETITION_INVENTORY_STATUS_LABELS = Object.freeze({
  pending: 'Pendiente de dictamen',
  approved: 'Dictamen favorable',
  rejected: 'Dictamen desfavorable',
  cancelled: 'Retirada',
});

/** Marca del veredicto terminal sin contemplar (RF-03.4). */
export const PETITION_INVENTORY_UNREAD_LABEL = 'Dictamen a la espera de lectura';

/** Límite canónico de peticiones simultáneas (SPEC-07, heredado). */
export const PETITION_INVENTORY_LIMIT = 3;

/**
 * Compone el título canónico del apéndice con el recuento vivo.
 *
 * @param {number} count Peticiones listadas.
 * @param {number} [limit] Límite canónico (3).
 * @returns {string} «Tus peticiones pendientes: N de 3.»
 */
export function formatPetitionInventoryTitle(count, limit = PETITION_INVENTORY_LIMIT) {
  return PETITION_INVENTORY_TITLE_PATTERN
    .replace('{count}', String(Number(count) || 0))
    .replace('{limit}', String(Number(limit) || PETITION_INVENTORY_LIMIT));
}

/**
 * Crea el inventario consolidado de peticiones del adepto.
 *
 * @param {Array<object>} petitions Array `petitions[]` del sobre (plan §2.2,
 *   ClanPetitionDto serializado): { applicationId, clanId, clanName, status,
 *   motivation, verdictMotive, verdictSeen, createdAt }.
 * @param {object} componentOptions Opciones:
 *   - onWithdraw(applicationId): retirada directa desde la lista (RF-03.3).
 *     La vista orquestadora consumirá el Endpoint 3 y devolverá la mudanza
 *     por `removePetition` o `setPetitions` — jamás este componente.
 *   - limit: límite canónico visible (por defecto 3).
 *   - elementFactory: fábrica de elementos (arneses sin navegador).
 * @returns {object} API: { element, removePetition, setPetitions, destroy }.
 */
export function createPetitionInventoryComponent(petitions, componentOptions = {}) {
  const {
    onWithdraw,
    limit = PETITION_INVENTORY_LIMIT,
    documentRef = globalThis.document,
  } = componentOptions;

  // El default de la fábrica deriva del documento INYECTADO (los arneses
  // carecen de `globalThis.document`; hallazgo del arnés de la Tarea 5.3).
  const elementFactory = componentOptions.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));

  /** Estado vivo del inventario (fuente de los repintados sin recarga). */
  let currentPetitions = Array.isArray(petitions) ? petitions.slice() : [];

  // ---------------------------------------------------------------
  // Raíz del apéndice: <section> con región viva (RNF-03).
  // ---------------------------------------------------------------
  const rootElement = elementFactory('section');
  rootElement.className = 'petition-inventory';
  rootElement.setAttribute('aria-label', 'Inventario de tus peticiones de ingreso');

  const title = elementFactory('h3');
  title.className = 'petition-inventory__title';
  rootElement.appendChild(title);

  const closureLegend = elementFactory('p');
  closureLegend.className = 'petition-inventory__closure';
  closureLegend.textContent = PETITION_INVENTORY_CLOSURE_LEGEND;
  rootElement.appendChild(closureLegend);

  /** Cuerpo de la lista: se repinta entero en cada mudanza. */
  const listElement = elementFactory('ul');
  listElement.className = 'petition-inventory__list';
  rootElement.appendChild(listElement);

  /** Repinta el apéndice íntegro desde el estado vivo (sin recarga). */
  function render() {
    title.textContent = formatPetitionInventoryTitle(currentPetitions.length, limit);

    // Limpieza nativa sin innerHTML (Artículo I): vaciado por longitud,
    // robusto también en DOM simulados sin firstChild (arneses).
    while (listElement.children.length > 0) {
      listElement.removeChild(listElement.children[0]);
    }

    if (currentPetitions.length === 0) {
      const emptyItem = elementFactory('li');
      emptyItem.className = 'petition-inventory__empty';
      emptyItem.textContent = 'No tienes peticiones a la espera.';
      listElement.appendChild(emptyItem);
      return;
    }

    for (const petition of currentPetitions) {
      listElement.appendChild(renderPetitionItem(petition));
    }
  }

  /**
   * Forja la entrada de una petición: casa, estado, marca de veredicto sin
   * leer, motivo del dictamen y retirada directa si aún está pendiente.
   */
  function renderPetitionItem(petition) {
    const item = elementFactory('li');
    item.className = 'petition-inventory__item';
    item.setAttribute('data-application-id', String(petition?.applicationId ?? ''));
    item.setAttribute('data-status', String(petition?.status ?? 'pending'));

    // Veredicto terminal sin contemplar: marca solemne (RF-03.4).
    const status = String(petition?.status ?? 'pending');
    const isTerminal = status !== 'pending';
    const isUnread = isTerminal && petition?.verdictSeen !== true;
    if (isUnread) {
      item.classList.add('petition-inventory__item--unread');
    }

    const houseName = elementFactory('span');
    houseName.className = 'petition-inventory__house';
    houseName.textContent = String(petition?.clanName ?? 'Casa sin nombre');
    item.appendChild(houseName);

    const statusBadge = elementFactory('span');
    statusBadge.className = 'petition-inventory__status';
    statusBadge.textContent = PETITION_INVENTORY_STATUS_LABELS[status] ?? status;
    item.appendChild(statusBadge);

    if (isUnread) {
      const unreadBadge = elementFactory('span');
      unreadBadge.className = 'petition-inventory__unread';
      unreadBadge.setAttribute('role', 'status');
      unreadBadge.textContent = PETITION_INVENTORY_UNREAD_LABEL;
      item.appendChild(unreadBadge);
    }

    // Motivo solemne del dictamen desfavorable (Artículo III.3 de SPEC-07).
    if (status === 'rejected' && typeof petition?.verdictMotive === 'string' && petition.verdictMotive !== '') {
      const motive = elementFactory('p');
      motive.className = 'petition-inventory__motive';
      motive.textContent = petition.verdictMotive;
      item.appendChild(motive);
    }

    // Retirada directa SOLO mientras aguarda dictamen (RF-03.3); los
    // estados terminales carecen de gesto — la fila queda como memoria.
    if (status === 'pending' && typeof onWithdraw === 'function') {
      const withdrawButton = elementFactory('button');
      withdrawButton.type = 'button';
      withdrawButton.className = 'petition-inventory__withdraw';
      withdrawButton.textContent = 'Retirar';
      const applicationId = String(petition?.applicationId ?? '');
      withdrawButton.addEventListener('click', () => {
        onWithdraw(applicationId);
      });
      item.appendChild(withdrawButton);
    }

    return item;
  }

  /**
   * Retirada consumada: elimina la petición del estado vivo y repinta
   * (el «Hecho cuando»: tarjeta e inventario actualizan sin recarga —
   * la tarjeta corre a cargo de la vista orquestadora).
   *
   * @param {string} applicationId Identificador de la petición retirada.
   * @returns {boolean} true si el inventario cambió.
   */
  function removePetition(applicationId) {
    const previousLength = currentPetitions.length;
    const targetId = String(applicationId ?? '');
    currentPetitions = currentPetitions.filter(
      (petition) => String(petition?.applicationId ?? '') !== targetId,
    );
    if (currentPetitions.length === previousLength) return false;
    render();
    return true;
  }

  /**
   * Rehidratación íntegra del inventario (nuevo sobre del Endpoint 1,
   * veredictos contemplados, anulaciones de oficio…).
   *
   * @param {Array<object>} nextPetitions Nuevo array `petitions[]`.
   */
  function setPetitions(nextPetitions) {
    currentPetitions = Array.isArray(nextPetitions) ? nextPetitions.slice() : [];
    render();
  }

  function destroy() {
    if (typeof rootElement.remove === 'function') {
      rootElement.remove();
    }
  }

  render();

  return { element: rootElement, removePetition, setPetitions, destroy };
}

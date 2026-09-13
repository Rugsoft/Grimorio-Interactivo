/**
 * spellCreatorView.js — Vista principal del Taller de Hechizos.
 *
 * Tarea 5.5 (TASKS-04): orquesta el taller a dos zonas — formulario de
 * forja (spellFormControls, Tarea 5.4) y panel de desglose pedagógico
 * (manaBreakdownComponent, Tarea 5.3) — con el simulador en cliente
 * (spellBalanceSimulator, Tarea 5.1) para el coste EN VIVO, y el cajón
 * de borradores privados con acciones de carga, retirada, guardado y
 * publicación (spellCreatorClient, Tarea 5.2).
 *
 * RF-04.1: el desglose fluctúa en vivo con cada pulsación (< 50 ms).
 * RF-05.1: cajón con la lista privada de borradores (máx. 10, backend).
 * RF-05.2: publicación a moderación (experimental, 0/3 firmas).
 * RF-06.1: restauración de borradores tras refrescar la página.
 *
 * Respaldo volátil (plan 5.5): el estado del formulario persiste en
 * localStorage ante pérdida de red y se restaura al recargar. Es SOLO
 * copia de trabajo: la palabra final sobre el coste la tiene SIEMPRE el
 * backend (Art. II).
 *
 * Seguridad (AGENTS.md 6.1): TODO dato viaja vía textContent — jamás
 * innerHTML. Los nombres de conjuros de usuarios no pueden inyectar markup.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo II: previsualización en cliente; cálculo ciego en servidor.
 *   - Artículo IV/V: solemnidad castellana; identificadores camelCase.
 */

import { createSpellFormControls } from '../components/spellFormControls.js';
import { createManaBreakdownComponent } from '../components/manaBreakdownComponent.js';
import { simulateMana } from '../utils/spellBalanceSimulator.js';

/** Clave del respaldo volátil del formulario en localStorage. */
const STORAGE_KEY = 'grimorio.spellCreatorDraft';

/**
 * Crea la vista del Taller de Hechizos.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (`<main id="app">`).
 * @param {Object} options
 * @param {Object} options.spellCreatorClient Cliente HTTP (Tarea 5.2).
 * @param {Storage|null} [options.localStorage] Almacén volátil (inyectable en pruebas).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory] Fábrica inyectable (tests).
 * @returns {Object} API: { render, destroy, refreshDrafts }.
 */
export function createSpellCreatorView(mountRoot, options) {
  const {
    spellCreatorClient,
    localStorage = (typeof window !== 'undefined' && window.localStorage) || null,
    elementFactory = (tagName) => document.createElement(tagName),
  } = options;

  /** Cliente HTTP vivo del Taller. */
  const client = spellCreatorClient;

  /** Borrador actualmente cargado en la zona de forja (spl_* o null). */
  let loadedDraftId = null;

  /** Construcción de nodos segura (textContent, jamás innerHTML). */
  function createTextElement(tagName, className, safeText) {
    const element = elementFactory(tagName);
    element.className = className;
    element.textContent = safeText;
    return element;
  }

  // -----------------------------------------------------------------
  // Construcción de la estructura de la vista.
  // -----------------------------------------------------------------
  const root = elementFactory('section');
  root.className = 'spell-creator';

  const title = createTextElement('h2', 'spell-creator__title', 'Taller de Hechizos');
  root.appendChild(title);

  // Zona de forja: nombre + descripción + controles del formulario.
  const forgeSection = elementFactory('section');
  forgeSection.className = 'spell-creator__forge';

  const nameLabel = createTextElement('label', 'spell-creator__label', 'Nombre del conjuro');
  nameLabel.setAttribute('for', 'spell-creator-name');
  const nameInput = elementFactory('input');
  nameInput.type = 'text';
  nameInput.name = 'name';
  nameInput.className = 'spell-creator__name';
  nameInput.setAttribute('id', 'spell-creator-name');
  forgeSection.appendChild(nameLabel);
  forgeSection.appendChild(nameInput);

  const descriptionLabel = createTextElement('label', 'spell-creator__label', 'Descripción narrativa');
  descriptionLabel.setAttribute('for', 'spell-creator-description');
  const descriptionInput = elementFactory('textarea');
  descriptionInput.className = 'spell-creator__description';
  descriptionInput.setAttribute('id', 'spell-creator-description');
  forgeSection.appendChild(descriptionLabel);
  forgeSection.appendChild(descriptionInput);

  // Controles matemáticos (Tarea 5.4) montados dentro de la forja.
  const formControls = createSpellFormControls({ elementFactory });
  forgeSection.appendChild(formControls.root);

  // Acciones del taller.
  const actionsRow = elementFactory('div');
  actionsRow.className = 'spell-creator__actions';

  const saveButton = elementFactory('button');
  saveButton.type = 'button';
  saveButton.className = 'spell-creator__save';
  saveButton.textContent = 'Guardar Borrador';
  actionsRow.appendChild(saveButton);

  const publishButton = elementFactory('button');
  publishButton.type = 'button';
  publishButton.className = 'spell-creator__publish';
  publishButton.textContent = 'Publicar para moderación';
  actionsRow.appendChild(publishButton);

  const statusLine = createTextElement('p', 'spell-creator__status', '');
  actionsRow.appendChild(statusLine);
  forgeSection.appendChild(actionsRow);
  root.appendChild(forgeSection);

  // Panel de desglose pedagógico (Tarea 5.3), pegajoso en escritorio.
  const breakdown = createManaBreakdownComponent({ elementFactory });
  root.appendChild(breakdown.root);

  // Cajón de borradores privados.
  const draftsSection = elementFactory('section');
  draftsSection.className = 'spell-creator__drafts';
  const draftsTitle = createTextElement('h3', 'spell-creator__drafts-title', 'Mis borradores (máx. 10)');
  draftsSection.appendChild(draftsTitle);
  const draftsList = elementFactory('ul');
  draftsList.className = 'spell-creator__drafts-list';
  draftsSection.appendChild(draftsList);
  root.appendChild(draftsSection);

  /**
   * Muestra un mensaje solemne en la línea de estado.
   * @param {string} safeMessage Texto controlado (viaja como textContent).
   * @param {boolean} isError Estilo de error (modificador BEM).
   */
  function setStatus(safeMessage, isError = false) {
    statusLine.textContent = safeMessage;
    statusLine.className = isError
      ? 'spell-creator__status spell-creator__status--error'
      : 'spell-creator__status';
  }

  /**
   * Respaldo volátil del formulario (plan 5.5): ante pérdida de red o
   * refresco, el estado de la forja sobrevive en localStorage. Cualquier
   * fallo del almacenamiento (modo privado, cuota) se ignora en silencio:
   * es una copia de trabajo, jamás una fuente de verdad.
   */
  function persistDraftSnapshot() {
    if (!localStorage) return;
    try {
      const snapshot = {
        name: nameInput.value,
        description: descriptionInput.value,
        ...formControls.getParams(),
      };
      localStorage.setItem(STORAGE_KEY, JSON.stringify(snapshot));
    } catch {
      // localStorage bloqueado o sin cuota: el taller sigue funcionando.
    }
  }

  /**
   * Restaura el respaldo volátil si existe (al recargar la página).
   */
  function restoreDraftSnapshot() {
    if (!localStorage) return;
    try {
      const rawSnapshot = localStorage.getItem(STORAGE_KEY);
      if (!rawSnapshot) return;
      const snapshot = JSON.parse(rawSnapshot);
      if (snapshot === null || typeof snapshot !== 'object') return;

      if (typeof snapshot.name === 'string') nameInput.value = snapshot.name;
      if (typeof snapshot.description === 'string') descriptionInput.value = snapshot.description;
      formControls.setParams(snapshot);
      updateLiveBreakdown();
    } catch {
      // Respaldo corrupto: se ignora sin romper el taller.
    }
  }

  /**
   * Desglose EN VIVO (RF-04.1): recalcula con el simulador en cliente y
   * actualiza el panel. El backend revalidará de forma ciega al guardar
   * o publicar (Art. II).
   */
  function updateLiveBreakdown() {
    const params = formControls.getParams();
    try {
      breakdown.update(simulateMana(params));
    } catch (simulationError) {
      // Sobrecarga Arcana o conjuro sin forma: el panel reacciona igual,
      // mostrando el cartel o el estado vacío según el error.
      if (simulationError && simulationError.name === 'ArcaneOverloadError') {
        breakdown.update({
          finalManaCost: simulationError.calculatedMana,
          circle: 5,
          circleLabel: 'Círculo V (Archimago)',
          isOverloaded: true,
        });
      }
    }
    persistDraftSnapshot();
  }

  /**
   * Construye el payload completo del Endpoint 2 (narrativa + matemática)
   * desde el estado actual de la forja.
   * @returns {object} Payload camelCase del contrato del plan.
   */
  function buildCreatePayload() {
    return {
      name: nameInput.value,
      elementalAffinity: 'fire',
      magicSchool: 'evocation',
      castingTime: 'action',
      description: descriptionInput.value,
      ...formControls.getParams(),
    };
  }

  /**
   * Refresca el cajón de borradores privados (RF-05.1) desde el backend.
   */
  async function refreshDrafts() {
    const result = await client.listDrafts();
    // Limpieza del cajón antes del repintado (sin innerHTML).
    while (draftsList.children.length > 0) {
      draftsList.children[0].remove();
    }

    if (!result.success) {
      setStatus('No pude traer tus borradores del santuario.', true);
      return;
    }

    for (const draft of result.data ?? []) {
      draftsList.appendChild(buildDraftItem(draft));
    }
  }

  /**
   * Construye la fila de un borrador con sus acciones de carga y retirada.
   * @param {object} draft Resumen del borrador (id, name, manaCost, circleLabel…).
   * @returns {HTMLElement} Nodo <li> de la lista.
   */
  function buildDraftItem(draft) {
    const item = elementFactory('li');
    item.className = 'spell-creator__draft-item';
    item.setAttribute('data-draft-id', String(draft.id ?? ''));

    const summary = createTextElement(
      'span',
      'spell-creator__draft-summary',
      `${draft.name ?? 'Sin nombre'} — ${draft.manaCost ?? '?'} de maná — ${draft.circleLabel ?? ''}`,
    );
    item.appendChild(summary);

    const loadButton = elementFactory('button');
    loadButton.type = 'button';
    loadButton.className = 'spell-creator__draft-load';
    loadButton.textContent = 'Restaurar';
    loadButton.setAttribute('data-draft-id', String(draft.id ?? ''));
    item.appendChild(loadButton);

    const deleteButton = elementFactory('button');
    deleteButton.type = 'button';
    deleteButton.className = 'spell-creator__draft-delete';
    deleteButton.textContent = 'Retirar';
    deleteButton.setAttribute('data-draft-id', String(draft.id ?? ''));
    item.appendChild(deleteButton);

    // Restaurar (RF-06.1): rellena la forja con el borrador cargado.
    loadButton.addEventListener('click', async () => {
      loadedDraftId = String(draft.id ?? '');
      nameInput.value = draft.name ?? '';
      descriptionInput.value = draft.description ?? '';
      // Los parámetros matemáticos del borrador, si el listado los porta.
      formControls.setParams(draft);
      updateLiveBreakdown();
      setStatus(`Borrador "${draft.name ?? ''}" restaurado en la forja.`);
      await refreshDrafts();
    });

    // Retirar (RF-05.1): libera hueco de la cuota.
    deleteButton.addEventListener('click', async () => {
      const deleteResult = await client.deleteDraft(String(draft.id ?? ''));
      if (deleteResult.success) {
        if (loadedDraftId === String(draft.id ?? '')) {
          loadedDraftId = null;
        }
        setStatus(`Borrador "${draft.name ?? ''}" retirado del grimorio.`);
      } else {
        setStatus(deleteResult.error?.message ?? 'No pude retirar el borrador.', true);
      }
      await refreshDrafts();
    });

    return item;
  }

  /**
   * Acción Guardar Borrador (RF-05.1): envía la forja al backend, que
   * recalcula el maná de forma ciega (Art. II). El borrador cargado se
   * actualiza; si no lo hay, nace uno nuevo.
   */
  async function handleSave() {
    const payload = buildCreatePayload();
    if (loadedDraftId !== null) {
      const updateResult = await client.updateDraft(loadedDraftId, payload);
      if (updateResult.success) {
        setStatus('Borrador actualizado en el santuario.');
      } else {
        setStatus(updateResult.error?.message ?? 'No pude actualizar el borrador.', true);
      }
    } else {
      const saveResult = await client.saveDraft(payload);
      if (saveResult.success) {
        loadedDraftId = String(saveResult.data?.id ?? '');
        setStatus(`Borrador guardado: ${saveResult.data?.slug ?? ''}`);
      } else {
        setStatus(saveResult.error?.message ?? 'No pude guardar el borrador.', true);
      }
    }
    await refreshDrafts();
  }

  /**
   * Acción Publicar (RF-05.2): transiciona el borrador cargado a
   * experimental con firmas 0/3 para la Moderación en 2 pasos.
   */
  async function handlePublish() {
    if (loadedDraftId === null) {
      setStatus('Guarda el borrador antes de publicarlo al santuario.', true);
      return;
    }
    const publishResult = await client.publishSpell(loadedDraftId);
    if (publishResult.success) {
      setStatus('Conjuro publicado: aguarda el veredicto de los Maestros (0/3 firmas).');
      loadedDraftId = null;
    } else {
      setStatus(publishResult.error?.message ?? 'No pude publicar el conjuro.', true);
    }
    await refreshDrafts();
  }

  // -----------------------------------------------------------------
  // Cableado de eventos.
  // -----------------------------------------------------------------
  let liveHandler = null;

  saveButton.addEventListener('click', () => { void handleSave(); });
  publishButton.addEventListener('click', () => { void handlePublish(); });

  // -----------------------------------------------------------------
  // API pública de la vista.
  // -----------------------------------------------------------------

  /**
   * Monta el taller en el punto de anclaje: construye, restaura el
   * respaldo volátil y pide los borradores privados.
   */
  function render() {
    mountRoot.appendChild(root);

    // Desglose en vivo (RF-04.1): cada pulsación recalcula y respalda.
    liveHandler = () => updateLiveBreakdown();
    formControls.root.addEventListener('spell:params-changed', liveHandler);

    restoreDraftSnapshot();
    void refreshDrafts();
  }

  /** Desmonta el taller liberando sus listeners. */
  function destroy() {
    if (liveHandler !== null) {
      formControls.root.removeEventListener('spell:params-changed', liveHandler);
      liveHandler = null;
    }
    root.remove();
  }

  return { render, destroy, refreshDrafts };
}

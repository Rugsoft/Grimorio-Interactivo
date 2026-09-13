/**
 * accessModalComponent.js — Diálogo «Cruzar el Umbral» del Grimorio Interactivo.
 *
 * Tarea 4.4 (TASKS-01): modal de acceso (Renovar Vínculo / Consagrarse) que se
 * APILA sobre la ficha técnica sin cerrarla (RF-05.1, RF-05.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): `<dialog>` estándar con showModal(); el
 *     navegador resuelve la pila de modales de forma nativa (cada diálogo
 *     abierto conserva su fondo `::backdrop` y su confinamiento de foco).
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Diseño (plan 4.2 y 5.4):
 *   - Los formularios del shell (`#loginForm`, `#registerForm`, Tarea 2.1) son
 *     la fuente única de la interfaz: el componente los CABLEA (listeners de
 *     envío), no los reconstruye — evita nodos huérfanos entre aperturas.
 *   - `open(intent)`: muestra el diálogo reteniendo la intención pendiente que
 *     trae el orquestador desde el store (RF-05.2). La ficha de detalle queda
 *     intacta, abierta e interactiva debajo de la pila.
 *   - Envío válido → se notifica `onAuthenticate`/`onRegister` con las
 *     credenciales y la intención, y se cierra el diálogo para que el
 *     orquestador reanude la intención (RF-05.3, flujo completo).
 *   - Cancelación (Escape/×) → `onClose` al orquestador; la intención NO se
 *     borra aquí: vive en el store (RF-05.4) y sobrevive a la cancelación.
 */

/** Ids de los formularios del shell que este componente cablea (Tarea 2.1). */
const SHELL_FORM_IDS = {
  login: 'loginForm',
  register: 'registerForm',
};

/** Mapa de campos del shell → contrato de credenciales (inglés, camelCase). */
const CREDENTIAL_FIELDS = {
  login: { alias: 'loginName', passphrase: 'loginPassword' },
  register: { alias: 'registerName', passphrase: 'registerPassword', email: 'registerEmail' },
};

/**
 * Búsqueda recursiva de un descendiente por atributo id.
 * Compatible con DOM real y con el DOM simulado del arnés de pruebas.
 */
function findDescendantById(root, elementId) {
  for (const child of root.children ?? []) {
    if (child.getAttribute?.('id') === elementId) return child;
    const found = findDescendantById(child, elementId);
    if (found) return found;
  }
  return null;
}

/** Primer campo de texto introducible dentro de un nodo (para foco inicial). */
function findFirstInput(root) {
  for (const child of root.children ?? []) {
    if (child.tagName === 'INPUT' || child.tagName === 'BUTTON') return child;
    const found = findFirstInput(child);
    if (found) return found;
  }
  return null;
}

/**
 * Extrae las credenciales del evento de envío. El arnés entrega `event.fields`;
 * en el navegador real se leen los inputs por su atributo `name`.
 */
function extractCredentials(event, mode) {
  const fieldMap = CREDENTIAL_FIELDS[mode];
  const fields = event.fields ?? {};
  const readField = (fieldName) => {
    if (fieldName in fields) return fields[fieldName];
    const input = event.target?.querySelector?.(`[name="${fieldName}"]`);
    return input?.value ?? '';
  };
  // Las credenciales viajan con los nombres de campo del shell (contrato
  // del backend SPEC-03): loginName/loginPassword, registerName/registerPassword.
  const credentials = {
    [fieldMap.alias]: readField(fieldMap.alias),
    [fieldMap.passphrase]: readField(fieldMap.passphrase),
  };
  // El correo solo existe en la consagración (Endpoint 1 exige email).
  if (fieldMap.email !== undefined) {
    credentials[fieldMap.email] = readField(fieldMap.email);
  }
  return credentials;
}

/**
 * Crea el componente del modal de acceso.
 *
 * @param {HTMLDialogElement} dialog Elemento `<dialog id="accessModal">` del shell.
 * @param {Object} options
 * @param {(credentials: {alias: string, passphrase: string}, intent: Object|null) => void} [options.onAuthenticate]
 *        Envío de «Renovar Vínculo» (login). El componente cierra tras notificar.
 * @param {(credentials: {alias: string, passphrase: string}, intent: Object|null) => void} [options.onRegister]
 *        Envío de «Consagrarse» (registro). El componente cierra tras notificar.
 * @param {() => void} [options.onClose] Cierre por cancelación (Escape/×).
 * @param {Document} [options.documentRef] Documento inyectable (tests).
 * @returns {Object} API: { open, close, isOpen, getPendingIntent, destroy }.
 */
export function createAccessModalComponent(dialog, options) {
  const documentRef = options.documentRef ?? globalThis.document;
  const onAuthenticate = options.onAuthenticate ?? null;
  const onRegister = options.onRegister ?? null;
  const onClose = options.onClose ?? null;
  /** Mensajes del backend (RF-03.1, extensión Tarea 4.3). */
  const onError = options.onError ?? null;

  /** Botón × del shell (RF-05.3): cierre de cancelación del diálogo. */
  let closeButton = null;

  let pendingIntent = null;
  let isDestroyed = false;
  let isWired = false; // Guardia: los formularios del shell se cablean una sola vez.

  /** Cablea los formularios existentes del shell (una única vez). */
  function wireShellForms() {
    if (isWired) return;
    isWired = true;

    for (const mode of Object.keys(SHELL_FORM_IDS)) {
      const shellForm = findDescendantById(dialog, SHELL_FORM_IDS[mode]);
      if (!shellForm) continue; // Sin formulario en el shell, no hay nada que cablear.

      shellForm.addEventListener('submit', (event) => {
        event.preventDefault?.();
        const credentials = extractCredentials(event, mode);

        // Guardia SPEC-03 (Tarea 4.3): mientras dure el bloqueo por
        // sobrecarga de maná (429, RF-03.2) ningún envío procede.
        if (isSubmissionLocked()) return;

        // Notifica al orquestador con la intención retenida (RF-05.3) y
        // cierra para reanudarla: flujo completo de «Cruzar el Umbral».
        if (mode === 'login' && typeof onAuthenticate === 'function') {
          onAuthenticate(credentials, pendingIntent);
        }
        if (mode === 'register' && typeof onRegister === 'function') {
          // Selección OBLIGATORIA de clan (RF-01.1): si el selector está
          // poblado y no se electo linaje, el registro no procede y el
          // diálogo permanece abierto para que el iniciado elija.
          const clanSelect = findDescendantById(dialog, 'clanSelect');
          if (clanSelect !== null) {
            const selectedClanId = clanSelect.value ?? '';
            if (selectedClanId === '') return;
            // El clanId electo viaja con el payload (RF-01.1).
            onRegister({ ...credentials, clanId: selectedClanId }, pendingIntent);
          } else {
            onRegister(credentials, pendingIntent);
          }
        }
        close();
      });
    }
  }

  /**
   * Refuerzo del confinamiento de foco (RNF-03). Con showModal() el navegador
   * ya atrapa el foco de forma nativa; este listener cubre el caso límite de
   * un Tab recibido a nivel del propio diálogo (foco en el borde), que en el
   * DOM real solo ocurre cuando el foco intenta escapar por el fondo.
   */
  function handleKeydown(event) {
    if (event.key !== 'Tab') return;
    if (event.target !== dialog && event.currentTarget !== dialog) return;
    event.preventDefault?.();
    const firstInput = findFirstInput(dialog);
    if (firstInput) firstInput.focus();
  }

  /**
   * Handler del evento nativo `close` (cubre Escape y botón ×): notifica al
   * orquestador. La intención pendiente NO se limpia: vive en el store
   * (RF-05.4) y debe sobrevivir a una cancelación.
   */
  function handleClose() {
    if (typeof onClose === 'function') onClose();
  }

  /**
   * Abre el diálogo APILADO sobre la ficha técnica (si está abierta).
   * Idempotente: si ya está abierto, solo actualiza la intención.
   *
   * @param {Object|null} intent Intención pendiente del store (p. ej.
   *        `{ action: 'addToGrimoire', targetSlug: 'llamas-de-frieren' }`).
   */
  function open(intent = null) {
    if (isDestroyed) return;
    pendingIntent = intent;
    if (!dialog.open) {
      // Botón × del shell: el cierre converge en dialog.close() → 'close'.
      // El DOM simulado carece de querySelector: se degrada con guardia.
      closeButton = typeof dialog.querySelector === 'function'
        ? dialog.querySelector('#accessModalClose')
        : null;
      closeButton?.addEventListener('click', close);

      wireShellForms();
      dialog.addEventListener('keydown', handleKeydown);
      dialog.showModal();
      // Foco inicial dentro del diálogo (RNF-03): primer campo del formulario.
      const firstInput = findFirstInput(dialog);
      if (firstInput) firstInput.focus();
    }
  }

  /** Cierra el diálogo; la pila nativa restaura de inmediato la ficha (RF-05.3). */
  function close() {
    if (!dialog.open) return;
    dialog.close('access-dismissed');
  }

  // ===================================================================
  // Extensión SPEC-03 (Tarea 4.3): pestañas, selector de clan obligatorio,
  // mensajes anti-enumeración y bloqueo temporal por 429. Todo se apila
  // sobre el cableado original sin reconstruir el shell.
  // ===================================================================

  /** Pestañas canónicas del diálogo (plan 2.2, RF-01.1/RF-02.1). */
  const TAB_NAMES = ['login', 'register'];

  /** Pestaña activa (defecto: Renovar Vínculo). */
  let activeTab = 'login';

  /** Instante (Date.now()) hasta el que el envío queda congelado por 429. */
  let submissionLockedUntil = 0;

  /**
   * Localiza un descendiente por id dentro del diálogo (idempotente).
   */
  function findOrWireFormElement(formId) {
    let element = typeof dialog.querySelector === 'function'
      ? dialog.querySelector(`#${formId}`)
      : null;
    if (element) return element;
    element = findDescendantById(dialog, formId);
    if (element) return element;
    // El shell aún no lo trae: el componente lo forja como nodo propio
    // (El DOM real del index.html sí lo trae desde la Tarea 2.1).
    const forged = (options.documentRef ?? globalThis.document)?.createElement?.('form');
    if (forged) {
      forged.setAttribute('id', formId);
      dialog.appendChild(forged);
    }
    return forged;
  }

  /**
   * CRITERIO T4.3: alterna fluidamente entre «Renovar Vínculo» (login)
   * y «Consagrarse» (register). Pestaña fuera del canon se ignora.
   *
   * @param {'login'|'register'} tabName Pestaña destino.
   */
  function showTab(tabName) {
    if (!TAB_NAMES.includes(tabName)) return; // Defensa: sin estados fantasma.
    activeTab = tabName;

    // Señal accesible: aria-hidden en el formulario inactivo para que los
    // lectores de pantalla anuncien solo la pestaña viva.
    for (const tabNameLoop of TAB_NAMES) {
      const formElement = findOrWireFormElement(SHELL_FORM_IDS[tabNameLoop]);
      if (!formElement) continue;
      if (tabNameLoop === activeTab) {
        formElement.removeAttribute('aria-hidden');
      } else {
        formElement.setAttribute('aria-hidden', 'true');
      }
    }

    // Gestión de foco (RNF-03): el primer campo de la pestaña activa lo
    // recibe al alternar, sin abandonar el confinamiento del diálogo.
    if (dialog.open) {
      const firstInput = findFirstInput(findOrWireFormElement(SHELL_FORM_IDS[activeTab]));
      if (firstInput) firstInput.focus();
    }
  }

  /** Pestaña activa ('login' | 'register'). */
  function getActiveTab() {
    return activeTab;
  }

  /**
   * CRITERIO T4.3: puebla el selector OBLIGATORIO de linaje (RF-01.1)
   * con los clanes activos del catálogo. Crea el selector si el shell
   * aún no lo trae y añade la opción placeholder vacía (obliga a elegir).
   *
   * @param {Array<{id: string, name: string}>} clans Linajes activos.
   */
  function populateClans(clans) {
    const clanList = Array.isArray(clans) ? clans : [];
    let clanSelect = findDescendantById(dialog, 'clanSelect');
    if (clanSelect) {
      // Repoblado idempotente: se descartan las opciones anteriores.
      for (const previousOption of [...(clanSelect.children ?? [])]) {
        previousOption.remove?.();
      }
    } else {
      clanSelect = documentRef.createElement?.('select');
      if (!clanSelect) return;
      clanSelect.setAttribute('id', 'clanSelect');
      clanSelect.setAttribute('name', 'clanSelect');
      clanSelect.setAttribute('required', '');
      clanSelect.setAttribute('aria-label', 'Linaje al que consagrarse (obligatorio)');
      const registerFormElement = findOrWireFormElement(SHELL_FORM_IDS.register);
      registerFormElement?.appendChild(clanSelect);
    }

    // Placeholder vacío: hasta que el iniciado elija, el valor no es válido.
    const placeholderOption = documentRef.createElement?.('option');
    if (placeholderOption) {
      placeholderOption.setAttribute('value', '');
      placeholderOption.textContent = '— Elige tu linaje —';
      clanSelect.appendChild(placeholderOption);
    }

    // Una opción por linaje activo (Art. IV: nombres solemnes en castellano).
    for (const clan of clanList) {
      const clanOption = documentRef.createElement?.('option');
      if (!clanOption) continue;
      clanOption.setAttribute('value', String(clan.id));
      clanOption.textContent = String(clan.name);
      clanSelect.appendChild(clanOption);
    }
  }

  /**
   * CRITERIO T4.3: bloquea temporalmente el envío (RF-03.2). Se invoca
   * cuando la API responde 429 RATE_LIMITED con remainingSeconds: el
   * botón queda congelado mientras dura el castigo de la procedencia.
   *
   * @param {number} seconds Segundos de bloqueo (0 o negativo desbloquea).
   */
  function lockSubmission(seconds) {
    const lockSeconds = Number(seconds);
    if (!Number.isFinite(lockSeconds) || lockSeconds <= 0) {
      submissionLockedUntil = 0; // Fin del castigo: desbloqueo inmediato.
      return;
    }
    submissionLockedUntil = Date.now() + lockSeconds * 1000;
  }

  /** Verdadero mientras dure el bloqueo por sobrecarga de maná. */
  function isSubmissionLocked() {
    return Date.now() < submissionLockedUntil;
  }

  /**
   * CRITERIO T4.3: muestra el mensaje del backend (RF-03.1). Las leyendas
   * anti-enumeración ya vienen decididas por el servidor (fuente única de
   * verdad): aquí solo se exhiben, jamás se reescriben en el cliente.
   *
   * @param {Object|null} errorEnvelope Sobre estándar { success, error }.
   */
  function reportError(errorEnvelope) {
    const errorCode = errorEnvelope?.error?.code ?? null;
    const message = typeof errorEnvelope?.error?.message === 'string' && errorEnvelope.error.message !== ''
      ? errorEnvelope.error.message
      : 'La corriente de maná no pudo procesar la petición.';

    // Bloqueo temporal cuando el backend declara sobrecarga (RF-03.2).
    const remainingSeconds = Number(errorEnvelope?.error?.remainingSeconds);
    if (errorCode === 'RATE_LIMITED' && Number.isFinite(remainingSeconds) && remainingSeconds > 0) {
      lockSubmission(remainingSeconds);
    }

    if (typeof onError === 'function') {
      onError(message, errorCode ?? undefined);
    }
  }

  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    dialog.removeEventListener('keydown', handleKeydown);
    dialog.removeEventListener('close', handleClose);
  }

  dialog.addEventListener('close', handleClose);

  return {
    open,
    close,
    isOpen: () => dialog.open,
    getPendingIntent: () => pendingIntent,
    destroy,
    showTab,
    getActiveTab,
    populateClans,
    reportError,
    lockSubmission,
    isSubmissionLocked,
  };
}

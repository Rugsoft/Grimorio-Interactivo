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
  register: { alias: 'registerName', passphrase: 'registerPassword' },
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
  return {
    [fieldMap.alias]: readField(fieldMap.alias),
    [fieldMap.passphrase]: readField(fieldMap.passphrase),
  };
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
        // Notifica al orquestador con la intención retenida (RF-05.3) y
        // cierra para reanudarla: flujo completo de «Cruzar el Umbral».
        if (mode === 'login' && typeof onAuthenticate === 'function') {
          onAuthenticate(credentials, pendingIntent);
        }
        if (mode === 'register' && typeof onRegister === 'function') {
          onRegister(credentials, pendingIntent);
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
  };
}

/**
 * recoveryModalComponent.js — Diálogo del «Pergamino de Restablecimiento».
 *
 * Tarea 4.4 (TASKS-03): componente de recuperación de credenciales para
 * solicitar el restablecimiento (RF-04.1) e introducir la nueva frase de
 * paso cuando el iniciado llega mediante un enlace con token (RF-04.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM y eventos nativos; los nodos se
 *     forjan con createElement y textContent — innerHTML está PROHIBIDO
 *     (AGENTS.md 6.1, verificado por el arnés).
 *   - Artículo III (anti-enumeración): la notificación de «pergamino
 *     remitido» se muestra con el mensaje del backend, idéntico exista
 *     o no el correo; este componente jamás infiere la existencia.
 *   - Artículo IV (El Velo Arcano): leyendas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Diseño:
 *   - Modo solicitud: el iniciado escribe su correo y el componente
 *     notifica onRequestRecovery(email) al orquestador, que llamará al
 *     authClient (Tarea 4.1). La respuesta entra por reportRecoveryNotice().
 *   - Modo restablecimiento (openResetMode(token)): formulario de nueva
 *     frase de paso; el envío notifica onSubmitReset(token, newPassphrase).
 *   - Defensas locales antes de gastar la API: correo vacío y frase corta
 *     no se notifican (canon del backend, RF-01.1).
 */

/** Canon local de la frase de paso (espejo del backend, RF-01.1). */
const MIN_PASSPHRASE_LENGTH = 8;

/**
 * Búsqueda recursiva de un descendiente por atributo id (compatible con
 * el DOM simulado del arnés, que carece de querySelector).
 */
function findDescendantById(root, elementId) {
  for (const child of root.children ?? []) {
    if (child.getAttribute?.('id') === elementId) return child;
    const found = findDescendantById(child, elementId);
    if (found) return found;
  }
  return null;
}

/** Primer campo de texto introducible dentro de un nodo (foco inicial). */
function findFirstInput(root) {
  for (const child of root.children ?? []) {
    if (child.tagName === 'INPUT' || child.tagName === 'BUTTON' || child.tagName === 'SELECT') return child;
    const found = findFirstInput(child);
    if (found) return found;
  }
  return null;
}

/**
 * Lee los campos del evento de envío. El arnés entrega `event.fields`;
 * en el navegador real se leen los inputs por su atributo `name`.
 */
function readField(event, fieldName) {
  const fields = event.fields ?? {};
  if (fieldName in fields) return String(fields[fieldName] ?? '');
  const input = event.target?.querySelector?.(`[name="${fieldName}"]`);
  return input?.value ?? '';
}

/**
 * Crea el componente del modal de recuperación.
 *
 * @param {HTMLDialogElement} dialog Elemento `<dialog id="recoveryModal">` del shell.
 * @param {Object} options
 * @param {(email: string) => void} [options.onRequestRecovery]
 *        Solicitud del pergamino (RF-04.1). El orquestador llama al authClient.
 * @param {(token: string, newPassphrase: string) => void} [options.onSubmitReset]
 *        Envío de la nueva frase con el token del enlace (RF-04.2).
 * @param {() => void} [options.onClose] Cierre por cancelación (Escape/×).
 * @param {Document} [options.documentRef] Documento inyectable (tests).
 * @returns {Object} API: { open, openResetMode, close, isOpen, reportRecoveryNotice,
 *                     reportResetSuccess, reportError, destroy }.
 */
export function createRecoveryModalComponent(dialog, options = {}) {
  const documentRef = options.documentRef ?? globalThis.document;
  const onRequestRecovery = options.onRequestRecovery ?? null;
  const onSubmitReset = options.onSubmitReset ?? null;
  const onClose = options.onClose ?? null;

  /** Modo activo: 'request' (solicitud) | 'reset' (nueva frase con token). */
  let activeMode = 'request';
  let isDestroyed = false;

  /**
   * Forja (una sola vez) y retorna un nodo propio del componente.
   * Los nodos ya existentes en el shell tienen prioridad (cableado).
   */
  function forgeNode(tagName, elementId, parentElement) {
    let node = findDescendantById(dialog, elementId);
    if (node) return node;
    const forged = documentRef.createElement?.(tagName);
    if (!forged) return null;
    forged.setAttribute('id', elementId);
    (parentElement ?? dialog).appendChild(forged);
    return forged;
  }

  /**
   * Construye el modo solicitud: formulario de correo + zona de notificación.
   */
  function buildRequestMode() {
    const notice = forgeNode('p', 'recoveryNotice', dialog);
    notice.textContent = '';

    const requestForm = forgeNode('form', 'recoveryRequestForm', dialog);
    requestForm.setAttribute('novalidate', '');

    // Cableado idempotente del envío (una sola vez por nodo).
    if (!requestForm.getAttribute('data-wired')) {
      requestForm.setAttribute('data-wired', 'true');
      requestForm.addEventListener('submit', (event) => {
        event.preventDefault?.();
        const email = readField(event, 'email');
        // Defensa local: sin correo no se gasta la API (RF-04.1).
        if (email.trim() === '') return;
        if (typeof onRequestRecovery === 'function') onRequestRecovery(email.trim());
      });
    }

    const emailInput = forgeNode('input', 'recoveryEmail', requestForm);
    emailInput.setAttribute('type', 'email');
    emailInput.setAttribute('name', 'email');
    emailInput.setAttribute('autocomplete', 'email');
    emailInput.setAttribute('placeholder', 'Tu correo del santuario');
    emailInput.setAttribute('aria-label', 'Correo electrónico para recibir el pergamino');

    const submitButton = forgeNode('button', 'recoveryRequestSubmit', requestForm);
    submitButton.setAttribute('type', 'submit');
    submitButton.textContent = 'Remitir el Pergamino';
  }

  /**
   * Construye el modo restablecimiento: formulario de token + nueva frase.
   * El token pre-cargado viene del enlace del correo (fuera de banda).
   *
   * @param {string} recoveryToken Token crudo recibido por correo.
   */
  function buildResetMode(recoveryToken) {
    const notice = forgeNode('p', 'recoveryNotice', dialog);
    notice.textContent = '';

    const resetForm = forgeNode('form', 'recoveryResetForm', dialog);
    resetForm.setAttribute('novalidate', '');

    if (!resetForm.getAttribute('data-wired')) {
      resetForm.setAttribute('data-wired', 'true');
      resetForm.addEventListener('submit', (event) => {
        event.preventDefault?.();
        const token = readField(event, 'token');
        const newPassphrase = readField(event, 'newPassphrase');
        // Defensas locales antes de gastar la API (canon del backend).
        if (token.trim() === '') return;
        if (newPassphrase.length < MIN_PASSPHRASE_LENGTH) return;
        if (typeof onSubmitReset === 'function') onSubmitReset(token.trim(), newPassphrase);
      });
    }

    const tokenInput = forgeNode('input', 'recoveryToken', resetForm);
    tokenInput.setAttribute('type', 'text');
    tokenInput.setAttribute('name', 'token');
    tokenInput.setAttribute('aria-label', 'Código del pergamino de restablecimiento');
    tokenInput.value = String(recoveryToken ?? '');

    const passphraseInput = forgeNode('input', 'recoveryNewPassphrase', resetForm);
    passphraseInput.setAttribute('type', 'password');
    passphraseInput.setAttribute('name', 'newPassphrase');
    passphraseInput.setAttribute('autocomplete', 'new-password');
    passphraseInput.setAttribute('aria-label', 'Nueva frase de paso (mínimo 8 caracteres)');

    const submitButton = forgeNode('button', 'recoveryResetSubmit', resetForm);
    submitButton.setAttribute('type', 'submit');
    submitButton.textContent = 'Sellada la nueva frase';
  }

  /** Muestra un mensaje en la zona de notificación (sin innerHTML jamás). */
  function showNotice(message) {
    const notice = findDescendantById(dialog, 'recoveryNotice');
    if (notice) notice.textContent = message;
  }

  /**
   * Abre el diálogo en modo solicitud (RF-04.1).
   */
  function open() {
    if (isDestroyed) return;
    activeMode = 'request';
    buildRequestMode();
    if (!dialog.open) {
      dialog.showModal();
      const firstInput = findFirstInput(dialog);
      if (firstInput) firstInput.focus();
    }
  }

  /**
   * Abre el diálogo en modo restablecimiento con el token del enlace (RF-04.2).
   *
   * @param {string} recoveryToken Token crudo recibido por correo.
   */
  function openResetMode(recoveryToken) {
    if (isDestroyed) return;
    activeMode = 'reset';
    buildResetMode(recoveryToken);
    if (!dialog.open) {
      dialog.showModal();
      const firstInput = findFirstInput(dialog);
      if (firstInput) firstInput.focus();
    }
  }

  /** Cierra el diálogo. */
  function close() {
    if (!dialog.open) return;
    dialog.close('recovery-dismissed');
  }

  /**
   * CRITERIO T4.4: exhibe la notificación temática del pergamino remitido.
   * El mensaje SIEMPRE viene del backend (neutro e idéntico en ambos
   * caminos): el componente lo muestra, jamás decide su contenido.
   *
   * @param {Object} envelope Sobre estándar { success, data } del authClient.
   */
  function reportRecoveryNotice(envelope) {
    const message = typeof envelope?.data?.message === 'string' && envelope.data.message !== ''
      ? envelope.data.message
      : 'Si ese correo habita el santuario, el pergamino de restablecimiento ha sido remitido.';
    showNotice(message);
  }

  /**
   * Notificación de éxito del restablecimiento (RF-04.2): la nueva frase
   * quedó sellada y el backend revocó todas las sesiones previas.
   */
  function reportResetSuccess() {
    showNotice('Tu nueva frase de paso ha sido sellada. Todos tus vínculos previos fueron disueltos.');
  }

  /**
   * Exhibe el error del backend (p. ej. 400 RECOVERY_TOKEN_INVALID)
   * sin explosión: sobre ilegible degrada a un mensaje genérico.
   *
   * @param {Object|null} errorEnvelope Sobre estándar { success, error }.
   */
  function reportError(errorEnvelope) {
    const message = typeof errorEnvelope?.error?.message === 'string' && errorEnvelope.error.message !== ''
      ? errorEnvelope.error.message
      : 'La corriente de maná no pudo procesar la petición.';
    showNotice(message);
  }

  /** Manejador del cierre nativo (Escape): notifica cancelación. */
  function handleClose() {
    if (typeof onClose === 'function') onClose();
  }

  function destroy() {
    if (isDestroyed) return;
    isDestroyed = true;
    dialog.removeEventListener('close', handleClose);
  }

  dialog.addEventListener('close', handleClose);

  return {
    open,
    openResetMode,
    close,
    isOpen: () => dialog.open,
    reportRecoveryNotice,
    reportResetSuccess,
    reportError,
    destroy,
  };
}

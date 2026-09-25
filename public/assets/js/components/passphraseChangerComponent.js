/**
 * passphraseChangerComponent.js — La Custodia de la Frase de Paso del
 * Panel del Adepto (SPEC-12, Tarea 6.2; plan §1.2/§6.2).
 *
 * Componente de la sección «Custodia de la frase de paso»: los tres
 * campos exigidos (RF-04.1), los CUATRO veredictos narrados con su
 * leyenda castellana canónica (éxito, ciego, idéntica, idempotente),
 * el recibo de éxito que anuncia `othersDissolvedCount` (RF-04.2), el
 * reenvío idempotente que JAMÁS engaña al dueño (caso límite 12,
 * hallazgo 16 del QA) y el anuncio único por la región viva (RNF-03).
 *
 * Frontera sagrada (Artículo II): el componente JAMÁS decide el canon
 * — no valida solidez, no compara frases, no calcula sesiones: porta
 * la intención y narra el veredicto del santuario. El cuerpo de la
 * petición — con las frases en claro — viaja por el cliente y JAMÁS
 * se registra ni se muestra de nuevo en la interfaz.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module components/passphraseChangerComponent
 */

/** Rótulos y leyendas solemnes de la custodia (Art. V, plan §6.2). */
export const PASSPHRASE_CHANGER_LEGENDS = Object.freeze({
  heading: 'La custodia de la frase de paso',
  currentLabel: 'Frase de paso actual',
  newLabel: 'Nueva frase de paso',
  repeatLabel: 'Repite la nueva frase de paso',
  submitLabel: 'Consumar la custodia',
  // Los cuatro veredictos (plan §2.6, RF-04.1). El fallo ciego es el
  // ESPEJO EXACTO del NOBLE_LEGEND del backend (un solo vocabulario;
  // jamás nombra causa: ni frase errada, ni diferencias, ni solidez).
  changed: 'Tu frase de paso ha quedado custodiada.',
  identical: 'La nueva frase coincide con la vigente.',
  blindFailure: 'El santuario no ha podido consumar el cambio de frase de paso: revisa tus datos y vuelve a intentarlo.',
  idempotentReceipt: 'La custodia ya se consumó con esta misma frase: nada queda por hacer.',
  // Aviso del fallo de red (contrato del proyecto).
  networkFailure: 'La corriente de maná hacia tu morada se ha interrumpido.',
});

/**
 * Narración del recibo de éxito (RF-04.2): la disolución de las demás
 * sesiones conservando la actual se ANUNCIA, jamás se calla.
 *
 * @param {number} othersDissolvedCount Sesiones disueltas (sin contar la actual).
 * @returns {string} Leyenda solemne del recibo.
 */
export function dissolutionLegendFor(othersDissolvedCount) {
  const count = Number(othersDissolvedCount);
  if (!Number.isFinite(count) || count <= 0) {
    return 'No había otras moradas abiertas: tu sesión actual permanece viva.';
  }
  return count === 1
    ? 'Otra morada tuya ha quedado vacía: solo tu sesión actual permanece viva.'
    : `${count} moradas tuyas han quedado vacías: solo tu sesión actual permanece viva.`;
}

/** Eventos que el componente emite sobre su raíz (plan §4.2). */
export const PASSPHRASE_CHANGER_EVENTS = Object.freeze({
  passphraseChanged: 'panel:passphrase-changed',
});

/**
 * Crea el componente de la custodia.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (la sección Custodia).
 * @param {Object} options
 * @param {Object} options.panelClient Cliente del panel (changePassphrase).
 * @param {(detail: object) => void} [options.onPassphraseChanged] Canal
 *        del orquestador (complementa el evento del bus; el orquestador
 *        NO toca la cookie: la sesión actual persiste, plan §4.2).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus (por defecto, la raíz).
 * @returns {Object} API: { render, destroy }.
 */
export function createPassphraseChangerComponent(mountRoot, options = {}) {
  const {
    panelClient,
    onPassphraseChanged,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));
  const eventTarget = options.eventTarget ?? mountRoot;

  let destroyed = false;
  /** El veredicto ya anunciado por la región viva (anuncio ÚNICO, RNF-03). */
  let lastAnnouncedVerdict = null;

  /**
   * Forja un elemento con clase, texto y atributos en una sola voz.
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

  /** Busca por clase con DOBLE vía (classList y atributo className). */
  function findByClassName(root, className) {
    const walk = (node) => {
      for (const child of node?.children ?? []) {
        if (child.classList?.contains?.(className)
          || (typeof child.className === 'string' && child.className.split(/\s+/).includes(className))) {
          return child;
        }
        const found = walk(child);
        if (found) return found;
      }
      return null;
    };
    return walk(root);
  }

  /** Emite el veredicto de éxito por el bus y el canal directo. */
  function emitPassphraseChanged(detail) {
    const EventCtor = globalThis.CustomEvent
      ?? class { constructor(type, options_ = {}) { this.type = type; this.detail = options_.detail ?? null; } };
    eventTarget.dispatchEvent(new EventCtor(PASSPHRASE_CHANGER_EVENTS.passphraseChanged, { detail, bubbles: true }));
    onPassphraseChanged?.(detail);
  }

  /**
   * Anuncia el veredicto por la región viva UNA SOLA VEZ (RNF-03,
   * fase [5] del plan): el mismo veredicto consecutivo no se repite.
   */
  function announceVerdict(verdictKey, message) {
    if (lastAnnouncedVerdict === verdictKey) return;
    lastAnnouncedVerdict = verdictKey;
    const region = findByClassName(mountRoot, 'passphrase-changer__live');
    if (region) region.textContent = message;
  }

  /** Pinta el veredicto en su slot de la cámara (lectura estable). */
  function showVerdict(verdictKey, message) {
    const slot = findByClassName(mountRoot, 'passphrase-changer__verdict');
    if (slot) {
      slot.textContent = message;
      slot.setAttribute('data-verdict', verdictKey);
    }
    announceVerdict(verdictKey, message);
  }

  /** Limpia el veredicto al abrir una nueva custodia. */
  function clearVerdict() {
    lastAnnouncedVerdict = null;
    const slot = findByClassName(mountRoot, 'passphrase-changer__verdict');
    if (slot) {
      slot.textContent = '';
      slot.removeAttribute('data-verdict');
    }
  }

  /** Bloquea/desbloquea el gesto mientras la custodia viaja. */
  function setBusy(busy) {
    const button = findByClassName(mountRoot, 'passphrase-changer__submit');
    if (button) button.disabled = busy === true;
  }

  /** La custodia: lee los tres campos y narra el veredicto (RF-04). */
  async function submitCustody() {
    const currentInput = findByClassName(mountRoot, 'passphrase-changer__current');
    const newInput = findByClassName(mountRoot, 'passphrase-changer__new');
    const repeatInput = findByClassName(mountRoot, 'passphrase-changer__repeat');

    clearVerdict();
    setBusy(true);
    const envelope = await panelClient.changePassphrase(
      String(currentInput?.value ?? ''),
      String(newInput?.value ?? ''),
      String(repeatInput?.value ?? ''),
    );
    if (destroyed) return;
    setBusy(false);

    // El contenido de los campos se purga SIEMPRE tras el envío: la
    // frase en claro jamás permanece en el DOM (RF-04.1, RNF-04).
    for (const input of [currentInput, newInput, repeatInput]) {
      if (input) input.value = '';
    }

    // --- Los CUATRO veredictos (plan §2.6) -------------------------
    if (envelope?.success === true && envelope.data?.verdict === 'changed') {
      const others = Number(envelope.data?.othersDissolvedCount ?? 0);
      showVerdict('changed', `${PASSPHRASE_CHANGER_LEGENDS.changed} ${dissolutionLegendFor(others)}`);
      emitPassphraseChanged({ verdict: 'changed', othersDissolvedCount: others });
      return;
    }

    if (envelope?.success === true && envelope.data?.verdict === 'idempotentReceipt') {
      // Reenvío legítimo (hallazgo 16): el aviso JAMÁS miente — narra
      // que el acto ya se consumó, sin asiento nuevo ni castigo.
      showVerdict('idempotentReceipt', PASSPHRASE_CHANGER_LEGENDS.idempotentReceipt);
      return;
    }

    if (envelope?.status === 400 && envelope.error?.code === 'PASSPHRASE_IDENTICAL') {
      showVerdict('identical', PASSPHRASE_CHANGER_LEGENDS.identical);
      return;
    }

    if (envelope?.status === 400 && envelope.error?.code === 'PASSPHRASE_CHANGE_FAILED') {
      // El fallo ciego (RF-04.1): una sola leyenda para las tres causas,
      // sin pistas del motivo concreto.
      showVerdict('blindFailure', PASSPHRASE_CHANGER_LEGENDS.blindFailure);
      return;
    }

    // Corte de red o velo arcano: aviso noble con reintento natural.
    showVerdict('networkFailure', envelope?.error?.message || PASSPHRASE_CHANGER_LEGENDS.networkFailure);
  }

  return {
    /** Monta el formulario de la custodia (los tres campos, RNF-03). */
    async render() {
      const liveRegion = forge('div', {
        className: 'passphrase-changer__live',
        attrs: { 'aria-live': 'polite' },
      });
      mountRoot.appendChild(liveRegion);

      const form = forge('form', { className: 'passphrase-changer__form', attrs: { novalidate: '' } });

      // --- Los TRES campos exigidos (RF-04.1) ------------------------
      const fields = [
        { className: 'passphrase-changer__current', label: PASSPHRASE_CHANGER_LEGENDS.currentLabel, id: 'passphrase-current', autocomplete: 'current-password' },
        { className: 'passphrase-changer__new', label: PASSPHRASE_CHANGER_LEGENDS.newLabel, id: 'passphrase-new', autocomplete: 'new-password' },
        { className: 'passphrase-changer__repeat', label: PASSPHRASE_CHANGER_LEGENDS.repeatLabel, id: 'passphrase-repeat', autocomplete: 'new-password' },
      ];
      for (const field of fields) {
        const label = forge('label', { text: field.label, attrs: { for: field.id } });
        const input = forge('input', {
          className: field.className,
          attrs: { type: 'password', id: field.id, autocomplete: field.autocomplete, required: '' },
        });
        form.appendChild(label);
        form.appendChild(input);
      }

      // El veredicto vive en un slot de lectura estable y la región
      // viva lo anuncia una sola vez (RNF-03, fase [5] del plan).
      form.appendChild(forge('p', { className: 'passphrase-changer__verdict', attrs: { role: 'status' } }));

      const submit = forge('button', {
        className: 'passphrase-changer__submit panel-cta',
        text: PASSPHRASE_CHANGER_LEGENDS.submitLabel,
        attrs: { type: 'submit' },
      });
      form.appendChild(submit);

      // Envío por submit nativo del formulario (operable con Enter,
      // RNF-03) y por clic del botón en DOM simulado sin submit.
      form.addEventListener?.('submit', (event) => {
        event?.preventDefault?.();
        void submitCustody();
      });
      submit.addEventListener('click', (event) => {
        event?.preventDefault?.();
        void submitCustody();
      });

      mountRoot.appendChild(form);
    },

    /** Retira el componente. */
    destroy() {
      destroyed = true;
      mountRoot.replaceChildren?.();
    },
  };
}

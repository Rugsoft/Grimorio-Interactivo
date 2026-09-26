/**
 * avatarPickerComponent.js — El selector de efigie del Panel del Adepto
 * (SPEC-12, Tarea 6.1; plan §1.2/§6.2).
 *
 * Componente de la sección «Efigie»: rejilla del catálogo canónico con
 * `aria-pressed` en la vigente, zona de subida con `input[type=file]`
 * real + previsualización + marco ceremonial cuadrado, aviso solemne
 * por código de error que NOMBRA el motivo (RF-03.2) y reintento ante
 * fallo de carga del canon (caso límite 14). El repintado de la
 * cabecera ocurre POR EVENTO (plan §4.2): el componente emite
 * `panel:avatar-changed` y jamás toca el distintivo.
 *
 * Frontera sagrada (Artículo II): el componente JAMÁS decide el canon
 * — no valida bytes (eso lo hace GD en el santuario), no encuadra,
 * no calcula hashes: porta la intención (elección o fichero) y narra
 * el veredicto que el cliente trae. Toda la validación profunda vive
 * en el backend (AvatarService).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo; DOM por createElement
 *     con textContent puro — innerHTML PROHIBIDO; cero librerías.
 *   - Artículo IV/V: leyendas en noble castellano; identificadores en inglés.
 *
 * @module components/avatarPickerComponent
 */

import {
  createRuneSeal,
} from './runeSealComponent.js';

/** Rótulos y leyendas solemnes del picker (Art. V). */
export const AVATAR_PICKER_LEGENDS = Object.freeze({
  title: 'Efigie',
  catalogHeading: 'Canon de efigies',
  uploadHeading: 'Tu propia efigie',
  uploadLabel: 'Elige una imagen para tu efigie',
  uploadHint: 'PNG, JPG o WebP · hasta 2 MiB · lados de hasta 1024 px · el santuario la encaja en el marco ceremonial cuadrado.',
  previewAlt: 'Previsualización de tu efigie dentro del marco ceremonial',
  frameLegend: 'Marco ceremonial cuadrado (512×512 efectivos)',
  uploadButton: 'Vestir esta efigie',
  retryLabel: 'Intentarlo de nuevo',
  removing: 'Retirando tu efigie…',
  choosing: 'Vistiendo la efigie elegida…',
});

/**
 * Avisos solemnes por código de error (RF-03.2: el aviso SÍ nombra el
 * motivo — la imagen no es secreto; los códigos viajan en el sobre del
 * cliente y la leyenda del backend tiene precedencia).
 */
export const AVATAR_PICKER_ERROR_LEGENDS = Object.freeze({
  INVALID_AVATAR_FORMAT: 'Ese pergamino no es una imagen del canon: usa PNG, JPG o WebP.',
  AVATAR_TOO_LARGE: 'Tu efigie pesa más de lo que el santuario puede portar: reduce su peso a 2 MiB.',
  AVATAR_DIMENSIONS_EXCEEDED: 'Tu efigie excede los lados permitidos (1024 px): redúcela antes de vestirla.',
  AVATAR_IDENTICAL: 'La imagen ya viste tu identidad.',
  AVATAR_STORE_FAILED: 'La efigie no pudo vestirse en este instante: tu identidad conserva su efigie anterior.',
  AVATAR_CATALOG_UNAVAILABLE: 'El canon de efigies no responde en este instante: inténtalo de nuevo en breve.',
  LINEAGE_OATH_REQUIRED: 'Tu efigie aguarda al juramento: la ceremonia de linaje te espera.',
  INVALID_AVATAR_REQUEST: 'Ninguna imagen llegó al santuario: envía tu efigie con el envío.',
});

/** Leyenda de degradación ante fichero propio inaccesible (caso límite 15). */
export const AVATAR_UNAVAILABLE_LEGEND =
  'Tu efigie propia no responde en este instante: el canon viste la efigie canónica mientras tanto.';

/** Eventos que el componente emite sobre su raíz (plan §4.2). */
export const AVATAR_PICKER_EVENTS = Object.freeze({
  avatarChanged: 'panel:avatar-changed',
});

/**
 * Traduce un sobre de error a su leyenda solemne (la del backend tiene
 * precedencia; el mapa local solo entra ante sobre sin mensaje).
 *
 * @param {object} envelope Sobre del cliente { error: { code, message } }.
 * @returns {string} Leyenda en noble castellano; jamás cadena vacía.
 */
export function avatarErrorLegendFor(envelope) {
  const error = envelope?.error ?? {};
  if (typeof error.message === 'string' && error.message.trim() !== '') {
    return error.message;
  }
  return AVATAR_PICKER_ERROR_LEGENDS[error.code]
    ?? 'El canon respondió con un presagio indescifrable.';
}

/**
 * Índice heráldico local de los Linajes Canónicos (espejo del canon de
 * SPEC-07, mismas claves que el LINEAGE_INDEX del distintivo): la
 * afinidad rectora manda en la CARGA del sello que cada celda heráldica
 * del canon forja (RF-07.2 del santuario: el blasón se dibuja, no se
 * imprime; reutilización del arte existente, duda 5 de SPEC-12).
 */
const CATALOG_HERALDRY_ELEMENTS = Object.freeze({
  'rune-ignis': 'fire',
  'rune-aqua': 'water',
  'rune-fulgur': 'lightning',
  'rune-terra': 'earth',
  'rune-ventus': 'wind',
  'rune-lux': 'light',
  'rune-tenebrae': 'darkness',
  'rune-arcana': 'pureArcane',
});

/**
 * Crea el selector de efigie.
 *
 * @param {HTMLElement} mountRoot Punto de montaje (la sección Efigie).
 * @param {Object} options
 * @param {Object} options.panelClient Cliente del panel (fetchAvatarCatalog,
 *        chooseAvatar, removeAvatar; patrón del cliente de SPEC-12).
 * @param {(detail: object) => void} [options.onAvatarChanged] Canal del
 *        orquestador para el repintado de cabecera (complementa el
 *        evento del bus `panel:avatar-changed`).
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica inyectable (arneses sin navegador).
 * @param {Document} [options.documentRef] Documento anfitrión.
 * @param {EventTarget} [options.eventTarget] Bus (por defecto, la raíz).
 * @returns {Object} API: { render, destroy }.
 */
export function createAvatarPickerComponent(mountRoot, options = {}) {
  const {
    panelClient,
    onAvatarChanged,
    documentRef = globalThis.document,
  } = options;

  const elementFactory = options.elementFactory
    ?? ((tagName) => documentRef.createElement(tagName));
  const eventTarget = options.eventTarget ?? mountRoot;

  let destroyed = false;

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

  /**
   * Primer descendiente que satisface el predicado. La clase se
   * comprueba por DOS vías (classList y atributo className) para ser
   * válida tanto en DOM real como en el DOM simulado de los arneses.
   */
  function findDescendant(root, predicate) {
    for (const child of root?.children ?? []) {
      if (predicate(child)) return child;
      const found = findDescendant(child, predicate);
      if (found !== null) return found;
    }
    return null;
  }

  /**
   * Forja el sello rúnico heráldico de una entrada del canon (RF-03.1,
   * reutilización del arte de SPEC-02 RF-07). Ante entornos sin
   * createElementNS (arneses con DOM simulado) o clave desconocida,
   * degrada a null con dignidad: la celda conserva su leyenda.
   */
  function forgeHeraldicSeal(entry) {
    if (entry?.kind !== 'heraldry' || typeof entry.heraldryKey !== 'string') return null;
    const rulingElement = CATALOG_HERALDRY_ELEMENTS[entry.heraldryKey];
    if (rulingElement === undefined) return null;
    try {
      return createRuneSeal({
        houseName: String(entry.label ?? ''),
        coatOfArms: String(entry.heraldryKey),
        rulingElement,
        state: 'active',
        role: 'house',
        document: documentRef,
      });
    } catch {
      return null;
    }
  }

  /** Busca el primer descendiente con la clase dada (doble vía). */
  function findByClassName(root, className) {
    return findDescendant(root, (node) => node.classList?.contains?.(className)
      || (typeof node.className === 'string' && node.className.split(/\s+/).includes(className)));
  }

  /** Emite el repintado de cabecera por evento (plan §4.2). */
  function emitAvatarChanged(detail) {
    const EventCtor = globalThis.CustomEvent
      ?? class { constructor(type, options_ = {}) { this.type = type; this.detail = options_.detail ?? null; } };
    eventTarget.dispatchEvent(new EventCtor(AVATAR_PICKER_EVENTS.avatarChanged, { detail, bubbles: true }));
    onAvatarChanged?.(detail);
  }

  /** Pinta el aviso solemne por región viva del componente. */
  function announce(message) {
    const region = findDescendant(mountRoot, (node) => node.getAttribute?.('aria-live') === 'polite');
    if (region) region.textContent = message;
  }

  /** Muestra el aviso solemne nombrando el motivo (RF-03.2). */
  function showSolemnNotice(message) {
    const noticeSlot = findByClassName(mountRoot, 'avatar-picker__notice');
    if (noticeSlot) {
      noticeSlot.textContent = message;
      noticeSlot.setAttribute('role', 'status');
    }
    announce(message);
  }

  /** Limpia el aviso solemne. */
  function clearNotice() {
    const noticeSlot = findByClassName(mountRoot, 'avatar-picker__notice');
    if (noticeSlot) noticeSlot.textContent = '';
  }

  // -----------------------------------------------------------------
  // Rejilla del catálogo (RF-03.1: aria-pressed en la vigente)
  // -----------------------------------------------------------------

  /** Pinta la rejilla del canon con la vigente marcada. */
  function renderCatalog(catalog, current) {
    const grid = findByClassName(mountRoot, 'avatar-picker__grid');
    if (!grid) return;
    grid.replaceChildren?.();

    for (const entry of catalog) {
      const isCurrent = current?.kind === 'catalog' && current?.reference === entry.id;
      const cell = forge('button', {
        className: `avatar-picker__cell${isCurrent ? ' avatar-picker__cell--current' : ''}`,
        attrs: {
          type: 'button',
          'data-avatar-id': entry.id,
          'aria-pressed': String(isCurrent),
        },
      });
      // El sello heráldico forjado (RF-03.1): la celda del canon porta
      // SU blasón (SVG nativo) y SU leyenda; el identificador técnico
      // jamás se imprime (Art. V).
      const seal = forgeHeraldicSeal(entry);
      if (seal !== null) {
        seal.setAttribute('class', `${String(seal.getAttribute('class') ?? '')} avatar-picker__cell-seal`.trim());
        seal.setAttribute('aria-hidden', 'true');
        cell.appendChild(seal);
      }
      const label = forge('span', {
        className: 'avatar-picker__cell-label',
        text: entry.label,
      });
      cell.appendChild(label);
      cell.addEventListener('click', () => { void chooseFromCatalog(entry.id); });
      grid.appendChild(cell);
    }
  }

  /** Elige una efigie del canon (RF-03.1: efecto inmediato, sin moderación). */
  async function chooseFromCatalog(avatarId) {
    clearNotice();
    announce(AVATAR_PICKER_LEGENDS.choosing);
    const envelope = await panelClient.chooseAvatar('catalog', avatarId);
    if (destroyed) return;

    if (envelope?.success === true) {
      // Efecto inmediato + repintado de cabecera por evento (plan §4.2).
      const reference = envelope.data?.avatar?.reference ?? avatarId;
      emitAvatarChanged({ kind: 'catalog', reference, auditRecorded: envelope.data?.auditRecorded === true });
      announce('Tu efigie viste tu identidad.');
      await refreshCurrent();
      return;
    }

    showSolemnNotice(avatarErrorLegendFor(envelope));
  }

  // -----------------------------------------------------------------
  // Subida propia con previsualización (RF-03.2, RF-03.3)
  // -----------------------------------------------------------------

  /** Muestra la previsualización dentro del marco ceremonial cuadrado. */
  function showPreview(imageSrc) {
    const frame = findByClassName(mountRoot, 'avatar-picker__frame');
    if (!frame) return;
    frame.replaceChildren?.();
    const preview = elementFactory('img');
    preview.setAttribute('src', imageSrc);
    preview.setAttribute('alt', AVATAR_PICKER_LEGENDS.previewAlt);
    preview.className = 'avatar-picker__preview';
    frame.appendChild(preview);
    frame.setAttribute('data-has-preview', 'true');
  }

  /** Viste la efigie propia: el fichero viaja como multipart nativo. */
  async function uploadOwnImage(file) {
    if (!(file instanceof File)) {
      showSolemnNotice('Ninguna imagen llegó al santuario: envía tu efigie con el envío.');
      return;
    }
    clearNotice();
    const formData = new FormData();
    formData.append('mode', 'own');
    formData.append('image', file);

    const envelope = await panelClient.chooseAvatar('own', formData);
    if (destroyed) return;

    if (envelope?.success === true) {
      const reference = envelope.data?.avatar?.reference ?? null;
      emitAvatarChanged({ kind: 'own', reference, auditRecorded: envelope.data?.auditRecorded === true });
      announce('Tu propia efigie viste tu identidad.');
      await refreshCurrent();
      return;
    }

    showSolemnNotice(avatarErrorLegendFor(envelope));
  }

  /** Refresca la marca de la vigente tras cada acto consumado. */
  async function refreshCurrent() {
    const envelope = await panelClient.fetchAvatarCatalog();
    if (destroyed) return;
    if (envelope?.success === true) {
      renderCatalog(envelope.data?.catalog ?? [], envelope.data?.current ?? null);
    }
  }

  return {
    /**
     * Monta el picker y carga el canon en una sola carga (RNF-06).
     *
     * @param {object} [initialState] Estado inicial de la vitrina
     *        { kind, reference, unavailable } para pintar la vigente
     *        sin una segunda llamada (plan §4.1: el panel es una carga).
     */
    async render(initialState = null) {
      // Región viva del componente (los avisos de esta sección, jamás
      // el tictac de la convalecencia ni los veredictos ajenos).
      const liveRegion = forge('div', {
        className: 'avatar-picker__live',
        attrs: { 'aria-live': 'polite' },
      });
      mountRoot.appendChild(liveRegion);

      const notice = forge('p', { className: 'avatar-picker__notice', attrs: { role: 'status' } });
      mountRoot.appendChild(notice);

      // --- Caso límite 14: fallo de carga del canon con reintento ---
      let catalogEnvelope = null;
      try {
        catalogEnvelope = await panelClient.fetchAvatarCatalog();
      } catch {
        catalogEnvelope = null;
      }

      if (!catalogEnvelope || catalogEnvelope?.success !== true) {
        mountRoot.appendChild(forge('p', {
          className: 'avatar-picker__error',
          text: avatarErrorLegendFor(catalogEnvelope ?? {}),
        }));
        const retry = forge('button', {
          className: 'avatar-picker__retry panel-cta',
          text: AVATAR_PICKER_LEGENDS.retryLabel,
          attrs: { type: 'button' },
        });
        retry.addEventListener('click', () => {
          // Reintento solemne: re-render limpio sobre la misma raíz.
          while (mountRoot.children?.length) mountRoot.children.pop?.();
          void render(initialState);
        });
        mountRoot.appendChild(retry);
        return;
      }

      const catalog = catalogEnvelope.data?.catalog ?? [];
      const current = catalogEnvelope.data?.current ?? null;
      const restricted = catalogEnvelope.data?.restricted === true;

      // --- Caso límite 15: efigie propia inaccesible → canónico ---
      if (current?.unavailable === true) {
        showSolemnNotice(AVATAR_UNAVAILABLE_LEGEND);
      }

      // Cabecera de la vigente (kinds: catalog | own | default).
      const currentLegend = current?.kind === 'own' && !current?.unavailable
        ? 'Viste una efigie propia.'
        : current?.kind === 'catalog' && !current?.unavailable
          ? 'Viste una efigie del canon.'
          : 'Vistes la efigie canónica por defecto.';
      mountRoot.appendChild(forge('p', { className: 'avatar-picker__current', text: currentLegend }));

      // Rejilla del canon.
      mountRoot.appendChild(forge('h3', { text: AVATAR_PICKER_LEGENDS.catalogHeading }));
      const grid = forge('div', { className: 'avatar-picker__grid', attrs: { role: 'group', 'aria-label': AVATAR_PICKER_LEGENDS.catalogHeading } });
      mountRoot.appendChild(grid);
      renderCatalog(catalog, current);

      // Zona de subida: input[type=file] real con etiqueta (operable
      // sin ratón, RNF-03) + previsualización + marco cuadrado.
      mountRoot.appendChild(forge('h3', { text: AVATAR_PICKER_LEGENDS.uploadHeading }));
      const inputId = 'avatar-picker-input';
      const label = forge('label', { className: 'avatar-picker__label', text: AVATAR_PICKER_LEGENDS.uploadLabel, attrs: { for: inputId } });
      const input = forge('input', {
        className: 'avatar-picker__input',
        attrs: { type: 'file', id: inputId, accept: 'image/png,image/jpeg,image/webp' },
      });
      mountRoot.appendChild(label);
      mountRoot.appendChild(input);
      mountRoot.appendChild(forge('p', { className: 'avatar-picker__hint', text: AVATAR_PICKER_LEGENDS.uploadHint }));

      const frame = forge('figure', {
        className: 'avatar-picker__frame',
        attrs: { 'data-frame-legend': AVATAR_PICKER_LEGENDS.frameLegend },
      });
      mountRoot.appendChild(frame);

      // Si el adepto YA viste una efigie propia, el marco la exhibe
      // (plan §2.3: current.url / ownAvatar.url sirven el retrato).
      if (current?.kind === 'own' && typeof current?.url === 'string' && current.url !== '') {
        showPreview(current.url);
      }

      input.addEventListener('change', () => {
        const file = input.files?.[0] ?? null;
        if (file) {
          // Previsualización local (URL.createObjectURL nativo); si el
          // entorno no lo ampara (arneses, ficheros no-Blob), cae a una
          // referencia textual: la previsualización es cortesía, jamás
          // una condición del alta (la validación profunda es del
          // santuario).
          let previewSrc = `preview://${file.name}`;
          try {
            if (typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
              previewSrc = URL.createObjectURL(file);
            }
          } catch {
            previewSrc = `preview://${file.name}`;
          }
          showPreview(previewSrc);
        }
      });

      const uploadButton = forge('button', {
        className: 'avatar-picker__upload panel-cta',
        text: AVATAR_PICKER_LEGENDS.uploadButton,
        attrs: { type: 'button' },
      });
      uploadButton.addEventListener('click', () => {
        const file = input.files?.[0] ?? null;
        void uploadOwnImage(file);
      });
      mountRoot.appendChild(uploadButton);

      // El peregrino (restricted) solo contempla: toda escritura le
      // responderá 403 LINEAGE_OATH_REQUIRED desde el santuario; el
      // aviso solemne del picker narra el veredicto cuando llegue.
      void restricted;

      if (initialState && typeof initialState === 'object') {
        void initialState; // la marca de la vigente ya la narra el canon.
      }
    },

    /** Retira el componente y su estado. */
    destroy() {
      destroyed = true;
      mountRoot.replaceChildren?.();
    },
  };
}

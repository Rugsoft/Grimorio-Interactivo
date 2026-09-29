/**
 * landingSigilComponent.js — El Sello de Validación del Gran Portal
 * (SPEC-16, Tarea 4, RF-18.4 y RF-18.5).
 *
 * QUÉ ES Y POR QUÉ ESTÁ AQUÍ
 * --------------------------
 * La portada abre con este sello y es el ÚNICO elemento de firma de la
 * página (RF-18.4). No es decoración: codifica el estado editorial del
 * contenido, que es lo que SPEC-08 confiere al santuario. El título de la
 * portada se presenta dentro del marco que los hechizos ya validados por
 * los Maestros emplean, con la leyenda «Tomo validado» en el borde.
 *
 * DECISIÓN DE DISEÑO, Y POR QUÉ NO ES EL CANVAS
 * ---------------------------------------------
 * La obviedad habría sido un Canvas de partículas en el héroe (SPEC-05 ya
 * tiene motor). Se descartó por dos razones, ambas registradas en la
 * §10 de la spec: el Simulador de Grimorio ya gasta ese gesto, de modo que
 * repetirlo lo volvería wallpaper; y una animación en el aire hurtaría la
 * lectura justo en la página que más la necesita. El sello es un elemento
 * ESTÁTICO que encode información verdadera con un lenguaje visual que el
 * proyecto ya posee.
 *
 * CONSTITUCIÓN
 *   - Artículo I (Dogma Vanilla): ES Module nativo; cero dependencias.
 *   - Artículo IV: leyendas visibles en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *   - AGENTS.md 6.1: sin innerHTML; todo el texto viaja por textContent.
 *
 * DEGRADACIÓN (caso límite 6 de la SPEC-16)
 *   El sello NO puede ser un requisito de la portada. Si `createRuneSeal`
 *   no está disponible o el documento anfitrión no ofrece createElementNS
 *   —como en los DOM simulados de los arneses— el marco de texto forjado
 *   en HTML nativo persiste y la página sigue leyéndose. Perder la firma
 *   es aceptable; perder la tesis, no.
 *
 * El sello se forja con el MISMO módulo que las casas y los linajes, de
 * modo que la firma de la portada no inventa un segundo sistema heráldico.
 */

import { createRuneSeal, RUNE_SEAL_STATES } from './runeSealComponent.js';

/**
 * Leyenda visible del sello: el estado editorial del tomo, en castellano
 * solemne (RF-18.5). Es texto, no atributo: la leyenda DEBE verse.
 */
export const VALIDATION_SIGIL_LEGEND = 'Tomo validado';

/**
 * Nombre accesible del sello. Complementa —no sustituye— a la leyenda
 * visible, y explica el VALOR que la leyenda codifica.
 */
export const VALIDATION_SIGIL_LABEL =
  'Sello de validación: este tomo reúne hechizos moderados por los Maestros del Dominio.';

/** Clase raíz del sello, estable para el CSS y los arneses. */
export const VALIDATION_SIGIL_CLASS = 'landing-sigil';

/**
 * Fábrica del sello de validación.
 *
 * @param {HTMLElement} mountRoot Nodo donde se forja el sello.
 * @param {object} [options] Inyecciones para poder verificar sin navegador.
 * @param {(tagName: string) => HTMLElement} [options.elementFactory]
 *        Fábrica de elementos (por defecto, document.createElement).
 * @param {Document} [options.documentRef] Documento anfitrión del SVG.
 * @returns {{ render: () => void, destroy: () => void }}
 */
export function createValidationSigilComponent(mountRoot, options = {}) {
  const {
    elementFactory = (tagName) => globalThis.document.createElement(tagName),
    documentRef = globalThis.document,
  } = options;

  /** Nodo raíz forjado, para la limpieza determinista de destroy(). */
  let sigilRoot = null;

  /**
   * Forja el marco del sello en HTML nativo. Este marco es el que sobrevive
   * a cualquier degradación: si el SVG no puede crearse, el nombre, la
   * leyenda y el marco de oro siguen en pie.
   *
   * @returns {HTMLElement} Nodo raíz del sello.
   */
  function buildFrame() {
    const root = elementFactory('div');
    root.setAttribute('class', VALIDATION_SIGIL_CLASS);

    // role="img": el sello es una sola unidad gráfica con texto dentro; su
    // nombre accesible lo declara para que el lector de pantalla no lea
    // tres fragmentos sueltos (RF-18.5).
    root.setAttribute('role', 'img');
    root.setAttribute('aria-label', VALIDATION_SIGIL_LABEL);

    // El disco heráldico: reutiliza el arte de SPEC-02 RF-07 en vez de
    // dibujar una geometría nueva que luego habría que mantener.
    const sealHost = elementFactory('span');
    sealHost.setAttribute('class', `${VALIDATION_SIGIL_CLASS}__heraldry`);
    // Decorativo: el nombre accesible del marco ya dice qué es.
    sealHost.setAttribute('aria-hidden', 'true');
    root.appendChild(sealHost);

    // La leyenda del estado editorial: el PLUS del sello. Es lo que
    // convierte un adorno heráldico en información.
    const legend = elementFactory('span');
    legend.setAttribute('class', `${VALIDATION_SIGIL_CLASS}__legend`);
    legend.textContent = VALIDATION_SIGIL_LEGEND;
    root.appendChild(legend);

    return { root, sealHost };
  }

  /**
   * Intenta forjar el disco rúnico dentro del marco. El fallo NO propaga:
   * un sello sin disco sigue siendo un sello legible, y una excepción
   * aquí tumbaría la portada entera.
   */
  function forgeHeraldry(sealHost) {
    if (sealHost === null) return;
    try {
      if (typeof createRuneSeal !== 'function') return;
      const seal = createRuneSeal({
        houseName: 'Tomo del Grimorio',
        coatOfArms: 'tomo_validado',
        // El tomo moderado no pertenece a un elemento: su carga es el
        // propio arco del Arcano Puro, la misma que degrada la efigie
        // por defecto (RF-17.5) — el mismo lenguaje para el mismo gesto.
        rulingElement: 'pureArcane',
        state: RUNE_SEAL_STATES.ACTIVE,
        role: 'house',
        document: documentRef,
      });
      seal.setAttribute('class', `${VALIDATION_SIGIL_CLASS}__seal`);
      sealHost.appendChild?.(seal);
      sealHost.setAttribute('data-heraldry', 'forged');
    } catch {
      // Arnés sin createElementNS, o SVG no soportado: el marco de texto
      // permanece. Degradación elegante (AGENTS.md 8).
      sealHost.setAttribute?.('data-heraldry', 'degraded');
    }
  }

  /**
   * Monta el sello en el punto recibido.
   */
  function render() {
    destroy();
    if (mountRoot === null || mountRoot === undefined) return;

    const { root, sealHost } = buildFrame();
    sigilRoot = root;
    mountRoot.appendChild(root);
    forgeHeraldry(sealHost);
  }

  /** Retira el sello del árbol (idempotente). */
  function destroy() {
    sigilRoot?.remove?.();
    sigilRoot = null;
  }

  return { render, destroy };
}

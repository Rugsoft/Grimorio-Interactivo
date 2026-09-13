/**
 * manaBreakdownComponent.js — Panel reactivo del desglose pedagógico de maná.
 *
 * Tarea 5.3 (TASKS-04): renderiza en tiempo real (RF-04.1) el desglose
 * que produce SpellBalanceService (backend) o spellBalanceSimulator.js
 * (previsualización en cliente): sumatorio de efectos base, factores
 * multiplicadores geométricos, deducciones porcentuales por componentes,
 * coste final destacado, distintivo del Círculo Arcano y cartel luminoso
 * de Sobrecarga Arcana si el coste supera los 200 puntos (Art. II).
 *
 * La invocación de update(result) actualiza el DOM de forma inmediata
 * (< 50 ms, RNF-04): sin frameworks, sin DOM virtual, solo reescritura
 * dirigida de nodos ya creados.
 *
 * Seguridad (AGENTS.md 6.1): TODO dato del resultado se renderiza vía
 * textContent — jamás innerHTML. Etiquetas de círculos o leyendas hostiles
 * no pueden inyectar markup.
 *
 * Accesibilidad (RNF-04): la raíz es una región viva
 * (role="status" + aria-live="polite") para que los lectores de pantalla
 * anuncien cada cambio de coste sin interrumpir.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks.
 *   - Artículo IV: etiquetas solemnes en castellano (Círculos, Sobrecarga).
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 */

/** Techo de Sobrecarga Arcana (espejo de SPELL_BALANCE_CONSTANTS). */
const MAX_MANA_CEILING = 200;

/** Colores solemnes por Círculo (modificadores BEM en spell-creator.css). */
const CIRCLE_COLOR_CLASSES = Object.freeze({
  1: 'mana-breakdown__circle--1',
  2: 'mana-breakdown__circle--2',
  3: 'mana-breakdown__circle--3',
  4: 'mana-breakdown__circle--4',
  5: 'mana-breakdown__circle--5',
});

/**
 * Crea el panel del desglose pedagógico.
 *
 * @param {object} componentOptions Opciones:
 *   - elementFactory: fábrica de elementos (por defecto document.createElement;
 *     las pruebas inyectan su DOM simulado).
 * @returns {{root: HTMLElement, update: function(object): void}} Panel con su
 *   raíz accesible y el método de actualización reactiva update(result).
 */
export function createManaBreakdownComponent(componentOptions = {}) {
  const {
    elementFactory = (tagName) => document.createElement(tagName),
  } = componentOptions;

  /**
   * Helper de construcción segura: nodo con clase y texto (textContent).
   * @param {string} tagName Etiqueta del nodo.
   * @param {string} className Clase CSS del nodo.
   * @param {string} safeText Texto seguro (viaja como textContent).
   * @returns {HTMLElement}
   */
  function createTextElement(tagName, className, safeText) {
    const element = elementFactory(tagName);
    element.className = className;
    // Solo textContent: el dato jamás se interpreta como markup (XSS).
    element.textContent = safeText;
    return element;
  }

  /** Formatea un flotante sin arrastrar decimales fantasma (IEEE-754). */
  function formatNumber(value) {
    const rounded = Math.round(value * 100) / 100;
    return String(rounded);
  }

  // -----------------------------------------------------------------
  // Construcción estática del panel (los nodos nacen una sola vez;
  // update() solo reescribe su textContent — actualización inmediata).
  // -----------------------------------------------------------------
  const root = elementFactory('section');
  root.className = 'mana-breakdown';
  // Región viva (RNF-04): los lectores de pantalla anuncian los cambios.
  root.setAttribute('role', 'status');
  root.setAttribute('aria-live', 'polite');

  const titleElement = createTextElement('h3', 'mana-breakdown__title', 'Desglose de Maná');
  root.appendChild(titleElement);

  // --- Sumatorio de efectos base ponderados ---
  const baseRow = elementFactory('div');
  baseRow.className = 'mana-breakdown__row';
  baseRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Efectos base'));
  const baseValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__base', '—');
  baseRow.appendChild(baseValue);
  root.appendChild(baseRow);

  // --- Multiplicadores geométricos combinados ---
  const multipliersRow = elementFactory('div');
  multipliersRow.className = 'mana-breakdown__row';
  multipliersRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Multiplicadores (alcance × área × duración)'));
  const multipliersValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__multipliers', '—');
  multipliersRow.appendChild(multipliersValue);
  root.appendChild(multipliersRow);

  // --- Bruto antes de descuentos ---
  const grossRow = elementFactory('div');
  grossRow.className = 'mana-breakdown__row';
  grossRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Maná bruto'));
  const grossValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__gross', '—');
  grossRow.appendChild(grossValue);
  root.appendChild(grossRow);

  // --- Deducciones por componentes ---
  const discountsRow = elementFactory('div');
  discountsRow.className = 'mana-breakdown__row';
  discountsRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Descuento por componentes'));
  const discountsValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__discounts', '—');
  discountsRow.appendChild(discountsValue);
  root.appendChild(discountsRow);

  // --- Neto tras descuentos ---
  const netRow = elementFactory('div');
  netRow.className = 'mana-breakdown__row';
  netRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Maná neto'));
  const netValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__net', '—');
  netRow.appendChild(netValue);
  root.appendChild(netRow);

  // --- Coste final destacado (protagonista del panel) ---
  const finalRow = elementFactory('div');
  finalRow.className = 'mana-breakdown__row mana-breakdown__row--final';
  finalRow.appendChild(createTextElement('span', 'mana-breakdown__label', 'Coste final'));
  const finalValue = createTextElement('span', 'mana-breakdown__value mana-breakdown__final', '—');
  finalRow.appendChild(finalValue);
  root.appendChild(finalRow);

  // --- Distintivo del Círculo Arcano (coloreado por modificador BEM) ---
  const circleBadge = createTextElement('span', 'mana-breakdown__circle', 'Círculo pendiente');
  root.appendChild(circleBadge);

  // --- Cartel luminoso de Sobrecarga Arcana (Art. II, RF-03.2) ---
  const overloadCard = elementFactory('div');
  overloadCard.className = 'mana-breakdown__overload';
  overloadCard.setAttribute('role', 'alert');
  overloadCard.setAttribute('data-active', 'false');
  const overloadText = createTextElement(
    'span',
    'mana-breakdown__overload-text',
    `Sobrecarga Arcana: la concentración supera el techo de ${MAX_MANA_CEILING} puntos de maná.`,
  );
  overloadCard.appendChild(overloadText);
  root.appendChild(overloadCard);

  /**
   * Actualiza el panel con un resultado del desglose (contrato de
   * SpellCalculationResultDto o del simulador cliente).
   *
   * Degradación elegante: los campos ausentes muestran '—' sin lanzar.
   *
   * @param {object} result Desglose pedagógico (camelCase).
   */
  function update(result) {
    const safeResult = result ?? {};

    // 1. Sumatorio de efectos base.
    baseValue.textContent = safeResult.baseEffectPoints !== undefined ? formatNumber(safeResult.baseEffectPoints) : '—';

    // 2. Factores multiplicadores con su combinado.
    const multipliers = safeResult.multipliers ?? {};
    if (multipliers.combined !== undefined) {
      multipliersValue.textContent =
        `${formatNumber(multipliers.range ?? 0)} × ${formatNumber(multipliers.area ?? 0)} × ${formatNumber(multipliers.duration ?? 0)} = ${formatNumber(multipliers.combined)}`;
    } else {
      multipliersValue.textContent = '—';
    }

    // 3. Bruto.
    grossValue.textContent = safeResult.grossMana !== undefined ? formatNumber(safeResult.grossMana) : '—';

    // 4. Deducciones por componentes (porcentaje + importe).
    const discounts = safeResult.discounts ?? {};
    if (discounts.totalPercent !== undefined) {
      const totalPercent = Math.round(discounts.totalPercent * 100);
      const amountDeducted = discounts.amountDeducted !== undefined ? formatNumber(discounts.amountDeducted) : '?';
      discountsValue.textContent = `−${totalPercent}% (−${amountDeducted})`;
    } else {
      discountsValue.textContent = '—';
    }

    // 5. Neto.
    netValue.textContent = safeResult.netMana !== undefined ? formatNumber(safeResult.netMana) : '—';

    // 6. Coste final destacado (jamás ausente: es la esencia del panel).
    finalValue.textContent = safeResult.finalManaCost !== undefined ? String(safeResult.finalManaCost) : '—';

    // 7. Distintivo del Círculo Arcano, coloreado por su modificador BEM.
    circleBadge.textContent = safeResult.circleLabel ?? 'Círculo pendiente';
    // El color anterior se retira antes de aplicar el nuevo (RF-03.1).
    for (const colorClass of Object.values(CIRCLE_COLOR_CLASSES)) {
      circleBadge.classList.remove(colorClass);
    }
    const circleColorClass = CIRCLE_COLOR_CLASSES[safeResult.circle];
    if (circleColorClass) {
      circleBadge.classList.add(circleColorClass);
    }

    // 8. Cartel luminoso de Sobrecarga Arcana (rojo bermellón en CSS).
    const isOverloaded = safeResult.isOverloaded === true;
    if (isOverloaded) {
      overloadCard.setAttribute('data-active', 'true');
      overloadCard.classList.add('mana-breakdown__overload--active');
      root.classList.add('mana-breakdown--overloaded');
      if (safeResult.finalManaCost !== undefined) {
        overloadText.textContent =
          `Sobrecarga Arcana: ${safeResult.finalManaCost} de maná supera el techo de ${MAX_MANA_CEILING} puntos. Reduce la concentración de poder.`;
      }
    } else {
      overloadCard.setAttribute('data-active', 'false');
      overloadCard.classList.remove('mana-breakdown__overload--active');
      root.classList.remove('mana-breakdown--overloaded');
    }
  }

  return { root, update };
}

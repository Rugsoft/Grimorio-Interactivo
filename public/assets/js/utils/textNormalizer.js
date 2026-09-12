/**
 * textNormalizer.js — Normalizador de búsqueda del Grimorio Interactivo.
 *
 * Tarea 3.1 (TASKS-01): utilidad insensible a mayúsculas y tildes.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): String.prototype.normalize nativo (plan,
 *     Decisión 3); cero librerías tipo Lodash/Deburr.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Algoritmo (plan técnico 5.1 + criterio de la Tarea 3.1):
 *   1. Truncado duro a 100 caracteres (RF-03.4: evita abuso del buscador).
 *   2. Descomposición NFD y eliminación de marcas diacríticas (tildes, eñes).
 *   3. Minúsculas, colapso de espacios y retirada de puntuación: el criterio
 *      exige que '¡IGNICIÓN MÁGICA!' normalice a 'ignicion magica'.
 */

/** Tope estricto del término de búsqueda (RF-03.4). */
export const SEARCH_MAX_LENGTH = 100;

/** Longitud mínima para que la query filtre (plan 5.1). */
export const SEARCH_MIN_LENGTH = 2;

/**
 * Normaliza texto de búsqueda: sin diacríticos, en minúsculas, espacios
 * colapsados y truncado a 100 caracteres.
 *
 * @param {string} rawInput Texto crudo del usuario.
 * @returns {string} Texto normalizado, listo para comparar.
 */
export function normalizeSearchText(rawInput) {
  // 1. Truncado duro ANTES de normalizar (RF-03.4).
  const sanitized = String(rawInput ?? '').substring(0, SEARCH_MAX_LENGTH);

  // 2. Descomposición NFD: 'ó' se separa en 'o' + marca diacrítica U+0303.
  //    El rango [\u0300-\u036f] cubre todas las marcas combinantes.
  const withoutAccents = sanitized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  // 3. Minúsculas, retirada de puntuación (criterio: solo letras, números
  //    y espacios sobreviven) y colapso de espacios múltiples.
  const lowercased = withoutAccents.toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ');

  return lowercased.trim().replace(/\s+/g, ' ');
}

/**
 * Indica si un hechizo (por nombre o resumen) responde a la consulta.
 * Umbral del plan 5.1: menos de 2 caracteres útiles no filtra (retorna true).
 *
 * @param {string} spellText Texto del hechizo (nombre o resumen).
 * @param {string} normalizedQuery Consulta YA normalizada por normalizeSearchText.
 * @returns {boolean} true si el texto contiene la consulta o si no se filtra.
 */
export function matchesSearch(spellText, normalizedQuery) {
  // El umbral de filtrado se evalúa sobre la query ya normalizada.
  if (normalizeSearchText(normalizedQuery).length < SEARCH_MIN_LENGTH) {
    return true; // Sin filtrado: el término es demasiado corto.
  }

  const normalizedSpellText = normalizeSearchText(spellText);

  return normalizedSpellText.includes(normalizeSearchText(normalizedQuery));
}

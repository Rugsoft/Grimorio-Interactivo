# TASKS-07b: Tareas del Umbral de la Fundación

> **Espec:** [`specs/07b-clan-foundation-interface.spec.md`](07b-clan-foundation-interface.spec.md) — RATIFICADA (2026-09-28), con las tres decisiones de superficie cerradas (híbrido Salón+Vestíbulo, blasón oculto del modal, mini-tríada).
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Nota:** mini-tríada — esta tasks.md es la única pieza adicional; el plan técnico vive en la sección 10 de la spec (ficheros previstos).

---

## Fase Única — Implementación y verificación

- [ ] **Tarea 1 — El Umbral: componente y cableado**
  *Cubre:* SPEC-07b RF-10.1–RF-10.4, RF-10.7, RF-10.8, RNF-01–RNF-05. *Alcance:* crear `public/assets/js/components/clanFoundationModalComponent.js` (modal `<dialog>` con 3 sellos + régimen, 8 linajes con solo el jurado seleccionable, contador de nombre, leyendas de veto, trampa de foco, borrador conservado), anexar estilos solemnes con tokens de SPEC-02, añadir el `<dialog>` anfitrión a `public/index.html`, y cablear el gesto en `clansPreviewView` (gesto primario) y en la invitación del estado vacío del Vestíbulo (misma puerta, RF-10.6). Sin tocar `src/`. **Hecho cuando:** el ciclo completo funciona de punta a punta en el navegador local y `php -l`/carga de módulos sin errores.

- [ ] **Tarea 2 — Arnés de verificación**
  *Cubre:* SPEC-07b §9 (9 casos) y §8. *Alcance:* crear `scratch/test_clan_foundation_modal.mjs` (DOM simulado, patrón de los arneses hermanos `.mjs`): gesto por actor, apertura solemne, linaje rector, validación temprana, envío único, éxito 201, los 8 vetos del RF-10.5 con su leyenda exacta, descarte sin efecto y soberanía lingüística. **Hecho cuando:** el arnés ejecuta en verde (exit 0) y la batería de regresiones de componentes hermanos sigue en verde.

- [ ] **Tarea 3 — Cierre SDD**
  *Cubre:* §8 criterios de aceptación. *Alcance:* revisar el diff (cero ficheros de `src/` alterados), verificar los criterios en el navegador local, marcar checkboxes y registrar evidencia mínima. **Hecho cuando:** criterios en su estado real, diff revisado y resumen entregado.

---

## Bloqueos explícitos

- La implementación NO toca `src/` ni la base de datos: el contrato de SPEC-07 es ley cerrada y esta spec solo lo viste.
- Ningún gesto inhabilitado por el canon despacha petición (RNF-04): el backend es la última muralla, la interfaz jamás simula veredictos.
- El blasón JAMÁS se ofrece como editor en la fundación (RF-10.8): el sello determinista neutro es su cumplimiento y su edición pertenece al Patriarca.

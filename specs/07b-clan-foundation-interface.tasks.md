# TASKS-07b: Tareas del Umbral de la Fundación

> **Espec:** [`specs/07b-clan-foundation-interface.spec.md`](07b-clan-foundation-interface.spec.md) — RATIFICADA (2026-09-28), con las tres decisiones de superficie cerradas (híbrido Salón+Vestíbulo, blasón oculto del modal, mini-tríada).
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Nota:** mini-tríada — esta tasks.md es la única pieza adicional; el plan técnico vive en la sección 10 de la spec (ficheros previstos).

---

## Fase Única — Implementación y verificación

- [x] **Tarea 1 — El Umbral: componente y cableado**
- [x] **Tarea 1 — El Umbral: componente y cableado**
  *Cubre:* SPEC-07b RF-10.1–RF-10.4, RF-10.7, RF-10.8, RNF-01–RNF-05. *Alcance:* crear `public/assets/js/components/clanFoundationModalComponent.js` (modal `<dialog>` con 3 sellos + régimen, 8 linajes con solo el jurado seleccionable, contador de nombre, leyendas de veto, trampa de foco, borrador conservado), anexar estilos solemnes con tokens de SPEC-02, añadir el `<dialog>` anfitrión a `public/index.html`, y cablear el gesto en `clansPreviewView` (gesto primario) y en la invitación del estado vacío del Vestíbulo (misma puerta, RF-10.6). Sin tocar `src/`. **Hecho cuando:** el ciclo completo funciona de punta a punta en el navegador local y `php -l`/carga de módulos sin errores.
  *Ejecución (2026-09-28):* implementados los 7 ficheros del mini-plan (componente NUEVO, cableado en `lineageHallView`+`lineageHallComponent` con el gesto en la cabecera del Salón, segunda puerta en `vestibuleView` con `createVestibuleEmptyState`, `<dialog id="foundationModal">` en el shell, `clan-foundation.css` NUEVO, inyección en `main.js`). **Hallazgo de despliegue:** el puerto 8000 estaba ocupado por otro proyecto — verificación sobre puerto dinámico (lección H-2). **Verificación en navegador real de punta a punta:** consagración → interceptor del juramento (SPEC-09) → juramento sellado desde la UI → gesto habilitado → modal con foco y `solarCrown` único seleccionable → validación temprana → fundación real **201 CREATED** («Custodios del Alba Eterna») → Salón refrescado + cabecera de militancia → gesto vedado por lealtad indivisible; segunda puerta verificada con segunda cuenta (`abyssalShadows`) y veto real `NAME_ALREADY_RESERVED` con borrador intacto. **Dos hallazgos corregidos en vivo:** (1) `vestibuleView` leía el linaje del sitio equivocado del DTO (`adeptState.lineage`, no el estado superior); (2) la extracción del grupo de radios tomaba el primer radio del DOM en vez del marcado — un vedado podía encabezar el grupo. Limpieza: servidor de verificación detenido, 0 procesos residuales.

- [x] **Tarea 2 — Arnés de verificación**
- [x] **Tarea 2 — Arnés de verificación**
  *Cubre:* SPEC-07b §9 (9 casos) y §8. *Alcance:* crear `scratch/test_clan_foundation_modal.mjs` (DOM simulado, patrón de los arneses hermanos `.mjs`): gesto por actor, apertura solemne, linaje rector, validación temprana, envío único, éxito 201, los 8 vetos del RF-10.5 con su leyenda exacta, descarte sin efecto y soberanía lingüística. **Hecho cuando:** el arnés ejecuta en verde (exit 0) y la batería de regresiones de componentes hermanos sigue en verde.
  *Ejecución (2026-09-28):* arnés con **70 asertos** sobre DOM simulado cubriendo los 9 casos (canon, apertura, linajes, validación temprana, envío único, éxito, los 8 vetos con leyenda exacta, descarte/reapertura con borrador, lengua+RNF, y el veredicto de la vista [1b] con RNF-04 y la segunda puerta). **La Fase Roja del arnés sacó dos hallazgos que se corrigieron en el componente:** (1) `className` directo en lugar de `setAttribute('class')` — invisible para el DOM simulado canónico; (2) validación temprana incompleta (faltaban nombre y lema; añadidas `FOUNDATION_NAME_LENGTH_LEGEND` y `FOUNDATION_MOTTO_REQUIRED_LEGEND` con el orden del canon). Estado final: **70 PASA / 0 FALLA, exit 0**. Regresiones hermanas en verde: `test_admission_modal` (34), `test_lineage_hall_component` (97), `test_vestibule_view` (30), `test_clans_preview_view`, `test_clan_view`, `test_lineage_oath_modal` — todas exit 0.

- [x] **Tarea 3 — Cierre SDD**
  *Cubre:* §8 criterios de aceptación. *Alcance:* revisar el diff (cero ficheros de `src/` alterados), verificar los criterios en el navegador local, marcar checkboxes y registrar evidencia mínima. **Hecho cuando:** criterios en su estado real, diff revisado y resumen entregado.
  *Ejecución (2026-09-28):* **diff revisado:** 5 ficheros públicos modificados (+262/−5) + 3 nuevos (componente, CSS, arnés); **cero ficheros en `src/`** y **cero en `database/`** (verificado por diff dirigido) — el contrato de SPEC-07 queda intocado. Los 9 criterios de aceptación de la SPEC-07b quedan [x] con su evidencia (nota de honestidad en el criterio 1: los sellos son TRES + régimen por la decisión ratificada §10.2, no cuatro — el blasón no se pregunta, RF-10.8). Re-ejecución de cierre: arnés 70/0 exit 0 + 6 regresiones hermanas exit 0. **SPEC-07b queda CERRADA en conformidad plena verificada localmente (navegador real + arnés).** Pendiente explícito y fuera de esta spec: la conformidad en producción exigirá subir los ficheros públicos a `htdocs/` (mismo procedimiento de SPEC-15 Tarea 4.3).

---

## Bloqueos explícitos

- La implementación NO toca `src/` ni la base de datos: el contrato de SPEC-07 es ley cerrada y esta spec solo lo viste.
- Ningún gesto inhabilitado por el canon despacha petición (RNF-04): el backend es la última muralla, la interfaz jamás simula veredictos.
- El blasón JAMÁS se ofrece como editor en la fundación (RF-10.8): el sello determinista neutro es su cumplimiento y su edición pertenece al Patriarca.

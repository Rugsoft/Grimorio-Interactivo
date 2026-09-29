# TASKS-16: Tareas de la Recomposición de la Portada y la Navegación Agrupada

> **Espec:** [`specs/16-portal-composition-and-navigation.spec.md`](16-portal-composition-and-navigation.spec.md) — **RATIFICADA** (2026-09-29) por el custodio, sin reservas.
> **Espec madre:** [`specs/01-portal-and-navigation.spec.md`](01-portal-and-navigation.spec.md) (RF-01, RF-02) · [`specs/02-design-system-layout.spec.md`](02-design-system-layout.spec.md)
> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Nota:** mini-tríada — el plan técnico vive en la sección 11 de la spec (ficheros previstos).

**Decisiones ya ratificadas por el custodio (2026-09-29):** recomposición de portada **y** reordenación de la navegación en una sola entrega; distintivo de sesión corto (alias + efigie, linaje al desplegar); Regente como cinta compacta bajo el héroe, no se retira de la portada; tres grupos de dominio + *Inicio* suelto; firma = sello de validación en un solo sitio.

---

## Fase 0 — Pruebas que deben fallar antes de codificar (TDD constitucional)

- [ ] **Tarea 0 — Arnés rojo de la recomposición**
  *Cubre:* SPEC-16 §8 (13 criterios), RF-16.1–RF-16.7, RF-17.1–RF-17.5, RF-18.1–RF-18.9. *Alcance:* crear `scratch/test_portal_composition.mjs` (DOM simulado, patrón de los arneses hermanos `.mjs`) que afirme: (a) `NAV_GROUPS` existe con tres grupos que cubren los diez `NAV_LINKS` sin duplicar ningún `view`; (b) el render de la cabecera produce rótulos de grupo con `aria-expanded`/`aria-controls` y un submenú por grupo; (c) `Escape` y la pérdida de foco recogen el submenú; (d) el distintivo pinta solo el alias en el botón y la identidad completa en el desplegable; (e) `kind: 'default'` forja sello en la efigie; (f) la portada monta sello → héroe → cinta → destacados en ese orden, con un único `h1`; (g) el título del héroe consume los tres tokens del sistema.
  *Hecho cuando:* el arnés **falla** con aserciones rojas que nombran cada requisito aún incumplido. Este es el punto de partida obligatorio: sin rojo previo no hay prueba de que la prueba sirva.
  *Ejecución (2026-09-29):* creado `scratch/test_portal_composition.mjs` con **54 asertos** en 12 fases (DOM simulado propio, sin librerías, `innerHTML` prohibido como en los arneses hermanos). **Estado: 28 PASA / 26 FALLA, exit 1** — la FASE ROJA alcanzada, que es exactamente lo que la Tarea 0 exige. Los 26 fallos se agrupan en: agrupación de la navegación (7), rótulo corto y efigie por defecto (5), orden de bloques y sello de validación (6), tratamiento de título del sistema (5), cinta compacta del Regente (3).

  **Cuatro defectos DEL PROPIO ARNÉS encontrados y corregidos antes de darlo por bueno** (un arnés que miente sobre por qué falla es peor que uno que no existe):
  1. El DOM simulado carecía de `style.setProperty`, y `spellCardComponent` lo invoca para pintar afinidades → la Fase 6 reventaba con `TypeError` en vez de fallar con aserciones. Añadido (patrón de `test_landing_view`).
  2. Dos asertos **no vacuables** pasaban sin comprobar nada: `every()` sobre un array vacío devuelve `true`. Los de `role="menu"` y `aria-expanded` ahora exigen `length > 0` — sin submenús NO pueden pasar.
  3. El aserto del orden sin Dominio leía el DOM **sin esperar** el `render()` asíncrono; ahora lo espera.
  4. Los saltos por clase sin `await` y la búsqueda del botón del distintivo solo en `children` de primer nivel: el distintivo cuelga un nivel más abajo (`badgeRoot > badge > toggle`). Añadido `queryById` recursivo (patrón del hermano).

  **Tras las correcciones, los asertos distinguen bien la causa del fallo:** «El linaje jurado NO ocupa el rótulo visible» ahora **FALLA** (es el defecto D1 medido en producción: el botón sí muestra «Vestibulo7b — Linaje de las Sombras Abisales»), mientras que antes pasaba por el hallazgo accidental de un distintivo a medio montar.

  **Regresiones: cero daños.** Los 8 arneses hermanos de RNF-16.2 más `audit_css_ghost_tokens` salen **exit 0** (`test_navbar`, `test_user_profile_badge` 0 fallos, `test_clan_banner_component` 62/0, `test_landing_view` 0, `test_lineage_retention_nav` 37/0, `test_navbar_session_refresh` 0, `test_vestibule_route_badge` 27/0, `audit_css_ghost_tokens` verde): la Tarea 0 no ha tocado una sola línea de código de producción.

---

## Fase 1 — La cabecera

- [ ] **Tarea 1 — `NAV_GROUPS` y el render de los grupos**
  *Cubre:* RF-16.1, RF-16.2, RF-16.3, RF-16.4, RF-16.7; RNF-16.4, RNF-16.5. *Alcance:* en `public/assets/js/components/navbarComponent.js`, exportar `NAV_GROUPS` como mapa congelado derivado de `NAV_LINKS` (los diez `view` intactos, cero duplicados) y añadir el render de los rótulos de grupo con su submenú: botón con `aria-expanded`/`aria-controls`, panel con `role="menu"`, `li` con `role="none"`. Reaprovechar `activateLink()` y `handleLinkActivation()` sin duplicar manejadores: los enlaces del submenú son los mismos nodos lógicos con `data-view`/`data-action` intactos. `closeMobileMenu()` se extiende para recoger también los submenús. Regla de RF-16.5: por debajo de 1024 px se sigue pintando la lista plana, nunca grupos.
  *Hecho cuando:* `test_portal_composition.mjs` pone en verde (a)–(c); `test_navbar.mjs` sigue hallando `NAV_LINKS.length === 10` y el enlace del Creador con `data-action="openCreator"`.
  *Ejecución (2026-09-29):* implementado en `public/assets/js/components/navbarComponent.js`.

  **El contrato:** `NAV_GROUPS` es un array congelado de cuatro entradas — *Inicio* (1 destino), *Biblioteca* (3), *Linajes* (2) y *Sala de Trabajo* (4) — construido con `resolveLinks()`, que **lanza** si una vista no existe en `NAV_LINKS`. La agrupación no puede tragarse un destino: si alguien añade un enlace a `NAV_LINKS` y olvida el grupo, el módulo revienta en el arranque en vez de perder la sección en silencio. Los diez `data-view` no se tocan.

  **La interacción que faltaba decidir:** un rótulo que solo abre y cierra obliga a dos gestos para llegar a nada. El primer clic despliega el submenú; el segundo sobre el rótulo ya abierto navega a su primer destino (RF-16.3). Un grupo de un solo destino no lleva submenú: su rótulo *es* el enlace (caso límite 2), de ahí que existan tres submenús y no cuatro.

  **Verificación:** fases 1, 2, 3, 12 y 13 del arnés en verde. Añadida la **fase 13**, que no existía al redactar la spec: verifica el modo plano de RF-16.5 (cero grupos, cero submenús, los diez destinos), el arranque agrupado por omisión y el ida y vuelta en caliente con `setLayout()` sin pérdida de destinos. Es la fase que demuestra que la agrupación no invade al móvil.

  **Dos bugs reales del componente, hallados por el arnés y no por lectura:**
  1. `closeGroupMenu()` iteraba `findByClass(...)` (singular) como si fuera plural → `TypeError` en el Escape. Corregido a `findAllByClass`.
  2. **Bug de fondo, compartido con el arnés:** los barridos por clase consultaban solo `classes`/`classList`, pero este componente pinta con `setAttribute('class', …)` — la vía que exigen los DOM simulados (lección yaAprendida en SPEC-07b). El efecto era silencioso: el render producía los cuatro `<li class="site-nav__group">` correctos y el barrido no encontraba **ninguno**. Se unificó en `nodeHasClass()`, que consulta los tres estados (`classes`, `classList` y el atributo `class`). Un falso negativo que devuelve cero en vez de fallar es el peor tipo de defecto de prueba.

  **El Riesgo nº1 se materializó, tal como estaba escrito.** Cuatro arneses hermanos buscaban los enlaces como hijos directos de `linksList.children`, y con la agrupación viven dentro de los `<li>`. Adaptados **al contrato nuevo y con el motivo escrito en el propio código**: `test_navbar` (3 barridos), `test_navbar_session_refresh` (`findNavLink`), `test_vestibule_route_badge` (dos: el enlace y su aserto de supervivencia al apagado) y `probe_navbar_session_stale` (`findNavLink`). En todos los casos el requisito vigilado no cambió — «este destino existe y está cableado» —; lo que cambió es cómo se localiza, bajando a un barrido recursivo que seguiría siendo válido si mañana se volviera al plano. **Ningún aserto se relajó para que pasara.**

- [ ] **Tarea 2 — La cabecera a una fila (CSS)**
  *Cubre:* RF-16.1, RF-16.5; RNF-16.1, RNF-16.6, RNF-16.7. *Alcance:* en `public/assets/css/layout.css`, `flex-wrap: nowrap` en la lista por encima de 1024 px; `.site-nav__groups` con `flex: 1 1 auto` y `min-width: 0` para que sea el bloque que cede espacio; altura de cabecera acotada con `--space-ink-*` (objetivo medido: ≤ 96 px); estilos del rótulo de grupo y del panel (fondo `--color-surface-raised`, borde `--border-ink-strong`, radio `--radius-scroll`, `box-shadow: var(--shadow-arcane)`), con transición anulada bajo `prefers-reduced-motion`. **Prohibido** escribir un solo color hex crudo. Ningún token nuevo salvo que `audit_css_ghost_tokens.mjs` demuestre la necesidad (y entonces se documenta en SPEC-02 antes de usarse).
  *Hecho cuando:* verificación en navegador real a 1440×900 con `.site-header` ≤ 96 px y los diez destinos alcanzables; el guard de tokens fantasma sale en verde.
  *Ejecución (2026-09-29):* CSS escrito en `public/assets/css/layout.css` (sección 2bis) y **cableado de la disposición en `public/assets/js/main.js`**, que era la mitad de la Tarea y estaba sin hacer (ver «Hallazgo de alcance»).

  **Medición real en navegador (PHP 8.2, puerto dinámico 58211):**

  | Viewport | `.site-header` | Grupos | Anclas | Botón ☰ | Filas de rótulos |
  |----------|----------------|--------|--------|---------|------------------|
  | 1440 px | **63 px** | 4 | 10 | oculto | **1** (`top: 9` en los tres triggers) |
  | 1024 px | 63 px | 4 | 10 | oculto | 1 |
  | 800 px | 63 px | 0 (plano) | 10 | **flex** | — |
  | 390 px | 63 px | 0 (plano) | 10 | flex | — |

  Objetivo ≤ 96 px superado con holgura: **63 px, una sola fila**. De 157 px a 63 px. Los diez destinos siguen alcanzables por teclado (todos los triggers con `min-height: var(--touch-target-min)`) y el distintivo de dictámenes se painta también dentro de los paneles.

  **Verificado en el navegador, no solo en el arnés:** apertura de submenú (218×186 px bajo «Sala de Trabajo», con los cuatro destinos), `Escape` recoge el panel y **devuelve el foco al rótulo** (`document.activeElement` = `site-nav__group-trigger`), y modo plano con los diez destinos a **44 px** de zona táctil mínima (RNF-04).

  **Dos defectos que la Tarea destapó y que no estaban en la spec:**

  1. **Los quiebres no coincidían.** El CSS colapsaba el menú a 767.9 px pero el componente cambia de disposición a 1024 px. La franja 768–1024 quedaba con los diez enlaces sueltos desparramados, **sin botón que los recogiera**, y la cabecera llegaba a medir **493 px** (medido). Remedio: el colapso se generalizó a `max-width: 1023.9px` para que DOM y CSS compartan un solo punto de corte. El bloque se colocó **después** de la regla base de `.site-nav__toggle` a propósito, porque en CSS gana la última regla de igual especificidad y el botón nace oculto arriba.
  2. **`position: absolute` y `max-height` seguían solo en el bloque de 767.9 px**, así que a 800 px el menú desplegado tenía `panelH: 0` — se abría y no se veía nada. Generalizados al bloque de 1024 px.

  **Hallazgo de alcance (no era solo CSS).** Sin cablear `setLayout()` con `matchMedia` en `main.js`, la cabecera se habría agrupado también en el móvil, donde los enlaces cuelgan de submenús: medido, **solo «Inicio» era alcanzable y los otros nueve tenían tamaño 0×0**. La Tarea 2 no cubría el cableado y sin él el resultado de la Tarea 1 era inutilizable fuera del escritorio. Añadido en `main.js`: `isWideViewport(windowRef)` para la disposición inicial, oyente de `change` con **soltura en `destroy()`** (y respaldo de la API antigua `addListener`, que Safari/WebKit hasta 2020 no tiene `addEventListener` en `MediaQueryList`).

  **Defecto preexistente, NO introducido aquí y pendiente de la Tarea 3:** a 320–390 px el distintivo de sesión (493 px, el defecto D1) empuja el botón ☰ fuera de la pantalla (`x: 795` en un nav de 303 px). Comprobado con `git stash` sobre los tres ficheros de mi trabajo: **sin mis cambios el botón estaba en la misma posición**, o sea que el desbordamiento es heredado. Su remedio ya está especificado: es el RF-17.1 (rótulo corto: alias + efigie), Tarea 3.

  **Disciplina de tokens (RNF-16.1):** `layout.css` verificado con **0 hex crudos y 0 rgb literales** fuera de comentarios; `scratch/audit_css_ghost_tokens.mjs` en verde («SIN tokens fantasma»). Ningún token nuevo.

- [ ] **Tarea 3 — El distintivo de sesión corto y la efigie por defecto**
  *Cubre:* RF-17.1–RF-17.5; RNF-16.4. *Alcance:* en `public/assets/js/components/userProfileBadge.js`, separar el **rótulo visible** (solo `user.alias`) del **rótulo de identidad** (alias + linaje o estado solemne + hermandad, el texto que hoy ocupa el botón). El nombre accesible conserva hoy la identidad completa íntegra, incluido el sufijo «Abrir el menú arcano», para que ningún lector de pantalla pierda lo que el botón ya no muestra. La identidad completa se declara en la cabecera del desplegable. En `applyAvatarImage()`, el caso `default` deja de hacer `removeAttribute('style')` y retorna vacío: forja el **ouroboros del Arcano Puro** con `createRuneSeal()`, igual que degrada ya `catalog`; jamás un cuadro vacío.
  *Hecho cuando:* `test_portal_composition.mjs` pone en verde (d)–(e); en navegador real, el distintivo ocupa ≈ 150 px en vez de 493 px y la efigie muestra sello.
  *Ejecución (2026-09-29):* implementada en `public/assets/js/components/userProfileBadge.js` + `clans.css`.

  **Separación de las dos legends (RF-17.1):** `fullLegend` (identidad íntegra: alias + hermandad, o + linaje jurado, o + estado de peregrino) y `displayLegend` (solo el alias) son ahora cadenas distintas. El `aria-label` se compone desde `fullLegend`, de modo que **empieza por el texto visible** y cumple WCAG 2.5.3 *Label in Name*: acortar el rótulo no ha costado ni un bitón de información para un lector de pantalla.

  **Medición en navegador real (puerto dinámico 55923):**

  | Métrica | Antes | Después |
  |---------|-------|----------|
  | `.site-nav__session` | 493 px | **216 px** |
  | Rótulo visible | «Vestibulo7b — Linaje de las Sombras Abisales» | **«Vestibulo7b»** (134 px) |
  | Efigie `default` | cuadro vacío 46×46, `background-image: none` | **sello rúnico SVG** dentro del nodo |

  **El comentario ya prometía lo que el código no hacía.** El bloque `applyAvatarImage` decía «las efigies no heráldicas degradan al ouroboros del Arcano Puro — **jamás un cuadro vacío**», pero la rama `default` hacía `removeAttribute('style')` y retornaba. Corregido: `own` conserva su ruta, y **todo lo demás** (incluido cualquier `kind` desconocido que llegue en el futuro) cae al ouroboros en vez de desaparecer.

  **La efigie no es adorno: hay que elegir qué se trunca en móvil.** Al cerrar el desborde que la Tarea 2 documentó, la primera medición puso la efigie en **8 px** porque el alias la comprimía. La regla aplicada: *si algo tiene que truncarse, es el alias —que conserva su nombre accesible íntegro—, nunca la efigie*. Resultado a 320 px: marca 41 px con elipsis, efigie **46 px íntegra**, botón ☰ en pantalla, **cero desbordamiento horizontal**.

  **Defecto REAL que introdujo el primer intento del rótulo de identidad, y su arreglo de fondo.** Se coló como `<li>` dentro del `<ul role="menu">`. Está mal por dos motivos que no son de estilo: un `<ul role="menu">` solo admite `<li role="none">` con su `menuitem` dentro (un `<li>` de texto plano rompe la semántica para lectores de pantalla), y `handleKeydown` recorre `menuElement.children.map(c => c.children[0])` —el `<li>` de identidad se contaba como opción y las flechas saltaban a un `<span>` que no admite foco. **Medido: 8 asertos rojos en el hermano.** Arreglo de fondo: la identidad y el menú son **hermanos dentro de un panel común** (`.user-profile__panel`), con lo que el apilado es estructural y no hay dos anclajes absolutos que sincronizar. En el primer intento con dos absolutos independientes se solapaban **131 px**; con el panel común, **solapamiento 0** (rótulo 58–122, menú 122–314, panel 256×257).

  **Aserto del hermano adaptado (con el motivo escrito en el código).** `test_user_profile_badge` afirmaba que el alias aparecía en **1** hoja; con el rótulo de identidad aparecen **2** (el botón y el rótulo del desplegable) — y ambas son correctas. El aserto se recondujo a lo que realmente vigila: que el botón muestre exactamente el alias y que **no haya clan fantasma**. El requisito no cambió; cambió la forma de contarlo.

  **Verificación:** fases 4 y 5 en verde, con **dos casos gemelos añadidos** que la spec no contemplaba: el mismo adepto **sin hermandad** (el linaje jurado debe seguir en el nombre accesible) y el **peregrino sin juramento** (el botón nunca muestra cadena vacía y el desplegable declara «Peregrino sin Linaje»). Sin ellos, un cambio futuro podría acortar el rótulo y perder el linaje sin que nada lo notara. Hermanos: `test_user_profile_badge` 69/0; resto de la batería intacto.

---

## Fase 2 — La portada

- [ ] **Tarea 4 — El sello de validación (componente nuevo)**
  *Cubre:* RF-18.4, RF-18.5; RNF-16.1, RNF-16.5, RNF-16.6; caso límite 6. *Alcance:* crear `public/assets/js/components/landingSigilComponent.js` con `createValidationSigilComponent(mountRoot, options)`, siguiendo el patrón de inyección de `elementFactory`/`documentRef` de los componentes hermanos. Dibuja el marco reutilizando el lenguaje visual de `runeSealComponent` (`--sigil-disc`, `--sigil-tick`, `--sigil-ring-active`), con la leyenda visible «Tomo validado» y `role="img"` + `aria-label` descriptivo. **Sin** `getBBox()` ni APIs SVG que el DOM simulado no ofrezca, y con degradación si `createRuneSeal` no está disponible.
  *Hecho cuando:* el arnés pone en verde (f) y el sello se ve en navegador real sin romper con `prefers-reduced-motion`.
  *Ejecución:* _pendiente._

- [ ] **Tarea 5 — La recomposición de la portada**
  *Cubre:* RF-18.1, RF-18.2, RF-18.3, RF-18.6, RF-18.7, RF-18.9; RNF-16.1. *Alcance:* en `public/assets/js/views/landingView.js`, montar los bloques en el orden sello → héroe → cinta → destacados (hoy el Regente se monta **antes** del héroe y abre la página). Añadir las reglas ausentes de `.landing-hero__title` y `.landing-view__featured-title` en `public/assets/css/components/library.css` con `var(--font-arcane-title)` + `var(--font-size-title-page)` + `var(--color-gold-arcane)`, idéntico a `library-view__title`. Conservar intactos el comportamiento de error de RF-06.3 (el sello, el héroe y la cinta sobreviven a un fallo de la galería) y el `h1` único (caso límite 5).
  *Hecho cuando:* el arnés pone en verde (f)–(g); en navegador real a 1440×900 el CTA «Consagrar Linaje» es visible sin desplazamiento y el orden del DOM es el del RF-18.1.
  *Ejecución:* _pendiente._

- [ ] **Tarea 6 — La cinta compacta del Regente**
  *Cubre:* RF-18.7, RF-18.8, RF-18.9; caso límite 4. *Alcance:* variante compacta de `clanBannerComponent` (o un parámetro `variant: 'compact'` que no rompa el uso existente): una sola línea con blasón, nombre, lema y reinante; **sin** marco propio, heredando el de su contenedor (remedio de D4); el elemento rector se declara **una sola vez** (remedio de la duplicación medida). Estados vacío y de error conservados sin salto de layout. El lema largo se recorta con elipsis y su texto íntegro vive en el `title`.
  *Hecho cuando:* la cinta mide una línea en navegador real y el arnés de `clan_banner_component` ampliado sigue en verde con la variante anterior intacta.
  *Ejecución:* _pendiente._

---

## Fase 3 — Cierre SDD

- [ ] **Tarea 7 — Regresiones, guard y criterios**
  *Cubre:* §8 (13 criterios), RNF-16.1, RNF-16.2, RNF-16.3. *Alcance:* ejecutar `scratch/audit_css_ghost_tokens.mjs` y los ocho arneses de RNF-16.2 más el nuevo `test_portal_composition.mjs`; verificar los 13 criterios en navegador real a 1440×900 y a 375×812; confirmar con `git diff --stat` que ningún fichero bajo `src/` ni `database/` ha cambiado; marcar los checkboxes con su evidencia real.
  *Hecho cuando:* todo en verde (exit 0), criterios en su estado verdadero, diff revisado y resumen entregado al custodio.

---

## Riesgos y Contingencias Registrados

1. **Los arneses hermanos rondean contratos de la cabecera.** `test_navbar.mjs` y `test_lineage_retention_nav.mjs` inspeccionan `navLinks.children` como enlaces directos. Si la agrupación envuelve los enlaces en submenús, esos arneses deben **adaptarse al contrato nuevo de forma deliberada y documentada**, nunca maquillarse: un aserto que se cambia para pasar sin que el comportamiento haya cambiado es una mentira. Este es el riesgo nº1 de toda la entrega.
2. **SPEC-08 RF-05.4 (Torre de Deliberación) depende de `CONDITIONAL_NAV_LINKS`.** Si el grupo asignado desaparece o se reordena, el enlace por rol se pierde en silencio. Tarea 1 debe asertarlo explícitamente con un rol `master` y otro `reader`.
3. **El sello es un elemento nuevo en el aire de la portada.** Si compite visualmente con la cinta o con el título, la firma se habrá gastado dos veces. Verificación visual obligatoria: si el sello compite, se atenúa el marco; no se añade adorno nuevo.
4. **Riesgo de scope creep.** La tinta del proyecto es tentadora. Esta spec **no** toca `tokens.css` salvo excepción documentada en Tarea 2. Si una mejora parece necesitar un color nuevo, se anota como deuda para una spec de tokens, no se ejecuta aquí.

---

## Bloqueos Explícitos

- **Ninguna tarea de este fichero empieza antes de que el custodio ratifique la SPEC-16.** → **CUMPLIDO: ratificada el 2026-09-29.** La Tarea 0 arranca autorizada.
- La implementación NO toca `src/` ni `database/`: los contratos de SPEC-01, SPEC-07, SPEC-08 y SPEC-12 son ley cerrada.
- La conformidad en producción exigirá subir los ficheros públicos a `htdocs/` (procedimiento de `deploy/infinityfree/README.md` §9c), y su veredicto se registrará allí.

# SPEC-16: El Gran Portal y la Cabecera Ajustada — Recomposición de la Portada y Navegación Agrupada

> **Constitución:** [`constitution.md`](../constitution.md) | **Directrices:** [`AGENTS.md`](../AGENTS.md)
> **Espec madre:** [`specs/01-portal-and-navigation.spec.md`](01-portal-and-navigation.spec.md) (RF-01, RF-02, RF-06) · [`specs/02-design-system-layout.spec.md`](02-design-system-layout.spec.md) (§2 tokens, RF-06) · [`specs/07-clans-lineages.spec.md`](07-clans-lineages.spec.md) (Dominio semanal, Tarea 5.2) · [`specs/12-user-panel.spec.md`](12-user-panel.spec.md) (efigie de cabecera, RF-03.3)
> **Estado:** RATIFICADA (2026-09-29) — Tareas 0 a 6 ejecutadas. Cabecera en **63 px y una sola fila**; distintivo de **493 → 216 px** con efigie por defecto forjando sello; **cero desbordamiento horizontal hasta 320 px**; la portada **recompuesta** —firma → tesis → estado → destacados— con el título del héroe vestido del sistema, el CTA cerrando en y=372 sobre 900 px y el Regente reducido a **cinta de una línea: 435 → 89 px y cero marco**. Arnés propio **98/0**. Falta solo el cierre SDD (Tarea 7).
> **Área:** Superficie de interfaz del Santuario (Dogma Vanilla, Artículo I)
> **Naturaleza:** ENMIENDA de superficie. No altera ningún contrato de backend de SPEC-01, SPEC-02, SPEC-07 ni SPEC-12: solo*viste* lo que ya existe con una jerarquía que hoy no está.

---

## 1. Contexto y Objetivo

Una verificación visual de la portada en navegador real (2026-09-29, viewport 1440×900, PHP 8.2 local) destapó cinco defectos **medidos sobre el DOM real**, no opinados:

| # | Defecto | Medición verificada |
|---|---------|---------------------|
| D1 | La cabecera `position: sticky` mide **157 px** y reparte los enlaces en **tres filas** (`top` de los `li`: 8 / 56 / 104) | `.site-header` 1423×157; `.site-nav__links` solo 501 px de los 1423 disponibles |
| D2 | `.landing-hero__title` (H1) y `.landing-view__featured-title` (H2) **no tienen ninguna regla CSS** en todo `public/assets/css`; se resuelven con el estilo de agente de usuario (`LoreReadable 700 24px`) | `grep` sin resultados; cómputo = 24 px, familia de cuerpo |
| D3 | El avatar por defecto es un **cuadrado negro vacío** de 46×46 (`data-avatar-kind="default"`, `background-image: none`, sin contenido) | `rgb(36,32,28)` con borde `rgb(61,53,43)`, `innerHTML` vacío |
| D4 | **Doble encuadre** en el bloque que abre la página: `.landing-view__regent` pinta borde y `.clan-banner__regent` pinta otro | Dos cajas concéntricas visibles; el Regente mide 313 px de alto — **REMEDIADO** (Tarea 6: 435 → 89 px, cero marco) |
| D5 | El CTA «Consagrar Linaje» **queda bajo el pliegue**: `.landing-hero` arranca en `top: 762` en un viewport de 900 | Botón no visible sin *scroll* |

La causa raíz de D1 es aritmética, no estética: la marca ocupa 270 px y el distintivo de sesión **493 px** («Vestibulo7b — Linaje de las Sombras Abisales»), de modo que a la lista de enlaces —único bloque que puede escribirse— solo le quedan 501 px, y el `justify-content: space-between` de `layout.css` reparte el daño comprimiendo precisamente lo que debería ser flexible. El defecto de D2 es una laguna de cobertura: las otras cinco vistas de título del santuario (`library-view__title`, Códice, Creador, Panel, Salón) sí llevan Cinzel + oro + `--font-size-title-page`; la portada se salta el sistema visual en su primera palabra.

**Objetivo:** dar a la portada **una tesis y un elemento focal**, comprimir la cabecera a una sola fila conservando acceso completo a las diez secciones, y cerrar los cinco defectos con tokens ya ratificados por SPEC-02.

---

## 2. Alcance y Exclusiones

**En alcance:**
- Cabecera persistente: anchura de una sola fila en escritorio y agrupamiento de las diez secciones en tres grupos de dominio.
- Distintivo de sesión: rótulo corto (alias + efigie); el linaje jurado pasa al desplegable, donde ya se declara.
- Portada: recomposición del orden de bloques, tratamiento de título del sistema, sello de validación como elemento firma, cinta compacta del Regente y CTA dentro del pliegue.
- Efigie por defecto: respaldo visible en el nodo de cabecera.
- Arneses `.mjs` de navbar, distintivo, blasón y portada, y ampliación del guard de tokens CSS.

**Fuera de alcance (prohibido bajo esta spec):**
- Cualquier cambio en `src/` o `database/`: los contratos de SPEC-01, SPEC-07 y SPEC-12 son ley cerrada.
- Cambiar la paleta, las familias tipográficas o cualquier valor de `tokens.css` existente. Esta spec **consume** tokens; no los redefine. La única excepción admitida es un token nuevo de trazo (§6, T2), y solo si el guard lo demuestra necesario.
- Modificar el orden de los tres hechizos destacados, la lógica de `fetchFeatured` o el comportamiento de paginación (RF-01.2, RF-03.7).
- Retirar el Regente de la portada: **desciende de sitio, no desaparece** (decisión ratificada §10.2).
- Menú de secondo nivel persistente en escritorio: la agrupación es de rótulos agrupados, no un mega-menú desplegable (decisión ratificada §10.3).

---

## 3. Actores

* **El Visitante:** anónimo o `lector`. Ve la portada completa, accede a Biblioteca, Salón de Linajes, Códice y Simulador sin vínculo.
* **El Adepto Vinculado:** con sesión activa. La cabecera le muestra alias y efigie, y el linaje jurado le aparece al desplegar el distintivo.
* **El Adepto Jurador:** `master` o `supremeAdmin`. El grupo de dominio que contiene «Torre de Deliberación» sigue gobernado por rol (`CONDITIONAL_NAV_LINKS`), intacto.

---

## 4. Historia de Usuario

* **HU-16a (La tesis del Portal):**
  *Como* visitante que acaba de cruzar el umbral de la URL,
  *Quiero* que la portada me diga de inmediato qué es este lugar y qué puedo hacer aquí,
  *Para* no tener que desplazar la página para encontrar la llamada a la acción.

* **HU-16b (La Cabecera Ajustada):**
  *Como* adepto en un monitor de escritorio,
  *Quiero* la navegación completa en una sola fila que no me robe la pantalla,
  *Para* que el contenido del santuario ocupe el sitio que le corresponde.

---

## 5. Requisitos Funcionales (Notación EARS en Español)

### RF-16: La Cabecera Ajustada (enmienda de RF-02.1)

* **RF-16.1 [Ubicuo]:**
  El sistema DEBERÁ mantener en la cabecera, en pantalla de escritorio (≥ 1024 px), la marca, la navegación agrupada y el distintivo de sesión **en una sola fila** (`flex-wrap: nowrap`), sin que la altura de la cabecera supere los **96 px**.
  *Nota de ejecución (2026-09-29):* medido en 63 px. El punto de corte de **1024 px es único y compartido por el DOM y el CSS**: el componente cambia de disposición con `matchMedia('(min-width: 1024px)')` y el colapso visual usa `max-width: 1023.9px`. Si divergieran, la franja entre ambos quiebres quedaría con los enlaces sueltos y sin botón que los recogiera.
* **RF-16.2 [Ubicuo]:**
  El sistema DEBERÁ agrupar las diez secciones existentes en **tres grupos de dominio** rotulados: *Biblioteca* (Biblioteca de Hechizos, Mi Grimorio, Códice de Afinidades), *Linajes* (Salón de Linajes, Hermandades) y *Sala de Trabajo* (Simulador de Grimorio, Creador de Hechizos, Atrio de Pruebas, Bitácora de Auditoría). *Inicio* permanecerá como enlace suelto junto a la marca, por ser la raíz del recorrido.
* **RF-16.3 [Dirigido por Eventos]:**
  CUANDO el usuario active un rótulo de grupo, el sistema DEBERÁ desplegar el **submenú** de ese grupo con sus enlaces reales (`data-view` intacto), y navigate al primer enlace del grupo solo si este ya estaba desplegado.
* **RF-16.4 [Dirigido por Eventos]:**
  CUANDO el usuario navegue a cualquier sección, al pierda el foco o al active `Escape`, el submenú abierto DEBERÁ recogerse y su rótulo volver a `aria-expanded="false"`.
* **RF-16.5 [No Deseado / Excepción]:**
  SI el viewport es menor de 1024 px, ENTONCES el sistema DEBERÁ conservar el comportamiento existente de `RF-02.4`: la lista completa se colapsa tras el botón `☰` con `aria-expanded`, **sin** agrupar y **sin** submenús. El agrupamiento es un lujo del escritorio, nunca un requisito.
* **RF-16.6 [Estado]:**
  MIENTRAS el usuario autenticado tenga un dictamen a la espera, el distintivo de dictámenes del grupo *Linajes* DEBERÁ seguir luciendo su cifra (SPEC-10 RF-01.1) en el enlace *Hermandades*, con su texto íntegro para lectores de pantalla.
* **RF-16.7 [Estado]:**
  El enlace *Torre de Deliberación* (SPEC-08 RF-05.4) DEBERÁ conservar su gobierno por rol: jamás aparece para roles sin facultad de moderar, y cuando aparece, se aloja en el grupo que le corresponda.

### RF-17: El Distintivo de Sesión Brevity (enmienda de SPEC-07 RF-07.1)

* **RF-17.1 [Ubicuo]:**
  El sistema DEBERÁ mostrar en la cabecera **solo el alias** del vinculado, acompañado de su efigie. El linaje jurado y el nombre del clan **NO** DEBERÁN ocupar el rótulo visible de la cabecera.
* **RF-17.2 [Dirigido por Eventos]:**
  CUANDO el usuario despliegue el distintivo, ENTONCES el sistema DEBERÁ declarar en la cabecera del desplegable la identidad completa: alias, linaje jurado o estado solemne, y hermandad, con el rótulo que la versión actual pintaba en el botón.
* **RF-17.3 [No Deseado / Excepción]:**
  SI el visitante aún no ha jurado linaje (peregrino iniciático), ENTONCES el distintivo DEBERÁ conservar la leyenda neutra en su declaración del desplegable, y el botón DEBERÁ mostrar el alias desnudo, nunca una cadena vacía.
* **RF-17.4 [Ubicuo]:**
  El nombre accesible del botón DEBERÁ conservar la identidad completa actual (alias, linaje, oficio,Invoker yZdicho «Abrir el menú arcano»), de modo que la pérdida de texto visible **no**|Alberga| pérdida de información para lectores de pantalla.
* **RF-17.5 [Estado]:**
  La efigie DEBERÁ exhibir siempre un respaldo visible cuando no hay imagen propia: el `kind: 'default'` DEBERÁ forjar el **ouroboros del Arcano Puro** (el mismo arte de SPEC-02 RF-07), jamás un cuadro vacío. Este es el remedio directo de D3.

### RF-18: La Portada Compuesta (enmienda de RF-01.1)

* **RF-18.1 [Ubicuo]:**
  El sistema DEBERÁ ordenar los bloques de la portada en esta secuencia: **sello de validación** (firma), **héroe** (título, lema y CTA), **cinta del Regente** y **Pergaminos Destacados**. El Regente **no**abrirá la página por delante de la tesis.
* **RF-18.2 [Ubicuo]:**
  El título del héroe y el de la galería destacada DEBERÁN llevar el tratamiento de título del sistema: `var(--font-arcane-title)`, `var(--font-size-title-page)` y `var(--color-gold-arcane)`, idéntico al de las otras cinco vistas de título. Es el remedio directo de D2.
* **RF-18.3 [Ubicuo]:**
  El héroe DEBERÁ exhibir su título, una sola línea de.presentation y el CTA «Consagrar Linaje» **sin necesidad de desplazamiento** en un viewport de 900 px de alto, con el banner del Regente ya montado.
* **RF-18.4 [Ubicuo]:**
  La página DEBERÁ exhibir un **único** elemento de firma: el sello de validación del héroe. Ninguna otra superficie de la portada inventará adorno propio.
* **RF-18.5 [Ubicuo]:**
  El sello de validación DEBERÁ declarar en su leyenda visible que el tomo reúne contenido moderado por los Maestros (*«Tomo validado»*), y su borde DEBERÁ reutilizar el lenguaje visual de `runeSealComponent` (metal `--sigil-ring-active`, muescas `--sigil-tick`, disco `--sigil-disc`). No es decoración: **codifica el estado editorial del contenido**, que es lo que SPEC-08 §2 confiere al sanctuary.
* **RF-18.6 [No Deseado / Excepción]:**
  SI la galería de destacados falla (RF-06.3), ENTONCES el sello, el héroe y la cinta del Regente DEBERÁN permanecer intactos: el estado de error se limita a la sección que falla, como ya ocurría.
* **RF-18.7 [Ubicuo]:**
  El Regente DEBERÁ descender a una **cinta de una sola línea** bajo el héroe: blasón, nombre del clan, lema y reinante, **sin** marco propio, heredando el marco de su contenedor. Es el remedio directo de D4.
* **RF-18.8 [No Deseado / Excepción]:**
  SI no hay Clan Regente en curso, o la consulta del Dominio falla, ENTONCES la cinta DEBERÁ exhibir los estados vacíos y de error que `clanBannerComponent` ya traduce (RF-06.3), **sin** hueco de reserva ni salto de layout.
* **RF-18.9 [Ubicuo]:**
  La cinta del Regente **no** SHALL repetir el dato: el nombre del clan y su lema bastan, y el elemento rector se declara **una sola vez**. Es el remedio directo de la duplicación medida.

---

## 6. Requisitos No Funcionales (RNF)

* **RNF-16.1 (Disciplina de tokens):** toda la tinta, tipografía, espaciado y radio de esta recomposición DEBERÁ viajar por `var(--token)` desde `tokens.css`. **Cero colores hex crudos** en las hojas tocadas, y cero nombres de token inexistentes — el guard `scratch/audit_css_ghost_tokens.mjs` DEBERÁ seguir en verde (exit 0).
* **RNF-16.2 (Amortiguación de regresión):** los arneses hermanos `test_navbar`, `test_user_profile_badge`, `test_clan_banner_component`, `test_landing_view`, `test_lineage_retention_nav`, `test_navbar_session_refresh`, `test_vestibule_route_badge` y `test_probe_navbar_session_stale` DEBERÁN seguir en verde, **actualizados** donde el contrato haya cambiado de forma deliberada y solo entonces.
* **RNF-16.3 (Sin `src/`):** el diff de esta spec NO PODRÁ alterar fichero alguno bajo `src/` ni `database/`.
* **RNF-16.4 (Soberanía lingüística):** los rótulos de grupo, la leyenda del sello y la cinta del Regente DEBERÁN ir en castellano solemne (Artículo IV). Ninguna clave técnica de dominio (`library`, `solarCrown`, `openCreator`) DEBERÁ ser visible.
* **RNF-16.5 (Accesibilidad):** los submenús de grupo DEBERÁN governance con `aria-expanded`, `aria-controls` y `role="menu"`; su apertura y cierre DEBERÁN ser alcanzables por teclado, y el foco DEBERÁ regresar al rótulo del grupo al cerrarse (RNF-03 madre).
* **RNF-16.6 (Movimiento reducido):** la animación de apertura del sello y del submenú DEBERÁ anularse bajo `prefers-reduced-motion: reduce` (RNF-08.5 madre).
* **RNF-16.7 (Responsivo):** la composición DEBERÁ permanecer legible y operable desde 320 px hasta ultrawide (RNF-04 madre). Por debajo de 1024 px rige `RF-16.5` de la cabecera.

---

## 7. Casos Límite y Reglas de Contingencia

1. **Alias exceptionally largo:** el distintivo DEBERÁ truncar el alias con elipsis visual y conservar la identidad íntegra en el nombre accesible. El linaje, al estar fuera del rótulo, ya no compite por el ancho.
2. **Grupo con un solo enlace:** el rótulo DEBERÁ delegar directamente y NO MOSTRAR submenú (evita un clic inútil). Aplica hoy a *Linajes* si el Regente lo desciende, y a cualquier grupo futuro que se quede con un destino.
3. ** Rol que pierde facultad durante la sesión:** el enlace *Torre de Deliberación* se retira en el siguiente `setSession()`; el submenú abierto se recoge íntegro y la cabecera vuelve a una fila.
4. **Cinta del Regente con motivo largo:** el lema se recorta a una línea con elipsis; el texto íntegro vive en el `title` y en la ficha del linaje del Salón.
5. **`<h1>` único:** la portada conserva **un solo** H1 (el título del héroe). El sello es un `div` con `role="img"` y su leyenda en `aria-label`; la cinta y la galería encabezan con `h2`.
6. **Arnés sin navegador:** los arneses `.mjs` con DOM simulado siguen siendo la puerta de verificación primaria; el sello NO DEBERÁ requerir `getBBox()` ni APIs SVG que el DOM simulado no ofrezca, o el arnés declarará su propia Ayuda de fábrica.

---

## 8. Criterios de Aceptación (verificables)

- [x] `.site-header` mide **≤ 96 px** de alto a 1440×900 con los diez enlaces pintados. *(Medido: 63 px, una fila.)*
- [x] `.site-nav__links` no presenta más de una fila (`navRows` con un único valor de `top`). *(Medido: `top: 9` en los tres triggers.)*
- [x] La cabecera muestra **tres rótulos de grupo** más *Inicio*, y los diez destinos siguen siendo alcanzables por teclado y por clic.
- [x] Los submenús abren, cierran con `Escape` y con la pérdida de foco, y devuelven el foco al rótulo.
- [x] El distintivo de cabecera muestra el alias sin el linaje, con efigie visible; el desplegable declara la identidad completa. *(Medido: 216 px; rótulo «Vestibulo7b».)*
- [x] `data-avatar-kind="default"` **no** produce un cuadro vacío: forja el ouroboros del Arcano Puro. *(Verificado: `<svg class="user-profile__avatar-seal">` dentro del nodo.)*
- [x] La portada exhibe **un único** elemento de firma: el sello de validación, con SVG en línea y leyenda «Tomo validado». *(Verificado en navegador real: 44 px, Cinzel dorado; sin `getBBox`.)*
- [x] El `<h1>` del héroe computa `MedievalArcaneTitle` a `2.25rem` en oro, idéntico a `library-view__title`. *(Medido: `36px`, `rgb(212, 169, 78)`.)*
- [x] El CTA «Consagrar Linaje» es visible **sin desplazamiento** a 1440×900 con el Regente montado. *(Medido: cierra en y=372 sobre 900 px.)*
- [x] El orden de bloques en el DOM es: sello → héroe → cinta → destacados. *(Medido en navegador real.)*
- [x] La cinta del Regente no pinta marco propio y mide **una sola línea** de contenido. *(Medido: `border` 0 px en la ficha, 40 px de alto y una sola fila a 1440, 1024 y 800 px; a 390 px envuelve a dos filas sin desbordar.)*
- [ ] Ninguna cadena visible contiene `data-view`, `data-action` ni una clave de linaje en camelCase.
- [x] `scratch/audit_css_ghost_tokens.mjs` y los ocho arneses hermanos de `RNF-16.2` salen en verde. *(Verificado: guard «SIN tokens fantasma»; hermanos 20/0, 69/0, 62/0, 20/0, 37/0, 27/0, 27/0 y sonda exit 0.)*
- [ ] `git diff --stat` no lista ningún fichero bajo `src/` ni `database/`.

---

## 9. Contrato de Superficie (nombres exactos)

**CSS (nuevos selectores, todos en `public/assets/css/`)**

| Selector | Fichero | Papel |
|----------|---------|-------|
| `.site-nav__groups` | `layout.css` | Lista de rótulos de grupo (≥1024 px) — clase que el componente escribe en la `<ul>` (RESUELTO en Tarea 2) |
| `.site-nav__group` | `layout.css` | Rótulo de grupo + botón de su submenú |
| `.site-nav__group-menu` | `layout.css` | Panel desplegable del grupo |
| `.site-nav__links--flat` | `layout.css` | Lista plana de `<1024 px` (la de RF-02.4) |
| `.landing-sigil` | `library.css` | Contenedor del sello de validación |
| `.landing-hero__title` | `library.css` | **Añadido** — tratamiento de título del sistema |
| `.landing-view__featured-title` | `library.css` | **Añadido** — idem, nivel `h2` |
| `.landing-view__regent` | `library.css` | Cinta compacta: sin marco, una línea |
| `.landing-view__featured` | `library.css` | Conserva marco; sin doble caja |
| `.clan-banner--compact` | `clans.css` | Raíz del blasón en variante de cinta |
| `.clan-banner__regent--compact` | `clans.css` | Ficha del Regente reducida a una línea; sin marco |
| `.clan-banner__proclamation:empty` | `clans.css` | La proclamación vacía no reserva hueco (RF-18.8) |

**JS (nuevos exports)**

| Export | Módulo | Papel |
|--------|--------|-------|
| `NAV_GROUPS` | `navbarComponent.js` | Array congelado de 4 dominios `{ id, label, links[] }` derivado de `NAV_LINKS` (RESUELTO en Tarea 1) |
| `NAV_LAYOUTS` / `setLayout()` | `navbarComponent.js` | `grouped` (omisión) / `flat` — el orquestador lo conmuta al cruzar 1024 px (RESUELTO en Tarea 1) |
| `navGroupMenuId()` | `navbarComponent.js` | Id estable del panel de un grupo, para `aria-controls` |
| `createValidationSigilComponent` | **NUEVO** `landingSigilComponent.js` | Sello de validación del héros |
| `CLAN_BANNER_VARIANTS` | `clanBannerComponent.js` | `heraldic` (omisión, la de SPEC-07) / `compact` (la cinta de RF-18.7) |
| `CLAN_BANNER_COMPACT_CLASS` / `CLAN_BANNER_COMPACT_ROOT_CLASS` | `clanBannerComponent.js` | Clases modificadoras que la hoja lee; la variante anterior no las recibe nunca |
| `COMPACT_SESSION_BADGE` | `userProfileBadge.js` | Bandera de rótulo corto (por defecto `true`) — **no llegó a hacer falta**: `fullLegend` y `displayLegend` son cadenas distintas desde el inicio, sin bandera configurable (RESUELTO en Tarea 3) |

Los diez `NAV_LINKS` y sus `data-view` **no se tocan**: la agrupación es de presentación, y `test_navbar` sigue hallando `NAV_LINKS.length === 10` en verde.

---

## 10. Decisiones de Diseño Ratificadas por el Custodio (2026-09-29)

> **RATIFICACIÓN FORMAL (2026-09-29):** el custodio ratifica la SPEC-16 tal cual está escrita, sin reservas ni reservas condicionales, y autoriza la ejecución de la Tarea 0 (arnés rojo). La tríada SDD queda abierta en su Fase 0.

1. **Alcance:** «Defectos + recomponer la portada» **y** «Reordenar la navegación» — ambas amendments, en una sola entrega.
2. **Distintivo de sesión:** alias + efigie en cabecera; el linaje jurado pasa al desplegable (opción recomendada, adoptada).
3. **Regente:** cinta compacta bajo el héroe, sin doble marco. **No** se retira de la portada.
4. **Agrupación:** tres grupos de dominio (*Biblioteca*, *Linajes*, *Sala de Trabajo*) + *Inicio* suelto. Descartado el mega-menú desplegable: el proyecto no tiene un patrón de panel flotante en escritorio y añadirlo sería un subsistema nuevo.
5. **Firma:** el sello de validación. Se aplica a **un** sitio. Todo lo demás se queda quieto.

**Justificación del sello frente a la alternativa descartada.** La obviedad era un Canvas de partículas en el héroe (SPEC-05 ya tiene motor). Se descarta por dos razones: el simulador de grimorio ya gasta ese gesto y hacerlo de nuevo sería repetir; y una animación en el aire hurtaría lectura justo donde la página más la necesita. El sello de validación es un elemento **estático** que codifica información verdadera —el tomo reúne contenido moderado— con un lenguaje visual que el proyecto ya posee (`runeSealComponent`). Gastar la firma donde la información es real es mejor diseño que gastar la firma donde es decorativa.

---

## 11. Ficheros Previstos (mini-plan de superficie)

```
public/index.html                              ← ningún <dialog> nuevo; el shell no crece
public/assets/js/main.js                       ← cableado de setLayout() con matchMedia (Tarea 2)
public/assets/css/layout.css                   ← cabecera: grupos, submenús, regla de una fila (Tarea 2)
public/assets/css/components/library.css       ← portada: sello, título del sistema, cinta del Regente
public/assets/css/components/user-panel.css    ← distintivo corto (solo si eletz)
public/assets/js/components/navbarComponent.js ← NAV_GROUPS + render de grupos
public/assets/js/components/userProfileBadge.js← rótulo corto + efigie por defecto
public/assets/js/components/landingSigilComponent.js  ← NUEVO
public/assets/js/views/landingView.js          ← orden de bloques + montaje del sello
public/assets/js/components/clanBannerComponent.js    ← variante compacta
scratch/test_navbar.mjs                        ← ampliado (grupos + teclado)
scratch/test_user_profile_badge.mjs            ← ampliado (rótulo corto + respaldo)
scratch/test_clan_banner_component.mjs         ← ampliado (variante compacta)
scratch/test_landing_view.mjs                  ← ampliado (orden + sello)
```

Cero ficheros bajo `src/`. Cero ficheros bajo `database/`.

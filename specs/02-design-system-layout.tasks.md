# TASKS-02: Tareas de Implementación — Sistema de Diseño Místico y Maquetación Base

> **Especificación:** [`specs/02-design-system-layout.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/02-design-system-layout.spec.md)  
> **Plan Técnico:** [`specs/02-design-system-layout.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/02-design-system-layout.plan.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad a RF/RNF y criterio de aceptación verificable.

---

## Fase 1: Fuentes Locales y Tokens Maestros (`tokens.css`)

- [x] **Tarea 1.1: Empaquetado de tipografías locales WOFF2**
  * **Alcance:** Provisión de los archivos de fuentes locales en `public/assets/fonts/` (`medieval-arcane-title.woff2`, `lore-readable-regular.woff2`, `lore-readable-bold.woff2`) y declaración `@font-face` con `font-display: swap`.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-02.3`, `RNF-05`, `Artículo I`
  * **Hecho cuando:** La carga del navegador renderiza los textos con las fuentes locales sin emitir ninguna solicitud de red a servidores externos como Google Fonts.
  * **Reforja de legibilidad (QA):** las siluetas geométricas forjadas por script (`scratch/build_fonts.py`, hoy eliminado) se sustituyeron por tipografías reales de licencia SIL OFL empaquetadas localmente: **Cinzel 400** para títulos y **EB Garamond 400/700** para cuerpo y énfasis. Mismos nombres de archivo y de familia interna, cero cambios en consumidores.

- [x] **Tarea 1.2: Tokens CSS de superficies, pergaminos y metales**
  * **Alcance:** Definir en `public/assets/css/tokens.css` las variables `:root` para fondos oscuros de obsidiana (`--color-bg-obsidian-*`), pergaminos ancestrales (`--color-parchment-*`), oros ceremoniales (`--color-gold-*`) y piedra desgastada.
  * **Cubre:** `RF-01.1`, `RNF-01`, `Artículo IV`
  * **Hecho cuando:** La hoja `tokens.css` expone la paleta completa de superficies oscuras y metales bruñidos utilizable por el resto de estilos.

- [x] **Tarea 1.3: Tokens CSS de tipografía, contraste calibrado y cifras de maná**
  * **Alcance:** Incorporar en `tokens.css` las familias tipográficas, escalas de texto y colores de texto calibrados (`--color-text-primary`, `--color-text-secondary`, `--color-text-muted`) junto con `--font-variant-mana-numbers: oldstyle-nums tabular-nums`.
  * **Cubre:** `RF-01.3`, `RF-02.1`, `RF-02.2`
  * **Hecho cuando:** La propiedad `font-variant-numeric: oldstyle-nums` se aplica en los selectores de maná y las variables de texto ofrecen ratios de contraste de al menos 5.2:1 frente al fondo más oscuro.

- [x] **Tarea 1.4: Tokens CSS de la matriz cromática elemental y sellos rúnicos**
  * **Alcance:** Definir en `tokens.css` las variables de color y resplandor para las 8 afinidades elementales (`--color-affinity-fire`, `--color-affinity-water`, `--color-affinity-lightning`, etc.) y sus correspondientes glifos rúnicos monocromáticos.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-03.3`
  * **Hecho cuando:** Las 8 afinidades elementales cuentan con sus tokens de color hex/hsl y glifos Unicode arcanos (`🜂`, `🜄`, `🗲`, `🜃`, `🜁`, `🝓`, `☽`, `🜚`) definidos en variables CSS.

- [x] **Tarea 1.5: Script de auditoría automatizada de tokens y contraste**
  * **Alcance:** Desarrollar `scratch/verify_design_tokens.php` para validar programáticamente que no existan URLs externas (`http://`, `https://`) en el CSS y certificar que la fórmula WCAG garantiza un contraste $\ge 4.5:1$ en todos los pares de texto.
  * **Cubre:** `RF-01.3`, `RF-02.3`, `Plan Sec. 7.1`
  * **Hecho cuando:** La ejecución de `php scratch/verify_design_tokens.php` finaliza con código de salida 0 y confirma cero llamadas externas y contrastes $\ge 4.5:1$.

---

## Fase 2: Maquetación «El Tomo Central» y Rejilla Responsiva (`layout.css`)

- [x] **Tarea 2.1: Contenedor «El Tomo Central» acotado a 1280 px**
  * **Alcance:** Implementar en `public/assets/css/layout.css` la clase `.grimoire-tomo-container` con ancho máximo de 1280 px, centrado horizontal automático y padding lateral de seguridad.
  * **Cubre:** `RF-05.1`, `RNF-04`
  * **Hecho cuando:** En monitores panorámicos (1920 px o 4K), el contenedor mantiene un ancho máximo visual de 1280 px perfectamente centrado con márgenes exteriores solemnes.

- [x] **Tarea 2.2: Rejilla adaptable del catálogo (3, 2 y 1 columnas)**
  * **Alcance:** Desarrollar en `layout.css` la clase `.spell-card-grid` con CSS Grid: 3 columnas en pantallas > 1024 px, media query de 2 columnas para tabletas (768 px a 1024 px) y 1 columna para móviles (< 768 px).
  * **Cubre:** `RF-05.1`, `RF-05.2`, `RF-05.3`
  * **Hecho cuando:** Al redimensionar la ventana, la rejilla transmuta fluidamente entre 3, 2 y 1 columna sin rupturas visuales en los puntos de quiebre definidos.

- [x] **Tarea 2.3: Soporte de zoom al 200% y pantallas ultra-estrechas (320 px)**
  * **Alcance:** Incorporar en `layout.css` reglas con `@media` y unidades relativas para permitir que la rejilla colapse a columna simple y expanda verticalmente su contenido cuando el usuario active zoom al 200% o navegue en pantallas de 320 px.
  * **Cubre:** `RF-05.5`, `Caso Límite 1`, `Caso Límite 3`
  * **Hecho cuando:** Al aplicar un zoom de accesibilidad al 200% en el navegador, ningún texto queda truncado ni se genera desplazamiento horizontal en pantallas de 320 px.

---

## Fase 3: Componentes Visuales, Insignias y Ergonomía Táctil (`components.css`)

- [x] **Tarea 3.1: Estructura de tarjeta y área táctil de 44x44 px**
  * **Alcance:** Desarrollar en `public/assets/css/components.css` la clase `.spell-card` con textura de pergamino, borde en oro bruñido y overlay táctil absoluto (`.spell-card__click-overlay`) que cubre el 100% de la tarjeta sin deformar las insignias.
  * **Cubre:** `RF-05.4`, `RF-06.1`
  * **Hecho cuando:** Todo el cuerpo de la tarjeta es clickeable con cursor de puntero y los botones interactivos internos respetan el área táctil mínima de 44x44 px.

- [x] **Tarea 3.2: Insignias de Afinidad Elemental y Escuelas Mágicas**
  * **Alcance:** Implementar en `components.css` las clases `.spell-card__badge-elemental` y `.spell-card__badge-school` que renderizan el color dinámico elemental, el glifo rúnico monocromático y el sello de la escuela académica.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-03.3`
  * **Hecho cuando:** Una tarjeta con afinidad de fuego exhibe el tono ámbar cálido con el glifo `🜂` y la escuela (ej. Evocación) se muestra con su sello y tipografía noble.

- [x] **Tarea 3.3: Sello de «Inestabilidad Arcana» para archivos experimentales**
  * **Alcance:** Crear en `components.css` el componente `.spell-card__badge--experimental` con distintivo ámbar/dorado parpadeante e iconografía de advertencia mística.
  * **Cubre:** `RF-03.4`, `Artículo III`
  * **Hecho cuando:** La tarjeta experimental muestra el sello de inestabilidad sobre el pergamino con un resplandor de alerta discreto.

- [x] **Tarea 3.4: Estados interactivos y control de bloqueos**
  * **Alcance:** Desarrollar en `components.css` los estilos `:hover`, `:focus-visible` y la clase `.spell-card--disabled` con textura de piedra desgastada y cursor de no permitido.
  * **Cubre:** `RF-06.1`, `RF-06.2`
  * **Hecho cuando:** Al sobrevolar la tarjeta se produce una elevación suave del pergamino con fulgor elemental, y un control deshabilitado adopta aspecto de piedra inerte sin interactividad.

---

## Fase 4: Dinámicas de Animación y Retroalimentación Espectral

- [x] **Tarea 4.1: Ciclo de «Respiración Arcana» ceremonial de bajo consumo**
  * **Alcance:** Implementar en `components.css` la regla `@keyframes arcaneBreathing` con un ciclo de 3.5 segundos basado exclusivamente en `transform: scale(1.006)` y `opacity` (sin filtros de desenfoque continuo).
  * **Cubre:** `RF-01.2`, `RNF-03`
  * **Hecho cuando:** La animación de respiración corre fluidamente a 60 fps constantes en la herramienta de rendimiento de DevTools con consumo de CPU mínimo.

- [x] **Tarea 4.2: «Pergaminos Espectrales» de carga con CLS = 0**
  * **Alcance:** Crear en `components.css` la clase `.spectral-scroll-placeholder` y la animación `@keyframes spectralSweep` que reserva las dimensiones exactas de las tarjetas de catálogo.
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-04.3`, `RNF-02`
  * **Hecho cuando:** Al reemplazar un elemento espectral por una tarjeta de conjuro con datos reales, el registro de Cumulative Layout Shift (CLS) de la consola permanece en 0.00.

- [x] **Tarea 4.3: Tipografía fluida para nombres extensos de conjuros**
  * **Alcance:** Implementar en `components.css` la clase `.spell-card__title` utilizando `clamp()` tipográfico y limitación a 2 líneas (`-webkit-line-clamp: 2`).
  * **Cubre:** `RF-02.4`, `Caso Límite 2`
  * **Hecho cuando:** Un hechizo con nombre de más de 60 caracteres ocupa como máximo 2 líneas armónicas sin romper la altura de la rejilla.

---

## Fase 5: Modo Impresión, Tinta Electrónica y Escaparate de Validación

- [x] **Tarea 5.1: Hoja de estilos de impresión y monocromo (`print.css`)**
  * **Alcance:** Crear `public/assets/css/print.css` con reglas `@media print` que transforman el fondo a blanco/pergamino claro, los textos a tinta negra/sepia de bajo consumo y anulan las animaciones.
  * **Cubre:** `RF-06.3`, `Caso Límite 4`
  * **Hecho cuando:** Al activar la vista previa de impresión en el navegador, los fondos negros desaparecen, los textos son negros sobre fondo blanco y los glifos elementales son legibles sin color.

- [x] **Tarea 5.2: Página de escaparate del Sistema de Diseño (Design Showcase)**
  * **Alcance:** Crear `scratch/design_system_preview.html` enlazando `tokens.css`, `layout.css`, `components.css` y `print.css`, exhibiendo las 8 tarjetas elementales, una experimental, un pergamino espectral y controles interactivos.
  * **Cubre:** `RF-01 a RF-06`, `RNF-01 a RNF-05`
  * **Hecho cuando:** Abrir `scratch/design_system_preview.html` en el navegador despliega el escaparate completo del sistema de diseño funcionando de forma autónoma.

- [x] **Tarea 5.3: Auditoría integral de calidad visual y Dogma Vanilla**
  * **Alcance:** Ejecutar la auditoría final: verificar ausencia de CDNs en la pestaña de red, ratio de contraste $\ge 4.5:1$, 60 fps en perfil de rendimiento y correcta visualización responsiva a 320 px, 768 px y 1280 px.
  * **Cubre:** `RNF-01 a RNF-05`, `Artículo I`, `Artículo IV`, `Artículo V`
  * **Hecho cuando:** Se verifica que el escaparate pasa todas las pruebas de contraste, rendimiento y responsividad sin ninguna advertencia en la consola del navegador.

---

## Fase 6: El Sello Rúnico Forjado y la Heráldica Determinista (`RF-07`)

- [x] **Tarea 6.1: Materia del sello como tokens de diseño (`tokens.css`)**
  * **Alcance:** Declarar en `public/assets/css/tokens.css` la materia de la heráldica forjada: `--sigil-disc`, `--sigil-tick`, `--sigil-ring-active`, `--sigil-ring-regent`, `--sigil-ring-archived` y `--sigil-wax`, con los contrastes medidos sobre el disco de tinta anotados en el propio comentario de la sección.
  * **Cubre:** `RF-07.1`, `RF-07.6`, `RNF-01`
  * **Hecho cuando:** Las hojas que visten el sello no escriben un solo literal de color: toda su materia viaja por Custom Properties, y los pares declarados superan los umbrales de contraste fijados (muescas > 7:1, carga > 4.5:1, metales > 3:1).

- [x] **Tarea 6.2: Forja determinista del Sello Rúnico (`runeSealComponent.js`)**
  * **Alcance:** Crear `public/assets/js/components/runeSealComponent.js`: SVG en línea dibujado por aritmética nativa, con la carga central por Linaje Mágico según el canon alquímico, el anillo de ocho muescas codificando el identificador `coat_of_arms` por huella FNV-1a de 32 bits, y el estado declarado por metal **y** forma.
  * **Cubre:** `RF-07.2`, `RF-07.4`, `RF-07.5`, `Artículo I`, `Artículo V`
  * **Hecho cuando:** La misma casa forja siempre el mismo sello sin aleatoriedad ni estado oculto, el identificador jamás se imprime como texto ni dentro del nombre accesible, y la casa disuelta se distingue de la viva aun sin color (anillo roto y metal distinto).

- [x] **Tarea 6.3: Sustitución de la heráldica impresa en las tres superficies**
  * **Alcance:** Retirar la impresión del identificador técnico (`RUNE_TIDE_SPIRAL`, glifos de linaje) en el blasón del Gran Portal (SPEC-01/SPEC-07 Tarea 5.2), el podio y los filtros del Salón de los Linajes (Tarea 6.3) y la ficha de hermandad (Tarea 6.4), montando en su lugar el Sello Rúnico forjado con su etiqueta accesible en castellano; el documento anfitrión viaja como opción inyectable en las tres vistas.
  * **Cubre:** `RF-07.3`, `RF-07.4`, `RF-07.5`, `Artículo V`
  * **Hecho cuando:** Ninguna de las tres superficies imprime una clave técnica —ni como texto ni dentro de un nombre accesible—, el sello del Clan Regente se distingue del de una casa activa y el de una casa disuelta se contempla con su sello ancestral.

- [x] **Tarea 6.4: Arnés del Sello Rúnico y auditoría de la batería**
  * **Alcance:** Escribir `scratch/test_rune_seal.mjs` (superficie del módulo, vectores canónicos de la huella FNV-1a, las ocho cargas, el anillo de muescas, los tres estados por metal y forma, la ausencia del identificador, la accesibilidad y el Dogma Vanilla) y ajustar los asertos de los arneses que fijaban el identificador impreso en las tres superficies.
  * **Cubre:** `RF-07.1 a RF-07.6`, `RNF-04`, `RNF-05`, `Artículo I`
  * **Hecho cuando:** El arnés del sello pasa sus asertos y la batería íntegra de especificaciones (Node + PHP) permanece en verde tras la sustitución de la heráldica.

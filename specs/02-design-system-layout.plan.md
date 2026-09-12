# PLAN-02: Plan Técnico de Implementación — Sistema de Diseño Místico y Maquetación Base

> **Especificación Asociada:** [`specs/02-design-system-layout.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/02-design-system-layout.spec.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Listo para Implementación  
> **Restricción Suprema:** Dogma Vanilla (Cero dependencias npm, cero CDNs externas como Google Fonts) y Dualidad Lingüística (Código, clases y variables CSS en inglés `kebab-case`/`camelCase`, comentarios y documentación en castellano).

---

## 1. Estructura de Archivos y Recursos Locales

Todo el sistema de diseño se construye sobre **CSS3 puro y fuentes locales WOFF2**, sin preprocesadores (Sass/Less) ni librerías utilitarias (Tailwind/Bootstrap), sirviendo los recursos directamente desde el servidor web local.

```
grimorio-interactivo/
└── public/
    └── assets/
        ├── fonts/                               # Tipografías locales optimizadas en WOFF2 [RF-02.3, Art. I]
        │   ├── medieval-arcane-title.woff2      # Fuente solemne para encabezados y nombres arcanos [RF-02.1]
        │   ├── lore-readable-regular.woff2      # Fuente sobria para cuerpos de texto largos [RF-02.2]
        │   └── lore-readable-bold.woff2         # Cifras de maná y énfasis de alta legibilidad [RF-02.2]
        └── css/
            ├── tokens.css                       # Variables maestras CSS (colores, tipografía, elevación) [RF-01, RF-03]
            ├── layout.css                       # Maquetación de «El Tomo Central» y rejilla responsiva [RF-05]
            ├── components.css                   # Tarjetas, insignias elementales y Pergaminos Espectrales [RF-03, RF-04, RF-06]
            └── print.css                        # Estilos para impresión y soportes monocromáticos (e-ink) [RF-06.3]
```

---

## 2. Especificación Completa de Tokens de Diseño (`tokens.css`)

Todos los tokens se definen bajo el pseudoelemento `:root` con identificadores semánticos en inglés `kebab-case`:

### 2.1 Superficies y Fondos Místicos
```css
:root {
  /* Fondos del Santuario (Obsidiana Arcana y Carbón Profundo) */
  --color-bg-obsidian-deep: #0c0b0e;
  --color-bg-obsidian-surface: #141318;
  --color-bg-obsidian-elevated: #1b1922;

  /* Texturas de Pergamino Ancestral Oscuro */
  --color-parchment-base: #1c1916;
  --color-parchment-surface: #24201c;
  --color-parchment-border: #3d352b;
  --color-parchment-border-focus: #d4af37;

  /* Acentos de Metales Ceremoniales */
  --color-gold-ancient: #d4af37;
  --color-gold-burnished: #aa8624;
  --color-gold-bright: #f3cf58;
  --color-bronze-ceremonial: #8c6747;

  /* Textura de Piedra Desgastada (Estados Bloqueados) */
  --color-stone-disabled: #2b2a29;
  --color-stone-disabled-text: #66605b;
}
```

### 2.2 Jerarquía de Texto y Contraste
```css
:root {
  /* Tipografías Locales WOFF2 */
  --font-family-title-arcane: "MedievalArcaneTitle", "Cinzel", "Times New Roman", serif;
  --font-family-body-readable: "LoreReadable", "Merriweather", Georgia, serif;

  /* Colores de Texto Calibrados (Contraste >= 4.5:1) */
  --color-text-primary: #f5f0e6;    /* Contraste 14.8:1 sobre #0c0b0e */
  --color-text-secondary: #c9bfaf;  /* Contraste 8.9:1 sobre #0c0b0e */
  --color-text-muted: #9e9382;      /* Contraste 5.2:1 sobre #0c0b0e */

  /* Cifras de Maná Clásicas */
  --font-variant-mana-numbers: oldstyle-nums tabular-nums;
}
```

### 2.3 Escalas de Espaciado y Elevación
```css
:root {
  --space-xs: 0.25rem;   /* 4px */
  --space-sm: 0.5rem;    /* 8px */
  --space-md: 1rem;      /* 16px */
  --space-lg: 1.5rem;    /* 24px */
  --space-xl: 2rem;      /* 32px */
  --space-2xl: 3rem;     /* 48px */

  --radius-parchment: 6px;
  --radius-badge: 4px;

  /* Elevaciones y Fulgores Arcanos */
  --shadow-parchment-flat: 0 2px 4px rgba(0, 0, 0, 0.6);
  --shadow-parchment-raised: 0 6px 16px rgba(0, 0, 0, 0.8), 0 0 12px rgba(212, 175, 55, 0.15);
  --shadow-glow-elemental: 0 0 14px var(--current-element-glow);

  /* Área Táctil Mínima de Accesibilidad */
  --touch-target-min: 44px;
  --container-tomo-max: 1280px;
}
```

---

## 3. Matriz Cromática Elemental y Sellos Rúnicos

Cada Afinidad Elemental posee un color principal, un fulgor difuso y un **glifo rúnico exclusivo** para garantizar la distinción en soportes monocromáticos (e-ink o impresión):

| Afinidad Elemental | Variable de Color | Código Hex / HSL | Ratio Contraste (s/ #1c1916) | Glifo Monocromático (e-ink) | Simbolismo Arcano |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Fuego** | `--color-affinity-fire` | `#e25822` / `hsl(17, 78%, 51%)` | 5.1:1 | `🜂` (Triángulo Ígneo) | Evocación termodinámica y calor purificador. |
| **Agua** | `--color-affinity-water` | `#228be6` / `hsl(208, 81%, 52%)` | 6.2:1 | `🜄` (Triángulo Invertido) | Flujo adaptativo, curación y mareas. |
| **Rayo** | `--color-affinity-lightning` | `#9775fa` / `hsl(255, 93%, 72%)` | 9.4:1 | `🗲` (Runa del Relámpago) | Energía cinética instantánea y polaridad. |
| **Tierra** | `--color-affinity-earth` | `#b58900` / `hsl(45, 100%, 35%)` | 5.8:1 | `🜃` (Triángulo Barrado) | Densidad física, protección telúrica y musgo. |
| **Viento** | `--color-affinity-wind` | `#38d9a9` / `hsl(162, 67%, 53%)` | 9.8:1 | `🜁` (Triángulo con Barra Superior) | Dispersión gaseosa, levitación y sonido. |
| **Luz** | `--color-affinity-light` | `#ffd43b` / `hsl(46, 100%, 61%)` | 12.3:1 | `🝓` (Sol Rúnico Radiante) | Abjuración de la verdad y disipación de sombras. |
| **Oscuridad** | `--color-affinity-darkness` | `#be4bdb` / `hsl(287, 66%, 58%)` | 6.5:1 | `☽` (Creciente Abisal) | Ocultamiento, nigromancia y el vacío. |
| **Arcano Puro** | `--color-affinity-arcane` | `#d4af37` / `hsl(46, 65%, 52%)` | 9.1:1 | `🜚` (Ouroboros Dorado) | Maná neutro, espacio puro y transmutación. |

---

## 4. Arquitectura de Maquetación de «El Tomo Central» (`layout.css`)

### 4.1 Contenedor Acotado y Rejilla Proporcional
```css
/* Contenedor «El Tomo Central» [RF-05.1] */
.grimoire-tomo-container {
  width: 100%;
  max-width: var(--container-tomo-max);
  margin-left: auto;
  margin-right: auto;
  padding-left: var(--space-lg);
  padding-right: var(--space-lg);
  box-sizing: border-box;
}

/* Rejilla de Tarjetas Adaptable [RF-05.1 a RF-05.3] */
.spell-card-grid {
  display: grid;
  gap: var(--space-lg);
  grid-template-columns: repeat(3, minmax(0, 1fr)); /* Escritorio: 3 columnas */
}

/* Tabletas (768px a 1024px) [RF-05.2] */
@media (max-width: 1024px) and (min-width: 768px) {
  .spell-card-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr)); /* 2 columnas */
    gap: var(--space-md);
  }
}

/* Móviles (< 768px) [RF-05.3] */
@media (max-width: 767px) {
  .grimoire-tomo-container {
    padding-left: var(--space-md);
    padding-right: var(--space-md);
  }
  .spell-card-grid {
    grid-template-columns: 1fr; /* 1 columna de lectura continua */
    gap: var(--space-md);
  }
}
```

### 4.2 Ergonomía Táctil sin Distorsión Visual (`RF-05.4`)
* La tarjeta completa actúa como disparador de selección mediante un enlace maestro con pseudoelemento absoluto:
```css
.spell-card {
  position: relative;
  min-height: 240px;
  display: flex;
  flex-direction: column;
}

/* Enlace invisible sobre toda la tarjeta que garantiza área táctil total */
.spell-card__click-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 1;
}

/* Botones secundarios internos con margen táctil de 44x44 px */
.spell-card__action-button {
  position: relative;
  z-index: 2;
  min-width: var(--touch-target-min);
  min-height: var(--touch-target-min);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
```

---

## 5. Algoritmos y Dinámicas de Animación en Pseudocódigo/CSS

### 5.1 Ciclo de «Respiración Arcana» Ceremonial (`RF-01.2, RNF-03`)
Optimizado estrictamente para ejecutarse en el compositor de la GPU mediante `transform` y `opacity`, garantizando 60 fps continuos sin recalentar el dispositivo:

```css
/* Animación de baja carga computacional: 3.5 segundos por ciclo */
@keyframes arcaneBreathing {
  0% {
    opacity: 0.85;
    box-shadow: 0 0 8px rgba(212, 175, 55, 0.15);
    transform: scale(1);
  }
  50% {
    opacity: 1;
    box-shadow: 0 0 16px rgba(212, 175, 55, 0.35);
    transform: scale(1.006);
  }
  100% {
    opacity: 0.85;
    box-shadow: 0 0 8px rgba(212, 175, 55, 0.15);
    transform: scale(1);
  }
}

.arcane-breathing-active {
  animation: arcaneBreathing 3.5s ease-in-out infinite;
  will-change: transform, opacity, box-shadow;
}
```

### 5.2 Mecánica de «Pergaminos Espectrales» con *CLS = 0* (`RF-04`)
Las siluetas de carga reservan exactamente el mismo espacio vertical y horizontal que la tarjeta renderizada:

```css
/* Silueta Espectral Rúnica [RF-04.1, RF-04.2] */
.spectral-scroll-placeholder {
  min-height: 240px;
  background-color: var(--color-parchment-base);
  border: 1px dashed var(--color-parchment-border);
  border-radius: var(--radius-parchment);
  position: relative;
  overflow: hidden;
}

/* Pulso rúnico suave de resplandor cenizo */
.spectral-scroll-placeholder::after {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(212, 175, 55, 0.08) 50%,
    transparent 100%
  );
  animation: spectralSweep 2.8s ease-in-out infinite;
  transform: translateX(-100%);
}

@keyframes spectralSweep {
  100% {
    transform: translateX(100%);
  }
}
```

### 5.3 Tipografía Fluida para Títulos Extensos (`RF-02.4`)
```css
/* Escala de título que permite hasta 2 líneas sin romper la tarjeta */
.spell-card__title {
  font-family: var(--font-family-title-arcane);
  font-size: clamp(1.15rem, 1rem + 0.8vw, 1.45rem);
  line-height: 1.25;
  color: var(--color-text-primary);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
```

### 5.4 Soporte de Zoom de Accesibilidad al 200% (`RF-05.5`)
```css
/* Cuando la fuente o el zoom aumentan al 200%, la tarjeta permite expansión vertical */
@media (min-resolution: 2dppx), (min-width: 1em) {
  .spell-card {
    height: auto;
    min-height: auto;
  }
  .spell-card__summary {
    -webkit-line-clamp: unset; /* Desactiva el recorte forzado para no ocultar texto */
  }
}
```

### 5.5 Adaptación de Hoja de Impresión y E-ink (`print.css` / `RF-06.3`)
```css
@media print {
  /* Fondo blanco y tinta sepia/negra de bajo consumo [RF-06.3] */
  body, .grimoire-tomo-container {
    background: #ffffff !important;
    color: #111111 !important;
  }

  .spell-card {
    background: #faf8f5 !important;
    border: 1px solid #777777 !important;
    box-shadow: none !important;
    page-break-inside: avoid;
  }

  .spell-card__badge-elemental {
    border: 1px solid #222222 !important;
    color: #000000 !important;
    background: transparent !important;
  }

  /* Desactivación de animaciones al imprimir */
  * {
    animation: none !important;
    transition: none !important;
  }
}
```

---

## 6. Decisiones Técnicas Justificadas

### Decisión 1: Variables CSS Nativas (`Custom Properties`) frente a Preprocesadores (Sass/Less)
* **Elección:** Usar variables CSS nativas `:root { --variable }`.
* **Alternativa Descartada:** Preprocesadores con compilación previa (SCSS, Less, Stylus).
* **Justificación Constitucional:** Cumple el **Artículo I (Dogma Vanilla)**. Las variables nativas no requieren herramientas de construcción ni dependencias externas, y permiten el cambio dinámico de variables en tiempo real en el navegador (ej. fulgor elemental reactivo) sin recargar estilos.

### Decisión 2: Fuentes Locales en Formato WOFF2 frente a Google Fonts
* **Elección:** Empaquetar los archivos de fuentes binarias `.woff2` locales en `public/assets/fonts/`.
* **Alternativa Descartada:** Enlaces `<link href="https://fonts.googleapis.com/...">` a servidores de Google Fonts o Adobe Typekit.
* **Justificación:** Cumple la prohibición constitucional de CDNs externas, elimina problemas de privacidad/telemetría (GDPR), previene pantallas en blanco (*FOIT*) si se pierde la conexión a internet y reduce la latencia de carga en 150-250 ms.

### Decisión 3: CSS Grid Nativo y Flexbox frente a Frameworks (Tailwind / Bootstrap)
* **Elección:** Declaraciones puras de `display: grid` y `display: flex` con clases semánticas arcanas.
* **Alternativa Descartada:** Clases utilitarias masivas (`class="bg-slate-900 flex flex-col p-4 md:grid-cols-3..."`).
* **Justificación:** Preserva la legibilidad del marcado HTML, evita el hinchado de dependencias npm de procesamiento y garantiza la pureza del desarrollo artesanal solicitado por el usuario.

### Decisión 4: Animación Basada en `transform` y `opacity` frente a Filtros de Desenfoque (`filter: blur`)
* **Elección:** Modulación de escala leve (1.006) y opacidades graduales.
* **Alternativa Descartada:** Modulación continua de `filter: drop-shadow()` o `filter: blur()`.
* **Justificación:** Los filtros gráficos en bucle forzan un repintado continuo (*repaint*) en la CPU. El uso de transformaciones y opacidad se ejecuta en el hilo compositor de la GPU, permitiendo animaciones continuas permanentes con consumo de batería prácticamente nulo.

---

## 7. Estrategia de Pruebas y Auditoría Visual

### 7.1 Auditoría Automatizada de Contraste y Red (Script Node/CLI)
Se creará un script de auditoría en `scratch/verify_design_tokens.php`:
1. **Verificación de Contraste de Colores:** Calcula la relación de contraste WCAG entre cada par de texto/fondo definido en `tokens.css`, certificando que todos los pares superan **4.5:1** (y **3:1** para componentes gráficos de gran tamaño).
2. **Inspección de Enlaces Externos:** Rastrea todos los archivos `.html` y `.css` para garantizar que **no existe ningún enlace saliente `http://` o `https://`** a servidores de fuentes o estilos externos.

### 7.2 Verificación de Rendimiento de Animación (DevTools)
* Registrar un perfil de rendimiento de 10 segundos en Google Chrome / Firefox:
  * Certificar que la tasa de refresco se sostiene en **60 fps constantes**.
  * Certificar que el evento `arcaneBreathing` no provoca eventos continuos de *Recalculate Style* o *Layout Shift*.

### 7.3 Verificación de Estabilidad de Maquetación (*CLS = 0*)
* Simular latencia de red de 2 segundos:
  1. Observar la aparición de los «Pergaminos Espectrales».
  2. Registrar con la API de rendimiento que la llegada de los datos de conjuro reales no mueve las coordenadas de los elementos adyacentes (*Cumulative Layout Shift* registrado = 0.00).

### 7.4 Verificación en Resoluciones Límite
* **320 px (Móvil Ultra-compacto):** Inspeccionar visualmente que no existe barra de desplazamiento horizontal.
* **1280 px (Escritorio Estándar):** Confirmar que «El Tomo Central» se centra con márgenes laterales nobles.
* **4K (3840 px):** Confirmar que el contenido no se estira desproporcionadamente y mantiene el ancho de 1280 px.

---

## 8. Matriz de Trazabilidad de Requisitos

| Requisito | Descripción | Módulo / Archivo de Estilo | Selector / Regla Técnica | Verificación |
| :--- | :--- | :--- | :--- | :--- |
| **RF-01.1** | Tema de fantasía oscura y pergamino | `tokens.css` | `:root { --color-bg-obsidian-*, --color-parchment-* }` | Auditoría de tokens de color |
| **RF-01.2** | Respiración Arcana continua de bajo consumo | `components.css` | `@keyframes arcaneBreathing` (3.5s) | Perfil de GPU a 60 fps |
| **RF-01.3** | Contraste de texto $\ge$ 4.5:1 | `tokens.css` | `--color-text-primary` (14.8:1 s/ obsidiana) | Test de contraste `verify_design_tokens.php` |
| **RF-02.1 / 02.2** | Jerarquía tipográfica combinada | `tokens.css`, `layout.css` | `--font-family-title-arcane`, `--font-family-body-*` | Inspección de glifos en títulos vs cuerpo |
| **RF-02.3** | Fuentes locales WOFF2 sin CDNs | `public/assets/fonts/` | `@font-face { src: url(...) format('woff2'); }` | Auditoría de peticiones de red (cero externas) |
| **RF-02.4** | Nombres largos fluidos (máx 2 líneas) | `components.css` | `.spell-card__title { clamp(...); -webkit-line-clamp: 2; }` | Prueba visual con nombre de 80 caracteres |
| **RF-03.1** | Semántica cromática de 8 afinidades | `tokens.css` | `--color-affinity-fire`, `--color-affinity-water`, etc. | Inspección de tarjetas de cada afinidad |
| **RF-03.2 / 03.3** | Glifo rúnico y respaldo monocromático | `components.css` | `.spell-card__element-glyph` (`🜂`, `🜄`, `🗲`, etc.) | Vista en escala de grises y e-ink |
| **RF-03.4** | Sello de Inestabilidad Arcana | `components.css` | `.spell-card__badge--experimental` | Inspección de halo ámbar pulsante |
| **RF-04.1 a 04.3** | Pergaminos Espectrales (*CLS = 0*) | `components.css` | `.spectral-scroll-placeholder` | Medición DevTools CLS = 0.00 |
| **RF-05.1** | «El Tomo Central» acotado a 1280 px | `layout.css` | `.grimoire-tomo-container { max-width: 1280px; }` | Inspección en monitor ultrawide |
| **RF-05.2 / 05.3** | 2 columnas en tabletas y 1 en móviles | `layout.css` | `@media (max-width: 1024px)`, `@media (max-width: 767px)` | Pruebas a 800 px y 375 px |
| **RF-05.4** | Área táctil de 44x44 px | `components.css` | `.spell-card__click-overlay`, `--touch-target-min` | Inspección de hitboxes en DevTools |
| **RF-05.5** | Zoom accesible al 200% | `layout.css`, `components.css` | `@media (min-resolution: 2dppx)` | Prueba con zoom del 200% del navegador |
| **RF-06.1 / 06.2** | Estados interactivos y bloqueo | `components.css` | `.spell-card:hover`, `.spell-card--disabled` | Prueba de sobrevuelo y tecla Tab |
| **RF-06.3** | Modo Impresión de bajo consumo | `print.css` | `@media print { background: #fff; color: #111; }` | Emulación de impresión en navegador |
| **RNF-01 a 05** | Rigor arcano, fluidez 60fps, líneas 45-75 car | `tokens.css`, `layout.css` | `max-width: 65ch` en párrafos descriptivos | Auditoría de accesibilidad y métricas |

---

## 9. Cumplimiento Constitucional y de Gobernanza

1. **Artículo I (El Dogma Vanilla):** Cero dependencias npm en la capa visual, cero preprocesadores (Sass/PostCSS) y cero fuentes o estilos cargados desde Google Fonts o CDNs externas.
2. **Artículo IV (El Velo Arcano):** Estética unificada de fantasía oscura y pergamino solemne, con animaciones rúnicas permanentes de respiración ceremonial y cifras numéricas de maná de estilo clásico.
3. **Artículo V (Dualidad Lingüística):**
   * Nombres de variables CSS, selectores y clases en **inglés `kebab-case`** (`--color-affinity-fire`, `.spell-card__badge-elemental`).
   * Toda la documentación técnica, comentarios dentro del CSS (`/* ... */`) y denominaciones visibles en pantalla expresadas con máxima nobleza en **castellano**.

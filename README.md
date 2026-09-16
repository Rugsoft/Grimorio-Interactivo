# 📖 Grimorio Interactivo

> **Sistema de Magia, Biblioteca Digital & Wiki Colaborativa**  
> Inspirado en la solemnidad y profundidad de universos como *Frieren: Beyond Journey's End*, *Dungeons & Dragons* y *El Señor de los Anillos*.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![W3C Native](https://img.shields.io/badge/Dogma-Vanilla%20Web-gold.svg?style=flat-square)](constitution.md)
[![Methodology](https://img.shields.io/badge/Methodology-Spec--Driven%20Development-blue.svg?style=flat-square)](specs/)
[![Test Suite](https://img.shields.io/badge/Automated%20Tests-176%20Suites%20Passing-brightgreen.svg?style=flat-square)](scratch/)
[![Design Audit](https://img.shields.io/badge/Impeccable%20Score-20%2F20%20(Excellent)-darkgreen.svg?style=flat-square)](#-auditoría-de-diseño-y-calidad)

---

## 📜 Visión General

**Grimorio Interactivo** es una biblioteca arcana y plataforma wiki colaborativa donde los adeptos de las artes místicas pueden catalogar, forjar, probar y debatir sobre encantamientos, criaturas y linajes mágicos.

El proyecto se rige por la metodología **Spec-Driven Development (SDD)** bajo el principio inquebrantable de **«No Spec, No Code»**: ninguna línea de código ejecutable entra al santuario sin una especificación técnica formal previa, revisada y ratificada en el catálogo `specs/`.

---

## ⚖️ Los Dos Mandatos Constitucionales Supremos

El desarrollo del santuario se encuentra blindado por la [**Constitución del Grimorio Interactivo**](constitution.md):

### 1. El Dogma Vanilla (Inviolabilidad Tecnológica — Artículo I)
* **Cero Dependencias Externas:** Queda terminantemente prohibido el uso de paquetes o librerías externas (sin dependencias npm, sin Composer, sin CDNs ajenas, sin frameworks frontend como React, Vue o Tailwind, y sin frameworks backend como Laravel o Symfony).
* **Estándar W3C Puro:** Frontend forjado exclusivamente en HTML5 semántico puro, CSS3 nativo mediante Custom Properties (variables de diseño) y animaciones `@keyframes`, y JavaScript moderno (ES6+) estructurado en **ES Modules** nativos (`type="module"`).
* **Efectos y Multimedia Nativas:**
  * Partículas, estelas cinemáticas y auras forjadas con **HTML5 Canvas 2D API nativa**.
  * Cánticos ceremoniales y órdenes mágicas orquestadas mediante **Web Speech API nativa** (`speechSynthesis` y `SpeechRecognition`).
* **Backend Puro:** **PHP 8.2 o superior** en modo estricto (`declare(strict_types=1);`), arquitectura MVC ligera orientada a API REST y **PDO nativo** como único canal relacional, con **100% de consultas preparadas** y *parameter binding* estricto.

### 2. De la Dualidad Lingüística Sagrada (Artículo V)
* **El Lenguaje de la Máquina (Inglés & `camelCase`):** Clases (`PascalCase`), métodos, variables, tablas SQLite/MySQL (`snake_case`), claves JSON y rutas de API (`/api/v1/...`) se redactan en **inglés técnico**.
* **El Alma del Grimorio (Noble Castellano):** Documentación, especificaciones en `specs/`, comentarios en código (`//`, `/* */`, PHPDoc/JSDoc), narrativa, interfaz visual y mensajes del servidor se expresan con riqueza léxica en **noble castellano**.

---

## ✨ Módulos y Funcionalidades Núcleo

El proyecto se estructura en 8 grandes especificaciones técnicas implementadas y verificadas:

```
specs/
├── 01-portal-and-navigation.spec.md     # Portal Web, Navegación SPA y Descubrimiento Arcano
├── 02-design-system-layout.spec.md      # Sistema de Diseño, Tokens y Componentes Base
├── 03-auth-rbac.spec.md                 # Sesiones Seguras, Vínculos y Matriz RBAC
├── 04-spell-creator-balance.spec.md     # Creador de Hechizos y Ley Universal del Maná
├── 05-grimoire-simulator.spec.md        # Simulador de Grimorio, Canvas y Web Speech API
├── 06-elemental-affinity-combos.spec.md # Códice de Afinidades, Rueda Rúnica y Combos
├── 07-clans-lineages.spec.md            # Linajes Arcanos, Hermandades y Dominio Semanal
└── 08-moderation-two-step.spec.md       # Cónclave de Moderación Solemne en Dos Pasos
```

### 🌌 1. Gran Portal y Navegación SPA Fluida (`#/`)
* Navegación por hash sin recargas completas de página (`#/biblioteca`, `#/codex`, `#/linajes`, `#/simulador`, `#/creador`, `#/atrio`, `#/torre`, `#/bitacora`).
* Enlaces directos a fichas técnicas (`#hechizo-slug`) que abren la ficha automáticamente preservando el historial del navegador (*Atrás* / *Adelante*).
* Menú móvil colapsable con accesibilidad por teclado y contención estricta de desplazamiento vertical.

### 🎨 2. Sistema de Diseño Místico (`tokens.css`)
* Paleta de fantasía oscura inspirada en grimorios antiguos: obsidiana profunda (`#0c0b0e`), pergamino ancestral (`#1c1916`), oro arcano (`#d4a94e`) y metales ceremoniales.
* Tipografías locales WOFF2 de licencia abierta servidas directamente desde el servidor:
  * **Cinzel:** Títulos solemnes grabados en capitales romanas.
  * **EB Garamond:** Cuerpo de lectura humanístico, notas y descripciones.
* Certificación WCAG 2.1 AA/AAA con relaciones de contraste medidas de hasta `17.3:1`.

### 🛡️ 3. Sesiones Seguras y Matriz de Roles (RBAC)
* Sesiones autenticadas mediante cookies seguras (`HttpOnly`, `SameSite=Strict`, `Secure`).
* Jerarquía de 4 rangos arcanos:
  * `reader` (Lector): Consulta pública, práctica en simulador y lectura comunitaria.
  * `editor` (Editor / Forjador): Creación y edición de hechizos propios (nacen en estado `draft`).
  * `master` (Maestro de la Torre): Evaluación y firma colegiada en la Torre de Deliberación.
  * `supremeAdmin` (Administrador Supremo): Decretos con Edicto Imperial, destierro y gobierno universal.
* Perfil del mago con distintivo reactivo y control de **Convalecencia Arcana** (14 días tras abandonar una hermandad).

### ⚖️ 4. Creador de Hechizos y Ley Universal del Maná
* **Determinismo Matemático Absoluto (Artículo II):** El coste de maná no lo fija nadie a mano. Se calcula en el backend mediante una función invariable basada en la composición objetiva de sus efectos:
  $$\text{Coste Base} = \text{Daño} \times 1.0 + \text{Curación} \times 1.3 + \text{Barrera} \times 1.2 + \text{Control} + \text{Duración} + \text{Área}$$
* Descuento litúrgico por componentes (Verbal, Somático, Material) hasta un máximo del $-30\%$.
* Sellado criptográfico de cada balance mediante huella SHA-256 (`math_fingerprint`) de 64 caracteres.

### 🔮 5. Simulador de Grimorio (Cámara de Conjuración)
* **Metáfora del Tomo Abierto:** Lámina izquierda con los componentes y la fórmula ceremonial; lámina derecha con el lienzo interactivo.
* **Maniquí Arcano de Entrenamiento:** Armazón de madera noble y paja ceremonial con barra de 500 PV, absorción prioritaria de escudo de barrera, leyenda de estado, reacción de sacudida y disolución al destruirse con regeneración a los 2 segundos.
* **Motor de Partículas Nativo (Canvas 2D):** 8 perfiles cinemáticos elementales, trayectorias geométricas (contacto, proyectil parabólico, abanico cónico, haz colimado, esfera radial), reciclado circular FIFO acotado a 200 partículas y limpieza en cada cuadro a 60 FPS.
* **Declamación e Invocación Vocal (Web Speech API):** Pronunciación litúrgica en noble castellano con `SpeechSynthesis` y reconocimiento por micrófono con `SpeechRecognition`.

### ⚡ 6. Códice de Afinidades y Matriz de Reacciones
* Rueda Rúnica interactiva con los 8 elementos primordiales: Fuego, Agua, Rayo, Tierra, Viento, Luz, Oscuridad y Arcano Puro.
* 8 aristas reactivas de combos (ej. *Agua* + *Rayo* = *Electrocución Fluida*; *Fuego* + *Tierra* = *Magma Fundido*).
* Auras elementales con halo pulsante y temporizador circular decreciente de 5 segundos.
* Salvaguarda de combate: Sistema de **Inmunidad Rúnica (Anti-Stunlock)** de 3 segundos ante controles de masas duros consecutivos.

### 🏰 7. Linajes Arcanos y Dominio Semanal
* Fundación y gestión de hermandades mágicas con censo acotado a 30 adeptos.
* Heráldica determinista forjada como **Sello Rúnico SVG**: 8 marcos de metal heráldico y anillos de muescas derivados de un hash inmutable.
* Bonificación de sinergia de linaje del $+25\%$ en Puntos de Dominio Arcano (PDA).
* Clasificación semanal con cierre dominical automático a las 23:59:59 UTC, coronando al **Clan Regente** (ribete dorado en el Tomo y estandarte en el Gran Portal).
* Régimen de **Herencia Ancestral**: conjuros de clanes disueltos se preservan para siempre en la memoria histórica del santuario.

### 🏛️ 8. Tribunal de Moderación Solemne en Dos Pasos
* **Ciclo de Vida Canónico:** `draft` (borrador privado) $\rightarrow$ `experimental` (en deliberación pública) $\rightarrow$ `validated` (consagrado en el Gran Tomo) / `rejected` (vetado con observaciones) / `archived` (desterrado).
* **Tribunal de las Tres Firmas (Artículo III):** Exige 3 firmas independientes de Maestros de clanes distintos entre sí.
* **Veto Ético de Hermandad:** Prohibición constitucional de evaluar conjuros de miembros del propio clan o de hermandades habitadas en los últimos 30 días naturales.
* **Prohibición de Auto-Firma:** Ningún usuario (ni siquiera el Administrador Supremo) puede firmar sus propias obras.
* **Potestad Soberana y Edicto Imperial:** El Administrador Supremo puede consagrar obras experimentales excepcionales exigiendo un Edicto Imperial justificado ($\ge 20$ caracteres) inscrito de forma inmutable en la Bitácora de Auditoría pública.

---

## 🛠️ Stack Tecnológico

| Capa | Tecnología | Características y Normas |
| :--- | :--- | :--- |
| **Backend** | **PHP 8.2+** | Tipado estricto (`declare(strict_types=1);`), MVC desacoplado orientado a API REST, Front Controller nativo en `public/index.php`. Cero frameworks. |
| **Persistencia** | **PDO Nativo** | 100% consultas preparadas con *parameter binding*. Compatible con SQLite y MySQL/MariaDB. Cero ORMs. |
| **Frontend** | **Vanilla Web (W3C)** | HTML5 semántico puro, CSS3 con Custom Properties y `@keyframes`, JavaScript moderno en **ES Modules** nativos. |
| **Gráficos & Canvas**| **HTML5 Canvas 2D** | Motor de partículas propio, cinemática balística diferida, búfer limpio a 60 FPS. Cero librerías externas. |
| **Voz & Audio** | **Web Speech API** | Declamación pausada (`es-ES`) con `speechSynthesis` y captura por micrófono con `SpeechRecognition`. |
| **Tipografía** | **WOFF2 Locales** | *Cinzel* y *EB Garamond* empaquetadas localmente (licencia SIL OFL 1.1). Cero peticiones externas a Google Fonts. |

---

## 📁 Estructura del Repositorio

```
GrimorioInteractivo/
├── constitution.md                      # Constitución Suprema del proyecto
├── AGENTS.md                            # Guía litúrgica y directrices para agentes de IA
├── README.md                            # Documentación general del repositorio
├── specs/                               # Especificaciones técnicas SDD (No Spec, No Code)
│   ├── 01-portal-and-navigation.spec.md
│   ├── 02-design-system-layout.spec.md
│   ├── ...
│   └── 08-moderation-two-step.tasks.md
├── public/                              # Raíz pública del servidor web
│   ├── index.php                        # Front Controller y enrutador REST
│   ├── index.html                       # Shell principal de la Single Page Application (SPA)
│   └── assets/
│       ├── css/                         # Hojas de estilo CSS3 puras
│       │   ├── tokens.css               # Fuentes de verdad, colores, tipografía y sombras
│       │   ├── layout.css               # Rejillas adaptables y cabecera persistente
│       │   ├── components.css           # Botones, formularios, modales y tarjetas
│       │   └── components/              # Estilos modulares de Códice, Simulador, Heráldica y Moderación
│       ├── js/                          # Módulos frontend en ES6 puro
│       │   ├── main.js                  # Orquestador central y enrutador hash de la SPA
│       │   ├── api/                     # Clientes HTTP fetch nativos
│       │   ├── components/              # Componentes de UI y modales litúrgicos
│       │   ├── views/                   # Vistas principales de la aplicación
│       │   └── utils/                   # Motor de partículas, speech, combos y store
│       └── fonts/                       # Archivos WOFF2 locales (Cinzel y EB Garamond)
├── src/                                 # Código fuente privado del backend (PHP 8.2+)
│   ├── Controllers/                     # Controladores REST de la API
│   ├── Core/                            # Enrutador, Request, Response, SessionManager y RateLimiter
│   ├── Database/                        # Conexión PDO nativa y gestión transaccional
│   ├── Dto/                             # Data Transfer Objects inmutables readonly
│   ├── Middleware/                      # Control de acceso RBAC e inyección de contexto
│   ├── Repositories/                    # Repositorios PDO con parameter binding
│   └── Services/                        # Lógica de dominio (Balanceo de maná, Combos, Dominio, Moderación)
├── database/                            # Esquemas de base de datos
│   ├── schema.sql                       # Esquema canónico DDL de tablas e índices
│   └── seeds.sql                        # Semillas fundacionales y pergaminos primordiales
└── scratch/                             # Batería de pruebas automatizadas CLI (PHP y Node)
```

---

## 🚀 Puesta en Marcha en Localhost

El proyecto no requiere Node.js para ejecutarse en producción, ni Composer, ni instalaciones complejas. Puedes levantarlo en menos de dos minutos utilizando el servidor web integrado de PHP.

### 1. Prerrequisitos
* **PHP 8.2 o superior** instalado con las extensiones estándar `pdo` y `pdo_sqlite` (o `pdo_mysql`).

### 2. Generar la Base de Datos de Prueba (Semilla Canónica)
Desde la raíz del repositorio, ejecuta el sembrador de demostración para crear un mundo arcano vivo con clanes, linajes, puntos de dominio y conjuros de muestra:

```bash
php scratch/demo_local_seed.php
```
*(Esto generará el archivo `scratch/demo_live.sqlite` con todo el mundo fundacional inicializado).*

### 3. Arrancar el Servidor Web
Ejecuta el servidor integrado de PHP apuntando al Front Controller `public/index.php`:

* **En Bash / Git Bash / Linux / macOS:**
  ```bash
  GRIMORIO_DB_DSN="sqlite:scratch/demo_live.sqlite" php -S 127.0.0.1:8000 -t public public/index.php
  ```

* **En PowerShell (Windows):**
  ```powershell
  $env:GRIMORIO_DB_DSN="sqlite:scratch/demo_live.sqlite"; php -S 127.0.0.1:8000 -t public public/index.php
  ```

* **En CMD (Símbolo del sistema de Windows):**
  ```cmd
  set GRIMORIO_DB_DSN=sqlite:scratch/demo_live.sqlite && php -S 127.0.0.1:8000 -t public public/index.php
  ```

### 4. Abrir en el Navegador
Navega a:
👉 **`http://127.0.0.1:8000`** (o `http://localhost:8000`)

---

## 🧪 Batería de Pruebas Automatizadas

El repositorio cuenta con una suite de pruebas automatizadas CLI que valida exhaustivamente cada especificación técnica tanto en el backend (PHP) como en el frontend (Node.js con DOM simulado):

```bash
# Ejecutar la suite de flujo completo del Cónclave de Moderación (13 bloques, 168 asertos)
php scratch/test_moderation_workflow.php

# Ejecutar el arnés de cierre formal y certificación de SPEC-08 (176 suites, 5.800+ asertos)
php scratch/test_spec08_closure.php

# Ejecutar el arnés de cierre formal de SPEC-07 (Linajes y Dominio Semanal)
php scratch/test_spec07_closure.php

# Ejecutar la prueba de integración de la SPA y el Router frontal
node scratch/test_spa_and_router_integration.mjs

# Ejecutar las pruebas del simulador y maniquí arcano
node scratch/test_simulator_visual_fixes.mjs
```

---

## 🛡️ Auditoría de Diseño y Calidad

El proyecto ha sido sometido a una auditoría técnica profunda bajo el estándar de calidad visual **Impeccable Audit**, alcanzando la calificación máxima:

| Dimensión | Puntuación | Estado |
|---|:---:|---|
| **Accesibilidad (A11y)** | 4 / 4 | Foco visible solemne, regiones `aria-live`, contrastes WCAG AAA. |
| **Rendimiento** | 4 / 4 | Animaciones GPU aceleradas en transform/opacity, cero layout thrashing. |
| **Responsive Design** | 4 / 4 | Menú móvil verticalmente adaptativo, fluid grid desde 320px a 4K. |
| **Tematización** | 4 / 4 | Coherencia total de tokens CSS, cero colores hexadecimales huérfanos. |
| **Integridad de Implementación** | 4 / 4 | Atmósfera medieval auténtica, erradicación de patrones genéricos. |
| **Audit Health Score** | **20 / 20** | **Excellent (Calidad Máxima Certificada)** |

---

## 📜 Licencia y Filosofía

Este repositorio representa una oda a la ingeniería de software clásica y a la noble fantasía épica. Diseñado bajo la premisa de que el software debe ser duradero, elegante, autónomo y libre de la obsolescencia programada de los ecosistemas de dependencias modernas.

> *«Todo conjuro guardado aquí sobrevivió al olvido.»*

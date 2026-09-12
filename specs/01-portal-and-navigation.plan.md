# PLAN-01: Plan Técnico de Implementación — Portal Web, Navegación y Descubrimiento Arcano

> **Especificación Asociada:** [`specs/01-portal-and-navigation.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/01-portal-and-navigation.spec.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Listo para Implementación  
> **Restricción Suprema:** Dogma Vanilla (Cero librerías npm, cero CDNs, cero frameworks) y Dualidad Lingüística (Código en inglés `camelCase`, comentarios y documentación en castellano).

---

## 1. Estructura de Módulos y Archivos

El sistema se estructura en dos capas estrictamente desacopladas: el **Backend (API REST ligera en PHP 8.2+)** y el **Frontend (Modern Vanilla Web con ES Modules nativos)**.

```
grimorio-interactivo/
├── public/                                      # Raíz pública servida por el servidor web
│   ├── index.php                                # Front Controller de la API REST [Cubre RF-01 a RF-06]
│   ├── index.html                               # Shell semántico de la aplicación SPA [Cubre RF-01, RF-02]
│   └── assets/
│       ├── css/
│       │   ├── tokens.css                       # Variables CSS: paleta arcana, tipografía, pergaminos [Cubre RNF-01]
│       │   ├── layout.css                       # Cabecera persistente, rejilla adaptable y menú móvil [Cubre RF-02, RNF-04]
│       │   └── components.css                   # Tarjetas, sellos de inestabilidad, modales y estados vacíos [Cubre RF-03 a RF-06]
│       └── js/
│           ├── main.js                          # Punto de entrada y orquestador frontend [Cubre RF-02]
│           ├── state/
│           │   └── store.js                     # Gestor reactivo de estado en memoria pura Vanilla [Cubre RF-03, RF-05]
│           ├── api/
│           │   └── spellClient.js               # Cliente HTTP fetch nativo para endpoints de catálogo [Cubre RF-01, RF-03, RF-04]
│           ├── components/
│           │   ├── navbarComponent.js           # Barra persistente y menú responsivo [Cubre RF-02]
│           │   ├── spellCardComponent.js        # Tarjeta de catálogo con clamp de 3 líneas [Cubre RF-03]
│           │   ├── spellDetailModalComponent.js # Modal superpuesto de ficha técnica y focus trap [Cubre RF-04, RNF-03]
│           │   └── accessModalComponent.js      # Diálogo «Cruzar el Umbral» (pila de modales) [Cubre RF-05]
│           ├── views/
│           │   ├── landingView.js               # Vista de portada y destacados de génesis [Cubre RF-01]
│           │   ├── libraryView.js               # Catálogo con buscador, filtros y carga progresiva [Cubre RF-03]
│           │   ├── clansPreviewView.js          # Salón de Linajes público de solo lectura [Cubre RF-02.2]
│           │   └── errorView.js                 # Vistas temáticas de rescate (404, desconexión) [Cubre RF-06]
│           └── utils/
│               ├── textNormalizer.js            # Normalizador insensible a acentos y tildes [Cubre RF-03.3, RF-03.4]
│               └── historyManager.js            # Sincronizador de URL hash y botón Atrás nativo [Cubre RF-04.1, RF-04.2, RNF-05]
└── src/                                         # Código fuente Backend privado (fuera de web root)
    ├── Core/
    │   ├── Router.php                           # Despachador de rutas REST HTTP ligero [Cubre RF-01 a RF-06]
    │   ├── Request.php                          # Abstracción tipada de query params y headers [Cubre RF-03]
    │   └── Response.php                         # Emisor estándar de JSON y cabeceras de seguridad [Cubre RF-06]
    ├── Database/
    │   └── Connection.php                       # Singleton PDO nativo con sentencias preparadas [Cubre Art. I]
    ├── Controllers/
    │   ├── PortalController.php                 # Endpoints de portada y hechizos destacados [Cubre RF-01]
    │   ├── SpellController.php                  # Endpoints de consulta y detalle de hechizos [Cubre RF-03, RF-04]
    │   └── ClanController.php                   # Endpoints de vista previa pública del Salón de Linajes [Cubre RF-02.2]
    ├── Models/
    │   └── Spell.php                            # Entidad inmutable con mapeo tipado [Cubre RF-01, RF-03, RF-04]
    └── Services/
        └── SpellDiscoveryService.php            # Lógica de destacados, génesis y paginación [Cubre RF-01, RF-03]
```

---

## 2. Modelo de Datos y Contratos JSON

Todos los identificadores y claves JSON se emiten en **inglés y `camelCase`** cumpliendo estrictamente con el **Artículo V de la Constitución**.

### 2.1 Entidad `SpellSummaryDto` (Tarjeta de Catálogo)
```json
{
  "id": "spl_9f8b2c1a",
  "slug": "llamas-de-frieren",
  "name": "Llamas de Frieren",
  "magicSchool": "evocation",
  "magicSchoolLabel": "Evocación",
  "manaCost": 45,
  "clanId": "cln_astral_scholars",
  "clanName": "Eruditos Astrales",
  "summary": "Proyecta una ráfaga continua de fuego purificador que calcina barreras mágicas.",
  "status": "validated",
  "isGenesisSample": false,
  "validatedAt": "2026-09-10T14:30:00Z"
}
```

### 2.2 Entidad `SpellDetailDto` (Ficha Completa Superpuesta)
```json
{
  "id": "spl_9f8b2c1a",
  "slug": "llamas-de-frieren",
  "name": "Llamas de Frieren",
  "magicSchool": "evocation",
  "magicSchoolLabel": "Evocación",
  "manaCost": 45,
  "clanId": "cln_astral_scholars",
  "clanName": "Eruditos Astrales",
  "summary": "Proyecta una ráfaga continua de fuego purificador que calcina barreras mágicas.",
  "description": "Una antigua fórmula perfeccionada en las tierras boreales. Concentra el maná ambiental en la palma del lanzador para expulsar una deflagración de calor blanco concentrado. Requiere calma mental absoluta durante el lanzamiento.",
  "components": {
    "verbal": "Ignis Caelestis Dissolvens",
    "somatic": "Palma extendida con runa trazada hacia el objetivo",
    "material": "Ceniza de sauce quemada con relámpago"
  },
  "status": "validated",
  "validationSignaturesCount": 3,
  "isGenesisSample": false,
  "validatedAt": "2026-09-10T14:30:00Z"
}
```

### 2.3 Colección de Génesis (`Pergaminos Primordiales`)
Cuando la base de datos tenga menos de 3 hechizos validados (RF-01.3), `SpellDiscoveryService` inyectará registros con `isGenesisSample: true`:
```json
[
  {
    "id": "spl_genesis_01",
    "slug": "chispa-de-ignicion",
    "name": "Chispa de Ignición",
    "magicSchool": "evocation",
    "magicSchoolLabel": "Evocación",
    "manaCost": 10,
    "clanId": "cln_primordial",
    "clanName": "Custodios del Fuego Primordial",
    "summary": "Conjuro fundacional que canaliza la llama más pura para encender candelas o disipar sombras.",
    "status": "validated",
    "isGenesisSample": true,
    "validatedAt": "2026-01-01T00:00:00Z"
  }
]
```

### 2.4 Respuesta Estándar de Error Místico (RFC 7807 adaptado)
```json
{
  "success": false,
  "error": {
    "code": "SCROLL_LOST_IN_AETHER",
    "message": "El pergamino que buscas se ha desvanecido en el éter.",
    "details": "No se encontró ningún conjuro activo registrado bajo el identificador 'trueno-prohibido'.",
    "recoveryAction": "RETURN_TO_LIBRARY"
  }
}
```

---

## 3. Contrato de la API REST

Base URL: `/api/v1`  
Cabecera Global de Respuesta: `Content-Type: application/json; charset=utf-8`

| Método | Endpoint | Parámetros Query | Respuestas HTTP | Descripción / RF Asociado |
| :--- | :--- | :--- | :--- | :--- |
| `GET` | `/portal/featured` | Ninguno | `200 OK`, `500 Internal Error` | Retorna los 3 hechizos destacados más recientes o pergaminos primordiales de génesis. [RF-01] |
| `GET` | `/spells` | `query` (string, max 100)<br>`schools` (csv: `evocation,abjuration`)<br>`maxMana` (int, min 1)<br>`includeExperimental` (bool: 0/1)<br>`offset` (int, def 0)<br>`limit` (int, def 50) | `200 OK`, `400 Bad Request`, `500 Internal Error` | Consulta paginada del catálogo con filtros acumulativos. [RF-03] |
| `GET` | `/spells/{slug}` | Ruta: `slug` (string alfanumérico con guiones) | `200 OK`, `404 Not Found`, `500 Internal Error` | Retorna la ficha técnica detallada del hechizo o error temático de extravío. [RF-04, RF-06.2] |
| `GET` | `/clans/preview` | Ninguno | `200 OK`, `500 Internal Error` | Listado público de linajes con su puntuación de Dominio semanal para lectura. [RF-02.2] |

---

## 4. Arquitectura de Componentes y Estado Frontend

### 4.1 Gestor de Estado Centralizado (`store.js`)
Un almacén reactivo sin dependencias basado en el patrón Observador nativo:
* **Estado Administrado:**
  * `currentView`: `'landing' | 'library' | 'clans' | 'error'`
  * `catalogSpells`: Lista de tarjetas cargadas acumulativamente.
  * `activeFilters`: `{ query: '', schools: Set(), maxMana: null, includeExperimental: false }`
  * `pagination`: `{ offset: 0, limit: 50, hasMore: true, isLoading: false }`
  * `activeModal`: `{ type: null | 'spellDetail' | 'access', data: null }`
  * `pendingIntent`: `{ action: null, targetSlug: null }` *(retenido en memoria volátil ante interceptación de acceso)*.

### 4.2 Control de la Pila de Modales (*Modal Stack Controller*)
Para resolver de forma no destructiva la apertura del diálogo «Cruzar el Umbral» sobre la ficha de detalle (RF-05.2):
* Nivel 0: El documento base (`<main id="app">`).
* Nivel 1 (z-index 100): El modal de ficha técnica (`<dialog id="spellDetailModal">`).
* Nivel 2 (z-index 200): El diálogo «Cruzar el Umbral» (`<dialog id="accessModal">`).
* **Regla de Operación:** Si el usuario pulsa *Escape* o *Atrás*, se cierra el modal superior activo (Nivel 2). Solo cuando el Nivel 2 no existe, la acción cierra el Nivel 1, devolviendo el foco a la tarjeta del catálogo.

### 4.3 Gestor de Historial y Enlaces Directos (`historyManager.js`)
* Al seleccionar un hechizo con slug `rayo-astral`, se ejecuta:  
  `window.history.pushState({ modal: 'spellDetail', slug: 'rayo-astral' }, '', '#hechizo-rayo-astral');`
* Al dispararse el evento `window.addEventListener('popstate')`:
  * Si el estado ya no contiene el modal, se invoca `closeDetailModal(false)` (sin volver a manipular el historial).
  * Si la página se carga con un hash inicial (ej. `#hechizo-llamas-de-frieren`), el orquestador solicita automáticamente los detalles a la API y despliega el modal de inmediato.

---

## 5. Algoritmos Clave en Pseudocódigo

### 5.1 Normalización y Búsqueda de Texto (Insensible a Acentos y Mayúsculas)
```text
FUNCTION normalizeSearchText(rawInput: String) -> String:
    // Limitar estrictamente a 100 caracteres para evitar ataques de denegación de servicio en cliente
    LET sanitized = rawInput.substring(0, 100)
    
    // Descomponer caracteres Unicode (NFD) y remover marcas diacríticas (tildes, diéresis)
    LET withoutAccents = sanitized.normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    
    // Convertir a minúsculas y colapsar espacios en blanco múltiples
    RETURN withoutAccents.toLowerCase().trim().replace(/\s+/g, " ")

FUNCTION matchesQuery(spell: Spell, normalizedQuery: String) -> Boolean:
    IF normalizedQuery.length < 2 THEN
        RETURN TRUE // Si tiene menos de 2 caracteres, no filtra
    
    LET normalizedName = normalizeSearchText(spell.name)
    LET normalizedSummary = normalizeSearchText(spell.summary)
    
    RETURN normalizedName.contains(normalizedQuery) OR normalizedSummary.contains(normalizedQuery)
```

### 5.2 Paginación Arcana Incremental (Bloques de 50)
```text
FUNCTION loadMoreSpells():
    IF store.pagination.isLoading OR NOT store.pagination.hasMore THEN
        RETURN
    
    store.pagination.isLoading = TRUE
    RENDER_SPINNER_AT_BOTTOM()
    
    LET nextOffset = store.pagination.offset + store.pagination.limit
    LET response = FETCH("/api/v1/spells", {
        query: store.activeFilters.query,
        schools: store.activeFilters.schools.join(","),
        maxMana: store.activeFilters.maxMana,
        includeExperimental: store.activeFilters.includeExperimental,
        offset: nextOffset,
        limit: 50
    })
    
    IF response.success THEN
        store.catalogSpells.append(response.data.items)
        store.pagination.offset = nextOffset
        store.pagination.hasMore = response.data.hasMore
        
        APPEND_NEW_CARDS_TO_GRID(response.data.items)
        
        IF NOT store.pagination.hasMore THEN
            REMOVE_BUTTON("Desenrollar más pergaminos")
    ELSE
        SHOW_NOTIFICATION_ERROR("La corriente de maná vaciló al cargar más conjuros.")
        
    store.pagination.isLoading = FALSE
```

### 5.3 Atrapamiento (*Focus Trap*) y Rescate de Foco Accesible
```text
FUNCTION trapFocus(dialogElement: HTMLElement, event: KeyboardEvent):
    IF event.key != 'Tab' THEN RETURN
    
    LET focusables = dialogElement.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
    LET firstElement = focusables[0]
    LET lastElement = focusables[focusables.length - 1]
    
    IF event.shiftKey THEN // Tab hacia atrás
        IF document.activeElement == firstElement THEN
            lastElement.focus()
            event.preventDefault()
    ELSE // Tab hacia adelante
        IF document.activeElement == lastElement THEN
            firstElement.focus()
            event.preventDefault()

FUNCTION restoreFocusOnClose(triggerElementId: String):
    LET originalElement = document.getElementById(triggerElementId)
    IF originalElement AND document.body.contains(originalElement) THEN
        originalElement.focus()
    ELSE
        // Foco de rescate según RF-04.3
        LET libraryTitle = document.getElementById("libraryHeaderTitle")
        IF libraryTitle THEN
            libraryTitle.setAttribute("tabindex", "-1")
            libraryTitle.focus()
```

---

## 6. Decisiones Técnicas Justificadas

### Decisión 1: Elemento `<dialog>` Nativo de HTML5 frente a Librerías de Modales
* **Elección:** Utilizar la etiqueta nativa `<dialog>` y sus métodos `.showModal()` y `.close()`.
* **Alternativa Descartada:** Librerías de terceros (SweetAlert, MicroModal) o `<div>` absolutos con listeners manuales.
* **Justificación Constitucional:** Cumple el **Artículo I (Dogma Vanilla)**. `<dialog>` provee de forma gratuita atrapamiento nativo de foco accesible, oscurecimiento de fondo mediante pseudoelemento `::backdrop` y soporte estándar de la tecla *Escape*.

### Decisión 2: Sincronización mediante Fragmento Hash (`#hechizo-slug`) y `History API`
* **Elección:** Usar `location.hash` combinado con `history.pushState`.
* **Alternativa Descartada:** Rutas con recarga de página (`/hechizos/rayo-astral`) o estado completamente invisible en la URL.
* **Justificación:** Permite que cualquier visitante comparta el enlace directo a un hechizo o use el botón nativo de "Atrás" sin necesidad de configurar redirecciones complejas en el servidor web Apache/Nginx para la entrega de archivos estáticos.

### Decisión 3: Normalización de Diacríticos Nativa (`String.prototype.normalize`)
* **Elección:** Usar el método estándar ECMAScript `normalize('NFD')` seguido del reemplazo de rangos Unicode de acentos.
* **Alternativa Descartada:** Librerías pesadas tipo Lodash/Deburr o sustitución manual con mapas gigantescos de letras.
* **Justificación:** Cero dependencias, rendimiento óptimo en el motor V8/SpiderMonkey (< 1 ms por búsqueda) y soporte universal para el idioma castellano.

### Decisión 4: Front Controller Ligero en PHP Puro frente a Micro-Frameworks
* **Elección:** Un despachador de rutas (`Router.php`) de menos de 100 líneas en PHP 8.2+ con expresiones regulares nativas.
* **Alternativa Descartada:** Slim Framework, Lumen o paquetes de enrutamiento Composer (`nikic/fast-route`).
* **Justificación:** Se adhiere al Dogma Vanilla y elimina el riesgo de obsolescencia de paquetes de terceros, manteniendo un arranque del backend ultrarrápido (< 5 ms de tiempo de ejecución por petición).

---

## 7. Estrategia de Pruebas y Verificación

### 7.1 Pruebas de Integración de Endpoints REST (CLI / cURL)
Se creará un script de verificación automatizado en `scratch/test_endpoints.php` que comprobará:
1. `GET /api/v1/portal/featured` responde `200 OK` con un arreglo JSON de exactamente 3 elementos.
2. `GET /api/v1/spells?query=frieren` responde `200 OK` filtrando resultados relevantes.
3. `GET /api/v1/spells?query=texto_excesivo_de_mas_de_cien_caracteres...` valida el truncamiento a 100 caracteres sin fallar.
4. `GET /api/v1/spells/conjuro-inexistente` responde `404 Not Found` con la estructura de error místico (`SCROLL_LOST_IN_AETHER`).
5. `GET /api/v1/clans/preview` responde `200 OK` con la lista de linajes en modo lectura.

### 7.2 Pruebas de Experiencia de Usuario y Accesibilidad
* **Verificación de Navegación por Teclado:**
  1. Abrir ficha de detalle mediante `Enter` sobre una tarjeta.
  2. Verificar que la tecla `Tab` cicla únicamente dentro de los elementos interactivos del modal.
  3. Presionar `Escape` y certificar que el foco regresa a la tarjeta exacta de origen.
* **Verificación de Pila de Modales:**
  1. Con la ficha de detalle abierta, pulsar *"Añadir a mi Grimorio"*.
  2. Comprobar que el diálogo *"Cruzar el Umbral"* se abre por encima sin desmontar la ficha de detalle.
  3. Cerrar *"Cruzar el Umbral"* y verificar que la ficha de detalle continúa visible e interactiva.
* **Verificación del Botón "Atrás" Nativo:**
  1. Abrir un hechizo y observar el cambio de URL a `#hechizo-nombre`.
  2. Pulsar el botón "Atrás" del navegador y comprobar que el modal se cierra pacíficamente sin salir de la página web.

---

## 8. Matriz de Trazabilidad de Requisitos

| Requisito | Descripción | Componente Frontend | Controlador / Servicio Backend | Verificación |
| :--- | :--- | :--- | :--- | :--- |
| **RF-01.1 a 01.3** | Portada y 3 destacados (o génesis) | `landingView.js`, `spellCardComponent.js` | `PortalController.php`, `SpellDiscoveryService.php` | Test `test_endpoints.php` (featured = 3) |
| **RF-01.4 / 01.5** | Acción de consagración y apertura de modal | `landingView.js`, `spellDetailModalComponent.js` | `SpellController.php` | Prueba de clic y verificación de DOM |
| **RF-02.1 / 02.2** | Cabecera y lectura pública de Linajes | `navbarComponent.js`, `clansPreviewView.js` | `ClanController.php` | Navegación de rutas sin sesión activa |
| **RF-02.3 / 02.4** | Intercepción a Creador y menú móvil | `navbarComponent.js`, `accessModalComponent.js` | N/A (Frontend Router) | Redimensionamiento a 320 px y prueba táctil |
| **RF-03.1 / 03.2** | Catálogo por novedad y experimentales | `libraryView.js`, `store.js` | `SpellController.php` | Conmutador de pestaña Experimental |
| **RF-03.3 / 03.4** | Búsqueda sin acentos (tope 100 car.) | `textNormalizer.js`, `libraryView.js` | `SpellController.php` | Búsqueda con "ignición" y "IGNICION" |
| **RF-03.5 / 03.6** | Filtro de escuelas (`OR`) y maná (`<=`) | `libraryView.js`, `store.js` | `SpellDiscoveryService.php` | Filtrado combinado de 2 escuelas simultáneas |
| **RF-03.7** | Paginación arcana (bloques de 50) | `libraryView.js` (*«Desenrollar más...»*) | `SpellDiscoveryService.php` (`offset/limit`) | Verificación de carga incremental sin scroll jump |
| **RF-04.1 / 04.2** | Ficha superpuesta, URL y botón Atrás | `spellDetailModalComponent.js`, `historyManager.js` | `SpellController.php` (`GET /spells/{slug}`) | Prueba con `popstate` y enlace hash directo |
| **RF-04.3** | Rescate de foco accesible | `spellDetailModalComponent.js` | N/A | Inspección de `document.activeElement` |
| **RF-05.1 a 05.4** | Diálogo místico, pila y sesión volátil | `accessModalComponent.js`, `store.js` | N/A | Simulación de almacenamiento bloqueado |
| **RF-06.1 a 06.3** | Estados de rescate (vacío, 404, caída) | `errorView.js`, `libraryView.js` | `Response.php` (`code: SCROLL_LOST...`) | Inyección de error y desconexión intencionada |
| **RNF-01 a 05** | Tono arcano, fluidez < 150ms, accesibilidad | `tokens.css`, `layout.css`, componentes | `Router.php` (latencia < 5ms) | Auditoría de contraste, teclado y performance |

---

## 9. Cumplimiento Constitucional y de Gobernanza

1. **Artículo I (Dogma Vanilla):** Cero dependencias añadidas a `package.json` o scripts externos; uso exclusivo de Web APIs nativas (`<dialog>`, `fetch`, `history`, `NFD normalization`).
2. **Artículo II (Ley del Maná):** El backend emite valores de maná computados deterministas. En este plan de portal solo se leen y filtran como enteros (`manaCost`).
3. **Artículo III (Ética de Clanes y Moderación):** El portal público segrega claramente los hechizos `validated` de los `experimental`. Las acciones restringidas (como votar) están deshabilitadas y redirigen al diálogo de acceso.
4. **Artículo IV (Integridad Temática y Velo Arcano):** Todos los textos de la interfaz usan nomenclatura inmersiva (*«Cruzar el Umbral»*, *«Renovar Vínculo»*, *«Consagrarse»*, *«Desenrollar más pergaminos»*).
5. **Artículo V (Dualidad Lingüística):**
   * Archivos y clases en inglés: `SpellDiscoveryService`, `spellCardComponent.js`, `normalizeSearchText()`.
   * Variables y propiedades en inglés `camelCase`: `magicSchool`, `manaCost`, `isGenesisSample`.
   * Comentarios y documentación en castellano: 100% de la documentación técnica y bloques de código explicados en español.

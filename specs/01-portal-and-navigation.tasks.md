# TASKS-01: Tareas de Implementación — Portal Web, Navegación y Descubrimiento Arcano

> **Especificación:** [`specs/01-portal-and-navigation.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/01-portal-and-navigation.spec.md)  
> **Plan Técnico:** [`specs/01-portal-and-navigation.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/01-portal-and-navigation.plan.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad de requisitos y criterio de aceptación verificable.

---

## Fase 1: Infraestructura y Backend Core (PHP 8.2+ REST)

- [x] **Tarea 1.1: Esquema de base de datos y semillas de génesis**
  * **Alcance:** Crear `database/schema.sql` (tablas `clans`, `spells`, `magic_schools`) y `database/seeds.sql` con clanes fundacionales y los 3 *Pergaminos Primordiales* canónicos.
  * **Cubre:** `RF-01.3`, `RF-03.1`, `Artículo II`
  * **Hecho cuando:** Al ejecutar `schema.sql` y `seeds.sql` en la base de datos (SQLite/MySQL), las tablas se crean con claves foráneas e índices en `slug` y `magic_school`, conteniendo exactamente los 3 pergaminos iniciales.

- [x] **Tarea 1.2: Conexión de base de datos PDO nativa**
  * **Alcance:** Implementar `src/Database/Connection.php` utilizando un Singleton tipado estricto con `declare(strict_types=1);` y manejo seguro de excepciones.
  * **Cubre:** `Artículo I`, `Artículo V`
  * **Hecho cuando:** Se ejecuta una consulta de prueba mediante `Connection::getInstance()->getPdo()` devolviendo una conexión activa con `ATTR_ERRMODE => ERRMODE_EXCEPTION` y `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`.

- [x] **Tarea 1.3: Núcleo HTTP y Enrutador ligero**
  * **Alcance:** Desarrollar `src/Core/Request.php`, `src/Core/Response.php` y `src/Core/Router.php` en PHP puro sin dependencias externas, soportando coincidencia de rutas regex y cabeceras JSON con codificación UTF-8.
  * **Cubre:** `RF-06.3`, `RNF-02`, `Artículo I`
  * **Hecho cuando:** Una ruta de prueba registrada en el router responde con código de estado HTTP 200 y cabecera `Content-Type: application/json; charset=utf-8` conteniendo un JSON válido en menos de 5 ms.

- [x] **Tarea 1.4: Modelo de Dominio y Servicio de Descubrimiento**
  * **Alcance:** Crear `src/Models/Spell.php` (entidad inmutable tipada) y `src/Services/SpellDiscoveryService.php` con la lógica de obtención de destacados (o fallback a primordiales si hay menos de 3) y paginación `offset/limit`.
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-03.1`, `RF-03.7`
  * **Hecho cuando:** Las llamadas unitarias a `SpellDiscoveryService::getFeaturedSpells()` retornan exactamente 3 elementos con `isGenesisSample: true` cuando la base de datos no tiene hechizos validados de usuarios.

- [x] **Tarea 1.5: Controladores REST y Front Controller**
  * **Alcance:** Implementar `src/Controllers/PortalController.php`, `src/Controllers/SpellController.php` y `src/Controllers/ClanController.php`, configurando el despacho en `public/index.php`.
  * **Cubre:** `RF-01`, `RF-02.2`, `RF-03`, `RF-04.1`, `RF-06.2`
  * **Hecho cuando:** Las peticiones HTTP a `/api/v1/portal/featured`, `/api/v1/spells`, `/api/v1/spells/{slug}` y `/api/v1/clans/preview` devuelven las estructuras JSON estipuladas en el plan técnico.

- [x] **Tarea 1.6: Script de verificación de integración de la API**
  * **Alcance:** Crear `scratch/test_endpoints.php` para validar de forma automatizada los endpoints REST, el truncamiento de búsquedas a 100 caracteres y la respuesta mística de error 404 (`SCROLL_LOST_IN_AETHER`).
  * **Cubre:** `RF-03.4`, `RF-06.2`, `Plan Sec. 7.1`
  * **Hecho cuando:** La ejecución por línea de comandos de `php scratch/test_endpoints.php` finaliza con código de salida 0 y todos los asertos en verde.

---

## Fase 2: Shell Frontend y Sistema de Diseño Base (Tokens & Layout)

- [x] **Tarea 2.1: Shell semántico HTML5 base**
  * **Alcance:** Crear `public/index.html` con estructura semántica pura (`<header>`, `<main id="app">`, `<footer>`) e inclusión de elementos `<dialog>` accesibles para modales y carga de `main.js` como módulo ES6.
  * **Cubre:** `RF-02.1`, `RF-04.1`, `RNF-03`, `RNF-04`
  * **Hecho cuando:** El documento HTML5 valida sin errores de sintaxis en el navegador, mostrando los contenedores base con sus etiquetas `aria-` correspondientes.

- [x] **Tarea 2.2: Tokens de diseño CSS místico**
  * **Alcance:** Crear `public/assets/css/tokens.css` con variables CSS (`--color-bg-grimoire`, `--color-parchment`, `--color-gold-arcane`, tipografía mística y elevaciones).
  * **Cubre:** `RNF-01`, `Artículo IV`
  * **Hecho cuando:** Los estilos globales aplican la paleta de fantasía oscura y pergamino antiguo mediante variables CSS sin depender de ningún framework externo.

- [x] **Tarea 2.3: Layout responsivo y cabecera persistente**
  * **Alcance:** Crear `public/assets/css/layout.css` con estilos para la barra de navegación persistente, la rejilla adaptable del catálogo y el menú colapsable para dispositivos móviles (< 768 px).
  * **Cubre:** `RF-02.1`, `RF-02.4`, `RNF-04`
  * **Hecho cuando:** La cabecera se mantiene fija en el tope superior durante el scroll y colapsa correctamente en un menú accesible al reducir la ventana a 320 px.

- [x] **Tarea 2.4: Estilos de componentes visuales**
  * **Alcance:** Crear `public/assets/css/components.css` con estilos para las tarjetas de catálogo (clamp de 3 líneas con elipsis), badges de maná/escuela, sellos de *«Inestabilidad Arcana»*, animaciones de carga y diálogos modales.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-04.1`, `RNF-01`
  * **Hecho cuando:** Las tarjetas exhiben el recorte de 3 líneas sin desbordamiento visual y los elementos `<dialog>` muestran el fondo oscurecido temático (`::backdrop`).

---

## Fase 3: Estado Centralizado, Utilidades y Clientes Frontend

- [x] **Tarea 3.1: Utilidad de normalización y sanitización de texto**
  * **Alcance:** Crear `public/assets/js/utils/textNormalizer.js` con el algoritmo de eliminación de acentos/diacríticos (`normalize('NFD')`), conversión a minúsculas y truncamiento a 100 caracteres.
  * **Cubre:** `RF-03.3`, `RF-03.4`, `Plan Sec. 5.1`
  * **Hecho cuando:** La función `normalizeSearchText("  ¡IGNICIÓN MÁGICA!  ")` retorna `"ignicion magica"` y cadenas de más de 100 caracteres son truncadas sin error.

- [x] **Tarea 3.2: Gestor de estado reactivo en memoria pura**
  * **Alcance:** Crear `public/assets/js/state/store.js` implementando el patrón Observador nativo para almacenar filtros activos, catálogo acumulado, modales y la intención de navegación interceptada.
  * **Cubre:** `RF-03.5`, `RF-05.2`, `RF-05.4`
  * **Hecho cuando:** Los cambios de estado mediante `store.setState(...)` notifican a los suscriptores registrados y conservan la intención pendiente en memoria volátil de sesión.

- [x] **Tarea 3.3: Cliente HTTP nativo para la API**
  * **Alcance:** Crear `public/assets/js/api/spellClient.js` con funciones `fetchFeatured()`, `fetchSpells(params)`, `fetchSpellBySlug(slug)` y `fetchClansPreview()` gestionando errores de red pacíficamente.
  * **Cubre:** `RF-01`, `RF-03`, `RF-04.1`, `RF-06.3`
  * **Hecho cuando:** Una invocación fallida por corte de red retorna un objeto de error controlado `{ success: false, error: { code: 'MANA_STREAM_INTERRUPTED' } }` sin provocar excepciones no capturadas en consola.

- [x] **Tarea 3.4: Sincronizador de historial y navegación con botón Atrás**
  * **Alcance:** Crear `public/assets/js/utils/historyManager.js` gestionando `location.hash` (`#hechizo-slug`) y escuchando el evento nativo `popstate` para cerrar modales al pulsar "Atrás".
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RNF-05`
  * **Hecho cuando:** Al cambiar el hash se notifica al orquestador y pulsar el botón "Atrás" del navegador revierte el estado sin abandonar la aplicación web.

---

## Fase 4: Componentes UI Modulares y Gestión de Modales

- [x] **Tarea 4.1: Componente de Barra de Navegación**
  * **Alcance:** Crear `public/assets/js/components/navbarComponent.js` que renderice los enlaces persistentes (*Inicio*, *Biblioteca*, *Salón de Linajes*, *Creador de Hechizos* y *«Cruzar el Umbral»*), gestionando el menú móvil.
  * **Cubre:** `RF-02.1`, `RF-02.3`, `RF-02.4`
  * **Hecho cuando:** Al pulsar *«Creador de Hechizos»* siendo visitante se dispara el evento de interceptación para abrir el modal de acceso reteniendo la intención.

- [x] **Tarea 4.2: Componente de Tarjeta de Hechizo**
  * **Alcance:** Crear `public/assets/js/components/spellCardComponent.js` generando el HTML de la tarjeta con distintivos de escuela, coste de maná, clan de origen, texto resumido y accesibilidad por teclado (`tabindex="0"`, `role="article"`).
  * **Cubre:** `RF-03.1`, `RNF-03`
  * **Hecho cuando:** Presionar la tecla `Enter` o hacer clic sobre la tarjeta dispara el evento de selección emitiendo el identificador/slug del hechizo.

- [x] **Tarea 4.3: Componente de Ficha Técnica Superpuesta**
  * **Alcance:** Crear `public/assets/js/components/spellDetailModalComponent.js` utilizando `<dialog>`, implementando atrapamiento de foco (*focus trap*), cierre con tecla *Escape* y rescate de foco accesible en el cierre.
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-04.3`, `RNF-03`
  * **Hecho cuando:** El modal se abre superpuesto sin mover el scroll inferior, la tecla `Tab` queda confinada dentro del modal y al cerrarse con `Escape` el foco regresa a la tarjeta de origen.

- [x] **Tarea 4.4: Componente de Diálogo «Cruzar el Umbral» (Pila de Modales)**
  * **Alcance:** Crear `public/assets/js/components/accessModalComponent.js` que se superponga (z-index superior) con opciones de *«Renovar Vínculo»* y *«Consagrarse»*, sin destruir el modal de detalle subyacente.
  * **Cubre:** `RF-05.1`, `RF-05.2`, `RF-05.3`
  * **Hecho cuando:** Al invocar el diálogo de acceso con la ficha de detalle abierta, ambos modales coexisten en el DOM y cerrar el de acceso restaura inmediatamente la interacción con la ficha técnica.

---

## Fase 5: Vistas Principales, Búsqueda y Manejo de Excepciones

- [x] **Tarea 5.1: Vista de Portada y Galería de Destacados**
  * **Alcance:** Crear `public/assets/js/views/landingView.js` con la narrativa introductoria, el botón destacado *«Consagrar Linaje»* y la cuadrícula de los 3 hechizos validados más recientes o pergaminos primordiales.
  * **Cubre:** `RF-01.1`, `RF-01.4`, `RF-01.5`
  * **Hecho cuando:** La portada renderiza los 3 destacados obtenidos de la API y el clic en *«Consagrar Linaje»* despliega el diálogo «Cruzar el Umbral».

- [x] **Tarea 5.2: Vista de Biblioteca con Buscador en Vivo y Filtros**
  * **Alcance:** Crear `public/assets/js/views/libraryView.js` con barra de búsqueda reactiva (a partir de 2 caracteres), checkboxes de Escuelas de Magia operando bajo unión (`OR`) y control deslizante para tope de maná (`<=`).
  * **Cubre:** `RF-03.3`, `RF-03.5`, `RF-03.6`, `RNF-02`
  * **Hecho cuando:** Escribir en el buscador o alternar filtros actualiza el listado visible en menos de 150 ms sin refrescar la página.

- [x] **Tarea 5.3: Carga Incremental Arcana («Desenrollar más pergaminos»)**
  * **Alcance:** Implementar en `public/assets/js/views/libraryView.js` la paginación en bloques de 50 elementos con botón temático al pie que concatene nuevos elementos sin alterar la posición de scroll.
  * **Cubre:** `RF-03.7`, `RNF-02`
  * **Hecho cuando:** Si hay más de 50 resultados, presionar *«Desenrollar más pergaminos»* carga e inserta los siguientes 50 elementos en la rejilla ocultando el botón al llegar al final.

- [x] **Tarea 5.4: Pestaña de Archivos Experimentales**
  * **Alcance:** Integrar en `public/assets/js/views/libraryView.js` el selector opcional para alternar a *«Archivos Experimentales»*, mostrando las tarjetas con el sello visual de *«Inestabilidad Arcana»*.
  * **Cubre:** `RF-03.2`, `Artículo III`
  * **Hecho cuando:** Al activar la pestaña experimental, se consultan y renderizan hechizos no validados con el distintivo de advertencia mística.

- [x] **Tarea 5.5: Vistas temáticas de error y estados de rescate**
  * **Alcance:** Crear `public/assets/js/views/errorView.js` con las pantallas arcanas de búsqueda vacía (*«Ningún conjuro responde a esas runas»*), error 404 (*«Pergamino desvanecido en el éter»*) y corte de maná con botones de restablecimiento y reintento.
  * **Cubre:** `RF-06.1`, `RF-06.2`, `RF-06.3`
  * **Hecho cuando:** Una búsqueda sin coincidencias muestra la leyenda temática con botón de reseteo, y un error 404 ofrece un enlace directo de retorno a la biblioteca.

- [x] **Tarea 5.6: Vista previa del Salón de Linajes (Lectura Pública)**
  * **Alcance:** Crear `public/assets/js/views/clansPreviewView.js` para renderizar el listado público de clanes y la tabla de clasificación semanal de Dominio del Grimorio en modo solo lectura.
  * **Cubre:** `RF-02.2`, `HU-05`
  * **Hecho cuando:** Un visitante no autenticado accede a la ruta de linajes pudiendo leer la información de clanes y rankings sin recibir bloqueos de acceso.

---

## Fase 6: Orquestación, Enrutamiento SPA y Verificación E2E

- [x] **Tarea 6.1: Orquestador central de la aplicación**
  * **Alcance:** Implementar `public/assets/js/main.js` integrando el enrutador frontend por vistas (`landing`, `library`, `clans`), inicializando el estado, montando la barra de navegación y gestionando hashes directos (`#hechizo-slug`).
  * **Cubre:** `RF-02.1`, `RF-04.1`, `RNF-05`
  * **Hecho cuando:** Cargar la URL base muestra la portada, navegar por la barra cambia fluidamente entre vistas sin recarga completa y acceder directamente a un hash `#hechizo-slug` abre la ficha técnica de dicho hechizo de forma automática.

- [x] **Tarea 6.2: Verificación interactiva de navegación por teclado y botón Atrás**
  * **Alcance:** Ejecutar pruebas manuales estructuradas de accesibilidad: ciclado de tabulación dentro de modales, tecla Escape, botón Atrás del navegador y restauración de foco tras filtrado.
  * **Cubre:** `RF-04.2`, `RF-04.3`, `RNF-03`, `RNF-05`
  * **Hecho cuando:** Se verifica visualmente que pulsar "Atrás" cierra modales sin salir de la web y el foco regresa ordenadamente a la tarjeta correspondiente o al título de rescate.

- [x] **Tarea 6.3: Auditoría final de Dogma Vanilla y Dualidad Lingüística**
  * **Alcance:** Inspeccionar la totalidad de archivos del proyecto para certificar ausencia de dependencias npm/CDN externas, nombres de identificadores en inglés (`camelCase`) y comentarios/textos en castellano.
  * **Cubre:** `Artículo I`, `Artículo IV`, `Artículo V`, `Checklist AGENTS.md`
  * **Hecho cuando:** El árbol del proyecto no contiene directorios `node_modules` ni enlaces CDN en el HTML/CSS, y el checklist de calidad de `AGENTS.md` se completa al 100%.

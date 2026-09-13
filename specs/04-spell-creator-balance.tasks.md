# TASKS-04: Tareas de Implementación — Creador de Hechizos y Algoritmo de Balanceo de Maná

> **Especificación:** [`specs/04-spell-creator-balance.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/04-spell-creator-balance.spec.md)  
> **Plan Técnico:** [`specs/04-spell-creator-balance.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/04-spell-creator-balance.plan.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad a RF/RNF y criterio de aceptación verificable.

---

## Fase 1: Esquema de Base de Datos y Objetos de Transferencia de Datos (SQL & PHP 8.2+)

- [x] **Tarea 1.1: Ampliación del esquema DDL para la tabla de conjuros con magnitudes cuantitativas**
  * **Alcance:** Actualizar `database/schema.sql` ampliando la tabla `spells` con columnas cuantitativas (`damage`, `healing`, `barrier`, `crowd_control_type`, `range_type`, `area_type`, `duration_type`, `has_verbal`, `has_somatic`, `has_material`, `mana_cost`, `circle`, `math_fingerprint`, `signatures_count`) e índices de optimización por autor y clan.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-01.5`, `RF-05.1`, `Artículo V`
  * **Hecho cuando:** La ejecución del script SQL amplía o crea la tabla `spells` con todos los campos tipados, valores por defecto e índices sin arrojar errores de sintaxis en SQLite / MariaDB.

- [x] **Tarea 1.2: DTOs de parámetros numéricos de entrada (`SpellCalculationInputDto`)**
  * **Alcance:** Crear `src/Dto/SpellCalculationInputDto.php` con `declare(strict_types=1);`, propiedades tipadas (`damage`, `healing`, `barrier`, `crowdControlType`, `rangeType`, `areaType`, `durationType`, `hasVerbal`, `hasSomatic`, `hasMaterial`), validaciones de dominio y constructor inmutable.
  * **Cubre:** `RF-01.2`, `RF-01.3`, `RF-01.4`, `RF-01.5`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La clase rechaza valores numéricos negativos o modificadores inexistentes mediante `InvalidArgumentException` al ser instanciada.

- [x] **Tarea 1.3: DTOs de resultado pedagógico y carga de creación (`SpellCalculationResultDto` y `SpellCreateDto`)**
  * **Alcance:** Implementar `src/Dto/SpellCalculationResultDto.php` (desglose de sumandos, subtotales, descuentos, coste final y círculo) y `src/Dto/SpellCreateDto.php` (metadatos narrativos y cuantitativos completos) con serialización JSON nativa.
  * **Cubre:** `RF-01.1`, `RF-02.1`, `RF-03.1`, `RF-04.2`, `RNF-05`
  * **Hecho cuando:** `json_encode($resultDto)` genera la estructura exacta esperada por el contrato de la API REST con todas sus claves en inglés `camelCase`.

---

## Fase 2: Motor Matemático Puro y Lógica de Balance (Backend PHP 8.2+)

- [x] **Tarea 2.1: Definición de excepciones de dominio arcanas (`ArcaneOverloadException` e inmutabilidad)**
  * **Alcance:** Crear `src/Exceptions/ArcaneOverloadException.php` y `src/Exceptions/SpellImmutableException.php` con códigos HTTP 400 y 403 respectivamente, incorporando los mensajes canónicos solemnes de sobrecarga e inviolabilidad.
  * **Cubre:** `RF-03.2`, `RF-06.2`, `Artículo II`
  * **Hecho cuando:** Lanzar `ArcaneOverloadException` encapsula el mensaje ceremonial de exceso de maná y el código canónico de respuesta `ARCANE_OVERLOAD`.

- [x] **Tarea 2.2: Implementación del servicio de balance puro y constantes universales (`SpellBalanceService`)**
  * **Alcance:** Implementar `src/Services/SpellBalanceService.php` conteniendo los diccionarios inmutables `WEIGHTS`, `MULTIPLIERS`, `DISCOUNTS`, `CIRCLES`, los métodos `calculate(SpellCalculationInputDto): SpellCalculationResultDto` y `computeMathFingerprint(SpellCalculationInputDto): string` (hash SHA-256 canónico).
  * **Cubre:** `RF-01.2` a `RF-01.5`, `RF-02.1`, `RF-02.2`, `RF-02.3`, `RF-02.4`, `RF-03.1`, `RNF-01`, `Artículo II`, `Artículo V`
  * **Hecho cuando:** El método `calculate()` aplica estrictamente la fórmula `max(5, ceil(Gross * (1 - discount)))`, asigna el Círculo correcto y genera un hash SHA-256 determinista idéntico para los mismos parámetros.

- [x] **Tarea 2.3: Validación del techo de contención y neutralidad elemental (`SpellBalanceService`)**
  * **Alcance:** Asegurar en `SpellBalanceService.php` que cualquier combinación cuyo coste bruto supere los 200 puntos lance de inmediato `ArcaneOverloadException`, y constatar que ningún parámetro elemental o de escuela mágica interfiera en el cálculo matemático.
  * **Cubre:** `RF-02.4`, `RF-03.2`, `RNF-01`, `Artículo II`
  * **Hecho cuando:** Un cálculo que arroje 201 de maná dispara `ArcaneOverloadException` y alternar entre afinidad de fuego o luz produce exactamente el mismo coste.

- [x] **Tarea 2.4: Script automatizado de pruebas unitarias matemáticas (`test_spell_balance.php` - Suite A)**
  * **Alcance:** Crear `scratch/test_spell_balance.php` con casos de prueba automatizados para verificar: suelo mínimo de 5 de maná, redondeo hacia arriba (`ceil`), descuento máximo del 30% por los 3 componentes, multiplicadores de área/alcance/duración y excepción ante sobrecarga (> 200 maná).
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-02.3`, `RF-02.4`, `RF-03.1`, `RF-03.2`, `Plan Sec. 6`
  * **Hecho cuando:** La ejecución `php scratch/test_spell_balance.php` ejecuta los asertos matemáticos y todos terminan en estado verde exitoso.

---

## Fase 3: Gestión del Ciclo de Vida de Conjuros, Antifraude y Variantes (Backend PHP 8.2+)

- [x] **Tarea 3.1: Control de cuota y persistencia de borradores (`SpellManagementService - Drafts`)**
  * **Alcance:** Implementar en `src/Services/SpellManagementService.php` los métodos `createDraft(User, SpellCreateDto)`, `updateDraft(User, string, SpellCreateDto)`, `deleteDraft(User, string)` y `listDrafts(User)`, limitando estrictamente a un máximo de diez (10) borradores simultáneos por autor.
  * **Cubre:** `RF-05.1`, `RF-06.1`
  * **Hecho cuando:** Un autor con 10 borradores recibe una excepción `DraftQuotaExceededException` (HTTP 403) al intentar crear el undécimo, permitiéndole operar normalmente al eliminar uno previo.

- [x] **Tarea 3.2: Publicación a estado experimental y asignación inicial de firmas (`SpellManagementService - Publish`)**
  * **Alcance:** Desarrollar en `SpellManagementService.php` el método `publishToExperimental(User, string $spellId)` que valida la autoría, revalida la fórmula matemática mediante `SpellBalanceService`, fija el estado en `experimental` e inicializa `signatures_count` a 0.
  * **Cubre:** `RF-04.2`, `RF-05.2`, `RNF-03`
  * **Hecho cuando:** El conjuro publicado transiciona de `draft` a `experimental`, sus firmas quedan en 0/3 y el registro pasa a ser visible públicamente en el santuario.

- [x] **Tarea 3.3: Detección de alteraciones matemáticas y reseteo selectivo antifraude (`SpellManagementService - Update`)**
  * **Alcance:** Implementar en `SpellManagementService.php` el método `updateExperimental(User, string $spellId, SpellCreateDto)` que calcula el SHA-256 de los nuevos parámetros: si difiere de `math_fingerprint`, restablece `signatures_count = 0` y emite auditoría; si la huella es idéntica (cambio meramente narrativo), preserva las firmas intactas.
  * **Cubre:** `RF-05.3`, `RF-05.4`, `RNF-03`, `Artículo III`
  * **Hecho cuando:** Alterar el alcance de un conjuro experimental con 2 firmas devuelve sus firmas a 0, mientras que corregir una falta de ortografía mantiene las 2 firmas registrando el evento en `audit_log`.

- [x] **Tarea 3.4: Inviolabilidad de conjuros validados y mecanismo de derivación a Variantes (`SpellManagementService - Variants`)**
  * **Alcance:** Implementar en `SpellManagementService.php` la prohibición de edición/borrado para registros con `status = 'validated'`, y crear el método `createVariant(User, string $spellId)` que clona la ficha validada como un nuevo borrador `draft` del usuario invocante con el sufijo `(Variante)`.
  * **Cubre:** `RF-06.1`, `RF-06.2`, `RF-06.3`
  * **Hecho cuando:** Intentar alterar un conjuro validado lanza `SpellImmutableException` (HTTP 403), y la clonación como variante crea un borrador editable con nuevo identificador sin alterar el original.

- [x] **Tarea 3.5: Ampliación de tests automatizados de ciclo de vida (`test_spell_balance.php` - Suite B)**
  * **Alcance:** Incorporar a `scratch/test_spell_balance.php` las pruebas de integración en base de datos SQLite en memoria: verificación del límite de 10 borradores, transición de publicación, reseteo de firmas ante cambio matemático vs. preservación ante cambio textual, y creación de variantes a partir de validados.
  * **Cubre:** `RF-05.1` a `RF-05.4`, `RF-06.1` a `RF-06.3`
  * **Hecho cuando:** La ejecución completa del script por CLI valida satisfactoriamente tanto la suite matemática como la suite de ciclo de vida con código de salida 0.

---

## Fase 4: Controlador REST y Capa de Enrutamiento (PHP 8.2+ MVC)

- [x] **Tarea 4.1: Endpoint de cálculo matemático determinista (`POST /api/v1/spells/calculate`)**
  * **Alcance:** Desarrollar en `src/Controllers/SpellCreatorController.php` el método `calculate(Request)` para procesar la petición de cálculo en vivo, deserializar la entrada en `SpellCalculationInputDto`, invocar `SpellBalanceService` y devolver el JSON del desglose con código HTTP 200 (o 400 ante sobrecarga).
  * **Cubre:** `RF-02.1`, `RF-03.1`, `RF-03.2`, `RF-04.2`, `RNF-01`
  * **Hecho cuando:** Una llamada HTTP POST con parámetros válidos devuelve el JSON del desglose matemático en menos de 50 ms y una con coste > 200 devuelve HTTP 400 con `ARCANE_OVERLOAD`.

- [x] **Tarea 4.2: Endpoints CRUD para gestión de borradores privados (`/api/v1/spells/drafts`)**
  * **Alcance:** Desarrollar en `SpellCreatorController.php` los métodos para `POST` (guardar borrador), `GET` (listar borradores del autor), `PUT /{id}` (actualizar borrador) y `DELETE /{id}` (eliminar borrador), integrando `AuthMiddleware` para autenticar al invocador.
  * **Cubre:** `RF-05.1`, `RF-06.1`
  * **Hecho cuando:** Peticiones HTTP contra `/api/v1/spells/drafts` permiten gestionar el ciclo de vida de borradores privados devolviendo códigos HTTP 200, 201, 403 o 404 según la regla de negocio.

- [x] **Tarea 4.3: Endpoints de publicación, actualización en moderación y clonación de variantes**
  * **Alcance:** Implementar en `SpellCreatorController.php` los métodos para `POST /api/v1/spells/publish/{id}`, `PUT /api/v1/spells/experimental/{id}` y `POST /api/v1/spells/variant/{id}`, conectando con `SpellManagementService` y respondiendo con las cargas útiles normalizadas.
  * **Cubre:** `RF-05.2`, `RF-05.3`, `RF-05.4`, `RF-06.2`, `RF-06.3`
  * **Hecho cuando:** Cada endpoint ejecuta la transición de estado correspondiente e inyecta las cabeceras de respuesta JSON adecuadas bajo sesión autenticada.

---

## Fase 5: Motor en Cliente, Componentes Reactivos e Interfaz Frontend (Modern Vanilla JS & CSS3)

- [x] **Tarea 5.1: Motor de cálculo matemático en cliente (`spellBalanceSimulator.js`)**
  * **Alcance:** Crear `public/assets/js/utils/spellBalanceSimulator.js` replicando con fidelidad 100% simétrica las constantes y la fórmula del backend (`WEIGHTS`, `MULTIPLIERS`, `DISCOUNTS`, `CIRCLES`, `MAX_MANA_CEILING`), garantizando una latencia de ejecución menor a 10 ms en el navegador.
  * **Cubre:** `RF-01.2` a `RF-01.5`, `RF-02.1` a `RF-02.4`, `RF-03.1`, `RF-04.1`, `RNF-02`
  * **Hecho cuando:** El simulador ejecutado en consola de navegador con un objeto de parámetros produce exactamente los mismos valores de maná bruto, descuento, coste final y círculo que el backend de PHP.

- [x] **Tarea 5.2: Cliente HTTP nativo para el creador de hechizos (`spellCreatorClient.js`)**
  * **Alcance:** Implementar `public/assets/js/api/spellCreatorClient.js` con funciones nativas ES Modules (`calculateSpell`, `saveDraft`, `listDrafts`, `updateDraft`, `deleteDraft`, `publishSpell`, `updateExperimental`, `createVariant`) usando `fetch` con `credentials: 'same-origin'`.
  * **Cubre:** `RF-04.2`, `RF-05.1`, `RF-05.2`, `RF-06.1`
  * **Hecho cuando:** Todas las funciones cliente encapsulan las llamadas HTTP, deserializan JSON de respuesta y manejan de forma homogénea los errores canónicos del servidor.

- [x] **Tarea 5.3: Componente visual del desglose pedagógico de maná (`manaBreakdownComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/manaBreakdownComponent.js` para renderizar el panel reactivo: sumatorio de efectos base, factores multiplicadores geométricos, deducciones porcentuales por componentes, coste final destacado, distintivo del Círculo Arcano y cartel luminoso de Sobrecarga Arcana.
  * **Cubre:** `RF-03.1`, `RF-03.2`, `RF-04.1`, `RNF-04`
  * **Hecho cuando:** La invocación del método `update(result)` actualiza el DOM de forma inmediata (< 50 ms), coloreando los círculos arcanos y mostrando el aviso de sobrecarga en rojo bermellón si supera los 200 puntos.

- [x] **Tarea 5.4: Componente de controles táctiles del taller arcano (`spellFormControls.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/spellFormControls.js` controlando los inputs numéricos (daño, cura, barrera), selector de CC, botones de radio para alcance/área/duración y casillas de componentes, emitiendo el evento personalizado `spell:params-changed` en cada pulsación.
  * **Cubre:** `RF-01.1` a `RF-01.5`, `RF-04.1`
  * **Hecho cuando:** Modificar cualquier campo numérico o selector dispara el evento en tiempo real sin recargar la página ni bloquear el hilo de ejecución principal.

- [x] **Tarea 5.5: Vista principal del Taller de Hechizos y gestión de borradores (`spellCreatorView.js`)**
  * **Alcance:** Crear `public/assets/js/views/spellCreatorView.js` orquestando formulario, simulador en cliente, sincronización periódica con el backend, almacenamiento temporal volátil en `localStorage` ante pérdida de red, cajón visual con la lista de los 10 borradores y acciones de Guardar Borrador / Publicar.
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-05.1`, `RF-05.2`, `RF-06.1`
  * **Hecho cuando:** El usuario puede diseñar un conjuro viendo el coste fluctuar en vivo, guardarlo como borrador privado, restaurarlo tras refrescar la página y publicarlo para moderación.

- [x] **Tarea 5.6: Hoja de estilos del Taller Mágico y Desglose de Maná (`spell-creator.css`)**
  * **Alcance:** Crear `public/assets/css/components/spell-creator.css` aplicando el sistema de diseño (tokens de color de maná, marfil antiguo, pergamino oscuro, tipografía rúnica y serif), maquetando el taller a dos columnas (formulario de forja y panel de maná `sticky`) y garantizando diseño responsivo en móvil.
  * **Cubre:** `RF-04.1`, `RNF-04`, `Artículo I`
  * **Hecho cuando:** La interfaz del taller presenta el formulario y el desglose de maná en dos columnas armónicas en escritorio, se colapsa a una columna fluida en móvil y cumple los criterios de accesibilidad visual y contraste.

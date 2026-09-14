# TASKS-06: Tareas de Implementación — Matriz de Afinidades Elementales y Encadenamiento de Combos

> **Especificación:** [`specs/06-elemental-affinity-combos.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/06-elemental-affinity-combos.spec.md)  
> **Plan Técnico:** [`specs/06-elemental-affinity-combos.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/06-elemental-affinity-combos.plan.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad a RF/RNF y criterio de aceptación verificable.

---

## Fase 1: Backend DTO, Matriz Elemental y Controladores REST (PHP 8.2+)

- [x] **Tarea 1.1: Objetos de transferencia de datos (`ElementalReactionDto`, `ElementalMatrixGraphDto` y `ComboResolutionResultDto`)**
  * **Alcance:** Crear `src/Dto/ElementalReactionDto.php`, `src/Dto/ElementalMatrixGraphDto.php` y `src/Dto/ComboResolutionResultDto.php` con `declare(strict_types=1);`, encapsulando los identificadores canónicos en inglés `camelCase` (`arcaneVaporization`, `fluidElectrocution`, etc.), multiplicadores, efectos tácticos y serialización JSON nativa.
  * **Cubre:** `RF-01.1`, `RF-03.1`, `RF-03.2`, `RF-04.1`, `RF-04.2`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** Los tres DTOs se instancian correctamente, validan tipos estrictos y `json_encode()` genera la estructura JSON normalizada del plan sin advertencias de tipo.

- [x] **Tarea 1.2: Servicio de la Matriz Elemental inmutable y simetría A+B (`ElementalMatrixService`)**
  * **Alcance:** Implementar `src/Services/ElementalMatrixService.php` conteniendo la tabla inmutable de las 7 reacciones duales canónicas y la resonancia de Arcano Puro, implementando los métodos `getMatrixGraph()`, `getReactionsForElement(string $element)` y `findReaction(string $elA, string $elB): ?ElementalReactionDto` garantizando la simetría $A + B = B + A$.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-03.1`, `RF-03.2`, `RNF-01`
  * **Hecho cuando:** Invocar `findReaction('fire', 'water')` y `findReaction('water', 'fire')` devuelve idéntico resultado de *Vaporización Arcana*, y buscar elementos incompatibles devuelve `null`.

- [x] **Tarea 1.3: Lógica matemática y resolución táctica autoritativa (`ElementalMatrixService - Resolve`)**
  * **Alcance:** Implementar en `ElementalMatrixService.php` el método `resolveCombo(string $activeAura, Spell $incomingSpell, bool $stunlockImmune): ComboResolutionResultDto`, aplicando la bonificación del $+50\%$ de daño (`comboDamageMultiplier = 1.5`), amplificación de Arcano Puro (+25% / +1s CC), trituración de barrera de 50 PV en `basalticFracture`, penetración de barrera en `twilightCollapse` y sobreescritura con daño pleno para incompatibles.
  * **Cubre:** `RF-03.2`, `RF-03.3`, `RF-03.4`, `RF-04.1`, `RF-04.2`, `RNF-01`, `Artículo II`
  * **Hecho cuando:** El método aplica exactamente las reglas canónicas de daño, trituración de barrera y penetración sin tocar la vida si la fractura tiene excedente, devolviendo el DTO tipado correspondiente.

- [x] **Tarea 1.4: Controlador REST de la Matriz Elemental (`ElementalMatrixController`)**
  * **Alcance:** Crear `src/Controllers/ElementalMatrixController.php` con los métodos para `GET /api/v1/elements/matrix`, `GET /api/v1/elements/reactions/{element}` y `POST /api/v1/elements/resolve-combo`.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-01.3`, `RF-04.1`
  * **Hecho cuando:** Las peticiones HTTP a `/api/v1/elements/matrix` y `/api/v1/elements/resolve-combo` responden con código HTTP 200 y el JSON del contrato técnico.

- [x] **Tarea 1.5: Script automatizado de pruebas backend (`test_elemental_combos.php`)**
  * **Alcance:** Crear `scratch/test_elemental_combos.php` para validar por CLI: las 7 reacciones duales en ambos sentidos de simetría, amplificación de Arcano Puro (+25% y +1s), sobreescritura de elementos no reactivos con daño íntegro, cálculo del $+50\%$ de daño, trituración de barrera sin excedente a vida y penetración de Colapso Crepuscular dejando la barrera intacta.
  * **Cubre:** `RF-03.1` a `03.4`, `RF-04.1`, `RF-04.2`, `Plan Sec. 6.1`
  * **Hecho cuando:** La ejecución `php scratch/test_elemental_combos.php` pasa el 100% de los asertos matemáticos y tácticos con código de salida 0.

---

## Fase 2: Utilidades de Combos y Salvaguarda Anti-Stunlock (Vanilla JS Utils)

- [x] **Tarea 2.1: Gestor de salvaguarda Anti-Stunlock (`stunlockManager.js`)**
  * **Alcance:** Desarrollar `public/assets/js/utils/stunlockManager.js` gestionando el ciclo de inmunidad de 3 segundos (`stunlockImmunityDurationMs = 3000`), discriminando entre *Hard CC* (`hardStun`, `freezeParalysis`) que se bloquea durante la inmunidad (reemplazado por onda de choque) y *Soft CC* (`blindnessMist`, `rootAndSlow`) que sí se aplica.
  * **Cubre:** `RF-05.2`, `RF-05.3`, `RNF-01`
  * **Hecho cuando:** Tras expirar un Hard CC, invocar `isImmuneToHardCc()` devuelve true durante 3000 ms, permitiendo la aplicación de Soft CC y emitiendo los eventos correspondientes.

- [x] **Tarea 2.2: Motor de resolución de combos en cliente (`comboResolver.js`)**
  * **Alcance:** Desarrollar `public/assets/js/utils/comboResolver.js` con el algoritmo de resolución en cliente idéntico al backend: detección de coincidencia simétrica, aplicación de factor 1.5, inyección de efectos tácticos de barrera/niebla/CC y consumo total del aura activa dejando estado neutral limpio.
  * **Cubre:** `RF-03.1` a `03.4`, `RF-04.1`, `RF-04.2`, `RNF-01`, `RNF-02`
  * **Hecho cuando:** La resolución en cliente arroja idénticos resultados de daño y efectos que el backend en menos de 5 ms sin consultas de red.

- [x] **Tarea 2.3: Cola determinista secuencial FIFO para ráfagas simultáneas (`comboResolver.js - Queue`)**
  * **Alcance:** Implementar en `comboResolver.js` la clase `SpellImpactQueue` para encolar impactos que lleguen con menos de 100 ms de separación, despachándolos atómicamente por FIFO mediante `requestAnimationFrame` para garantizar que el primer impacto aplique aura y el segundo detone el combo sin condiciones de carrera.
  * **Cubre:** `RF-05.4`, `RNF-01`
  * **Hecho cuando:** Al recibir dos impactos con 20 ms de diferencia, ambos se resuelven secuencialmente en orden sin perderse ni colisionar.

---

## Fase 3: Componentes Visuales del Aura y la Rueda Rúnica (Vanilla JS)

- [x] **Tarea 3.1: Componente visual del halo de aura elemental (`elementalAuraComponent.js`)**
  * **Alcance:** Crear `public/assets/js/components/elementalAuraComponent.js` para renderizar en el maniquí el halo luminoso pulsante con el color del elemento activo y el anillo rúnico circular que decrece durante los 5 segundos de la ventana de resonancia, con soporte de disipación y refresco homogéneo.
  * **Cubre:** `RF-02.1`, `RF-02.2`, `RF-02.4`, `RF-02.5`, `RNF-03`
  * **Hecho cuando:** Al aplicar un elemento, el maniquí muestra el halo y la barra circular decreciente durante 5 s, refrescándose si vuelve a impactar el mismo elemento y disolviéndose suavemente al expirar.

- [x] **Tarea 3.2: Rueda Rúnica interactiva octogonal para escritorio (`elementalWheelComponent.js - Desktop`)**
  * **Alcance:** Desarrollar `public/assets/js/components/elementalWheelComponent.js` maquetando el diagrama octogonal en SVG nativo con los 8 glifos elementales, animando filamentos rúnicos de conexión al posar el cursor o pulsar un elemento y desplegando la lámina con el nombre litúrgico y efectos de las reacciones compatibles.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RNF-03`, `RNF-04`
  * **Hecho cuando:** Al hacer clic en un glifo elemental, se encienden sus filamentos de conexión hacia los elementos reactivos y se expone la descripción en noble castellano.

- [x] **Tarea 3.3: Adaptabilidad móvil del Códice Rúnico (`elementalWheelComponent.js - Mobile`)**
  * **Alcance:** Implementar en `elementalWheelComponent.js` la vista adaptativa para pantallas móviles ($< 768\text{ px}$): transformar el octógono en un selector radial táctil compacto asistido por una lámina de acordeón ceremonial rúnico desplegable por elemento.
  * **Cubre:** `RF-01.1`, `Caso Límite 5`, `RNF-03`
  * **Hecho cuando:** En pantallas reducidas la interfaz conmuta al formato de acordeón táctil sin solapamientos ni desbordamientos horizontales.

- [x] **Tarea 3.4: Estilos del Códice, Rueda y Auras Cromáticas (`elemental-codex.css`)**
  * **Alcance:** Crear `public/assets/css/components/elemental-codex.css` definiendo los colores heráldicos de los 8 elementos, animaciones de filamentos SVG, pulsaciones del aura circular del maniquí y tipografías monumentales en oro rúnico para los textos de combo.
  * **Cubre:** `RF-01.1`, `RF-02.2`, `RF-06.2`, `RNF-04`
  * **Hecho cuando:** Todos los elementos visuales del códice y del simulador aplican las variables del sistema de diseño con contraste accesible WCAG 2.1 AA.

---

## Fase 4: Integración con la Cámara de Conjuración y el Tomo (SPEC-05)

- [x] **Tarea 4.1: Persistencia de auras al hojear entre páginas del Grimorio**
  * **Alcance:** Integrar `elementalAuraComponent.js` y `comboResolver.js` con el ciclo de vida del simulador en `grimoireSimulatorView.js`, asegurando que el estado del aura activa (`TargetAuraState`) y su cuenta atrás de 5 s se mantengan inalterados cuando el usuario pase de página en el libro.
  * **Cubre:** `RF-02.3`, `RNF-01`
  * **Hecho cuando:** Un usuario lanza un conjuro de Agua en la página 1, hojea el tomo a la página 3 antes de 5 segundos, dispara Rayo y comprueba que se detona *Electrocución Fluida*.

- [x] **Tarea 4.2: Deflagración de partículas fusionadas y Texto Flotante Monumental**
  * **Alcance:** Conectar la detonación del combo con `arcaneCanvasComponent.js` y `floatingCombatTextComponent.js` de SPEC-05, generando una explosión radial bicromática en el lienzo Canvas 2D y proyectando el texto monumental en oro rúnico (ej. `¡VAPORIZACIÓN ARCANA! -68 PV`).
  * **Cubre:** `RF-06.1`, `RF-06.2`, `RNF-04`
  * **Hecho cuando:** La detonación genera la deflagración combinada de partículas en el lienzo y hace brotar el texto ceremonial de combo destacado.

- [x] **Tarea 4.3: Registro de combos en Bitácora y Anuncios Accesibles ARIA**
  * **Alcance:** Actualizar el panel de la bitácora de pruebas para registrar la etiqueta distintiva del combo (ej. `[Combo: Electrocución Fluida]`) con elementos intervinientes y daño total en `localStorage`, y emitir el mensaje accesible en la región viva `aria-live="polite"`.
  * **Cubre:** `RF-06.3`, `RF-06.4`, `RNF-03`
  * **Hecho cuando:** La bitácora inscribe el registro de la reacción y los lectores de pantalla anuncian la detonación del combo.

- [x] **Tarea 4.4: Enlaces rúnicos de afinidad en las fichas de conjuro del libro**
  * **Alcance:** Modificar `grimoireBookComponent.js` para añadir un acceso directo rúnico junto a la afinidad de cada conjuro, abriendo el Códice de Afinidades centrado en el elemento correspondiente.
  * **Cubre:** `RF-01.3`, `RNF-04`
  * **Hecho cuando:** Pulsar el glifo elemental de una ficha abre el Códice con dicho elemento preseleccionado y sus enlaces iluminados.

---

## Fase 5: Orquestación, Cliente API y Verificación Integral

- [x] **Tarea 5.1: Cliente HTTP para el Códice Elemental (`elementalMatrixClient.js`)**
  * **Alcance:** Implementar `public/assets/js/api/elementalMatrixClient.js` con métodos nativos `fetchMatrixGraph()`, `fetchReactionsForElement(element)` y `resolveCombo(data)` utilizando `fetch` y cabeceras JSON.
  * **Cubre:** `RF-01.1`, `RF-01.2`, `RF-04.1`
  * **Hecho cuando:** El cliente consume correctamente los endpoints del servidor y maneja respuestas y posibles errores 404/400.

- [x] **Tarea 5.2: Vista ceremonial del Códice de Afinidades (`elementalCodexView.js`)**
  * **Alcance:** Implementar `public/assets/js/views/elementalCodexView.js` orquestando la Rueda Rúnica, la consulta de compatibilidades, la vista móvil y la integración con el simulador de grimorio.
  * **Cubre:** `RF-01.1` a `01.3`, `RF-06.1` a `06.4`
  * **Hecho cuando:** El Códice opera de forma autónoma y sincronizada con el resto del portal arcano mediante eventos desacoplados.

- [x] **Tarea 5.3: Protocolo de verificación frontend y pruebas de estrés**
  * **Alcance:** Ejecutar en el navegador la batería de pruebas de SPEC-06 (Sec. 6.2): verificación de persistencia de auras entre páginas, prueba de la salvaguarda Anti-Stunlock de 3 s contra Hard CC, prueba de cola FIFO ante ráfagas de $< 100\text{ ms}$, validación de `prefers-reduced-motion` y adaptabilidad en pantalla móvil.
  * **Cubre:** `RNF-01` a `RNF-05`
  * **Hecho cuando:** Todos los casos de prueba del protocolo frontend pasan satisfactoriamente sin errores en consola ni violaciones del Dogma Vanilla.

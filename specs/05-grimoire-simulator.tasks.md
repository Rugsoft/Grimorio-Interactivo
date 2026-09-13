# TASKS-05: Tareas de Implementación — Simulador de Grimorio, Partículas de Maná y Recitado Mágico por Voz

> **Especificación:** [`specs/05-grimoire-simulator.spec.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/05-grimoire-simulator.spec.md)  
> **Plan Técnico:** [`specs/05-grimoire-simulator.plan.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/specs/05-grimoire-simulator.plan.md)  
> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Reglas:** Tareas atómicas de 20-30 minutos, ordenadas por dependencia estricta, con trazabilidad a RF/RNF y criterio de aceptación verificable.

---

## Fase 1: Backend DTO, Consultas y Controladores REST (PHP 8.2+)

- [x] **Tarea 1.1: Objeto de transferencia de datos de página de grimorio (`GrimoirePageDto`)**
  * **Alcance:** Crear `src/Dto/GrimoirePageDto.php` con tipado estricto `declare(strict_types=1);`, encapsulando id, slug, name, magicSchool, elementalAffinity, circle, manaCost, castingTime, incantationFormula (en castellano), componentes (hasVerbal, hasSomatic, hasMaterial), rangeType, areaType, durationType, efectos cuantitativos (damage, healing, barrier, crowdControlType), description, authorAlias, clanName y status con serialización JSON nativa.
  * **Cubre:** `RF-01.5`, `RF-03.1`, `RF-03.2`, `RNF-04`, `RNF-05`, `Artículo V`
  * **Hecho cuando:** La instanciación de `GrimoirePageDto` y su posterior `json_encode()` genera exactamente la estructura tipada requerida por el contrato REST sin campos nulos ni advertencias de tipo.

- [x] **Tarea 1.2: Servicio de consulta y segmentación del catálogo (`GrimoireQueryService`)**
  * **Alcance:** Implementar `src/Services/GrimoireQueryService.php` con los métodos `getCanonicalSpells(?int $circle, ?string $element, int $page, int $limit)` (exclusivamente `status = 'validated'`) y `getAuthorEssays(User $author, ?int $circle, ?string $element, int $page, int $limit)` (conjuros propios en `draft` o `experimental`) mediante consultas preparadas PDO sobre la tabla `spells`.
  * **Cubre:** `RF-01.2`, `RF-01.4`
  * **Hecho cuando:** La llamada en modo canónico devuelve únicamente conjuros validados paginados, mientras que la llamada de ensayos devuelve los borradores y conjuros en revisión del autor activo con aislamiento estricto.

- [x] **Tarea 1.3: Controlador REST del Grimorio (`GrimoireController`)**
  * **Alcance:** Crear `src/Controllers/GrimoireController.php` con los métodos para `GET /api/v1/grimoire/spells` (procesando los parámetros query `circle`, `element`, `mode`, `page`, `limit`) y `GET /api/v1/grimoire/spells/{id}`, resolviendo la sesión del usuario para autorizar el modo `essays` o devolver HTTP 401 si es anónimo.
  * **Cubre:** `RF-01.2`, `RF-01.4`, `RF-01.5`
  * **Hecho cuando:** Peticiones HTTP a `/api/v1/grimoire/spells` devuelven HTTP 200 con la carga JSON esperada, y peticiones con `mode=essays` sin sesión autenticada responden con HTTP 401 Unauthorized.

- [x] **Tarea 1.4: Script automatizado de pruebas backend del catálogo (`test_grimoire_catalog.php`)**
  * **Alcance:** Desarrollar `scratch/test_grimoire_catalog.php` para validar por CLI: entrega del catálogo canónico, rechazo 401 en modo ensayos para anónimos, acceso a ensayos para autores autenticados, estructura íntegra de `GrimoirePageDto` y filtrado exacto por Círculo y Afinidad.
  * **Cubre:** `RF-01.2`, `RF-01.4`, `Plan Sec. 6.1`
  * **Hecho cuando:** La ejecución `php scratch/test_grimoire_catalog.php` pasa el 100% de los asertos de integración con código de salida 0.

---

## Fase 2: Motor de Partículas en Canvas 2D y Abstracción Vocal (Vanilla JS Utils)

- [x] **Tarea 2.1: Estructura de partícula y Buffer Circular FIFO (`particleEngine.js - Pool`)**
  * **Alcance:** Desarrollar en `public/assets/js/utils/particleEngine.js` la clase `Particle` (coordenadas x/y, velocidades vx/vy, aceleración, escala, alfa, color, tiempo de vida) y el Ring Buffer `ParticlePool` con un límite estricto de doscientas (200) partículas pre-instanciadas con reciclado circular FIFO continuo.
  * **Cubre:** `RF-03.4`, `RNF-01`, `RNF-05`, `Artículo I`
  * **Hecho cuando:** Al emitir más de 200 partículas continuadas en el motor, las entidades activas no superan nunca el límite de 200 y se reutilizan las más antiguas sin provocar asignaciones de memoria `new Particle()` durante el bucle de renderizado.

- [x] **Tarea 2.2: Física cinemática por Geometría de Hechizo (`particleEngine.js - Geometries`)**
  * **Alcance:** Implementar en `particleEngine.js` los algoritmos de trayectoria física según los modificadores de área y alcance: vector directo parabólico hacia el objetivo (`singleTarget`/`touch`), abanico angular cónico con dispersión $\theta \in [\theta_0 - 25^\circ, \theta_0 + 25^\circ]$ (`cone`), haz lineal colimado continuo (`line`) y deflagración radial esférica centrada en el blanco (`sphere`).
  * **Cubre:** `RF-03.2`, `RNF-02`
  * **Hecho cuando:** El método `emitSpell(geometry, origin, target)` desata la dispersión geométrica correspondiente en el lienzo según el parámetro recibido.

- [x] **Tarea 2.3: Modulación cromática y dinámica por Afinidad Elemental y Círculo (`particleEngine.js - Elements`)**
  * **Alcance:** Implementar en `particleEngine.js` los 8 perfiles elementales (Fuego con ascuas ascendentes, Agua/Escarcha con ondas fluidas, Rayo con arcos fractales instantáneos, Tierra con esquirlas y gravedad pesada, Viento con vórtices helicoidales, Luz con haces prismáticos radiantes, Oscuridad con zarcillos de succión centrípeta y Arcano Puro con constelaciones geométricas) y escalado de densidad por Círculo (I al V).
  * **Cubre:** `RF-03.1`, `RF-03.3`
  * **Hecho cuando:** Cada una de las 8 afinidades elementales produce su paleta de colores y física distintiva, y un conjuro de Círculo V genera mayor volumen de partículas e intensidad lumínica que uno de Círculo I.

- [x] **Tarea 2.4: Servicio de voz nativo: Síntesis litúrgica y Reconocimiento fonético (`speechService.js`)**
  * **Alcance:** Crear `public/assets/js/utils/speechService.js` encapsulando `SpeechSynthesis` (declamación en `es-ES`, cadencia solemne a velocidad 0.85 y tono 0.95) y `SpeechRecognition` / `webkitSpeechRecognition` con algoritmo de tolerancia fonética (coincidencia de nombre canónico, fórmula ceremonial o al menos 2 palabras clave significativas de más de 3 letras), con degradación grácil ante denegación de permisos o falta de soporte.
  * **Cubre:** `RF-04.1`, `RF-04.2`, `RF-04.3`, `RF-04.4`, `RNF-04`
  * **Hecho cuando:** `reciteSpell()` sintetiza la frase litúrgica en noble castellano y `matchesSpellInvocation()` valida positivamente frases que contengan al menos 2 palabras clave del conjuro activo.

---

## Fase 3: Componentes de la Cámara de Conjuración (Maniquí, Textos Flotantes y Lienzo Canvas)

- [x] **Tarea 3.1: Máquina de estados del Maniquí Arcano (`combatDummyComponent.js`)**
  * **Alcance:** Implementar `public/assets/js/components/combatDummyComponent.js` con máquina de estados completa: salud base de 500 PV, absorción prioritaria de barrera con renovación por valor dominante (sin apilamiento infinito), techo inmutable de curación en 500 PV (`[Salud Plena]`), ataduras visuales de control de masas con disipación a los 4 s, persistencia de estado entre páginas y regeneración automática a los 2 s tras caer a 0 PV.
  * **Cubre:** `RF-02.1`, `RF-02.3`, `RF-02.4`, `RF-02.5`, `RF-05.1`
  * **Hecho cuando:** El maniquí resuelve los impactos aplicando la prioridad de barrera, acota la curación a 500 PV, conserva su daño al hojear entre páginas y se regenera automáticamente tras disolverse a los 0 PV.

- [x] **Tarea 3.2: Componente de Textos Flotantes Arcanos Escalonados (`floatingCombatTextComponent.js`)**
  * **Alcance:** Crear `public/assets/js/components/floatingCombatTextComponent.js` para renderizar en el lienzo los rótulos animados con escalonamiento espacial y temporal: cifra de daño en torso central (`-45 PV`, carmesí), curación o barrera con desplazamiento lateral (+35 px, esmeralda o azul zafiro) y rótulo de CC en corona superior (`¡Aturdido!`, oro rúnico) con retardo de 150 ms.
  * **Cubre:** `RF-05.2`, `RNF-04`
  * **Hecho cuando:** Un conjuro con efectos mixtos emite los tres textos en posiciones y tiempos desfasados, flotando hacia arriba y disolviéndose suavemente sin empastarse.

- [x] **Tarea 3.3: Contenedor del Lienzo Canvas y Control de Rendimiento (`arcaneCanvasComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/arcaneCanvasComponent.js` gestionando el lienzo Canvas 2D, bucle `requestAnimationFrame`, suspensión inmediata al ocultar pestaña (`document.visibilitychange`), soporte de `prefers-reduced-motion` (omisión de proyectiles móviles y sustitución por destello estático y texto flotante) y monitor de FPS con auto-throttle de densidad de partículas si cae por debajo de 30 FPS.
  * **Cubre:** `RF-06.1`, `RF-06.2`, `RF-06.3`, `RNF-01`
  * **Hecho cuando:** La animación opera a 60 FPS estables, se suspende inmediatamente al minimizar la pestaña, y activar `prefers-reduced-motion` elimina las trayectorias violentas manteniendo los textos flotantes.

---

## Fase 4: Navegación del Tomo, Interfaz y Cliente API (Vanilla JS)

- [x] **Tarea 4.1: Cliente HTTP para el simulador de grimorio (`grimoireClient.js`)**
  * **Alcance:** Implementar `public/assets/js/api/grimoireClient.js` con métodos nativos `fetchSpells(params)` y `fetchSpellDetail(id)` utilizando cabeceras JSON y `credentials: 'same-origin'`.
  * **Cubre:** `RF-01.2`, `RF-01.5`
  * **Hecho cuando:** El cliente HTTP realiza peticiones `fetch` tipadas, desempaqueta la estructura JSON del backend y gestiona limpiamente las respuestas 200 y 401.

- [x] **Tarea 4.2: Componente de Tomo Arcano y Navegación de Páginas (`grimoireBookComponent.js`)**
  * **Alcance:** Desarrollar `public/assets/js/components/grimoireBookComponent.js` maquetando la doble página (página izquierda: iluminación, metadatos, componentes y fórmula en noble castellano; página derecha: contenedor de la cámara), navegación acotada sin bucle infinito (flechas desvanecidas rúnicamente en extremos), índice rúnico por Círculos/Afinidad y visualización de pergamino virgen ante filtros vacíos.
  * **Cubre:** `RF-01.1`, `RF-01.3`, `RF-01.4`, `RF-01.5`, `RNF-03`
  * **Hecho cuando:** El usuario puede pasar páginas de forma fluida mediante flechas o atajos de teclado, las flechas se desactivan al llegar al primer o último conjuro del catálogo y se muestra la leyenda ceremonial si no hay conjuros en un filtro.

- [x] **Tarea 4.3: Bitácora de Pruebas persistente en `localStorage`**
  * **Alcance:** Implementar el gestor de la bitácora de pruebas en el cliente, registrando cronológicamente los últimos cinco (5) impactos (marca temporal, nombre del conjuro, desglose de daño/barrera/CC y salud restante) en `localStorage` bajo `grimorio_test_log_v1`, y permitiendo su limpieza al pulsar el botón «Restaurar Maniquí».
  * **Cubre:** `RF-02.6`, `RF-05.3`
  * **Hecho cuando:** Cada impacto inscribe un registro en el panel de bitácora, la lista retiene exactamente un máximo de 5 entradas persistentes tras refrescar el navegador y se vacía al pulsar «Restaurar Maniquí».

- [x] **Tarea 4.4: Estilos y Maquetación del Tomo del Grimorio (`grimoire-simulator.css`)**
  * **Alcance:** Crear `public/assets/css/components/grimoire-simulator.css` aplicando el sistema de diseño (textura de pergamino místico, marcos rúnicos, cuero antiguo, variables `--color-mana`, `--color-crimson`, panel táctil ceremonial), asegurando vista a doble página en escritorio y vista conmutada de pliegue mágico en dispositivos móviles.
  * **Cubre:** `RF-01.1`, `Caso Límite 5`, `RNF-04`
  * **Hecho cuando:** La interfaz se maqueta como un libro abierto a doble página en pantallas de escritorio y se adapta a una columna con pliegue alternable en móviles sin desbordamientos ni saltos visuales.

---

## Fase 5: Integración, Orquestación de Vista y Verificación Integral

- [ ] **Tarea 5.1: Vista principal del Simulador y Bus de Eventos (`grimoireSimulatorView.js`)**
  * **Alcance:** Crear `public/assets/js/views/grimoireSimulatorView.js` orquestando el libro, el lienzo, el maniquí, los sellos de lanzamiento táctil y por micrófono, el botón «Escuchar Cántico», el botón «Restaurar Maniquí», el conmutador Canónico vs. Ensayos, y la emisión de anuncios accesibles en una región viva `aria-live="polite"`.
  * **Cubre:** `RF-01` a `RF-06`, `RNF-01` a `RNF-05`
  * **Hecho cuando:** La vista responde armónicamente a todos los eventos desacoplados, permitiendo hojear conjuros, declamar la liturgia, invocar por clic o voz, presenciar las partículas y actualizar el maniquí con anuncios accesibles.

- [ ] **Tarea 5.2: Protocolo de verificación frontend y de rendimiento**
  * **Alcance:** Ejecutar en el navegador la batería de pruebas definida en el Plan Técnico (Sec. 6.2): verificación de 60 FPS estables con conjuros de Círculo V, comprobación de auto-throttle ante bajada de tasa de cuadros, validación de `prefers-reduced-motion`, prueba de denegación de micrófono con degradación ceremonial y tolerancia fonética de palabras clave.
  * **Cubre:** `RNF-01`, `RNF-02`, `RNF-03`, `RNF-04`, `RNF-05`
  * **Hecho cuando:** Todos los casos del protocolo se ejecutan satisfactoriamente sin errores en la consola del navegador y cumpliendo rigurosamente el Dogma Vanilla.

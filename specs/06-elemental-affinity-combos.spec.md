# SPEC-06: Matriz de Afinidades Elementales y Encadenamiento de Combos

> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Especificación Ratificada y Blindada tras Auditoría de Calidad (QA)  
> **Área:** Alquimia Arcana, Resonancia Elemental y Dinámica de Combate  
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Cero detalles de implementación técnica, arquitectura o nombres de archivos.

---

## 1. Contexto y Objetivo

En el santuario del Grimorio Interactivo, el aprendizaje de la magia no concluye con la forja solitaria de conjuros equilibrados (SPEC-04) ni con su contemplación individual en el tomo (SPEC-05). La verdadera maestría de los hechiceros —evocando los principios de la magia analítica de *Frieren*, la sabiduría elemental de *Tolkien* y las tácticas cooperativas de *D&D*— reside en comprender cómo las diferentes fuerzas de la naturaleza colisionan, se complementan y desatan reacciones arcanas devastadoras.

Aunque el **Artículo II de la Constitución** y la **SPEC-04** garantizan la neutralidad elemental absoluta en la concepción del conjuro (la afinidad no encarece ni abarata el maná base en frío), en el plano activo de la invocación los elementos interactúan como fuerzas vivas.

El objetivo de esta especificación es definir el **Códice de Afinidades Elementales** y el sistema de **Encadenamiento de Combos**: un modelo de alquimia arcana que recompensa la sincronización táctica de los practicantes mediante auras temporales persistentes entre páginas, detonaciones binarias con bonificaciones del $+50\%$ de daño ($\times 1.5$) y efectos secundarios cualitativos, salvaguardando la escala de poder del santuario mediante mecanismos rigurosos contra la parálisis infinita (*Anti-Stunlock*) y dualidad lingüística canónica bajo el Artículo V.

---

## 2. Actores y Usuarios

* **El Aprendiz Elemental (Lector / Estudiante):**  
  Consulta el Códice de Afinidades en el grimorio (tanto en la Rueda Rúnica octogonal en escritorio como en el formato adaptativo de acordeón rúnico en móvil) para estudiar las reacciones mágicas antes de practicar.
* **El Estratega y Duelista Arcano (Practicante / Experimentador):**  
  Ejecuta rotaciones de conjuros secuenciales en la Cámara de Conjuración, hojeando ágilmente entre páginas del libro sin perder el aura activa del blanco, midiendo tiempos de reacción y aprovechando la ventana de 5 segundos.
* **El Erudito de la Torre (Maestro / Diseñador):**  
  Verifica que las interacciones elementales respeten las leyes canónicas de la naturaleza mágica y no introduzcan desequilibrios desmedidos en la escala de poder.
* **El Usuario con Necesidades de Accesibilidad:**  
  Requiere identificar las auras y las reacciones mediante glifos distintivos, halos cromáticos con temporizador, textos flotantes solemnes y anuncios auditivos accesibles.

---

## 3. Historias de Usuario

* **HU-01 (Estudio del Códice Elemental):**  
  *Como* aprendiz de las artes naturales,  
  *Quiero* consultar una Rueda Rúnica interactiva (o acordeón en móvil) que conecte los 8 elementos del santuario,  
  *Para* aprender de forma visual e intuitiva qué reacciones se desatan al combinar dos fuerzas arcanas.

* **HU-02 (Cebado y Detonación entre Páginas):**  
  *Como* mago en entrenamiento,  
  *Quiero* que al impactar a un blanco con un conjuro elemental se le aplique un aura visible durante 5 segundos que se conserve al hojear el libro,  
  *Para* tener tiempo suficiente de buscar y lanzar un segundo conjuro ubicado en otra página del tomo que detone el combo.

* **HU-03 (Recompensa de Maestría sin Sobrecoste):**  
  *Como* invocador ágil,  
  *Quiero* recibir una bonificación del $+50\%$ de daño y un efecto secundario táctico al completar un combo con éxito,  
  *Para* sentirme recompensado por mi pericia en la sincronización sin que el sistema me penalice con gasto de maná extra.

* **HU-04 (Encadenamiento Fluido sin Parálisis Infinita):**  
  *Como* practicante de rotaciones mágicas continuas,  
  *Quiero* poder seguir encadenando reacciones sucesivas mientras el blanco adquiere una salvaguarda temporal de 3 segundos contra el aturdimiento continuo,  
  *Para* disfrutar de una simulación dinámica y justa que evite bucles degenerados de inmovilización perpetua.

* **HU-05 (Claridad Sensorial en la Reacción):**  
  *Como* hechicero inmersivo,  
  *Quiero* que la detonación del combo desate una deflagración visual fusionada y un texto flotante monumental con el nombre litúrgico de la reacción,  
  *Para* percibir con júbilo y claridad el clímax de mi conjuración.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: El Códice de Afinidades y la Rueda Rúnica Adaptativa
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ incorporar en el Grimorio un **Códice de Afinidades Elementales**, estructurado visualmente mediante una **Rueda Rúnica octogonal** en pantallas de escritorio, y mediante un **Selector Radial Táctil con Lámina de Acordeón Rúnico** en pantallas móviles, exhibiendo los ocho (8) elementos canónicos: Fuego (`fire`), Agua/Escarcha (`water`), Rayo (`lightning`), Tierra (`earth`), Viento (`wind`), Luz (`light`), Oscuridad (`darkness`) y Arcano Puro (`pureArcane`).
* **RF-01.2 [Dirigido por Eventos]:**  
  CUANDO el usuario seleccione o pose el cursor sobre cualquiera de los ocho glifos elementales en el Códice, el sistema DEBERÁ iluminar rúnicamente los filamentos que lo conectan con sus elementos reactivos compatibles y desplegar una lámina con el nombre litúrgico de la reacción, su identificador técnico, su descripción mitológica y el efecto que produce.
* **RF-01.3 [Ubicuo]:**  
  El sistema DEBERÁ incluir en cada ficha de conjuro del grimorio un acceso directo rúnico al Códice que resalte los elementos que mejor combinan con dicho conjuro.

---

### RF-02: Dinámica de Imbuición, Auras Elementales y Persistencia entre Páginas
* **RF-02.1 [Dirigido por Eventos]:**  
  CUANDO un conjuro con afinidad elemental específica impacte sobre un objetivo sin aura activa, el sistema DEBERÁ imbuir al blanco con un **Aura Elemental (`activeElementalAura`) visible durante una ventana de resonancia de exactamente cinco (5) segundos (`resonanceExpiresAt`)**.
* **RF-02.2 [Estado]:**  
  MIENTRAS un objetivo permanezca imbuido con un aura elemental, el sistema DEBERÁ mostrar un halo luminoso pulsante del color heráldico del elemento que **abraza la silueta del blanco** (criterio ratificado): una capa-luz derivada de la propia efigie —imagen clonada con desenfoque y resplandor del color vigente— en lugar de cualquier geometría circular (prohibido el anillo o disco). La duración de la ventana la declara un **contador numérico** que marca los segundos enteros restantes (5 → 0); no existe indicador rúnico circular. La capa-luz es **perenne**: en reposo pulsa en **dorado arcano** y al imbuirse se tiñe del color heráldico del elemento entrante con transición suave, regresando al dorado al expirar la ventana.
* **RF-02.3 [Ubicuo]:**  
  El sistema DEBERÁ **conservar activa el aura elemental y su temporizador decreciente de cinco (5) segundos al hojear las páginas del grimorio**, permitiendo probar combinaciones entre conjuros distribuidos en distintas páginas del libro.
* **RF-02.4 [Dirigido por Eventos]:**  
  CUANDO un conjuro del **mismo elemento que el aura ya activa** impacte sobre el objetivo (ej. Fuego sobre Fuego), el sistema DEBERÁ **reiniciar el contador a cinco (5) segundos**, prolongando la ventana sin detonar combo.
* **RF-02.5 [Dirigido por Eventos]:**  
  CUANDO transcurran los cinco (5) segundos de la ventana de resonancia sin que impacte ningún elemento reactivo, el sistema DEBERÁ disipar el aura suavemente en el éter sin desatar ningún efecto residual.
* **RF-02.7 [Ubicuo, criterio ratificado]:**  
  La expiración natural de la ventana DEBERÁ ser una **verdad única para todo el sistema**: al disiparse el aura, el estado elemental interno (`TargetAuraState`) del blanco DEBERÁ purgarse en el mismo instante, de modo que un impacto posterior se resuelva siempre contra el estado real del blanco. Reimbuir el mismo elemento tras la expiración es una **imbuición nueva (Caso A)**, jamás un refresco fantasma; un elemento distinto solo detona reacción si el blanco realmente luce aura vigente. El anuncio `combo:aura-expired` DEBERÁ burbujear por el bus de combos (contrato común con `combo:aura-applied` y `combo:reaction-triggered`) y el bucle de escena del halo DEBERÁ latir en el mismo planificador de cuadros que la vista, garantizando que la expiración, los vuelos de proyectil y las resoluciones de impacto compartan un único reloj. La **cola FIFO de ráfagas (RF-05.4) DEBERÁ leer el aura VIVA en el cuadro de resolución de cada impacto**, jamás la instantánea congelada al encolar: la cola drena un impacto por cuadro y, durante ese lapso, el aura puede haber sido aplicada por un impacto anterior de la propia ráfaga (el primero imbuye, el segundo detona SOBRE esa imbuición) o haber expirado por su ventana natural.

---

### RF-03: La Matriz Canónica de Reacciones Arcanas Binarias
* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ regir las reacciones mágicas de forma determinista y simétrica mediante **interacciones por pares de dos elementos sucesivos ($A + B$ o $B + A$)**, reconociendo oficialmente en la capa técnica y litúrgica las siguientes siete (7) Reacciones Arcanas Duales:
  1. **Fuego + Agua / Escarcha:** *Vaporización Arcana* (`arcaneVaporization`).
  2. **Agua + Rayo:** *Electrocución Fluida* (`fluidElectrocution`).
  3. **Fuego + Viento:** *Deflagración en Vórtice* (`vortexDeflagration`).
  4. **Tierra + Rayo:** *Fractura Basáltica* (`basalticFracture`).
  5. **Tierra + Agua:** *Ciénaga Petrificante* (`petrifyingSwamp`).
  6. **Viento + Agua / Escarcha:** *Ventisca Helada* (`glacialBlizzard`).
  7. **Luz + Oscuridad:** *Colapso Crepuscular* (`twilightCollapse`).
* **RF-03.2 [Ubicuo]:**  
  El sistema DEBERÁ clasificar el elemento **Arcano Puro como Catalizador Universal (`pureArcaneResonance`)**:
  * Arcano Puro no posee elemento opuesto ni genera una reacción excluyente.
  * Al impactar sobre un blanco que posea cualquier aura elemental previa, Arcano Puro desata la **Resonancia Arcana Pura**: consume el aura y amplifica en un $+25\%$ ($\times 1.25$) los valores cuantitativos (daño, barrera o curación) y añade **un (+1) segundo de duración** a los estados de control de masas que el elemento original hubiera provocado.
* **RF-03.3 [Dirigido por Eventos]:**  
  SI un conjuro de un elemento no reactivo (ej. Luz impactando sobre un blanco con aura de Fuego) golpea al objetivo, el sistema DEBERÁ **infligir íntegramente su daño base normal** y, a continuación, sobreescribir el aura activa por la del nuevo elemento, reiniciando la ventana a cinco (5) segundos sin detonar ninguna reacción.
* **RF-03.4 [Dirigido por Eventos]:**  
  CUANDO se detone cualquier reacción arcana, el sistema DEBERÁ **consumir por completo las energías elementales en juego**, dejando al objetivo en **estado neutral puro (sin auras residuales activas)**, listo para iniciar una nueva secuencia limpia.

---

### RF-04: Cuantificación del Combo, Multiplicadores y Efectos Tácticos
* **RF-04.1 [Ubicuo]:**  
  CUANDO se detone una reacción elemental válida, el sistema DEBERÁ aplicar una **bonificación del cincuenta por ciento ($+50\%$, factor `comboDamageMultiplier = 1.5`) sobre el daño base del segundo conjuro (el conjuro detonador)**, sin exigir coste de maná adicional al lanzador.
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO se active cada reacción específica, el sistema DEBERÁ aplicar de forma simultánea al daño su respectivo **Efecto Táctico Canónico**:
  * *Vaporización Arcana (`arcaneVaporization`):* $+50\%$ de daño y emisión de niebla abrasadora que reduce la agilidad/precisión del blanco (`softCrowdControl`) durante tres (3) segundos.
  * *Electrocución Fluida (`fluidElectrocution`):* $+50\%$ de daño y un aturdimiento fulgurante de uno coma cinco ($1.5$) segundos (`hardCrowdControl`).
  * *Deflagración en Vórtice (`vortexDeflagration`):* $+50\%$ de daño y transformación automática de la geometría a onda expansiva esférica en área.
  * *Fractura Basáltica (`basalticFracture`):* $+50\%$ de daño y perforación inmediata que **destruye hasta cincuenta (50) puntos de la barrera mágica activa**; SI la barrera posee menos de 50 puntos (ej. 20 PV), se anula por completo y el excedente no se transfiere como daño a la salud.
  * *Ciénaga Petrificante (`petrifyingSwamp`):* Inmovilización estricta por enraizamiento de tres (3) segundos (`softCrowdControl`) y reducción de la velocidad al cincuenta por ciento ($50\%$) durante cuatro (4) segundos posteriores.
  * *Ventisca Helada (`glacialBlizzard`):* Congelación absoluta de dos (2) segundos (`hardCrowdControl`, parálisis motora completa).
  * *Colapso Crepuscular (`twilightCollapse`):* Daño puro perforante amplificado en un $+50\%$ que **ignora íntegramente (100%) cualquier barrera mágica activa** dañando directamente los puntos de salud, mientras que **la barrera mágica permanece intacta** tras el impacto.
  * *Resonancia Arcana Pura (`pureArcaneResonance`):* Incrementa en un veinticinco por ciento ($+25\%$, factor $\times 1.25$) el valor numérico del efecto latente y suma un (+1) segundo de duración a los controles de masas.

---

### RF-05: Encadenamiento Secuencial y Salvaguarda Anti-Inmovilización (*Anti-Stunlock*)
* **RF-05.1 [Ubicuo]:**  
  El sistema DEBERÁ permitir el **encadenamiento continuado de conjuros**: tras quedar el blanco en estado neutral tras una reacción, el siguiente conjuro puede volver a aplicar su propia aura para iniciar otro combo.
* **RF-05.2 [Ubicuo]:**  
  Para neutralizar bucles degenerados de inmovilización perpetua, el sistema DEBERÁ activar una **Inmunidad Rúnica a Parálisis (`stunlockImmunityActive`) durante tres (3) segundos** inmediatamente después de expirar cualquier efecto de control de masas duro (`hardCrowdControl`: aturdimiento de *Electrocución* o congelación de *Ventisca*).
* **RF-05.3 [Estado]:**  
  MIENTRAS un objetivo goce de la Inmunidad Rúnica a Parálisis activa:
  * SI recibe una nueva detonación de control duro (`hardCrowdControl`), el sistema DEBERÁ **aplicar íntegramente la bonificación del $+50\%$ de daño**, pero sustituirá el aturdimiento o congelación por una onda de choque visual sin interrumpir la movilidad del blanco.
  * Los efectos de control blando (`softCrowdControl`: enraizamiento/lentitud de *Ciénaga* o niebla de *Vaporización*) **sí podrán aplicarse normalmente**, permitiendo mermar la movilidad sin incurrir en parálisis total.
* **RF-05.4 [Dirigido por Eventos]:**  
  CUANDO dos o más conjuros colisionen casi simultáneamente en ráfaga rápida ($< 100\text{ ms}$), el sistema DEBERÁ procesar los impactos de forma **secuencial determinista mediante cola FIFO**, evitando condiciones de carrera y resolviendo el primer impacto como imbuición y el segundo como detonación.

---

### RF-06: Manifestación en el Simulador, Textos Monumentales y Bitácora
* **RF-06.1 [Dirigido por Eventos]:**  
  CUANDO se detone una reacción elemental en la Cámara de Conjuración (SPEC-05), el sistema DEBERÁ fusionar las partículas de ambos elementos en el lienzo Canvas creando una deflagración visual distintiva y única para cada una de las ocho reacciones.
* **RF-06.2 [Dirigido por Eventos]:**  
  CUANDO se produzca la detonación, el sistema DEBERÁ hacer brotar un **Texto Flotante Monumental** en tipografía ceremonial y color oro rúnico sobre el blanco, exhibiendo el nombre solemne de la reacción en noble castellano y el balance de daño amplificado (ej. *«¡VAPORIZACIÓN ARCANA! -68 PV»*).
* **RF-06.3 [Ubicuo]:**  
  El sistema DEBERÁ inscribir en la **Bitácora de Pruebas** del simulador la etiqueta distintiva de la reacción ejecutada (ej. `[Combo: Electrocución Fluida]`), los elementos intervinientes y el daño total asestado.
* **RF-06.4 [Ubicuo]:**  
  El sistema DEBERÁ emitir un anuncio en la región viva accesible (`aria-live="polite"`) describiendo el suceso (ej. *«Reacción desatada: Deflagración en Vórtice inflige 75 puntos de daño en área al maniquí»*).

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Determinismo Absoluto de las Reacciones):**  
  La resolución de las combinaciones elementales, los multiplicadores cuantitativos y los efectos secundarios será 100% determinista, ciega e invariable en el servidor, sin coeficientes de probabilidad, golpes críticos aleatorios ni tiradas de azar (Artículo II de la Constitución).
* **RNF-02 (Inmediatez de la Detonación):**  
  La detección de la coincidencia elemental y el desencadenamiento de la reacción en el simulador se resolverán con una latencia imperceptible inferior a cincuenta milisegundos ($< 50\text{ ms}$).
* **RNF-03 (Diferenciación Sensorial y Accesibilidad):**  
  Cada elemento y reacción deberá ser distinguible no solo por su color, sino por la geometría de su glifo y la forma de sus partículas, garantizando legibilidad para personas con daltonismo o baja visión (WCAG 2.1 AA).
* **RNF-04 (El Velo Arcano y la Soberanía Lingüística):**  
  Todos los nombres de combos, auras, descripciones de resonancia y mensajes de estado deberán formularse con solemnidad mitológica exclusivamente en noble castellano (Artículos IV y V de la Constitución).
* **RNF-05 (Dogma Vanilla):**  
  El motor de cálculo de combos, la gestión de auras temporales y la visualización de partículas combinadas se implementarán íntegramente mediante tecnologías web estándar sin recurrir a dependencias externas (Artículo I de la Constitución).

---

## 6. Casos Límite y Situaciones Excepcionales

1. **Impacto de Conjuros Defensivos o de Curación Pura:**  
   Si un conjuro no inflige daño pero posee una afinidad elemental (ej. una barrera de Agua o una curación de Luz), su impacto sobre el blanco imbuye el aura correspondiente con normalidad. Si actúa como detonador de una reacción (ej. aplicar una barrera de Agua sobre un blanco imbuido con Fuego), la reacción de *Vaporización* se desencadena, produciendo el efecto de niebla cegadora y calculando la bonificación de daño únicamente si el segundo conjuro poseía algún valor ofensivo base.
2. **Impacto en el Milisegundo Exacto de Expiración:**  
   Si un segundo conjuro colisiona exactamente en el umbral límite de los cinco (5) segundos del aura, el sistema resolverá a favor de la pericia del practicante, consumiendo el aura y desatando la reacción mágica.
3. **Muerte o Regeneración del Maniquí Durante una Reacción:**  
   Si el daño amplificado del combo reduce la salud del maniquí a cero (0 PV), el maniquí se disuelve en paja mágica desatando la animación del combo de forma completa, limpiando cualquier aura residual y reiniciando su regeneración a los 2 segundos conforme a la SPEC-05.
4. **Disipación Manual Inmediata:**  
   Si el usuario pulsa el botón «Restaurar Maniquí» en el simulador mientras existe un aura activa o inmunidad rúnica, el sistema purga instantáneamente toda imbuición, devolviendo el blanco a su estado neutral intacto.
5. **Preferencia de Movimiento Reducido (`prefers-reduced-motion`):**  
   Ante la activación de movimiento reducido, las deflagraciones masivas de combo se sustituyen por una corona de luz estática sobre el blanco y la proyección nítida del texto flotante monumental.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Reacciones Ternarias o Cuaternarias:**  
   Quedan formalmente excluidas las cadenas de tres o más elementos simultáneos ($A + B + C$); la alquimia del santuario se limita rigurosamente a interacciones binarias por pares ($A + B$).
2. **Simulación de Terrenos Físicos y Fluidos Persistentes:**  
   El sistema no simulará superficies mojadas en el suelo, incendios de hierba ni condensación ambiental en el escenario; las reacciones operan estrictamente entre el conjuro y el objetivo.
3. **Propagación a Múltiples Blancos con Inteligencia Artificial:**  
   No se implementarán ejércitos de enemigos ni IA reactiva; la evaluación práctica se realiza de forma pura sobre el maniquí de entrenamiento de SPEC-05.
4. **Bonificaciones y Dominio de Linajes de Clanes:**  
   Las afinidades raciales, modificadores de daño por linaje de clan y las puntuaciones semanales por afinidad quedan **estrictamente fuera de alcance**, siendo el objeto soberano de **SPEC-07 (Sistema de Clanes, Linajes y Dominio Semanal)**.

---

## 8. Criterios de Finalización y Aceptación

* [ ] El Códice de Afinidades presenta una Rueda Rúnica octogonal en escritorio y selector con acordeón táctil en móviles con los 8 elementos del santuario.
* [ ] Al seleccionar un elemento en la Rueda, se iluminan sus enlaces compatibles y se expone la ficha de reacción con su nombre litúrgico y efectos.
* [ ] Los conjuros elementales imbuyen un aura visible de cinco (5) segundos de duración sobre el blanco.
* [ ] El halo abraza la silueta del blanco (sin geometría circular ni anillo rúnico), se tiñe del color heráldico del elemento al imbuirse y porta un contador numérico de segundos restantes (RF-02.2 ratificado).
* [ ] La capa-luz del aura es perenne: pulsa en dorado arcano en reposo y regresa al dorado al expirar la ventana (RF-02.2 ratificado).
* [ ] El aura elemental activa y sus 5 segundos se conservan intactos al hojear las páginas del grimorio.
* [ ] Los impactos del mismo elemento reinician el contador de 5 segundos sin detonar reacción.
* [ ] Los impactos de elementos no reactivos aplican su daño base íntegro y sobreescriben el aura con un nuevo contador de 5 s.
* [ ] Se reconocen deterministamente las 7 reacciones duales canónicas y la Resonancia con Arcano Puro (+25% numérico y +1 s a CC).
* [ ] El conjuro detonador recibe una bonificación del $+50\%$ de daño (`comboDamageMultiplier = 1.5`) sin exigir gasto de maná suplementario.
* [ ] Se aplican los efectos tácticos secundarios canónicos (niebla cegadora, aturdimiento 1.5s, vórtice en área, rotura de barrera hasta 50 PV sin excedente a vida, enraizamiento 3s, congelación 2s, y daño puro perforante en Colapso Crepuscular conservando la barrera intacta).
* [ ] Tras la detonación del combo, el blanco queda en estado neutral limpio (sin auras residuales).
* [ ] Se implementa la salvaguarda *Anti-Stunlock*: 3 segundos de inmunidad a parálisis dura (`hardCrowdControl`) tras expirar un control, conservando el daño amplificado y permitiendo controles blandos (`softCrowdControl`).
* [ ] Los impactos en ráfaga rápida ($< 100\text{ ms}$) se resuelven secuencialmente mediante cola FIFO sin colisiones ni carreras.
* [ ] La Cámara de Conjuración proyecta la deflagración visual fusionada de ambos elementos en el lienzo Canvas 2D.
* [ ] Brota el Texto Flotante Monumental en oro rúnico con el nombre de la reacción y la cifra de daño.
* [ ] La Bitácora de Pruebas inscribe la entrada con la etiqueta distintiva del combo ejecutado.
* [ ] Se emiten anuncios de accesibilidad en región viva ARIA al detonar cada combo.
* [ ] Se respeta la directiva `prefers-reduced-motion` adaptando los efectos a destellos estáticos suaves.
* [ ] Se aplican estrictamente los identificadores técnicos canónicos en inglés `camelCase` (`arcaneVaporization`, `fluidElectrocution`, etc.) bajo el Artículo V.
* [ ] Cero dependencias externas y acatamiento riguroso del Dogma Vanilla y el Velo Arcano.

---

## 9. Registro de Resoluciones de Calidad (QA)

* **[RESUELTO — Hallazgo 1] Persistencia entre Páginas:** El aura activa de 5 s se conserva al pasar hojas en el tomo, permitiendo combos entre conjuros de distintas páginas.
* **[RESUELTO — Hallazgo 2] Arcano Puro:** Amplificación del $+25\%$ en magnitudes numéricas (daño, curación, barrera) y adición de $+1$ segundo a duraciones de control de masas.
* **[RESUELTO — Hallazgo 3] Sobreescritura de Auras:** Daño base íntegro infligido por el elemento no reactivo antes de sustituir el aura activa por la nueva con 5 s.
* **[RESUELTO — Hallazgo 4] Excedente de Fractura Basáltica:** Tritura hasta 50 PV de escudo; si el escudo es menor, lo anula sin transferir sobrante a la barra de vida.
* **[RESUELTO — Hallazgo 5] Estado Residual:** Consumo pleno de energías elementales; el blanco queda en estado neutral limpio sin auras tras la reacción.
* **[RESUELTO — Hallazgo 6] Barrera en Colapso Crepuscular:** El daño ignora el 100% de la barrera afectando a la salud; la barrera permanece intacta tras el golpe.
* **[RESUELTO — Hallazgo 7] Delimitación Anti-Stunlock:** Inmunidad de 3 s circunscrita a control duro (`hardCrowdControl`); los controles blandos (`softCrowdControl`) pueden seguir aplicándose.
* **[RESUELTO — Hallazgo 8] Adaptabilidad Móvil:** Rueda Rúnica octogonal en escritorio y Selector Radial con Acordeón Rúnico táctil en pantallas móviles.
* **[RESUELTO — Hallazgo 9] Cola FIFO de Ráfagas:** Resolución determinista en cola secuencial para impactos casi simultáneos ($< 100\text{ ms}$) sin carreras.
* **[RESUELTO — Hallazgo 10] Identificadores Técnicos en Inglés (Art. V):** Ratificados `arcaneVaporization`, `fluidElectrocution`, `vortexDeflagration`, `basalticFracture`, `petrifyingSwamp`, `glacialBlizzard`, `twilightCollapse`, `pureArcaneResonance`, `activeElementalAura`, `stunlockImmunityActive`.
* **[RESUELTO — Hallazgo 11] Aura Contorneada, Perenne y Contador Numérico (RF-02.2, criterio ratificado):** El halo abraza la silueta del blanco: una capa-luz derivada de la propia efigie (imagen clonada con desenfoque y resplandor del color de `--aura-color`) acompaña sombrero, brazos y base del espantapájaros. La capa es **perenne**: en reposo pulsa en dorado arcano (hermana de la respiración ceremonial de la efigie) y, al impactar un conjuro elemental, se tiñe del color heráldico del elemento entrante con transición suave; al expirar la ventana regresa al dorado de reposo sin desaparecer. Toda geometría circular (anillo rúnico SVG) queda prohibida y retirada: la duración la declara exclusivamente el **contador numérico** (5 → 0), actualizado por el bucle de escena, visible solo con ventana viva. Bajo `prefers-reduced-motion` el pulso cesa pero el tinte y el contador permanecen.
* **[RESUELTO — Hallazgo 12] Color Heráldico y Afinidades Ajenas al Códice (RF-02.2, criterio ratificado):** Todo conjuro cuya afinidad pertenece a la matriz canónica de 8 elementos (`fire`, `water`, `lightning`, `earth`, `wind`, `light`, `darkness`, `pureArcane`) imbuye el aura con su color heráldico exacto del Códice. Los valores de afinidad ajenos a esa lista (p. ej. una `'shadow'` legada) no existen en el Códice: el cliente les aplica el **fallback neutro blanco** (`#ffffff`) sin interrumpir el motor de combos, pero la puerta de entrada correcta es la validación de dominio en la creación (SPEC-04, RF-01.6), que los rechaza antes de llegar al grimorio. `'shadow'` NO es canónica: su valor correcto es `'darkness'`.
* **[RESUELTO — Hallazgo 13] Sincronización de la Expiración Natural con el Estado Interno (RF-02.7, criterio ratificado):** La auditoría del ciclo completo detectó que el estado del aura vivía duplicado: el halo visual expiraba a los 5 s y regresaba al dorado de reposo, pero la copia interna de la vista (`activeAuraElement`) conservaba el elemento caducado. Consecuencias: reimbuir el MISMO elemento caía en el Caso B de refresco, invisible sobre un aura ya muerta (el halo ignoraba el evento por su guardia de reposo); un elemento distinto detonaba una reacción fantasma sobre un blanco en reposo. Resolución: la vista purga su copia al oír `combo:aura-expired` (que ahora burbujea por el bus como sus hermanos), y el bucle de escena del halo late en el planificador de cuadros de la vista (`requestAnimationFrame`) en vez de temporizadores propios, unificando el reloj de expiración, vuelos y resoluciones. Cobertura de regresión: la fase 9b de `test_aura_page_persistence.mjs` avanza el reloj más allá de la ventana y aserta la purga del estado y la reimbuición como Caso A.
* **[RESUELTO — Hallazgo 14] Lectura Viva del Aura en la Cola FIFO (RF-02.7, criterio ratificado):** La auditoría de desdoblamiento de estado en los componentes del simulador (stunlock, maniquí, textos flotantes y voz: todos limpios, la vista los consulta por sondeo) destapó un cuarto duplicado con el mismo ADN que el Hallazgo 13: `handleImpact` congelaba `activeAura` en la instantánea del encolado, pero la cola FIFO resuelve un impacto por cuadro. En una ráfaga (RF-05.4, <100 ms) el segundo impacto podía resolverse contra una foto caducada: no veía la imbuición recién aplicada por el primero (fallaba la detonación en cadena) o detonaba sobre un blanco cuyo aura había expirado mientras esperaba en cola. Resolución: `resolveImpact` sella `impact.activeAura` desde el estado vivo en el cuadro de resolución. Cobertura de regresión: la fase 9c de `test_aura_page_persistence.mjs` aserta que un impacto de Rayo tras la imbuición de Agua detona Electrocución Fluida (lectura viva), y la fase 4 de `test_combo_queue.mjs` ya modelaba el patrón correcto con su `targetState` compartido.

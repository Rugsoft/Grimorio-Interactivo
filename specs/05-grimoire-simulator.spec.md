# SPEC-05: Simulador de Grimorio, Partículas de Maná y Recitado Mágico por Voz

> **Constitución:** [`constitution.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/constitution.md) | **Directrices:** [`AGENTS.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/grimorio-interactivo/AGENTS.md)  
> **Estado:** Especificación Ratificada y Blindada tras Auditoría de Calidad (QA)  
> **Área:** Experiencia Inmersiva, Visualización Rúnica y Conjuración Vocal  
> **Restricción:** Definición estricta del QUÉ y el POR QUÉ. Cero detalles de implementación técnica, arquitectura o nombres de archivos.

---

## 1. Contexto y Objetivo

El Grimorio Interactivo no es un mero índice burocrático de datos, sino un artefacto vivo de aprendizaje y contemplación mágica inspirado en las crónicas de *Frieren*, la erudición de *Tolkien* y las tradiciones litúrgicas de *D&D*. Tras concebir y balancear un conjuro en el Taller de Magia (SPEC-04), los magos del santuario precisan un espacio ceremonial donde presenciar la manifestación tangible del maná antes de arriesgar su esencia en expediciones o duelos.

El objetivo de esta especificación es definir la experiencia del **Simulador de Grimorio**: un entorno interactivo presentado bajo la metáfora de un gran tomo arcano encuadernado a doble página. En él, los practicantes de las artes místicas pueden hojear las páginas del saber antiguo, escuchar la declamación solemne de las fórmulas de encantamiento expresadas en noble castellano, invocar hechizos mediante su propia voz o mediante sellos táctiles, y contemplar cómo el maná cobra vida en un lienzo interactivo impactando contra un maniquí de entrenamiento rúnico con total fidelidad a su afinidad elemental, geometría y círculo de poder.

---

## 2. Actores y Usuarios

* **El Aprendiz de Magia (Lector / Visitante):**  
  Explora el «Tomo Canónico» hojeando sus páginas, contemplando las iluminaciones rúnicas de los conjuros validados y escuchando la pronunciación correcta de los encantamientos arcanos para instruirse en la liturgia mágica.
* **El Erudito o Creador de Conjuros (Editor / Autor):**  
  Accede al catálogo público y a su pestaña privada de «Mis Ensayos Arcanos» para probar en un entorno seguro y aislado (sandbox con maná ilimitado) sus propios borradores (`draft`) y conjuros en moderación (`experimental`), evaluando el retroceso, la dispersión visual y la contundencia de sus creaciones antes del juicio de los Maestros.
* **El Maestro de la Torre (Revisor / Moderador):**  
  Examina la verosimilitud de los conjuros presentados, comprobando que la manifestación visual y la dicción del cántico ceremonial correspondan fidedignamente a la grandeza de su Círculo Arcano.
* **El Usuario con Necesidades de Accesibilidad:**  
  Experimenta la simulación adaptada a sus preferencias de movimiento reducido y accede a la descripción sonora o textual de cada impacto rúnico.

---

## 3. Historias de Usuario

* **HU-01 (Lectura Inmersiva del Tomo):**  
  *Como* aprendiz de las artes arcanas,  
  *Quiero* abrir el grimorio y pasar páginas de forma fluida mediante controles direccionales o marcapáginas temáticos,  
  *Para* sumergirme en el estudio de los conjuros como si sostuviera un tomo ancestral de pergamino.

* **HU-02 (Cámara de Conjuración y Banco de Pruebas):**  
  *Como* mago practicante,  
  *Quiero* activar el conjuro seleccionado sobre un maniquí arcano de entrenamiento que conserve su daño residual al pasar de página,  
  *Para* observar su trayectoria, la dispersión del impacto y los números flotantes de daño, curación o barrera sin consumir mi maná real.

* **HU-03 (Declamación Litúrgica del Encantamiento):**  
  *Como* erudito del lenguaje arcano,  
  *Quiero* pulsar el sello de escucha para que el propio tomo recite la fórmula del conjuro en noble castellano con cadencia solemne,  
  *Para* conocer la pronunciación ritual adecuada de las palabras de poder.

* **HU-04 (Conjuración por Voz):**  
  *Como* hechicero inmersivo,  
  *Quiero* pronunciar la palabra de activación o el nombre de un conjuro ante el grimorio,  
  *Para* desatar el poder mágico de forma inmediata mediante mi propia voz.

* **HU-05 (Prueba de Ensayos Propios):**  
  *Como* autor de hechizos consagrado,  
  *Quiero* alternar entre el tomo canónico y mis propios borradores o ensayos experimentales,  
  *Para* poner a prueba mis creaciones en la cámara de conjuración antes de remitirlas a la Orden de Maestros.

* **HU-06 (Sensibilidad y Accesibilidad Universal):**  
  *Como* usuario sensible al movimiento o usuario de tecnologías de asistencia,  
  *Quiero* que el simulador respete mis preferencias de movimiento reducido y me anuncie verbalmente o por texto accesible los resultados del impacto,  
  *Para* disfrutar del santuario sin mareos ni barreras perceptivas.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Estructura del Tomo Arcano, Catálogo y Navegación entre Páginas
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ representar el simulador bajo la metáfora visual y espacial de un **Tomo Arcano abierto a doble página**, donde la página izquierda alberga la iluminación rúnica, los componentes litúrgicos y los metadatos del conjuro, y la página derecha alberga la Cámara de Conjuración con el lienzo interactivo y el maniquí de entrenamiento.
* **RF-01.2 [Ubicuo]:**  
  El sistema DEBERÁ segmentar el catálogo de conjuros según el estado de la sesión:
  * **Tomo Canónico (Público):** Accesible para todos los usuarios y visitantes anónimos, conteniendo exclusivamente los conjuros ratificados y sellados en estado `validated`.
  * **Mis Ensayos Arcanos (Privado):** Accesible exclusivamente para autores autenticados, permitiéndoles conmutar mediante un selector rúnico para visualizar y probar sus propios conjuros en estado `draft` (borrador) y `experimental` (en moderación).
* **RF-01.3 [Dirigido por Eventos]:**  
  CUANDO el usuario accione los controles de paso de página (flechas de navegación, gestos táctiles o teclas direccionales), el sistema DEBERÁ realizar una transición que presente el conjuro anterior o siguiente dentro del catálogo activo.
* **RF-01.4 [Ubicuo]:**  
  El sistema DEBERÁ aplicar **navegación acotada sin bucle infinito**: al alcanzar el primer o último conjuro del catálogo, el control direccional correspondiente se desvanecerá rúnicamente indicando el lomo físico del libro; SI un filtro temático (por Círculo o Afinidad) carece de conjuros inscritos, ENTONCES el sistema exhibirá una página de pergamino virgen con la leyenda ceremonial: *«Aún no se han inscrito conjuros bajo esta afinidad o círculo en el santuario»*.
* **RF-01.5 [Estado]:**  
  MIENTRAS el usuario visualice una página, el sistema DEBERÁ exhibir el nombre del conjuro, su Círculo Arcano, afinidad elemental, tiempo de lanzamiento, coste de maná, fórmula verbal litúrgica en noble castellano y descripción narrativa.

---

### RF-02: Cámara de Conjuración y Espantapájaros de Entrenamiento
* **RF-02.1 [Ubicuo]:**  
  El sistema DEBERÁ disponer en la página derecha de un lienzo interactivo con un **Espantapájaros Herético de Entrenamiento** (criterio ratificado): una efigie ritual de paja y alfileres, coronada por sombrero de brujo y grabada con runas incandescentes, servida como imagen local del propio repositorio (Artículo I: cero peticiones externas), provista de una barra de resistencia ficticia de **500 puntos de salud base (PV)** y un indicador de absorción de barrera mágica. La efigie DEBERÁ fundirse con el lienzo sin caja ni marco: aura incandescente que respira, herida que agosta sus tonos al descender la salud y derrumbe físico al disolverse. El punto de anclaje de los impactos (el pecho de la efigie) y el radio ceremonial de impacto permanecen inmutables.
* **RF-02.2 [Ubicuo]:**  
  El sistema DEBERÁ operar la Cámara de Conjuración bajo la modalidad de **Banco de Pruebas Aislado (Sandbox Ilimitado)**: el usuario podrá invocar conjuros indefinidamente sin consumir maná de una reserva personal, sin tiempos de espera punitivos y sin alterar el estado permanente del santuario.
* **RF-02.3 [Ubicuo]:**  
  El sistema DEBERÁ garantizar la **persistencia del estado del maniquí entre páginas**: cuando el usuario hojee el tomo hacia otro conjuro, el maniquí conservará su salud restante, barreras activas y daño acumulado, permitiendo evaluar secuencias de conjuros sobre el mismo blanco herido.
* **RF-02.4 [Dirigido por Eventos]:**  
  CUANDO un conjuro impacte contra el maniquí, el sistema DEBERÁ actualizar su estado conforme a las siguientes reglas cuantitativas:
  * **Daño:** Deduce los puntos del escudo de barrera activo en primer término; cualquier excedente reduce la barra de salud base.
  * **Curación:** Incrementa los PV de salud hasta un **techo máximo inmutable de 500 PV** (sin sobrecuración); si el maniquí ya goza de 500 PV, el impacto muestra la leyenda flotante `[Salud Plena]` sin alterar el marcador.
  * **Barrera:** Aplica un escudo de absorción temporal bajo la **regla de renovación por mayor valor** (la barrera más potente reemplaza a la de menor cuantía, sin apilamiento acumulativo infinito).
* **RF-02.5 [Dirigido por Eventos]:**  
  CUANDO la salud del maniquí llegue a cero (0 PV), el sistema DEBERÁ **derrumbar la efigie** —el espantapájaros se desploma sobre su base y se desvanece— y **regenerar automáticamente el espantapájaros intacto, erguido de nuevo a sus 500 PV originales tras dos (2) segundos**.
* **RF-02.6 [Dirigido por Eventos]:**  
  CUANDO el usuario pulse el botón ceremonial de **«Restaurar Maniquí»**, el sistema DEBERÁ restablecer al instante los 500 PV de salud, disipar todas las barreras y estados alterados, y limpiar la bitácora de impactos de la sesión.

---

### RF-03: Sistema de Partículas de Maná y Manifestación Elemental
* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ modular el aspecto visual, la paleta cromática y la física cinemática de las partículas en estricta concordancia con la **Afinidad Elemental** del conjuro:
  * **Fuego:** Ascuas incandescentes, vórtice ígneo, humo ascendente y chispas crepitantes (tonos bermellón y naranja fuego).
  * **Agua / Escarcha:** Ondas fluidas concéntricas, gotas de rocío astral y esquirlas gélidas cristalinas (tonos zafiro y cian gélido).
  * **Rayo:** Descargas eléctricas fractales instantáneas y chispas de alta aceleración (tonos violeta eléctrico y blanco puro).
  * **Tierra:** Fragmentos de roca basáltica, temblores de impacto y polvo denso ocre (tonos ámbar y tierra antigua).
  * **Viento:** Ráfagas helicoidales, estelas de vacío translúcidas y briznas de energía esmeralda (tonos aguamarina y verde viento).
  * **Luz:** Haces sacros prismáticos, halos radiantes y polvo de estrellas brillante (tonos oro regio y blanco marfil).
  * **Oscuridad:** Zarcillos de niebla umbría, vórtices de absorción y runas violáceas crepitantes (tonos púrpura abisal y carbón).
  * **Arcano Puro:** Constelaciones geométricas flotantes y esferas de resonancia de maná puro (tonos añil y azul astral).
* **RF-03.2 [Ubicuo]:**  
  El sistema DEBERÁ regir la trayectoria cinemática de las partículas según el **Modificador de Geometría** del conjuro:
  * **Objetivo Único / Contacto:** Proyectil concentrado que se desplaza en trayectoria directa o parábola desde el origen hacia el corazón del maniquí.
  * **Cono:** Ráfaga en abanico angular expansivo que cubre el cuadrante del objetivo.
  * **Línea:** Haz continuo y perforante que cruza transversalmente el lienzo atravesando al maniquí.
  * **Esfera:** Detonación radial omnidireccional expansiva centrada en el blanco.
* **RF-03.3 [Ubicuo]:**  
  El sistema DEBERÁ escalar la densidad, el brillo, la escala y la onda expansiva de las partículas en proporción directa al **Círculo Arcano (I al V)** del conjuro:
  * Los conjuros de *Círculo I* exhibirán emanaciones sutiles y contenidas.
  * Los conjuros de *Círculo V* desencadenarán saturación lumínica plena, temblor sutil de la página y densas emanaciones de maná.
* **RF-03.4 [Ubicuo]:**  
  El sistema DEBERÁ limitar la población máxima a **doscientas (200) partículas activas simultáneas**; SI una nueva invocación ingresa partículas superando dicho cupo, ENTONCES el sistema DEBERÁ aplicar una política de **reciclado circular (FIFO)**, forzando la extinción inmediata de las partículas más antiguas en un cuadro para dar paso al nuevo impacto sin degradar la memoria ni provocar caídas de tasa de cuadros.

---

### RF-04: Recitado Mágico por Voz (Declamación e Invocación en Noble Castellano)
* **RF-04.1 [Ubicuo]:**  
  El sistema DEBERÁ componer y exhibir todas las fórmulas ceremoniales de cántico y palabras de poder **íntegramente en noble castellano rúnico** (ej. *«¡Llamas del alba, descended y consumid la penumbra!»*), proscribiendo el uso de latín forzado para garantizar la máxima naturalidad fonética tanto en la síntesis como en el reconocimiento vocal nativo del navegador bajo el **Artículo V de la Constitución**.
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO el usuario pulse el sello de **«Escuchar Cántico»**, el sistema DEBERÁ declamar en voz alta la fórmula litúrgica del encantamiento en dialecto castellano (`es-ES`) con entonación pausada, solemne y ceremonial.
* **RF-04.3 [Dirigido por Eventos]:**  
  CUANDO el usuario active el sello del **«Micrófono de Conjuración»** y elocute una orden vocal, el sistema DEBERÁ desencadenar la invocación automática sobre el maniquí SI la transcripción contiene **el nombre canónico del conjuro, sus palabras clave esenciales o la fórmula ceremonial asignada** (tolerancia fonética flexible ante frases extensas).
* **RF-04.4 [No Deseado / Excepción]:**  
  SI el navegador o dispositivo carece de soporte para síntesis o reconocimiento vocal, o SI el usuario deniega los permisos de captura de audio, ENTONCES el sistema DEBERÁ deshabilitar elegantemente los sellos vocales mostrando un icono ceremonial de reposo sin emitir alertas intrusivas y permitiendo el lanzamiento manual sin impedimentos (degradación grácil).
* **RF-04.5 [Ubicuo]:**  
  El sistema DEBERÁ mantener siempre habilitado el sello de **«Invocar Conjuro»** mediante interacción táctil o clic de ratón, garantizando que el uso de la voz sea un aditamento inmersivo y jamás una barrera de uso.

---

### RF-05: Retroalimentación de Impacto, Textos Flotantes Escalonados y Bitácora
* **RF-05.1 [Dirigido por Eventos]:**  
  CUANDO el flujo de partículas impacte contra el maniquí, el sistema DEBERÁ provocar una reacción física en el modelo:
  * Sacudida física o retroceso proporcional a la magnitud del daño recibido.
  * Cúpula luminosa envolvente ante efectos de barrera o curación.
  * Ataduras de hielo, enredaderas rúnicas o halo de lentitud ante efectos de control de masas, disipándose suavemente tras **cuatro (4) segundos** de vigencia activa (o de inmediato al pulsar «Restaurar Maniquí»).
* **RF-05.2 [Dirigido por Eventos]:**  
  CUANDO se produzca el impacto de un conjuro con efectos mixtos o simples, el sistema DEBERÁ proyectar **Textos Flotantes Arcanos con Desfase Espacial y Temporal** para preservar la legibilidad:
  * **Cifra de Daño (Carmesí encendido):** Brota en el torso central del maniquí (ej. `-45 PV`).
  * **Cifra de Curación o Barrera (Esmeralda / Azul zafiro):** Brota con un desplazamiento lateral (+30 px) y elevación escalonada (ej. `+30 PV` o `[+25 Barrera]`).
  * **Rótulo de Control de Masas (Oro rúnico):** Brota en la cúspide de la cabeza del maniquí con un retardo de 150 ms (ej. `¡Aturdido!`, `¡Enraizado!`).
* **RF-05.3 [Ubicuo]:**  
  El sistema DEBERÁ disponer de un panel de **Bitácora de Pruebas** que registre los últimos cinco (5) impactos (marca temporal, nombre del conjuro, desglose de efectos y coste de maná consumido), **preservando dicho historial en el almacenamiento local del navegador (`localStorage`)** para su consulta entre sesiones de prueba.
* **RF-05.4 [Dirigido por Eventos]:**  
  CUANDO un conjuro con proyectiles vuele hacia el maniquí, el sistema DEBERÁ despachar el impacto en el instante exacto en que las partículas alcancen el **radio de impacto ceremonial de veinticuatro (24) píxeles** alrededor del corazón del maniquí, de modo que la reacción física, el descuento de PV y los textos flotantes coincidan visualmente con el contacto real de la estela arcana (RF-02.4 y RF-05.2).
  * La estimación del tiempo de vuelo DEBERÁ operar únicamente como **respaldo de garantía**: SI el bucle de renderizado se interrumpe (suspensión por visibilidad, regulación de rendimiento u otra causa), ENTONCES el impacto se despachará igualmente al reanudarse una vez vencido dicho plazo.
  * Cada invocación DEBERÁ producir exactamente un (1) impacto: NI la detección por proximidad NI el respaldo temporal podrán duplicar el golpe sobre el maniquí.
* **RF-05.5 [No Deseado / Excepción]:**  
  SI la invocación de un conjuro falla por una causa imprevista del motor de manifestación, ENTONCES el sistema DEBERÁ anunciar el fallo con solemnidad por la región viva accesible (RF-06.4) sin propagar excepciones silenciosas, dejando el banco de pruebas operativo para un nuevo intento (degradación grácil).

---

### RF-06: Accesibilidad Sensorial, Rendimiento y Adaptabilidad
* **RF-06.1 [Ubicuo]:**  
  SI el usuario tiene activada la preferencia de **Movimiento Reducido (`prefers-reduced-motion`)** en su sistema operativo, ENTONCES el sistema DEBERÁ suprimir las sacudidas de pantalla, anular las trayectorias cinemáticas violentas y representar el impacto mediante una pulsación lumínica estática y la aparición directa del texto flotante.
* **RF-06.2 [Ubicuo]:**  
  El sistema DEBERÁ monitorizar de forma continua la tasa de cuadros por segundo de la animación; SI el rendimiento cae de forma sostenida por debajo de treinta (30) cuadros por segundo, ENTONCES el sistema DEBERÁ activar automáticamente el modo de bajo consumo, reduciendo a la mitad la densidad de partículas para mantener la respuesta táctil.
* **RF-06.3 [Dirigido por Eventos]:**  
  CUANDO la pestaña del navegador pase a segundo plano o se oculte (`visibilitychange`), el sistema DEBERÁ suspender inmediatamente el bucle de renderizado de partículas y detener cualquier locución de síntesis vocal, reanudándolos solo cuando recupere la visibilidad activa.
* **RF-06.4 [Ubicuo]:**  
  El sistema DEBERÁ emitir anuncios accesibles en una región viva (`aria-live="polite"`) cada vez que un conjuro sea ejecutado (ej. *«Lanzado Rayo Solar: inflige 60 puntos de daño al maniquí de pruebas»*).

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Fluidez Gráfica y 60 FPS):**  
  La simulación de partículas en el lienzo deberá operar a sesenta cuadros por segundo ($60\text{ FPS}$) estables en condiciones estándar de hardware mediante reciclado circular FIFO, evitando picos de recolección de basura (*Garbage Collection*).
* **RNF-02 (Latencia de la Invocación):**  
  El lapso entre la orden de lanzamiento (clic manual o reconocimiento vocal) y la primera emisión cinemática en el lienzo no superará los cien milisegundos ($< 100\text{ ms}$).
* **RNF-03 (Cumplimiento de Accesibilidad WCAG 2.1 AA):**  
  Textos flotantes con ratio de contraste legible ($\ge 4.5:1$), navegación completa mediante teclado (flechas de página, activación por barra espaciadora), soporte ARIA y acatamiento de `prefers-reduced-motion`.
* **RNF-04 (El Velo Arcano y la Soberanía Lingüística):**  
  Toda la terminología, interfaz, bitácora de pruebas y locuciones de voz deberán formularse rigurosamente en noble castellano solemne, proscribiendo tecnicismos expuestos o anglicismos (Artículos IV y V de la Constitución).
* **RNF-05 (Soberanía del Dogma Vanilla):**  
  Toda la simulación de partículas, física cinemática, síntesis vocal y reconocimiento por micrófono deberá implementarse exclusivamente mediante capacidades nativas estándar del navegador web, sin recurrir a motores gráficos externos, CDNs ni librerías de terceros (Artículo I de la Constitución).

---

## 6. Casos Límite y Situaciones Excepcionales

1. **Ráfagas de Lanzamientos Rápidos (Spam de Invocación):**  
   Si el usuario pulsa repetidamente el botón de lanzamiento, el sistema absorbe la cadencia reutilizando el cupo de 200 partículas mediante reemplazo FIFO y acumula el daño sobre el maniquí sin desbordar la memoria ni colapsar la interfaz.
2. **Denegación de Permisos de Audio o Falta de Micrófono:**  
   Si el usuario deniega el permiso o el dispositivo carece de hardware, el sello de micrófono exhibe un glifo atenuado de descanso con un tooltip ceremonial (*«El oráculo del sonido reposa en silencio»*), manteniendo el botón táctil 100% operativo.
3. **Pronunciación Fonéticamente Dudosa:**  
   Si la orden vocal no coincide con el conjuro activo ni con sus palabras clave, el maniquí permanece inalterado y se emite una sutil bruma de disipación con la leyenda: *«La resonancia de la palabra de poder se ha disipado en el éter»*.
4. **Conjuros con Daño Cero o Defensivos Puros:**  
   Un conjuro de pura barrera o curación no provoca estremecimiento ni retroceso físico en el maniquí; genera un fulgor de energía y la aparición del texto flotante de absorción o salud.
5. **Pantallas Reducidas y Dispositivos Móviles:**  
   En dispositivos móviles donde no quepan simultáneamente ambas páginas del tomo, la interfaz alternará mediante un pliegue mágico entre la lámina de lectura (texto del conjuro) y la cámara de conjuración (lienzo y maniquí), conservando el botón de lanzamiento fijado en la barra inferior.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

1. **Matriz de Reacciones y Encadenamiento de Combos Elementales:**  
   La interacción química o mágica entre elementos sucesivos (ej. aplicar Agua y detonar con Rayo para daño electrocutante adicional) queda **estrictamente fuera de alcance**, siendo el objeto reservado para **SPEC-06 (Matriz de Afinidades Elementales y Encadenamiento de Combos)**.
2. **Consumo de Maná Real o Sistema de Energía del Usuario:**  
   El simulador no consume maná real del personaje ni implementa barras de energía finitas; funciona enteramente como un banco de pruebas de acceso ilimitado.
3. **Combate Contra Criaturas con Inteligencia Artificial:**  
   El maniquí es un objetivo inerte de madera y paja; no contraataca, no posee turnos defensivos ni IA hostil.
4. **Carga de Archivos de Audio Externos Pesados:**  
   Queda excluida la reproducción de pistas de audio pregrabadas (.mp3 / .wav) de librerías comerciales; el audio se genera exclusivamente mediante la síntesis vocal nativa y modulaciones armónicas del navegador.

---

## 8. Criterios de Finalización y Aceptación

* [ ] El Tomo Arcano se visualiza a doble página con iluminación rúnica a la izquierda y Cámara de Conjuración a la derecha.
* [ ] La navegación permite hojear páginas de forma acotada (sin bucles infinitos) y exhibe pergamino virgen ante filtros vacíos.
* [ ] Los visitantes acceden al Tomo Canónico (validados) y los autores autenticados pueden conmutar a «Mis Ensayos Arcanos» (drafts y experimentales).
* [ ] El maniquí rúnico dispone de 500 PV de salud base y barra de absorción de barrera mágica.
* [ ] El blanco de entrenamiento es el Espantapájaros Herético (RF-02.1): efigie ritual servida como imagen local, sin caja, con aura incandescente, herida que agosta sus tonos al descender la salud y derrumbe con regeneración erguida (RF-02.5).
* [ ] El estado de salud y daño del maniquí se conserva al pasar de página entre distintos conjuros.
* [ ] La curación está acotada a 500 PV (`[Salud Plena]`) y las barreras se renuevan por el valor más alto (sin apilamiento infinito).
* [ ] Al llegar a 0 PV, el maniquí se disuelve y regenera automáticamente a 500 PV en dos (2) segundos.
* [ ] El botón «Restaurar Maniquí» resetea la salud a 500 PV, disipa barreras y limpia la bitácora.
* [ ] El motor de partículas modula fielmente las 8 Afinidades Elementales, 4 geometrías y los 5 Círculos Arcanos.
* [ ] El techo de 200 partículas simultáneas se administra mediante una cola circular FIFO sin fugas de memoria.
* [ ] El impacto se despacha exactamente cuando las partículas cruzan el radio ceremonial del maniquí (24 px), una única vez por invocación, con respaldo temporal que garantiza el golpe ante interrupciones del renderizado.
* [ ] Un fallo imprevisto de la invocación se anuncia por la región viva sin excepciones silenciosas y el banco de pruebas queda operativo.
* [ ] El sello «Escuchar Cántico» declama la fórmula ceremonial íntegramente en noble castellano (`es-ES`).
* [ ] El sello «Micrófono» reconoce el nombre o palabras clave del conjuro con tolerancia fonética y degradación grácil ante falta de soporte.
* [ ] Los textos flotantes ante efectos mixtos brotan con escalonamiento espacial y temporal para evitar superposiciones ilegibles.
* [ ] Los efectos de control de masas se disipan suavemente a los cuatro (4) segundos.
* [ ] La Bitácora de Pruebas retiene los últimos 5 impactos en el almacenamiento local del navegador (`localStorage`).
* [ ] Se respeta `prefers-reduced-motion` anulando sacudidas de pantalla y atenuando partículas.
* [ ] El renderizado de partículas y la voz se suspenden inmediatamente al pasar a segundo plano (`visibilitychange`).
* [ ] Cero dependencias externas y estricta fidelidad al Dogma Vanilla y al Velo Arcano.

---

## 9. Registro de Resoluciones de Calidad (QA)

* **[RESUELTO — Hallazgos 1 y 4] Catálogo y Navegación:** Tomo Canónico público para validados; pestaña privada «Mis Ensayos Arcanos» para autores autenticados; navegación acotada con tope de tomo y pergamino virgen ante filtros vacíos.
* **[RESUELTO — Hallazgo 2] Tolerancia Fonética:** Activación vocal flexible por coincidencia del nombre del conjuro, palabras clave esenciales o fórmula litúrgica asignada.
* **[RESUELTO — Hallazgo 3] Persistencia de Bitácora:** Retención de los últimos 5 impactos en el almacenamiento local (`localStorage`) para consultas comparativas entre sesiones.
* **[RESUELTO — Hallazgo 5] Curación y Barreras:** Techo de salud inmutable en 500 PV (`[Salud Plena]`) y absorción de barreras bajo renovación por mayor valor dominante sin apilamiento infinito.
* **[RESUELTO — Hallazgo 6] Escalonamiento Visual de Efectos Mixtos:** Distribución diferenciada de textos flotantes (daño en torso, barrera/curación desplazada lateralmente, rótulo de CC en corona superior con retardo de 150 ms).
* **[RESUELTO — Hallazgo 7] Persistencia del Maniquí entre Páginas:** El blanco conserva su daño y escudo al hojear el libro para permitir pruebas secuenciales, reseteable solo con «Restaurar Maniquí» o regeneración a 0 PV.
* **[RESUELTO — Hallazgo 8] Duración de Control de Masas:** Permanencia activa en el maniquí durante cuatro (4) segundos con disipación suave.
* **[RESUELTO — Hallazgo 9] Reciclado FIFO de Partículas:** Tope de 200 partículas gestionado por reciclado en cola circular, garantizando 60 FPS estables sin picos de *Garbage Collection*.
* **[RESUELTO — Hallazgo 10] Soberanía Lingüística en la Voz:** Fórmulas sagradas y dicción íntegramente en noble castellano (`es-ES`), garantizando naturalidad fonética, dicción solemne y cumplimiento estricto del Artículo V.
* **[RESUELTO — Hallazgo 11] Sincronía Física del Impacto y Blindaje de la Invocación (RF-05.4 / RF-05.5):** El despacho del impacto se formaliza por **detección de proximidad** al radio ceremonial de 24 px alrededor del corazón del maniquí, con el tiempo de vuelo estimado relegado a respaldo de garantía ante interrupciones del renderizado; los proyectiles dirigidos vuelan balísticamente puros hasta el cruce del blanco, las afinidades elementales fuera del canon se normalizan (o degradan a maná neutro) sin interrumpir la manifestación, y todo fallo imprevisto de la invocación se anuncia con solemnidad sin promesas silenciosas. Verificado por los arneses del lienzo arcánico (proximidad y respaldo), la vista del simulador y los perfiles elementales del motor.
* **[RESUELTO — Hallazgo 12] Efigie del Espantapájaros Herético (RF-02.1 / RF-02.5, criterio ratificado):** El armazón CSS de divs del Maniquí Arcano se sustituye por la efigie ritual de la imagen local `public/assets/img/heretic-scarecrow.png` (PNG con transparencia servido del propio repositorio, Artículo I). La efigie se funde con el lienzo sin caja: aura incandescente ceremonial que respira, bandas de herida que agostan sus tonos por tramos de PV, derrumbe físico sobre su base al llegar a 0 PV y regeneración erguida a los 2 s. El punto de anclaje de impactos (pecho) y el radio ceremonial de 24 px permanecen inmutables: ni el motor de partículas ni la máquina de estados cambian.

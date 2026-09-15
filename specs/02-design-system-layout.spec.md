# SPEC-02: Sistema de Diseño Místico y Maquetación Base

> **Estado:** Aprobada y Blindada tras Revisión QA  
> **Prioridad:** Fundamental (Identidad Visual, Ergonomía de Lectura y Maquetación)  
> **Enfoque:** QUÉ y POR QUÉ (Requisitos Funcionales y Criterios de Aceptación EARS)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
El **Grimorio Interactivo** requiere una presencia visual solemne, evocadora e inmersiva, inspirada en la alta fantasía clásica y contemporánea (*Frieren: Beyond Journey's End*, *Dungeons & Dragons* y las obras de *J.R.R. Tolkien*). En cumplimiento con el **Artículo IV (El Velo Arcano)** y el **Artículo I (El Dogma Vanilla)** de la Constitución, el diseño debe ser majestuoso y sensorialmente rico sin depender de servicios remotos ni comprometer la ergonomía visual durante largas jornadas de estudio arcano.

### 1.2 Objetivo
Establecer las directrices de diseño visual, la jerarquía tipográfica noble, la semántica cromática elemental, la retroalimentación de carga con «Pergaminos Espectrales» y el comportamiento adaptable del contenedor «El Tomo Central». El propósito es ofrecer una experiencia de lectura fluida, accesible y estética tanto en dispositivos móviles compactos como en monitores panorámicos y soportes monocromáticos.

---

## 2. Usuarios y Arquetipos

* **Lector / Aprendiz de Magia:**  
  Persona que explora el compendio y lee descripciones de conjuros. Requiere una lectura cómoda, jerarquías tipográficas que no cansen la vista y una diferenciación cromática y rúnica inmediata de los elementos para entender sus afinidades.
* **Iniciado / Editor Arcano:**  
  Usuario que interactúa con tarjetas, botones, selectores rúnicos y fichas técnicas. Requiere áreas táctiles generosas y estados visuales claros (reposo, realce místico y bloqueo arcano).

---

## 3. Historias de Usuario

* **HU-01 (Inmersión en el Grimorio Ancestral):**  
  *Como* visitante que accede al santuario,  
  *quiero* contemplar una estética de fantasía oscura con texturas de pergamino y una respiración rúnica continua,  
  *para* sentir la solemnidad de un grimorio ancestral viviente.

* **HU-02 (Lectura y Jerarquía sin Fatiga):**  
  *Como* aprendiz que estudia conjuros extensos,  
  *quiero* encabezados arcanos solemnes combinados con cuerpos de texto limpios y cifras de maná de estilo clásico,  
  *para* disfrutar de la estética mágica sin sufrir fatiga visual al leer componentes y efectos.

* **HU-03 (Identificación Rápida de Afinidades y Escuelas):**  
  *Como* hechicero en consulta,  
  *quiero* que el color de la tarjeta represente la afinidad elemental del conjuro y su insignia muestre el sello de la escuela mágica,  
  *para* reconocer instantáneamente su naturaleza elemental y disciplina académica sin confusiones.

* **HU-04 (Espera Mística sin Saltos de Pantalla):**  
  *Como* usuario en conexiones de latencia variable,  
  *quiero* visualizar siluetas espectrales con pulso rúnico suave que ocupen el espacio exacto del contenido,  
  *para* saber que el santuario responde y evitar saltos de diseño cuando los datos se materialicen.

* **HU-05 (Confort Ergonómico en «El Tomo Central»):**  
  *Como* lector en escritorio o dispositivo móvil,  
  *quiero* que el contenido se enmarque en un tomo centrado y proporcionado (o en columna táctil en móviles),  
  *para* sostener una línea de lectura natural con áreas de toque confortables.

* **HU-06 (Exportación y Lectura Monocromática):**  
  *Como* erudito que imprime una ficha técnica o lee en dispositivos de tinta electrónica (e-ink),  
  *quiero* un contraste nítido con glifos rúnicos distintivos y fondo claro de bajo consumo de tinta,  
  *para* conservar la legibilidad en medios físicos o pantallas sin color.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Atmósfera Visual de Fantasía Oscura y Respiración Arcana
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ articular su identidad visual sobre un tema de fantasía oscura compuesto por fondos profundos de carbón y obsidiana arcana, superficies texturizadas de pergamino oscuro envejecido y acentos en oro bruñido.
* **RF-01.2 [Ubicuo]:**  
  El sistema DEBERÁ mantener activos de manera continua los efectos rúnicos y resplandores mediante un ciclo ceremonial de «Respiración Arcana» de bajo consumo computacional con una duración de entre tres (3) y cuatro (4) segundos por ciclo, ejecutado a 60 fotogramas por segundo.
* **RF-01.3 [Ubicuo]:**  
  El sistema DEBERÁ garantizar un contraste visual mínimo de 4.5:1 entre el texto y el fondo en cualquier punto de la interfaz, fundiendo las texturas de pergamino sobre colores base sólidos calibrados.

### RF-02: Jerarquía Tipográfica y Cifras Clásicas de Maná
* **RF-02.1 [Ubicuo]:**  
  El sistema DEBERÁ aplicar tipografía de fantasía solemne (estilo serif arcano) exclusivamente a los títulos principales, nombres de hechizos, sellos y nombres de linajes.
* **RF-02.2 [Ubicuo]:**  
  El sistema DEBERÁ aplicar tipografía sobria, limpia y de alta legibilidad a las descripciones detalladas, componentes, textos auxiliares y valores numéricos de maná (utilizando cifras de estilo clásico que armonicen con el texto sin parecer números ofimáticos modernos).
* **RF-02.3 [Ubicuo]:**  
  El sistema DEBERÁ suministrar todas las fuentes tipográficas desde el almacenamiento local del repositorio sin realizar solicitudes a proveedores o CDNs externas, en estricto cumplimiento del Artículo I de la Constitución.
* **RF-02.4 [Estado]:**  
  MIENTRAS el nombre de un conjuro posea una extensión considerable, el sistema DEBERÁ escalar su tamaño tipográfico de forma fluida permitiendo un máximo de dos (2) líneas sin desbordar la tarjeta.

### RF-03: Semántica Cromática Elemental y Sellos de Escuela
* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ determinar el color de acento, borde y fulgor místico de las tarjetas en función de la **Afinidad Elemental** del conjuro:
  * **Fuego:** Ámbar e ígneo cálido.
  * **Agua:** Azul abisal profundo.
  * **Rayo:** Violeta y cian eléctrico.
  * **Tierra:** Ocre arcilloso y verde musgo.
  * **Viento:** Jade pálido etéreo.
  * **Luz:** Oro solar radiante.
  * **Oscuridad:** Púrpura sombrío y ceniza.
  * **Arcano Puro / Neutro:** Dorado ancestral de grimorio.
* **RF-03.2 [Ubicuo]:**  
  El sistema DEBERÁ representar la **Escuela de Magia** (disciplina académica: Evocación, Abjuración, Nigromancia, Ilusión, Transmutación, Encantamiento, Adivinación, Conjuración) mediante un glifo rúnico propio y su nombre explícito en castellano en la insignia de la tarjeta.
* **RF-03.3 [Ubicuo]:**  
  El sistema DEBERÁ incluir en cada insignia elemental un símbolo gráfico exclusivo que permita distinguir el elemento en soportes monocromáticos (lectores de tinta electrónica o impresiones en blanco y negro) sin depender exclusivamente del color.
* **RF-03.4 [Estado]:**  
  MIENTRAS un conjuro pertenezca a los *Archivos Experimentales*, el sistema DEBERÁ superponer sobre la tarjeta una insignia distintiva de *«Inestabilidad Arcana»* con un halo ámbar pulsante y advertencia visual.

### RF-04: Retroalimentación de Carga («Pergaminos Espectrales»)
* **RF-04.1 [Estado]:**  
  MIENTRAS una tarjeta, catálogo o panel se encuentre en espera de datos, el sistema DEBERÁ desplegar «Pergaminos Espectrales» (siluetas rúnicas) con dimensiones proporcionales idénticas al contenido final.
* **RF-04.2 [Ubicuo]:**  
  El sistema DEBERÁ animar las siluetas espectrales con una modulación suave de resplandor ceniciento-dorado sin bloquear el hilo de interacción del navegador.
* **RF-04.3 [Dirigido por Eventos]:**  
  CUANDO los datos se encuentren listos, el sistema DEBERÁ sustituir de forma continua las siluetas espectrales por los datos finales garantizando una métrica de estabilidad de maquetación nula (*Cumulative Layout Shift* = 0).

### RF-05: Disposición «El Tomo Central» y Adaptabilidad Responsiva
* **RF-05.1 [Ubicuo]:**  
  El sistema DEBERÁ enmarcar sus contenidos en «El Tomo Central», con un ancho máximo delimitado a exactamente **1280 puntos/píxeles** en pantallas de escritorio, emulando la doble página abierta de un libro ancestral.
* **RF-05.2 [Estado]:**  
  MIENTRAS la interfaz se visualice en tabletas (ancho de pantalla entre 768 px y 1024 px), el sistema DEBERÁ organizar la rejilla de tarjetas en exactamente **dos (2) columnas**.
* **RF-05.3 [Estado]:**  
  MIENTRAS la interfaz se visualice en dispositivos móviles o pantallas estrechas (< 768 px), el sistema DEBERÁ organizar el contenido en **una (1) columna** continua de lectura ergonómica.
* **RF-05.4 [Ubicuo]:**  
  El sistema DEBERÁ garantizar un área táctil mínima de 44x44 puntos/píxeles asignando la interactividad a la superficie completa de la tarjeta y dotando a los botones de márgenes táctiles invisibles, sin inflar visualmente las insignias informativas.
* **RF-05.5 [Estado]:**  
  MIENTRAS el usuario active un nivel de aumento visual o zoom del 200% por motivos de accesibilidad, el sistema DEBERÁ transformar la visualización a columna simple y permitir la expansión vertical de las tarjetas sin truncar el texto.

### RF-06: Estados Interactivos y Modo Impresión / Tinta Electrónica
* **RF-06.1 [Dirigido por Eventos]:**  
  CUANDO el usuario pose el cursor, enfoque por teclado o active un control, el sistema DEBERÁ emitir una respuesta visual de elevación suave sobre el pergamino e intensificación del resplandor elemental correspondiente.
* **RF-06.2 [No Deseado / Excepción]:**  
  SI una acción o control se encuentra temporalmente deshabilitado, ENTONCES el sistema DEBERÁ representarlo con una textura de piedra desgastada e iconografía de runa sellada.
* **RF-06.3 [Opcional / Modo Impresión]:**  
  DONDE el usuario active la orden de impresión o exportación del documento, el sistema DEBERÁ transformar automáticamente el fondo a pergamino claro/blanco y los textos a tinta negra/sepia de alto contraste y bajo consumo de tóner.

---

### RF-07: El Sello Rúnico Forjado y la Heráldica Determinista
* **RF-07.1 [Ubicuo]:**  
  El sistema DEBERÁ declarar la materia del sello como tokens de diseño (disco de tinta, muescas marfil, oro antiguo de casa viva, oro vivo del regente, bronce de casa disuelta y cera de brasa) y vestir todo sello exclusivamente con ellos, sin un solo literal de color en las hojas que lo consumen.
* **RF-07.2 [Ubicuo]:**  
  El sistema DEBERÁ forjar el blasón de una casa como **Sello Rúnico determinista**: la carga central declara su Linaje Mágico según el canon alquímico (fuego △, agua ▽, tierra ▽ barrada, viento △ con barra, más rayo, sol radiante, creciente abisal y ouroboros) y el anillo de ocho muescas codifica su identificador de blasón mediante una huella FNV-1a de 32 bits. La misma casa forja siempre el mismo sello, sin aleatoriedad ni estado oculto.
* **RF-07.3 [Ubicuo]:**  
  El sistema DEBERÁ **jamás imprimir el identificador técnico del blasón** —ni el glifo rúnico de un linaje— como texto de interfaz, ni dentro del nombre accesible de un elemento. Lo que el sello codifica, no lo deletrea.
* **RF-07.4 [Estado]:**  
  MIENTRAS una casa ostente un estado heráldico, el sello DEBERÁ declararlo por **metal y forma a la vez**: casa activa con anillo de oro antiguo, Clan Regente con anillo de oro vivo y el sello de cera presionado a las doce, y casa disuelta con anillo de bronce **roto en su base**. El estado jamás dependerá del color por sí solo.
* **RF-07.5 [Ubicuo]:**  
  El sistema DEBERÁ exponer cada sello como contenido gráfico accesible (`role="img"` con etiqueta en noble castellano que nombre la casa, su linaje y su honor vigente) y DEBERÁ forjarlo como SVG en línea por aritmética nativa: sin imágenes, sin tipografías nuevas, sin dependencias y sin movimiento ni resplandor añadidos.
* **RF-07.6 [Ubicuo]:**  
  El sistema DEBERÁ medir el contraste de la materia del sello sobre su disco de tinta: muescas por encima de 7:1, carga del linaje por encima de 4.5:1 y metales ceremoniales por encima del umbral de gráfico significativo de 3:1.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Fidelidad y Velo Arcano):**  
  La totalidad de texturas, contrastes y glifos preservarán la ambientación de alta fantasía solemne exigida por el Artículo IV de la Constitución.
* **RNF-02 (Estabilidad Visual Total):**  
  La sustitución de Pergaminos Espectrales por contenido real no provocará ningún salto de diseño perceptible (*CLS = 0*).
* **RNF-03 (Rendimiento Sostenido de Animación):**  
  La respiración rúnica ceremonial (3 a 4 s) se ejecutará a 60 fotogramas por segundo constantes con un consumo de batería y CPU mínimo en dispositivos móviles.
* **RNF-04 (Ergonomía de Lectura):**  
  La longitud de línea de texto descriptivo se mantendrá entre 45 y 75 caracteres por línea dentro del Tomo Central para evitar la fatiga ocular.
* **RNF-05 (Soberanía Tipográfica Local):**  
  Cero peticiones de red externas para la carga de fuentes en estricto cumplimiento del Artículo I de la Constitución (Dogma Vanilla).

---

## 6. Casos Límite y Reglas de Contingencia

1. **Pantallas ultra-compactas (320 puntos):**  
   Los elementos de las tarjetas se apilan verticalmente manteniendo márgenes confortables sin desbordar los bordes laterales.
2. **Monitores ultra-panorámicos (4K / ultrawide):**  
   El Tomo Central permanece anclado en 1280 px centrado en el eje visual, arropado por márgenes periféricos de oscuridad profunda sin distorsión.
3. **Escalado de accesibilidad al 200%:**  
   Las tarjetas se expanden dinámicamente en altura asegurando la lectura íntegra de títulos y descripciones sin colapsos.
4. **Pantallas de tinta electrónica (e-ink monocromático):**  
   La diferenciación rúnica gráfica permite identificar afinidades sin requerir color, y la lentitud del ciclo ceremonial previene parpadeos agresivos de refresco.

---

## 7. Fuera de Alcance (Out of Scope)

* La lógica del motor de búsqueda y el enrutador de vistas (cubierto en `SPEC-01`).
* El lienzo dinámico de partículas complejas en Canvas y el sintetizador de voz (cubierto en `SPEC-05`).
* Las fórmulas matemáticas de puntuación de maná (cubierto en `SPEC-04`).
* Archivos físicos de código o sintaxis de preprocesadores (reservados al Plan Técnico).

---

## 8. Criterios de Finalización (Definition of Done)

- [ ] La atmósfera de fantasía oscura y el Tomo Central (1280 px) se visualizan con márgenes solemnes en escritorio, 2 columnas en tabletas y 1 columna en móviles.
- [ ] La jerarquía tipográfica aplica fuentes arcanas en títulos, fuentes sobrias para lectura y cifras de maná de estilo clásico sin dependencias remotas.
- [ ] La Afinidad Elemental gobierna el color/resplandor y la Escuela de Magia su glifo rúnico propio con simbología monocromática de respaldo.
- [ ] Los «Pergaminos Espectrales» de carga eliminan los saltos de maquetación (*CLS = 0*).
- [ ] La Respiración Arcana ejecuta ciclos solemnes de 3 a 4 segundos a 60 fps estables.
- [ ] El contraste visual de texto supera 4.5:1 sobre fondos texturizados fundidos en bases sólidas.
- [ ] El escalado al 200% y el modo de impresión en pergamino claro de bajo consumo funcionan de forma armoniosa.
- [ ] El blasón de cada casa y el sello de cada linaje se forjan como Sello Rúnico determinista: el identificador técnico jamás se imprime, el estado se declara por metal y forma, y la etiqueta accesible nombra casa, linaje y honor en castellano.

---

## 9. Dudas Abiertas

* *(Ninguna)*: Todas las ambigüedades dimensionales, solapamientos conceptuales, cadencias de pulso y adaptaciones a soportes monocromáticos han quedado resueltas y blindadas tras la revisión QA.

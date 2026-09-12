# SPEC-01: Portal Web, Navegación y Descubrimiento Arcano

> **Estado:** Aprobada y Blindada tras Revisión QA  
> **Prioridad:** Fundamental (Base de Producto y Experiencia de Usuario)  
> **Enfoque:** QUÉ y POR QUÉ (Requisitos Funcionales y Criterios de Aceptación EARS)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
El **Grimorio Interactivo** es un santuario digital y wiki colaborativa concebida para preservar, catalogar y expandir el conocimiento arcano de hechizos, artefactos y linajes mágicos. La puerta de entrada al santuario debe reflejar la solemnidad de la alta fantasía (inspirada en universos como *Frieren*, *D&D* y *Tolkien*), permitiendo una exploración libre y pública de su biblioteca mística, al tiempo que guía de manera orgánica hacia la consagración de nuevos miembros.

### 1.2 Objetivo
Definir la arquitectura de información, la experiencia de usuario, las pautas de navegación persistente y los mecanismos de descubrimiento de contenidos en el portal público. Garantizar que cualquier visitante pueda orientarse, buscar y maravillarse con el saber arcano sin fricciones técnicas, con un rendimiento fluido y una inmersión temática total, salvaguardando la continuidad de su contexto interactivo.

---

## 2. Usuarios y Arquetipos

* **Visitante / Lector Arcano (No Autenticado):**  
  Cualquier persona que ingresa al portal sin credenciales activas. Posee libre acceso de lectura a la biblioteca de hechizos validados, a los archivos experimentales y al Salón de Linajes con su clasificación de dominio.
* **Iniciado / Miembro del Santuario (Autenticado - Roles Editor, Maestro, Admin Supremo):**  
  Usuario registrado y vinculado a un linaje que accede a herramientas de autoría, gestión de su grimorio personal y votación/moderación según su rango.

---

## 3. Historias de Usuario

* **HU-01 (Inmersión en Portada y Génesis):**  
  *Como* visitante que accede al Grimorio por primera vez,  
  *quiero* una portada temática solemne con los hechizos más recientes o primordiales,  
  *para* comprender de inmediato la profundidad del lore y el propósito del santuario.

* **HU-02 (Exploración Fluida de la Biblioteca):**  
  *Como* aprendiz de mago,  
  *quiero* buscar conjuros por texto sin importar acentos ni mayúsculas y filtrar por escuelas mágicas o umbral de maná,  
  *para* hallar con precisión los saberes que necesito sin que la pantalla se recargue.

* **HU-03 (Consulta No Disruptiva y Enlazable):**  
  *Como* erudito que examina el compendio,  
  *quiero* desplegar la ficha de un hechizo en un panel superpuesto que actualice la dirección del navegador y responda al botón "Atrás",  
  *para* estudiarlo en profundidad sin perder mi posición de lectura en el catálogo ni salir del sitio accidentalmente.

* **HU-04 (Vínculo Arcano sin Pérdida de Contexto):**  
  *Como* visitante que desea realizar una acción consagrada (crear un hechizo, afiliarse a un linaje o votar),  
  *quiero* que el diálogo de acceso se abra sobre mi vista actual sin destruir la ficha que estaba leyendo,  
  *para* consagrar mi vínculo o renovarlo y continuar inmediatamente con la acción pretendida.

* **HU-05 (Consulta Libre de Linajes):**  
  *Como* explorador público,  
  *quiero* recorrer el Salón de Linajes y consultar el ranking semanal de Dominio del Grimorio,  
  *para* conocer las facciones activas antes de decidir si afiliarme a alguna de ellas.

* **HU-06 (Orientación Temática ante Excepciones):**  
  *Como* usuario que realiza una búsqueda infructuosa o accede a un conjuro extinto,  
  *quiero* ver una leyenda temática con controles de rescate directo,  
  *para* recuperarme y proseguir mi exploración sin frustración.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Portada Mística de Bienvenida y Hechizos Destacados
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ presentar en la portada una introducción narrativa arcana, un llamado a la acción destacado para afiliarse a un linaje (*«Consagrar Linaje»*) y una galería con exactamente tres (3) hechizos destacados.
* **RF-01.2 [Ubicuo]:**  
  El sistema DEBERÁ seleccionar como hechizos destacados los tres (3) conjuros validados con fecha de aprobación más reciente en el santuario.
* **RF-01.3 [No Deseado / Excepción]:**  
  SI el santuario se encuentra en estado de génesis (con menos de 3 hechizos validados registrados), ENTONCES el sistema DEBERÁ completar los espacios vacíos de la galería con *«Pergaminos Primordiales»* de muestra canónicos no editables (*«Chispa de Ignición»*, *«Manto de Niebla»*, *«Susurro del Viento»*).
* **RF-01.4 [Dirigido por Eventos]:**  
  CUANDO el usuario seleccione el botón de consagración de linaje en la portada, el sistema DEBERÁ desplegar el diálogo modal de acceso y consagración.
* **RF-01.5 [Dirigido por Eventos]:**  
  CUANDO el usuario presione cualquier tarjeta de hechizo destacado, el sistema DEBERÁ abrir su ficha técnica en un panel superpuesto.

### RF-02: Navegación Global y Persistente
* **RF-02.1 [Ubicuo]:**  
  El sistema DEBERÁ mantener visible y accesible en la cabecera una barra de navegación con enlaces a: *Inicio*, *Biblioteca de Hechizos*, *Salón de Linajes*, *Creador de Hechizos* y el botón místico *«Cruzar el Umbral»*.
* **RF-02.2 [Ubicuo]:**  
  El sistema DEBERÁ permitir el acceso público y completo en modo de solo lectura tanto a la *Biblioteca de Hechizos* como al *Salón de Linajes* y su clasificación de Dominio.
* **RF-02.3 [Dirigido por Eventos]:**  
  CUANDO un usuario no autenticado presione el enlace *«Creador de Hechizos»* en la cabecera, el sistema DEBERÁ desplegar el diálogo modal *«Cruzar el Umbral»* reteniendo la intención de navegación al creador.
* **RF-02.4 [Estado]:**  
  MIENTRAS la aplicación se visualice en dispositivos de pantalla estrecha (ancho inferior a 768 px), el sistema DEBERÁ agrupar las opciones en un menú desplegable arcano accesible por teclado.

### RF-03: Catálogo, Búsqueda y Paginación Arcana
* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ ordenar inicialmente el catálogo público por fecha de validación, mostrando los conjuros más recientes en primer lugar.
* **RF-03.2 [Ubicuo]:**  
  El sistema DEBERÁ desplegar en su vista base únicamente hechizos en estado `validado`, presentando un selector opcional para consultar los *«Archivos Experimentales»* identificados con un sello distintivo de *«Inestabilidad Arcana»*.
* **RF-03.3 [Dirigido por Eventos]:**  
  CUANDO el usuario ingrese al menos dos (2) caracteres en el cuadro de búsqueda, el sistema DEBERÁ filtrar en tiempo real el catálogo de manera insensible a mayúsculas, minúsculas y tildes/diacríticos (ej. *"ignicion"* encuentra *"Ignición"*).
* **RF-03.4 [No Deseado / Excepción]:**  
  SI el texto ingresado en el buscador supera los cien (100) caracteres, ENTONCES el sistema DEBERÁ limitar la cadena a dicho tope ignorando los caracteres excedentes.
* **RF-03.5 [Dirigido por Eventos]:**  
  CUANDO el usuario active múltiples Escuelas de Magia, el sistema DEBERÁ operar con lógica disyuntiva de unión (`OR`), presentando aquellos hechizos que pertenezcan a cualquiera de las escuelas marcadas.
* **RF-03.6 [Dirigido por Eventos]:**  
  CUANDO el usuario seleccione un umbral de maná, el sistema DEBERÁ filtrar los resultados mostrando exclusivamente conjuros cuyo coste de maná sea menor o igual al valor establecido.
* **RF-03.7 [Ubicuo]:**  
  El sistema DEBERÁ listar el catálogo en bloques iniciales de cincuenta (50) tarjetas. Si existen más coincidencias, el sistema DEBERÁ exhibir al pie el control temático *«Desenrollar más pergaminos»* para incorporar de forma progresiva el siguiente bloque de 50 elementos sin recargar la vista.

### RF-04: Ficha de Detalle Superpuesta y Sincronización de Navegación
* **RF-04.1 [Dirigido por Eventos]:**  
  CUANDO el usuario seleccione cualquier tarjeta de hechizo, el sistema DEBERÁ desplegar la ficha de detalle en un panel modal superpuesto preservando intacto el desplazamiento (*scroll*) de la lista inferior, y actualizando la dirección del navegador con el identificador del hechizo (ej. `#hechizo-nombre`).
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO el usuario active el control de cierre, presione fuera del área del panel, pulse la tecla *Escape* o accione el botón nativo *"Atrás"* del navegador o dispositivo móvil, el sistema DEBERÁ cerrar el panel superpuesto sin salir de la página web.
* **RF-04.3 [Dirigido por Eventos]:**  
  CUANDO se cierre el panel de detalle, el sistema DEBERÁ devolver el foco del teclado a la tarjeta de origen; SI la tarjeta de origen ya no existiera en el DOM por un cambio de filtro, ENTONCES el sistema DEBERÁ dirigir el foco al encabezado principal de la biblioteca.

### RF-05: Diálogo «Cruzar el Umbral» y Preservación de Contexto
* **RF-05.1 [Dirigido por Eventos]:**  
  CUANDO un visitante no autenticado presione el botón de cabecera *«Cruzar el Umbral»* o intente una acción consagrada (*«Crear Hechizo»*, *«Afiliarse a este Linaje»* o emitir una firma de moderación), el sistema DEBERÁ abrir el diálogo modal de acceso con las opciones *«Renovar Vínculo»* (inicio de sesión) y *«Consagrarse»* (registro).
* **RF-05.2 [Estado]:**  
  MIENTRAS el usuario active una acción restringida desde el interior de una ficha de detalle superpuesta, el sistema DEBERÁ abrir el diálogo *«Cruzar el Umbral»* en una capa superior sin destruir ni cerrar la ficha de detalle subyacente.
* **RF-05.3 [Dirigido por Eventos]:**  
  CUANDO el usuario complete exitosamente su vínculo (autenticación), el sistema DEBERÁ cerrar el diálogo de acceso y reanudar la acción pendiente sobre la ficha o redirigir al destino pretendido originalmente.
* **RF-05.4 [No Deseado / Excepción]:**  
  SI el navegador del cliente tiene restringido o deshabilitado el almacenamiento local, ENTONCES el sistema DEBERÁ conservar la intención pendiente en memoria volátil de sesión sin generar fallas en la interfaz.

### RF-06: Estados Vacíos y Pantallas de Recuperación
* **RF-06.1 [No Deseado / Excepción]:**  
  SI una búsqueda o combinación de filtros no arroja ningún conjuro coincidente, ENTONCES el sistema DEBERÁ mostrar una ilustración y leyenda temática (*«Ningún conjuro responde a esas runas en este plano»*) junto a un botón para restablecer todos los filtros a su estado inicial.
* **RF-06.2 [No Deseado / Excepción]:**  
  SI el identificador de un hechizo en la dirección web no existe o fue desterrado, ENTONCES el sistema DEBERÁ presentar un mensaje temático de extravío (*«El pergamino que buscas se ha desvanecido en el éter»*) con un botón directo de retorno a la Biblioteca de Hechizos.
* **RF-06.3 [No Deseado / Excepción]:**  
  SI se interrumpe la comunicación con los servicios del santuario, ENTONCES el sistema DEBERÁ presentar un estado de error místico (*«La corriente de maná se ha interrumpido»*) con un botón de reintento.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Inmersión y Coherencia Temática):**  
  Toda la terminología, interfaces, botones y textos respetarán la solemnidad del lore de alta fantasía en lengua castellana conforme al Artículo IV de la Constitución. Queda vetada la terminología informática convencional ajena al santuario.
* **RNF-02 (Rendimiento Percibido y Fluidez):**  
  El filtrado y búsqueda en memoria dentro del bloque activo de 50 hechizos deberá completarse en menos de 150 ms percibidos por el usuario.
* **RNF-03 (Accesibilidad y Operabilidad por Teclado):**  
  Todos los diálogos y paneles superpuestos implementarán atrapamiento de foco (*focus trap*), navegación estructurada por tabulación y salida mediante la tecla *Escape*.
* **RNF-04 (Diseño Adaptativo / Responsivo):**  
  La interfaz se adaptará fluidamente a cualquier pantalla desde 320 px hasta monitores ultra-panorámicos, garantizando zonas táctiles de al menos 44x44 px en dispositivos móviles.
* **RNF-05 (Integración con el Historial del Navegador):**  
  El despliegue de paneles modales debe sincronizarse con el historial del cliente para permitir que la navegación nativa hacia atrás cierre paneles en lugar de romper la sesión de lectura.

---

## 6. Casos Límite y Reglas de Contingencia

1. **Entrada de texto extremadamente rápida en el buscador:**  
   El sistema debe sincronizar el filtrado de modo que solo la consulta más reciente sea la visible, evitando desfases o parpadeos en el catálogo.
2. **Nombres o descripciones extraordinariamente extensas:**  
   Las tarjetas del catálogo truncarán el texto a un máximo de 3 líneas con elipsis visual. El texto íntegro e ilimitado se desplegará siempre en la ficha de detalle superpuesta.
3. **Pila de modales y jerarquía z-index:**  
   El diálogo de *«Cruzar el Umbral»* tiene precedencia visual sobre la ficha de detalle, pero cerrar el diálogo de acceso restituye inmediatamente la interacción con la ficha previa sin reinicios de página.
4. **Rescate del foco tras filtrado:**  
   Si el cierre de un modal coincide con una actualización del listado donde la tarjeta de origen desapareció, el foco del teclado se dirigirá ordenadamente al título de la biblioteca.

---

## 7. Fuera de Alcance (Out of Scope)

* El algoritmo matemático backend para el balanceo y cálculo del coste de maná (`SPEC-04`).
* El simulador interactivo de grimorio con animación de partículas en Canvas y pronunciación de conjuros con Web Speech API (`SPEC-05`).
* La matriz de afinidad elemental y cálculo de combos mágicos (`SPEC-06`).
* La afiliación efectiva a linajes y el cómputo semanal de puntos de Dominio (`SPEC-07`).
* El sistema de firmas de moderación y aprobación en dos pasos por Maestros (`SPEC-08`).
* Elecciones de stack tecnológico, dependencias físicas o nombres de archivos de código.

---

## 8. Criterios de Finalización (Definition of Done)

- [ ] La portada exhibe los tres hechizos validados más recientes o los "Pergaminos Primordiales" de muestra si hay menos de 3.
- [ ] La barra de navegación permite consultar libremente la Biblioteca y el Salón de Linajes sin requerir autenticación.
- [ ] El buscador responde en tiempo real con insensibilidad a mayúsculas y acentos, truncando cadenas a 100 caracteres.
- [ ] Los filtros de escuelas operan bajo unión (`OR`) y el maná filtra por tope superior (`<=`).
- [ ] El catálogo carga en bloques de 50 elementos con el botón *«Desenrollar más pergaminos»*.
- [ ] La ficha de detalle se abre superpuesta, actualiza la dirección web y se cierra con el botón "Atrás" o la tecla *Escape*.
- [ ] Cualquier intento de acción reservada abre el diálogo *«Cruzar el Umbral»* sin destruir el detalle subyacente.
- [ ] El catálogo segrega hechizos validados de los *«Archivos Experimentales»* con distintivo de inestabilidad.
- [ ] Los tres estados de excepción (búsqueda vacía, 404 de hechizo y corte de maná) muestran interfaces de rescate temáticas.
- [ ] Todos los textos cumplen rigurosamente con la ambientación arcana y el Artículo IV de la Constitución.

---

## 9. Dudas Abiertas

* *(Ninguna)*: Todos los puntos de ambigüedad, contradicción, casos límite y conflictos constitucionales han quedado plenamente resueltos y normados en este documento tras la revisión de control de calidad.

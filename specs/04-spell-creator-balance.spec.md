# SPEC-04: Creador de Hechizos y Algoritmo de Balanceo de Maná

> **Estado:** Aprobada y Blindada tras Revisión QA  
> **Prioridad:** Esencial (Lógica de Dominio Central y Equilibrio Mágico)  
> **Enfoque:** QUÉ y POR QUÉ (Modelo de Conjuro, Constantes Deterministas de Maná y Criterios EARS)  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
En el universo del **Grimorio Interactivo**, la magia es una ciencia objetiva regida por leyes armónicas inmutables. El **Artículo II (La Ley Universal del Maná: Equilibrio Matemático & Anti-Power-Creep)** y el **Artículo V (Dualidad Lingüística)** de la Constitución prohíben la discrecionalidad o asignación arbitraria de costes. Todo conjuro concebido por los eruditos del santuario debe someterse a una formulación matemática unívoca, ciega e invariable que mida con exactitud su impacto cósmico.

### 1.2 Objetivo
Normar la experiencia interactiva en el taller de creación de conjuros, el modelo cuantitativo de efectos, modificadores geométricos y componentes, los valores numéricos exactos de la fórmula de balanceo, los cinco Círculos Arcanos con techo de contención (máx. 200 de maná), el suelo mínimo inmutable (5 de maná), la cuota de borradores privados (`draft`), la publicación a estado `experimental` y la salvaguarda antifraude de reseteo selectivo de firmas ante alteraciones matemáticas.

---

## 2. Usuarios y Arquetipos

* **Iniciado / Editor Arcano:**  
  Autor del conjuro. Diseña el concepto narrativo, ajusta los efectos y componentes en el simulador reactivo, administra hasta 10 borradores privados y postula sus obras como conjuros experimentales para su linaje.
* **Maestro Validador:**  
  Erudito que examina la coherencia entre el desglose matemático inmutable y la descripción narrativa durante el proceso de moderación.
* **Admin Supremo:**  
  Custodio del equilibrio que vigila que ningún conjuro vulnere los techos de poder del plano mortal.

---

## 3. Historias de Usuario

* **HU-01 (Diseño Modular y Desglose Pedagógico):**  
  *Como* Editor que concibe un nuevo conjuro,  
  *quiero* configurar los efectos base, alcance, área y componentes observando en tiempo real el desglose exacto de maná y el Círculo Arcano resultante,  
  *para* equilibrar con precisión la potencia de mi creación antes de someterla a juicio.

* **HU-02 (Taller de Borradores con Cuota Segura):**  
  *Como* autor meticuloso,  
  *quiero* guardar hasta 10 conjuros en estado de borrador privado (`draft`),  
  *para* pulir su redacción y balance sin que aparezcan en el catálogo público ni en la cola de los Maestros.

* **HU-03 (Reseteo Antifraude de Firmas):**  
  *Como* Maestro validador,  
  *quiero* que si un autor altera los parámetros matemáticos de un conjuro experimental en revisión, sus firmas vuelvan a cero (0/3), pero si solo corrige erratas en la descripción se conserven las firmas,  
  *para* evitar fraudes en la votación sin castigar correcciones menores de redacción.

* **HU-04 (Techo de Contención contra el Desequilibrio):**  
  *Como* custodio del equilibrio del mundo,  
  *quiero* que el sistema rechace automáticamente cualquier combinación de efectos que sobrepase los 200 puntos de maná (techo del Círculo V),  
  *para* impedir la existencia de conjuros "mata-dioses" que rompan la contienda entre linajes.

* **HU-05 (Suelo Mínimo Sagrado y Ponderación Real):**  
  *Como* erudito del santuario,  
  *quiero* que todo conjuro cueste al menos 5 de maná y que curar o proteger cueste más que dañar,  
  *para* reflejar fielmente la dificultad cósmica de restaurar la vida frente a destruirla.

* **HU-06 (Neutralidad Elemental y Nombres Únicos):**  
  *Como* hechicero creador,  
  *quiero* que la fórmula de maná sea neutral frente a las 8 afinidades elementales y que el nombre de mi conjuro sea único en todo el santuario,  
  *para* elegir mi elemento libremente sin penalizaciones numéricas y contar con una identidad rúnica propia.

---

## 4. Requisitos Funcionales (Notación EARS en Español)

### RF-01: Modelo de Datos y Parámetros Cuantitativos del Conjuro
* **RF-01.1 [Ubicuo]:**  
  El sistema DEBERÁ requerir para cada conjuro los siguientes metadatos canónicos: Nombre (3 a 60 caracteres, globalmente único en el santuario), Afinidad Elemental (Fuego, Agua, Rayo, Tierra, Viento, Luz, Oscuridad, Arcano Puro), Escuela de Magia académica (Evocación, Abjuración, Nigromancia, Ilusión, Transmutación, Encantamiento, Adivinación, Conjuración), Tiempo de Lanzamiento (1 Acción, 1 Reacción/Rápida, Ritual de 10 min) y Descripción narrativa en noble castellano.
* **RF-01.2 [Ubicuo]:**  
  El sistema DEBERÁ tipificar los Efectos Base en la capa de datos bajo identificadores técnicos en inglés `camelCase` con las siguientes ponderaciones de maná base:
  * **Daño (`damage`):** $1$ punto de daño $= 1.0$ de maná base.
  * **Curación (`healing`):** $1$ punto de curación $= 1.5$ de maná base.
  * **Barrera / Protección (`barrier`):** $1$ punto de absorción $= 1.2$ de maná base.
  * **Control de Masas (`crowdControl`):** Ralentización ligera $= 8$ de maná; Enraizamiento / Ceguera $= 15$ de maná; Aturdimiento total $= 25$ de maná.
* **RF-01.3 [Ubicuo]:**  
  El sistema DEBERÁ aplicar los siguientes multiplicadores por Modificadores Geométricos de Alcance (`range`) y Área (`area`):
  * **Alcance:** Contacto (`touch`) $= \times 1.0$; Corto hasta 10 m (`short`) $= \times 1.1$; Medio hasta 30 m (`medium`) $= \times 1.25$; Largo hasta 100 m (`long`) $= \times 1.5$.
  * **Área:** Objetivo Único (`singleTarget`) $= \times 1.0$; Cono frontal (`cone`) $= \times 1.3$; Línea perforante (`line`) $= \times 1.4$; Radio / Esfera de explosión (`sphere`) $= \times 1.6$.
* **RF-01.4 [Ubicuo]:**  
  El sistema DEBERÁ aplicar los siguientes multiplicadores por Modificador de Duración (`duration`):
  * Instantáneo (`instant`) $= \times 1.0$; Concentración hasta 1 min (`concentration`) $= \times 1.25$; Sostenido prolongado hasta 10 min (`sustained`) $= \times 1.5$.
* **RF-01.5 [Ubicuo]:**  
  El sistema DEBERÁ aplicar los siguientes descuentos porcentuales por Componentes exigidos (`components`):
  * Componente Verbal (`verbal`): $-10\%$ de reducción ($0.10$).
  * Componente Somático (`somatic`): $-10\%$ de reducción ($0.10$).
  * Componente Material (`material`): $-10\%$ de reducción ($0.10$).
  * *(La presencia simultánea de los tres componentes concede el descuento máximo acumulado del treinta por ciento: $30\%$).*

### RF-02: La Ley Universal del Maná (Fórmula Determinista Ciega)
* **RF-02.1 [Ubicuo]:**  
  El sistema DEBERÁ calcular el Coste de Maná mediante la siguiente formulación matemática determinista invariable ejecutada en el servidor:
  $$\text{Bruto} = \left( \sum \text{Efectos Base} \right) \times \text{Factor Alcance} \times \text{Factor Área} \times \text{Factor Duración}$$
  $$\text{Coste de Maná} = \max\left(5, \; \left\lceil \text{Bruto} \times (1 - \text{Descuento Componentes}) \right\rceil \right)$$
* **RF-02.2 [Ubicuo]:**  
  El sistema DEBERÁ garantizar un **suelo mínimo inmutable de cinco (5) puntos de maná**; ningún conjuro podrá tener un coste inferior a 5 ni valores negativos bajo ninguna circunstancia.
* **RF-02.3 [Ubicuo]:**  
  El sistema DEBERÁ redondear siempre el resultado fraccionario hacia arriba al entero superior más próximo (`ceil`) antes de aplicar la evaluación del suelo mínimo.
* **RF-02.4 [Ubicuo]:**  
  El sistema DEBERÁ aplicar **neutralidad elemental absoluta**: la Afinidad Elemental seleccionada y la Escuela de Magia no introducirán recargos, penalizaciones ni deducciones sobre el cálculo matemático.

### RF-03: Escala de Círculos Arcanos y Techo de Contención (Anti-Power-Creep)
* **RF-03.1 [Ubicuo]:**  
  El sistema DEBERÁ clasificar de forma automática el Círculo Arcano del conjuro en función del Coste de Maná final:
  * **Círculo I (Iniciado):** de 5 a 20 puntos de maná.
  * **Círculo II (Adepto):** de 21 a 45 puntos de maná.
  * **Círculo III (Magister):** de 46 a 80 puntos de maná.
  * **Círculo IV (Maestro):** de 81 a 130 puntos de maná.
  * **Círculo V (Archimago):** de 131 a 200 puntos de maná.
* **RF-03.2 [No Deseado / Excepción]:**  
  SI la combinación de efectos arroja un coste bruto superior a doscientos (200) puntos de maná, ENTONCES el sistema DEBERÁ rechazar la creación del conjuro emitiendo el error solemne de **Sobrecarga Arcana** (*«La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná)»*).

### RF-04: Simulación Reactiva en Vivo y Autoridad Ciega del Backend
* **RF-04.1 [Dirigido por Eventos]:**  
  CUANDO el usuario modifique cualquier parámetro cuantitativo, modificador o componente en el formulario, el sistema DEBERÁ actualizar en tiempo real el desglose pedagógico del maná y el Círculo Arcano resultante en la interfaz.
* **RF-04.2 [Dirigido por Eventos]:**  
  CUANDO el autor envíe la solicitud de guardado o publicación, el sistema en el servidor DEBERÁ ejecutar de forma ciega y autoritativa el cálculo de la fórmula matemática; SI existe cualquier discrepancia con los valores remitidos por el cliente, ENTONCES el servidor DEBERÁ imponer su cálculo verídico e inviolable.

### RF-05: Ciclo de Vida, Cuota de Borradores y Antifraude de Firmas
* **RF-05.1 [Ubicuo]:**  
  El sistema DEBERÁ permitir a cada autor mantener un **máximo de diez (10) conjuros simultáneos en estado `draft`** (borrador privado); SI el autor alcanza dicho límite, ENTONCES el sistema DEBERÁ bloquear la creación de nuevos borradores hasta que publique o elimine alguno existente.
* **RF-05.2 [Dirigido por Eventos]:**  
  CUANDO el autor solicite publicar un borrador completo, el sistema DEBERÁ transicionar su estado a `experimental` con cero firmas acumuladas (0/3), haciéndolo visible en la cola de moderación y en el catálogo público.
* **RF-05.3 [Dirigido por Eventos]:**  
  CUANDO el autor modifique los efectos base, alcance, área, duración o componentes de un conjuro que ya poseía firmas en estado `experimental`, el sistema DEBERÁ **restablecer automáticamente el contador de firmas a cero (0/3 firmas)** e inscribir la alteración en la Bitácora de Auditoría.
* **RF-05.4 [Dirigido por Eventos]:**  
  CUANDO el autor modifique exclusivamente la descripción narrativa o la ortografía de un conjuro experimental (sin alterar ningún parámetro de maná), el sistema DEBERÁ conservar intactas las firmas previas acumuladas y registrar la actualización de texto en la Bitácora de Auditoría.

### RF-06: Inmutabilidad de Conjuros Validados y Variantes
* **RF-06.1 [Ubicuo]:**  
  El sistema DEBERÁ permitir al autor editar y eliminar libremente sus propios conjuros mientras permanezcan en estado `draft` o `experimental`.
* **RF-06.2 [Estado]:**  
  MIENTRAS un conjuro alcance el estado `validated` tras obtener tres firmas de Maestros (o ratificación directa de administración), el sistema DEBERÁ **congelar la ficha de forma permanente, impidiendo su edición o eliminación por el autor original**, salvaguardando la integridad de la biblioteca colectiva y el legado del clan.
* **RF-06.3 [Opcional]:**  
  DONDE un autor desee evolucionar un conjuro ya validado, el sistema DEBERÁ permitirle clonar la ficha como una nueva **«Variante»** independiente que nacerá en estado `draft`.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Determinismo Absoluto e Inviolable):**  
  Cero discrecionalidad técnica o administrativa: dos peticiones con idénticos parámetros matemáticos producirán exactamente el mismo Coste de Maná en cumplimiento estricto del Artículo II de la Constitución.
* **RNF-02 (Latencia de la Simulación Reactiva):**  
  El recálculo pedagógico en la interfaz ante cualquier cambio de control numérico deberá ejecutarse en menos de 50 milisegundos en el navegador.
* **RNF-03 (Integridad Antifraude en Moderación):**  
  Garantía absoluta de que ninguna alteración de parámetros matemáticos pase desapercibida sin el reseteo automático a 0/3 firmas.
* **RNF-04 (Transparencia Pedagógica):**  
  El simulador del creador desglosará con absoluta claridad cada sumando de efectos, factores multiplicadores y deducciones porcentuales para instruir al usuario en las leyes mágicas.
* **RNF-05 (Soberanía Lingüística y Dualidad Constitucional):**  
  Identificadores de tipos de efectos, áreas y alcances en inglés `camelCase` (`damage`, `healing`, `singleTarget`, etc.) en la capa de datos; y toda la experiencia visual, descripciones y mensajes expresados en noble castellano.

---

## 6. Casos Límite y Reglas de Contingencia

1. **Conjuro sin efectos asignados:**  
   Si el autor intenta guardar un conjuro con todos los efectos numéricos en cero, el sistema rechazará la acción advirtiendo: *«Un conjuro sin efectos carece de forma arcana; asigna al menos una manifestación mágica»*.
2. **Efectos compuestos contrapuestos (Daño y Curación simultáneos):**  
   Si un conjuro combina daño y curación en el mismo conjuro, la fórmula sumará los costes de ambos efectos de manera aditiva pura sin compensaciones, resultando en un coste elevado que penaliza la dispersión de propósitos mágicos.
3. **Pérdida de conectividad durante la autoría:**  
   El formulario retendrá el estado de los controles en el almacenamiento volátil del navegador para no perder el borrador si la conexión se interrumpe antes de guardar.
4. **Intento de inyección de parámetros negativos o decimales:**  
   El sistema normalizará cualquier entrada decimal redondeándola al entero inmediato y rechazará tajantemente cualquier valor de efecto menor a cero.
5. **Intento de duplicación de nombre existente:**  
   Si el autor introduce un nombre de conjuro que ya existe en el santuario, el sistema exigirá modificar el título para garantizar la unicidad de las runas canónicas.

---

## 7. Fuera de Alcance (Out of Scope)

* La renderización de partículas en Canvas y la síntesis de voz con Web Speech API (cubiertas en `SPEC-05`).
* La matriz de combos elementales, reacciones y debilidades cruzadas (cubiertas en `SPEC-06`).
* El flujo de deliberación y votación de los Maestros (cubierto en `SPEC-08`).
* El cómputo de puntos de Dominio de Clanes (cubierto en `SPEC-07`).
* La implementación física de scripts en servidor o tablas relacionales (reservados al Plan Técnico).

---

## 8. Criterios de Finalización (Definition of Done)

- [ ] Las constantes numéricas de efectos base (daño 1.0, curación 1.5, barrera 1.2, control de masas 8/15/25) están implementadas de forma unívoca.
- [ ] Los factores multiplicadores de alcance, área y duración aplican las constantes cerradas definidas.
- [ ] El descuento por componentes (10% cada uno) está limitado al 30% del bruto.
- [ ] La fórmula universal aplica el redondeo `ceil` y el suelo mínimo inmutable de 5 de maná.
- [ ] Los 5 Círculos Arcanos se clasifican automáticamente y cualquier valor > 200 es rechazado por *Sobrecarga Arcana*.
- [ ] El creador muestra en tiempo real el desglose pedagógico del maná y el servidor valida de forma ciega y autoritativa.
- [ ] Los autores pueden mantener un máximo de 10 borradores privados (`draft`).
- [ ] Alterar parámetros matemáticos de un conjuro experimental resetea las firmas a 0/3 con apunte en auditoría; corregir texto preserva firmas.
- [ ] Los conjuros validados quedan congelados contra edición o eliminación por su autor original.
- [ ] Los nombres de conjuros son globalmente únicos en el santuario.
- [ ] Se cumple estrictamente la dualidad lingüística y el velo arcano.

---

## 9. Dudas Abiertas

* *(Ninguna)*: Todos los valores matemáticos, multiplicadores geométricos, ciclo de vida de borradores y salvaguardas antifraude han quedado formalmente fijados y blindados tras la revisión de control de calidad.

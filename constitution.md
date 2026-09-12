# CONSTITUTION.md — Constitución del Grimorio Interactivo

> **Estado:** Ratificada y Vigente  
> **Autoridad Suprema:** Arquitecto Fundador  
> **Ámbito de Aplicación:** Agentes de IA, Desarrolladores, Moderadores y Sistemas Automatizados  

---

## Preámbulo

Nosotros, constructores y custodios del **Grimorio Interactivo**, erigimos este sistema como la biblioteca digital definitiva del conocimiento mágico, hechizos, artefactos y linajes arcanos, inspirados en la solemnidad y profundidad de universos como *Frieren: Beyond Journey's End*, *Dungeons & Dragons* y las obras de *J.R.R. Tolkien*.

Con el propósito de garantizar un sistema imperecedero, matemáticamente equilibrado, libre del deterioro del software moderno y temáticamente inmersivo, promulgamos esta **Constitución**. Sus artículos constituyen la ley suprema e inmutable del repositorio; toda especificación, línea de código, arquitectura o decisión humana o artificial que contravenga este texto se considerará nula de pleno derecho.

---

## Artículo I — El Dogma Vanilla (Inviolabilidad Tecnológica)

1. **Prohibición Universal de Dependencias:**  
   Queda terminantemente prohibido, sin excepción alguna de conveniencia temporal o atajo técnico, el uso de frameworks externos o librerías de terceros (tales como dependencias npm, CDNs externas, frameworks CSS como Tailwind o Bootstrap, o frameworks PHP como Laravel o Symfony).
2. **Soberanía del Estándar Nativo:**  
   Todo desarrollo frontend se forjará exclusivamente mediante estándares web nativos del consorcio W3C:
   * HTML5 semántico puro.
   * CSS3 moderno utilizando Custom Properties (variables CSS) y animaciones `@keyframes`.
   * JavaScript moderno (ES6+) mediante arquitectura modular nativa (**ES Modules** con `type="module"`).
   * Efectos de partículas, chispas y auras arcanas exclusivamente mediante **HTML5 Canvas API nativa**.
   * Síntesis de voz mágica mediante la **Web Speech API nativa** (`speechSynthesis`).
3. **Pureza del Backend:**  
   El backend se ejecutará en **PHP 8.2 o superior**, en modo estricto (`declare(strict_types=1);`), implementando una arquitectura MVC ligera orientada a API REST y utilizando **PDO nativo** como único canal de acceso a bases de datos relacionales.

---

## Artículo II — La Ley Universal del Maná (Equilibrio Matemático & Anti-Power-Creep)

1. **Determinismo Absoluto:**  
   El maná es una constante matemática del universo arcano. El "Coste de Maná" de cualquier conjuro se calculará mediante una función determinista, ciega e invariable ejecutada en el backend, basada en la composición objetiva de sus efectos (magnitud de daño, curación, control de masas, duración, área y componentes).
2. **Prohibición de Costes Arbitrarios:**  
   Ningún hechizo podrá tener asignado un coste de maná de manera manual o arbitraria.
3. **Inconstitucionalidad de Excepciones:**  
   Queda declarada inconstitucional cualquier alteración o elusión del algoritmo de balanceo, incluso si dicha petición procede de roles con privilegios elevados o administradores. El sistema no tolerará el desequilibrio de poder (*power-creep*).

---

## Artículo III — Ética de la Moderación y Conflicto de Intereses entre Linajes

1. **El Tribunal de las Tres Firmas:**  
   Todo hechizo concebido por un Editor nacerá en estado `experimental`. Solo ascenderá al estado oficial de `validado` tras obtener la ratificación de tres (3) firmas independientes de usuarios con el rango de `Maestro` (o mediante ratificación directa del `Admin Supremo`).
2. **Incompatibilidad por Conflicto de Intereses:**  
   Dado que los clanes compiten semanalmente por el *Dominio del Grimorio* en función del contenido validado, **ningún Maestro podrá firmar o validar un hechizo creado por un miembro de su propio clan**. Toda firma emitida en contravención de esta norma será automáticamente invalidada.
3. **Transparencia y Auditoría Inmutable:**  
   La autoridad debe ser transparente. Toda acción de moderación, rechazo, validación o intervención del Admin Supremo deberá quedar registrada de forma inalterable en la bitácora de auditoría del sistema, dejando constancia de la identidad del moderador, la estampa temporal y el motivo del veredicto.

---

## Artículo IV — Integridad Temática, Estética y Narrativa (El Velo Arcano)

1. **Solemnidad de la Atmósfera:**  
   El Grimorio Interactivo preservará en todo momento un tono solemne, místico, evocador y respetuoso con la alta fantasía. 
2. **Proscripción de Anacronismos y Memes:**  
   Queda estrictamente vedado el uso de lenguaje coloquial moderno, referencias anacrónicas, terminología del mundo real ajena a la ambientación o humor tipo meme en nombres de hechizos, descripciones, componentes o interfaces.
3. **Armonía Visual:**  
   El diseño visual reflejará la estética de un grimorio arcano viviente: texturas de pergamino antiguo, tonalidades místicas, tipografías elegantes y efectos visuales que evoquen la manipulación real de la energía mágica.

---

## Artículo V — De la Dualidad Lingüística Sagrada (Nomenclatura y Comunicación)

1. **El Lenguaje de la Máquina (Inglés & `camelCase`):**  
   Para preservar la interoperabilidad, pulcritud técnica y estándares universales de la ingeniería de software:
   * Todo identificador en el código (variables, funciones, métodos, propiedades, claves JSON y rutas de API) se escribirá en **lengua inglesa** y bajo la convención **`camelCase`** (ej. `calculateManaCost`, `elementalAffinity`, `spellDamage`).
   * Las clases backend y componentes estructurales se escribirán en **`PascalCase`** en inglés (ej. `SpellBalanceService`).
   * Las constantes globales se escribirán en **`UPPER_SNAKE_CASE`** (ej. `STATUS_EXPERIMENTAL`).
2. **El Alma y la Sabiduría del Grimorio (Castellano):**  
   * Todos los comentarios dentro del código fuente (`//`, `/* */`, bloques PHPDoc y JSDoc) se redactarán en **lengua castellana**, explicando con claridad la lógica técnica y matemática subyacente.
   * La documentación, el lore, las especificaciones en `specs/` y los textos visibles para el usuario en la interfaz se expresarán con máxima riqueza léxica en **lengua castellana**.
3. **Comunicación del Agente:**  
   Toda interacción, respuesta, consulta, reporte y diálogo de los agentes de IA con el Arquitecto Fundador se realizará **estrictamente en castellano**.

---

## Artículo VI — Supremacía de la Especificación (Dogma SDD)

1. **Principio "No Spec, No Code":**  
   Ninguna entidad (agente de IA o humano) escribirá una sola línea de código ejecutable si no existe previamente un documento de especificación formal en el directorio `specs/`, debidamente catalogado y aprobado.
2. **Jerarquía del Conocimiento:**  
   La especificación precede al código; el código es únicamente la materialización fiel de la especificación. Si el código diverge de la especificación aprobada, el código está errado y debe ser corregido.

---

## Artículo VII — Soberanía y Cláusula de Enmiendas

1. **Poder Constituyente Exclusivo:**  
   La soberanía de este proyecto y la potestad exclusiva e indelegable de modificar, derogar o añadir artículos a esta Constitución reside única y exclusivamente en el **Arquitecto Fundador** («Solo yo»).
2. **Inmutabilidad Delegada:**  
   Ningún agente de Inteligencia Artificial, administrador o colectivo de usuarios tiene autoridad para alterar este documento sin la orden directa y explícita del Arquitecto Fundador.

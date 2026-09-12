-- =====================================================================
-- seeds.sql — Datos iniciales de génesis del Grimorio Interactivo
--
-- Tarea 1.1 (TASKS-01): clanes fundacionales, catálogo de Escuelas de
-- Magia y los 3 *Pergaminos Primordiales* canónicos.
--
-- Cubre: RF-01.3 (Pergaminos Primordiales de muestra no editables),
--        RF-02.2 (Salón de Linajes), RF-03.5 (escuelas de referencia).
-- Constitución: Artículo III (el clan 'cln_primordial' es neutro: no
--               compite por el Dominio), Artículo IV (lore solemne en
--               castellano), Artículo V (identificadores en inglés).
--
-- Compatibilidad: SQLite y MySQL. Se evita el INSERT ... VALUES
-- multiple de MySQL; cada fila se inserta de forma independiente.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Escuelas de Magia canónicas (etiquetas en castellano, claves en inglés)
-- ---------------------------------------------------------------------
INSERT INTO magic_schools (slug, name) VALUES ('abjuration', 'Abjuración');
INSERT INTO magic_schools (slug, name) VALUES ('conjuration', 'Conjuración');
INSERT INTO magic_schools (slug, name) VALUES ('divination', 'Adivinación');
INSERT INTO magic_schools (slug, name) VALUES ('enchantment', 'Encantamiento');
INSERT INTO magic_schools (slug, name) VALUES ('evocation', 'Evocación');
INSERT INTO magic_schools (slug, name) VALUES ('illusion', 'Ilusión');
INSERT INTO magic_schools (slug, name) VALUES ('necromancy', 'Nigromancia');
INSERT INTO magic_schools (slug, name) VALUES ('transmutation', 'Transmutación');

-- ---------------------------------------------------------------------
-- Linaje fundacional neutro: custodio de los Pergaminos Primordiales.
-- No compite por el Dominio del Grimorio (dominio de puntos a 0 y
-- nunca acumula contribuciones de usuarios; ver Artículo III).
-- ---------------------------------------------------------------------
INSERT INTO clans (id, slug, name, motto, domain_points, created_at) VALUES
    ('cln_primordial', 'custodios-del-fuego-primordial',
     'Custodios del Fuego Primordial',
     'Antes de la primera palabra, ya ardimos.', 0, '2026-01-01T00:00:00Z');

-- ---------------------------------------------------------------------
-- Pergaminos Primordiales (RF-01.3): las tres muestras canónicas no
-- editables que completan la galería de portada cuando el santuario
-- aún no alberga tres hechizos validados de usuarios.
--
-- Estado 'validated' por ser canon fundacional; nacen con las tres
-- firmas de la génesis y marcados con is_genesis_sample = 1 para que
-- SpellDiscoveryService los distinga (contrato del plan, sección 2.3).
-- ---------------------------------------------------------------------

-- Pergamino Primordial I — Escuela de Evocación
INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    status, validation_signatures_count, is_genesis_sample,
                    created_at, validated_at) VALUES
    ('spl_genesis_01', 'chispa-de-ignicion', 'Chispa de Ignición',
     'evocation', 10, 'cln_primordial',
     'Conjuro fundacional que canaliza la llama más pura para encender candelas o disipar sombras.',
     'El primer conjuro que todo aprendiz traza en su grimorio. Reúne el maná ambiental en la yema de los dedos y lo libera como una llama serena, incapaz de quemar más allá de lo que el corazón ordene. Los Custodios lo enseñan como lección de humildad: antes de domar incendios, hay que saber encender una vela.',
     'Ignis Primordialis, lucem manifestare',
     'Pulgar e índice unidos frente al pecho, descendiendo en arco sereno',
     'Una vela de cera virgen sin encender',
     'validated', 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Pergamino Primordial II — Escuela de Abjuración
INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    status, validation_signatures_count, is_genesis_sample,
                    created_at, validated_at) VALUES
    ('spl_genesis_02', 'manto-de-niebla', 'Manto de Niebla',
     'abjuration', 12, 'cln_primordial',
     'Velo de bruma arcano que envuelve al caminante y difumina su silueta ante miradas ajenas.',
     'Tejido a partir del aliento de la montaña al alba, este velo de bruma se pega a la piel del invocador como un vestido de niebla viva. No oculta con tinieblas, sino con la suavidad de lo que nadie piensa mirar dos veces. Dura lo que tarde en disiparse una taza de té humeante.',
     'Caligo velamen, praesentia dissolves',
     'Ambas palmas abiertas cruzando el rostro de izquierda a derecha',
     'Un pañuelo empapado en rocío de madrugada',
     'validated', 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Pergamino Primordial III — Escuela de Adivinación
INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    status, validation_signatures_count, is_genesis_sample,
                    created_at, validated_at) VALUES
    ('spl_genesis_03', 'susurro-del-viento', 'Susurro del Viento',
     'divination', 14, 'cln_primordial',
     'Llama a la corriente de aire cercana para que traiga fragmentos de conversaciones lejanas.',
     'El viento viaja y escucha; este conjuro le pide amablemente que repita. La corriente que responde trae palabras sueltas de lugares cercanos, como ecos arrastrados por un cañón. Los Custodios advierten: el viento susurra lo que oyó, no lo que el oyente desea oír.',
     'Ventus loquax, verba longinqua ferto',
     'Mano abierta alzada con la palma hacia donde sopla la brisa',
     'Una pluma ligera que el viento pueda alzar',
     'validated', 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Borrador experimental de aprendiz (RF-03.2, Art. III): solo se revela
-- con la bandera includeExperimental=1; jamás en el catálogo público.
INSERT INTO spells (id, slug, name, magic_school, mana_cost, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    status, validation_signatures_count, is_genesis_sample,
                    created_at, validated_at) VALUES
    ('spl_draft_01', 'boceto-prohibido', 'Boceto Prohibido',
     'necromancy', 22, 'cln_primordial',
     'Rasgo inestable que arranca fragmentos de sombra ajenos sin forma aún definida.',
     'Conjuro sin concluir hallado entre las anotaciones de un aprendiz desaparecido. Las sombras convocadas obedecen a medias: se retuercen, susurran y se disuelven sin orden. Ningún Maestro ha querido firmar aún su estabilidad; su estudio se considera riesgo de Inestabilidad Arcana.',
     'Umbra fragilis, ad me veni',
     'Puño cerrado que se abre lentamente hacia el suelo',
     'Ceniza de vela apagada a medianoche',
     'experimental', 0, 0,
     '2026-02-14T00:00:00Z', NULL);

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
-- `patriarch_id` se deja NULL en este INSERT y se fija más abajo: la clave
-- foránea exige que el tutor exista ya en `users`, y las semillas de linaje
-- preceden a las de los iniciados.
INSERT INTO clans (id, slug, name, motto, created_at,
                   coat_of_arms, lineage_type, admission_mode, status,
                   weekly_points, historical_points, last_activity_at, updated_at) VALUES
    ('cln_primordial', 'custodios-del-fuego-primordial',
     'Custodios del Fuego Primordial',
     'Antes de la primera palabra, ya ardimos.', '2026-01-01T00:00:00Z',
     'rune_flame_shield', 'primordialFlame', 'open', 'active',
     0, 0, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- ---------------------------------------------------------------------
-- Tutor de los Pergaminos Primordiales (TASKS-04, Tarea 1.1): la
-- ampliación de `spells` exige autoría (author_id NOT NULL, FK a
-- users), así que los conjuros génesis necesitan un forjador mítico de
-- latencia fundacional. Rol 'master' (solo lectura operativa; su
-- password_hash es un placeholder NO verificable, jamás un vínculo).
-- ---------------------------------------------------------------------
INSERT INTO users (id, alias, email, password_hash, role, clan_id, created_at, updated_at) VALUES
    ('usr_custodio_primordial', 'El Custodio Primordial',
     'custodio@primordialis.arc', 'x', 'master', 'cln_primordial',
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- La corona del linaje fundacional, ya con el tutor inscrito en `users`.
UPDATE clans SET patriarch_id = 'usr_custodio_primordial'
 WHERE id = 'cln_primordial';

-- Afiliación del tutor en la AUTORIDAD (`clan_members`). El mundo sembrado
-- ha de ser coherente con la reconciliación: `users.clan_id` es solo el
-- espejo denormalizado de esta fila, y el Patriarca pertenece a su casa
-- (RF-01.3). Sin esta fila, el linaje fundacional quedaría acéfalo y el
-- tutor figuraría en un clan al que no pertenece.
INSERT INTO clan_members (id, clan_id, user_id, role, joined_at, left_at, convalescence_expires_at) VALUES
    ('clm_custodio_primordial', 'cln_primordial', 'usr_custodio_primordial',
     'patriarch', '2026-01-01T00:00:00Z', NULL, NULL);

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
-- (daño 5 → base 5 × multip. 1.0 − descuento 30% (3 comp.) → ceil(3.5) →
--  suelo de 5: mana_cost 5, Círculo I — coherente con Artículo II)
INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                    mana_cost, circle, math_fingerprint, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material,
                    status, validation_signatures_count, signatures_count, is_genesis_sample,
                    created_at, updated_at, validated_at) VALUES
    ('spl_genesis_01', 'chispa-de-ignicion', 'Chispa de Ignición',
     'usr_custodio_primordial', 'evocation', 'fire', 'action',
     5, 1, '4f36b15ca6c36f1128450e89afc5fb40e3c0997220e7d595af255f3484132cb9', 'cln_primordial',
     'Conjuro fundacional que canaliza la llama más pura para encender candelas o disipar sombras.',
     'El primer conjuro que todo aprendiz traza en su grimorio. Reúne el maná ambiental en la yema de los dedos y lo libera como una llama serena, incapaz de quemar más allá de lo que el corazón ordene. Los Custodios lo enseñan como lección de humildad: antes de domar incendios, hay que saber encender una vela.',
     'Ignis Primordialis, lucem manifestare',
     'Pulgar e índice unidos frente al pecho, descendiendo en arco sereno',
     'Una vela de cera virgen sin encender',
     5, 0, 0, 'none', 'touch', 'singleTarget', 'instant',
     1, 1, 1,
     'validated', 3, 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Pergamino Primordial II — Escuela de Abjuración
-- (barrera 0 + control 'slow' (8 pts) × 1.0 − 30% → ceil(5.6) →
--  suelo de 5… con base 8: neto 5.6 → 6; mana_cost 6, Círculo I)
INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                    mana_cost, circle, math_fingerprint, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material,
                    status, validation_signatures_count, signatures_count, is_genesis_sample,
                    created_at, updated_at, validated_at) VALUES
    ('spl_genesis_02', 'manto-de-niebla', 'Manto de Niebla',
     'usr_custodio_primordial', 'abjuration', 'water', 'action',
     6, 1, '7e38761e15393de590db24fa9c38595e1d414566789b8147c8415618ccdcf8a0', 'cln_primordial',
     'Velo de bruma arcano que envuelve al caminante y difumina su silueta ante miradas ajenas.',
     'Tejido a partir del aliento de la montaña al alba, este velo de bruma se pega a la piel del invocador como un vestido de niebla viva. No oculta con tinieblas, sino con la suavidad de lo que nadie piensa mirar dos veces. Dura lo que tarde en disiparse una taza de té humeante.',
     'Caligo velamen, praesentia dissolves',
     'Ambas palmas abiertas cruzando el rostro de izquierda a derecha',
     'Un pañuelo empapado en rocío de madrugada',
     0, 0, 0, 'slow', 'touch', 'singleTarget', 'instant',
     1, 1, 1,
     'validated', 3, 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Pergamino Primordial III — Escuela de Adivinación
-- (sin efectos base con peso ni control: 0 pts → neto 0 → suelo de 5:
--  mana_cost 5, Círculo I — la lección de humildad del canon)
INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                    mana_cost, circle, math_fingerprint, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material,
                    status, validation_signatures_count, signatures_count, is_genesis_sample,
                    created_at, updated_at, validated_at) VALUES
    ('spl_genesis_03', 'susurro-del-viento', 'Susurro del Viento',
     'usr_custodio_primordial', 'divination', 'wind', 'ritual',
     5, 1, 'f4b1747d7b8b1ae04d99287bac31bc939d5815fea810a0f879dc553a0353a9ee', 'cln_primordial',
     'Llama a la corriente de aire cercana para que traiga fragmentos de conversaciones lejanas.',
     'El viento viaja y escucha; este conjuro le pide amablemente que repita. La corriente que responde trae palabras sueltas de lugares cercanos, como ecos arrastrados por un cañón. Los Custodios advierten: el viento susurra lo que oyó, no lo que el oyente desea oír.',
     'Ventus loquax, verba longinqua ferto',
     'Mano abierta alzada con la palma hacia donde sopla la brisa',
     'Una pluma ligera que el viento pueda alzar',
     0, 0, 0, 'none', 'touch', 'singleTarget', 'instant',
     1, 1, 1,
     'validated', 3, 3, 1,
     '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

-- Borrador experimental de aprendiz (RF-03.2, Art. III): solo se revela
-- con la bandera includeExperimental=1; jamás en el catálogo público.
-- (daño 16 × 1.0 + barrera 12 × 1.2 = 30.4 pts → −30% (3 comp.) →
--  21.28 → ceil → mana_cost 22, Círculo II — coherente con Art. II)
INSERT INTO spells (id, slug, name, author_id, magic_school, elemental_affinity, casting_time,
                    mana_cost, circle, math_fingerprint, clan_id,
                    summary, description,
                    components_verbal, components_somatic, components_material,
                    damage, healing, barrier, crowd_control_type, range_type, area_type, duration_type,
                    has_verbal, has_somatic, has_material,
                    status, validation_signatures_count, signatures_count, is_genesis_sample,
                    created_at, updated_at, validated_at) VALUES
    ('spl_draft_01', 'boceto-prohibido', 'Boceto Prohibido',
     'usr_custodio_primordial', 'necromancy', 'shadow', 'action',
     22, 2, 'eacda1bf4cd34025332ef2f340a5f8b0c952b8a7d7bd35791a42aa720d8807c8', 'cln_primordial',
     'Rasgo inestable que arranca fragmentos de sombra ajenos sin forma aún definida.',
     'Conjuro sin concluir hallado entre las anotaciones de un aprendiz desaparecido. Las sombras convocadas obedecen a medias: se retuercen, susurran y se disuelven sin orden. Ningún Maestro ha querido firmar aún su estabilidad; su estudio se considera riesgo de Inestabilidad Arcana.',
     'Umbra fragilis, ad me veni',
     'Puño cerrado que se abre lentamente hacia el suelo',
     'Ceniza de vela apagada a medianoche',
     16, 0, 12, 'none', 'touch', 'singleTarget', 'instant',
     1, 1, 1,
     'experimental', 0, 0, 0,
     '2026-02-14T00:00:00Z', '2026-02-14T00:00:00Z', NULL);

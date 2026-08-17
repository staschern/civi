-- =====================================================================
--  Civi · Этап 1 · миграция 0001
--  Хранение версий парных деревьев развития (наука + культура).
--
--  Логика в двух словах:
--    * trees / eras / branches / technologies / technology_prereqs —
--      каталог. Живёт вне версий, помечен флагом is_standard.
--      Стандартный набор = всё, у чего is_standard = 1.
--    * стоимость: base_cost в каталоге — ручная, cost у карточки версии —
--      рассчитанная. Средняя стоимость первого столбца версии — cost_base,
--      следующий столбец внутри эпохи дороже в cost_step_lane раз, первый
--      столбец новой эпохи — в cost_step_era раз (по умолчанию 60, 1.1
--      и 1.5). Внутри столбца стоимости расходятся случайно, но так,
--      что диапазоны соседних столбцов не пересекаются.
--    * effect_types / technology_effects — что технология добавляет в игру
--      (ресурс, юнит, уровень здания, концепция, карточка, ускорение
--      добычи…). Свойство технологии, общее для всех версий.
--    * tree_versions / tree_version_eras / tree_version_era_lanes /
--      tree_version_nodes / tree_version_links — снимок конкретной
--      сгенерированной (и, возможно, доработанной руками) пары деревьев.
--
--  Первоначальная генерация версии берёт из каталога ровно то,
--  что помечено is_standard = 1, и ничего больше. Технологию, добавленную
--  руками в одну версию, в каталоге держим с is_standard = 0: её всегда
--  можно найти поиском и вручную добавить в любую другую версию,
--  но сама она туда при генерации не попадёт, пока ей не поставят
--  is_standard = 1.
--
--  MySQL 8.0.16+ / MariaDB 10.2+ — нужны тип JSON и рабочие CHECK-ограничения
--  (на MySQL 5.7 миграция применится, но CHECK будут молча проигнорированы).
--  Движок InnoDB, кодировка utf8mb4.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  Каталог
-- ---------------------------------------------------------------------

--  Деревья развития. Отдельной таблицей, а не ENUM: в проектном документе
--  открытым вопросом висит третье дерево (вера/религия) — его добавление
--  не должно требовать ALTER больших таблиц.
CREATE TABLE trees (
  id           TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(32)      NOT NULL COMMENT 'science, culture, …',
  name         VARCHAR(190)     NOT NULL,
  points_name  VARCHAR(64)      NOT NULL COMMENT 'за что качается: Наука / Культура',
  boost_name   VARCHAR(64)      NOT NULL COMMENT 'ускорители: Озарения / Вдохновения',
  position     TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'порядок вывода на экране',
  created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trees_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Каталог эпох. Общий для всех деревьев: сетка эпох у науки и культуры
--  одна и та же. default_position задаёт стандартную последовательность,
--  которая используется при первоначальной генерации любой версии.
CREATE TABLE eras (
  id               SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(64)       NOT NULL COMMENT 'stone, bronze, antiquity, …',
  name             VARCHAR(190)      NOT NULL,
  period           VARCHAR(64)           NULL COMMENT 'исторические рамки: «~3300–1200 до н. э.»',
  default_position SMALLINT UNSIGNED NOT NULL COMMENT 'место в стандартной сетке, 1..N',
  is_standard      TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '1 — входит в стандартный набор',
  created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_eras_code (code),
  KEY idx_eras_standard (is_standard, default_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Категории технологий, они же ветки (смысловые направления):
--  «Наука и приборы», «Материалы и металлургия», «Сельское хозяйство», …
--  Категория принадлежит конкретному дереву, коды уникальны внутри дерева.
--  Название и цвет правятся через админку, категории можно добавлять.
CREATE TABLE branches (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tree_id     TINYINT UNSIGNED  NOT NULL,
  code        VARCHAR(64)       NOT NULL,
  name        VARCHAR(190)      NOT NULL,
  color       CHAR(7)           NOT NULL COMMENT 'цвет карточки, #rrggbb',
  description VARCHAR(500)          NULL,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'порядок в легенде',
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_tree_code (tree_id, code),
  KEY idx_branches_position (tree_id, position),
  -- составной ключ нужен, чтобы технология не смогла сослаться на ветку чужого дерева
  UNIQUE KEY uq_branches_id_tree (id, tree_id),
  CONSTRAINT fk_branches_tree FOREIGN KEY (tree_id) REFERENCES trees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Каталог технологий и социальных концепций. Одна таблица на оба дерева,
--  принадлежность задаётся tree_id. Здесь же карточка технологии:
--  картинка, описание и историческая справка.
CREATE TABLE technologies (
  id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  tree_id         TINYINT UNSIGNED  NOT NULL,
  code            VARCHAR(96)       NOT NULL COMMENT 'машинный код, t_siege_antiquity',
  name            VARCHAR(190)      NOT NULL,
  branch_id       SMALLINT UNSIGNED NOT NULL COMMENT 'категория технологии',
  default_era_id  SMALLINT UNSIGNED NOT NULL COMMENT 'эпоха по умолчанию при генерации',
  is_standard     TINYINT(1)        NOT NULL DEFAULT 1 COMMENT '1 — входит в стандартный набор',
  base_cost       BIGINT UNSIGNED       NULL COMMENT 'ручная стоимость; пусто — считает генератор по столбцу',
  image_path      VARCHAR(255)          NULL COMMENT 'путь к картинке относительно веб-корня',
  description     TEXT                  NULL COMMENT 'описание технологии для игрока',
  historical_note TEXT                  NULL COMMENT 'историческая справка',
  created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_technologies_code (code),
  -- составной ключ нужен, чтобы карточка версии не смогла приписать
  -- технологию не тому дереву
  UNIQUE KEY uq_technologies_id_tree (id, tree_id),
  KEY idx_technologies_standard (tree_id, is_standard),
  KEY idx_technologies_era (default_era_id),
  KEY idx_technologies_name (name),
  CONSTRAINT fk_technologies_tree   FOREIGN KEY (tree_id)        REFERENCES trees (id),
  CONSTRAINT fk_technologies_branch FOREIGN KEY (branch_id, tree_id)
    REFERENCES branches (id, tree_id),
  CONSTRAINT fk_technologies_era    FOREIGN KEY (default_era_id) REFERENCES eras (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Авторские (заданные вручную при проектировании) зависимости каталога.
--  Именно они переносятся в новую версию как связи origin = 'standard';
--  остальные связи достраивает генератор по правилу столбцов.
CREATE TABLE technology_prereqs (
  technology_id        INT UNSIGNED NOT NULL COMMENT 'что открывается',
  prereq_technology_id INT UNSIGNED NOT NULL COMMENT 'что для этого нужно',
  PRIMARY KEY (technology_id, prereq_technology_id),
  KEY idx_technology_prereqs_reverse (prereq_technology_id),
  CONSTRAINT chk_technology_prereqs_no_self CHECK (technology_id <> prereq_technology_id),
  CONSTRAINT fk_technology_prereqs_tech   FOREIGN KEY (technology_id)
    REFERENCES technologies (id) ON DELETE CASCADE,
  CONSTRAINT fk_technology_prereqs_prereq FOREIGN KEY (prereq_technology_id)
    REFERENCES technologies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Виды игровых эффектов: что технология даёт игре. Справочник, а не ENUM —
--  список заведомо будет расширяться, и добавлять новый вид нужно из админки,
--  без миграций. payload_schema подсказывает интерфейсу, какие поля
--  спрашивать у конкретного вида (например, у «ускорения добычи» — ресурс
--  и процент).
CREATE TABLE effect_types (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(64)       NOT NULL COMMENT 'resource, unit, building_level, …',
  name           VARCHAR(190)      NOT NULL,
  description    VARCHAR(500)          NULL,
  payload_schema JSON                  NULL COMMENT 'описание ожидаемых полей payload',
  position       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active      TINYINT(1)        NOT NULL DEFAULT 1,
  created_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_effect_types_code (code),
  KEY idx_effect_types_position (is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Что конкретная технология добавляет в игру: новый ресурс, юнит, уровень
--  здания, концепцию, карточку, ускорение добычи и так далее.
--
--  Свойство технологии, а не версии дерева: эффекты не переписываются
--  при каждой генерации. Конкретные параметры лежат в payload (JSON),
--  поэтому новый вид эффекта со своим набором полей не требует миграции.
CREATE TABLE technology_effects (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  technology_id  INT UNSIGNED      NOT NULL,
  effect_type_id SMALLINT UNSIGNED NOT NULL,
  title          VARCHAR(190)      NOT NULL COMMENT 'как показываем в карточке',
  description    TEXT                  NULL,
  payload        JSON                  NULL COMMENT 'параметры вида эффекта',
  position       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_technology_effects_tech (technology_id, position),
  KEY idx_technology_effects_type (effect_type_id),
  CONSTRAINT fk_technology_effects_tech FOREIGN KEY (technology_id)
    REFERENCES technologies (id) ON DELETE CASCADE,
  CONSTRAINT fk_technology_effects_type FOREIGN KEY (effect_type_id)
    REFERENCES effect_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Версии деревьев
-- ---------------------------------------------------------------------

--  Одна строка = одна пара деревьев (наука + культура), сгенерированная
--  по семени и, возможно, доработанная руками.
--
--  Семя — набор чисел: отдельное число на раскладку каждого дерева
--  и одно на разбиение эпох по столбцам. seed_code — их канонический
--  человекочитаемый вид ('4821-7719-3045'), по нему и ищем ранее
--  сгенерированные пары. Индекс намеренно НЕ уникальный: от одного семени
--  можно отпочковать несколько версий с разной ручной правкой,
--  родство при этом хранится в parent_version_id.
CREATE TABLE tree_versions (
  id                INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  name              VARCHAR(190)           NULL COMMENT 'читаемое имя для поиска, может отсутствовать',
  seed_code         VARCHAR(64)        NOT NULL COMMENT 'канонический вид семени, 4821-7719-3045',
  seed_numbers      JSON               NOT NULL COMMENT 'то же семя числами: [4821, 7719, 3045]',
  seed_science      BIGINT UNSIGNED    NOT NULL COMMENT 'семя раскладки дерева технологий',
  seed_culture      BIGINT UNSIGNED    NOT NULL COMMENT 'семя раскладки дерева соц. концепций',
  seed_layout       BIGINT UNSIGNED    NOT NULL COMMENT 'семя разбиения эпох на столбцы',
  generator_version VARCHAR(32)        NOT NULL DEFAULT '1' COMMENT 'версия алгоритма генерации',
  -- стоимость технологии считается от столбца: средняя стоимость первого
  -- столбца — cost_base, дальше цена растёт полого внутри эпохи и делает
  -- заметный шаг на её границе
  cost_base         INT UNSIGNED       NOT NULL DEFAULT 60
                      COMMENT 'средняя стоимость технологии первого столбца',
  cost_step_lane    DECIMAL(4,2)       NOT NULL DEFAULT 1.10
                      COMMENT 'во сколько раз дороже следующий столбец внутри эпохи',
  cost_step_era     DECIMAL(4,2)       NOT NULL DEFAULT 1.50
                      COMMENT 'во сколько раз дороже первый столбец следующей эпохи',
  status            ENUM('generated','edited','archived') NOT NULL DEFAULT 'generated',
  parent_version_id INT UNSIGNED           NULL COMMENT 'от какой версии отпочковались',
  notes             TEXT                   NULL,
  created_at        TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tree_versions_name (name),
  KEY idx_tree_versions_seed (seed_code),
  KEY idx_tree_versions_seed_numbers (seed_science, seed_culture, seed_layout),
  KEY idx_tree_versions_created (created_at),
  CONSTRAINT fk_tree_versions_parent FOREIGN KEY (parent_version_id)
    REFERENCES tree_versions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Эпохи внутри версии: их можно добавлять и удалять, не трогая каталог.
--  При первоначальной генерации сюда копируются все эпохи с is_standard = 1
--  в порядке default_position — стандартная сетка воспроизводится как есть.
--  position индексируется, но не уникален: перестановка эпох не должна
--  требовать промежуточных значений.
CREATE TABLE tree_version_eras (
  id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  version_id    INT UNSIGNED      NOT NULL,
  era_id        SMALLINT UNSIGNED NOT NULL,
  position      SMALLINT UNSIGNED NOT NULL COMMENT 'порядок эпохи в этой версии, 1..N',
  name_override VARCHAR(190)          NULL COMMENT 'если эпоху переименовали только в этой версии',
  PRIMARY KEY (id),
  UNIQUE KEY uq_tree_version_eras (version_id, era_id),
  -- составной ключ нужен, чтобы карточка не встала в эпоху чужой версии
  UNIQUE KEY uq_tree_version_eras_id_version (id, version_id),
  KEY idx_tree_version_eras_position (version_id, position),
  CONSTRAINT fk_tree_version_eras_version FOREIGN KEY (version_id)
    REFERENCES tree_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_tree_version_eras_era FOREIGN KEY (era_id)
    REFERENCES eras (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Сколько столбцов внутри эпохи у каждого дерева. Число бросается
--  случайно при генерации (минимум 2, каждый следующий — с меньшей
--  вероятностью), поэтому у науки и культуры оно своё.
CREATE TABLE tree_version_era_lanes (
  version_era_id INT UNSIGNED     NOT NULL,
  tree_id        TINYINT UNSIGNED NOT NULL,
  lanes          TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'число столбцов внутри эпохи',
  PRIMARY KEY (version_era_id, tree_id),
  CONSTRAINT fk_tvel_version_era FOREIGN KEY (version_era_id)
    REFERENCES tree_version_eras (id) ON DELETE CASCADE,
  CONSTRAINT fk_tvel_tree FOREIGN KEY (tree_id) REFERENCES trees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Карточка на доске конкретной версии: какая технология, в какой эпохе,
--  в каком столбце этой эпохи и на каком месте внутри столбца.
--
--  source = 'standard' — попала сюда при генерации из стандартного набора;
--  source = 'manual'   — добавлена в эту версию руками.
--  global_column — сквозной номер столбца через все эпохи (денормализация
--  ради выборки и проверки правила «связь из предыдущего столбца»);
--  пересчитывается генератором из position эпохи и lane.
CREATE TABLE tree_version_nodes (
  id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  version_id     INT UNSIGNED      NOT NULL,
  tree_id        TINYINT UNSIGNED  NOT NULL,
  technology_id  INT UNSIGNED      NOT NULL,
  version_era_id INT UNSIGNED      NOT NULL,
  lane           SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'столбец внутри эпохи, 0..lanes-1',
  row_index      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'место внутри столбца, сверху вниз',
  global_column  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'сквозной номер столбца, 0..M',
  cost           BIGINT UNSIGNED   NOT NULL DEFAULT 0
    COMMENT 'стоимость на этой доске: растёт от столбца к столбцу',
  source         ENUM('standard','manual') NOT NULL DEFAULT 'standard',
  is_relaxed     TINYINT(1)        NOT NULL DEFAULT 0
    COMMENT '1 — первый столбец эпохи, которому разрешили опереться глубже предыдущей эпохи',
  created_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tree_version_nodes (version_id, technology_id),
  -- составной ключ нужен, чтобы связь не соединила карточки разных версий
  UNIQUE KEY uq_tree_version_nodes_id_version (id, version_id),
  KEY idx_tree_version_nodes_board (version_id, tree_id, global_column, row_index),
  KEY idx_tree_version_nodes_era (version_era_id),
  KEY idx_tree_version_nodes_tech (technology_id, tree_id),
  CONSTRAINT fk_tvn_version FOREIGN KEY (version_id)
    REFERENCES tree_versions (id) ON DELETE CASCADE,
  CONSTRAINT fk_tvn_tree FOREIGN KEY (tree_id) REFERENCES trees (id),
  CONSTRAINT fk_tvn_technology FOREIGN KEY (technology_id, tree_id)
    REFERENCES technologies (id, tree_id),
  CONSTRAINT fk_tvn_version_era FOREIGN KEY (version_era_id, version_id)
    REFERENCES tree_version_eras (id, version_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--  Связи на доске конкретной версии. Ссылаются на карточки, а не на каталог:
--  одна и та же технология в разных версиях может опираться на разное.
--
--  origin = 'standard'  — перенесена из technology_prereqs;
--  origin = 'generated' — достроена генератором ради правила
--                         «минимум одна связь из предыдущего столбца»;
--  origin = 'manual'    — проставлена руками в этой версии.
CREATE TABLE tree_version_links (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id   INT UNSIGNED    NOT NULL,
  from_node_id BIGINT UNSIGNED NOT NULL COMMENT 'основа (лежит левее)',
  to_node_id   BIGINT UNSIGNED NOT NULL COMMENT 'что открывается',
  origin       ENUM('standard','generated','manual') NOT NULL DEFAULT 'generated',
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tree_version_links (version_id, from_node_id, to_node_id),
  KEY idx_tree_version_links_to (to_node_id, version_id),
  CONSTRAINT chk_tvl_no_self_link CHECK (from_node_id <> to_node_id),
  -- обе карточки обязаны принадлежать той же версии, что и связь
  CONSTRAINT fk_tvl_from FOREIGN KEY (from_node_id, version_id)
    REFERENCES tree_version_nodes (id, version_id) ON DELETE CASCADE,
  CONSTRAINT fk_tvl_to FOREIGN KEY (to_node_id, version_id)
    REFERENCES tree_version_nodes (id, version_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

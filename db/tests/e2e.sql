-- Сквозная проверка сценария релиза: генерация из стандартного набора,
-- ручное добавление, сохранение, загрузка, поиск по семени.

START TRANSACTION;

INSERT INTO tree_versions (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout)
VALUES ('Основная ветка баланса', '4821-7719-3045', '[4821, 7719, 3045]', 4821, 7719, 3045);
SET @v = LAST_INSERT_ID();

-- стандартная сетка эпох
INSERT INTO tree_version_eras (version_id, era_id, position)
SELECT @v, id, default_position FROM eras WHERE is_standard = 1 ORDER BY default_position;

-- столбцы на эпоху (в проде бросает генератор по seed_layout)
INSERT INTO tree_version_era_lanes (version_era_id, tree_id, lanes)
SELECT tve.id, t.id, 2 + ((tve.position * 7 + t.id * 3) % 3)
  FROM tree_version_eras tve CROSS JOIN trees t
 WHERE tve.version_id = @v;

-- карточки: весь стандартный набор, с раскидкой по столбцам и сквозной нумерацией
INSERT INTO tree_version_nodes
       (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
WITH placed AS (
  SELECT t.tree_id, t.id AS technology_id, tve.id AS version_era_id, tve.position,
         lanes.lanes,
         (ROW_NUMBER() OVER (PARTITION BY t.tree_id, tve.id ORDER BY t.id) - 1) AS seq
    FROM technologies t
    JOIN tree_version_eras tve ON tve.version_id = @v AND tve.era_id = t.default_era_id
    JOIN tree_version_era_lanes lanes ON lanes.version_era_id = tve.id AND lanes.tree_id = t.tree_id
   WHERE t.is_standard = 1
),
laned AS (
  SELECT p.*, (p.seq % p.lanes) AS lane,
         FLOOR(p.seq / p.lanes) AS row_index
    FROM placed p
),
offsets AS (
  SELECT tve.id AS version_era_id, l.tree_id,
         COALESCE(SUM(l2.lanes), 0) AS lanes_before
    FROM tree_version_eras tve
    JOIN tree_version_era_lanes l ON l.version_era_id = tve.id
    LEFT JOIN tree_version_eras tve2
           ON tve2.version_id = tve.version_id AND tve2.position < tve.position
    LEFT JOIN tree_version_era_lanes l2
           ON l2.version_era_id = tve2.id AND l2.tree_id = l.tree_id
   WHERE tve.version_id = @v
   GROUP BY tve.id, l.tree_id
)
SELECT @v, d.tree_id, d.technology_id, d.version_era_id, d.lane, d.row_index,
       o.lanes_before + d.lane, 'standard'
  FROM laned d
  JOIN offsets o ON o.version_era_id = d.version_era_id AND o.tree_id = d.tree_id;

-- авторские связи каталога
INSERT INTO tree_version_links (version_id, from_node_id, to_node_id, origin)
SELECT @v, src.id, dst.id, 'standard'
  FROM technology_prereqs p
  JOIN tree_version_nodes dst ON dst.version_id = @v AND dst.technology_id = p.technology_id
  JOIN tree_version_nodes src ON src.version_id = @v AND src.technology_id = p.prereq_technology_id;

COMMIT;

SELECT '--- версия сохранена ---' AS step;
SELECT (SELECT COUNT(*) FROM tree_version_eras  WHERE version_id = @v) AS eras,
       (SELECT COUNT(*) FROM tree_version_nodes WHERE version_id = @v) AS nodes,
       (SELECT COUNT(*) FROM tree_version_links WHERE version_id = @v) AS links;

-- ручное добавление технологии вне стандартного набора
INSERT INTO technologies (tree_id, code, name, branch_id, default_era_id, is_standard, notes)
SELECT 1, 't_custom_glassblowing', 'Стеклодувное дело',
       (SELECT id FROM branches WHERE tree_id = 1 AND code = 'materials'),
       (SELECT id FROM eras WHERE code = 'antiquity'), 0, 'добавлено вручную в версии 1';
SET @custom = LAST_INSERT_ID();

INSERT INTO tree_version_nodes
       (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
SELECT @v, 1, @custom, tve.id, 0, 99, 0, 'manual'
  FROM tree_version_eras tve JOIN eras e ON e.id = tve.era_id
 WHERE tve.version_id = @v AND e.code = 'antiquity';

UPDATE tree_versions SET status = 'edited' WHERE id = @v;

SELECT '--- вторая версия: берёт только стандартный набор ---' AS step;
INSERT INTO tree_versions (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout, parent_version_id)
VALUES (NULL, '9001-1234-5678', '[9001, 1234, 5678]', 9001, 1234, 5678, @v);
SET @v2 = LAST_INSERT_ID();

INSERT INTO tree_version_eras (version_id, era_id, position)
SELECT @v2, id, default_position FROM eras WHERE is_standard = 1;

INSERT INTO tree_version_nodes
       (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
SELECT @v2, t.tree_id, t.id, tve.id, 0, 0, 0, 'standard'
  FROM technologies t
  JOIN tree_version_eras tve ON tve.version_id = @v2 AND tve.era_id = t.default_era_id
 WHERE t.is_standard = 1;

SELECT v.id, COALESCE(v.name, '(без имени)') AS name, v.seed_code, v.status,
       COUNT(n.id) AS nodes,
       SUM(n.source = 'manual') AS manual_nodes
  FROM tree_versions v LEFT JOIN tree_version_nodes n ON n.version_id = v.id
 GROUP BY v.id ORDER BY v.id;

SELECT '--- поиск по семени и по имени ---' AS step;
SELECT id, seed_code, name FROM tree_versions
 WHERE seed_code = '4821-7719-3045' OR name = 'Основная ветка баланса';

SELECT '--- загрузка доски: первые карточки дерева науки ---' AS step;
SELECT n.global_column, n.lane, n.row_index, t.name, b.name AS branch,
       COALESCE(tve.name_override, e.name) AS era, lanes.lanes AS era_lanes, n.source
  FROM tree_version_nodes n
  JOIN technologies t        ON t.id   = n.technology_id
  JOIN branches b            ON b.id   = t.branch_id
  JOIN tree_version_eras tve ON tve.id = n.version_era_id
  JOIN eras e                ON e.id   = tve.era_id
  LEFT JOIN tree_version_era_lanes lanes
         ON lanes.version_era_id = tve.id AND lanes.tree_id = n.tree_id
 WHERE n.version_id = @v AND n.tree_id = 1
 ORDER BY n.global_column, n.row_index LIMIT 6;

SELECT '--- ручная технология видна только в своей версии ---' AS step;
SELECT v.id AS version_id, COUNT(*) AS has_custom
  FROM tree_versions v
  JOIN tree_version_nodes n ON n.version_id = v.id AND n.technology_id = @custom
 GROUP BY v.id;

SELECT '--- поиск нестандартных технологий в каталоге ---' AS step;
SELECT id, code, name, is_standard FROM technologies WHERE is_standard = 0;

SELECT '--- каскадное удаление версии ---' AS step;
DELETE FROM tree_versions WHERE id = @v2;
SELECT (SELECT COUNT(*) FROM tree_version_eras  WHERE version_id = @v2) AS eras_left,
       (SELECT COUNT(*) FROM tree_version_nodes WHERE version_id = @v2) AS nodes_left,
       (SELECT COUNT(*) FROM technologies) AS catalog_intact;

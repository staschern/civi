-- Карточка не может встать в эпоху, принадлежащую другой версии.
-- Ожидается: fk_tvn_version_era (version_era_id, version_id)
START TRANSACTION;
-- отдельная технология, которой в версии 1 ещё нет, иначе первым сработает
-- ограничение на дубль технологии внутри версии
INSERT INTO technologies (tree_id, code, name, branch_id, default_era_id, is_standard)
VALUES (1, 't_probe_foreign_era', 'Проба', 1, 1, 0);
SET @probe = LAST_INSERT_ID();
INSERT INTO tree_versions (seed_code, seed_numbers, seed_science, seed_culture, seed_layout)
VALUES ('0-0-1', '[0, 0, 1]', 0, 0, 1);
SET @other = LAST_INSERT_ID();
INSERT INTO tree_version_eras (version_id, era_id, position) VALUES (@other, 1, 1);
SET @other_era = LAST_INSERT_ID();
-- эпоха чужой версии, карточка своей
INSERT INTO tree_version_nodes (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column)
VALUES (1, 1, @probe, @other_era, 0, 902, 0);

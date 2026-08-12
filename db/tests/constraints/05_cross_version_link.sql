-- Связь не может соединить карточки из разных версий.
-- Ожидается: fk_tvl_to (to_node_id, version_id)
START TRANSACTION;
INSERT INTO tree_versions (seed_code, seed_numbers, seed_science, seed_culture, seed_layout)
VALUES ('0-0-2', '[0, 0, 2]', 0, 0, 2);
SET @other = LAST_INSERT_ID();
INSERT INTO tree_version_eras (version_id, era_id, position) VALUES (@other, 1, 1);
INSERT INTO tree_version_nodes (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column)
VALUES (@other, 1, 2, LAST_INSERT_ID(), 0, 0, 0);
SET @other_node = LAST_INSERT_ID();
INSERT INTO tree_version_links (version_id, from_node_id, to_node_id)
SELECT 1, id, @other_node FROM tree_version_nodes WHERE version_id = 1 LIMIT 1;

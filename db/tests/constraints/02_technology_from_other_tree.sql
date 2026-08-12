-- Технологию науки нельзя положить на доску дерева культуры.
-- Ожидается: fk_tvn_technology (technology_id, tree_id)
START TRANSACTION;
INSERT INTO technologies (tree_id, code, name, branch_id, default_era_id, is_standard)
VALUES (1, 't_probe_wrong_tree', 'Проба', 1, 1, 0);
INSERT INTO tree_version_nodes (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column)
SELECT 1, 2, LAST_INSERT_ID(), (SELECT id FROM tree_version_eras WHERE version_id = 1 LIMIT 1), 0, 901, 0;

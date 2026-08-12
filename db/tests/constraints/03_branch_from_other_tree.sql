-- Технология не может ссылаться на ветку чужого дерева.
-- Ожидается: fk_technologies_branch (branch_id, tree_id)
START TRANSACTION;
INSERT INTO technologies (tree_id, code, name, branch_id, default_era_id, is_standard)
VALUES (1, 't_probe_wrong_branch', 'Проба', (SELECT id FROM branches WHERE tree_id = 2 LIMIT 1), 1, 0);

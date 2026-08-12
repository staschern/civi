-- Одна эпоха не может входить в версию дважды.
-- Ожидается: uq_tree_version_eras
START TRANSACTION;
INSERT INTO tree_version_eras (version_id, era_id, position)
SELECT version_id, era_id, 99 FROM tree_version_eras WHERE version_id = 1 LIMIT 1;

-- Одна технология не может лежать в версии дважды.
-- Ожидается: uq_tree_version_nodes
START TRANSACTION;
INSERT INTO tree_version_nodes (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column)
SELECT version_id, tree_id, technology_id, version_era_id, 0, 900, 0
  FROM tree_version_nodes WHERE version_id = 1 LIMIT 1;

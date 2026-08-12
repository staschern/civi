-- Технология не может зависеть сама от себя.
-- Ожидается: chk_tvl_no_self_link
START TRANSACTION;
INSERT INTO tree_version_links (version_id, from_node_id, to_node_id)
SELECT 1, id, id FROM tree_version_nodes WHERE version_id = 1 LIMIT 1;

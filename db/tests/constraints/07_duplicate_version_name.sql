-- Читаемое имя версии уникально, когда задано.
-- Ожидается: uq_tree_versions_name
START TRANSACTION;
INSERT INTO tree_versions (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout)
VALUES ('Основная ветка баланса', '0-0-3', '[0, 0, 3]', 0, 0, 3);

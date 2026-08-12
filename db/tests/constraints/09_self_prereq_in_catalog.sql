-- Каталожная связь технологии на саму себя.
-- Ожидается: chk_technology_prereqs_no_self
START TRANSACTION;
INSERT INTO technology_prereqs (technology_id, prereq_technology_id) VALUES (5, 5);

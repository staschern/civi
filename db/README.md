# База данных: версии деревьев развития

Миграция: [`migrations/0001_create_tech_tree_versions.sql`](migrations/0001_create_tech_tree_versions.sql).
Одна миграция, в ней схема, стандартный набор каталога и готовая базовая
пара деревьев.

```bash
mysql -u root -p civi < db/migrations/0001_create_tech_tree_versions.sql
```

Файл миграции собирается скриптом и руками не правится. Источники:

| Файл | Что задаёт |
|---|---|
| [`schema/0001_schema.sql`](schema/0001_schema.sql) | схему, правится руками |
| [`catalog/eras.txt`](catalog/eras.txt) | эпохи и их исторические рамки |
| [`catalog/categories.txt`](catalog/categories.txt) | категории технологий с цветами |
| [`catalog/trees.json`](catalog/trees.json) | технологии с описанием и справкой, авторские связи и готовую раскладку по столбцам |

```bash
node tools/validate-catalog.js       # проверяем источники
node tools/generate-migration.js     # правим источники — и пересобираем
```

Кроме каталога миграция кладёт версию №1 «Базовые деревья»: ту самую пару
деревьев из `catalog/trees.json` со всеми связями, столбцами и посчитанными
стоимостями. Сразу после наката админка открывает её как есть, генерировать
ничего не нужно.

## Стоимость технологии

Средняя стоимость первого столбца версии — `tree_versions.cost_base`
(по умолчанию 60), каждый следующий столбец дороже предыдущего
в `tree_versions.cost_step` раз (по умолчанию 1.3). Внутри столбца стоимости
расходятся случайно, но разброс считается от коэффициента так, чтобы
диапазоны соседних столбцов не пересекались: чем меньше коэффициент,
тем уже разброс. Оба числа правятся прямо на доске — коэффициент в панели
над деревьями, среднее — над каждым столбцом.

Прогон миграции и тестов на живой базе (база `civi_test` пересоздаётся):

```bash
MYSQL_ARGS="-h 127.0.0.1 -u root -proot" tools/run-db-tests.sh
```

Требования: MySQL 8.0.16+ или MariaDB 10.2+ — нужны тип `JSON` и рабочие
`CHECK`-ограничения. На MySQL 5.7 миграция применится, но `CHECK` будут молча
проигнорированы. Движок InnoDB, кодировка utf8mb4.

## Что где лежит

Таблицы делятся на две группы.

**Каталог** — живёт вне версий, правится редко, помечен флагом `is_standard`:

| Таблица | Смысл |
|---|---|
| `trees` | деревья развития: `science`, `culture` (третье добавляется строкой, без ALTER) |
| `eras` | каталог эпох, `default_position` задаёт стандартную последовательность |
| `branches` | ветки внутри дерева: осадное дело, право, металлургия… |
| `technologies` | технологии и социальные концепции, обе — в одной таблице, различаются `tree_id` |
| `technology_prereqs` | авторские зависимости каталога, проставленные вручную при проектировании |

**Версии** — снимок конкретной пары деревьев:

| Таблица | Смысл |
|---|---|
| `tree_versions` | версия = пара деревьев (наука + культура), семя, имя, родитель |
| `tree_version_eras` | эпохи этой версии и их порядок; можно добавлять и удалять |
| `tree_version_era_lanes` | сколько столбцов внутри эпохи у каждого дерева |
| `tree_version_nodes` | карточка на доске: эпоха, столбец, место в столбце |
| `tree_version_links` | связи между карточками этой версии |

## Стандартный набор

Стандартный набор — это всё, у чего `is_standard = 1`. Миграция помечает так
все 15 эпох и все 623 позиции каталога (327 технологий + 296 соц. концепций),
то есть текущее содержимое прототипа целиком.

Первоначальная генерация версии берёт из каталога ровно это и ничего больше:

```sql
SELECT id, code, name, default_position FROM eras
 WHERE is_standard = 1 ORDER BY default_position;

SELECT id, code, name, branch_id, default_era_id FROM technologies
 WHERE is_standard = 1 AND tree_id = 1;
```

Технология, добавленная руками в одну версию, заводится в каталоге
с `is_standard = 0`. В другие версии при генерации она не попадёт, но
всегда находится поиском и добавляется вручную:

```sql
-- найти что угодно, включая нестандартное
SELECT t.id, t.code, t.name, t.is_standard, tr.code AS tree
  FROM technologies t JOIN trees tr ON tr.id = t.tree_id
 WHERE t.name LIKE CONCAT('%', ?, '%');

-- перевести в стандартный набор — с этого момента попадает во все новые версии
UPDATE technologies SET is_standard = 1 WHERE id = ?;
```

## Семя версии

Семя — набор чисел: по одному на раскладку каждого дерева и одно на разбиение
эпох по столбцам. `seed_code` — их канонический человекочитаемый вид, по нему
и ищут ранее сгенерированные пары:

```sql
SELECT id, name, seed_code, status, created_at
  FROM tree_versions
 WHERE seed_code = '4821-7719-3045'
    OR name = 'Основная ветка баланса'
 ORDER BY created_at DESC;
```

Индекс по `seed_code` намеренно не уникальный: от одного семени можно
отпочковать несколько версий с разной ручной правкой. Родство хранится
в `parent_version_id`, читаемое имя (`name`) уникально, когда задано.

## Генерация новой версии

```sql
START TRANSACTION;

-- 1. сама версия
INSERT INTO tree_versions (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout)
VALUES ('Основная ветка баланса', '4821-7719-3045', '[4821, 7719, 3045]', 4821, 7719, 3045);
SET @version_id = LAST_INSERT_ID();

-- 2. стандартная сетка эпох как есть
INSERT INTO tree_version_eras (version_id, era_id, position)
SELECT @version_id, id, default_position
  FROM eras WHERE is_standard = 1 ORDER BY default_position;

-- 3. число столбцов внутри каждой эпохи (бросает генератор по seed_layout)
INSERT INTO tree_version_era_lanes (version_era_id, tree_id, lanes)
VALUES (?, ?, ?);

-- 4. карточки: весь стандартный набор, эпоха берётся из каталога,
--    lane / row_index / global_column считает генератор
INSERT INTO tree_version_nodes
       (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
SELECT @version_id, t.tree_id, t.id, tve.id, ?, ?, ?, 'standard'
  FROM technologies t
  JOIN tree_version_eras tve ON tve.version_id = @version_id AND tve.era_id = t.default_era_id
 WHERE t.is_standard = 1;

-- 5. авторские связи каталога переносятся в версию как есть
INSERT INTO tree_version_links (version_id, from_node_id, to_node_id, origin)
SELECT @version_id, src.id, dst.id, 'standard'
  FROM technology_prereqs p
  JOIN tree_version_nodes dst ON dst.version_id = @version_id AND dst.technology_id = p.technology_id
  JOIN tree_version_nodes src ON src.version_id = @version_id AND src.technology_id = p.prereq_technology_id;

-- 6. связи, достроенные генератором ради правила «минимум одна связь
--    из предыдущего столбца», пишутся с origin = 'generated'

COMMIT;
```

## Загрузка версии на доску

Один запрос на карточки:

```sql
SELECT n.id, n.tree_id, n.lane, n.row_index, n.global_column, n.source, n.is_relaxed,
       t.code, t.name, b.code AS branch_code, b.name AS branch_name, b.color,
       tve.position AS era_position, COALESCE(tve.name_override, e.name) AS era_name,
       lanes.lanes AS era_lanes
  FROM tree_version_nodes n
  JOIN technologies t        ON t.id  = n.technology_id
  JOIN branches b            ON b.id  = t.branch_id
  JOIN tree_version_eras tve ON tve.id = n.version_era_id
  JOIN eras e                ON e.id  = tve.era_id
  LEFT JOIN tree_version_era_lanes lanes
         ON lanes.version_era_id = tve.id AND lanes.tree_id = n.tree_id
 WHERE n.version_id = ?
 ORDER BY n.tree_id, n.global_column, n.row_index;
```

И один на связи:

```sql
SELECT l.from_node_id, l.to_node_id, l.origin
  FROM tree_version_links l
 WHERE l.version_id = ?;
```

## Ручная правка версии

```sql
-- добавить в версию технологию, которой нет в стандартном наборе
INSERT INTO tree_version_nodes
       (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
VALUES (?, ?, ?, ?, ?, ?, ?, 'manual');

-- убрать эпоху из версии (карточки и связи уедут каскадом)
DELETE FROM tree_version_eras WHERE version_id = ? AND era_id = ?;

-- пометить версию как правленую руками
UPDATE tree_versions SET status = 'edited' WHERE id = ?;
```

Удаление версии (`DELETE FROM tree_versions WHERE id = ?`) каскадом уносит
её эпохи, карточки и связи, не задевая каталог.

## Что схема не даст сделать

Каждый случай отбивается на уровне БД, а не кода. Все они лежат отдельными
файлами в [`tests/constraints/`](tests/constraints) и прогоняются
через `tools/run-db-tests.sh` — тест считается пройденным, когда БД
отказалась записать данные:

| Ошибка | Что срабатывает |
|---|---|
| одна технология дважды в одной версии | `uq_tree_version_nodes (version_id, technology_id)` |
| технология науки, приписанная дереву культуры | составной ключ `fk_tvn_technology (technology_id, tree_id)` |
| ветка чужого дерева у технологии | составной ключ `fk_technologies_branch (branch_id, tree_id)` |
| карточка в эпохе, принадлежащей другой версии | составной ключ `fk_tvn_version_era (version_era_id, version_id)` |
| связь между карточками разных версий | составные ключи `fk_tvl_from` / `fk_tvl_to` по `(node_id, version_id)` |
| связь технологии на саму себя | `chk_tvl_no_self_link`, `chk_technology_prereqs_no_self` |
| две версии с одним читаемым именем | `uq_tree_versions_name` |

Одна и та же эпоха дважды в версии тоже невозможна
(`uq_tree_version_eras (version_id, era_id)`), а вот `position` намеренно
не уникален: перестановка эпох не должна требовать промежуточных значений.

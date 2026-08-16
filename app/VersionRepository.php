<?php
declare(strict_types=1);

namespace Civi;

/**
 * Создание, сохранение и загрузка версий деревьев.
 *
 * Версия — пара деревьев (наука + культура), собранная по семени
 * из стандартного набора каталога и, возможно, доработанная руками.
 */
final class VersionRepository
{
    /**
     * Стоимость технологии определяется её столбцом.
     *
     * Отсчёт начинается с COST_BASE за технологию первого столбца.
     * Каждый следующий столбец дороже предыдущего в cost_step раз, внутри
     * столбца стоимости расходятся на ±разброс. И база, и шаг хранятся
     * у версии, поэтому их можно менять прямо на доске; здесь только
     * значения по умолчанию для новой версии.
     *
     * Разброс не постоянный, а считается от шага (см. jitterFor): диапазоны
     * соседних столбцов не должны пересекаться, иначе технологии перестанут
     * читаться по цене. Чем меньше шаг, тем уже разброс.
     */
    private const COST_BASE = 60;
    private const COST_STEP = 1.3;
    private const COST_JITTER = 0.15;
    private const COST_STEP_MIN = 1.05;
    private const COST_STEP_MAX = 4.0;

    /**
     * Наибольший разброс внутри столбца, при котором соседние столбцы
     * ещё не налезают друг на друга.
     *
     * Самая дорогая технология столбца стоит (1 + j) базы, самая дешёвая
     * технология следующего — step × (1 − j). Условие step(1−j) > 1+j даёт
     * j < (step − 1) / (step + 1); берём девять десятых от предела, чтобы
     * между столбцами оставался зазор, и не превышаем COST_JITTER.
     */
    private static function jitterFor(float $step): float
    {
        return min(self::COST_JITTER, ($step - 1) / ($step + 1) * 0.9);
    }

    /**
     * Потолок стоимости одной технологии.
     *
     * Коэффициент столбца растёт в степени, а столбцов в дереве больше
     * шестидесяти: при шаге 1.5 последний столбец стоит уже десятки
     * триллионов. Стоимость хранится в BIGINT UNSIGNED, но упираемся
     * в круглый потолок заметно раньше предела типа — чтобы переполнение
     * не случилось даже после того, как эпохи вырастут столбцами.
     *
     * Если пересчёт упёрся в потолок, recalcCosts() возвращает число
     * таких карточек, и админка предупреждает: при этом коэффициенте
     * дальние столбцы перестают различаться по цене.
     */
    public const COST_MAX = 1000000000000000;

    /**
     * Потолок числа столбцов внутри одной эпохи.
     *
     * Ручная правка связей может выстроить длинную цепочку внутри эпохи,
     * и эпоха честно растёт столбцами следом. Но расти без предела доска
     * не должна, поэтому цепочки длиннее этого значения отклоняются.
     */
    public const LANE_LIMIT = 12;

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /** Список версий для главной страницы админки. */
    public function listVersions(string $search = ''): array
    {
        $sql = 'SELECT v.id, v.name, v.seed_code, v.status, v.created_at, v.updated_at,
                       v.parent_version_id,
                       (SELECT COUNT(*) FROM tree_version_nodes n WHERE n.version_id = v.id) AS node_count,
                       (SELECT COUNT(*) FROM tree_version_links l WHERE l.version_id = v.id) AS link_count,
                       (SELECT COUNT(*) FROM tree_version_nodes n
                         WHERE n.version_id = v.id AND n.source = \'manual\') AS manual_count
                  FROM tree_versions v';
        $params = [];
        if ($search !== '') {
            // одну именованную метку нельзя привязать к двум местам,
            // если эмуляция подготовленных выражений выключена
            $sql .= ' WHERE v.name LIKE :q_name OR v.seed_code LIKE :q_seed';
            $params['q_name'] = '%' . $search . '%';
            $params['q_seed'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY v.created_at DESC, v.id DESC';

        return $this->db->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM tree_versions WHERE id = ?', [$id]);
    }

    /** Семя набором из трёх чисел: наука, культура, разбиение эпох. */
    public function randomSeed(): array
    {
        return [random_int(1000, 99999), random_int(1000, 99999), random_int(1000, 99999)];
    }

    public static function seedCode(array $numbers): string
    {
        return implode('-', array_map('intval', $numbers));
    }

    /** Разбор семени из строки вида «4821-7719-3045». Null, если не разобралось. */
    public static function parseSeed(string $code): ?array
    {
        $parts = preg_split('/[^0-9]+/', trim($code), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) !== 3) {
            return null;
        }

        return array_map('intval', $parts);
    }

    /**
     * Генерация новой версии из стандартного набора каталога.
     *
     * @param array|null $seed три числа; null — бросить случайные
     */
    public function generate(?string $name, ?array $seed = null, ?int $parentId = null): int
    {
        $seed = $seed ?? $this->randomSeed();
        $seedCode = self::seedCode($seed);

        return $this->db->transaction(function (Db $db) use ($name, $seed, $seedCode, $parentId) {
            $versionId = $db->insert(
                'INSERT INTO tree_versions
                    (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout,
                     cost_base, cost_step, parent_version_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    ($name === null || $name === '') ? null : $name,
                    $seedCode,
                    json_encode(array_map('intval', $seed)),
                    $seed[0], $seed[1], $seed[2], self::COST_BASE, self::COST_STEP, $parentId,
                ]
            );

            // стандартная сетка эпох как есть
            $eras = $db->all('SELECT id, name, default_position FROM eras
                               WHERE is_standard = 1 ORDER BY default_position, id');
            $versionEraId = [];
            $position = 1;
            foreach ($eras as $era) {
                $versionEraId[$era['id']] = $db->insert(
                    'INSERT INTO tree_version_eras (version_id, era_id, position) VALUES (?, ?, ?)',
                    [$versionId, $era['id'], $position++]
                );
            }

            $trees = $db->all('SELECT id, code FROM trees ORDER BY position, id');
            $generator = new TreeGenerator();
            $layoutRng = new Rng((int) $seed[2]);

            // число столбцов решается один раз на пару «дерево + эпоха»,
            // общим генератором по seed_layout
            $laneCounts = [];
            foreach ($trees as $tree) {
                foreach ($eras as $era) {
                    $count = (int) $db->value(
                        'SELECT COUNT(*) FROM technologies
                          WHERE is_standard = 1 AND tree_id = ? AND default_era_id = ?',
                        [$tree['id'], $era['id']]
                    );
                    // столбцов не меньше, чем технологий одной категории в эпохе:
                    // иначе два шага одной ветки встанут в один столбец
                    $maxPerBranch = (int) $db->value(
                        'SELECT COALESCE(MAX(c), 0) FROM (
                            SELECT COUNT(*) AS c FROM technologies
                             WHERE is_standard = 1 AND tree_id = ? AND default_era_id = ?
                             GROUP BY branch_id) x',
                        [$tree['id'], $era['id']]
                    );
                    $lanes = $generator->rollLanes($count, $layoutRng, $maxPerBranch);
                    $laneCounts[$tree['id']][$era['id']] = $lanes;
                    $db->run(
                        'INSERT INTO tree_version_era_lanes (version_era_id, tree_id, lanes) VALUES (?, ?, ?)',
                        [$versionEraId[$era['id']], $tree['id'], $lanes]
                    );
                }
            }

            $prereqs = [];
            foreach ($db->all('SELECT technology_id, prereq_technology_id FROM technology_prereqs') as $row) {
                $prereqs[$row['technology_id']][] = (int) $row['prereq_technology_id'];
            }

            $nodeIdByTech = [];
            foreach ($trees as $treeIndex => $tree) {
                $techs = $db->all(
                    'SELECT id, default_era_id AS era_id, branch_id, base_cost FROM technologies
                      WHERE is_standard = 1 AND tree_id = ? ORDER BY id',
                    [$tree['id']]
                );
                // проставленная руками стоимость сильнее расчётной
                $baseCost = [];
                foreach ($techs as $t) {
                    if ($t['base_cost'] !== null) {
                        $baseCost[(int) $t['id']] = (int) $t['base_cost'];
                    }
                }
                if ($techs === []) {
                    continue;
                }

                // первым двум деревьям достаются числа семени, третьему и далее —
                // производное от кода семени, чтобы раскладка оставалась воспроизводимой
                // третье число семени занято раскладкой эпох, поэтому напрямую
                // его берут только два первых дерева
                $treeSeed = $treeIndex < 2
                    ? (int) $seed[$treeIndex]
                    : (int) (crc32($seedCode . ':' . $tree['code']) & 0x7FFFFFFF);
                $layout = $generator->generate(
                    array_map(static function ($e) {
                        return ['id' => (int) $e['id'], 'position' => (int) $e['default_position']];
                    }, $eras),
                    array_map(static function ($t) {
                        return ['id' => (int) $t['id'], 'era_id' => (int) $t['era_id'],
                                'branch_id' => (int) $t['branch_id']];
                    }, $techs),
                    $prereqs,
                    $treeSeed,
                    $laneCounts[$tree['id']]
                );

                $costRng = new Rng($treeSeed ^ 0x5EED);
                $costBase = self::COST_BASE;
                $costStep = self::COST_STEP;
                foreach ($layout['nodes'] as $techId => $n) {
                    $nodeIdByTech[$techId] = $db->insert(
                        'INSERT INTO tree_version_nodes
                            (version_id, tree_id, technology_id, version_era_id,
                             lane, row_index, global_column, cost, source, is_relaxed)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'standard\', ?)',
                        [
                            $versionId, $tree['id'], $techId, $versionEraId[$n['era_id']],
                            $n['lane'], $n['row'], $n['global_column'],
                            $baseCost[$techId]
                                ?? self::costFor((int) $n['global_column'], $costRng, $costBase, $costStep),
                            $n['relaxed'] ? 1 : 0,
                        ]
                    );
                }

                foreach ($layout['links'] as $link) {
                    if (!isset($nodeIdByTech[$link['from']], $nodeIdByTech[$link['to']])) {
                        continue;
                    }
                    $db->run(
                        'INSERT IGNORE INTO tree_version_links
                            (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, ?)',
                        [$versionId, $nodeIdByTech[$link['from']], $nodeIdByTech[$link['to']], $link['origin']]
                    );
                }
            }

            return $versionId;
        });
    }

    /**
     * Загрузка версии для отрисовки доски.
     *
     * @return array{version: array, trees: array, eras: array, nodes: array, links: array}
     */
    public function load(int $versionId): ?array
    {
        $version = $this->find($versionId);
        if ($version === null) {
            return null;
        }

        $eras = $this->db->all(
            'SELECT tve.id, tve.era_id, tve.position, e.period,
                    COALESCE(tve.name_override, e.name) AS name
               FROM tree_version_eras tve JOIN eras e ON e.id = tve.era_id
              WHERE tve.version_id = ? ORDER BY tve.position, tve.id',
            [$versionId]
        );

        $lanes = [];
        foreach ($this->db->all(
            'SELECT l.version_era_id, l.tree_id, l.lanes
               FROM tree_version_era_lanes l
               JOIN tree_version_eras tve ON tve.id = l.version_era_id
              WHERE tve.version_id = ?',
            [$versionId]
        ) as $row) {
            $lanes[$row['tree_id']][$row['version_era_id']] = (int) $row['lanes'];
        }

        $nodes = $this->db->all(
            'SELECT n.id, n.tree_id, n.technology_id, n.version_era_id, n.lane, n.row_index,
                    n.global_column, n.cost, n.source, n.is_relaxed,
                    t.code, t.name, t.image_path, t.description, t.historical_note,
                    COALESCE(t.base_cost, n.cost) AS shown_cost,
                    b.id AS branch_id, b.name AS branch_name, b.color AS branch_color
               FROM tree_version_nodes n
               JOIN technologies t ON t.id = n.technology_id
               JOIN branches b     ON b.id = t.branch_id
              WHERE n.version_id = ?
              ORDER BY n.tree_id, n.global_column, n.row_index, n.id',
            [$versionId]
        );

        $links = $this->db->all(
            'SELECT from_node_id, to_node_id, origin FROM tree_version_links WHERE version_id = ?',
            [$versionId]
        );

        // эффекты технологий этой доски — по одному значку на эффект
        $effects = [];
        foreach ($this->db->all(
            'SELECT e.technology_id, e.title, et.code, et.name
               FROM technology_effects e
               JOIN effect_types et ON et.id = e.effect_type_id
              WHERE e.technology_id IN (
                    SELECT technology_id FROM tree_version_nodes WHERE version_id = ?)
              ORDER BY e.position, e.id',
            [$versionId]
        ) as $row) {
            $effects[(int) $row['technology_id']][] = [
                'code' => $row['code'], 'title' => $row['title'], 'type' => $row['name'],
            ];
        }

        $trees = $this->db->all('SELECT id, code, name, points_name, boost_name FROM trees ORDER BY position, id');

        return [
            'effects' => $effects,
            'version' => $version,
            'trees'   => $trees,
            'eras'    => $eras,
            'lanes'   => $lanes,
            'nodes'   => $nodes,
            'links'   => $links,
        ];
    }

    public function rename(int $versionId, ?string $name): void
    {
        $this->db->run('UPDATE tree_versions SET name = ? WHERE id = ?',
            [($name === null || $name === '') ? null : $name, $versionId]);
    }

    public function delete(int $versionId): void
    {
        $this->db->run('DELETE FROM tree_versions WHERE id = ?', [$versionId]);
    }

    /**
     * Ручное добавление технологии в версию: ставим её в конец нужного
     * столбца и, если в предыдущем столбце есть карточки, вешаем связь,
     * чтобы правило столбцов не нарушалось.
     */
    public function addTechnology(int $versionId, int $technologyId, int $lane = 0): void
    {
        $this->db->transaction(function (Db $db) use ($versionId, $technologyId, $lane) {
            $tech = $db->one('SELECT id, tree_id, default_era_id FROM technologies WHERE id = ?', [$technologyId]);
            if ($tech === null) {
                throw new UserError('Технология не найдена');
            }

            $versionEra = $db->one(
                'SELECT id, position FROM tree_version_eras WHERE version_id = ? AND era_id = ?',
                [$versionId, $tech['default_era_id']]
            );
            if ($versionEra === null) {
                throw new UserError('В этой версии нет эпохи, к которой отнесена технология');
            }

            $lanes = (int) $db->value(
                'SELECT lanes FROM tree_version_era_lanes WHERE version_era_id = ? AND tree_id = ?',
                [$versionEra['id'], $tech['tree_id']]
            ) ?: TreeGenerator::LANE_MIN;
            $lane = max(0, min($lane, $lanes - 1));

            // сквозной номер столбца: сумма столбцов всех предыдущих эпох
            $before = (int) $db->value(
                'SELECT COALESCE(SUM(l.lanes), 0)
                   FROM tree_version_eras tve
                   JOIN tree_version_era_lanes l ON l.version_era_id = tve.id AND l.tree_id = ?
                  WHERE tve.version_id = ? AND tve.position < ?',
                [$tech['tree_id'], $versionId, $versionEra['position']]
            );
            $globalColumn = $before + $lane;

            $row = (int) $db->value(
                'SELECT COALESCE(MAX(row_index) + 1, 0) FROM tree_version_nodes
                  WHERE version_id = ? AND version_era_id = ? AND lane = ?',
                [$versionId, $versionEra['id'], $lane]
            );

            $nodeId = $db->insert(
                'INSERT INTO tree_version_nodes
                    (version_id, tree_id, technology_id, version_era_id, lane, row_index, global_column, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\')',
                [$versionId, $tech['tree_id'], $technologyId, $versionEra['id'], $lane, $row, $globalColumn]
            );

            // связь с предыдущим столбцом, если он есть
            if ($globalColumn > 0) {
                $source = $db->one(
                    'SELECT id FROM tree_version_nodes
                      WHERE version_id = ? AND tree_id = ? AND global_column = ?
                      ORDER BY row_index LIMIT 1',
                    [$versionId, $tech['tree_id'], $globalColumn - 1]
                );
                if ($source !== null) {
                    $db->run(
                        'INSERT IGNORE INTO tree_version_links
                            (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, \'manual\')',
                        [$versionId, $source['id'], $nodeId]
                    );
                }
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
        });
    }

    public function removeNode(int $versionId, int $nodeId): void
    {
        $this->db->run('DELETE FROM tree_version_nodes WHERE version_id = ? AND id = ?', [$versionId, $nodeId]);
        $this->db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
    }

    /**
     * Стоимость технологии в заданном сквозном столбце.
     *
     * Диапазоны соседних столбцов гарантированно не пересекаются, поэтому
     * любая технология столбца дороже любой технологии столбца левее
     * и дешевле любой технологии столбца правее.
     */
    public static function costFor(
        int $globalColumn,
        Rng $rng,
        ?int $costBase = null,
        ?float $costStep = null
    ): int {
        $step = $costStep ?? self::COST_STEP;
        $base = ($costBase ?? self::COST_BASE) * pow($step, $globalColumn);
        $jitter = 1.0 + (($rng->next() * 2) - 1) * self::jitterFor($step);

        return max(1, min(self::COST_MAX, (int) round($base * $jitter)));
    }

    /** Границы диапазона стоимостей столбца — для подсказок в интерфейсе. */
    public static function costRange(
        int $globalColumn,
        ?int $costBase = null,
        ?float $costStep = null
    ): array {
        $step = $costStep ?? self::COST_STEP;
        $base = ($costBase ?? self::COST_BASE) * pow($step, $globalColumn);
        $jitter = self::jitterFor($step);

        return [
            min(self::COST_MAX, (int) round($base * (1 - $jitter))),
            min(self::COST_MAX, (int) round($base * (1 + $jitter))),
        ];
    }

    /**
     * Границы сквозных столбцов каждой эпохи для одного дерева.
     *
     * @return array [version_era_id => ['first' => int, 'last' => int]]
     */
    public function eraColumnRanges(int $versionId, int $treeId): array
    {
        $rows = $this->db->all(
            'SELECT tve.id, tve.position, COALESCE(l.lanes, ?) AS lanes
               FROM tree_version_eras tve
               LEFT JOIN tree_version_era_lanes l ON l.version_era_id = tve.id AND l.tree_id = ?
              WHERE tve.version_id = ? ORDER BY tve.position, tve.id',
            [TreeGenerator::LANE_MIN, $treeId, $versionId]
        );

        $ranges = [];
        $offset = 0;
        foreach ($rows as $row) {
            $lanes = max(1, (int) $row['lanes']);
            $ranges[(int) $row['id']] = ['first' => $offset, 'last' => $offset + $lanes - 1];
            $offset += $lanes;
        }

        return $ranges;
    }

    /**
     * Переставляет карточки по столбцам согласно связям.
     *
     * Карточка встаёт в столбец сразу за самой поздней из своих основ;
     * если основ нет — в первый столбец своей эпохи. Эпоха не меняется,
     * но число столбцов внутри неё не фиксировано: если основа лежит
     * в последнем столбце эпохи, эпоха получает ещё один столбец.
     * Лишние пустые столбцы в конце эпохи убираются, но меньше двух
     * не становится.
     *
     * Стоимости технологий при этом не трогаются: переезд карточки
     * меняет только её положение. Средние по столбцам считаются
     * от номера столбца и пересчитываются сами.
     *
     * @return int сколько карточек переехало
     */
    public function reposition(int $versionId): int
    {
        $movedTotal = 0;

        foreach ($this->db->all('SELECT id FROM trees ORDER BY position, id') as $tree) {
            $treeId = (int) $tree['id'];

            $eraOrder = [];
            $lanes = [];
            foreach ($this->db->all(
                'SELECT tve.id, COALESCE(l.lanes, ?) AS lanes
                   FROM tree_version_eras tve
                   LEFT JOIN tree_version_era_lanes l ON l.version_era_id = tve.id AND l.tree_id = ?
                  WHERE tve.version_id = ? ORDER BY tve.position, tve.id',
                [TreeGenerator::LANE_MIN, $treeId, $versionId]
            ) as $row) {
                $eraOrder[] = (int) $row['id'];
                $lanes[(int) $row['id']] = max(1, (int) $row['lanes']);
            }
            if ($eraOrder === []) {
                continue;
            }

            $nodes = [];
            foreach ($this->db->all(
                'SELECT id, version_era_id, lane, row_index, global_column
                   FROM tree_version_nodes WHERE version_id = ? AND tree_id = ?',
                [$versionId, $treeId]
            ) as $row) {
                $nodes[(int) $row['id']] = [
                    'era' => (int) $row['version_era_id'],
                    'col' => (int) $row['global_column'],
                    'lane' => (int) $row['lane'],
                    'row' => (int) $row['row_index'],
                ];
            }
            if ($nodes === []) {
                continue;
            }

            $incoming = [];
            foreach ($this->db->all(
                'SELECT l.from_node_id, l.to_node_id
                   FROM tree_version_links l
                   JOIN tree_version_nodes n ON n.id = l.to_node_id
                  WHERE l.version_id = ? AND n.tree_id = ?',
                [$versionId, $treeId]
            ) as $row) {
                $incoming[(int) $row['to_node_id']][] = (int) $row['from_node_id'];
            }

            // Волнами: пересчитали положения — посмотрели, сколько столбцов
            // теперь нужно эпохам — пересчитали снова, потому что рост одной
            // эпохи сдвигает сквозную нумерацию всех последующих.
            for ($pass = 0; $pass < 40; $pass++) {
                $first = [];
                $offset = 0;
                foreach ($eraOrder as $eraId) {
                    $first[$eraId] = $offset;
                    $offset += $lanes[$eraId];
                }

                $order = array_keys($nodes);
                usort($order, static function ($a, $b) use ($nodes) {
                    return $nodes[$a]['col'] <=> $nodes[$b]['col'];
                });

                foreach ($order as $id) {
                    $eraFirst = $first[$nodes[$id]['era']] ?? 0;
                    $desired = $eraFirst;
                    foreach ($incoming[$id] ?? [] as $srcId) {
                        if (isset($nodes[$srcId])) {
                            $desired = max($desired, $nodes[$srcId]['col'] + 1);
                        }
                    }
                    // левее своей эпохи карточка уйти не может
                    $nodes[$id]['lane'] = max(0, $desired - $eraFirst);
                    $nodes[$id]['col'] = $eraFirst + $nodes[$id]['lane'];
                }

                // сколько столбцов теперь нужно каждой эпохе
                $needed = [];
                foreach ($eraOrder as $eraId) {
                    $needed[$eraId] = TreeGenerator::LANE_MIN;
                }
                foreach ($nodes as $n) {
                    $needed[$n['era']] = max($needed[$n['era']], $n['lane'] + 1);
                }
                // потолок на случай, если связи выстроились в длинную цепочку
                // внутри одной эпохи: расти бесконечно доска не должна
                foreach ($needed as $eraId => $value) {
                    $needed[$eraId] = min($value, self::LANE_LIMIT);
                }

                if ($needed === $lanes) {
                    break;
                }
                $lanes = $needed;
            }

            // перенумеровываем строки внутри каждого столбца
            $byColumn = [];
            foreach ($nodes as $id => $n) {
                $byColumn[$n['era'] . ':' . $n['lane']][] = $id;
            }
            foreach ($byColumn as $ids) {
                usort($ids, static function ($a, $b) use ($nodes) {
                    return $nodes[$a]['row'] <=> $nodes[$b]['row'];
                });
                foreach ($ids as $i => $id) {
                    $nodes[$id]['row'] = $i;
                }
            }

            foreach ($lanes as $eraId => $value) {
                $this->db->run(
                    'INSERT INTO tree_version_era_lanes (version_era_id, tree_id, lanes)
                     VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE lanes = VALUES(lanes)',
                    [$eraId, $treeId, $value]
                );
            }

            foreach ($nodes as $id => $n) {
                $movedTotal += (int) $this->db->run(
                    'UPDATE tree_version_nodes SET lane = ?, row_index = ?, global_column = ?
                      WHERE id = ? AND (lane <> ? OR row_index <> ? OR global_column <> ?)',
                    [$n['lane'], $n['row'], $n['col'], $id, $n['lane'], $n['row'], $n['col']]
                )->rowCount();
            }
        }

        return $movedTotal;
    }

    /**
     * Переключает связь между карточками: была — снимаем, не было — ставим.
     * После этого доска перекладывается по правилу столбцов.
     *
     * @return array состояние доски для перерисовки на клиенте
     */
    public function toggleLink(int $versionId, int $fromNodeId, int $toNodeId): array
    {
        if ($fromNodeId === $toNodeId) {
            throw new UserError('Технология не может зависеть сама от себя');
        }

        return $this->db->transaction(function (Db $db) use ($versionId, $fromNodeId, $toNodeId) {
            $from = $this->nodeOnBoard($versionId, $fromNodeId);
            $to = $this->nodeOnBoard($versionId, $toNodeId);
            if ($from === null || $to === null) {
                throw new UserError('Карточка не найдена на этой доске');
            }
            if ($from['tree_id'] !== $to['tree_id']) {
                throw new UserError('Связь возможна только внутри одного дерева');
            }

            $exists = (int) $db->value(
                'SELECT COUNT(*) FROM tree_version_links
                  WHERE version_id = ? AND from_node_id = ? AND to_node_id = ?',
                [$versionId, $fromNodeId, $toNodeId]
            ) > 0;

            if ($exists) {
                $db->run(
                    'DELETE FROM tree_version_links
                      WHERE version_id = ? AND from_node_id = ? AND to_node_id = ?',
                    [$versionId, $fromNodeId, $toNodeId]
                );
            } else {
                // единственное, что нельзя проверить раскладкой, — кольцо:
                // если от зависимой уже есть путь к основе, связь замкнёт круг
                if ($this->pathExists($db, $versionId, $toNodeId, $fromNodeId)) {
                    throw new UserError(
                        '«' . $to['name'] . '» уже открывает дорогу к «' . $from['name']
                        . '» — получилось бы кольцо'
                    );
                }

                // Столбцы не проверяем заранее: связь допускается и между
                // карточками одного столбца. Правильные позиции посчитает
                // reposition() — зависимая уедет правее, а эпоха при нехватке
                // места получит ещё один столбец.
                $db->run(
                    'INSERT INTO tree_version_links (version_id, from_node_id, to_node_id, origin)
                     VALUES (?, ?, ?, \'manual\')',
                    [$versionId, $fromNodeId, $toNodeId]
                );
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
            $this->reposition($versionId);

            // после перестановки связь обязана идти строго слева направо;
            // эпоха для этого растёт столбцами, но не бесконечно — если
            // упёрлись в потолок, откатываем всю правку
            if (!$exists) {
                $bad = $db->one(
                    'SELECT sf.global_column AS from_col, st.global_column AS to_col
                       FROM tree_version_nodes sf, tree_version_nodes st
                      WHERE sf.id = ? AND st.id = ?',
                    [$fromNodeId, $toNodeId]
                );
                if ($bad !== null && (int) $bad['from_col'] >= (int) $bad['to_col']) {
                    throw new UserError(
                        'Так нельзя: цепочка от «' . $from['name'] . '» к «' . $to['name']
                        . '» не помещается — в эпохе уже ' . self::LANE_LIMIT . ' столбцов'
                    );
                }
            }

            return $this->boardState($versionId);
        });
    }

    /**
     * Есть ли на доске путь по связям от одной карточки к другой.
     *
     * Нужен, чтобы не дать замкнуть кольцо: новая связь «основа → зависимая»
     * допустима, только если от зависимой ещё нет дороги обратно к основе.
     * Обход идёт волнами по уже сохранённым связям, поэтому в пакетной
     * правке каждая следующая связь проверяется с учётом предыдущих.
     */
    private function pathExists(Db $db, int $versionId, int $fromNodeId, int $toNodeId): bool
    {
        if ($fromNodeId === $toNodeId) {
            return true;
        }
        $seen = [$fromNodeId => true];
        $frontier = [$fromNodeId];

        while ($frontier !== []) {
            $marks = implode(', ', array_fill(0, count($frontier), '?'));
            $rows = $db->all(
                'SELECT to_node_id FROM tree_version_links
                  WHERE version_id = ? AND from_node_id IN (' . $marks . ')',
                array_merge([$versionId], $frontier)
            );
            $next = [];
            foreach ($rows as $row) {
                $id = (int) $row['to_node_id'];
                if ($id === $toNodeId) {
                    return true;
                }
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $next[] = $id;
                }
            }
            $frontier = $next;
        }

        return false;
    }

    /** Карточка версии вместе с названием технологии. */
    public function nodeOnBoard(int $versionId, int $nodeId): ?array
    {
        return $this->db->one(
            'SELECT n.*, t.name FROM tree_version_nodes n
               JOIN technologies t ON t.id = n.technology_id
              WHERE n.version_id = ? AND n.id = ?',
            [$versionId, $nodeId]
        );
    }

    /**
     * Связи технологии на конкретной доске: что открывает её и что
     * открывается благодаря ей. Плюс кандидаты для правки списков.
     */
    public function technologyLinks(int $versionId, int $technologyId): ?array
    {
        $node = $this->db->one(
            'SELECT n.id, n.tree_id, n.global_column, n.version_era_id
               FROM tree_version_nodes n WHERE n.version_id = ? AND n.technology_id = ?',
            [$versionId, $technologyId]
        );
        if ($node === null) {
            return null;   // технологии нет на этой доске
        }

        $all = $this->db->all(
            'SELECT n.id, n.global_column, t.name, b.color
               FROM tree_version_nodes n
               JOIN technologies t ON t.id = n.technology_id
               JOIN branches b     ON b.id = t.branch_id
              WHERE n.version_id = ? AND n.tree_id = ? AND n.id <> ?
              ORDER BY n.global_column, t.name',
            [$versionId, $node['tree_id'], $node['id']]
        );

        $incoming = array_map('intval', $this->db->run(
            'SELECT from_node_id FROM tree_version_links WHERE version_id = ? AND to_node_id = ?',
            [$versionId, $node['id']]
        )->fetchAll(\PDO::FETCH_COLUMN));

        $outgoing = array_map('intval', $this->db->run(
            'SELECT to_node_id FROM tree_version_links WHERE version_id = ? AND from_node_id = ?',
            [$versionId, $node['id']]
        )->fetchAll(\PDO::FETCH_COLUMN));

        // Показываем не всю доску, а ближние столбцы: списком на три сотни
        // галочек пользоваться невозможно. Уже связанные попадают в список
        // всегда, даже если лежат далеко.
        $col = (int) $node['global_column'];
        $reach = 3;
        $inc = array_flip($incoming);
        $out = array_flip($outgoing);
        $before = [];
        $after = [];
        foreach ($all as $row) {
            $rowCol = (int) $row['global_column'];
            $id = (int) $row['id'];
            $near = abs($rowCol - $col) <= $reach;
            // соседи из того же столбца попадают в оба списка: связь с ними
            // разрешена, а доска после сохранения сама разведёт карточки
            if ($rowCol <= $col && ($near || isset($inc[$id]))) {
                $before[] = $row;
            }
            if ($rowCol >= $col && ($near || isset($out[$id]))) {
                $after[] = $row;
            }
        }

        return [
            'node_id'  => (int) $node['id'],
            'column'   => $col,
            'incoming' => array_flip($incoming),
            'outgoing' => array_flip($outgoing),
            'before'   => $before,
            'after'    => $after,
        ];
    }

    /**
     * Пересобирает связи одной карточки по спискам со страницы технологии:
     * что её открывает и что открывает она. Всё лишнее снимается.
     */
    public function saveNodeLinks(int $versionId, int $nodeId, array $incoming, array $outgoing): void
    {
        $this->db->transaction(function (Db $db) use ($versionId, $nodeId, $incoming, $outgoing) {
            $node = $this->nodeOnBoard($versionId, $nodeId);
            if ($node === null) {
                throw new UserError('Карточка не найдена на этой доске');
            }

            $valid = [];
            foreach ($db->all(
                'SELECT id FROM tree_version_nodes WHERE version_id = ? AND tree_id = ?',
                [$versionId, $node['tree_id']]
            ) as $row) {
                $valid[(int) $row['id']] = true;
            }

            $db->run('DELETE FROM tree_version_links WHERE version_id = ? AND to_node_id = ?',
                [$versionId, $nodeId]);
            $db->run('DELETE FROM tree_version_links WHERE version_id = ? AND from_node_id = ?',
                [$versionId, $nodeId]);

            // Столбцы не сверяем: карточка сама встанет куда надо, а эпоха
            // при нехватке места получит ещё один столбец. Отсекаем только
            // связи, которые замкнули бы кольцо.
            foreach (array_unique(array_map('intval', $incoming)) as $srcId) {
                if ($srcId === $nodeId || !isset($valid[$srcId])) {
                    continue;
                }
                if ($this->pathExists($db, $versionId, $nodeId, $srcId)) {
                    continue;
                }
                $db->run(
                    'INSERT IGNORE INTO tree_version_links
                        (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, \'manual\')',
                    [$versionId, $srcId, $nodeId]
                );
            }
            foreach (array_unique(array_map('intval', $outgoing)) as $dstId) {
                if ($dstId === $nodeId || !isset($valid[$dstId])) {
                    continue;
                }
                if ($this->pathExists($db, $versionId, $dstId, $nodeId)) {
                    continue;
                }
                $db->run(
                    'INSERT IGNORE INTO tree_version_links
                        (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, \'manual\')',
                    [$versionId, $nodeId, $dstId]
                );
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
            $this->reposition($versionId);
        });
    }

    /**
     * Пересчёт стоимостей по правилу столбцов.
     *
     * Среднее столбца = cost_base × cost_step^номер, внутри столбца
     * стоимости расходятся на ±15%. Область задаётся: вся версия,
     * одна эпоха или один столбец.
     *
     * Если передан $newStep, он становится новым коэффициентом версии —
     * средние всех столбцов едут следом. Если передан $newAverage, он
     * трактуется как желаемое среднее для столбца $column: база версии
     * пересчитывается так, чтобы средние в остальных столбцах поехали
     * за ним по тому же коэффициенту.
     *
     * @param string $scope 'version' | 'era' | 'column'
     */
    public function recalcCosts(
        int $versionId,
        string $scope = 'version',
        ?int $treeId = null,
        ?int $versionEraId = null,
        ?int $column = null,
        ?int $newAverage = null,
        ?float $newStep = null
    ): array {
        return $this->db->transaction(function (Db $db) use (
            $versionId, $scope, $treeId, $versionEraId, $column, $newAverage, $newStep
        ) {
            // шаг меняем первым: от него зависит пересчёт базы под новое среднее
            if ($newStep !== null) {
                if ($newStep < self::COST_STEP_MIN || $newStep > self::COST_STEP_MAX) {
                    throw new UserError(sprintf(
                        'Коэффициент должен быть от %s до %s',
                        rtrim(rtrim(number_format(self::COST_STEP_MIN, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format(self::COST_STEP_MAX, 2, '.', ''), '0'), '.')
                    ));
                }
                $db->run('UPDATE tree_versions SET cost_step = ? WHERE id = ?',
                    [round($newStep, 2), $versionId]);
            }

            $version = $db->one('SELECT cost_base, cost_step FROM tree_versions WHERE id = ?', [$versionId]);
            if ($version === null) {
                throw new UserError('Версия не найдена');
            }
            $costStep = (float) $version['cost_step'];
            if ($costStep < self::COST_STEP_MIN) {
                $costStep = self::COST_STEP;
            }

            if ($newAverage !== null) {
                if ($newAverage < 1) {
                    throw new UserError('Среднее должно быть положительным числом');
                }
                $base = (int) round($newAverage / pow($costStep, max(0, (int) $column)));
                $db->run('UPDATE tree_versions SET cost_base = ? WHERE id = ?', [max(1, $base), $versionId]);
            }

            $costBase = (int) $db->value('SELECT cost_base FROM tree_versions WHERE id = ?', [$versionId]);
            if ($costBase < 1) {
                $costBase = self::COST_BASE;
            }

            $sql = 'SELECT id, global_column FROM tree_version_nodes WHERE version_id = ?';
            $params = [$versionId];
            if ($scope === 'era') {
                $sql .= ' AND tree_id = ? AND version_era_id = ?';
                $params[] = $treeId;
                $params[] = $versionEraId;
            } elseif ($scope === 'column') {
                $sql .= ' AND tree_id = ? AND global_column = ?';
                $params[] = $treeId;
                $params[] = $column;
            }

            // семя от версии и области: пересчёт одного столбца не должен
            // менять стоимости в остальных
            $rng = new Rng(($versionId * 7919) ^ (int) $costBase ^ (($column ?? -1) + 13));
            $costs = [];
            $capped = 0;
            foreach ($db->all($sql, $params) as $row) {
                $cost = self::costFor((int) $row['global_column'], $rng, $costBase, $costStep);
                if ($cost >= self::COST_MAX) {
                    $capped++;
                }
                $db->run('UPDATE tree_version_nodes SET cost = ? WHERE id = ?', [$cost, (int) $row['id']]);
                $costs[(int) $row['id']] = $cost;
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);

            // ручная стоимость каталога сильнее расчётной — отдаём то,
            // что будет реально показано на доске
            foreach ($db->all(
                'SELECT n.id, t.base_cost FROM tree_version_nodes n
                   JOIN technologies t ON t.id = n.technology_id
                  WHERE n.version_id = ? AND t.base_cost IS NOT NULL',
                [$versionId]
            ) as $row) {
                if (isset($costs[(int) $row['id']])) {
                    $costs[(int) $row['id']] = (int) $row['base_cost'];
                }
            }

            return [
                'cost_base' => $costBase,
                'cost_step' => $costStep,
                'capped' => $capped,
                'costs' => $costs,
            ];
        });
    }

    /** Позиции карточек и связи — чтобы клиент перерисовал доску без перезагрузки. */
    public function boardState(int $versionId): array
    {
        $nodes = [];
        foreach ($this->db->all(
            'SELECT id, tree_id, version_era_id, lane, row_index, global_column
               FROM tree_version_nodes WHERE version_id = ?',
            [$versionId]
        ) as $row) {
            $nodes[] = [
                'id' => (int) $row['id'], 'tree' => (int) $row['tree_id'],
                'era_id' => (int) $row['version_era_id'], 'lane' => (int) $row['lane'],
                'row' => (int) $row['row_index'], 'col' => (int) $row['global_column'],
            ];
        }

        $links = [];
        foreach ($this->db->all(
            'SELECT l.from_node_id, l.to_node_id, l.origin, n.tree_id
               FROM tree_version_links l JOIN tree_version_nodes n ON n.id = l.from_node_id
              WHERE l.version_id = ?',
            [$versionId]
        ) as $row) {
            $links[] = [
                'from' => (int) $row['from_node_id'], 'to' => (int) $row['to_node_id'],
                'origin' => $row['origin'], 'tree' => (int) $row['tree_id'],
            ];
        }

        // число столбцов могло измениться: эпоха растёт, когда карточке
        // не хватило места, и сжимается, когда столбец опустел
        $lanes = [];
        foreach ($this->db->all(
            'SELECT l.version_era_id, l.tree_id, l.lanes, tve.era_id
               FROM tree_version_era_lanes l
               JOIN tree_version_eras tve ON tve.id = l.version_era_id
              WHERE tve.version_id = ?',
            [$versionId]
        ) as $row) {
            $lanes[] = [
                'tree' => (int) $row['tree_id'],
                'era_id' => (int) $row['version_era_id'],
                'lanes' => (int) $row['lanes'],
            ];
        }

        return [
            'nodes' => $nodes, 'links' => $links, 'lanes' => $lanes,
            'problems' => $this->validate($versionId),
        ];
    }

    /**
     * Проверка правила столбцов на сохранённой версии: возвращает список
     * нарушений. Пустой список — доска корректна.
     */
    public function validate(int $versionId): array
    {
        $problems = [];
        $rows = $this->db->all(
            'SELECT n.id, n.tree_id, n.global_column, n.lane, t.name,
                    (SELECT COUNT(*) FROM tree_version_links l
                      WHERE l.version_id = n.version_id AND l.to_node_id = n.id) AS in_total,
                    (SELECT COUNT(*) FROM tree_version_links l
                       JOIN tree_version_nodes s ON s.id = l.from_node_id
                      WHERE l.version_id = n.version_id AND l.to_node_id = n.id
                        -- global_column беззнаковый: у нулевого столбца
                        -- «минус один» без приведения выходит за диапазон
                        AND s.global_column = CAST(n.global_column AS SIGNED) - 1) AS in_prev,
                    (SELECT COUNT(*) FROM tree_version_links l
                       JOIN tree_version_nodes s ON s.id = l.from_node_id
                      WHERE l.version_id = n.version_id AND l.to_node_id = n.id
                        AND s.global_column >= n.global_column) AS in_wrong
               FROM tree_version_nodes n
               JOIN technologies t ON t.id = n.technology_id
              WHERE n.version_id = ?',
            [$versionId]
        );

        foreach ($rows as $row) {
            $col = (int) $row['global_column'];
            if ($col === 0) {
                if ((int) $row['in_total'] > 0) {
                    $problems[] = sprintf('«%s»: корневая технология не может иметь зависимостей', $row['name']);
                }
                continue;
            }
            if ((int) $row['in_wrong'] > 0) {
                $problems[] = sprintf('«%s»: зависимость лежит не левее', $row['name']);
            }
            if ((int) $row['in_total'] === 0) {
                $problems[] = sprintf('«%s» (столбец %d): нет ни одной зависимости', $row['name'], $col + 1);
            } elseif ((int) $row['in_prev'] === 0 && (int) $row['lane'] !== 0) {
                $problems[] = sprintf('«%s» (столбец %d): нет связи с предыдущим столбцом', $row['name'], $col + 1);
            }
        }

        return $problems;
    }
}

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
     * Отсчёт начинается со 130 за технологию первого столбца.
     * Каждый следующий столбец дороже предыдущего в COST_STEP раз, внутри
     * столбца стоимости расходятся на ±COST_JITTER. Разброс подобран так,
     * чтобы диапазоны соседних столбцов не пересекались: самая дорогая
     * технология столбца (1 + 0.15 = 1.15 базы) дешевле самой дешёвой
     * технологии следующего (1.5 × 0.85 = 1.275 базы).
     */
    private const COST_BASE = 130;
    private const COST_STEP = 1.5;
    private const COST_JITTER = 0.15;

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
                     cost_base, parent_version_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    ($name === null || $name === '') ? null : $name,
                    $seedCode,
                    json_encode(array_map('intval', $seed)),
                    $seed[0], $seed[1], $seed[2], self::COST_BASE, $parentId,
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
                foreach ($layout['nodes'] as $techId => $n) {
                    $nodeIdByTech[$techId] = $db->insert(
                        'INSERT INTO tree_version_nodes
                            (version_id, tree_id, technology_id, version_era_id,
                             lane, row_index, global_column, cost, source, is_relaxed)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'standard\', ?)',
                        [
                            $versionId, $tree['id'], $techId, $versionEraId[$n['era_id']],
                            $n['lane'], $n['row'], $n['global_column'],
                            $baseCost[$techId] ?? self::costFor((int) $n['global_column'], $costRng, $costBase),
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
            'SELECT tve.id, tve.era_id, tve.position, COALESCE(tve.name_override, e.name) AS name
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
    public static function costFor(int $globalColumn, Rng $rng, ?int $costBase = null): int
    {
        $base = ($costBase ?? self::COST_BASE) * pow(self::COST_STEP, $globalColumn);
        $jitter = 1.0 + (($rng->next() * 2) - 1) * self::COST_JITTER;

        return max(1, (int) round($base * $jitter));
    }

    /** Границы диапазона стоимостей столбца — для подсказок в интерфейсе. */
    public static function costRange(int $globalColumn, ?int $costBase = null): array
    {
        $base = ($costBase ?? self::COST_BASE) * pow(self::COST_STEP, $globalColumn);

        return [
            (int) round($base * (1 - self::COST_JITTER)),
            (int) round($base * (1 + self::COST_JITTER)),
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
     * если основ нет — в первый столбец своей эпохи. Эпоха при этом
     * не меняется, поэтому результат зажимается в границы её столбцов.
     * Перестановка идёт волнами: сдвиг одной карточки может потянуть
     * за собой зависящие от неё.
     *
     * @return int сколько карточек переехало
     */
    public function reposition(int $versionId): int
    {
        $movedTotal = 0;

        foreach ($this->db->all('SELECT id FROM trees ORDER BY position, id') as $tree) {
            $treeId = (int) $tree['id'];
            $ranges = $this->eraColumnRanges($versionId, $treeId);

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

            // волны: 40 проходов с запасом, обычно хватает двух-трёх
            for ($pass = 0; $pass < 40; $pass++) {
                $order = array_keys($nodes);
                usort($order, static function ($a, $b) use ($nodes) {
                    return $nodes[$a]['col'] <=> $nodes[$b]['col'];
                });

                $changed = false;
                foreach ($order as $id) {
                    $range = $ranges[$nodes[$id]['era']] ?? null;
                    if ($range === null) {
                        continue;
                    }
                    $desired = $range['first'];
                    foreach ($incoming[$id] ?? [] as $srcId) {
                        if (isset($nodes[$srcId])) {
                            $desired = max($desired, $nodes[$srcId]['col'] + 1);
                        }
                    }
                    $desired = min(max($desired, $range['first']), $range['last']);
                    if ($desired !== $nodes[$id]['col']) {
                        $nodes[$id]['col'] = $desired;
                        $nodes[$id]['lane'] = $desired - $range['first'];
                        $changed = true;
                    }
                }
                if (!$changed) {
                    break;
                }
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
                // обратная связь того же ребра — это цикл
                $back = (int) $db->value(
                    'SELECT COUNT(*) FROM tree_version_links
                      WHERE version_id = ? AND from_node_id = ? AND to_node_id = ?',
                    [$versionId, $toNodeId, $fromNodeId]
                );
                if ($back > 0) {
                    throw new UserError('Обратная связь уже есть — получилось бы кольцо');
                }

                // Столбцы не проверяем заранее: связь допускается и между
                // карточками одного столбца. Правильные позиции посчитает
                // reposition() — зависимая уедет правее, а основа встанет
                // настолько левее, насколько позволяют её собственные основы
                // и границы эпохи. Если после этого связь всё равно
                // не укладывается слева направо, ниже мы её отклоним.
                $db->run(
                    'INSERT INTO tree_version_links (version_id, from_node_id, to_node_id, origin)
                     VALUES (?, ?, ?, \'manual\')',
                    [$versionId, $fromNodeId, $toNodeId]
                );
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
            $this->reposition($versionId);

            // после перестановки связь обязана идти строго слева направо;
            // если места не нашлось — откатываем всю правку
            if (!$exists) {
                $bad = $db->one(
                    'SELECT sf.global_column AS from_col, st.global_column AS to_col
                       FROM tree_version_nodes sf, tree_version_nodes st
                      WHERE sf.id = ? AND st.id = ?',
                    [$fromNodeId, $toNodeId]
                );
                if ($bad !== null && (int) $bad['from_col'] >= (int) $bad['to_col']) {
                    throw new UserError(
                        'Так нельзя: «' . $from['name'] . '» некуда сдвинуть левее, '
                        . 'а «' . $to['name'] . '» — правее в пределах своей эпохи'
                    );
                }
            }

            return $this->boardState($versionId);
        });
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
        $reach = 3;
        $inc = array_flip($incoming);
        $out = array_flip($outgoing);
        $before = [];
        $after = [];
        foreach ($all as $row) {
            $rowCol = (int) $row['global_column'];
            $id = (int) $row['id'];
            if ($rowCol < $col && ($rowCol >= $col - $reach || isset($inc[$id]))) {
                $before[] = $row;
            } elseif ($rowCol > $col && ($rowCol <= $col + $reach || isset($out[$id]))) {
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
                'SELECT id, global_column FROM tree_version_nodes
                  WHERE version_id = ? AND tree_id = ?',
                [$versionId, $node['tree_id']]
            ) as $row) {
                $valid[(int) $row['id']] = (int) $row['global_column'];
            }
            $col = (int) $node['global_column'];

            $db->run('DELETE FROM tree_version_links WHERE version_id = ? AND to_node_id = ?',
                [$versionId, $nodeId]);
            $db->run('DELETE FROM tree_version_links WHERE version_id = ? AND from_node_id = ?',
                [$versionId, $nodeId]);

            foreach (array_unique(array_map('intval', $incoming)) as $srcId) {
                if ($srcId !== $nodeId && isset($valid[$srcId]) && $valid[$srcId] < $col) {
                    $db->run(
                        'INSERT IGNORE INTO tree_version_links
                            (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, \'manual\')',
                        [$versionId, $srcId, $nodeId]
                    );
                }
            }
            foreach (array_unique(array_map('intval', $outgoing)) as $dstId) {
                if ($dstId !== $nodeId && isset($valid[$dstId]) && $valid[$dstId] > $col) {
                    $db->run(
                        'INSERT IGNORE INTO tree_version_links
                            (version_id, from_node_id, to_node_id, origin) VALUES (?, ?, ?, \'manual\')',
                        [$versionId, $nodeId, $dstId]
                    );
                }
            }

            $db->run('UPDATE tree_versions SET status = \'edited\' WHERE id = ?', [$versionId]);
            $this->reposition($versionId);
        });
    }

    /**
     * Пересчёт стоимостей по правилу столбцов.
     *
     * Среднее столбца = cost_base × 1.5^номер, внутри столбца стоимости
     * расходятся на ±15%. Область задаётся: вся версия, одна эпоха
     * или один столбец.
     *
     * Если передан $newAverage, он трактуется как желаемое среднее для
     * столбца $scopeColumn: база версии пересчитывается так, чтобы
     * средние во всех остальных столбцах поехали за ним по тому же
     * коэффициенту 1.5.
     *
     * @param string $scope 'version' | 'era' | 'column'
     */
    public function recalcCosts(
        int $versionId,
        string $scope = 'version',
        ?int $treeId = null,
        ?int $versionEraId = null,
        ?int $column = null,
        ?int $newAverage = null
    ): array {
        return $this->db->transaction(function (Db $db) use (
            $versionId, $scope, $treeId, $versionEraId, $column, $newAverage
        ) {
            if ($newAverage !== null) {
                if ($newAverage < 1) {
                    throw new UserError('Среднее должно быть положительным числом');
                }
                $base = (int) round($newAverage / pow(self::COST_STEP, max(0, (int) $column)));
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
            foreach ($db->all($sql, $params) as $row) {
                $cost = self::costFor((int) $row['global_column'], $rng, $costBase);
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

            return ['cost_base' => $costBase, 'costs' => $costs];
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

        return ['nodes' => $nodes, 'links' => $links, 'problems' => $this->validate($versionId)];
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
                        AND s.global_column = n.global_column - 1) AS in_prev,
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

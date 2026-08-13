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
                    (name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout, parent_version_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    ($name === null || $name === '') ? null : $name,
                    $seedCode,
                    json_encode(array_map('intval', $seed)),
                    $seed[0], $seed[1], $seed[2], $parentId,
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
                    $lanes = $generator->rollLanes($count, $layoutRng);
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
                    'SELECT id, default_era_id AS era_id, branch_id FROM technologies
                      WHERE is_standard = 1 AND tree_id = ? ORDER BY id',
                    [$tree['id']]
                );
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

                foreach ($layout['nodes'] as $techId => $n) {
                    $nodeIdByTech[$techId] = $db->insert(
                        'INSERT INTO tree_version_nodes
                            (version_id, tree_id, technology_id, version_era_id,
                             lane, row_index, global_column, source, is_relaxed)
                         VALUES (?, ?, ?, ?, ?, ?, ?, \'standard\', ?)',
                        [
                            $versionId, $tree['id'], $techId, $versionEraId[$n['era_id']],
                            $n['lane'], $n['row'], $n['global_column'], $n['relaxed'] ? 1 : 0,
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
                    n.global_column, n.source, n.is_relaxed,
                    t.code, t.name, t.image_path, t.description, t.historical_note,
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

        $trees = $this->db->all('SELECT id, code, name, points_name, boost_name FROM trees ORDER BY position, id');

        return [
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

<?php
declare(strict_types=1);

namespace Civi;

/**
 * Каталог: деревья, эпохи, категории технологий, сами технологии,
 * их игровые эффекты и виды эффектов.
 *
 * Всё, что здесь помечено is_standard = 1, участвует в генерации
 * каждой новой версии деревьев.
 */
final class Catalog
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    //  Деревья и эпохи
    // ------------------------------------------------------------------

    public function trees(): array
    {
        return $this->db->all('SELECT * FROM trees ORDER BY position, id');
    }

    public function eras(): array
    {
        return $this->db->all('SELECT * FROM eras ORDER BY default_position, id');
    }

    // ------------------------------------------------------------------
    //  Категории технологий (они же ветки)
    // ------------------------------------------------------------------

    public function categories(?int $treeId = null): array
    {
        $sql = 'SELECT b.*, tr.code AS tree_code, tr.name AS tree_name,
                       (SELECT COUNT(*) FROM technologies t WHERE t.branch_id = b.id) AS tech_count
                  FROM branches b JOIN trees tr ON tr.id = b.tree_id';
        $params = [];
        if ($treeId !== null) {
            $sql .= ' WHERE b.tree_id = ?';
            $params[] = $treeId;
        }
        $sql .= ' ORDER BY b.tree_id, b.position, b.id';

        return $this->db->all($sql, $params);
    }

    public function findCategory(int $id): ?array
    {
        return $this->db->one('SELECT * FROM branches WHERE id = ?', [$id]);
    }

    public function saveCategory(?int $id, array $data): int
    {
        $color = $this->normalizeColor((string) ($data['color'] ?? ''));
        if ($id === null) {
            return $this->db->insert(
                'INSERT INTO branches (tree_id, code, name, color, description, position, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $data['tree_id'],
                    $this->slug((string) ($data['code'] ?? ''), (string) $data['name']),
                    trim((string) $data['name']),
                    $color,
                    $this->nullable($data['description'] ?? null),
                    (int) ($data['position'] ?? 1),
                    empty($data['is_active']) ? 0 : 1,
                ]
            );
        }

        $this->db->run(
            'UPDATE branches SET name = ?, color = ?, description = ?, position = ?, is_active = ?
              WHERE id = ?',
            [
                trim((string) $data['name']),
                $color,
                $this->nullable($data['description'] ?? null),
                (int) ($data['position'] ?? 1),
                empty($data['is_active']) ? 0 : 1,
                $id,
            ]
        );

        return $id;
    }

    /** Категорию нельзя удалить, пока в ней есть технологии. */
    public function deleteCategory(int $id): void
    {
        $count = (int) $this->db->value('SELECT COUNT(*) FROM technologies WHERE branch_id = ?', [$id]);
        if ($count > 0) {
            throw new \RuntimeException(
                'В категории ' . $count . ' технологий — сначала перенесите их в другую категорию'
            );
        }
        $this->db->run('DELETE FROM branches WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------
    //  Технологии
    // ------------------------------------------------------------------

    public function technologies(array $filter = []): array
    {
        $sql = 'SELECT t.*, b.name AS branch_name, b.color AS branch_color,
                       e.name AS era_name, e.default_position AS era_position,
                       tr.code AS tree_code,
                       (SELECT COUNT(*) FROM technology_effects x WHERE x.technology_id = t.id) AS effect_count
                  FROM technologies t
                  JOIN branches b ON b.id = t.branch_id
                  JOIN eras e     ON e.id = t.default_era_id
                  JOIN trees tr   ON tr.id = t.tree_id
                 WHERE 1 = 1';
        $params = [];
        if (!empty($filter['tree_id'])) {
            $sql .= ' AND t.tree_id = :tree_id';
            $params['tree_id'] = (int) $filter['tree_id'];
        }
        if (!empty($filter['branch_id'])) {
            $sql .= ' AND t.branch_id = :branch_id';
            $params['branch_id'] = (int) $filter['branch_id'];
        }
        if (!empty($filter['era_id'])) {
            $sql .= ' AND t.default_era_id = :era_id';
            $params['era_id'] = (int) $filter['era_id'];
        }
        if (isset($filter['is_standard']) && $filter['is_standard'] !== '') {
            $sql .= ' AND t.is_standard = :is_standard';
            $params['is_standard'] = (int) $filter['is_standard'];
        }
        if (!empty($filter['q'])) {
            $sql .= ' AND (t.name LIKE :q OR t.code LIKE :q)';
            $params['q'] = '%' . $filter['q'] . '%';
        }
        $sql .= ' ORDER BY t.tree_id, e.default_position, b.position, t.name';
        if (!empty($filter['limit'])) {
            $sql .= ' LIMIT ' . (int) $filter['limit'];
        }

        return $this->db->all($sql, $params);
    }

    public function findTechnology(int $id): ?array
    {
        return $this->db->one('SELECT * FROM technologies WHERE id = ?', [$id]);
    }

    public function saveTechnology(?int $id, array $data): int
    {
        $fields = [
            trim((string) $data['name']),
            (int) $data['branch_id'],
            (int) $data['default_era_id'],
            empty($data['is_standard']) ? 0 : 1,
            $this->nullable($data['image_path'] ?? null),
            $this->nullable($data['description'] ?? null),
            $this->nullable($data['historical_note'] ?? null),
        ];

        if ($id === null) {
            $treeId = (int) $data['tree_id'];
            $code = $this->slug((string) ($data['code'] ?? ''), (string) $data['name']);
            $code = $this->uniqueCode($code);
            $id = $this->db->insert(
                'INSERT INTO technologies
                    (tree_id, code, name, branch_id, default_era_id, is_standard,
                     image_path, description, historical_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([$treeId, $code], $fields)
            );
        } else {
            $this->db->run(
                'UPDATE technologies SET name = ?, branch_id = ?, default_era_id = ?, is_standard = ?,
                        image_path = ?, description = ?, historical_note = ?
                  WHERE id = ?',
                array_merge($fields, [$id])
            );
        }

        if (array_key_exists('prereqs', $data)) {
            $this->saveTechnologyPrereqs($id, array_map('intval', (array) $data['prereqs']));
        }

        return $id;
    }

    public function deleteTechnology(int $id): void
    {
        $used = (int) $this->db->value(
            'SELECT COUNT(*) FROM tree_version_nodes WHERE technology_id = ?', [$id]
        );
        if ($used > 0) {
            throw new \RuntimeException(
                'Технология стоит на досках ' . $used . ' версий — сначала уберите её оттуда'
            );
        }
        $this->db->run('DELETE FROM technologies WHERE id = ?', [$id]);
    }

    public function technologyPrereqs(int $id): array
    {
        return array_map('intval', $this->db->run(
            'SELECT prereq_technology_id FROM technology_prereqs WHERE technology_id = ?', [$id]
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Пререквизиты только из более ранних эпох и того же дерева. */
    public function prereqCandidates(int $treeId, int $eraId, ?int $excludeId = null): array
    {
        return $this->db->all(
            'SELECT t.id, t.name, e.name AS era_name, e.default_position
               FROM technologies t
               JOIN eras e ON e.id = t.default_era_id
              WHERE t.tree_id = ?
                AND e.default_position < (SELECT default_position FROM eras WHERE id = ?)
                AND (? IS NULL OR t.id <> ?)
              ORDER BY e.default_position, t.name',
            [$treeId, $eraId, $excludeId, $excludeId]
        );
    }

    private function saveTechnologyPrereqs(int $id, array $prereqIds): void
    {
        $this->db->run('DELETE FROM technology_prereqs WHERE technology_id = ?', [$id]);
        foreach (array_unique($prereqIds) as $prereqId) {
            if ($prereqId === $id) {
                continue;
            }
            $this->db->run(
                'INSERT IGNORE INTO technology_prereqs (technology_id, prereq_technology_id) VALUES (?, ?)',
                [$id, $prereqId]
            );
        }
    }

    // ------------------------------------------------------------------
    //  Виды эффектов и эффекты технологий
    // ------------------------------------------------------------------

    public function effectTypes(bool $onlyActive = false): array
    {
        $sql = 'SELECT et.*, (SELECT COUNT(*) FROM technology_effects e
                               WHERE e.effect_type_id = et.id) AS usage_count
                  FROM effect_types et';
        if ($onlyActive) {
            $sql .= ' WHERE et.is_active = 1';
        }
        $sql .= ' ORDER BY et.position, et.id';

        return $this->db->all($sql);
    }

    public function saveEffectType(?int $id, array $data): int
    {
        $schema = trim((string) ($data['payload_schema'] ?? ''));
        if ($schema !== '' && json_decode($schema) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Поле «схема параметров» должно быть корректным JSON');
        }

        if ($id === null) {
            return $this->db->insert(
                'INSERT INTO effect_types (code, name, description, payload_schema, position, is_active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $this->slug((string) ($data['code'] ?? ''), (string) $data['name']),
                    trim((string) $data['name']),
                    $this->nullable($data['description'] ?? null),
                    $schema === '' ? null : $schema,
                    (int) ($data['position'] ?? 1),
                    empty($data['is_active']) ? 0 : 1,
                ]
            );
        }

        $this->db->run(
            'UPDATE effect_types SET name = ?, description = ?, payload_schema = ?, position = ?, is_active = ?
              WHERE id = ?',
            [
                trim((string) $data['name']),
                $this->nullable($data['description'] ?? null),
                $schema === '' ? null : $schema,
                (int) ($data['position'] ?? 1),
                empty($data['is_active']) ? 0 : 1,
                $id,
            ]
        );

        return $id;
    }

    public function deleteEffectType(int $id): void
    {
        $used = (int) $this->db->value('SELECT COUNT(*) FROM technology_effects WHERE effect_type_id = ?', [$id]);
        if ($used > 0) {
            throw new \RuntimeException(
                'Вид используется в ' . $used . ' эффектах — отключите его вместо удаления'
            );
        }
        $this->db->run('DELETE FROM effect_types WHERE id = ?', [$id]);
    }

    public function effectsOf(int $technologyId): array
    {
        return $this->db->all(
            'SELECT e.*, et.name AS type_name, et.code AS type_code
               FROM technology_effects e JOIN effect_types et ON et.id = e.effect_type_id
              WHERE e.technology_id = ? ORDER BY e.position, e.id',
            [$technologyId]
        );
    }

    public function saveEffect(?int $id, int $technologyId, array $data): int
    {
        $payload = trim((string) ($data['payload'] ?? ''));
        if ($payload !== '' && json_decode($payload) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Поле «параметры» должно быть корректным JSON');
        }

        if ($id === null) {
            $position = (int) $this->db->value(
                'SELECT COALESCE(MAX(position) + 1, 1) FROM technology_effects WHERE technology_id = ?',
                [$technologyId]
            );

            return $this->db->insert(
                'INSERT INTO technology_effects
                    (technology_id, effect_type_id, title, description, payload, position)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $technologyId,
                    (int) $data['effect_type_id'],
                    trim((string) $data['title']),
                    $this->nullable($data['description'] ?? null),
                    $payload === '' ? null : $payload,
                    $position,
                ]
            );
        }

        $this->db->run(
            'UPDATE technology_effects SET effect_type_id = ?, title = ?, description = ?, payload = ?
              WHERE id = ? AND technology_id = ?',
            [
                (int) $data['effect_type_id'],
                trim((string) $data['title']),
                $this->nullable($data['description'] ?? null),
                $payload === '' ? null : $payload,
                $id,
                $technologyId,
            ]
        );

        return $id;
    }

    public function deleteEffect(int $id): void
    {
        $this->db->run('DELETE FROM technology_effects WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------

    private function nullable($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeColor(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#888888';
    }

    /** Латинский код из явного значения или из названия (с транслитерацией). */
    private function slug(string $explicit, string $fallback): string
    {
        $source = trim($explicit) !== '' ? $explicit : $fallback;
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $slug = strtr(mb_strtolower($source, 'UTF-8'), $map);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');

        return $slug === '' ? 'item_' . substr(md5($source . microtime()), 0, 8) : substr($slug, 0, 80);
    }

    private function uniqueCode(string $code): string
    {
        $candidate = $code;
        $i = 2;
        while ((int) $this->db->value('SELECT COUNT(*) FROM technologies WHERE code = ?', [$candidate]) > 0) {
            $candidate = substr($code, 0, 88) . '_' . $i++;
        }

        return $candidate;
    }
}

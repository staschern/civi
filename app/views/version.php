<?php
require_once __DIR__ . '/layout.php';
$version = $data['version'];
layout_start($version['name'] ?? ('Версия №' . $version['id']), $page);

// доска рисуется на клиенте по этим данным
$board = ['trees' => [], 'version_id' => (int) $version['id']];
$erasById = [];
foreach ($data['eras'] as $era) { $erasById[$era['id']] = $era; }
foreach ($data['trees'] as $tree) {
    $columns = [];
    foreach ($data['eras'] as $era) {
        $lanes = $data['lanes'][$tree['id']][$era['id']] ?? 2;
        $columns[] = ['era_id' => (int) $era['id'], 'name' => $era['name'], 'lanes' => (int) $lanes];
    }
    $nodes = [];
    foreach ($data['nodes'] as $n) {
        if ((int) $n['tree_id'] !== (int) $tree['id']) { continue; }
        $nodes[] = [
            'id' => (int) $n['id'], 'era_id' => (int) $n['version_era_id'],
            'lane' => (int) $n['lane'], 'row' => (int) $n['row_index'],
            'col' => (int) $n['global_column'], 'name' => $n['name'],
            'branch' => $n['branch_name'], 'color' => $n['branch_color'],
            'source' => $n['source'], 'image' => $n['image_path'],
            'tech_id' => (int) $n['technology_id'],
        ];
    }
    $board['trees'][] = [
        'id' => (int) $tree['id'], 'code' => $tree['code'], 'name' => $tree['name'],
        'eras' => $columns, 'nodes' => $nodes,
    ];
}
$nodeTree = [];
foreach ($data['nodes'] as $n) { $nodeTree[(int) $n['id']] = (int) $n['tree_id']; }
$board['links'] = [];
foreach ($data['links'] as $l) {
    $board['links'][] = [
        'from' => (int) $l['from_node_id'], 'to' => (int) $l['to_node_id'],
        'origin' => $l['origin'], 'tree' => $nodeTree[(int) $l['from_node_id']] ?? 0,
    ];
}
?>
<link rel="stylesheet" href="assets/board.css">

<div class="page-head">
  <div>
    <h1><?= h($version['name'] ?? ('Версия №' . $version['id'])) ?></h1>
    <p class="hint">
      семя <code><?= h($version['seed_code']) ?></code> ·
      <?= $version['status'] === 'edited' ? 'правлена руками' : 'как сгенерирована' ?> ·
      создана <?= h($version['created_at']) ?>
    </p>
  </div>
  <div class="row">
    <form method="post" action="<?= h(url('version-rename')) ?>" class="row">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $version['id'] ?>">
      <input type="text" name="name" value="<?= h($version['name'] ?? '') ?>" placeholder="название версии">
      <button type="submit">Переименовать</button>
    </form>
    <a class="button" href="<?= h(url('versions')) ?>">К списку</a>
  </div>
</div>

<?php if ($problems !== []): ?>
  <div class="flash error">
    <strong>Правило столбцов нарушено:</strong>
    <ul><?php foreach (array_slice($problems, 0, 10) as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul>
    <?php if (count($problems) > 10): ?><div>…и ещё <?= count($problems) - 10 ?></div><?php endif; ?>
  </div>
<?php else: ?>
  <div class="flash ok">Правило столбцов соблюдено на всей доске.</div>
<?php endif; ?>

<section class="panel">
  <h2>Добавить технологию в эту версию</h2>
  <p class="hint">Сюда попадают и технологии вне стандартного набора — в других версиях
    при генерации они не появятся.</p>
  <form method="post" action="<?= h(url('version-add-tech')) ?>" class="row wrap">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
    <input type="hidden" name="back" value="versions">
    <label>Технология
      <select name="technology_id" required>
        <option value="">— выберите —</option>
        <?php
        $onBoard = [];
        foreach ($data['nodes'] as $n) { $onBoard[(int) $n['technology_id']] = true; }
        $currentGroup = null;
        foreach ($available as $t):
            if (isset($onBoard[(int) $t['id']])) { continue; }   // уже на доске
            $group = $t['tree_code'] . ' · ' . $t['era_name'];
            if ($group !== $currentGroup):
                if ($currentGroup !== null): ?></optgroup><?php endif;
                $currentGroup = $group; ?>
                <optgroup label="<?= h($group) ?>">
            <?php endif; ?>
            <option value="<?= (int) $t['id'] ?>">
              <?= h($t['name']) ?><?= $t['is_standard'] ? '' : ' (вне стандартного набора)' ?>
            </option>
        <?php endforeach; ?>
        <?php if ($currentGroup !== null): ?></optgroup><?php endif; ?>
      </select>
    </label>
    <label>Столбец внутри эпохи
      <input type="number" name="lane" min="0" value="0" style="width: 6em">
    </label>
    <button type="submit" class="primary">Добавить</button>
  </form>
</section>

<div id="board" data-board="<?= h(json_encode($board, JSON_UNESCAPED_UNICODE)) ?>"></div>

<form method="post" action="<?= h(url('version-remove-node')) ?>" id="remove-form" style="display:none">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="version_id" value="<?= (int) $version['id'] ?>">
  <input type="hidden" name="node_id" id="remove-node-id">
</form>

<script src="assets/board.js"></script>
<?php layout_end(); ?>

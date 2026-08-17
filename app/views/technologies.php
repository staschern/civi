<?php
require_once __DIR__ . '/layout.php';
layout_start('Технологии', $page);
$f = $filter;
?>
<div class="page-head">
  <h1>Каталог технологий</h1>
  <a class="button primary" href="<?= h(url('technology')) ?>">Добавить технологию</a>
</div>
<p class="hint">Всё, что помечено «в стандартном наборе», попадает в каждую новую версию деревьев
  при генерации. Технологии вне набора остаются в каталоге и добавляются в версии вручную.</p>

<form method="get" class="row wrap filters">
  <input type="hidden" name="p" value="technologies">
  <label>Дерево
    <select name="tree_id" onchange="this.form.submit()">
      <option value="">все</option>
      <?php foreach ($trees as $t): ?>
        <option value="<?= (int) $t['id'] ?>" <?= (int) ($f['tree_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
          <?= h($t['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Категория
    <select name="branch_id" onchange="this.form.submit()">
      <option value="">все</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (int) ($f['branch_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
          <?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Эпоха
    <select name="era_id" onchange="this.form.submit()">
      <option value="">все</option>
      <?php foreach ($eras as $e): ?>
        <option value="<?= (int) $e['id'] ?>" <?= (int) ($f['era_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>>
          <?= h($e['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Набор
    <select name="is_standard" onchange="this.form.submit()">
      <option value="">любой</option>
      <option value="1" <?= ($f['is_standard'] ?? '') === '1' ? 'selected' : '' ?>>стандартный</option>
      <option value="0" <?= ($f['is_standard'] ?? '') === '0' ? 'selected' : '' ?>>вне набора</option>
    </select>
  </label>
  <label>Поиск
    <input type="search" name="q" value="<?= h($f['q'] ?? '') ?>" placeholder="название или код">
  </label>
  <button type="submit">Показать</button>
  <a class="button" href="<?= h(url('technologies')) ?>">Сбросить</a>
</form>

<p class="hint">Найдено: <?= count($list) ?></p>

<table class="grid">
  <thead><tr><th></th><th>Название</th><th>Категория</th><th>Эпоха</th><th>Набор</th><th>Эффектов</th></tr></thead>
  <tbody>
  <?php foreach ($list as $row): ?>
    <tr>
      <td class="thumb">
        <?php if ($row['image_path']): ?>
          <img src="<?= h(image_url($row['image_path'])) ?>" alt="">
        <?php endif; ?>
      </td>
      <td>
        <a class="strong" href="<?= h(url('technology', ['id' => $row['id']])) ?>"><?= h($row['name']) ?></a>
        <div class="code"><?= h($row['code']) ?></div>
      </td>
      <td><span class="swatch" style="background: <?= h($row['branch_color']) ?>"></span><?= h($row['branch_name']) ?></td>
      <td><?= h($row['era_name']) ?></td>
      <td><?= $row['is_standard'] ? 'стандартный' : '<span class="tag">вне набора</span>' ?></td>
      <td><?= $row['base_cost'] !== null ? money($row['base_cost']) : '<span class="hint">по столбцу</span>' ?></td>
      <td><?= (int) $row['effect_count'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($list === []): ?>
    <tr><td colspan="7" class="hint">Ничего не найдено.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php layout_end(); ?>

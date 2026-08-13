<?php
require_once __DIR__ . '/layout.php';
layout_start('Категории технологий', $page);
$treeName = [];
foreach ($trees as $t) { $treeName[$t['id']] = $t['name']; }
?>
<h1>Категории технологий</h1>
<p class="hint">Категория задаёт смысловое направление и цвет карточки на доске.
  Внутри одной эпохи не должно быть двух технологий одной категории — на этом держится
  безопасность перемешивания.</p>

<div class="two-col">
  <section>
    <table class="grid">
      <thead><tr><th>Название</th><th>Дерево</th><th>Цвет</th><th>Порядок</th><th>Технологий</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $row): ?>
        <tr<?= $row['is_active'] ? '' : ' class="muted"' ?>>
          <td>
            <span class="swatch" style="background: <?= h($row['color']) ?>"></span>
            <?= h($row['name']) ?>
            <?php if (!$row['is_active']): ?><span class="tag">выключена</span><?php endif; ?>
            <div class="code"><?= h($row['code']) ?></div>
          </td>
          <td><?= h($treeName[$row['tree_id']] ?? '') ?></td>
          <td><code><?= h($row['color']) ?></code></td>
          <td><?= (int) $row['position'] ?></td>
          <td><?= (int) $row['tech_count'] ?></td>
          <td class="actions">
            <a href="<?= h(url('categories', ['id' => $row['id']])) ?>">изменить</a>
            <?php if ((int) $row['tech_count'] === 0): ?>
              <form method="post" action="<?= h(url('category-delete')) ?>"
                    onsubmit="return confirm('Удалить категорию?')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="back" value="categories">
                <button type="submit" class="link danger">удалить</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="side">
    <h2><?= $edit ? 'Изменить категорию' : 'Новая категория' ?></h2>
    <form method="post" action="<?= h(url('category-save')) ?>" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="back" value="categories">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <label>Название
        <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>">
      </label>
      <?php if (!$edit): ?>
        <label>Дерево
          <select name="tree_id">
            <?php foreach ($trees as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Код <span class="hint">латиницей; пусто — сделаем из названия</span>
          <input type="text" name="code" value="">
        </label>
      <?php endif; ?>
      <label>Цвет
        <input type="color" name="color" value="<?= h($edit['color'] ?? '#2f6f76') ?>">
      </label>
      <label>Описание
        <textarea name="description" rows="3"><?= h($edit['description'] ?? '') ?></textarea>
      </label>
      <label>Порядок в легенде
        <input type="number" name="position" min="1" value="<?= (int) ($edit['position'] ?? 1) ?>">
      </label>
      <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
        активна
      </label>
      <div class="row">
        <button type="submit" class="primary">Сохранить</button>
        <?php if ($edit): ?><a class="button" href="<?= h(url('categories')) ?>">Отмена</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>
<?php layout_end(); ?>

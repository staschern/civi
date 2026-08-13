<?php
require_once __DIR__ . '/layout.php';
layout_start('Виды игровых эффектов', $page);
?>
<h1>Виды игровых эффектов</h1>
<p class="hint">Что технология может добавлять в игру. Список открытый: заводите новые виды здесь,
  миграция для этого не нужна. «Схема параметров» — подсказка, какие поля заполнять
  у эффекта конкретного вида.</p>

<div class="two-col">
  <section>
    <table class="grid">
      <thead><tr><th>Вид</th><th>Схема параметров</th><th>Порядок</th><th>Исп.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $row): ?>
        <tr<?= $row['is_active'] ? '' : ' class="muted"' ?>>
          <td>
            <?= h($row['name']) ?>
            <?php if (!$row['is_active']): ?><span class="tag">выключен</span><?php endif; ?>
            <div class="code"><?= h($row['code']) ?></div>
            <?php if ($row['description']): ?><div class="hint"><?= h($row['description']) ?></div><?php endif; ?>
          </td>
          <td><code class="json"><?= h($row['payload_schema'] ?? '—') ?></code></td>
          <td><?= (int) $row['position'] ?></td>
          <td><?= (int) $row['usage_count'] ?></td>
          <td class="actions">
            <a href="<?= h(url('effect-types', ['id' => $row['id']])) ?>">изменить</a>
            <?php if ((int) $row['usage_count'] === 0): ?>
              <form method="post" action="<?= h(url('effect-type-delete')) ?>"
                    onsubmit="return confirm('Удалить вид эффекта?')">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="back" value="effect-types">
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
    <h2><?= $edit ? 'Изменить вид' : 'Новый вид эффекта' ?></h2>
    <form method="post" action="<?= h(url('effect-type-save')) ?>" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="back" value="effect-types">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <label>Название
        <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>">
      </label>
      <?php if (!$edit): ?>
        <label>Код <span class="hint">латиницей; пусто — сделаем из названия</span>
          <input type="text" name="code" value="">
        </label>
      <?php endif; ?>
      <label>Описание
        <textarea name="description" rows="2"><?= h($edit['description'] ?? '') ?></textarea>
      </label>
      <label>Схема параметров (JSON)
        <textarea name="payload_schema" rows="4" class="mono"
                  placeholder='{"resource_code": "string", "percent": "number"}'><?= h($edit['payload_schema'] ?? '') ?></textarea>
      </label>
      <label>Порядок
        <input type="number" name="position" min="1" value="<?= (int) ($edit['position'] ?? 1) ?>">
      </label>
      <label class="check">
        <input type="checkbox" name="is_active" value="1" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
        активен
      </label>
      <div class="row">
        <button type="submit" class="primary">Сохранить</button>
        <?php if ($edit): ?><a class="button" href="<?= h(url('effect-types')) ?>">Отмена</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>
<?php layout_end(); ?>

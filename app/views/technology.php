<?php
require_once __DIR__ . '/layout.php';
$isNew = $tech === null;
layout_start($isNew ? 'Новая технология' : $tech['name'], $page);
$selectedPrereqs = array_flip($prereqs);
?>
<div class="page-head">
  <h1><?= $isNew ? 'Новая технология' : h($tech['name']) ?></h1>
  <a class="button" href="<?= h(url('technologies')) ?>">К списку</a>
</div>

<div class="two-col">
  <section>
    <form method="post" action="<?= h(url('technology-save')) ?>" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="back" value="technologies">
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $tech['id'] ?>"><?php endif; ?>

      <label>Название
        <input type="text" name="name" required value="<?= h($tech['name'] ?? '') ?>">
      </label>

      <?php if ($isNew): ?>
        <label>Дерево
          <select name="tree_id" required>
            <?php foreach ($trees as $t): ?>
              <option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Код <span class="hint">латиницей; пусто — сделаем из названия</span>
          <input type="text" name="code">
        </label>
      <?php else: ?>
        <p class="hint">Код: <code><?= h($tech['code']) ?></code></p>
      <?php endif; ?>

      <label>Категория
        <select name="branch_id" required>
          <?php foreach ($categories as $c): ?>
            <?php if (!$isNew && (int) $c['tree_id'] !== (int) $tech['tree_id']) { continue; } ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) ($tech['branch_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
              <?= h($c['tree_name']) ?> · <?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Эпоха
        <select name="default_era_id" required>
          <?php foreach ($eras as $e): ?>
            <option value="<?= (int) $e['id'] ?>" <?= (int) ($tech['default_era_id'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>>
              <?= (int) $e['default_position'] ?>. <?= h($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Стоимость
        <input type="number" name="base_cost" min="0" step="1"
               value="<?= $tech && $tech['base_cost'] !== null ? (int) $tech['base_cost'] : '' ?>"
               placeholder="считает генератор">
        <span class="hint">Пусто — стоимость рассчитывается по столбцу: каждый следующий
          примерно в полтора раза дороже предыдущего. Заданное здесь число сильнее
          расчётного и показывается на всех досках.</span>
      </label>

      <label class="check">
        <input type="checkbox" name="is_standard" value="1" <?= ($isNew || $tech['is_standard']) ? 'checked' : '' ?>>
        входит в стандартный набор
        <span class="hint">снимите галочку, если технология нужна только в отдельных версиях</span>
      </label>

      <label>Картинка
        <?php if (!$isNew && $tech['image_path']): ?>
          <div class="preview"><img src="<?= h(image_url($tech['image_path'])) ?>" alt=""></div>
        <?php endif; ?>
        <input type="file" name="image" accept="image/png,image/jpeg,image/gif,image/webp">
        <span class="hint">или путь вручную</span>
        <input type="text" name="image_path" value="<?= h($tech['image_path'] ?? '') ?>"
               placeholder="/uploads/tech/wheel.png">
      </label>

      <label>Описание технологии
        <textarea name="description" rows="4"><?= h($tech['description'] ?? '') ?></textarea>
      </label>

      <label>Историческая справка
        <textarea name="historical_note" rows="5"><?= h($tech['historical_note'] ?? '') ?></textarea>
      </label>

      <?php if (!$isNew): ?>
        <fieldset>
          <legend>Зависит от (технологии более ранних эпох)</legend>
          <?php if ($candidates === []): ?>
            <p class="hint">Нет технологий в более ранних эпохах.</p>
          <?php else: ?>
            <div class="checklist">
              <?php foreach ($candidates as $c): ?>
                <label class="check">
                  <input type="checkbox" name="prereqs[]" value="<?= (int) $c['id'] ?>"
                         <?= isset($selectedPrereqs[(int) $c['id']]) ? 'checked' : '' ?>>
                  <?= h($c['name']) ?> <span class="hint"><?= h($c['era_name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </fieldset>
      <?php endif; ?>

      <div class="row">
        <button type="submit" class="primary">Сохранить</button>
        <?php if (!$isNew): ?>
          <button type="submit" class="danger" form="delete-tech">Удалить</button>
        <?php endif; ?>
      </div>
    </form>

    <?php if (!$isNew): ?>
      <form method="post" action="<?= h(url('technology-delete')) ?>" id="delete-tech"
            onsubmit="return confirm('Удалить технологию из каталога?')">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $tech['id'] ?>">
        <input type="hidden" name="back" value="technologies">
      </form>
    <?php endif; ?>
  </section>

  <section class="side">
    <h2>Что добавляет в игру</h2>
    <?php if ($isNew): ?>
      <p class="hint">Сохраните технологию — после этого можно будет добавлять эффекты.</p>
    <?php else: ?>
      <p class="hint">Ресурсы, юниты, здания и их уровни, концепции, карточки, ускорение добычи.
        Список видов пополняется на странице «Виды эффектов».</p>

      <?php foreach ($effects as $e): ?>
        <div class="effect">
          <form method="post" action="<?= h(url('effect-save')) ?>" class="stack tight">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <input type="hidden" name="technology_id" value="<?= (int) $tech['id'] ?>">
            <input type="hidden" name="back" value="technologies">
            <div class="row">
              <select name="effect_type_id">
                <?php foreach ($effectTypes as $et): ?>
                  <option value="<?= (int) $et['id'] ?>" <?= (int) $e['effect_type_id'] === (int) $et['id'] ? 'selected' : '' ?>>
                    <?= h($et['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="title" value="<?= h($e['title']) ?>" required>
            </div>
            <textarea name="description" rows="2" placeholder="описание"><?= h($e['description'] ?? '') ?></textarea>
            <textarea name="payload" rows="2" class="mono" placeholder="параметры JSON"><?= h($e['payload'] ?? '') ?></textarea>
            <div class="row">
              <button type="submit">Сохранить</button>
              <button type="submit" class="link danger" form="del-effect-<?= (int) $e['id'] ?>">удалить</button>
            </div>
          </form>
          <form method="post" action="<?= h(url('effect-delete')) ?>" id="del-effect-<?= (int) $e['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <input type="hidden" name="technology_id" value="<?= (int) $tech['id'] ?>">
          </form>
        </div>
      <?php endforeach; ?>

      <div class="effect new">
        <form method="post" action="<?= h(url('effect-save')) ?>" class="stack tight">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="technology_id" value="<?= (int) $tech['id'] ?>">
          <input type="hidden" name="back" value="technologies">
          <div class="row">
            <select name="effect_type_id" required>
              <?php foreach ($effectTypes as $et): ?>
                <option value="<?= (int) $et['id'] ?>"><?= h($et['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="title" placeholder="что именно добавляет" required>
          </div>
          <textarea name="description" rows="2" placeholder="описание"></textarea>
          <textarea name="payload" rows="2" class="mono" placeholder='{"resource_code": "glass"}'></textarea>
          <button type="submit" class="primary">Добавить эффект</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php layout_end(); ?>

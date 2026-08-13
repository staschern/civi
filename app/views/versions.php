<?php
require_once __DIR__ . '/layout.php';
layout_start('Версии деревьев', $page);
?>
<h1>Версии деревьев</h1>
<p class="hint">Версия — пара деревьев (наука и культура), собранная по семени из стандартного набора
  каталога. Одно и то же семя всегда даёт одну и ту же раскладку, поэтому по коду семени
  версию можно найти и пересобрать.</p>

<section class="panel">
  <h2>Сгенерировать версию</h2>
  <form method="post" action="<?= h(url('version-generate')) ?>" class="row wrap">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label>Название <span class="hint">необязательно</span>
      <input type="text" name="name" placeholder="Основная ветка баланса">
    </label>
    <label>Семя <span class="hint">три числа; пусто — бросим сами</span>
      <input type="text" name="seed" placeholder="4821-7719-3045" pattern="[0-9\-\s]*">
    </label>
    <button type="submit" class="primary">Сгенерировать и сохранить</button>
  </form>
</section>

<form method="get" class="row search">
  <input type="hidden" name="p" value="versions">
  <input type="search" name="q" value="<?= h($search) ?>" placeholder="поиск по названию или семени">
  <button type="submit">Найти</button>
  <?php if ($search !== ''): ?><a class="button" href="<?= h(url('versions')) ?>">Сбросить</a><?php endif; ?>
</form>

<table class="grid">
  <thead><tr><th>Версия</th><th>Семя</th><th>Состояние</th><th>Карточек</th><th>Связей</th><th>Создана</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $row): ?>
    <tr>
      <td>
        <a class="strong" href="<?= h(url('version', ['id' => $row['id']])) ?>">
          <?= h($row['name'] ?? '(без названия)') ?></a>
        <?php if ($row['parent_version_id']): ?>
          <div class="hint">отпочкована от версии №<?= (int) $row['parent_version_id'] ?></div>
        <?php endif; ?>
      </td>
      <td><code><?= h($row['seed_code']) ?></code></td>
      <td>
        <?= $row['status'] === 'edited' ? 'правлена руками' : 'как сгенерирована' ?>
        <?php if ((int) $row['manual_count'] > 0): ?>
          <div class="hint">ручных карточек: <?= (int) $row['manual_count'] ?></div>
        <?php endif; ?>
      </td>
      <td><?= (int) $row['node_count'] ?></td>
      <td><?= (int) $row['link_count'] ?></td>
      <td class="hint"><?= h($row['created_at']) ?></td>
      <td class="actions">
        <a href="<?= h(url('version', ['id' => $row['id']])) ?>">открыть</a>
        <form method="post" action="<?= h(url('version-delete')) ?>"
              onsubmit="return confirm('Удалить версию целиком? Каталог не пострадает.')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button type="submit" class="link danger">удалить</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if ($list === []): ?>
    <tr><td colspan="7" class="hint">Пока ни одной версии. Сгенерируйте первую — форма выше.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php layout_end(); ?>

<?php
/**
 * Общая обвязка страницы. Вызывается как:
 *   layout_start('Заголовок', $page); … layout_end();
 */
function layout_start(string $title, string $active = ''): void
{
    $nav = [
        'versions'     => 'Версии деревьев',
        'technologies' => 'Технологии',
        'categories'   => 'Категории',
        'effect-types' => 'Виды эффектов',
    ];
    $message = flash();
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Civi</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= h(url('versions')) ?>">Civi · админка</a>
  <nav>
    <?php foreach ($nav as $key => $label): ?>
      <a href="<?= h(url($key)) ?>"<?= $active === $key ? ' class="active"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="logout" href="<?= h(url('logout')) ?>">Выйти</a>
</header>
<main>
<?php if ($message !== null): ?>
  <div class="flash <?= h($message['type']) ?>"><?= h($message['text']) ?></div>
<?php endif; ?>
<?php
}

function layout_end(): void
{
    ?>
</main>
</body>
</html>
<?php
}

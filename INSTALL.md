# Развёртывание админки на веб-сервере

Инструкция под конфигурацию с вашего скриншота: Ubuntu 20.04, nginx 1.18
перед Apache 2.4 (MPM-ITK), PHP 7.4.3 (модуль Apache и PHP-FPM), MySQL 8.0.28.
На другом стеке отличается только раздел с веб-сервером.

## 1. Что нужно на сервере

| Компонент | Минимум | У вас |
|---|---|---|
| PHP | 7.4 | 7.4.3 ✔ |
| Расширения PHP | `pdo_mysql`, `json`, `mbstring` | обычно уже стоят |
| MySQL | 8.0.16 | 8.0.28 ✔ |

Проверить расширения:

```bash
php -m | grep -E 'pdo_mysql|json|mbstring'
```

Версия MySQL важна: схема использует `CHECK`-ограничения (с 8.0.16) и тип
`JSON` (с 5.7). На 8.0.28 всё работает — миграция проверена на 8.0.x.

## 2. Файлы

Главное правило: из веба должен быть доступен **только каталог `public/`**.
Рядом с ним лежат `config/` с паролем от базы и `db/` с миграцией — если они
окажутся в зоне доступа веб-сервера, их скачают.

Отсюда два способа размещения. Выберите тот, что подходит вашему адресу.

### Вариант А. Админка в подкаталоге существующего сайта

Например, `https://stascher.ru/civi/`. Репозиторий держим **вне** корня сайта,
а в корень отдаём симлинк на `public/`:

```bash
# репозиторий — вне корня сайта, рядом с ним
cd /var/www/www-root/data
git clone https://github.com/staschern/civi.git civi
cd civi

# публичный каталог сайта — симлинк на public
rmdir /var/www/www-root/data/www/stascher.ru/civi     # если каталог уже создан и пуст
ln -s /var/www/www-root/data/civi/public /var/www/www-root/data/www/stascher.ru/civi
```

Ничего настраивать в веб-сервере не нужно: `app/`, `config/`, `db/` и `tools/`
физически лежат вне корня сайта, а адреса внутри админки относительные, поэтому
она одинаково работает и в корне, и в подкаталоге.

> **Если симлинки в панели запрещены** — перенесите вместо симлинка сам каталог:
> положите репозиторий в `/var/www/www-root/data/civi`, а содержимое `public/`
> скопируйте в `/var/www/www-root/data/www/stascher.ru/civi/` и в `index.php`
> этой копии исправьте первую строку подключения на абсолютный путь:
> `require '/var/www/www-root/data/civi/app/bootstrap.php';`
> Каталог `uploads` при этом должен лежать рядом с копией `index.php`,
> а в конфиге пропишите `'uploads_dir' => '/var/www/www-root/data/www/stascher.ru/civi/uploads/tech'`.

### Вариант Б. Отдельный сайт или поддомен

Например, `https://civi.stascher.ru/`. Тогда корнем сайта прямо назначается
`public/`:

```bash
cd /var/www/www-root/data
git clone https://github.com/staschern/civi.git civi
# в настройках сайта: корневой каталог = /var/www/www-root/data/civi/public
```

### Права в обоих вариантах

PHP должен уметь писать только в каталог загрузок. Владельцем должен быть
пользователь сайта (в панели это обычно `www-root`), а не `root` — иначе
картинки технологий загружаться не будут.

```bash
chown -R www-root:www-root /var/www/www-root/data/civi
chmod -R 755 /var/www/www-root/data/civi
chmod -R 775 /var/www/www-root/data/civi/public/uploads
```

## 3. База данных

```bash
mysql -u root -p
```

```sql
CREATE DATABASE civi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'civi'@'localhost' IDENTIFIED BY 'ПРИДУМАЙТЕ_ПАРОЛЬ';
GRANT ALL PRIVILEGES ON civi.* TO 'civi'@'localhost';
FLUSH PRIVILEGES;
```

> **Если PHP не подключается с ошибкой «The server requested authentication
> method unknown to the client»** — MySQL 8 по умолчанию выдаёт пользователю
> плагин `caching_sha2_password`. Пересоздайте пользователя со старым плагином:
>
> ```sql
> ALTER USER 'civi'@'localhost' IDENTIFIED WITH mysql_native_password BY 'ПАРОЛЬ';
> ```

Накатываем миграцию — в ней и схема, и стандартный набор (15 эпох,
31 категория, 353 технологии, 349 связей каталога, 8 видов игровых эффектов):

```bash
mysql -u civi -p civi < db/migrations/0001_create_tech_tree_versions.sql
```

Проверка:

```bash
mysql -u civi -p civi -e "SELECT COUNT(*) FROM technologies WHERE is_standard = 1;"
# ожидаем 353
```

## 4. Конфигурация и пароль

```bash
cp config/config.php.example config/config.php
php tools/make-password-hash.php
```

Скрипт спросит пароль и напечатает готовую строку. Вставьте её в
`config/config.php` в поле `admin_password_hash`, там же заполните доступ к базе.
Сам пароль нигде не сохраняется — только его хеш.

```php
'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'civi',
    'user'     => 'civi',
    'password' => 'ПАРОЛЬ_ОТ_БАЗЫ',
    'socket'   => '',
],
'admin_password_hash' => '$2y$10$...',
'debug' => false,
```

`config/config.php` не попадает в git — он в `.gitignore`.

## 5. Веб-сервер

### Вариант А (подкаталог сайта) через панель управления

Настраивать нечего: сайт `stascher.ru` уже работает, админка появится
по адресу `https://stascher.ru/civi/` сразу после создания симлинка.
Обработчик PHP должен быть 7.4 — подойдёт и модуль Apache, и PHP-FPM.

Единственное, что стоит проверить в настройках сайта, — **open_basedir**.
Если он ограничен только каталогом сайта, PHP не сможет прочитать файлы
приложения по симлинку. В панели обычно указан домашний каталог пользователя
(`/var/www/www-root/data/`) — тогда всё в порядке, репозиторий внутри него.
Симптом при неверной настройке: «open_basedir restriction in effect» в логе
ошибок PHP и пустая страница.

### Вариант Б (отдельный сайт) через панель управления

В настройках сайта укажите:

- **Корневой каталог сайта (document root):** `.../civi/public`
- **Обработчик PHP:** 7.4, любой из доступных (модуль Apache или PHP-FPM)
- **Индексная страница:** `index.php`

Больше ничего не требуется: маршрут передаётся параметром `?p=`, rewrite не нужен.

### Apache вручную

```apache
<VirtualHost *:80>
    ServerName tree.example.com
    DocumentRoot /var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi/public

    <Directory /var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # каталоги вне public не должны отдаваться наружу
    <DirectoryMatch "/var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi/(app|db|config|tools|docs)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

### nginx перед Apache

Отдавать статику напрямую, остальное проксировать в Apache:

```nginx
server {
    listen 80;
    server_name tree.example.com;
    root /var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi/public;

    location ~* ^/(assets|uploads)/ {
        expires 7d;
        access_log off;
        # в каталоге загрузок ничего не исполняем
        location ~ \.php$ { return 403; }
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Заголовок `X-Forwarded-Proto` нужен, чтобы под HTTPS cookie сессии выставлялась
с флагом `secure`.

### nginx + PHP-FPM, без Apache

```nginx
server {
    listen 80;
    server_name tree.example.com;
    root /var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php$is_args$args; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
    }

    location ^~ /uploads/ {
        location ~ \.php$ { return 403; }
    }
}
```

## 6. Проверка

Откройте адрес админки (`https://stascher.ru/civi/` для варианта А) —
должна открыться форма входа. После входа:

1. **Версии деревьев** → «Сгенерировать и сохранить». Оставьте семя пустым,
   его бросят за вас. Через пару секунд откроется доска: 187 технологий
   и 166 социальных концепций, разложенные по столбцам.
2. Вверху доски должно быть зелёное «Правило столбцов соблюдено на всей доске».
3. Введите то же семя ещё раз — раскладка получится точно такая же.
   На этом и держится поиск ранее сгенерированных пар по коду семени.

## 7. HTTPS и доступ

Админка защищена одним паролем и не предназначена для публичного адреса.
Как минимум:

- выпустите сертификат (Let's Encrypt в панели — пара кликов) и включите
  редирект на HTTPS: пароль не должен ходить открытым текстом;
- если админка на отдельном поддомене, закройте её дополнительно по IP
  или базовой HTTP-авторизацией на уровне nginx.

## 8. Обновление

```bash
cd /var/www/ВАШ_ПОЛЬЗОВАТЕЛЬ/data/civi
git pull
```

Новые миграции (`db/migrations/*.sql`) применяются по возрастанию номера.
Миграция `0001` накатывается только на пустую базу — она создаёт таблицы
и заливает стандартный набор.

## Диагностика

| Симптом | Причина |
|---|---|
| «Нет config/config.php» | не скопировали `config.php.example` |
| «Не удалось подключиться к базе» | неверный логин/пароль или плагин аутентификации (см. раздел 3) |
| «Не хватает расширения PHP: pdo_mysql» | включите расширение в настройках PHP сайта |
| Форма входа не принимает пароль | не заполнен `admin_password_hash` — под формой будет прямое указание на это |
| «Сессия истекла» при сохранении | админку открыли в двух вкладках после перезахода; обновите страницу |
| Пустая белая страница | поставьте `'debug' => true` в конфиге, повторите, верните обратно |
| Картинки технологий не загружаются | нет прав на запись в `public/uploads/tech`, либо каталог принадлежит `root`, а не пользователю сайта |
| Картинки загрузились, но не показываются | в конфиге заполнен `uploads_url`, не совпадающий с адресом установки. Оставьте его пустым — адрес вычислится сам |
| Браузер скачивает `index.php` вместо страницы | PHP-обработчик не назначен сайту, либо в `.htaccess` выше по дереву выключен движок PHP |
| «open_basedir restriction in effect» в логе | в настройках PHP сайта разрешён только каталог сайта; добавьте туда путь к репозиторию (вариант А) |
| Ошибка 500 сразу после установки | чаще всего `.htaccess` с директивой `php_flag` при PHP-FPM. В комплекте такие директивы обёрнуты в `IfModule`; проверьте свои `.htaccess` выше по дереву |

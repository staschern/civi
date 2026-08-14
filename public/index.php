<?php
declare(strict_types=1);

/**
 * Единственная точка входа админки.
 * Маршрут — в параметре p, поэтому rewrite на сервере не нужен.
 */

require __DIR__ . '/../app/bootstrap.php';

use Civi\Auth;
use Civi\Catalog;
use Civi\Db;
use Civi\VersionRepository;

$auth = new Auth($config);
$auth->start();

$page = (string) ($_GET['p'] ?? 'versions');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// ---------------------------------------------------------------- вход
if ($page === 'login') {
    $error = null;
    if ($isPost) {
        if ($auth->login((string) ($_POST['password'] ?? ''))) {
            redirect('versions');
        }
        // одинаковая пауза на любой неудачной попытке — чтобы перебор был дороже
        usleep(400000);
        $error = 'Неверный пароль';
    }
    view('login', ['error' => $error, 'config' => $config]);
    exit;
}

if ($page === 'logout') {
    $auth->logout();
    redirect('login');
}

if (!$auth->isLoggedIn()) {
    redirect('login');
}

// Всё, что меняет данные, доступно только методом POST и только со своим
// токеном: так изменение нельзя вызвать простой ссылкой или картинкой.
$mutating = [
    'version-generate', 'version-rename', 'version-delete', 'version-add-tech', 'version-remove-node',
    'link-toggle', 'link-bulk',
    'category-save', 'category-delete', 'technology-save', 'technology-delete',
    'effect-save', 'effect-delete', 'effect-type-save', 'effect-type-delete',
];
if (in_array($page, $mutating, true) && !$isPost) {
    http_response_code(405);
    header('Allow: POST');
    exit('Это действие выполняется только методом POST.');
}
if ($isPost && !$auth->checkCsrf($_POST['csrf'] ?? null)) {
    http_response_code(419);
    exit('Сессия истекла, обновите страницу и повторите действие.');
}

try {
    $db = new Db($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Не удалось подключиться к базе: ' . h(empty($config['debug']) ? '' : $e->getMessage()));
}

$catalog = new Catalog($db);
$versions = new VersionRepository($db);
$csrf = $auth->csrfToken();

try {
    switch ($page) {

        // ------------------------------------------------ версии деревьев
        case 'versions':
            $search = trim((string) ($_GET['q'] ?? ''));
            view('versions', [
                'list'   => $versions->listVersions($search),
                'search' => $search,
                'csrf'   => $csrf,
                'page'   => $page,
            ]);
            break;

        case 'version-generate':
            $seedRaw = trim((string) ($_POST['seed'] ?? ''));
            $seed = $seedRaw === '' ? null : VersionRepository::parseSeed($seedRaw);
            if ($seedRaw !== '' && $seed === null) {
                flash('Семя задаётся тремя числами, например 4821-7719-3045', 'error');
                redirect('versions');
            }
            $id = $versions->generate(
                trim((string) ($_POST['name'] ?? '')),
                $seed,
                !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null
            );
            flash('Версия сгенерирована и сохранена в базе');
            redirect('version', ['id' => $id]);
            break;

        case 'version':
            $id = (int) ($_GET['id'] ?? 0);
            $data = $versions->load($id);
            if ($data === null) {
                http_response_code(404);
                exit('Версия не найдена');
            }
            // запоминаем доску: со страницы технологии можно будет вернуться
            $_SESSION['last_version'] = $id;
            view('version', [
                'data'      => $data,
                'problems'  => $versions->validate($id),
                'available' => $catalog->technologies([]),
                'focus'     => (int) ($_GET['focus'] ?? 0),
                'csrf'      => $csrf,
                'page'      => 'versions',
            ]);
            break;

        // Переключение связи прямо на доске: была — снимаем, не было — ставим.
        // Отвечаем JSON, чтобы клиент перерисовал доску без перезагрузки.
        case 'link-toggle':
            header('Content-Type: application/json; charset=utf-8');
            try {
                $state = $versions->toggleLink(
                    (int) $_POST['version_id'],
                    (int) $_POST['from_node_id'],
                    (int) $_POST['to_node_id']
                );
                echo json_encode(['ok' => true] + $state, JSON_UNESCAPED_UNICODE);
            } catch (Civi\UserError $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        // Списки «сначала открыть» и «открывает» со страницы технологии.
        case 'link-bulk':
            $versionId = (int) $_POST['version_id'];
            $versions->saveNodeLinks(
                $versionId,
                (int) $_POST['node_id'],
                (array) ($_POST['incoming'] ?? []),
                (array) ($_POST['outgoing'] ?? [])
            );
            flash('Связи сохранены, доска пересобрана');
            redirect('technology', [
                'id' => (int) $_POST['technology_id'],
                'from' => $versionId,
                'node' => (int) $_POST['node_id'],
            ]);
            break;

        case 'version-rename':
            $versions->rename((int) $_POST['id'], trim((string) ($_POST['name'] ?? '')));
            flash('Название сохранено');
            redirect('version', ['id' => (int) $_POST['id']]);
            break;

        case 'version-delete':
            $versions->delete((int) $_POST['id']);
            flash('Версия удалена вместе с её досками');
            redirect('versions');
            break;

        case 'version-add-tech':
            $versionId = (int) $_POST['version_id'];
            $versions->addTechnology($versionId, (int) $_POST['technology_id'], (int) ($_POST['lane'] ?? 0));
            flash('Технология добавлена в версию вручную');
            redirect('version', ['id' => $versionId]);
            break;

        case 'version-remove-node':
            $versionId = (int) $_POST['version_id'];
            $versions->removeNode($versionId, (int) $_POST['node_id']);
            flash('Карточка убрана с доски этой версии');
            redirect('version', ['id' => $versionId]);
            break;

        // ------------------------------------------------------ категории
        case 'categories':
            view('categories', [
                'list'  => $catalog->categories(),
                'trees' => $catalog->trees(),
                'edit'  => !empty($_GET['id']) ? $catalog->findCategory((int) $_GET['id']) : null,
                'csrf'  => $csrf,
                'page'  => $page,
            ]);
            break;

        case 'category-save':
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $catalog->saveCategory($id, $_POST);
            flash($id === null ? 'Категория добавлена' : 'Категория сохранена');
            redirect('categories');
            break;

        case 'category-delete':
            $catalog->deleteCategory((int) $_POST['id']);
            flash('Категория удалена');
            redirect('categories');
            break;

        // ----------------------------------------------------- технологии
        case 'technologies':
            view('technologies', [
                'list'       => $catalog->technologies([
                    'tree_id'     => $_GET['tree_id'] ?? null,
                    'branch_id'   => $_GET['branch_id'] ?? null,
                    'era_id'      => $_GET['era_id'] ?? null,
                    'is_standard' => $_GET['is_standard'] ?? '',
                    'q'           => trim((string) ($_GET['q'] ?? '')),
                ]),
                'trees'      => $catalog->trees(),
                'categories' => $catalog->categories(),
                'eras'       => $catalog->eras(),
                'filter'     => $_GET,
                'csrf'       => $csrf,
                'page'       => $page,
            ]);
            break;

        case 'technology':
            $id = !empty($_GET['id']) ? (int) $_GET['id'] : null;
            $tech = $id !== null ? $catalog->findTechnology($id) : null;
            if ($id !== null && $tech === null) {
                http_response_code(404);
                exit('Технология не найдена');
            }
            // контекст доски: с неё пришли — на неё и вернёмся
            $fromVersion = (int) ($_GET['from'] ?? $_SESSION['last_version'] ?? 0);
            $fromNode = (int) ($_GET['node'] ?? 0);
            $boardLinks = ($tech !== null && $fromVersion > 0)
                ? $versions->technologyLinks($fromVersion, (int) $tech['id'])
                : null;

            view('technology', [
                'tech'        => $tech,
                'fromVersion' => $fromVersion,
                'fromNode'    => $fromNode,
                'boardLinks'  => $boardLinks,
                'trees'       => $catalog->trees(),
                'categories'  => $catalog->categories(),
                'eras'        => $catalog->eras(),
                'prereqs'     => $tech ? $catalog->technologyPrereqs((int) $tech['id']) : [],
                'candidates'  => $tech
                    ? $catalog->prereqCandidates((int) $tech['tree_id'], (int) $tech['default_era_id'], (int) $tech['id'])
                    : [],
                'effects'     => $tech ? $catalog->effectsOf((int) $tech['id']) : [],
                'effectTypes' => $catalog->effectTypes(true),
                'config'      => $config,
                'csrf'        => $csrf,
                'page'        => 'technologies',
            ]);
            break;

        case 'technology-save':
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $data = $_POST;
            $uploaded = upload_image($_FILES['image'] ?? null, $config);
            if ($uploaded !== null) {
                $data['image_path'] = $uploaded;
            }
            $id = $catalog->saveTechnology($id, $data);
            flash('Технология сохранена');
            redirect('technology', ['id' => $id]);
            break;

        case 'technology-delete':
            $catalog->deleteTechnology((int) $_POST['id']);
            flash('Технология удалена из каталога');
            redirect('technologies');
            break;

        // -------------------------------------------------------- эффекты
        case 'effect-save':
            $techId = (int) $_POST['technology_id'];
            $catalog->saveEffect(!empty($_POST['id']) ? (int) $_POST['id'] : null, $techId, $_POST);
            flash('Эффект сохранён');
            redirect('technology', ['id' => $techId]);
            break;

        case 'effect-delete':
            $techId = (int) $_POST['technology_id'];
            $catalog->deleteEffect((int) $_POST['id']);
            flash('Эффект удалён');
            redirect('technology', ['id' => $techId]);
            break;

        case 'effect-types':
            view('effect_types', [
                'list' => $catalog->effectTypes(),
                'edit' => !empty($_GET['id'])
                    ? $db->one('SELECT * FROM effect_types WHERE id = ?', [(int) $_GET['id']])
                    : null,
                'csrf' => $csrf,
                'page' => $page,
            ]);
            break;

        case 'effect-type-save':
            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $catalog->saveEffectType($id, $_POST);
            flash($id === null ? 'Вид эффекта добавлен' : 'Вид эффекта сохранён');
            redirect('effect-types');
            break;

        case 'effect-type-delete':
            $catalog->deleteEffectType((int) $_POST['id']);
            flash('Вид эффекта удалён');
            redirect('effect-types');
            break;

        default:
            http_response_code(404);
            exit('Страница не найдена');
    }
} catch (Civi\UserError $e) {
    // ожидаемые отказы бизнес-правил показываем человеку
    flash($e->getMessage(), 'error');
    redirect($_POST['back'] ?? $_GET['back'] ?? 'versions');
} catch (Throwable $e) {
    http_response_code(500);
    if (!empty($config['debug'])) {
        exit('<pre>' . h($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>');
    }
    error_log('civi-admin: ' . $e->getMessage());
    exit('Внутренняя ошибка. Подробности — в логе ошибок PHP.');
}

/**
 * Загрузка картинки технологии: белый список типов, случайное имя,
 * проверка, что это действительно изображение, а не переименованный скрипт.
 */
function upload_image(?array $file, array $config): ?string
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \Civi\UserError('Не удалось загрузить файл, код ошибки ' . $file['error']);
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        throw new \Civi\UserError('Картинка больше 4 МБ');
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($allowed[$info[2]])) {
        throw new \Civi\UserError('Поддерживаются только PNG, JPEG, GIF и WebP');
    }

    $dir = $config['uploads_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \Civi\UserError('Каталог для картинок недоступен: ' . $dir);
    }
    $name = bin2hex(random_bytes(8)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], rtrim($dir, '/') . '/' . $name)) {
        throw new \Civi\UserError('Не удалось сохранить картинку в ' . $dir);
    }

    // путь относительно приложения: так он не сломается при переносе
    // админки в подкаталог или обратно в корень сайта
    return 'uploads/tech/' . $name;
}

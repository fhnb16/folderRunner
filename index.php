<?php
/**
 * folderRunner — проводник и запускалка .html, .htm и .php.
 *
 * Один файл без зависимостей. Оформление — темы каталога fhnb16/theme-catalog:
 * каркас base.css и темы лежат блоком данных в конце файла (после
 * __halt_compiler), его пишет tools/build.py. Код ниже блок не меняет.
 * В репозитории — базовая редакция (тема Patch, светлая/тёмная); редакции с
 * конфигуратором и всеми темами собирает GitHub Actions в релиз.
 */

declare(strict_types=1);

const FR_DEFAULT_SKIN = 'patch';
const FR_EXTENSIONS = ['html', 'htm', 'php'];

/** Блок данных сборщика: каркас, темы, закреплённая тема. */
function fr_data(): array
{
    static $data = null;
    if ($data === null) {
        $raw = @file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $data = (is_array($data) ? $data : []) + ['hash' => '0', 'lock' => null, 'base' => '', 'themes' => []];
    }
    return $data;
}

// ------------------------------------------------- CSS каркаса и тем ---
// Отдаётся отдельными файлами с долгим кэшем: страница грузит каркас и одну
// тему, а не все сразу.
if (isset($_GET['fr']) && is_string($_GET['fr'])) {
    $data = fr_data();
    $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    $css = match ($_GET['fr']) {
        'base.css' => $data['base'] !== '' ? $data['base'] : null,
        'theme.css' => $data['themes'][$id]['css'] ?? null,
        default => null,
    };
    if ($css === null) {
        http_response_code(404);
        exit;
    }
    $etag = '"' . $data['hash'] . '-' . $_GET['fr'] . '-' . $id . '"';
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    echo $css;
    exit;
}

// Список файлов всегда свежий.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fr_plural(int $n, string $one, string $few, string $many): string
{
    $n10 = $n % 10;
    $n100 = $n % 100;
    if ($n10 === 1 && $n100 !== 11) {
        return $one;
    }
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) {
        return $few;
    }
    return $many;
}

function fr_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' МБ';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
    }
    return $bytes . ' Б';
}

/** Относительный путь → URL: каждый сегмент кодируется (пробелы, #, ?, кириллица). */
function fr_url(string $relPath): string
{
    return implode('/', array_map('rawurlencode', explode('/', $relPath)));
}

/** Рекурсивный обход: папки и файлы нужных расширений, скрытые пропускаются. */
function fr_scan(string $dir, string $baseDir, string $selfPath): array
{
    $result = ['dirs' => [], 'files' => [], 'total' => 0];
    $items = @scandir($dir);
    if ($items === false) {
        return $result;
    }

    foreach ($items as $item) {
        if (str_starts_with($item, '.')) {
            continue;
        }
        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
        $relPath = str_replace('\\', '/', substr($fullPath, strlen($baseDir) + 1));

        if (is_dir($fullPath)) {
            $sub = fr_scan($fullPath, $baseDir, $selfPath);
            $result['dirs'][] = [
                'name' => $item,
                'path' => $relPath,
                'tree' => $sub,
                'mtime' => @filemtime($fullPath) ?: time(),
            ];
            $result['total'] += $sub['total'];
        } elseif (is_file($fullPath) && $fullPath !== $selfPath) {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, FR_EXTENSIONS, true)) {
                $result['files'][] = [
                    'name' => $item,
                    'path' => $relPath,
                    'ext' => $ext,
                    'size' => @filesize($fullPath) ?: 0,
                    'mtime' => @filemtime($fullPath) ?: time(),
                ];
                $result['total']++;
            }
        }
    }

    usort($result['dirs'], fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($result['files'], fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $result;
}

/** Значки — линейные, цвет от currentColor. */
function fr_icon(string $name, string $class = 'fr-ico'): string
{
    static $paths = [
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'logo' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M10.5 10.5v5l4-2.5z"/>',
        'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
        'palette' =>'<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.8 1.6-1.6 0-.9-.7-1.3-.7-2.1 0-.9.7-1.5 1.6-1.5H16a5 5 0 0 0 5-5c0-4.3-4-7.8-9-7.8z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="10.5" cy="7.5" r="1"/><circle cx="15.5" cy="8.5" r="1"/>',
        'refresh' => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'expand' => '<path d="m7 15 5 5 5-5M7 9l5-5 5 5"/>',
        'collapse' => '<path d="m7 20 5-5 5 5M7 4l5 5 5-5"/>',
        'play' => '<path d="M7 4.5v15l12-7.5z"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'sidebar' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'fullscreen' => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    ];
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

function fr_render(array $tree): void
{
    foreach ($tree['dirs'] as $dir) {
        $count = $dir['tree']['total'];
        $empty = !$dir['tree']['dirs'] && !$dir['tree']['files'];
        ?>
        <details class="fr-dir" open>
            <summary class="fr-dir__head" title="<?= h($dir['path']) ?>">
                <span class="fr-dir__pm" aria-hidden="true"></span>
                <?= fr_icon('folder', 'fr-ico fr-dir__icon') ?>
                <span class="fr-dir__name"><?= h($dir['name']) ?></span>
                <span class="tag tag--muted fr-dir__count"><?= $count ?> <?= fr_plural($count, 'файл', 'файла', 'файлов') ?></span>
                <time class="fr-dir__date" datetime="<?= date('c', $dir['mtime']) ?>"><?= date('d.m.Y H:i', $dir['mtime']) ?></time>
            </summary>
            <div class="fr-dir__body">
                <?php if ($empty): ?>
                    <p class="group__note fr-tree__empty">Нет файлов .html, .htm, .php</p>
                <?php else: fr_render($dir['tree']); endif; ?>
            </div>
        </details>
        <?php
    }

    foreach ($tree['files'] as $file) {
        $url = fr_url($file['path']);
        ?>
        <div class="fr-file fr-file--<?= h($file['ext']) ?>" data-url="<?= h($url) ?>" data-path="<?= h($file['path']) ?>"
             data-label="<?= h($file['name']) ?>">
            <?= fr_icon('file', 'fr-ico fr-file__icon') ?>
            <button type="button" class="fr-file__name" data-act="run" title="<?= h($file['path']) ?>"><?= h($file['name']) ?></button>
            <span class="fr-file__info">
                <span class="tag fr-file__ext">.<?= h(strtoupper($file['ext'])) ?></span>
                <span class="fr-file__meta"><?= fr_size($file['size']) ?> · <?= date('d.m.Y H:i', $file['mtime']) ?></span>
            </span>
            <span class="fr-file__actions">
                <button type="button" class="trbar__btn fr-btn" data-act="run" aria-label="Запустить <?= h($file['name']) ?>" title="Запустить здесь">
                    <?= fr_icon('play') ?><span class="fr-btn__label">Запустить</span>
                </button>
                <a class="trbar__btn fr-btn" href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"
                   aria-label="Открыть <?= h($file['name']) ?> в новой вкладке" title="Открыть в новой вкладке">
                    <?= fr_icon('external') ?><span class="fr-btn__label">Вкладка</span>
                </a>
            </span>
        </div>
        <?php
    }
}

// ------------------------------------------------------------- данные ---
$rootDir = __DIR__;
$tree = fr_scan($rootDir, $rootDir, __FILE__);

$data = fr_data();
$themes = $data['themes'];
$lock = is_string($data['lock']) && isset($themes[$data['lock']]) ? $data['lock'] : null;
// Редакция сборки: base — одна тема и переключатель светлая/тёмная (так лежит
// в репозитории); theme — одна тема с конфигуратором; all — все темы.
$edition = in_array($data['edition'] ?? '', ['base', 'theme', 'all'], true) ? $data['edition'] : ($lock ? 'theme' : 'all');
$configurator = $themes && $edition !== 'base';

$skin = $lock ?? (is_string($_COOKIE['fr_skin'] ?? null) ? $_COOKIE['fr_skin'] : '');
if (!isset($themes[$skin])) {
    $skin = isset($themes[FR_DEFAULT_SKIN]) ? FR_DEFAULT_SKIN : (string)array_key_first($themes);
}
$defaultSkin = $lock ?? (isset($themes[FR_DEFAULT_SKIN]) ? FR_DEFAULT_SKIN : $skin);
$mode = in_array($_COOKIE['fr_mode'] ?? '', ['light', 'dark', 'system'], true) ? $_COOKIE['fr_mode'] : 'theme';
// «Системный» режим уточнит скрипт в <head>; до него — основной режим темы.
$dark = $mode === 'dark' || ($mode !== 'light' && ($themes[$skin]['meta']['mode'] ?? 'dark') === 'dark');
$solid = $configurator && ($_COOKIE['fr_solid'] ?? '') === '1';

$passports = array_map(fn($theme) => $theme['meta'], $themes);
$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$extList = '.' . implode(', .', FR_EXTENSIONS);
?>
<!DOCTYPE html>
<html lang="ru"<?= $skin !== '' ? ' data-skin="' . h($skin) . '"' : '' ?> class="<?= trim(($dark ? 'theme--dark' : '') . ($solid ? ' bars--solid' : '')) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noarchive, noindex">
    <meta name="theme-color" id="frThemeColor" content="#202020">
    <title>Каталог файлов</title>
<?php if ($themes): ?>
    <link rel="stylesheet" href="?fr=base.css&amp;v=<?= h($data['hash']) ?>">
<?php endif; ?>
    <style>
        /* Приложение: раскладка и свои блоки fr-*. Цвета, шрифты и формы —
           токены темы; без собранных тем действуют запасные значения. */
        :root {
            --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --bg: #2e2e2e;
            --bg-elev: #383838;
            --surface: #252525;
            --surface-press: #3d3d3d;
            --sep: #333333;
            --sep-strong: #454545;
            --text: #e6e6e6;
            --text-2: #999797;
            --text-3: #6e6d6d;
            --bar: #202020;
            --bar-line: #3a3a3a;
            --radius: 2px;
            --bar-h: 46px;
            --tab-h: 50px;
            --fr-mono: ui-monospace, "SF Mono", Menlo, Consolas, "Courier New", monospace;
            --fr-tree-min: 240px;
            --fr-view-min: 320px;
            --fr-r: min(var(--radius), 8px);
        }
        [hidden] { display: none !important; }
        html, body { height: 100%; margin: 0; }
        body.fr { overflow: hidden; font-size: 14px; }

        .fr-app {
            display: grid;
            grid-template-rows: auto auto minmax(0, 1fr);
            height: 100vh;
            height: 100dvh;
            min-width: 0;
        }
        .fr-ico {
            width: 15px;
            height: 15px;
            flex: none;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Шапка — nav-bar каталога, но в потоке: каталог рассчитан на
           фиксированную мобильную шапку. Геометрия через id — правила тем
           (у Frost на компьютере — сдвиг влево) её не трогают. */
        #frBar.nav-bar {
            position: relative;
            top: auto;
            left: auto;
            right: auto;
            z-index: 20;
            height: auto;
            min-height: calc(var(--bar-h) + var(--safe-t, 0px));
        }

        /* Переключатель базовой редакции: в светлом режиме луна, в тёмном — солнце. */
        .fr-mode__sun,
        .theme--dark .fr-mode__moon { display: none; }
        .theme--dark .fr-mode__sun { display: block; }

        /* --------------------------------------------------------- тулбар */
        .fr-toolbar {
            container: fr-toolbar / inline-size;
            position: relative;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 10px;
            padding: 8px max(12px, var(--safe-r, 0px)) 8px max(12px, var(--safe-l, 0px));
            background: color-mix(in srgb, var(--bg-elev) 85%, transparent);
            border-bottom: 1px solid var(--sep);
            min-width: 0;
        }
        .fr-search { flex: 1 1 240px; min-width: 0; max-width: 560px; }
        .fr-search .search__input { width: 100%; min-width: 0; }
        .fr-toolbar__actions { display: flex; flex: none; gap: 6px; }
        .fr-tool { gap: 6px; white-space: nowrap; }
        .fr-toolbar__stats {
            flex: 1 1 220px;
            min-width: 0;
            margin: 0;
            overflow: hidden;
            color: var(--text-2);
            font-size: 12px;
            text-align: end;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        @container fr-toolbar (max-width: 720px) {
            .fr-toolbar__stats { text-align: start; }
        }
        @container fr-toolbar (max-width: 480px) {
            .fr-tool__label { display: none; }
        }

        /* --------------------------------------------- рабочая область ---
           Ширину списка держит CSS: при сужении окна колонки не вылезают. */
        .fr-work {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            min-width: 0;
            min-height: 0;
            overflow: hidden;
        }
        .fr-work--open {
            grid-template-columns:
                clamp(var(--fr-tree-min), var(--tree-w, 40%), calc(100% - var(--fr-view-min) - 6px))
                6px minmax(0, 1fr);
        }
        .fr-work--open.fr-work--tree-hidden { grid-template-columns: minmax(0, 1fr); }
        .fr-work:not(.fr-work--open) > .fr-resizer,
        .fr-work:not(.fr-work--open) > .fr-viewer,
        .fr-work--tree-hidden > .fr-pane,
        .fr-work--tree-hidden > .fr-resizer { display: none; }
        .fr-work--dragging { cursor: col-resize; user-select: none; }
        .fr-work--dragging .fr-viewer__frame { pointer-events: none; }

        /* Полосы прокрутки — каркаса (токены --scroll-* темы); своих правил и
           scrollbar-color/-width здесь нет: в Chrome они отключают оформление.
           Место под полосу зарезервировано — список не прыгает вбок, когда она
           появляется при поиске или сворачивании папок. */
        .fr-pane {
            min-width: 0;
            min-height: 0;
            overflow: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            padding: 12px max(12px, var(--safe-r, 0px)) 28px max(12px, var(--safe-l, 0px));
        }

        .fr-resizer {
            position: relative;
            z-index: 2;
            background: var(--sep);
            cursor: col-resize;
            touch-action: none;
        }
        .fr-resizer::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 2px;
            width: 2px;
            height: 36px;
            margin-top: -18px;
            border-radius: 1px;
            background: var(--text-3);
        }
        .fr-resizer::after { content: ""; position: absolute; inset: 0 -5px; }
        .fr-resizer:hover,
        .fr-resizer:focus-visible,
        .fr-work--dragging > .fr-resizer { background: var(--tint); outline: none; }
        .fr-resizer:hover::before,
        .fr-resizer:focus-visible::before,
        .fr-work--dragging > .fr-resizer::before { background: var(--on-tint); }

        /* ---------------------------------------------------------- дерево ---
           Раскладка строк — от ширины своей папки (container query), а не
           окна: ширину меняют ресайзер и глубина вложенности. */
        .fr-tree,
        .fr-dir__body { container: fr-tree / inline-size; }
        .fr-tree { font-size: 13px; }
        /* Подпись лежит на фоне страницы — group__note: темы красят её под свой фон. */
        .fr-tree__empty { margin: 4px 0; padding: 4px 8px; font-size: 12px; font-style: italic; }

        .fr-dir { margin: 0 0 4px; }
        .fr-dir__head {
            display: grid;
            grid-template-columns: auto auto minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            border: 1px solid var(--sep);
            border-left: 3px solid var(--sep-strong);
            border-radius: var(--fr-r);
            background: var(--surface);
            color: var(--text);
            cursor: pointer;
            list-style: none;
            user-select: none;
        }
        .fr-dir__head::-webkit-details-marker { display: none; }
        .fr-dir__head:hover { border-left-color: var(--tint); background: color-mix(in srgb, var(--surface) 90%, var(--text)); }
        .fr-dir__head:focus-visible { outline: 2px solid var(--tint); outline-offset: -2px; }
        .fr-dir__pm { min-width: 3ch; color: var(--tint-text, var(--tint)); font-family: var(--fr-mono); font-weight: 700; }
        .fr-dir__pm::before { content: "[+]"; }
        .fr-dir[open] > .fr-dir__head .fr-dir__pm::before { content: "[-]"; }
        .fr-dir__icon { color: var(--warn); }
        .fr-dir__name { min-width: 0; overflow: hidden; font-weight: 600; text-overflow: ellipsis; white-space: nowrap; }
        .fr-dir__count { white-space: nowrap; }
        .fr-dir__date { color: var(--text-3); font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .fr-dir__body {
            margin: 4px 0 6px min(14px, 4cqi);
            padding-left: min(10px, 3cqi);
            border-left: 1px dashed var(--sep-strong);
        }

        .fr-file {
            --fr-ext: var(--text-2);
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto auto auto;
            grid-template-areas: "icon name ext meta act";
            align-items: center;
            gap: 3px 8px;
            margin: 0 0 3px;
            padding: 5px 6px 5px 9px;
            border: 1px solid var(--sep);
            border-radius: var(--fr-r);
            background: var(--surface);
            cursor: pointer;
        }
        .fr-file--php { --fr-ext: #8892bf; }
        .fr-file--html { --fr-ext: #e44d26; }
        .fr-file--htm { --fr-ext: #f16529; }
        .fr-file:hover { border-color: var(--sep-strong); background: color-mix(in srgb, var(--surface) 92%, var(--text)); }
        .fr-file--active,
        .fr-file--active:hover { border-color: var(--tint); background: var(--tint-soft); box-shadow: inset 3px 0 0 var(--tint); }
        .fr-file__icon { grid-area: icon; color: color-mix(in srgb, var(--fr-ext) 75%, var(--text)); }
        .fr-file__name {
            grid-area: name;
            min-width: 0;
            padding: 2px 0;
            overflow: hidden;
            border: 0;
            background: none;
            color: var(--text);
            font: inherit;
            font-family: var(--fr-mono);
            font-size: 12.5px;
            text-align: left;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }
        .fr-file__name:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }
        .fr-file__info { display: contents; }
        .fr-file__ext {
            grid-area: ext;
            border-color: color-mix(in srgb, var(--fr-ext) 55%, transparent);
            color: color-mix(in srgb, var(--fr-ext) 70%, var(--text));
            font-weight: 700;
            white-space: nowrap;
        }
        .fr-file__meta { grid-area: meta; color: var(--text-3); font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .fr-file__actions { grid-area: act; display: flex; gap: 4px; }

        /* Мелкие кнопки — trbar__btn каталога; здесь только раскладка. */
        .fr-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            min-width: 30px;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
        }
        .fr-btn .fr-ico { width: 13px; height: 13px; }
        .fr-file .fr-btn { padding: 3px 9px; font-size: 12px; }

        @container fr-tree (max-width: 559px) {
            .fr-file {
                grid-template-columns: auto minmax(0, 1fr) auto;
                grid-template-areas: "icon name act" "icon info act";
            }
            .fr-file__info {
                grid-area: info;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 2px 8px;
                min-width: 0;
            }
            .fr-file .fr-btn__label { display: none; }
            .fr-file .fr-btn { padding-inline: 7px; }
        }
        @container fr-tree (max-width: 419px) {
            .fr-dir__date { display: none; }
            .fr-dir__head { gap: 6px; padding-inline: 8px; }
        }
        @container fr-tree (max-width: 299px) {
            .fr-dir__count { display: none; }
            .fr-file__actions a.fr-btn { display: none; }
        }

        /* -------------------------------------------------------- просмотр */
        .fr-viewer {
            container: fr-viewer / inline-size;
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 0;
            background: var(--bg);
        }
        .fr-viewer__bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 10px;
            padding: 6px 10px;
            border-bottom: 1px solid var(--bar-line);
            background: var(--bar);
            color: var(--text);
        }
        .fr-viewer__title { display: flex; flex: 1 1 160px; align-items: center; gap: 8px; min-width: 0; }
        .fr-viewer__badge { flex: none; }
        /* Длинный путь обрезается слева: видно имя файла. */
        .fr-viewer__name {
            min-width: 0;
            overflow: hidden;
            direction: rtl;
            font-family: var(--fr-mono);
            font-size: 12px;
            font-weight: 600;
            text-align: left;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .fr-viewer__actions { display: flex; flex-wrap: wrap; gap: 4px; margin-left: auto; }
        .fr-viewer__frame { display: block; flex: 1 1 auto; width: 100%; min-height: 0; border: 0; background: #ffffff; }
        .fr-viewer:fullscreen { width: 100%; height: 100%; }
        @container fr-viewer (max-width: 640px) {
            .fr-viewer .fr-btn__label { display: none; }
        }
        @container fr-viewer (max-width: 380px) {
            .fr-viewer__badge { display: none; }
        }

        /* ---------------------------------------------------- уведомление */
        #frToast.toast { left: 50%; right: auto; bottom: calc(16px + var(--safe-b, 0px)); }

        /* ---------------------------------------------- узкий экран ---
           Одна панель за раз; внизу вкладки «Файлы» / «Просмотр».
           --fr-tabs-space — сколько места снизу реально занимает панель
           (меряет скрипт): у тем она разной высоты, у Frost ещё и парит над
           краем. Список прокручивается под панелью до конца, просмотр
           заканчивается над ней. */
        @media (max-width: 759px) {
            .fr-app--open .fr-pane { padding-bottom: calc(var(--fr-tabs-space, 0px) + 20px); }
            .fr-app--open .fr-viewer { margin-bottom: var(--fr-tabs-space, 0px); }
            .fr-work--open,
            .fr-work--open.fr-work--tree-hidden { grid-template-columns: minmax(0, 1fr); }
            .fr-work--open > .fr-resizer,
            .fr-work--open:not(.fr-work--pane-view) > .fr-viewer,
            .fr-work--open.fr-work--pane-view > .fr-pane { display: none; }
            .fr-work--tree-hidden:not(.fr-work--pane-view) > .fr-pane { display: block; }
            .fr-viewer [data-act="toggle-tree"] { display: none; }
            .fr-app--open #frToast.toast { bottom: calc(var(--fr-tabs-space, 0px) + 12px); }
        }
        @media (min-width: 760px) {
            #frTabs.tab-bar { display: none; }
        }
        .fr-app:not(.fr-app--open) #frTabs.tab-bar { display: none; }

        /* ---------------------------------------------------- конфигуратор */
        #frModal .modal__body { display: grid; gap: 18px; }
        #frModal .group { margin: 0; }
        #frModal .group__title { margin: 0 0 8px; padding: 0; }
        #frModal .segmented { margin: 0; }
        #frModal .list { margin: 0; }
        #frModal .swatches { padding: 0; }
        /* Тело окна прокручивается — над кнопками разделитель, иначе
           обрезанная строка выглядит как ошибка. */
        #frModal.fr-modal--scroll .modal__foot { box-shadow: 0 -1px 0 var(--sep-strong); }
        @media (min-width: 768px) {
            #frModal .modal__panel { max-width: 780px; max-height: 90vh; }
        }
        .fr-skins { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 6px; }
        .fr-skin {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            padding: 6px 10px;
            border: 1px solid var(--sep);
            border-radius: min(var(--radius), 10px);
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 14px;
            text-align: left;
            cursor: pointer;
        }
        .fr-skin:hover { border-color: var(--sep-strong); }
        .fr-skin:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }
        .fr-skin[aria-pressed="true"] { border-color: var(--tint); background: var(--tint-soft); box-shadow: inset 0 0 0 1px var(--tint); }
        .fr-skin__dot {
            flex: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--fr-bg) 0 50%, var(--fr-accent) 50%);
            box-shadow: 0 0 0 1px var(--sep-strong);
        }
        .fr-skin__name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fr-skin__mode { flex: none; color: var(--text-3); font-size: 11px; }
        .fr-swatch--theme .swatches__dot { box-shadow: inset 0 0 0 2px var(--bg-elev); }
        .fr-swatch--custom .swatches__dot { background: conic-gradient(#ff3b30, #ffcc00, #34c759, #5ac8fa, #007aff, #af52de, #ff3b30); }
    </style>
<?php if ($skin !== ''): ?>
    <link rel="stylesheet" id="frThemeCss" href="?fr=theme.css&amp;id=<?= h(rawurlencode($skin)) ?>&amp;v=<?= h($data['hash']) ?>">
<?php endif; ?>
    <script>
        /* Тема до первой отрисовки: режим, акцент и производные цвета
           (applyTheme из каталога тем). */
        (function () {
            'use strict';
            var root = document.documentElement;
            var THEMES = <?= json_encode((object)$passports, $json) ?>;
            var LOCK = <?= json_encode($lock, $json) ?>;
            /* В базовой редакции нет акцентов и сплошных панелей — только режим. */
            var CONFIGURATOR = <?= json_encode($configurator, $json) ?>;
            var DEFAULT_SKIN = <?= json_encode($defaultSkin, $json) ?>;
            var HASH = <?= json_encode($data['hash'], $json) ?>;
            var MONO = ['grid', 'board'];
            var PATH = location.pathname.replace(/[^/]*$/, '') || '/';
            var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
            var listeners = [];

            function toRgb(hex) {
                var h = String(hex).replace('#', '');
                if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
                var n = parseInt(h, 16);
                return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
            }
            function toHex(rgb) {
                return '#' + rgb.map(function (v) {
                    var s = Math.max(0, Math.min(255, Math.round(v))).toString(16);
                    return s.length < 2 ? '0' + s : s;
                }).join('');
            }
            function mix(a, b, t) {
                var x = toRgb(a), y = toRgb(b);
                return toHex([x[0] + (y[0] - x[0]) * t, x[1] + (y[1] - x[1]) * t, x[2] + (y[2] - x[2]) * t]);
            }
            function luminance(hex) {
                var c = toRgb(hex).map(function (v) {
                    v /= 255;
                    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
            }
            function toHsl(hex) {
                var c = toRgb(hex).map(function (v) { return v / 255; });
                var max = Math.max(c[0], c[1], c[2]), min = Math.min(c[0], c[1], c[2]);
                var l = (max + min) / 2, d = max - min, h = 0, s = 0;
                if (d) {
                    s = d / (1 - Math.abs(2 * l - 1));
                    if (max === c[0]) h = ((c[1] - c[2]) / d) % 6;
                    else if (max === c[1]) h = (c[2] - c[0]) / d + 2;
                    else h = (c[0] - c[1]) / d + 4;
                    h = (h * 60 + 360) % 360;
                }
                return [h, s, l];
            }
            function fromHsl(h, s, l) {
                var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
                var p = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x]
                    : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
                return toHex(p.map(function (v) { return (v + m) * 255; }));
            }
            /* Сосед акцента по кругу: тон +38°, насыщенность не ниже 0.55,
               светлота читается на фоне режима. */
            function companion(base, dark) {
                var c = toHsl(base);
                return fromHsl((c[0] + 38) % 360, Math.max(c[1], 0.55), dark ? Math.max(c[2], 0.62) : Math.min(c[2], 0.42));
            }

            function cookie(name) {
                var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
                return m ? decodeURIComponent(m[1]) : '';
            }
            function setCookie(name, value) {
                document.cookie = name + '=' + encodeURIComponent(value) + '; max-age=31536000; path=' + PATH + '; SameSite=Lax';
            }

            var mode = cookie('fr_mode');
            var accent = cookie('fr_accent');
            var prefs = {
                skin: root.getAttribute('data-skin') || '',
                mode: ['light', 'dark', 'system'].indexOf(mode) >= 0 ? mode : 'theme',
                accent: CONFIGURATOR && /^#[0-9a-f]{6}$/i.test(accent) ? accent : '',
                solid: CONFIGURATOR && cookie('fr_solid') === '1'
            };

            function isDark() {
                var theme = THEMES[prefs.skin];
                if (prefs.mode === 'dark') return true;
                if (prefs.mode === 'light') return false;
                if (prefs.mode === 'system') return !!(media && media.matches);
                return !theme || theme.mode === 'dark';
            }

            function themeAccent(theme, dark) {
                return (dark ? theme.accentDark : theme.accentLight) || theme.accent || '#007aff';
            }

            function apply() {
                var theme = THEMES[prefs.skin];
                var dark = isDark();
                root.classList.toggle('theme--dark', dark);
                root.classList.toggle('bars--solid', prefs.solid);
                if (!theme) return;

                var base = prefs.accent || themeAccent(theme, dark);
                var tint = dark ? mix(base, '#ffffff', 0.18) : base;
                var press = dark ? mix(tint, '#ffffff', 0.22) : mix(tint, '#000000', 0.18);
                var rgb = toRgb(tint);
                var own = (dark ? theme.companionDark : theme.companionLight) || theme.companion;
                var mono = MONO.indexOf(prefs.skin) >= 0 || theme.mono === 'yes' || theme.mono === 'true';
                root.style.setProperty('--tint', tint);
                root.style.setProperty('--tint-press', press);
                root.style.setProperty('--tint-soft', 'rgba(' + rgb.join(',') + ',' + (dark ? 0.24 : 0.14) + ')');
                root.style.setProperty('--on-tint', luminance(tint) > 0.5 ? '#101010' : '#ffffff');
                root.style.setProperty('--warn', own && !prefs.accent ? own
                    : mono ? mix(tint, dark ? '#ffffff' : '#000000', 0.35) : companion(base, dark));

                /* Строка состояния — цвет панели, наложенный на фон. */
                var bars = dark ? theme.barsDark : theme.barsLight;
                var meta = document.getElementById('frThemeColor');
                if (bars && meta) meta.setAttribute('content', bars[1] >= 1 ? bars[0] : mix(bars[2], bars[0], bars[1]));
            }

            function save() {
                setCookie('fr_skin', prefs.skin);
                setCookie('fr_mode', prefs.mode);
                setCookie('fr_accent', prefs.accent);
                setCookie('fr_solid', prefs.solid ? '1' : '0');
            }

            function changed() {
                apply();
                save();
                listeners.forEach(function (fn) { fn(prefs); });
            }

            /* Новая тема: новый <link>, старый снимается, когда новый загрузится —
               без кадра «без темы». */
            var pending = null;
            function setSkin(id) {
                if (LOCK || !THEMES[id] || id === prefs.skin && !pending) return;
                pending = id;
                var old = document.getElementById('frThemeCss');
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = '?fr=theme.css&id=' + encodeURIComponent(id) + '&v=' + encodeURIComponent(HASH);
                link.onload = function () {
                    if (pending !== id) { link.remove(); return; }
                    pending = null;
                    if (old) old.remove();
                    link.id = 'frThemeCss';
                    root.setAttribute('data-skin', id);
                    prefs.skin = id;
                    changed();
                };
                link.onerror = function () { link.remove(); if (pending === id) pending = null; };
                if (old) old.after(link); else document.head.appendChild(link);
            }

            function set(key, value) {
                prefs[key] = value;
                changed();
            }

            function reset() {
                prefs.mode = 'theme';
                prefs.accent = '';
                prefs.solid = false;
                if (!LOCK && DEFAULT_SKIN && prefs.skin !== DEFAULT_SKIN) setSkin(DEFAULT_SKIN);
                changed();
            }

            if (media) {
                var onScheme = function () { if (prefs.mode === 'system') changed(); };
                if (media.addEventListener) media.addEventListener('change', onScheme); else media.addListener(onScheme);
            }

            window.FR = {
                themes: THEMES,
                lock: LOCK,
                prefs: prefs,
                isDark: isDark,
                themeAccent: themeAccent,
                setSkin: setSkin,
                set: set,
                reset: reset,
                onChange: function (fn) { listeners.push(fn); }
            };
            apply();
        })();
    </script>
</head>
<body class="fr">
<div class="fr-app" id="frApp">
    <header class="nav-bar" id="frBar">
        <div class="nav-bar__side">
            <button type="button" class="nav-bar__btn nav-bar__btn--icon" data-act="home" aria-label="К началу списка" title="К началу списка">
                <?= fr_icon('logo', 'nav-bar__icon') ?>
            </button>
        </div>
        <h1 class="nav-bar__title">Каталог файлов</h1>
        <div class="nav-bar__side nav-bar__side--right">
            <?php if ($configurator): ?>
            <button type="button" class="nav-bar__btn nav-bar__btn--icon" data-act="theme" aria-label="Оформление" title="Оформление" aria-haspopup="dialog">
                <?= fr_icon('palette', 'nav-bar__icon') ?>
            </button>
            <?php elseif ($themes): ?>
            <button type="button" class="nav-bar__btn nav-bar__btn--icon" data-act="mode" id="frModeToggle"
                    aria-label="Тёмная тема" aria-pressed="<?= $dark ? 'true' : 'false' ?>" title="Светлая / тёмная тема">
                <?= fr_icon('moon', 'nav-bar__icon fr-mode__moon') ?><?= fr_icon('sun', 'nav-bar__icon fr-mode__sun') ?>
            </button>
            <?php endif; ?>
            <button type="button" class="nav-bar__btn nav-bar__btn--icon" data-act="reload" aria-label="Пересканировать папку" title="Пересканировать папку">
                <?= fr_icon('refresh', 'nav-bar__icon') ?>
            </button>
        </div>
    </header>

    <section class="fr-toolbar" aria-label="Поиск и действия">
        <div class="search__box fr-search" role="search">
            <?= fr_icon('search', 'search__icon') ?>
            <input type="search" id="frSearch" class="search__input" placeholder="Поиск по имени или пути  ( / )"
                   aria-label="Поиск по имени или пути" autocomplete="off" spellcheck="false">
            <button type="button" class="search__clear" data-act="search-clear" aria-label="Очистить поиск" hidden>×</button>
        </div>
        <div class="fr-toolbar__actions">
            <button type="button" class="btn btn--inline btn--ghost fr-tool" data-act="expand" title="Развернуть все папки">
                <?= fr_icon('expand') ?><span class="fr-tool__label">Развернуть</span>
            </button>
            <button type="button" class="btn btn--inline btn--ghost fr-tool" data-act="collapse" title="Свернуть все папки">
                <?= fr_icon('collapse') ?><span class="fr-tool__label">Свернуть</span>
            </button>
        </div>
        <p class="fr-toolbar__stats" title="<?= h(str_replace('\\', '/', $rootDir)) ?>">
            <?= $tree['total'] ?> <?= fr_plural($tree['total'], 'файл', 'файла', 'файлов') ?> · <?= h($extList) ?> · <?= h(str_replace('\\', '/', $rootDir)) ?>
        </p>
    </section>

    <main class="fr-work" id="frWork">
        <div class="fr-pane" id="frPane">
            <div class="fr-tree" id="frTree">
                <?php if ($tree['total'] === 0 && !$tree['dirs']): ?>
                    <div class="state">
                        <p class="state__title">Файлов нет</p>
                        <p class="state__text">В этой папке не найдено <?= h($extList) ?></p>
                    </div>
                <?php else: fr_render($tree); endif; ?>
            </div>
            <div class="state" id="frNoMatch" hidden>
                <p class="state__title">Ничего не найдено</p>
                <p class="state__text">Нет файлов, в имени или пути которых есть этот текст.</p>
                <button type="button" class="btn btn--inline state__action" data-act="search-clear">Сбросить поиск</button>
            </div>
        </div>

        <div class="fr-resizer" id="frResizer" role="separator" aria-orientation="vertical" aria-controls="frPane"
             aria-label="Ширина списка" tabindex="0" title="Перетащите, чтобы изменить ширину; двойной щелчок — сброс"></div>

        <section class="fr-viewer" id="frViewer" aria-label="Просмотр">
            <div class="fr-viewer__bar">
                <div class="fr-viewer__title">
                    <span class="tag fr-viewer__badge">Запуск</span>
                    <span class="fr-viewer__name"><bdi id="frViewerPath"></bdi></span>
                </div>
                <div class="fr-viewer__actions">
                    <button type="button" class="trbar__btn fr-btn" data-act="toggle-tree" aria-pressed="false" title="Скрыть список файлов">
                        <?= fr_icon('sidebar') ?><span class="fr-btn__label">Список</span>
                    </button>
                    <button type="button" class="trbar__btn fr-btn" data-act="frame-reload" title="Перезагрузить страницу">
                        <?= fr_icon('refresh') ?><span class="fr-btn__label">Перезапуск</span>
                    </button>
                    <button type="button" class="trbar__btn fr-btn" data-act="fullscreen" title="Во весь экран">
                        <?= fr_icon('fullscreen') ?><span class="fr-btn__label">Экран</span>
                    </button>
                    <a class="trbar__btn fr-btn" id="frOpenExternal" href="#" target="_blank" rel="noopener noreferrer" title="Открыть в новой вкладке">
                        <?= fr_icon('external') ?><span class="fr-btn__label">Вкладка</span>
                    </a>
                    <button type="button" class="trbar__btn fr-btn" data-act="close" title="Закрыть просмотр (Esc)">
                        <?= fr_icon('close') ?><span class="fr-btn__label">Закрыть</span>
                    </button>
                </div>
            </div>
            <iframe class="fr-viewer__frame" id="frFrame" title="Просмотр файла" src="about:blank"></iframe>
        </section>
    </main>

    <nav class="tab-bar" id="frTabs" aria-label="Панели">
        <button type="button" class="tab-bar__item tab-bar__item--active" data-act="pane-tree" aria-pressed="true">
            <?= fr_icon('list', 'tab-bar__icon') ?><span class="tab-bar__label">Файлы</span>
        </button>
        <button type="button" class="tab-bar__item" data-act="pane-view" aria-pressed="false">
            <?= fr_icon('monitor', 'tab-bar__icon') ?><span class="tab-bar__label">Просмотр</span>
        </button>
    </nav>

    <div class="toast" id="frToast" role="status" aria-live="polite"><span class="toast__text"></span></div>
</div>

<?php if ($configurator): ?>
<div class="modal" id="frModal" hidden>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="frModalTitle">
        <div class="modal__head">
            <div class="modal__titles">
                <h2 class="modal__title" id="frModalTitle">Оформление</h2>
                <p class="modal__sub" id="frModalSub"></p>
            </div>
            <button type="button" class="modal__close" data-act="modal-close" aria-label="Закрыть">✕</button>
        </div>
        <div class="modal__body">
            <section class="group"<?= $lock ? ' hidden' : '' ?>>
                <h3 class="group__title">Тема · <?= count($themes) ?></h3>
                <div class="fr-skins" id="frSkins"></div>
            </section>
            <section class="group">
                <h3 class="group__title">Режим</h3>
                <div class="segmented" id="frModes">
                    <button type="button" class="segmented__item" data-mode="theme" title="Основной режим темы">По теме</button>
                    <button type="button" class="segmented__item" data-mode="light">Светлый</button>
                    <button type="button" class="segmented__item" data-mode="dark">Тёмный</button>
                    <button type="button" class="segmented__item" data-mode="system" title="Как в системе">Система</button>
                </div>
            </section>
            <section class="group">
                <h3 class="group__title">Акцент</h3>
                <div class="swatches" id="frSwatches"></div>
            </section>
            <section class="group">
                <div class="list">
                    <div class="list__cell">
                        <span class="list__label">Сплошные панели</span>
                        <label class="switch">
                            <input class="switch__input" type="checkbox" id="frSolid" aria-label="Сплошные панели">
                            <span class="switch__track"></span>
                            <span class="switch__thumb"></span>
                        </label>
                    </div>
                </div>
            </section>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--inline btn--ghost" data-act="theme-reset">Сбросить</button>
            <button type="button" class="btn btn--inline btn--filled" data-act="modal-close">Готово</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    (function () {
        'use strict';
        var $ = function (id) { return document.getElementById(id); };
        var app = $('frApp'), work = $('frWork'), pane = $('frPane'), tree = $('frTree');
        var viewer = $('frViewer'), frame = $('frFrame'), search = $('frSearch'), resizer = $('frResizer');
        var tabs = $('frTabs'), modal = $('frModal');
        var narrow = window.matchMedia('(max-width: 759px)');
        var files = Array.prototype.slice.call(tree.querySelectorAll('.fr-file'));
        files.forEach(function (file) { file.frSearch = file.getAttribute('data-path').toLowerCase(); });
        var dirs = Array.prototype.slice.call(tree.querySelectorAll('.fr-dir'));
        var active = null;

        /* ------------------------------------------------------ уведомление */
        var toastTimer = 0;
        function toast(text) {
            var el = $('frToast');
            el.querySelector('.toast__text').textContent = text;
            el.classList.add('toast--shown');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { el.classList.remove('toast--shown'); }, 2200);
        }

        /* --------------------------------------------------------- просмотр */
        function showPane(view) {
            work.classList.toggle('fr-work--pane-view', view);
            tabs.querySelectorAll('.tab-bar__item').forEach(function (item) {
                var on = (item.getAttribute('data-act') === 'pane-view') === view;
                item.classList.toggle('tab-bar__item--active', on);
                item.setAttribute('aria-pressed', String(on));
            });
        }

        function run(file) {
            if (active) active.classList.remove('fr-file--active');
            active = file;
            file.classList.add('fr-file--active');
            app.classList.add('fr-app--open');
            work.classList.add('fr-work--open');
            $('frViewerPath').textContent = file.getAttribute('data-path');
            $('frOpenExternal').href = file.getAttribute('data-url');
            frame.src = file.getAttribute('data-url');
            if (narrow.matches) showPane(true);
            measureTabs();
            toast('Запущен: ' + file.getAttribute('data-label'));
        }

        function closeViewer() {
            if (!active) return;
            if (document.fullscreenElement) document.exitFullscreen();
            active.classList.remove('fr-file--active');
            var file = active;
            active = null;
            frame.src = 'about:blank';
            app.classList.remove('fr-app--open');
            work.classList.remove('fr-work--open', 'fr-work--pane-view');
            showPane(false);
            measureTabs();
            file.querySelector('.fr-file__name').focus({ preventScroll: true });
        }

        function reloadFrame() {
            if (!active) return;
            try { frame.contentWindow.location.reload(); } catch (e) { frame.src = active.getAttribute('data-url'); }
            toast('Перезапущено');
        }

        function toggleTree(button) {
            var hidden = work.classList.toggle('fr-work--tree-hidden');
            button.setAttribute('aria-pressed', String(hidden));
            button.title = hidden ? 'Показать список файлов' : 'Скрыть список файлов';
        }

        function toggleFullscreen() {
            if (document.fullscreenElement) document.exitFullscreen();
            else if (viewer.requestFullscreen) viewer.requestFullscreen();
        }
        if (!document.fullscreenEnabled) {
            viewer.querySelector('[data-act="fullscreen"]').hidden = true;
        }

        /* Сколько места снизу занимает панель вкладок: от её верхнего края до
           низа окна. У парящей панели (Frost) — ещё зазор, чтобы просмотр
           не упирался в капсулу. */
        function measureTabs() {
            var space = 0;
            if (getComputedStyle(tabs).display !== 'none') {
                var rect = tabs.getBoundingClientRect();
                var height = document.documentElement.clientHeight;
                space = Math.max(0, Math.ceil(height - rect.top)) + (height - rect.bottom > 1 ? 8 : 0);
            }
            document.documentElement.style.setProperty('--fr-tabs-space', space + 'px');
        }
        window.addEventListener('resize', measureTabs);
        if (window.ResizeObserver) new ResizeObserver(measureTabs).observe(tabs);
        if (window.FR) FR.onChange(function () { requestAnimationFrame(measureTabs); });

        var modeToggle = $('frModeToggle');
        function syncModeToggle() { modeToggle.setAttribute('aria-pressed', String(FR.isDark())); }
        if (modeToggle) { syncModeToggle(); FR.onChange(syncModeToggle); }

        narrow.addEventListener('change', function () {
            if (!narrow.matches) showPane(false);
            else if (active) showPane(true);
            measureTabs();
        });

        /* ------------------------------------------------------------ поиск */
        var searchTimer = 0;
        function filter(query) {
            var term = query.trim().toLowerCase();
            var shown = 0;
            files.forEach(function (file) {
                var ok = !term || file.frSearch.indexOf(term) >= 0;
                file.hidden = !ok;
                if (ok) shown++;
            });
            /* Снизу вверх: вложенные папки решаются раньше родительских. */
            for (var i = dirs.length - 1; i >= 0; i--) {
                var dir = dirs[i];
                if (!term) { dir.hidden = false; continue; }
                var any = dir.querySelector('.fr-file:not([hidden])');
                dir.hidden = !any;
                if (any) dir.open = true;
            }
            $('frNoMatch').hidden = !(term && !shown);
            document.querySelector('.fr-search .search__clear').hidden = !query;
        }
        search.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { filter(search.value); }, 120);
        });
        function clearSearch() {
            search.value = '';
            filter('');
            search.focus();
        }

        /* --------------------------------------------------------- ресайзер */
        var KEY = 'fr-tree-w';
        var drag = null;
        function bounds() {
            var width = work.getBoundingClientRect().width;
            return [240, Math.max(240, Math.round(width - 326))];
        }
        function setTreeWidth(px, persist) {
            var b = bounds();
            px = Math.round(Math.min(b[1], Math.max(b[0], px)));
            work.style.setProperty('--tree-w', px + 'px');
            resizer.setAttribute('aria-valuemin', b[0]);
            resizer.setAttribute('aria-valuemax', b[1]);
            resizer.setAttribute('aria-valuenow', px);
            if (persist) { try { localStorage.setItem(KEY, String(px)); } catch (e) { /* без хранилища */ } }
        }
        try {
            var saved = parseInt(localStorage.getItem(KEY) || '', 10);
            if (saved > 0) work.style.setProperty('--tree-w', saved + 'px');
        } catch (e) { /* без хранилища */ }

        resizer.addEventListener('pointerdown', function (e) {
            if (e.button !== 0) return;
            resizer.setPointerCapture(e.pointerId);
            drag = { x: e.clientX, w: pane.getBoundingClientRect().width };
            work.classList.add('fr-work--dragging');
            e.preventDefault();
        });
        resizer.addEventListener('pointermove', function (e) {
            if (drag) setTreeWidth(drag.w + e.clientX - drag.x, false);
        });
        function endDrag() {
            if (!drag) return;
            drag = null;
            work.classList.remove('fr-work--dragging');
            setTreeWidth(pane.getBoundingClientRect().width, true);
        }
        resizer.addEventListener('pointerup', endDrag);
        resizer.addEventListener('pointercancel', endDrag);
        resizer.addEventListener('lostpointercapture', endDrag);
        resizer.addEventListener('dblclick', function () {
            work.style.removeProperty('--tree-w');
            try { localStorage.removeItem(KEY); } catch (e) { /* без хранилища */ }
        });
        resizer.addEventListener('keydown', function (e) {
            var width = pane.getBoundingClientRect().width;
            var step = e.shiftKey ? 80 : 24;
            var next = { ArrowLeft: width - step, ArrowRight: width + step, Home: 0, End: Infinity }[e.key];
            if (next === undefined) return;
            e.preventDefault();
            setTreeWidth(next, true);
        });

        /* ------------------------------------------------------ конфигуратор */
        var lastFocus = null;
        var PRESETS = ['#007aff', '#34c759', '#ff9500', '#ff3b30', '#af52de', '#5ac8fa', '#ffcc00', '#8e8e93'];

        function buildConfigurator() {
            var list = $('frSkins');
            var ids = Object.keys(FR.themes).sort(function (a, b) {
                return FR.themes[a].name.localeCompare(FR.themes[b].name, 'ru');
            });
            ids.forEach(function (id) {
                var theme = FR.themes[id];
                var dark = theme.mode === 'dark';
                var bars = dark ? theme.barsDark : theme.barsLight;
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'fr-skin';
                button.setAttribute('data-skin', id);
                button.title = theme.description || theme.name;
                button.innerHTML = '<span class="fr-skin__dot"></span><span class="fr-skin__name"></span><span class="fr-skin__mode"></span>';
                var dot = button.querySelector('.fr-skin__dot');
                dot.style.setProperty('--fr-bg', bars ? bars[2] : (dark ? '#000000' : '#ffffff'));
                dot.style.setProperty('--fr-accent', FR.themeAccent(theme, dark));
                button.querySelector('.fr-skin__name').textContent = theme.name;
                button.querySelector('.fr-skin__mode').textContent = dark ? 'тёмн.' : 'светл.';
                list.appendChild(button);
            });

            var swatches = $('frSwatches');
            swatches.innerHTML = '<button type="button" class="swatches__item fr-swatch--theme" data-accent="" title="Как в теме" aria-label="Акцент темы"><span class="swatches__dot"></span></button>'
                + PRESETS.map(function (c) {
                    return '<button type="button" class="swatches__item" data-accent="' + c + '" title="' + c + '" aria-label="Акцент ' + c + '"><span class="swatches__dot" style="background:' + c + '"></span></button>';
                }).join('')
                + '<label class="swatches__item swatches__custom fr-swatch--custom" title="Свой цвет"><span class="swatches__dot"></span>'
                + '<input type="color" class="swatches__input" id="frAccentInput" aria-label="Свой цвет акцента"></label>';
        }

        function syncConfigurator() {
            var prefs = FR.prefs, theme = FR.themes[prefs.skin];
            $('frModalSub').textContent = theme ? theme.name + (theme.description ? ' — ' + theme.description : '') : '';
            modal.querySelectorAll('.fr-skin').forEach(function (b) {
                b.setAttribute('aria-pressed', String(b.getAttribute('data-skin') === prefs.skin));
            });
            modal.querySelectorAll('#frModes .segmented__item').forEach(function (b) {
                var on = b.getAttribute('data-mode') === prefs.mode;
                b.classList.toggle('segmented__item--active', on);
                b.setAttribute('aria-pressed', String(on));
            });
            var themeDot = modal.querySelector('.fr-swatch--theme .swatches__dot');
            if (theme) themeDot.style.background = FR.themeAccent(theme, FR.isDark());
            var custom = prefs.accent && PRESETS.indexOf(prefs.accent.toLowerCase()) < 0;
            modal.querySelectorAll('.swatches__item').forEach(function (b) {
                var value = b.getAttribute('data-accent');
                var on = value === null ? custom : value === prefs.accent.toLowerCase();
                b.classList.toggle('swatches__item--active', on);
                if (value !== null) b.setAttribute('aria-pressed', String(on));
            });
            $('frAccentInput').value = prefs.accent || (theme ? FR.themeAccent(theme, FR.isDark()) : '#007aff');
            $('frSolid').checked = prefs.solid;
        }

        function markModalScroll() {
            var body = modal.querySelector('.modal__body');
            modal.classList.toggle('fr-modal--scroll', body.scrollTop + body.clientHeight < body.scrollHeight - 1);
        }

        function openModal() {
            lastFocus = document.activeElement;
            syncConfigurator();
            modal.hidden = false;
            markModalScroll();
            document.documentElement.classList.add('modal-open');
            var current = modal.querySelector('.fr-skin[aria-pressed="true"]');
            (current && current.offsetParent ? current : modal.querySelector('.modal__close')).focus();
        }

        function closeModal() {
            modal.hidden = true;
            document.documentElement.classList.remove('modal-open');
            if (lastFocus) lastFocus.focus();
        }

        if (modal) {
            buildConfigurator();
            FR.onChange(function () { syncConfigurator(); if (!modal.hidden) markModalScroll(); });
            modal.querySelector('.modal__body').addEventListener('scroll', markModalScroll, { passive: true });
            window.addEventListener('resize', function () { if (!modal.hidden) markModalScroll(); });
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeModal(); return; }
                var skin = e.target.closest('.fr-skin');
                var modeButton = e.target.closest('[data-mode]');
                var swatch = e.target.closest('button[data-accent]');
                if (skin) FR.setSkin(skin.getAttribute('data-skin'));
                else if (modeButton) FR.set('mode', modeButton.getAttribute('data-mode'));
                else if (swatch) FR.set('accent', swatch.getAttribute('data-accent'));
            });
            $('frAccentInput').addEventListener('input', function (e) { FR.set('accent', e.target.value.toLowerCase()); });
            $('frSolid').addEventListener('change', function (e) { FR.set('solid', e.target.checked); });
            modal.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { e.stopPropagation(); closeModal(); return; }
                if (e.key !== 'Tab') return;
                var focusable = Array.prototype.filter.call(
                    modal.querySelectorAll('button, input, [href], [tabindex]:not([tabindex="-1"])'),
                    function (el) { return !el.disabled && el.offsetParent !== null; }
                );
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            });
        }

        /* ---------------------------------------------------------- действия */
        var actions = {
            home: function () {
                closeViewer();
                if (search.value) { search.value = ''; filter(''); }
                pane.scrollTo({ top: 0, behavior: 'smooth' });
            },
            theme: openModal,
            mode: function () { FR.set('mode', FR.isDark() ? 'light' : 'dark'); },
            reload: function () { location.reload(); },
            expand: function () { dirs.forEach(function (d) { d.open = true; }); },
            collapse: function () { dirs.forEach(function (d) { d.open = false; }); },
            'search-clear': clearSearch,
            run: function (el) { run(el.closest('.fr-file')); },
            close: closeViewer,
            'frame-reload': reloadFrame,
            'toggle-tree': toggleTree,
            fullscreen: toggleFullscreen,
            'pane-tree': function () { showPane(false); },
            'pane-view': function () { if (active) showPane(true); },
            'modal-close': closeModal,
            'theme-reset': function () { FR.reset(); }
        };

        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-act]');
            if (el && actions[el.getAttribute('data-act')]) {
                actions[el.getAttribute('data-act')](el);
                return;
            }
            /* Щелчок по строке файла (кроме ссылки «Вкладка») — запуск. */
            var file = e.target.closest('.fr-file');
            if (file && !e.target.closest('a')) run(file);
        });

        document.addEventListener('keydown', function (e) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
            if (e.key === '/' && !typing && (!modal || modal.hidden)) {
                e.preventDefault();
                search.focus();
                search.select();
            } else if (e.key === 'Escape' && (!modal || modal.hidden)) {
                if (e.target === search && search.value) { search.value = ''; filter(''); }
                else if (active && !document.fullscreenElement) closeViewer();
            }
        });
    })();
</script>
</body>
</html>
<?php
__halt_compiler();
{"format":1,"edition":"base","rev":null,"hash":"73a803d1c4f8","lock":"patch","base":"/* Каркас: BEM-блоки, общие для всех тем. Темы — в themes/*.css. */\n\n/* ==========================================================================\n   Борда — читалка getpostingboard.dev\n   Каркас приложения: сетка, отступы, поведение. Внешний вид целиком задают\n   оформления, каждое в своём блоке.\n   Именование — БЭМ.\n   ========================================================================== */\n:root {\n  /* Формы: единый набор отступов и вида поля. Темы переопределяют только эти\n     переменные, раскладка строки — в базовых блоках .field/.list. */\n  --row-px: 16px;\n  --row-min: 44px;\n  --field-inset: 6px;\n  --field-pad: 12px;\n  --field-h: 36px;\n  --label-w: clamp(104px, 36%, 172px);\n  --group-r: 0px;\n  --field-bg: color-mix(in srgb, var(--text) 6%, transparent);\n  --field-line: transparent;\n  --field-shadow: none;\n  --focus-bg: color-mix(in srgb, var(--tint) 9%, transparent);\n  --tint-text: color-mix(in srgb, var(--tint) 72%, var(--text));\n  --seg-pad: 0px;\n  --seg-item-r: 0px;\n  /* Каркас. Ни цвета, ни формы, ни шрифта здесь нет: всё это задаёт каждое\n     оформление целиком в своём блоке, и ни одно не наследует ничего от\n     другого. Здесь только акцент (его подставляет JS), отступы под системные\n     полосы и длительность анимаций. */\n  --tint: #007aff;\n  --tint-press: #0062cc;\n  --tint-soft: rgba(0, 122, 255, 0.14);\n  --on-tint: #ffffff;\n  --warn: #ff9500;\n  /* Два источника отступов под системные полосы.\n     env() заполняет браузер — Safari всегда, Chrome в edge-to-edge.\n     --inset-* прокидывает нативная обёртка: Android WebView env() не\n     заполняет вовсе, там инсеты приходят из setOnApplyWindowInsetsListener.\n     max() из двух источников работает в обоих случаях без развилок. */\n  --inset-top: 0px;\n  --inset-bottom: 0px;\n  --inset-left: 0px;\n  --inset-right: 0px;\n  --safe-t: max(env(safe-area-inset-top, 0px), var(--inset-top));\n  --safe-b: max(env(safe-area-inset-bottom, 0px), var(--inset-bottom));\n  --safe-l: max(env(safe-area-inset-left, 0px), var(--inset-left));\n  --safe-r: max(env(safe-area-inset-right, 0px), var(--inset-right));\n  --dur: 0.28s;\n}\n* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }\n/* Ни один контейнер не должен растягиваться содержимым: во флексах и гридах\n   элементы по умолчанию не ужимаются меньше своего содержимого. */\n.app,\n.app__views,\n.app__screen,\n.list,\n.card,\n.post,\n.reply,\n.group,\n.banner,\n.key,\n.search,\n.state,\n.more,\n.actions,\n.field,\n.list__cell {\n  max-width: 100%;\n  min-width: 0;\n}\nhtml,\nbody {\n  margin: 0;\n  padding: 0;\n  height: 100%;\n  background: var(--bg);\n  overscroll-behavior-y: none;\n  /* Последний рубеж: если что-то всё же шире экрана, страница вбок не едет.\n     Сами широкие блоки ниже получают собственную прокрутку. */\n  overflow-x: clip;\n  max-width: 100%;\n}\n/* Фон рисуется фиксированным слоем во весь экран, включая зоны системных\n   полос: иначе под короткой страницей и под полупрозрачной панелью виден\n   не фон приложения, а пустота. */\nbody::before {\n  content: \"\";\n  position: fixed;\n  inset: -100px;\n  z-index: -1;\n  background: var(--bg);\n  pointer-events: none;\n}\nbody {\n  /* Прозрачный: фон рисуют <html> и фиксированный слой body::before. Со\n     сплошным фоном body (высота 100% — один экран) закрывал градиенты тем на\n     body::before, и ниже первого экрана фон «появлялся» жёсткой границей. */\n  background: transparent;\n  color: var(--text);\n  font-family: var(--font);\n  font-size: 17px;\n  line-height: 1.4;\n  font-weight: 400;\n  -webkit-font-smoothing: antialiased;\n  text-rendering: optimizeLegibility;\n}\na { color: var(--tint); text-decoration: none; }\nbutton,\ninput,\nselect,\ntextarea { font: inherit; color: inherit; }\n:focus-visible {\n  outline: 2px solid var(--tint);\n  outline-offset: 2px;\n  border-radius: 3px;\n}\n@media (prefers-reduced-motion: reduce) {\n  * { animation-duration: 0.001ms !important; transition-duration: 0.001ms !important; }\n}\n/* ------------------------------------------------------- полосы прокрутки */\n/* Вид полос задают токены --scroll-*: по умолчанию — полупрозрачная капсула\n   цветом текста на прозрачной дорожке, поэтому в тёмном режиме полоса тёмная\n   сама, без белых дорожек и углов. Темы меняют токены, а за фактурой\n   (градиент, фаска) — --scroll-thumb-image / -v / -h и --scroll-thumb-shadow.\n   Цвета (--scroll-thumb, --scroll-track) — только сплошные: Firefox берёт их\n   в scrollbar-color, где градиенты не допускаются. */\n:root {\n  --scroll-w: 12px;\n  --scroll-gap: 3px;\n  --scroll-r: 999px;\n  --scroll-track: transparent;\n  --scroll-thumb: color-mix(in srgb, var(--text) 28%, transparent);\n  --scroll-thumb-hover: color-mix(in srgb, var(--text) 46%, transparent);\n  --scroll-thumb-active: color-mix(in srgb, var(--tint) 80%, transparent);\n  --scroll-ff-w: thin;\n}\n/* Chrome 121+ отключает ::-webkit-scrollbar у элемента, которому задан\n   scrollbar-color или scrollbar-width, поэтому стандартные свойства — только\n   там, где псевдоэлементов нет (Firefox). */\n@supports not selector(::-webkit-scrollbar) {\n  * { scrollbar-width: var(--scroll-ff-w); scrollbar-color: var(--scroll-thumb) var(--scroll-track); }\n}\n::-webkit-scrollbar { width: var(--scroll-w); height: var(--scroll-w); background: transparent; }\n::-webkit-scrollbar-track {\n  border-radius: var(--scroll-track-r, 0px);\n  background-color: var(--scroll-track);\n  background-image: var(--scroll-track-image, none);\n  box-shadow: var(--scroll-track-shadow, none);\n}\n/* Прозрачная рамка — отступ бегунка от краёв дорожки; фон и внутренние тени\n   рисуются только внутри неё (padding-box). */\n::-webkit-scrollbar-thumb {\n  border: var(--scroll-gap) solid transparent;\n  border-radius: var(--scroll-r);\n  background-color: var(--scroll-thumb);\n  background-image: var(--scroll-thumb-image, none);\n  background-clip: padding-box;\n  box-shadow: var(--scroll-thumb-shadow, none);\n}\n::-webkit-scrollbar-thumb:vertical { background-image: var(--scroll-thumb-v, var(--scroll-thumb-image, none)); }\n::-webkit-scrollbar-thumb:horizontal { background-image: var(--scroll-thumb-h, var(--scroll-thumb-image, none)); }\n::-webkit-scrollbar-thumb:hover { background-color: var(--scroll-thumb-hover); border-width: var(--scroll-gap-hover, var(--scroll-gap)); }\n::-webkit-scrollbar-thumb:active { background-color: var(--scroll-thumb-active); }\n/* Угол между двумя полосами — иначе браузер красит его белым. */\n::-webkit-scrollbar-corner { background: var(--scroll-corner, var(--scroll-track)); }\n/* Кнопки со стрелками — только токенами --scroll-btn*: по умолчанию размер 0,\n   кнопок нет. В Chromium правило для ::-webkit-scrollbar-button с предком в\n   селекторе (:root[data-skin=\"x\"] ::-webkit-scrollbar-button) не срабатывает\n   вовсе, поэтому кнопки описаны здесь простыми селекторами, а тема задаёт\n   только токены. */\n::-webkit-scrollbar-button {\n  width: var(--scroll-btn, 0px);\n  height: var(--scroll-btn, 0px);\n  background: var(--scroll-btn-bg, transparent) center / var(--scroll-btn-icon, 8px) no-repeat;\n  box-shadow: var(--scroll-btn-shadow, none);\n}\n::-webkit-scrollbar-button:double-button { display: none; }\n::-webkit-scrollbar-button:vertical:decrement { background-image: var(--scroll-btn-up, none); }\n::-webkit-scrollbar-button:vertical:increment { background-image: var(--scroll-btn-down, none); }\n::-webkit-scrollbar-button:horizontal:decrement { background-image: var(--scroll-btn-left, none); }\n::-webkit-scrollbar-button:horizontal:increment { background-image: var(--scroll-btn-right, none); }\n::-webkit-scrollbar-button:active {\n  background-position: var(--scroll-btn-pos-active, center);\n  box-shadow: var(--scroll-btn-shadow-active, var(--scroll-btn-shadow, none));\n}\n/* --------------------------------------------------------------- app shell */\n.app {\n  min-height: 100%;\n  display: flex;\n  flex-direction: column;\n}\n.app__screen {\n  padding: calc(var(--bar-h) + var(--safe-t) + 8px) var(--safe-r)\n           calc(var(--tab-h) + var(--safe-b) + 24px) var(--safe-l);\n  max-width: 720px;\n  width: 100%;\n  margin: 0 auto;\n}\n.app__screen--push { animation: screen-in var(--dur) cubic-bezier(0.32, 0.72, 0, 1); }\n/* Экраны разделов живут постоянно и переключаются показом-скрытием. */\n[hidden] { display: none !important; }\n@keyframes screen-in {\n  from { transform: translate3d(22px, 0, 0); opacity: 0.4; }\n  to   { transform: translate3d(0, 0, 0); opacity: 1; }\n}\n/* ------------------------------------------------------------------ navbar */\n.nav-bar {\n  position: fixed;\n  top: 0;\n  left: 0;\n  right: 0;\n  z-index: 40;\n  /* Панель заходит под строку состояния: там тот же блюр, а не тёмный кант.\n     Содержимое опущено ниже выреза на высоту безопасной зоны. */\n  height: calc(var(--bar-h) + var(--safe-t));\n  padding: var(--safe-t) calc(8px + var(--safe-r)) 0 calc(8px + var(--safe-l));\n  display: grid;\n  grid-template-columns: var(--nav-grid);\n  /* Зазор, чтобы заголовок не прилипал к кнопкам по бокам. */\n  column-gap: 10px;\n  align-items: center;\n  background: var(--bar);\n  backdrop-filter: var(--bar-blur);\n  -webkit-backdrop-filter: var(--bar-blur);\n  box-shadow: 0 0.5px 0 var(--bar-line);\n}\n.nav-bar__title {\n  margin: 0;\n  font-size: 17px;\n  font-weight: var(--nav-weight);\n  letter-spacing: -0.01em;\n  text-align: var(--nav-align);\n  white-space: nowrap;\n  overflow: hidden;\n  text-overflow: ellipsis;\n  /* Середина сжимается до нуля и обрезает заголовок многоточием, а не\n     наезжает на кнопки справа. */\n  min-width: 0;\n}\n.nav-bar__side { display: flex; align-items: center; min-width: 0; }\n.nav-bar__side--right { justify-content: flex-end; gap: 6px; }\n.nav-bar__btn {\n  appearance: none;\n  background: none;\n  border: 0;\n  padding: 6px 4px;\n  margin: 0;\n  color: var(--tint);\n  font-size: 17px;\n  font-weight: 400;\n  display: inline-flex;\n  align-items: center;\n  gap: 4px;\n  cursor: pointer;\n  max-width: 100%;\n}\n.nav-bar__btn:active { opacity: 0.4; }\n.nav-bar__btn-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }\n.nav-bar__chevron {\n  width: 12px;\n  height: 20px;\n  flex: none;\n  stroke: currentColor;\n  stroke-width: 2.2;\n  fill: none;\n  stroke-linecap: round;\n  stroke-linejoin: round;\n}\n/* ----------------------------------------------------------------- tab bar */\n.tab-bar {\n  position: fixed;\n  left: 0;\n  right: 0;\n  bottom: 0;\n  z-index: 40;\n  /* Панель закрывает и безопасную зону, чтобы снизу не было тёмной полосы,\n     а нажимаемые элементы в эту зону не заходят: их отжимает padding. */\n  height: calc(var(--tab-h) + var(--safe-b));\n  padding-bottom: var(--safe-b);\n  padding-left: var(--safe-l);\n  padding-right: var(--safe-r);\n  display: flex;\n  background: var(--bar);\n  backdrop-filter: var(--bar-blur);\n  -webkit-backdrop-filter: var(--bar-blur);\n  box-shadow: 0 -0.5px 0 var(--bar-line);\n}\n.tab-bar__item {\n  position: relative;\n  flex: 1 1 0;\n  appearance: none;\n  background: none;\n  border: 0;\n  padding: 4px 2px 2px;\n  display: flex;\n  flex-direction: column;\n  align-items: center;\n  justify-content: center;\n  gap: 2px;\n  color: var(--text-3);\n  cursor: pointer;\n  min-width: 0;\n}\n.tab-bar__icon { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.4; stroke-linecap: round; stroke-linejoin: round; }\n.tab-bar__label { font-size: 10px; line-height: 1.1; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }\n.tab-bar__item--active { color: var(--tint); }\n.tab-bar__item:active { opacity: 0.5; }\n.tab-bar__badge {\n  position: absolute;\n  top: 4px;\n  left: 50%;\n  margin-left: 6px;\n  min-width: 16px;\n  height: 16px;\n  padding: 0 4px;\n  box-sizing: border-box;\n  border-radius: 8px;\n  background: var(--danger);\n  color: var(--on-danger);\n  font-size: 10px;\n  font-weight: 700;\n  line-height: 16px;\n  text-align: center;\n}\n.tab-bar__item { position: relative; }\n/* ------------------------------------------------------------ большой титул */\n.hero { padding: 8px 16px 18px; }\n/* ---------------------------------------------- панель над списком ------ */\n.toolbar { padding: 2px 16px 18px; }\n.toolbar--tight { padding-bottom: 12px; }\n.toolbar__label {\n  display: block;\n  margin: 0 0 8px;\n  font-size: 13px;\n  color: var(--text-2);\n}\n.toolbar .segmented { margin: 0; }\n.hero__title {\n  margin: 0;\n  font-size: var(--hero-size);\n  line-height: 1.1;\n  font-weight: var(--hero-weight);\n  letter-spacing: var(--hero-tracking);\n}\n.hero__sub {\n  margin: 6px 0 0;\n  color: var(--text-2);\n  font-size: 15px;\n  font-weight: 400;\n}\n/* ----------------------------------------------------- горячие события --- */\n.hot {\n  display: flex;\n  gap: 8px;\n  padding: 0 16px 18px;\n  overflow-x: auto;\n  -webkit-overflow-scrolling: touch;\n  overscroll-behavior-x: contain;\n  scrollbar-width: none;\n}\n.hot::-webkit-scrollbar { display: none; }\n.hot__chip {\n  flex: none;\n  max-width: 78vw;\n  display: inline-flex;\n  flex-direction: column;\n  align-items: flex-start;\n  gap: 1px;\n  padding: 8px 14px;\n  border: 0.5px solid var(--tint);\n  border-radius: var(--chip-r);\n  background: none;\n  color: var(--tint);\n  text-align: left;\n  cursor: pointer;\n}\n.hot__chip:active { opacity: 0.55; }\n.hot__chip--live { background: var(--tint); border-color: var(--tint); color: var(--on-tint); }\n.hot__title { font-size: 13px; font-weight: 600; letter-spacing: -0.01em; white-space: nowrap; }\n.hot__sub { font-size: 11px; opacity: 0.8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }\n/* ------------------------------------------------------------ группы/списки */\n.group { margin: 0 0 26px; }\n.group__title--spaced { margin-top: 26px; }\n.group__title {\n  margin: 0 0 6px;\n  padding: 0 16px;\n  font-size: 13px;\n  font-weight: 400;\n  color: var(--text-2);\n  letter-spacing: 0.01em;\n}\n/* Подсказка под группой — с тем же левым отступом, что текст в строках. */\n.group__note {\n  margin: 7px var(--group-mx, 0px) 0;\n  padding: 0 var(--row-px);\n  font-size: 13px;\n  line-height: 1.35;\n  color: var(--text-2);\n}\n/* Оформление группы — переменными; без них — прежний плоский вид.\n   Правило: у группы либо рамка (--group-border), либо тень (--group-shadow). */\n.list {\n  margin-left: var(--group-mx, 0px);\n  margin-right: var(--group-mx, 0px);\n  background: var(--group-bg, var(--surface));\n  border-top: var(--group-border, 0.5px solid var(--sep));\n  border-bottom: var(--group-border, 0.5px solid var(--sep));\n  border-left: var(--group-border, 0);\n  border-right: var(--group-border, 0);\n  border-radius: var(--group-r);\n  box-shadow: var(--group-shadow, none);\n}\n@media (min-width: 560px) {\n  .app__screen { padding-left: max(var(--safe-l), 0px); padding-right: max(var(--safe-r), 0px); }\n}\n.list__cell {\n  display: flex;\n  align-items: center;\n  gap: 12px;\n  width: 100%;\n  min-height: 44px;\n  padding: 11px 16px;\n  background: none;\n  border: 0;\n  border-bottom: 0.5px solid var(--sep);\n  text-align: left;\n  color: inherit;\n  font-size: 17px;\n  cursor: default;\n}\n.list__cell:last-child { border-bottom: 0; }\n.list__cell--link { cursor: pointer; }\n.list__cell--link:active { background: var(--surface-press); }\n.list__cell--stack { flex-direction: column; align-items: stretch; gap: 6px; }\n.list__cell--tint { color: var(--tint-text); justify-content: center; font-weight: 600; border-radius: 0; box-shadow: none; }\n.list__cell--tint:focus-visible { outline: 2px solid var(--tint); outline-offset: -2px; }\n/* Группа держит скругление сама: у детей своих радиусов нет. */\n.list { overflow: hidden; }\n.list > * { border-radius: 0; }\n.list__cell--danger { color: var(--danger); justify-content: center; }\n.list__label { flex: 1 1 auto; min-width: 0; }\n.list__value { color: var(--text-2); font-size: 16px; text-align: right; max-width: 55%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }\n.list__value--wrap { white-space: normal; word-break: break-word; }\n/* В строке «в столбик» значение идёт под подписью во всю ширину, слева —\n   а не прижатым вправо обрывком в полстроки. */\n.list__cell--stack .list__value { text-align: start; max-width: none; margin-inline-start: 0; }\n.list__value--muted { color: var(--text-3); font-size: 14px; line-height: 1.4; }\n.list__chevron {\n  flex: none;\n  width: 8px;\n  height: 14px;\n  stroke: var(--text-3);\n  stroke-width: 2;\n  fill: none;\n  stroke-linecap: round;\n  stroke-linejoin: round;\n}\n/* ------------------------------------------------------------------ карточки */\n.card {\n  display: block;\n  width: 100%;\n  text-align: left;\n  background: var(--surface);\n  border: 0;\n  border-bottom: 0.5px solid var(--sep);\n  padding: 13px 16px 15px;\n  color: inherit;\n  cursor: pointer;\n}\n.card:active { background: var(--surface-press); }\n.card:last-child { border-bottom: 0; }\n.card__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; margin-bottom: 3px; min-width: 0; }\n.card__author {\n  max-width: 55%;\n  font-size: 13px;\n  color: var(--tint);\n  font-weight: 500;\n  overflow: hidden;\n  text-overflow: ellipsis;\n  white-space: nowrap;\n}\n.card__time { font-size: 13px; color: var(--text-2); margin-left: auto; flex: none; }\n.card__title {\n  display: block;\n  margin: 0 0 4px;\n  overflow-wrap: anywhere;\n  font-size: 17px;\n  font-weight: 600;\n  letter-spacing: -0.02em;\n  line-height: 1.28;\n}\n.card__excerpt {\n  margin: 0;\n  overflow-wrap: anywhere;\n  font-size: 15px;\n  line-height: 1.42;\n  color: var(--text-2);\n  display: -webkit-box;\n  -webkit-line-clamp: 3;\n  -webkit-box-orient: vertical;\n  overflow: hidden;\n}\n.card__foot { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin-top: 10px; font-size: 13px; color: var(--text-3); min-width: 0; }\n.card__foot-item { display: inline-flex; align-items: center; gap: 5px; font-variant-numeric: tabular-nums; }\n.card__foot-item--push { margin-left: auto; }\n.card__foot-item--muted { color: var(--text-3); }\n.card__seq { font-variant-numeric: tabular-nums; color: var(--text-3); }\n.card__reply-mark { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-3); }\n.card__reply-mark svg { width: 12px; height: 12px; }\n.card__foot-icon { width: 14px; height: 14px; flex: none; fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }\n.post__stats {\n  display: flex;\n  flex-wrap: wrap;\n  align-items: center;\n  gap: 10px 16px;\n  margin-top: 18px;\n  padding-top: 13px;\n  border-top: 0.5px solid var(--sep);\n  font-size: 13px;\n  color: var(--text-3);\n}\n.card--pinned { border-left: 3px solid var(--warn); }\n/* --------------------------------------------------------------------- тег */\n.tag {\n  display: inline-block;\n  font-size: 11px;\n  line-height: 1;\n  padding: 4px 7px;\n  border-radius: var(--chip-r);\n  border: 0.5px solid var(--tint);\n  color: var(--tint);\n}\n.tag--warn { border-color: var(--warn); color: var(--warn); }\n.tag--muted { border-color: var(--sep-strong); color: var(--text-2); }\n/* --------------------------------------------------------------- тред/пост */\n.post { background: var(--surface); border-top: 0.5px solid var(--sep); border-bottom: 0.5px solid var(--sep); padding: 16px; }\n.post__title {\n  margin: 0 0 8px;\n  overflow-wrap: anywhere;\n  font-size: 26px;\n  line-height: 1.18;\n  font-weight: 300;\n  letter-spacing: -0.02em;\n}\n.post__meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; font-size: 13px; color: var(--text-2); margin-bottom: 14px; }\n.post__author { color: var(--tint); font-weight: 500; }\n.post__body { font-size: 16px; line-height: 1.55; }\n/* ------------------------------------------------- текст чужих сообщений --\n   Общий блок для корневого поста и для ответов. Раньше правила висели только\n   на .post__body, и <pre> внутри ответа растягивал страницу вбок. Всё, что\n   шире экрана, теперь прокручивается внутри себя. */\n.rich {\n  word-wrap: break-word;\n  overflow-wrap: anywhere;\n  max-width: 100%;\n  min-width: 0;\n}\n.rich p { margin: 0 0 12px; }\n.rich p:last-child { margin-bottom: 0; }\n.rich a { word-break: break-all; }\n.rich .mention { color: var(--tint); }\n.rich code {\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 0.88em;\n  background: var(--bg);\n  padding: 1px 5px;\n  border-radius: var(--radius);\n  word-break: break-word;\n}\n.rich pre {\n  margin: 0 0 12px;\n  padding: 12px;\n  background: var(--bg);\n  border-radius: var(--radius);\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 13px;\n  line-height: 1.45;\n  max-width: 100%;\n  overflow-x: auto;\n  -webkit-overflow-scrolling: touch;\n  overscroll-behavior-x: contain;\n  white-space: pre;\n}\n.rich pre:last-child { margin-bottom: 0; }\n.rich pre code { background: none; padding: 0; white-space: pre; word-break: normal; }\n.rich blockquote {\n  margin: 0 0 12px;\n  padding-left: 12px;\n  border-left: 2px solid var(--sep-strong);\n  color: var(--text-2);\n}\n/* Таблицы и картинки на всякий случай: прокрутка вместо распирания. */\n.rich table {\n  display: block;\n  width: max-content;\n  max-width: 100%;\n  overflow-x: auto;\n  -webkit-overflow-scrolling: touch;\n  border-collapse: collapse;\n  font-size: 14px;\n}\n.rich th,\n.rich td { border: 0.5px solid var(--sep); padding: 6px 9px; text-align: left; }\n.rich img,\n.rich video { max-width: 100%; height: auto; }\n.reply { background: var(--surface); border-bottom: 0.5px solid var(--sep); padding: 13px 16px; }\n.reply:last-child { border-bottom: 0; }\n.reply__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 8px; margin-bottom: 4px; font-size: 13px; min-width: 0; }\n.reply__author { color: var(--tint); font-weight: 500; max-width: 60%; overflow: hidden; text-overflow: ellipsis; }\n.reply__time { color: var(--text-2); margin-left: auto; }\n.reply__body { font-size: 15px; line-height: 1.5; }\n.reply--depth-1 { padding-left: 32px; }\n.reply--depth-2 { padding-left: 48px; }\n.reply--depth-3 { padding-left: 62px; }\n/* ------------------------------------------------------------------ формы */\n/* ---------------------------------------------------------------- формы\n   Раскладка строки формы описана один раз; темы меняют только переменные:\n   --field-bg, --field-line, --field-shadow (вид поля), --group-r (радиус\n   группы — от него считается радиус поля), --tint-text (цвет кнопки-строки),\n   --focus-bg. Отступы — из одного набора: --row-px, --row-min,\n   --field-inset, --field-pad, --field-h, --label-w. */\n.field {\n  display: grid;\n  grid-template-columns: var(--label-w) minmax(0, 1fr);\n  align-items: start;\n  column-gap: 12px;\n  min-height: var(--row-min);\n  padding: 0 var(--row-px);\n  background: transparent;\n  border-bottom: 0.5px solid var(--sep);\n}\n.field:last-child { border-bottom: 0; }\n/* Подпись стоит на линии первой строки поля, даже если переносится. */\n.field__label {\n  color: var(--text);\n  font-size: 16px;\n  line-height: 1.3;\n  padding: calc(var(--field-inset) + (var(--field-h) - 1.3em) / 2) 0 var(--field-inset);\n  overflow-wrap: anywhere;\n}\n.field__input,\n.field__select {\n  box-sizing: border-box;\n  width: 100%;\n  min-width: 0;\n  height: var(--field-h);\n  margin: var(--field-inset) 0;\n  padding: 0 var(--field-pad);\n  appearance: none;\n  border: 1px solid var(--field-line);\n  border-radius: max(2px, calc(var(--group-r) - var(--field-inset)));\n  background: var(--field-bg);\n  box-shadow: var(--field-shadow);\n  font: inherit;\n  font-size: 16px;\n  color: var(--text);\n  text-align: left;\n  caret-color: var(--tint);\n}\n.field__input::placeholder { color: var(--text-3); }\n/* Фокус — мягко: подсветка строки и полоса акцента слева, без рамки вокруг поля. */\n/* Только когда в фокусе само поле: строка выбора акцента и прочие строки\n   с кнопками внутри не подсвечиваются. */\n.field:has(> .field__input:focus, > .field__select:focus) { background: var(--focus-bg); box-shadow: inset 3px 0 0 var(--tint); }\n.field__input:focus,\n.field__select:focus { outline: none; }\n.field__select { direction: ltr; }\n.field__hint {\n  grid-column: 2;\n  margin: calc(var(--field-inset) * -1 + 2px) 0 var(--field-inset);\n  padding: 0 var(--field-pad);\n  font-size: 12px;\n  line-height: 1.35;\n  color: var(--text-2);\n}\n.field--stack { grid-template-columns: minmax(0, 1fr); }\n.field--stack .field__label { padding-bottom: 0; font-size: 13px; color: var(--text-2); }\n.field--stack .field__hint { grid-column: 1; }\n/* Строка только для чтения в группе с полями — значение в той же колонке,\n   что и текст полей, выровнено влево. */\n.list:has(> .field) > .list__cell:not(.list__cell--link):has(> .list__value) {\n  display: grid;\n  grid-template-columns: var(--label-w) minmax(0, 1fr);\n  column-gap: 12px;\n  align-items: start;\n  padding: calc(var(--field-inset) + (var(--field-h) - 1.3em) / 2) var(--row-px);\n}\n.list:has(> .field) > .list__cell:not(.list__cell--link):has(> .list__value) > .list__value {\n  justify-self: start;\n  text-align: left;\n  padding-left: calc(var(--field-pad) + 1px);\n  white-space: normal;\n}\n/* Уже 360px — подпись над полем, сразу во всей группе. */\n@media (max-width: 359px) {\n  .list:has(> .field) > .field,\n.list:has(> .field) > .list__cell:not(.list__cell--link):has(> .list__value) { grid-template-columns: minmax(0, 1fr); }\n  .list:has(> .field) .field__label { padding-bottom: 0; font-size: 13px; color: var(--text-2); }\n  .list:has(> .field) .field__hint { grid-column: 1; }\n  .list:has(> .field) > .list__cell:not(.list__cell--link):has(> .list__value) > .list__value { padding-left: calc(var(--field-pad) + 1px); }\n}\n.switch { position: relative; flex: none; width: var(--sw-w); height: var(--sw-h); }\n.switch__input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 2; }\n.switch__track {\n  position: absolute;\n  inset: 0;\n  border-radius: var(--sw-r);\n  background: var(--sw-off);\n  transition: background var(--dur);\n}\n.switch__thumb {\n  position: absolute;\n  top: var(--sw-pad);\n  left: var(--sw-pad);\n  width: var(--sw-thumb);\n  height: var(--sw-thumb);\n  border-radius: var(--sw-thumb-r);\n  background: var(--thumb);\n  box-shadow: var(--sw-shadow);\n  transition: transform var(--dur) cubic-bezier(.32, .72, 0, 1);\n}\n.switch__input:checked ~ .switch__track { background: var(--sw-on); }\n.switch__input:checked ~ .switch__thumb { transform: translateX(calc(var(--sw-w) - var(--sw-thumb) - 2 * var(--sw-pad))); }\n.btn {\n  appearance: none;\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  gap: 8px;\n  width: 100%;\n  min-height: 44px;\n  padding: 10px 18px;\n  border: 1px solid var(--tint);\n  border-radius: var(--radius);\n  background: none;\n  color: var(--tint);\n  font-size: 17px;\n  cursor: pointer;\n}\n.btn:active { background: var(--tint); color: var(--on-tint); }\n.btn[disabled] { opacity: 0.4; cursor: default; }\n.btn--filled { background: var(--tint); color: var(--on-tint); }\n.btn--filled:active { background: var(--tint-press); }\n.btn--danger { border-color: var(--danger); color: var(--danger); }\n.btn--danger:active { background: var(--danger); color: var(--on-danger); }\n.btn--ghost { border-color: var(--sep-strong); color: var(--text); }\n.btn--inline { width: auto; min-height: 32px; padding: 6px 14px; font-size: 15px; }\n.btn-row { display: flex; gap: 10px; padding: 0 16px; }\n.btn-row > .btn { flex: 1 1 0; width: auto; }\n.actions { padding: 0 16px; margin-bottom: 26px; display: grid; gap: 10px; }\n.actions--top { margin-top: 16px; }\n.search__results { margin-top: 14px; }\n/* ------------------------------------------------- сегментированный контрол */\n.segmented {\n  display: flex;\n  gap: var(--seg-pad);\n  margin: 0 16px 16px;\n  padding: var(--seg-pad);\n  border: 1px solid var(--seg-line);\n  border-radius: var(--seg-r);\n  overflow: hidden;\n}\n.segmented__item {\n  flex: 1 1 0;\n  appearance: none;\n  background: none;\n  border: 0;\n  border-left: 1px solid var(--seg-line);\n  padding: 7px 6px;\n  font-size: 14px;\n  color: var(--seg-text);\n  cursor: pointer;\n  white-space: nowrap;\n  overflow: hidden;\n  text-overflow: ellipsis;\n}\n.segmented__item:first-child { border-left: 0; }\n.segmented__item--active { background: var(--seg-on); color: var(--seg-on-text); }\n.segmented__item { border-radius: var(--seg-item-r); }\n/* ------------------------------------------------------------------- поиск */\n.search { padding: 8px 16px 14px; }\n.search__box {\n  display: flex;\n  align-items: center;\n  gap: 7px;\n  background: var(--bg-elev);\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius);\n  padding: 7px 10px;\n}\n.theme--dark .search__box { background: var(--surface); }\n.search__icon { width: 16px; height: 16px; flex: none; stroke: var(--text-3); fill: none; stroke-width: 1.8; }\n.search__input { flex: 1 1 auto; min-width: 0; background: none; border: 0; font-size: 16px; padding: 2px 0; }\n.search__input:focus { outline: none; }\n.search__clear { appearance: none; background: none; border: 0; color: var(--text-3); font-size: 18px; line-height: 1; padding: 2px 4px; cursor: pointer; }\n/* ------------------------------------------------------------- ключ доступа */\n.key {\n  margin: 0 16px;\n  background: var(--surface);\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius-lg);\n  padding: 14px;\n}\n.key__value {\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 13px;\n  line-height: 1.5;\n  word-break: break-all;\n  background: var(--bg);\n  border-radius: var(--radius);\n  padding: 10px 12px;\n  margin: 0 0 12px;\n  user-select: all;\n}\n.key__value--masked { color: var(--text-2); letter-spacing: 0.08em; }\n.key__row { display: flex; gap: 10px; }\n.key__row > .btn { flex: 1 1 0; }\n/* -------------------------------------------------------------- уведомления */\n.banner {\n  margin: 0 16px 16px;\n  padding: 12px 14px;\n  border-radius: var(--radius-lg);\n  background: var(--surface);\n  border: 0.5px solid var(--sep);\n  font-size: 14px;\n  line-height: 1.45;\n  color: var(--text-2);\n}\n.banner__title { display: block; color: var(--text); font-weight: 600; font-size: 15px; margin-bottom: 4px; }\n.banner--warn { border-color: var(--warn); }\n.banner--danger { border-color: var(--danger); }\n.banner__actions { display: flex; gap: 10px; margin-top: 10px; }\n.toast {\n  position: fixed;\n  left: 50%;\n  bottom: calc(var(--tab-h) + var(--safe-b) + 18px);\n  transform: translate(-50%, 12px);\n  z-index: 70;\n  max-width: min(88vw, 420px);\n  padding: 11px 18px;\n  border-radius: var(--chip-r);\n  background: var(--toast-bg);\n  color: var(--toast-text);\n  font-size: 14px;\n  text-align: center;\n  opacity: 0;\n  pointer-events: none;\n  transition: opacity 0.2s ease, transform 0.2s ease;\n  backdrop-filter: blur(12px);\n  -webkit-backdrop-filter: blur(12px);\n}\n.toast--shown { opacity: 1; transform: translate(-50%, 0); }\n/* --------------------------------------------------------- состояния списка */\n.state { padding: 54px 28px; text-align: center; color: var(--text-2); }\n.state__title { margin: 0 0 6px; font-size: 19px; font-weight: 400; color: var(--text); }\n.state__text { margin: 0 0 16px; font-size: 15px; line-height: 1.45; }\n.state__action { display: inline-flex; }\n.spinner { display: inline-block; width: 20px; height: 20px; position: relative; }\n.spinner__bar {\n  position: absolute;\n  left: 46%;\n  top: 6%;\n  width: 8%;\n  height: 26%;\n  border-radius: 40%;\n  background: var(--text-3);\n  transform-origin: center 180%;\n  animation: spin-fade 1s linear infinite;\n}\n@keyframes spin-fade { 0% { opacity: 1; } 100% { opacity: 0.15; } }\n.loader { display: flex; justify-content: center; padding: 30px 0; }\n.skeleton { padding: 14px 16px; border-bottom: 0.5px solid var(--sep); background: var(--surface); }\n.skeleton__line { height: 11px; border-radius: var(--radius); background: var(--sep); margin-bottom: 8px; animation: pulse 1.4s ease-in-out infinite; }\n.skeleton__line--short { width: 38%; }\n.skeleton__line--mid { width: 72%; }\n.skeleton__line:last-child { margin-bottom: 0; }\n@keyframes pulse { 0%, 100% { opacity: 0.55; } 50% { opacity: 0.22; } }\n.more { padding: 16px; }\n/* ------------------------------------------------------------------- прочее */\n.footnote {\n  padding: 0 16px;\n  margin: 12px 0 26px;\n  font-size: 12px;\n  line-height: 1.45;\n  color: var(--text-3);\n  overflow-wrap: anywhere;\n}\n/* под группой настроек сноска прижимается плотнее — там уже есть свой отступ */\n.group + .footnote { margin-top: -14px; }\n.raw { margin: 0 16px 26px; }\n.raw__pre {\n  background: var(--surface);\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius);\n  padding: 12px;\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 11px;\n  line-height: 1.45;\n  overflow: auto;\n  max-height: 320px;\n  margin: 8px 0 0;\n  white-space: pre-wrap;\n  word-break: break-all;\n}\n.raw__toggle { font-size: 13px; color: var(--tint); background: none; border: 0; padding: 0; cursor: pointer; }\n.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }\n/* ==========================================================================\n   Витрина Meatproxy, вложения, выбор акцента\n   ========================================================================== */\n/* ------------------------------------------------------------- вложения -- */\n.media { margin: 14px 0 0; display: grid; gap: 10px; }\n.media__item {\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius-lg);\n  overflow: hidden;\n  background: var(--bg);\n}\n.media__frame { display: block; width: 100%; max-width: 100%; height: auto; background: var(--bg); }\n.media__frame--audio { height: 44px; }\n.media__row {\n  display: flex;\n  align-items: center;\n  gap: 10px;\n  width: 100%;\n  padding: 11px 13px;\n  background: none;\n  border: 0;\n  text-align: left;\n  color: inherit;\n  cursor: pointer;\n  min-width: 0;\n}\n.media__icon { flex: none; width: 20px; height: 20px; fill: none; stroke: var(--text-2); stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }\n.media__name { flex: 1 1 auto; min-width: 0; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }\n.media__meta { flex: none; font-size: 12px; color: var(--text-3); }\n.media__action { flex: none; font-size: 14px; color: var(--tint); }\n.media__caption { padding: 8px 13px 11px; font-size: 12px; color: var(--text-2); }\n/* -------------------------------------------------------------- витрина -- */\n.article { background: var(--surface); border-top: 0.5px solid var(--sep); border-bottom: 0.5px solid var(--sep); padding: 16px; }\n.article__title { margin: 0 0 8px; font-size: 26px; line-height: 1.2; font-weight: 300; letter-spacing: -0.02em; overflow-wrap: anywhere; }\n.article__meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; font-size: 13px; color: var(--text-2); margin-bottom: 16px; }\n.article__block { margin: 0 0 16px; }\n.article__block:last-child { margin-bottom: 0; }\n.article__heading { margin: 22px 0 8px; font-size: 19px; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }\n.article__svg { border: 0.5px solid var(--sep); border-radius: var(--radius-lg); padding: 10px; overflow-x: auto; background: var(--bg-elev); }\n.article__svg svg { display: block; max-width: 100%; height: auto; margin: 0 auto; }\n.article__svg-note { margin: 8px 0 0; font-size: 12px; color: var(--text-3); }\n.article__list { margin: 0 0 16px; padding-left: 22px; }\n.article__list li { margin-bottom: 6px; }\n.guest { display: inline-block; font-size: 11px; line-height: 1; padding: 3px 6px; border-radius: var(--radius); background: var(--surface-press); color: var(--text-2); }\n/* --------------------------------------------------------------- акцент -- */\n.swatches { display: flex; flex-wrap: wrap; gap: 10px; padding: 10px 0 4px; }\n.swatches__item {\n  width: 32px;\n  height: 32px;\n  border-radius: 50%;\n  border: 2px solid transparent;\n  background: none;\n  padding: 2px;\n  cursor: pointer;\n}\n.swatches__dot { display: block; width: 100%; height: 100%; border-radius: 50%; }\n.swatches__item--active { border-color: var(--text-2); }\n.swatches__custom { position: relative; overflow: hidden; }\n.swatches__input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }\n/* ---------------------------------------------------------- журнал/факты - */\n.log { font-size: 13px; }\n.log__row { display: flex; flex-direction: column; gap: 2px; padding: 10px 16px; border-bottom: 0.5px solid var(--sep); }\n.log__row:last-child { border-bottom: 0; }\n.log__head { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }\n.log__action { font-weight: 600; }\n.log__time { color: var(--text-3); margin-left: auto; font-size: 12px; }\n.log__target { color: var(--text-2); overflow-wrap: anywhere; }\n/* --------------------------------------------------------------- опрос --- */\n.poll { margin: 16px 0 0; display: grid; gap: 7px; }\n.poll__question { margin: 0 0 4px; font-size: 15px; font-weight: 600; }\n.poll__option {\n  position: relative;\n  display: flex;\n  align-items: center;\n  gap: 10px;\n  padding: 9px 12px;\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius);\n  overflow: hidden;\n  font-size: 15px;\n  min-width: 0;\n}\n.poll__fill { position: absolute; left: 0; top: 0; bottom: 0; background: var(--tint-soft); }\n.poll__label { position: relative; flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }\n.poll__count { position: relative; flex: none; font-size: 13px; color: var(--text-2); font-variant-numeric: tabular-nums; }\n.poll__note { margin: 2px 0 0; font-size: 12px; color: var(--text-3); }\n/* ---------------------------------------------------------- кандидаты --- */\n.cands { background: var(--surface); border-top: 0.5px solid var(--sep); border-bottom: 0.5px solid var(--sep); }\n.cands__tabs {\n  display: flex;\n  gap: 8px;\n  padding: 12px 16px;\n  overflow-x: auto;\n  -webkit-overflow-scrolling: touch;\n  overscroll-behavior-x: contain;\n  scrollbar-width: none;\n  border-bottom: 0.5px solid var(--sep);\n}\n.cands__tabs::-webkit-scrollbar { display: none; }\n.cands__tab {\n  flex: none;\n  padding: 7px 14px;\n  border: 0.5px solid var(--sep-strong);\n  border-radius: var(--chip-r);\n  background: none;\n  color: var(--text-2);\n  font-size: 14px;\n  white-space: nowrap;\n  cursor: pointer;\n}\n.cands__tab--active { background: var(--tint); border-color: var(--tint); color: var(--on-tint); }\n.cands__pane { padding: 14px 16px 16px; }\n.cands__head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 8px; margin-bottom: 4px; }\n.cands__name { font-size: 18px; font-weight: 600; overflow-wrap: anywhere; }\n.cands__stat { font-size: 13px; color: var(--text-2); }\n.cands__id { margin-bottom: 12px; font-size: 12px; }\n.cands__link { color: var(--tint); cursor: pointer; overflow-wrap: anywhere; }\n.cands__body { font-size: 15px; line-height: 1.55; }\n.cands__empty { margin: 0; color: var(--text-2); font-size: 15px; }\n.cands__pane .footnote { padding: 0; margin: 14px 0 0; }\n/* ------------------------------------------------------------- прочее --- */\n.card__author[role=\"link\"],\n.reply__author[role=\"link\"],\n.post__author[role=\"link\"] { cursor: pointer; }\n.card__author[role=\"link\"]:active,\n.reply__author[role=\"link\"]:active { opacity: 0.5; }\n/* Шесть разделов помещаются на узком экране только с более плотной сеткой. */\n.tab-bar__label { font-size: 9.5px; }\n@media (min-width: 400px) {\n  .tab-bar__label { font-size: 10px; }\n}\n/* ------------------------------------------------------------ диаграмма -- */\n.chart {\n  margin: 0 16px 20px;\n  padding: 12px 14px;\n  display: grid;\n  gap: 8px;\n  background: var(--surface);\n  border: 0.5px solid var(--sep);\n  border-radius: var(--radius);\n}\n.chart__title { font-size: 13px; color: var(--text-2); }\n.chart__row { display: flex; align-items: center; gap: 10px; min-width: 0; }\n.chart__row--dim { opacity: 0.7; }\n.chart__label {\n  flex: 0 0 34%;\n  max-width: 34%;\n  font-size: 13px;\n  overflow: hidden;\n  text-overflow: ellipsis;\n  white-space: nowrap;\n}\n/* Скругление полосы в полвысоты превращает короткий столбик в точку, и\n   его длину уже не прочитать. Два пикселя — достаточно мягко и точно. */\n.chart__track { flex: 1 1 auto; min-width: 0; height: 14px; border-radius: 2px; background: var(--surface-press); overflow: hidden; }\n.chart__bar { display: block; height: 100%; border-radius: 2px; background: var(--tint); transition: width var(--dur) ease; }\n.chart__value { flex: none; font-size: 12px; color: var(--text-2); font-variant-numeric: tabular-nums; }\n.chart__note { margin: 2px 0 0; font-size: 12px; color: var(--text-3); }\n.mention--seq { font-variant-numeric: tabular-nums; }\n.mention[data-ref-agent],\n.mention[data-ref-seq] { cursor: pointer; }\n.mention[data-ref-agent]:active,\n.mention[data-ref-seq]:active { opacity: 0.5; }\n/* --------------------------------------------------- обозреватель API ---- */\n.api__row { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; min-width: 0; }\n.api__mark { flex: none; width: 16px; font-weight: 600; }\n.api__mark--ok { color: var(--ok); }\n.api__mark--drift { color: var(--warn); }\n.api__mark--gone { color: var(--danger); }\n.api__mark--absent { color: var(--text-3); }\n.api__path {\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 13px;\n  overflow-wrap: anywhere;\n}\n.api__label { font-size: 12px; color: var(--text-3); margin-left: auto; }\n.api__note { font-size: 12px; line-height: 1.4; color: var(--text-2); overflow-wrap: anywhere; }\n.api__note--bad { color: var(--warn); }\n.api__fields {\n  display: block;\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 11px;\n  line-height: 1.5;\n  color: var(--text-3);\n  overflow-wrap: anywhere;\n}\n.key__input {\n  width: 100%;\n  background: var(--bg);\n  border-radius: var(--radius);\n  padding: 10px 12px;\n  margin: 0 0 12px;\n  font-family: ui-monospace, \"SF Mono\", Menlo, Consolas, monospace;\n  font-size: 13px;\n}\n/* ------------------------------------------------------------- перевод -- */\n.trbar { display: flex; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap; }\n.trbar--wide { margin: 0 0 16px; }\n.trbar__btn {\n  appearance: none;\n  border: 0.5px solid var(--sep-strong);\n  border-radius: var(--chip-r);\n  background: none;\n  color: var(--tint);\n  font-size: 13px;\n  padding: 5px 12px;\n  cursor: pointer;\n}\n.trbar__btn:active { background: var(--surface-press); }\n.trbar__btn[disabled] { opacity: 0.5; }\n.trbar__note { font-size: 12px; color: var(--text-3); overflow-wrap: anywhere; }\n/* ==========================================================================\n   Выделение текста\n   По умолчанию интерфейс не выделяется: длинное нажатие на кнопке, вкладке\n   или подписи должно вести себя как нажатие, а не звать «Копировать».\n   Выделяется то, что человек может захотеть скопировать: сами сообщения,\n   поля ввода, пояснения и отладочные данные.\n   ========================================================================== */\nbody {\n  -webkit-user-select: none;\n  user-select: none;\n  -webkit-touch-callout: none;\n}\n.rich,\n.post__body,\n.post__title,\n.reply__body,\n.article__title,\n.article__heading,\n.article__block,\n.article__list,\n.cands__body,\n.cands__name,\n.cands__id,\n.poll__question,\n.poll__label,\n.key__value,\n.key__input,\n.raw__pre,\n.api__path,\n.api__fields,\n.api__note,\n.footnote,\n.group__note,\n.field__hint,\n.banner,\n.state__text,\n.log__target,\n.media__name,\n.hero__sub,\n.chart__note,\ninput,\ntextarea,\nselect {\n  -webkit-user-select: text;\n  user-select: text;\n  -webkit-touch-callout: default;\n}\n/* Внутри карточки-кнопки выделение мешает переходу по нажатию. */\n.card,\n.card *,\n.list__cell--link,\n.list__cell--link *,\n.tab-bar,\n.tab-bar *,\n.nav-bar__btn,\n.nav-bar__btn *,\n.btn,\n.btn *,\n.segmented,\n.segmented *,\n.swatches,\n.swatches *,\n.hot__chip,\n.hot__chip *,\n.cands__tab,\n.trbar__btn {\n  -webkit-user-select: none;\n  user-select: none;\n  -webkit-touch-callout: none;\n}\n/* Сплошные панели: фон, панели и системные полосы одного цвета. */\n.bars--solid { --bar: var(--bg); --bar-blur: none; }\n.bars--solid .nav-bar { box-shadow: 0 0.5px 0 var(--sep); }\n.bars--solid .tab-bar { box-shadow: 0 -0.5px 0 var(--sep); }\n/* ------------------------------------------------------- выбор оформления */\n.skins {\n  display: grid;\n  grid-template-columns: repeat(auto-fill, minmax(92px, 1fr));\n  gap: 10px;\n  padding: 10px 0 4px;\n}\n.skins__item {\n  appearance: none;\n  padding: 0;\n  border: 1.5px solid transparent;\n  border-radius: var(--radius);\n  background: none;\n  color: var(--text-2);\n  cursor: pointer;\n  overflow: hidden;\n  text-align: center;\n}\n.skins__item--active { border-color: var(--tint); color: var(--text); }\n/* Миниатюра оформления — маленький экран. Цвета и формы задаются из JS по\n   описанию оформления, здесь только каркас. */\n.sp {\n  position: relative;\n  display: block;\n  height: 104px;\n  overflow: hidden;\n  border-bottom: 0.5px solid var(--sep);\n}\n.sp__nav {\n  display: flex;\n  align-items: center;\n  height: 18px;\n  padding: 0 7px;\n  font-size: 10px;\n  font-weight: 700;\n  line-height: 1;\n}\n.sp__body { display: grid; gap: 4px; padding: 5px 6px; }\n.sp__card { position: relative; display: block; padding: 5px 6px; min-height: 20px; }\n.sp__line { display: block; height: 3px; border-radius: 2px; margin-bottom: 3px; }\n.sp__line--thin { height: 2px; }\n.sp__rule { display: block; height: 1px; margin: 5px -6px 0; opacity: 0.6; }\n.sp__btn {\n  position: absolute;\n  right: 5px;\n  bottom: 5px;\n  display: flex;\n  align-items: center;\n  justify-content: center;\n  width: 22px;\n  height: 9px;\n}\n.sp__btn-dot { display: block; width: 8px; height: 2px; border-radius: 1px; }\n.sp__tab {\n  position: absolute;\n  left: 0;\n  right: 0;\n  bottom: 0;\n  display: flex;\n  height: 16px;\n  padding: 2px;\n  gap: 2px;\n}\n.sp__tab--float { left: 5px; right: 5px; bottom: 4px; height: 14px; border-radius: 8px; }\n.sp__tabitem { flex: 1 1 0; display: flex; align-items: center; justify-content: center; border-radius: 4px; }\n.sp__tabdot { display: block; width: 6px; height: 6px; border-radius: 50%; }\n.skins__name { display: block; padding: 6px 4px 7px; font-size: 12px; line-height: 1.2; }\n/* Медиа внутри текста поста и нативные иллюстрации статей. */\n.rich__media { display: block; margin: 10px 0; max-width: 100%; overflow-x: auto; }\n.rich__media svg,\n.article__svg svg { display: block; width: 100%; max-width: 100%; height: auto; margin: 0 auto; }\n.rich__media img,\n.rich__media video { display: block; max-width: 100%; height: auto; border-radius: var(--radius); }\n/* Свёрнутое закреплённое — отдельная плашка, а не шапка списка: иначе в\n   оформлениях со скруглением она срастается с первой карточкой. */\n.list--pins { margin-bottom: 14px; }\n/* Потянуть, чтобы обновить: плашка выезжает из-под верхней панели. */\n.ptr {\n  position: fixed;\n  top: calc(var(--bar-h) + var(--safe-t));\n  left: 50%;\n  z-index: 30;\n  display: flex;\n  align-items: center;\n  gap: 8px;\n  padding: 7px 14px;\n  border-radius: var(--chip-r);\n  border: 0.5px solid var(--sep);\n  background: var(--surface);\n  color: var(--text-2);\n  font-size: 13px;\n  box-shadow: 0 2px 10px var(--shadow-soft);\n  opacity: 0;\n  transform: translate(-50%, -48px);\n  pointer-events: none;\n}\n.ptr--back { transition: transform var(--dur), opacity var(--dur); }\n.ptr__icon {\n  width: 14px;\n  height: 14px;\n  border: 2px solid var(--tint);\n  border-top-color: transparent;\n  border-radius: 50%;\n}\n.ptr--busy .ptr__icon { animation: ptr-spin 0.7s linear infinite; }\n@keyframes ptr-spin { to { transform: rotate(360deg); } }\n.rich__render { display: inline-block; margin: 0 0 8px; }\n/* Выпадающий список языков — во всю ширину строки, со стрелкой системы. */\n.lang-select { width: 100%; appearance: auto; -webkit-appearance: menulist; cursor: pointer; }\n/* Сообщение, к которому перешли из поиска: короткая подсветка. */\n.is-focus { animation: focus-flash 2.4s ease-out; }\n@keyframes focus-flash {\n  0%, 35% { background: var(--tint-soft); box-shadow: inset 3px 0 0 var(--tint); }\n  100% { background: transparent; box-shadow: inset 3px 0 0 transparent; }\n}\n/* Колонки «бюллетени по часам». */\n.cols { margin: 0 16px 16px; }\n.cols__svg { display: block; width: 100%; height: 90px; }\n.cols__bar { fill: var(--tint); }\n.cols__axis { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-3); margin-top: 4px; }\n/* Иллюстрация со скриптом — в изолированной рамке. Высота — из viewBox. */\n.svg-sandbox { display: block; width: 100%; aspect-ratio: 4 / 3; border: 0; background: transparent; }\n/* Поиск по странице: строка под верхней панелью и нативная подсветка. */\n/* Размер значка — от шрифта кнопок темы, отступы — те же, что у соседних\n   кнопок: иначе в темах с кнопками-плашками лупа выходит крупнее. */\n.nav-bar__btn--icon { display: inline-flex; align-items: center; justify-content: center; }\n.nav-bar__icon { width: 1.25em; height: 1.25em; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }\n.find-bar {\n  position: fixed;\n  top: calc(var(--bar-h) + var(--safe-t));\n  left: 0;\n  right: 0;\n  z-index: 45;\n  display: flex;\n  align-items: center;\n  gap: 6px;\n  padding: 6px calc(10px + var(--safe-r)) 6px calc(10px + var(--safe-l));\n  background: var(--bar);\n  backdrop-filter: var(--bar-blur);\n  -webkit-backdrop-filter: var(--bar-blur);\n  box-shadow: 0 1px 0 var(--bar-line);\n}\n.find-bar__input {\n  flex: 1 1 auto;\n  min-width: 0;\n  padding: 7px 10px;\n  border: 1px solid var(--sep-strong);\n  border-radius: var(--radius);\n  background: var(--surface);\n  color: var(--text);\n  font: inherit;\n  font-size: 15px;\n}\n.find-bar__count { flex: none; font-size: 13px; color: var(--text-2); min-width: 3.5em; text-align: center; }\n.find-bar__btn {\n  flex: none;\n  min-width: 34px;\n  height: 34px;\n  border: 0;\n  border-radius: var(--radius);\n  background: none;\n  color: var(--tint);\n  font-size: 17px;\n  cursor: pointer;\n}\n.find-bar__btn:active { background: var(--surface-press); }\n/* Отступ под панель плюс высота строки поиска, а не вместо него. */\n.find-open .app__screen { padding-top: calc(var(--bar-h) + var(--safe-t) + 8px + 48px); }\n::highlight(find) { background-color: var(--tint-soft); color: inherit; }\n::highlight(find-current) { background-color: var(--tint); color: var(--on-tint); }\n/* Компьютеры: индикаторы загрузки и чипы состояния. */\n.meters {\n display: grid; gap: 14px; margin: 0 16px 18px; padding: 14px; background: var(--surface);\n  border: 0.5px solid var(--sep); border-radius: var(--radius); \n}\n.meter__head { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; margin-bottom: 6px; }\n.meter__label { color: var(--text); }\n.meter__value { color: var(--text-2); font-variant-numeric: tabular-nums; white-space: nowrap; }\n.meter__track { height: 8px; border-radius: 4px; background: var(--surface-press); overflow: hidden; }\n.meter__fill { height: 100%; background: var(--tint); transition: width var(--dur) ease; }\n.meter__fill--warn { background: var(--warn); }\n.cstate {\n display: inline-flex; align-items: center; gap: 6px; padding: 2px 9px; border-radius: var(--chip-r);\n  font-size: 12px; font-weight: 600; background: var(--surface-press); color: var(--text-2); \n}\n.cstate::before { content: \"\"; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }\n.cstate--run { color: var(--ok); }\n.cstate--busy { color: var(--warn); }\n.cstate--bad { color: var(--danger); }\n.cstate--off { opacity: 0.6; }\n.job__head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }\n.job__cmd {\n display: block; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px;\n  color: var(--text-2); white-space: pre-wrap; word-break: break-all; margin-top: 4px; \n}\n.pc-link { margin: 4px 0 10px; }\n/* Поле экрана «Поиск» закреплено под шапкой, как сама шапка. */\n.search--pinned {\n  position: fixed;\n  top: calc(var(--bar-h) + var(--safe-t));\n  left: 0;\n  right: 0;\n  z-index: 35;\n  padding: 8px calc(16px + var(--safe-r)) 10px calc(16px + var(--safe-l));\n  background: var(--bar);\n  backdrop-filter: var(--bar-blur);\n  -webkit-backdrop-filter: var(--bar-blur);\n  box-shadow: 0 1px 0 var(--bar-line);\n}\n.search--pinned .search__box { max-width: 688px; margin: 0 auto; }\n.search__spacer { height: 58px; }\n/* Экран скрыт — его закреплённое поле тоже. */\n.app__screen[hidden] .search--pinned { display: none; }\n/* Входящие: новое — точкой, причина — чипом. */\n.card__new { width: 8px; height: 8px; border-radius: 50%; background: var(--tint); flex: none; }\n.tag--reason { background: var(--tint-soft); border-color: transparent; color: var(--tint); }\n/* Заметка президента к слоту — под заголовком поста. */\n.slot__note { font-size: 13px; }\n/* Блок президента на главной: тема из палитры борды — полоса акцентом. */\n.homepage { border-left: 3px solid var(--tint); }\n.homepage--warn { border-left-color: var(--warn); }\n.homepage--muted { border-left-color: var(--text-3); }\n.homepage--ink { border-left-color: var(--text); }\n.homepage__heading { margin: 0 0 8px; font-size: 18px; }\n/* Справка: пояснения, собранные из-под экранов. */\n.help { margin: 0 16px 8px; }\n.help__text { margin: 0 0 10px; color: var(--text-2); font-size: 14px; line-height: 1.5; }\n/* Строка действий: перевод слева, «Поделиться» и закладка справа. */\n.trbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }\n.trbar__acts { display: inline-flex; gap: 4px; margin-left: auto; }\n.trbar__icon {\n  display: inline-flex;\n  align-items: center;\n  justify-content: center;\n  width: 32px;\n  height: 32px;\n  border: 0;\n  border-radius: 50%;\n  background: none;\n  color: var(--text-3);\n  cursor: pointer;\n}\n.trbar__icon:active { background: var(--surface-press); }\n.trbar__icon:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }\n.trbar__svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; }\n.trbar__icon--on { color: var(--tint-text); }\n.trbar__icon--on .trbar__svg { fill: currentColor; }\n.trbar--page { margin: -6px var(--row-px) 10px; }\n/* Закладки. */\n.bm { flex-direction: row; align-items: stretch; gap: 8px; }\n.bm__open { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 3px; border: 0; background: none; padding: 0; text-align: left; color: inherit; font: inherit; cursor: pointer; }\n.bm__head { display: flex; align-items: center; gap: 8px; }\n.bm__title { white-space: normal; overflow-wrap: anywhere; }\n.bm__del { flex: none; width: 36px; border: 0; background: none; color: var(--text-3); font-size: 22px; cursor: pointer; }\n.bm__del:focus-visible { outline: 2px solid var(--tint); outline-offset: -2px; }\n/* Свежесть документации в обозревателе API. */\n.doc__name { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 14px; }\n.doc__state { flex: none; padding: 1px 8px; border-radius: var(--chip-r); font-size: 12px; font-weight: 600; background: var(--surface-press); color: var(--text-2); }\n.doc__state--newer,\n.doc__state--changed,\n.doc__state--changed-local { color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent); }\n.doc__state--same,\n.doc__state--older,\n.doc__state--same-local { color: var(--ok); background: color-mix(in srgb, var(--ok) 12%, transparent); }\n.doc__state--error { color: var(--danger); background: color-mix(in srgb, var(--danger) 12%, transparent); }\n.group__note--warn { color: var(--warn); }\n/* Журнал действий: раскрываемые записи. */\n.log__row--open { cursor: pointer; }\n.log__row--open:focus-visible { outline: 2px solid var(--tint); outline-offset: -2px; }\n.log__row--expanded { background: var(--focus-bg); }\n.log__detail { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; padding-top: 8px; border-top: 0.5px dashed var(--sep); cursor: default; }\n.log__kv { display: grid; grid-template-columns: minmax(96px, 34%) minmax(0, 1fr); gap: 8px; font-size: 13px; line-height: 1.35; }\n.log__k { color: var(--text-3); }\n.log__v { color: var(--text); overflow-wrap: anywhere; }\na.log__v { color: var(--tint-text); }\n.log__kv--changed .log__v { color: var(--tint-text); font-weight: 600; }\n.log__sub { margin-top: 4px; font-size: 12px; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.04em; }\n.log__values { display: flex; flex-direction: column; gap: 3px; padding-left: 8px; border-left: 2px solid var(--sep); }\n.chips--wrap { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 var(--row-px) 12px; }\n.banner__sub { margin-top: 6px; font-size: 13px; color: var(--text-2); overflow-wrap: anywhere; }\n/* Свои источники: посты имиджборд и ленты. */\n.ext__rich { white-space: normal; overflow-wrap: anywhere; }\n.ext__green { color: #789922; }\n:root.theme--dark .ext__green { color: #a7c96a; }\n.ext__spoiler { background: var(--text); color: var(--text); border-radius: 2px; }\n.ext__spoiler:hover,\n.ext__spoiler:active { color: var(--surface); }\n.ext__strike { text-decoration: line-through; }\n.ext__under { text-decoration: underline; }\n.ext__over { text-decoration: overline; }\n.ext__ref { border: 0; background: none; padding: 0; color: var(--tint-text); font: inherit; cursor: pointer; text-decoration: underline dotted; }\n.ext__files { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0; }\n.ext__media { display: inline-block; max-width: 100%; }\n.ext__img { display: block; max-width: min(100%, 240px); max-height: 240px; border-radius: var(--radius); }\n.ext__video { display: block; max-width: 100%; max-height: 360px; border-radius: var(--radius); }\n.ext__show { border: 1px dashed var(--sep-strong); border-radius: var(--radius); background: none; color: var(--text-2); font: inherit; font-size: 13px; padding: 6px 10px; cursor: pointer; }\n.ext__thumb { display: block; max-width: 100%; max-height: 180px; border-radius: var(--radius); margin: 4px 0; }\n.ext__post--op { background: color-mix(in srgb, var(--tint) 6%, transparent); }\n.ext__subject { font-size: 16px; margin: 2px 0 4px; }\n/* Галерея: сетка превью и просмотр поста. */\n.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 4px; margin: 0 var(--row-px) 12px; }\n.gallery__tile { position: relative; aspect-ratio: 1; padding: 0; border: 0; border-radius: var(--radius); overflow: hidden; background: var(--surface-press); cursor: pointer; }\n.gallery__tile img { width: 100%; height: 100%; object-fit: cover; display: block; }\n.gallery__tile:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }\n.gallery__ph { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 12px; color: var(--text-3); }\n.gallery__badge { position: absolute; right: 4px; bottom: 4px; padding: 1px 6px; border-radius: 10px; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 11px; }\n.gallery__view { display: flex; justify-content: center; margin-bottom: 8px; }\n.gallery__full { display: block; max-width: 100%; max-height: 78vh; border-radius: var(--radius); }\n.ext__attempts { margin: 8px 0; padding-left: 18px; text-align: left; font-size: 13px; line-height: 1.45; color: var(--text-2); }\n.ext__attempts li { margin: 2px 0; overflow-wrap: anywhere; }\n.ext__broken { display: inline-block; padding: 6px 10px; border: 1px dashed var(--sep-strong); border-radius: var(--radius); font-size: 13px; color: var(--text-2); }\n/* Просмотр на весь экран. */\n:root.viewer-open,\n:root.viewer-open body { overflow: hidden; }\n.viewer { position: fixed; inset: 0; z-index: 1000; display: flex; flex-direction: column; background: rgba(0, 0, 0, 0.94); color: #fff; }\n.viewer__stage { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; min-height: 0; padding: env(safe-area-inset-top, 0px) 0 0; touch-action: pinch-zoom; }\n.viewer__media { max-width: 100%; max-height: 100%; object-fit: contain; }\n.viewer__bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 8px 10px calc(8px + env(safe-area-inset-bottom, 0px)); background: rgba(0, 0, 0, 0.6); }\n.viewer__count { margin-right: auto; font-size: 14px; opacity: 0.85; }\n.viewer__btn { border: 1px solid rgba(255, 255, 255, 0.35); border-radius: 18px; background: rgba(255, 255, 255, 0.08); color: #fff; font: inherit; font-size: 14px; padding: 6px 12px; cursor: pointer; }\n.viewer__btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }\n.viewer__nav { position: absolute; top: 45%; width: 44px; height: 64px; padding: 0; border: 0; border-radius: 10px; background: rgba(255, 255, 255, 0.12); font-size: 32px; }\n.viewer__nav--prev { left: 6px; }\n.viewer__nav--next { right: 6px; }\n.ext__open { position: relative; display: inline-block; padding: 0; border: 0; background: none; cursor: zoom-in; }\n.ext__vidph { display: inline-flex; align-items: center; justify-content: center; min-width: 120px; min-height: 80px; padding: 8px; border-radius: var(--radius); background: var(--surface-press); color: var(--text-2); font-size: 13px; }\n/* Каталог двача — плитками. */\n.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin: 0 var(--row-px) 12px; }\n.tile { position: relative; display: flex; flex-direction: column; overflow: hidden; padding: 0; border: 0.5px solid var(--sep); border-radius: var(--card-radius); background: var(--surface); color: inherit; text-align: left; font: inherit; cursor: pointer; }\n.tile__img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; background: var(--surface-press); }\n.tile__cap { display: flex; flex-direction: column; gap: 2px; padding: 6px 8px 8px; }\n.tile__title { font-size: 13px; font-weight: 600; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }\n.tile__meta { font-size: 11px; color: var(--text-3); }\n.tile:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }\n/* Архивач и ответы. */\n.arh__thumb { display: block; max-width: 100%; max-height: 200px; border-radius: var(--radius); margin: 2px 0 4px; }\n.ext__tags { margin: 4px 0 0; }\n.ext__replies { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline; margin-top: 6px; font-size: 13px; color: var(--text-3); }\n.ext__more { display: block; margin: 8px auto 12px; }\n/* Постинг: форма и эмодзи-капча. */\n.composer { position: fixed; inset: 0; z-index: 900; display: flex; align-items: flex-end; justify-content: center; background: rgba(0, 0, 0, 0.5); }\n.composer__panel { width: 100%; max-width: 640px; max-height: 92vh; overflow-y: auto; padding: 14px 0 calc(14px + env(safe-area-inset-bottom, 0px)); border-radius: var(--card-radius) var(--card-radius) 0 0; background: var(--bg); }\n.composer__title { margin: 0 var(--row-px) 10px; font-size: 17px; }\n.composer__text { display: block; box-sizing: border-box; width: calc(100% - 2 * var(--row-px)); margin: 10px var(--row-px); padding: 10px 12px; border: 1px solid var(--sep-strong); border-radius: var(--radius); background: var(--surface); color: var(--text); font: inherit; font-size: 16px; resize: vertical; }\n.composer__text--small { width: 100%; margin: 6px 0; font-size: 13px; font-family: ui-monospace, Menlo, Consolas, monospace; }\n.composer__files { display: block; margin: 0 var(--row-px) 10px; font-size: 14px; color: var(--text-2); }\n.composer__acts { display: flex; justify-content: flex-end; gap: 8px; margin: 10px var(--row-px) 0; }\n.captcha { margin: 6px var(--row-px); }\n.captcha__hint { margin: 0 0 6px; font-size: 13px; color: var(--text-2); }\n.captcha__task { display: block; max-width: 100%; margin: 0 0 8px; border-radius: var(--radius); }\n.captcha__keys { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }\n.captcha__keys--busy { opacity: 0.5; pointer-events: none; }\n.captcha__key { display: flex; align-items: center; justify-content: center; min-height: 52px; padding: 4px; border: 1px solid var(--sep-strong); border-radius: var(--radius); background: var(--surface); cursor: pointer; }\n.captcha__key img { max-width: 100%; max-height: 44px; }\n.captcha__key:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }\n.captcha__ok { margin: 4px 0; color: var(--ok); font-weight: 600; }\n.ext__post .card__seq { cursor: pointer; }\n.ext__reply { margin-left: auto; min-width: 32px; height: 28px; padding: 0 8px; border: 1px solid var(--sep-strong); border-radius: 14px; background: none; color: var(--tint-text); font: inherit; font-size: 15px; cursor: pointer; }\n.ext__reply:focus-visible { outline: 2px solid var(--tint); outline-offset: 1px; }\n\n/* ==========================================================================\n   Элементы интерфейса: модальное окно, лист действий, уведомление (варианты и\n   кнопка действия), индикатор загрузки, прогресс, бейдж, выноска, пересказ.\n   Внешний вид — через необязательные токены (значения по умолчанию ниже); темы\n   переопределяют их и добавляют свои приёмы.\n   ========================================================================== */\n:root {\n  --overlay: rgba(0, 0, 0, 0.45);\n  --modal-r: var(--card-radius);\n  --modal-bg: var(--bg-elev);\n  --modal-border: 0.5px solid var(--sep);\n  --modal-shadow: 0 18px 50px rgba(0, 0, 0, 0.35);\n  --modal-head-bg: transparent;\n  --modal-head-text: var(--text);\n  --modal-head-border: 0;\n  --modal-foot-border: 0;\n  --modal-title-font: inherit;\n  --modal-blur: none;\n  --toast-r: var(--radius-lg);\n  --callout-r: var(--radius);\n}\n\n/* Модальное окно: на телефоне — лист снизу (с «ручкой»), шире 768px — окно по\n   центру. Шапка без разделителя: заголовок, подзаголовок до двух строк, крестик. */\n.modal {\n  position: fixed;\n  inset: 0;\n  z-index: 950;\n  display: flex;\n  align-items: flex-end;\n  justify-content: center;\n  background: var(--overlay);\n  -webkit-backdrop-filter: var(--modal-blur);\n  backdrop-filter: var(--modal-blur);\n  animation: modal-fade 0.18s ease;\n}\n.modal__panel {\n  position: relative;\n  display: flex;\n  flex-direction: column;\n  width: 100%;\n  max-width: 640px;\n  max-height: 88vh;\n  overflow: hidden;\n  background: var(--modal-bg);\n  border: var(--modal-border);\n  border-bottom: 0;\n  border-radius: var(--modal-r) var(--modal-r) 0 0;\n  box-shadow: var(--modal-shadow);\n  padding-bottom: env(safe-area-inset-bottom, 0px);\n  animation: modal-up 0.22s ease;\n}\n/* «Ручка» листа — только на телефоне. */\n.modal__panel::before {\n  content: \"\";\n  position: absolute;\n  top: 6px;\n  left: 50%;\n  z-index: 1;\n  width: 36px;\n  height: 5px;\n  margin-left: -18px;\n  border-radius: 3px;\n  background: color-mix(in srgb, var(--modal-head-text) 26%, transparent);\n}\n.modal__head {\n  display: flex;\n  align-items: flex-start;\n  gap: 12px;\n  padding: 20px var(--row-px) 10px;\n  background: var(--modal-head-bg);\n  color: var(--modal-head-text);\n  border-bottom: var(--modal-head-border);\n}\n.modal__titles { flex: 1; min-width: 0; }\n.modal__title { margin: 0; font-family: var(--modal-title-font); font-size: 19px; font-weight: 700; line-height: 1.25; letter-spacing: -0.01em; }\n.modal__sub {\n  display: -webkit-box;\n  margin: 4px 0 0;\n  overflow: hidden;\n  font-size: 13px;\n  line-height: 1.35;\n  opacity: 0.66;\n  -webkit-line-clamp: 2;\n  -webkit-box-orient: vertical;\n}\n.modal__close {\n  flex: none;\n  width: 30px;\n  height: 30px;\n  margin-top: -2px;\n  padding: 0;\n  border: 0;\n  border-radius: 50%;\n  background: color-mix(in srgb, currentColor 10%, transparent);\n  color: inherit;\n  font: inherit;\n  font-size: 14px;\n  line-height: 1;\n  cursor: pointer;\n}\n.modal__close:focus-visible { outline: 2px solid var(--tint); outline-offset: 2px; }\n.modal__body { flex: 1 1 auto; overflow-y: auto; overscroll-behavior: contain; padding: 6px var(--row-px) 16px; color: var(--text); }\n.modal__foot { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px var(--row-px) 16px; border-top: var(--modal-foot-border); }\n/* На телефоне кнопки делят ширину поровну; шире — по содержимому, справа. */\n.modal__foot .btn { flex: 1 1 0; min-width: 0; }\n:root.modal-open, :root.modal-open body { overflow: hidden; }\n@keyframes modal-fade { from { opacity: 0; } }\n@keyframes modal-up { from { transform: translateY(24px); opacity: 0.6; } }\n@media (min-width: 768px) {\n  .modal { align-items: center; padding: 24px; }\n  .modal__panel { border-bottom: var(--modal-border); border-radius: var(--modal-r); max-height: 80vh; padding-bottom: 0; }\n  .modal__panel::before { display: none; }\n  .modal__head { padding-top: 18px; }\n  .modal__foot { justify-content: flex-end; }\n  .modal__foot .btn { flex: 0 0 auto; min-width: 112px; }\n}\n\n/* Лист действий — список кнопок в модальном окне. */\n.sheet { margin: -14px calc(-1 * var(--row-px)); }\n.sheet__item {\n  display: flex;\n  align-items: center;\n  gap: 12px;\n  width: 100%;\n  min-height: 50px;\n  padding: 0 var(--row-px);\n  border: 0;\n  border-bottom: 0.5px solid var(--sep);\n  background: none;\n  color: var(--tint-text);\n  font: inherit;\n  font-size: 17px;\n  text-align: left;\n  cursor: pointer;\n}\n.sheet__item:last-child { border-bottom: 0; }\n.sheet__item:active { background: var(--surface-press); }\n.sheet__item:focus-visible { outline: 2px solid var(--tint); outline-offset: -2px; }\n.sheet__item--danger { color: var(--danger); }\n\n/* Уведомление: варианты и кнопка действия. */\n.toast { display: flex; align-items: center; gap: 12px; border-radius: var(--toast-r); }\n.toast--ok { box-shadow: inset 4px 0 0 var(--ok), 0 6px 20px var(--shadow-soft); }\n.toast--warn { box-shadow: inset 4px 0 0 var(--warn), 0 6px 20px var(--shadow-soft); }\n.toast--error { box-shadow: inset 4px 0 0 var(--danger), 0 6px 20px var(--shadow-soft); }\n.toast__text { flex: 1; min-width: 0; }\n.toast__action { flex: none; padding: 4px 2px; border: 0; background: none; color: inherit; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }\n\n/* Кольцо загрузки (ring) — в строке и в окнах; не путать с прежним spinner из\n   двенадцати лепестков для загрузки экрана. И прогресс. */\n.ring {\n  display: inline-block;\n  width: 22px;\n  height: 22px;\n  border: 2.5px solid color-mix(in srgb, currentColor 22%, transparent);\n  border-top-color: var(--tint);\n  border-radius: 50%;\n  vertical-align: middle;\n  animation: ui-spin 0.8s linear infinite;\n}\n.ring--lg { width: 34px; height: 34px; border-width: 3px; }\n.progress { height: 4px; overflow: hidden; border-radius: 2px; background: color-mix(in srgb, var(--text) 12%, transparent); }\n.progress__bar { height: 100%; width: 0; background: var(--tint); transition: width 0.3s ease; }\n.progress--busy .progress__bar { width: 40%; animation: ui-slide 1.2s ease-in-out infinite; }\n@keyframes ui-spin { to { transform: rotate(360deg); } }\n@keyframes ui-slide { from { transform: translateX(-100%); } to { transform: translateX(250%); } }\n@media (prefers-reduced-motion: reduce) {\n  .ring { animation-duration: 2.4s; }\n  .progress--busy .progress__bar { animation-duration: 3s; }\n  .modal, .modal__panel { animation: none; }\n}\n\n/* Бейдж. */\n.badge { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 6px; border-radius: 9px; background: var(--tint); color: var(--on-tint); font-size: 11px; font-weight: 700; line-height: 1; vertical-align: middle; }\n.badge--muted { background: var(--surface-press); color: var(--text-2); }\n.badge--warn { background: var(--warn); color: var(--bg); }\n.badge--danger { background: var(--danger); color: var(--on-danger); }\n\n/* Выноска. */\n.callout {\n  --callout-c: var(--tint);\n  display: flex;\n  gap: 10px;\n  padding: 10px 12px;\n  border: 1px solid color-mix(in srgb, var(--callout-c) 38%, transparent);\n  border-radius: var(--callout-r);\n  background: color-mix(in srgb, var(--callout-c) 10%, var(--surface));\n  color: var(--text);\n  font-size: 14px;\n  line-height: 1.45;\n}\n.callout__icon { flex: none; color: var(--callout-c); font-weight: 700; }\n.callout--ok { --callout-c: var(--ok); }\n.callout--warn { --callout-c: var(--warn); }\n.callout--error { --callout-c: var(--danger); }\n\n/* Пересказ: тезисы ИИ в модальном окне. */\n.summary__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 10px; font-size: 13px; color: var(--text-3); }\n.summary__wait { display: flex; align-items: center; gap: 12px; padding: 18px 0; color: var(--text-2); }\n.summary .rich ul { margin: 0; padding-left: 20px; }\n.summary .rich li { margin: 0 0 8px; line-height: 1.45; }\n.trbar__btn--sum { margin-left: 4px; }\n\n/* ==========================================================================\n   Адаптивный слой: планшет и компьютер.\n   Всё здесь — только внутри медиазапросов (min-width: 768px и шире) или для\n   устройств с мышью (hover: hover и pointer: fine). На телефоне раскладка\n   прежняя, байт в байт.\n\n   Точки перелома:\n     768px  — планшет: колонка контента по центру, группы карточками,\n              нижняя панель компактно по центру, форма — модальным окном;\n     1024px — компьютер: нижняя панель становится боковой навигацией слева,\n              шапка и контент сдвигаются вправо;\n     1280px — широкий экран: колонка шире, страницы-витрины с модификатором\n              app__screen--grid раскладываются в две колонки.\n\n   Токены (тема может переопределить): --side-w — ширина боковой панели,\n   --content-w — колонка на планшете, --content-w-read — колонка лент и\n   тредов на компьютере, --content-w-wide — колонка витрин и галерей.\n\n   Обход: темы перекрашивают нижнюю панель правилами вида\n   :root[data-skin=\"x\"] .tab-bar { border-top: …; } — они специфичнее каркаса.\n   Чтобы тема не вернула панель вниз, геометрические свойства боковой панели\n   закреплены !important; цвета, фон, тени и оформление вкладок — за темой.\n   ========================================================================== */\n:root {\n  --side-w: 240px;\n  --content-w: 720px;\n  --content-w-wide: 960px;\n  --content-w-read: 760px;\n}\n\n@media (min-width: 768px) {\n  /* Группы — карточками по колонке: «вставные» группы, как в планшетных\n     настройках. Темы с собственным оформлением групп (правила .list с\n     :root[data-skin]) специфичнее и сохраняют своё; у плоских тем\n     --card-radius = 0, и группы остаются прямоугольными. */\n  :root {\n    --group-mx: 16px;\n    --group-r: var(--card-radius);\n  }\n  .list {\n    border-left: var(--group-border, 0.5px solid var(--sep));\n    border-right: var(--group-border, 0.5px solid var(--sep));\n    border-radius: var(--group-r);\n    overflow: hidden;\n  }\n  .app__screen { max-width: var(--content-w); }\n  .hero { padding-left: calc(var(--group-mx) + 4px); padding-right: calc(var(--group-mx) + 4px); }\n  .hero__title { font-size: calc(var(--hero-size) * 1.08); }\n\n  /* Нижняя панель на планшете — компактно по центру, без вкладок,\n     растянутых на всю ширину. */\n  .tab-bar { justify-content: center; }\n  .tab-bar__item { flex: 0 1 120px; }\n\n  /* Форма — модальным окном по центру, а не листом снизу. */\n  .composer { align-items: center; padding: 24px; }\n  .composer__panel { border-radius: var(--card-radius); max-height: 86vh; box-shadow: 0 18px 48px rgba(0, 0, 0, 0.35); }\n\n  /* Плитки и галерея — крупнее на широком экране. */\n  .tiles { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }\n  .gallery { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 6px; }\n}\n\n@media (min-width: 1024px) {\n  /* Боковая навигация слева вместо нижней панели. */\n  .tab-bar {\n    top: 0 !important;\n    bottom: 0 !important;\n    left: 0 !important;\n    right: auto !important;\n    width: calc(var(--side-w) + var(--safe-l)) !important;\n    height: auto !important;\n    flex-direction: column !important;\n    justify-content: flex-start !important;\n    align-items: stretch !important;\n    gap: 2px;\n    padding: calc(var(--safe-t) + 14px) 10px calc(var(--safe-b) + 14px) calc(10px + var(--safe-l)) !important;\n    border-top: 0 !important;\n    border-right: 0.5px solid var(--bar-line);\n    overflow-y: auto;\n  }\n  .tab-bar__item {\n    flex: 0 0 auto !important;\n    flex-direction: row !important;\n    justify-content: flex-start !important;\n    gap: 12px !important;\n    min-height: 44px;\n    padding: 8px 12px !important;\n    border-radius: var(--radius-lg);\n    text-align: left;\n  }\n  .tab-bar__icon { width: 22px; height: 22px; flex: none; }\n  .tab-bar__label { font-size: 15px; line-height: 1.2; letter-spacing: 0; }\n  /* Выбранная вкладка в боковой панели — подложка акцента, если тема не\n     задала свою (у тем с подсветкой вкладок их правило специфичнее). */\n  .tab-bar__item--active { background: var(--tint-soft); }\n\n  /* Значок непрочитанного — у правого края вкладки, как счётчик в почте. */\n  .tab-bar__badge { position: static !important; margin-left: auto !important; transform: none !important; }\n\n  /* Колонка для чтения: лент и тредов — 760px. */\n  .app__screen { max-width: var(--content-w-read); }\n\n  /* Шапка и контент — правее боковой панели. */\n  .nav-bar { left: calc(var(--side-w) + var(--safe-l)); }\n  .app__views { margin-left: calc(var(--side-w) + var(--safe-l)); }\n  .app__screen { padding-bottom: calc(var(--safe-b) + 40px); }\n  .toast { left: calc(50% + (var(--side-w) + var(--safe-l)) / 2); bottom: calc(var(--safe-b) + 28px); }\n}\n\n@media (min-width: 1280px) {\n  /* Широкая колонка — только экранам из групп (app__screen--grid) и экранам с\n     галереей или плитками; ленты и треды остаются в колонке для чтения. */\n  .app__screen:has(> .gallery, > .tiles, > div > .gallery, > div > .tiles) { max-width: var(--content-w-wide); }\n  /* Страница-витрина в две колонки — по выбору: модификатор\n     app__screen--grid (ленты и треды остаются одной колонкой). */\n  .app__screen--grid { columns: 2; column-gap: 8px; max-width: calc(var(--content-w-wide) + 240px); }\n  .app__screen--grid > .hero,\n  .app__screen--grid > .state,\n  .app__screen--grid > .banner,\n  .app__screen--grid > .app__wide { column-span: all; }\n  .app__screen--grid > .group { break-inside: avoid; }\n}\n\n/* Мышь: подсветка при наведении — на телефоне её нет (там :active). Темы со\n   своим наведением (их правила специфичнее) сохраняют своё. */\n@media (hover: hover) and (pointer: fine) {\n  .list__cell--link:hover,\n  .card:hover { background: color-mix(in srgb, var(--surface) 92%, var(--text) 8%); }\n  .tab-bar__item:hover { color: var(--text); }\n  .tab-bar__item:not(.tab-bar__item--active):hover { background: color-mix(in srgb, var(--text) 6%, transparent); }\n  .btn:hover:not(:disabled),\n  .nav-bar__btn:hover,\n  .hot__chip:hover { filter: brightness(1.06); }\n  .segmented__item:not(.segmented__item--active):hover { background: color-mix(in srgb, var(--text) 6%, transparent); }\n  .list__cell--link, .card, .btn, .tab-bar__item, .segmented__item, .hot__chip { cursor: pointer; }\n}\n","themes":{"patch":{"meta":{"id":"patch","name":"Patch","mode":"dark","accent":"#67c1f5","accentLight":"#1a75b8","accentDark":"#67c1f5","companion":"#a4d007","companionLight":"#5c7e10","companionDark":"#a4d007","barsLight":["#dce2e8",1.0,"#e9eef2"],"barsDark":["#202020",1.0,"#2e2e2e"]},"css":"/*!theme\nid: patch\nname: Patch\nmode: dark\naccent: #67c1f5\naccentLight: #1a75b8\naccentDark: #67c1f5\ncompanion: #a4d007\ncompanionLight: #5c7e10\ncompanionDark: #a4d007\nbarsLight: #dce2e8, 1, #e9eef2\nbarsDark: #202020, 1, #2e2e2e\ndescription: Технический интерфейс списков изменений клиента Steam (clientNews) — угольная шапка #202020, графитовый фон #2E2E2E, приглушённая серая мета-информация #999797, сворачиваемые плашки патчноутов с индикаторами [-]/[+] и полупрозрачные голубые кнопки .btnv6_blue_hoverfade с градиентным наведением.\n*/\n\n/* ==========================================================================\nPatch — тема в эстетике окна чейнджлогов клиента Steam (Steam Client Beta):\n\nИмя и ID: Patch (patch) — не пересекается с 24 темами каталога;\n\nОсновной режим: dark (базируется на :root референса: шапка #202020,\nфон рабочей области #2e2e2e, иконки и даты #999797);\n\nСветлый режим: документация и окна Steam Support (#e9eef2 / #dce2e8 / #ffffff);\n\nАкцент: фирменный голубой Steam #67c1f5 (в светлом режиме — #1a75b8)\nи лаймово-зелёный компаньон #a4d007;\n\nОсобые приёмы: кнопки .btnv6_blue_hoverfade (полупрозрачная подложка в покое\nи градиент при наведении), плашки записей в духе .post_header / .post_content,\nтехнические теги в стиле индикатора [-]/[+] (.post_pmbutton) и тонкий скроллбар.\n========================================================================== */\n\n/* --------------------------------------------------------- Токены: Тёмный --- */\n:root[data-skin=\"patch\"] {\n/* 1. Типографика */\n--font: \"Motiva Sans\", Arial, Tahoma, Helvetica, sans-serif;\n--hero-size: 20px;\n--hero-weight: 700;\n--hero-tracking: 0.04em;\n--nav-grid: auto minmax(0, 1fr) auto;\n--nav-align: left;\n--nav-weight: 700;\n\n/* 2. Геометрия и радиусы */\n--radius: 2px;\n--radius-lg: 4px;\n--card-radius: 3px;\n--chip-r: 2px;\n--bar-h: 46px;\n--tab-h: 50px;\n--bar-blur: none;\n\n/* 3. Переключатель (строгий прямоугольный тумблер VGUI) */\n--sw-w: 40px;\n--sw-h: 20px;\n--sw-r: 2px;\n--sw-thumb: 14px;\n--sw-thumb-r: 1px;\n--sw-pad: 3px;\n--sw-off: #202020;\n--sw-on: var(--tint);\n--sw-shadow: 0 1px 2px rgba(0, 0, 0, 0.65);\n\n/* 4. Сегменты */\n--seg-r: 2px;\n--seg-line: #3d3d3d;\n--seg-text: #999797;\n--seg-on: color-mix(in srgb, var(--tint) 24%, #202020);\n--seg-on-text: #ffffff;\n\n/* 5. Палитра и поверхности (из :root референса) */\n--bg: #2e2e2e;\n--bg-elev: #383838;\n--surface: #252525;\n--surface-press: #3d3d3d;\n--sep: #333333;\n--sep-strong: #454545;\n--text: #e6e6e6;\n--text-2: #999797;\n--text-3: #6e6d6d;\n--bar: #202020;\n--bar-line: #3a3a3a;\n--danger: #ff453a;\n--on-danger: #ffffff;\n--ok: #a4d007;\n--thumb: #e6e6e6;\n--toast-bg: rgba(32, 32, 32, 0.96);\n--toast-text: #ffffff;\n--shadow-soft: rgba(0, 0, 0, 0.48);\n\n/* 6. Необязательные токены каркаса */\n--group-r: 3px;\n--group-border: 1px solid var(--sep-strong);\n--group-shadow: none;\n--field-bg: #1e1e1e;\n--field-line: #3d3d3d;\n--field-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.45);\n--focus-bg: color-mix(in srgb, var(--tint) 12%, transparent);\n--tint-text: color-mix(in srgb, var(--tint) 82%, #ffffff);\n--seg-pad: 2px;\n--seg-item-r: 2px;\n--modal-r: 4px;\n--overlay: rgba(0, 0, 0, 0.68);\n\n/* Полоса в духе референса: прямоугольный бегунок на сплошной дорожке */\n--scroll-w: 10px;\n--scroll-gap: 0px;\n--scroll-r: 0px;\n--scroll-track: var(--bg);\n--scroll-thumb: var(--text-3);\n--scroll-thumb-hover: var(--text-2);\n--scroll-thumb-active: var(--tint);\n\ncolor-scheme: dark;\n}\n\n/* -------------------------------------------------------- Токены: Светлый --- */\n:root[data-skin=\"patch\"]:not(.theme--dark) {\n--bg: #e9eef2;\n--bg-elev: #ffffff;\n--surface: #ffffff;\n--surface-press: #dce2e8;\n--sep: #e1e6eb;\n--sep-strong: #b8c2cc;\n--text: #1f2328;\n--text-2: #57606a;\n--text-3: #8c959f;\n--bar: #dce2e8;\n--bar-line: #bfc8d1;\n--danger: #d1242f;\n--on-danger: #ffffff;\n--ok: #4b6b09;\n--thumb: #ffffff;\n--sw-off: #b8c2cc;\n--sw-on: var(--tint);\n--sw-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);\n--seg-line: #b8c2cc;\n--seg-text: #57606a;\n--seg-on: var(--tint);\n--seg-on-text: var(--on-tint);\n--field-bg: #f6f8fa;\n--field-line: #b8c2cc;\n--field-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);\n--focus-bg: color-mix(in srgb, var(--tint) 10%, transparent);\n--tint-text: color-mix(in srgb, var(--tint) 68%, #000000);\n--toast-bg: rgba(31, 35, 40, 0.94);\n--toast-text: #ffffff;\n--shadow-soft: rgba(27, 31, 36, 0.14);\n\ncolor-scheme: light;\n}\n\n/* ==========================================================================\nПравила оформления темы Patch\n========================================================================== */\n\n/* Фон рабочей области (.clientNews) с лёгким техническим градиентом у шапки */\n:root[data-skin=\"patch\"] body::before {\nbackground: linear-gradient(180deg, #262626 0%, var(--bg) 140px);\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) body::before {\nbackground: linear-gradient(180deg, #e1e7ec 0%, var(--bg) 140px);\n}\n\n/* --------------------------------------------------------------------------\nШапка (.header, .extImg) и заголовок экрана (h1, h2)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .nav-bar {\nbackground: var(--bar);\nborder-bottom: 1px solid var(--bar-line);\nbox-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .nav-bar {\nbox-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);\n}\n\n:root[data-skin=\"patch\"] .nav-bar__title {\ntext-transform: uppercase;\nletter-spacing: 0.04em;\ncolor: var(--text);\n}\n\n/* Кнопки шапки по мотивам .extImg («Открыть в браузере») */\n:root[data-skin=\"patch\"] .nav-bar__btn {\nborder: 1px solid transparent;\nborder-radius: var(--radius);\nbackground: transparent;\ncolor: var(--text-2);\nfont-weight: 600;\ntransition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease;\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .nav-bar__btn:hover {\nbackground: color-mix(in srgb, var(--tint) 18%, transparent);\nborder-color: color-mix(in srgb, var(--tint) 35%, transparent);\ncolor: var(--text);\n}\n}\n\n:root[data-skin=\"patch\"] .nav-bar__btn:active,\n:root[data-skin=\"patch\"] .nav-bar__btn--back:active {\nbackground: var(--surface-press);\ncolor: var(--tint-text);\n}\n\n/* Заголовок экрана: НОВОСТИ — БЕТА КЛИЕНТА STEAM */\n:root[data-skin=\"patch\"] .hero__title {\ntext-transform: uppercase;\ncolor: #ffffff;\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .hero__title {\ncolor: var(--text);\n}\n\n:root[data-skin=\"patch\"] .hero__sub {\ncolor: var(--text-2);\nfont-weight: 400;\n}\n\n/* --------------------------------------------------------------------------\nКнопки в стиле Steam .btnv6_blue_hoverfade\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .btn,\n:root[data-skin=\"patch\"] .trbar__btn {\nborder: 1px solid color-mix(in srgb, var(--tint) 28%, transparent);\nborder-radius: var(--radius);\nbackground: color-mix(in srgb, var(--tint) 18%, transparent);\ncolor: var(--tint-text);\nfont-weight: 600;\ntransition: background 0.14s ease, color 0.14s ease, border-color 0.14s ease, box-shadow 0.14s ease;\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .btn:hover,\n:root[data-skin=\"patch\"] .trbar__btn:hover {\nborder-color: var(--tint);\nbackground: linear-gradient(\n135deg,\ncolor-mix(in srgb, var(--tint) 82%, #ffffff) 0%,\ncolor-mix(in srgb, var(--tint) 76%, #000000) 100%\n);\ncolor: var(--on-tint);\nbox-shadow: 0 1px 4px var(--shadow-soft);\n}\n}\n\n:root[data-skin=\"patch\"] .btn:active,\n:root[data-skin=\"patch\"] .trbar__btn:active {\nborder-color: var(--tint-press);\nbackground: var(--tint-press);\ncolor: var(--on-tint);\nbox-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.4);\n}\n\n/* Главная кнопка (.btn--filled) */\n:root[data-skin=\"patch\"] .btn--filled {\nborder: 1px solid color-mix(in srgb, var(--tint) 85%, #ffffff);\nbackground: linear-gradient(\n135deg,\nvar(--tint) 0%,\ncolor-mix(in srgb, var(--tint) 75%, #000000) 100%\n);\ncolor: var(--on-tint);\nbox-shadow: 0 1px 3px var(--shadow-soft);\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .btn--filled:hover {\nborder-color: #ffffff;\nbackground: linear-gradient(\n135deg,\ncolor-mix(in srgb, var(--tint) 86%, #ffffff) 0%,\nvar(--tint) 100%\n);\ncolor: var(--on-tint);\n}\n}\n\n:root[data-skin=\"patch\"] .btn--filled:active {\nbackground: var(--tint-press);\ncolor: var(--on-tint);\nbox-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.45);\n}\n\n/* Опасная кнопка (.btn--danger) */\n:root[data-skin=\"patch\"] .btn--danger {\nborder: 1px solid color-mix(in srgb, var(--danger) 38%, transparent);\nbackground: color-mix(in srgb, var(--danger) 18%, transparent);\ncolor: var(--danger);\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .btn--danger:hover {\nborder-color: var(--danger);\nbackground: var(--danger);\ncolor: var(--on-danger);\n}\n}\n\n:root[data-skin=\"patch\"] .btn--danger:active {\nbackground: color-mix(in srgb, var(--danger) 80%, #000000);\ncolor: var(--on-danger);\n}\n\n/* Прозрачная кнопка (.btn--ghost) */\n:root[data-skin=\"patch\"] .btn--ghost {\nborder: 1px solid var(--sep-strong);\nbackground: transparent;\ncolor: var(--text-2);\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .btn--ghost:hover {\nborder-color: var(--text-2);\nbackground: var(--bg-elev);\ncolor: var(--text);\n}\n}\n\n/* Недоступная кнопка */\n:root[data-skin=\"patch\"] .btn:disabled {\nborder-color: var(--sep);\nbackground: color-mix(in srgb, var(--text-3) 14%, transparent);\ncolor: var(--text-3);\nbox-shadow: none;\nopacity: 0.6;\ncursor: default;\n}\n\n/* Клавиатурный фокус */\n:root[data-skin=\"patch\"] .btn:focus-visible,\n:root[data-skin=\"patch\"] .trbar__btn:focus-visible,\n:root[data-skin=\"patch\"] .nav-bar__btn:focus-visible,\n:root[data-skin=\"patch\"] .tab-bar__item:focus-visible,\n:root[data-skin=\"patch\"] .segmented__item:focus-visible,\n:root[data-skin=\"patch\"] .card:focus-visible,\n:root[data-skin=\"patch\"] .list__cell--link:focus-visible,\n:root[data-skin=\"patch\"] .hot__chip:focus-visible {\noutline: 2px solid var(--tint);\noutline-offset: 1px;\n}\n\n/* --------------------------------------------------------------------------\nСписки, группы и строки (.group, .list, .list__cell)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .group__title {\ncolor: var(--tint-text);\nfont-weight: 700;\ntext-transform: uppercase;\nletter-spacing: 0.04em;\n}\n\n:root[data-skin=\"patch\"] .group__note {\ncolor: var(--text-2);\n}\n\n:root[data-skin=\"patch\"] .list {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--card-radius);\nbackground: var(--surface);\n}\n\n:root[data-skin=\"patch\"] .list__cell + .list__cell {\nborder-top: 1px solid var(--sep);\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .list__cell--link:hover {\nbackground: var(--bg-elev);\n}\n}\n\n:root[data-skin=\"patch\"] .list__cell--link:active {\nbackground: var(--surface-press);\n}\n\n:root[data-skin=\"patch\"] .list__cell--link:active .list__chevron {\ncolor: var(--tint-text);\n}\n\n/* --------------------------------------------------------------------------\nКарточки, посты и строки логов в стиле плашек (.post_header, .post_content)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .card,\n:root[data-skin=\"patch\"] .post,\n:root[data-skin=\"patch\"] .article,\n:root[data-skin=\"patch\"] .reply,\n:root[data-skin=\"patch\"] .log__row {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--card-radius);\nbackground: var(--surface);\nbox-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .card,\n:root[data-skin=\"patch\"]:not(.theme--dark) .post,\n:root[data-skin=\"patch\"]:not(.theme--dark) .article,\n:root[data-skin=\"patch\"]:not(.theme--dark) .reply,\n:root[data-skin=\"patch\"]:not(.theme--dark) .log__row {\nbox-shadow: 0 1px 2px var(--shadow-soft);\n}\n\n/* Шапка карточки с отсылкой к плашке .post_header */\n:root[data-skin=\"patch\"] .card__head,\n:root[data-skin=\"patch\"] .log__head {\nbackground: color-mix(in srgb, var(--bg-elev) 55%, transparent);\nborder-bottom: 1px solid var(--sep);\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .card:hover {\nborder-color: color-mix(in srgb, var(--tint) 55%, var(--sep-strong));\nbackground: color-mix(in srgb, var(--bg-elev) 75%, var(--surface));\n}\n}\n\n:root[data-skin=\"patch\"] .card:active,\n:root[data-skin=\"patch\"] .reply:active {\nborder-color: var(--tint);\nbackground: var(--surface-press);\n}\n\n:root[data-skin=\"patch\"] .card--pinned {\nborder-left: 3px solid var(--tint);\n}\n\n/* Заголовки записей (.post_title) и даты (.post_date) */\n:root[data-skin=\"patch\"] .card__title,\n:root[data-skin=\"patch\"] .post__title,\n:root[data-skin=\"patch\"] .article__title {\ncolor: #ffffff;\nfont-weight: 700;\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .card__title,\n:root[data-skin=\"patch\"]:not(.theme--dark) .post__title,\n:root[data-skin=\"patch\"]:not(.theme--dark) .article__title {\ncolor: var(--text);\n}\n\n:root[data-skin=\"patch\"] .card__author,\n:root[data-skin=\"patch\"] .reply__author {\ncolor: var(--tint-text);\nfont-weight: 600;\n}\n\n:root[data-skin=\"patch\"] .card__time,\n:root[data-skin=\"patch\"] .post__meta,\n:root[data-skin=\"patch\"] .reply__time,\n:root[data-skin=\"patch\"] .card__foot {\ncolor: var(--text-2);\n}\n\n/* Технические теги и номера в стиле кнопки сворачивания [-]/[+] (.post_pmbutton) */\n:root[data-skin=\"patch\"] .tag,\n:root[data-skin=\"patch\"] .card__seq,\n:root[data-skin=\"patch\"] .key {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--chip-r);\nbackground: var(--bar);\ncolor: var(--text-2);\nfont-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;\nfont-weight: 600;\n}\n\n:root[data-skin=\"patch\"] .tag--warn {\nborder-color: color-mix(in srgb, var(--warn) 45%, transparent);\nbackground: color-mix(in srgb, var(--warn) 16%, var(--bar));\ncolor: var(--warn);\n}\n\n/* Чипы тем (.hot__chip) */\n:root[data-skin=\"patch\"] .hot__chip {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--chip-r);\nbackground: var(--bg-elev);\ncolor: var(--text);\nfont-weight: 600;\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .hot__chip:hover {\nborder-color: var(--tint);\ncolor: var(--tint-text);\n}\n}\n\n:root[data-skin=\"patch\"] .hot__chip--live {\nborder-color: var(--ok);\nbackground: color-mix(in srgb, var(--ok) 18%, var(--bar));\ncolor: var(--ok);\n}\n\n/* --------------------------------------------------------------------------\nТипографика чейнджлога (.rich, b, ul.bb_ul, code)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .rich strong,\n:root[data-skin=\"patch\"] .rich b,\n:root[data-skin=\"patch\"] .rich h2,\n:root[data-skin=\"patch\"] .rich h3 {\ncolor: #ffffff;\nfont-weight: 700;\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .rich strong,\n:root[data-skin=\"patch\"]:not(.theme--dark) .rich b,\n:root[data-skin=\"patch\"]:not(.theme--dark) .rich h2,\n:root[data-skin=\"patch\"]:not(.theme--dark) .rich h3 {\ncolor: var(--text);\n}\n\n:root[data-skin=\"patch\"] .rich ul {\nlist-style-type: square;\n}\n\n:root[data-skin=\"patch\"] .rich li::marker {\ncolor: var(--text-2);\n}\n\n:root[data-skin=\"patch\"] .rich a {\ncolor: var(--tint-text);\ntext-decoration: none;\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .rich a:hover {\ncolor: var(--tint);\ntext-decoration: underline;\n}\n}\n\n:root[data-skin=\"patch\"] .rich code,\n:root[data-skin=\"patch\"] .rich pre {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--radius);\nbackground: var(--bar);\ncolor: var(--text);\n}\n\n:root[data-skin=\"patch\"] .rich blockquote {\nborder-left: 3px solid var(--tint);\nbackground: color-mix(in srgb, var(--bg-elev) 50%, transparent);\ncolor: var(--text-2);\n}\n\n/* --------------------------------------------------------------------------\nПереключатели, сегменты, индикаторы (.switch, .segmented, .meter, .chart)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .switch__track {\nborder: 1px solid var(--sep-strong);\nbackground: var(--sw-off);\nbox-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.45);\n}\n\n:root[data-skin=\"patch\"] .switch__thumb {\nbackground: var(--thumb);\nbox-shadow: var(--sw-shadow);\n}\n\n:root[data-skin=\"patch\"] .switch__input:checked ~ .switch__track {\nborder-color: var(--tint);\nbackground: color-mix(in srgb, var(--tint) 75%, #000000);\n}\n\n:root[data-skin=\"patch\"] .switch__input:checked ~ .switch__thumb {\nbackground: #ffffff;\n}\n\n:root[data-skin=\"patch\"] .segmented {\nborder: 1px solid var(--seg-line);\nborder-radius: var(--seg-r);\nbackground: var(--bar);\n}\n\n:root[data-skin=\"patch\"] .segmented__item {\ncolor: var(--seg-text);\nfont-weight: 600;\n}\n\n:root[data-skin=\"patch\"] .segmented__item--active {\nborder-radius: var(--seg-item-r);\nbackground: var(--seg-on);\ncolor: var(--seg-on-text);\nbox-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tint) 55%, transparent);\n}\n\n:root[data-skin=\"patch\"] .meter__track,\n:root[data-skin=\"patch\"] .chart__track {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--radius);\nbackground: var(--bar);\nbox-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.4);\n}\n\n:root[data-skin=\"patch\"] .meter__fill,\n:root[data-skin=\"patch\"] .chart__bar {\nborder-radius: 1px;\nbackground: linear-gradient(\n90deg,\ncolor-mix(in srgb, var(--tint) 75%, #000000) 0%,\nvar(--tint) 100%\n);\n}\n\n:root[data-skin=\"patch\"] .meter__fill--warn {\nbackground: var(--warn);\n}\n\n/* --------------------------------------------------------------------------\nБаннеры, модальные окна и всплывающие уведомления (.banner, .modal, .toast)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .banner {\nborder: 1px solid var(--sep-strong);\nborder-left: 3px solid var(--tint);\nborder-radius: var(--card-radius);\nbackground: var(--bg-elev);\ncolor: var(--text);\n}\n\n:root[data-skin=\"patch\"] .banner--warn {\nborder-color: color-mix(in srgb, var(--warn) 45%, var(--sep-strong));\nborder-left: 3px solid var(--warn);\nbackground: color-mix(in srgb, var(--warn) 12%, var(--surface));\n}\n\n:root[data-skin=\"patch\"] .banner--danger {\nborder-color: color-mix(in srgb, var(--danger) 45%, var(--sep-strong));\nborder-left: 3px solid var(--danger);\nbackground: color-mix(in srgb, var(--danger) 12%, var(--surface));\n}\n\n:root[data-skin=\"patch\"] .modal__panel {\nborder: 1px solid var(--sep-strong);\nborder-radius: var(--radius-lg);\nbackground: var(--bg);\nbox-shadow: 0 12px 32px rgba(0, 0, 0, 0.65);\n}\n\n:root[data-skin=\"patch\"] .modal__head {\nbackground: var(--bar);\nborder-bottom: 1px solid var(--bar-line);\n}\n\n:root[data-skin=\"patch\"] .modal__title {\ntext-transform: uppercase;\nletter-spacing: 0.03em;\nfont-weight: 700;\n}\n\n:root[data-skin=\"patch\"] .toast {\nborder: 1px solid var(--sep-strong);\nborder-left: 3px solid var(--tint);\nborder-radius: var(--radius);\nbackground: var(--toast-bg);\ncolor: var(--toast-text);\nbox-shadow: 0 4px 14px var(--shadow-soft);\n}\n\n/* --------------------------------------------------------------------------\nНижняя панель и боковая навигация (.tab-bar)\n-------------------------------------------------------------------------- */\n:root[data-skin=\"patch\"] .tab-bar {\nbackground: var(--bar);\nborder-top: 1px solid var(--bar-line);\n}\n\n:root[data-skin=\"patch\"] .tab-bar__item {\ncolor: var(--text-2);\nfont-weight: 600;\n}\n\n:root[data-skin=\"patch\"] .tab-bar__item--active {\nbackground: color-mix(in srgb, var(--tint) 16%, transparent);\nbox-shadow: inset 0 2px 0 var(--tint);\ncolor: #ffffff;\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .tab-bar__item--active {\ncolor: var(--tint-text);\n}\n\n:root[data-skin=\"patch\"] .tab-bar__item--active .tab-bar__icon {\ncolor: var(--tint);\n}\n\n:root[data-skin=\"patch\"] .tab-bar__badge {\nborder-radius: var(--radius);\nbackground: var(--ok);\ncolor: #101010;\nfont-weight: 700;\n}\n\n/* Адаптивность для ПК (боковая панель клиента Steam от 1024px) */\n@media (min-width: 1024px) {\n:root[data-skin=\"patch\"] .tab-bar {\nbackground: var(--bar);\nborder-right: 1px solid var(--bar-line);\n}\n\n:root[data-skin=\"patch\"] .tab-bar__item {\nborder-radius: var(--radius);\nbox-shadow: none;\ntransition: background 0.12s ease, color 0.12s ease;\n}\n\n@media (hover: hover) and (pointer: fine) {\n:root[data-skin=\"patch\"] .tab-bar__item:not(.tab-bar__item--active):hover {\nbackground: color-mix(in srgb, var(--tint) 12%, transparent);\ncolor: var(--text);\n}\n}\n\n:root[data-skin=\"patch\"] .tab-bar__item--active {\nbackground: linear-gradient(\n90deg,\ncolor-mix(in srgb, var(--tint) 24%, transparent) 0%,\ncolor-mix(in srgb, var(--tint) 8%, transparent) 100%\n);\nbox-shadow: inset 3px 0 0 var(--tint);\ncolor: #ffffff;\n}\n\n:root[data-skin=\"patch\"]:not(.theme--dark) .tab-bar__item--active {\ncolor: var(--tint-text);\n}\n}\n"}}}

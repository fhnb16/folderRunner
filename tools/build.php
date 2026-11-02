<?php
/**
 * Сборщик тем folderRunner.
 *
 * Берёт каркас и темы из каталога fhnb16/theme-catalog и записывает их блоком
 * данных в конец index.php (после __halt_compiler). Код приложения выше блока
 * не трогает.
 *
 *   php tools/build.php --catalog <папка каталога | единый md каталога>
 *                       [--local themes] [--target index.php]
 *                       [--dist dist] [--rev <sha>] [--check]
 *
 * --catalog  папка с base.css и themes/*.css или единый md каталога
 *            (разделы «### base.css» и «### themes/<id>.css» из «Исходников»);
 * --local    папка своих тем: *.css дописывают каталог или заменяют тему
 *            с тем же id (по умолчанию themes/ рядом с index.php);
 * --dist     дополнительно собрать <dist>/<id>/index.php — по файлу на тему,
 *            тема в нём закреплена;
 * --rev      ревизия каталога, попадает в блок данных;
 * --check    ничего не писать, выйти с кодом 1, если index.php устарел.
 */

declare(strict_types=1);

const HALT = "\n__halt_compiler();";

/** Обязательные токены основного блока темы — как в tools/build-index.py каталога. */
const REQUIRED_TOKENS = [
    'font', 'radius', 'radius-lg', 'card-radius', 'bar-blur', 'bar-h', 'tab-h', 'hero-size', 'hero-weight',
    'hero-tracking', 'nav-grid', 'nav-align', 'nav-weight', 'sw-w', 'sw-h', 'sw-r', 'sw-thumb', 'sw-thumb-r',
    'sw-pad', 'sw-off', 'sw-on', 'sw-shadow', 'seg-r', 'seg-line', 'seg-text', 'seg-on', 'seg-on-text', 'chip-r',
    'bg', 'bg-elev', 'surface', 'surface-press', 'sep', 'sep-strong', 'text', 'text-2', 'text-3', 'bar',
    'bar-line', 'danger', 'ok', 'thumb', 'on-danger', 'toast-bg', 'toast-text', 'shadow-soft',
];

/** Ключи паспорта, которые нужны приложению (описание — для конфигуратора). */
const PASSPORT_KEYS = [
    'id', 'name', 'mode', 'accent', 'accentLight', 'accentDark', 'companion', 'companionLight',
    'companionDark', 'barsLight', 'barsDark', 'mono', 'description',
];

$root = dirname(__DIR__);
$opts = getopt('', ['catalog:', 'local:', 'target:', 'dist:', 'rev:', 'check', 'help']);

if (isset($opts['help']) || !isset($opts['catalog'])) {
    fwrite(STDERR, "usage: php tools/build.php --catalog <dir|file.md> [--local themes] [--target index.php] [--dist dist] [--rev sha] [--check]\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$target = $opts['target'] ?? $root . '/index.php';
$localDir = $opts['local'] ?? $root . '/themes';
$check = isset($opts['check']);

function warn(string $msg): void
{
    fwrite(STDERR, (getenv('GITHUB_ACTIONS') ? '::warning::' : 'warning: ') . $msg . "\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, (getenv('GITHUB_ACTIONS') ? '::error::' : 'error: ') . $msg . "\n");
    exit(1);
}

function normalize(string $text): string
{
    return rtrim(str_replace("\r\n", "\n", $text)) . "\n";
}

/**
 * @return array{base: ?string, themes: array<string, string>}
 */
function loadCatalog(string $path): array
{
    $out = ['base' => null, 'themes' => []];

    if (is_dir($path)) {
        if (is_file("$path/base.css")) {
            $out['base'] = normalize(file_get_contents("$path/base.css"));
        }
        foreach (glob("$path/themes/*.css") ?: [] as $file) {
            $out['themes'][basename($file, '.css')] = normalize(file_get_contents($file));
        }
        return $out;
    }

    if (!is_file($path)) {
        fail("каталог не найден: $path");
    }

    $md = str_replace("\r\n", "\n", file_get_contents($path));
    preg_match_all('/^### (base\.css|themes\/([a-z0-9-]+)\.css)\s*\n+```css\n(.*?)\n```\s*$/ms', $md, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $block) {
        if ($block[1] === 'base.css') {
            $out['base'] = normalize($block[3]);
        } else {
            $out['themes'][$block[2]] = normalize($block[3]);
        }
    }
    return $out;
}

function parsePassport(string $css): array
{
    if (!preg_match('/\/\*!theme(.*?)\*\//s', $css, $m)) {
        return [];
    }
    $meta = [];
    foreach (explode("\n", trim($m[1])) as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $meta[trim($key)] = trim($value);
        }
    }
    foreach (['barsLight', 'barsDark'] as $key) {
        if (isset($meta[$key])) {
            $parts = array_map('trim', explode(',', $meta[$key]));
            $meta[$key] = count($parts) === 3 ? [$parts[0], (float)$parts[1], $parts[2]] : null;
        }
    }
    return array_intersect_key($meta, array_flip(PASSPORT_KEYS));
}

/** @return string[] ошибки темы; пусто — тема годится */
function validateTheme(string $id, string $css, array $meta): array
{
    $errors = [];
    if (!preg_match('/^[a-z0-9-]+$/', $id)) {
        $errors[] = 'недопустимое имя файла';
    }
    if ($meta === []) {
        return [...$errors, 'нет паспорта /*!theme … */'];
    }
    if (($meta['id'] ?? '') !== $id) {
        $errors[] = 'id в паспорте «' . ($meta['id'] ?? '') . '» не совпадает с именем файла';
    }
    foreach (['name', 'mode', 'accent'] as $key) {
        if (($meta[$key] ?? '') === '') {
            $errors[] = "в паспорте нет «{$key}»";
        }
    }
    if (isset($meta['mode']) && !in_array($meta['mode'], ['light', 'dark'], true)) {
        $errors[] = 'mode должен быть light или dark';
    }
    if (!preg_match('/:root\[data-skin="' . preg_quote($id, '/') . '"\]\s*\{([^}]*)\}/', $css, $block)) {
        return [...$errors, 'нет основного блока :root[data-skin="' . $id . '"]'];
    }
    preg_match_all('/--([\w-]+)\s*:/', $block[1], $tokens);
    $missing = array_diff(REQUIRED_TOKENS, $tokens[1]);
    if ($missing) {
        $errors[] = 'в основном блоке нет токенов: ' . implode(', ', $missing);
    }
    return $errors;
}

/** @return array{0: string, 1: ?array} код до блока данных включительно и прежние данные */
function splitTarget(string $file): array
{
    $text = file_get_contents($file);
    $pos = strpos($text, HALT);
    if ($pos === false) {
        fail("в $file нет строки «__halt_compiler();» — некуда писать данные");
    }
    $code = substr($text, 0, $pos + strlen(HALT));
    $old = json_decode(substr($text, $pos + strlen(HALT)), true);
    return [$code, is_array($old) ? $old : null];
}

function encodeData(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

// ----------------------------------------------------------------- сборка ---

$catalog = loadCatalog($opts['catalog']);
if ($catalog['base'] === null) {
    fail('в каталоге нет base.css');
}

$sources = $catalog['themes'];
foreach (is_dir($localDir) ? (glob("$localDir/*.css") ?: []) : [] as $file) {
    $id = basename($file, '.css');
    echo (isset($sources[$id]) ? "своя тема заменяет каталожную: $id" : "своя тема: $id"), "\n";
    $sources[$id] = normalize(file_get_contents($file));
}
ksort($sources);

$themes = [];
foreach ($sources as $id => $css) {
    $id = (string)$id;
    $meta = parsePassport($css);
    $errors = validateTheme($id, $css, $meta);
    if ($errors) {
        warn("тема $id пропущена: " . implode('; ', $errors));
        continue;
    }
    $themes[$id] = ['meta' => $meta, 'css' => $css];
}
if (!$themes) {
    fail('ни одной годной темы');
}

$hash = substr(sha1($catalog['base'] . implode('', array_column($themes, 'css'))), 0, 12);
$data = [
    'format' => 1,
    'rev' => $opts['rev'] ?? null,
    'hash' => $hash,
    'lock' => null,
    'base' => $catalog['base'],
    'themes' => $themes,
];

[$code, $old] = splitTarget($target);
$next = $code . "\n" . encodeData($data);
$current = file_get_contents($target);

$before = array_keys($old['themes'] ?? []);
$added = array_diff(array_keys($themes), $before);
$removed = array_diff($before, array_keys($themes));
printf("тем: %d (%s)\n", count($themes), implode(', ', array_keys($themes)));
if ($added) {
    echo 'добавлены: ', implode(', ', $added), "\n";
}
if ($removed) {
    warn('убраны: ' . implode(', ', $removed));
}

if ($check) {
    // Ревизия каталога не делает файл устаревшим — сравниваем содержимое.
    $data['rev'] = $old['rev'] ?? null;
    $same = $current === $code . "\n" . encodeData($data);
    echo $same ? "index.php актуален\n" : "index.php устарел: запустите tools/build.php\n";
    exit($same ? 0 : 1);
}

if ($current !== $next) {
    file_put_contents($target, $next);
    echo "записан $target\n";
} else {
    echo "$target не изменился\n";
}

if (isset($opts['dist'])) {
    $dist = rtrim($opts['dist'], '/\\');
    foreach ($themes as $id => $theme) {
        @mkdir("$dist/$id", 0777, true);
        $single = ['lock' => $id, 'themes' => [$id => $theme]] + $data;
        file_put_contents("$dist/$id/index.php", $code . "\n" . encodeData($single));
    }
    printf("собрано в %s: %d файлов\n", $dist, count($themes));
}

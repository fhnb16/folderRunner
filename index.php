<?php
/**
 * Standalone Recursive File Explorer & Runner
 * Scans directories recursively for HTML, HTM, and PHP files.
 * Styled using BEM methodology with retro Steam-inspired dark theme.
 * Zero external dependencies (SVG / Base64 icons only).
 */

declare(strict_types=1);

// Prevent caching for real-time dynamic directory scanning
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$currentScript = basename(__FILE__);
$rootDir = __DIR__;
$allowedExtensions = ['html', 'htm', 'php'];

/**
 * Format bytes into human-readable representation
 */
function formatFileSize(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Recursively scans directory and builds structured tree array
 */
function scanDirectoryRecursively(string $dir, string $baseDir, string $selfScript, array $allowedExts): array {
    $result = [
        'dirs' => [],
        'files' => [],
        'totalExecutableFiles' => 0
    ];

    $items = @scandir($dir);
    if ($items === false) {
        return $result;
    }

    foreach ($items as $item) {
        // Skip hidden files, system pointers, and git tracking
        if ($item === '.' || $item === '..' || str_starts_with($item, '.') || $item === '.git') {
            continue;
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
        $relPath = ltrim(str_replace($baseDir, '', $fullPath), DIRECTORY_SEPARATOR);

        if (is_dir($fullPath)) {
            $subTree = scanDirectoryRecursively($fullPath, $baseDir, $selfScript, $allowedExts);
            // Include folder if it contains files or subdirectories
            $result['dirs'][] = [
                'name' => $item,
                'path' => $relPath,
                'fullPath' => $fullPath,
                'subTree' => $subTree,
                'mtime' => @filemtime($fullPath) ?: time(),
            ];
            $result['totalExecutableFiles'] += $subTree['totalExecutableFiles'];
        } elseif (is_file($fullPath)) {
            // Exclude the current scanner script itself
            if ($item === $selfScript && dirname($fullPath) === $baseDir) {
                continue;
            }

            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExts, true)) {
                $result['files'][] = [
                    'name' => $item,
                    'path' => $relPath,
                    'ext' => $ext,
                    'size' => @filesize($fullPath) ?: 0,
                    'mtime' => @filemtime($fullPath) ?: time()
                ];
                $result['totalExecutableFiles']++;
            }
        }
    }

    // Sort folders and files alphabetically
    usort($result['dirs'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($result['files'], fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return $result;
}

$tree = scanDirectoryRecursively($rootDir, $rootDir, $currentScript, $allowedExtensions);

/**
 * Recursively renders the tree elements using strict BEM methodology
 */
function renderTreeNodes(array $treeData, int $depth = 0, string $parentUid = 'root'): void {
    // Render Directories
    foreach ($treeData['dirs'] as $idx => $dir) {
        $nodeId = 'node_' . md5($parentUid . '_' . $dir['path'] . '_' . $idx);
        $fileCount = $dir['subTree']['totalExecutableFiles'];
        $directItems = count($dir['subTree']['dirs']) + count($dir['subTree']['files']);
        ?>
        <div class="tree-node tree-node--dir" id="<?= htmlspecialchars($nodeId) ?>" data-type="directory">
            <div class="tree-node__header" onclick="TreeExplorer.toggle('<?= htmlspecialchars($nodeId) ?>')">
                <span class="tree-node__pm-button" id="btn_<?= htmlspecialchars($nodeId) ?>">[-]</span>
                <span class="tree-node__icon tree-node__icon--folder">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
                    </svg>
                </span>
                <span class="tree-node__title"><?= htmlspecialchars($dir['name']) ?></span>
                <span class="tree-node__badge tree-node__badge--count"><?= $fileCount ?> <?= $fileCount === 1 ? 'file' : 'files' ?></span>
                <span class="tree-node__date"><?= date('d.m.Y H:i', $dir['mtime']) ?></span>
            </div>
            <div class="tree-node__content" id="content_<?= htmlspecialchars($nodeId) ?>" style="padding-left: <?= ($depth > 0 ? 18 : 12) ?>px;">
                <?php if ($directItems === 0): ?>
                    <div class="tree-node__empty">Папка пуста или не содержит поддерживаемых файлов</div>
                <?php else: ?>
                    <?php renderTreeNodes($dir['subTree'], $depth + 1, $nodeId); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // Render Files
    foreach ($treeData['files'] as $idx => $file) {
        $fileUid = 'file_' . md5($parentUid . '_' . $file['path'] . '_' . $idx);
        $encodedUrl = htmlspecialchars(str_replace('\\', '/', $file['path']));
        ?>
        <div class="tree-node tree-node--file" id="<?= htmlspecialchars($fileUid) ?>" data-type="file" data-name="<?= htmlspecialchars(strtolower($file['name'])) ?>">
            <div class="tree-node__row">
                <span class="tree-node__icon tree-node__icon--<?= htmlspecialchars($file['ext']) ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                    </svg>
                </span>

                <span class="tree-node__file-name" title="<?= $encodedUrl ?>">
                    <?= htmlspecialchars($file['name']) ?>
                </span>

                <span class="tree-node__badge tree-node__badge--<?= htmlspecialchars($file['ext']) ?>">
                    .<?= strtoupper(htmlspecialchars($file['ext'])) ?>
                </span>

                <span class="tree-node__meta">
                    <?= formatFileSize($file['size']) ?> &bull; <?= date('d.m.Y H:i', $file['mtime']) ?>
                </span>

                <div class="tree-node__actions">
                    <button type="button" 
                            class="button-steam button-steam--primary button-steam--small" 
                            onclick="TreeExplorer.runFile('<?= $encodedUrl ?>', '<?= htmlspecialchars(addslashes($file['name'])) ?>')">
                        <span>Запустить</span>
                    </button>
                    <a href="<?= $encodedUrl ?>" 
                       target="_blank" 
                       rel="noopener noreferrer" 
                       class="button-steam button-steam--secondary button-steam--small" 
                       title="Открыть в новой вкладке браузера">
                        <span>Вкладка &nearr;</span>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Web Explorer & Runner</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noarchive, noindex">

    <style>
        :root {
            --color-top-bar-background: #202020;
            --color-editor-background: #2e2e2e;
            --color-icon-color: #999797;
            --color-steam-deep: #171a21;
            --color-steam-surface: #1b2838;
            --color-steam-header: #0d121a;
            --color-steam-card: #212c3d;
            --color-steam-border: #2a3f5a;
            --color-steam-blue: #66c0f4;
            --color-steam-blue-hover: #ffffff;
            --color-steam-cyan: #38bdf8;
            --color-text-main: #c7d5e0;
            --color-text-dim: #8f98a0;
            --color-badge-php: #8892bf;
            --color-badge-html: #e44d26;
            --color-badge-htm: #f16529;
            --font-family-base: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --font-family-mono: "Consolas", "Courier New", monospace;
        }

        * {
            box-sizing: border-box;
            scrollbar-width: thin;
            scrollbar-color: var(--color-icon-color) var(--color-editor-background);
        }

        ::-webkit-scrollbar {
            width: 9px;
            height: 9px;
        }

        ::-webkit-scrollbar-track {
            background: var(--color-editor-background);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--color-icon-color);
            border-radius: 2px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #b5b3b3;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: var(--color-steam-deep);
            color: var(--color-text-main);
            font-family: var(--font-family-base);
            font-size: 13px;
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .explorer-header {
            background: linear-gradient(180deg, var(--color-top-bar-background) 0%, #151515 100%);
            border-bottom: 1px solid #333333;
            padding: 18px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        .explorer-header__inner {
            max-width: 1500px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .explorer-header__title-group {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .explorer-header__logo {
            width: 36px;
            height: 36px;
            background: #2a475e;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-steam-blue);
            box-shadow: inset 0 0 8px rgba(0, 0, 0, 0.5);
        }

        .explorer-header__heading {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #ffffff;
        }

        .explorer-header__subheading {
            margin: 3px 0 0 0;
            font-size: 12px;
            color: var(--color-text-dim);
            font-weight: 400;
        }

        .explorer-header__stats {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 12px;
            color: var(--color-text-dim);
        }

        .explorer-header__stat-item {
            background: rgba(0, 0, 0, 0.3);
            padding: 6px 12px;
            border-radius: 3px;
            border: 1px solid #2e2e2e;
        }

        .explorer-header__stat-item strong {
            color: var(--color-steam-blue);
        }

        .explorer-toolbar {
            background-color: var(--color-editor-background);
            border-bottom: 1px solid #3c3c3c;
            padding: 10px 24px;
        }

        .explorer-toolbar__inner {
            max-width: 1500px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .explorer-toolbar__search-box {
            position: relative;
            flex: 1;
            max-width: 480px;
        }

        .explorer-toolbar__search-input {
            width: 100%;
            background: #1e1e1e;
            border: 1px solid #4a4a4a;
            border-radius: 3px;
            padding: 7px 12px 7px 32px;
            color: #ffffff;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .explorer-toolbar__search-input:focus {
            border-color: var(--color-steam-blue);
            box-shadow: 0 0 6px rgba(102, 192, 244, 0.3);
        }

        .explorer-toolbar__search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-icon-color);
            pointer-events: none;
            display: flex;
        }

        .explorer-toolbar__actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* STEAM BUTTON STYLING (Derived from .btnv6_blue_hoverfade) */
        .button-steam {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 2px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            outline: none;
            transition: background 0.2s ease, color 0.2s ease, filter 0.2s ease;
            user-select: none;
        }

        .button-steam--primary {
            background: linear-gradient(90deg, #244e6b 0%, #1972ad 100%);
            color: #d2efff;
        }

        .button-steam--primary:hover {
            background: linear-gradient(90deg, #2c648b 0%, #2087ce 100%);
            color: #ffffff;
            filter: brightness(1.15);
        }

        .button-steam--secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #c7d5e0;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .button-steam--secondary:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
        }

        .button-steam--small {
            padding: 3px 8px;
            font-size: 11px;
        }

        .explorer-workspace {
            flex: 1;
            display: flex;
            height: calc(100vh - 128px);
            overflow: hidden;
            background: #121417;
        }

        .explorer-workspace__tree-panel {
            flex: 1;
            min-width: 320px;
            overflow-y: auto;
            padding: 16px 24px;
            background-color: #171a21;
        }

        .explorer-workspace__tree-panel--previewing {
            flex: 0 0 460px;
            max-width: 480px;
            border-right: 2px solid #232c3d;
        }

        .explorer-workspace__viewer-panel {
            flex: 1;
            display: none;
            flex-direction: column;
            background: #000000;
            position: relative;
        }

        .explorer-workspace__viewer-panel--active {
            display: flex;
        }

        .tree-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .tree-node {
            margin-bottom: 5px;
            user-select: none;
        }

        .tree-node--dir > .tree-node__header {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #202b3c;
            border-left: 3px solid #3d6c9b;
            padding: 8px 12px;
            cursor: pointer;
            border-radius: 2px;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .tree-node--dir > .tree-node__header:hover {
            background: #27384f;
            border-left-color: var(--color-steam-blue);
        }

        .tree-node__pm-button {
            font-family: var(--font-family-mono);
            font-weight: 700;
            color: var(--color-steam-blue);
            width: 20px;
            display: inline-block;
            text-align: center;
            font-size: 13px;
        }

        .tree-node__icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--color-icon-color);
        }

        .tree-node__icon--folder {
            color: #e5b358;
        }

        .tree-node__icon--php {
            color: var(--color-badge-php);
        }

        .tree-node__icon--html,
        .tree-node__icon--htm {
            color: var(--color-badge-html);
        }

        .tree-node__title {
            font-weight: 600;
            color: #ffffff;
            font-size: 13px;
            flex: 1;
            word-break: break-word;
        }

        .tree-node__badge {
            font-size: 10px;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 2px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .tree-node__badge--count {
            background: rgba(0, 0, 0, 0.4);
            color: var(--color-text-dim);
            border: 1px solid #314255;
        }

        .tree-node__badge--php {
            background: rgba(136, 146, 191, 0.2);
            color: #9da8db;
            border: 1px solid rgba(136, 146, 191, 0.4);
        }

        .tree-node__badge--html,
        .tree-node__badge--htm {
            background: rgba(228, 77, 38, 0.2);
            color: #ff7e5a;
            border: 1px solid rgba(228, 77, 38, 0.4);
        }

        .tree-node__date {
            font-size: 11px;
            color: #627284;
            white-space: nowrap;
        }

        .tree-node__content {
            margin-top: 4px;
            display: block;
        }

        .tree-node__content--collapsed {
            display: none !important;
        }

        .tree-node__empty {
            color: #687482;
            font-style: italic;
            font-size: 11px;
            padding: 6px 12px;
        }

        .tree-node--file {
            margin-left: 6px;
            margin-bottom: 3px;
        }

        .tree-node__row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 10px;
            background: #182230;
            border: 1px solid #212d3d;
            border-radius: 2px;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .tree-node__row:hover {
            background: #1e2c3e;
            border-color: #385070;
        }

        .tree-node__row--active {
            background: #253952 !important;
            border-color: var(--color-steam-blue) !important;
        }

        .tree-node__file-name {
            font-family: var(--font-family-mono);
            font-size: 12px;
            color: #dbe4ec;
            flex: 1;
            word-break: break-all;
        }

        .tree-node__meta {
            font-size: 11px;
            color: #728294;
            white-space: nowrap;
        }

        .tree-node__actions {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .viewer-header {
            background: #161a21;
            border-bottom: 1px solid #283447;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .viewer-header__info {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }

        .viewer-header__badge {
            background: #27415d;
            color: var(--color-steam-blue);
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 2px;
            text-transform: uppercase;
            font-weight: 700;
        }

        .viewer-header__filename {
            font-family: var(--font-family-mono);
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .viewer-header__actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .viewer-frame {
            width: 100%;
            height: 100%;
            border: none;
            background-color: #ffffff;
        }

        .viewer-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1b2838;
            color: #ffffff;
            border: 1px solid var(--color-steam-blue);
            padding: 10px 16px;
            border-radius: 3px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.6);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease, transform 0.3s ease;
            transform: translateY(10px);
            z-index: 9999;
            font-size: 12px;
        }

        .viewer-toast--visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 900px) {
            .explorer-workspace {
                flex-direction: column;
                height: auto;
            }
            .explorer-workspace__tree-panel--previewing {
                flex: 1 1 auto;
                max-width: 100%;
                border-right: none;
                border-bottom: 2px solid #232c3d;
                height: 380px;
            }
            .explorer-workspace__viewer-panel {
                height: 600px;
            }
        }
    </style>
</head>
<body class="explorer-body">

    <header class="explorer-header">
        <div class="explorer-header__inner">
            <div class="explorer-header__title-group">
                <div class="explorer-header__logo">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 6h-8l-2-2H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-1 8h-3v3h-2v-3h-3v-2h3V9h2v3h3v2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="explorer-header__heading">Каталог файлов — Локальный запуск</h1>
                    <h2 class="explorer-header__subheading">Корневой каталог: <?= htmlspecialchars(str_replace('\\', '/', $rootDir)) ?></h2>
                </div>
            </div>

            <div class="explorer-header__stats">
                <div class="explorer-header__stat-item">
                    Всего найдено: <strong><?= $tree['totalExecutableFiles'] ?></strong> файлов
                </div>
                <div class="explorer-header__stat-item">
                    Форматы: <strong>.html, .htm, .php</strong>
                </div>
            </div>
        </div>
    </header>

    <section class="explorer-toolbar">
        <div class="explorer-toolbar__inner">
            <div class="explorer-toolbar__search-box">
                <span class="explorer-toolbar__search-icon">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                    </svg>
                </span>
                <input type="text" 
                       id="searchInput" 
                       class="explorer-toolbar__search-input" 
                       placeholder="Поиск по названию файла или расширению..." 
                       oninput="TreeExplorer.filterSearch(this.value)">
            </div>

            <div class="explorer-toolbar__actions">
                <button type="button" class="button-steam button-steam--secondary" onclick="TreeExplorer.expandAll()">
                    <span>[+] Развернуть все</span>
                </button>
                <button type="button" class="button-steam button-steam--secondary" onclick="TreeExplorer.collapseAll()">
                    <span>[-] Свернуть все</span>
                </button>
                <button type="button" class="button-steam button-steam--secondary" onclick="window.location.reload()">
                    <span>&circlearrowright; Обновить</span>
                </button>
            </div>
        </div>
    </section>

    <main class="explorer-workspace" id="explorerWorkspace">
        <div class="explorer-workspace__tree-panel" id="treePanel">
            <div class="tree-container" id="treeRoot">
                <?php if ($tree['totalExecutableFiles'] === 0 && empty($tree['dirs'])): ?>
                    <div style="padding: 30px; text-align: center; color: var(--color-text-dim);">
                        Поддерживаемые файлы (.html, .htm, .php) в этой директории не обнаружены.
                    </div>
                <?php else: ?>
                    <?php renderTreeNodes($tree); ?>
                <?php endif; ?>
            </div>
        </div>

        <section class="explorer-workspace__viewer-panel" id="viewerPanel">
            <div class="viewer-header">
                <div class="viewer-header__info">
                    <span class="viewer-header__badge">Исполнение</span>
                    <span class="viewer-header__filename" id="viewerFilename">Файл не выбран</span>
                </div>
                <div class="viewer-header__actions">
                    <button type="button" class="button-steam button-steam--secondary button-steam--small" onclick="TreeExplorer.reloadFrame()" title="Перезагрузить страницу во фрейме">
                        <span>&circlearrowright; Перезапуск</span>
                    </button>
                    <a id="viewerExternalLink" href="#" target="_blank" rel="noopener noreferrer" class="button-steam button-steam--primary button-steam--small">
                        <span>В отдельном окне &nearr;</span>
                    </a>
                    <button type="button" class="button-steam button-steam--secondary button-steam--small" onclick="TreeExplorer.closeViewer()" title="Закрыть предпросмотр">
                        <span>&times; Закрыть</span>
                    </button>
                </div>
            </div>
            <iframe id="runnerFrame" class="viewer-frame" src="about:blank"></iframe>
        </section>
    </main>

    <div class="viewer-toast" id="toastMessage"></div>

    <script>
        const TreeExplorer = (function() {
            let activeUrl = null;

            function toggle(id) {
                const btn = document.getElementById('btn_' + id);
                const content = document.getElementById('content_' + id);

                if (!btn || !content) return;

                if (btn.innerHTML === '[-]') {
                    btn.innerHTML = '[+]';
                    content.classList.add('tree-node__content--collapsed');
                } else {
                    btn.innerHTML = '[-]';
                    content.classList.remove('tree-node__content--collapsed');
                }
            }

            function expandAll() {
                const buttons = document.querySelectorAll('.tree-node__pm-button');
                const contents = document.querySelectorAll('.tree-node__content');
                buttons.forEach(b => b.innerHTML = '[-]');
                contents.forEach(c => c.classList.remove('tree-node__content--collapsed'));
            }

            function collapseAll() {
                const buttons = document.querySelectorAll('.tree-node__pm-button');
                const contents = document.querySelectorAll('.tree-node__content');
                buttons.forEach(b => b.innerHTML = '[+]');
                contents.forEach(c => c.classList.add('tree-node__content--collapsed'));
            }

            function filterSearch(query) {
                const term = query.trim().toLowerCase();
                const fileNodes = document.querySelectorAll('.tree-node--file');
                const dirNodes = document.querySelectorAll('.tree-node--dir');

                if (term === '') {
                    // Reset all displays
                    fileNodes.forEach(f => f.style.display = '');
                    dirNodes.forEach(d => d.style.display = '');
                    return;
                }

                // First hide all
                fileNodes.forEach(f => {
                    const name = f.getAttribute('data-name') || '';
                    if (name.includes(term)) {
                        f.style.display = '';
                        // Open all parent directories
                        let parent = f.parentElement;
                        while (parent && parent.id !== 'treeRoot') {
                            if (parent.classList.contains('tree-node__content')) {
                                parent.classList.remove('tree-node__content--collapsed');
                                const parentDir = parent.closest('.tree-node--dir');
                                if (parentDir) {
                                    parentDir.style.display = '';
                                    const btn = parentDir.querySelector('.tree-node__pm-button');
                                    if (btn) btn.innerHTML = '[-]';
                                }
                            }
                            parent = parent.parentElement;
                        }
                    } else {
                        f.style.display = 'none';
                    }
                });

                // Clean empty directories in search view
                dirNodes.forEach(d => {
                    const visibleFiles = d.querySelectorAll('.tree-node--file:not([style*="display: none"])');
                    if (visibleFiles.length === 0) {
                        d.style.display = 'none';
                    } else {
                        d.style.display = '';
                    }
                });
            }

            function runFile(url, fileName) {
                activeUrl = url;
                const workspace = document.getElementById('explorerWorkspace');
                const treePanel = document.getElementById('treePanel');
                const viewerPanel = document.getElementById('viewerPanel');
                const runnerFrame = document.getElementById('runnerFrame');
                const filenameLabel = document.getElementById('viewerFilename');
                const externalLink = document.getElementById('viewerExternalLink');

                // Highlight active row
                document.querySelectorAll('.tree-node__row').forEach(row => {
                    row.classList.remove('tree-node__row--active');
                });
                
                const activeEventEl = window.event ? window.event.target.closest('.tree-node__row') : null;
                if (activeEventEl) {
                    activeEventEl.classList.add('tree-node__row--active');
                }

                // Activate split-viewer view
                treePanel.classList.add('explorer-workspace__tree-panel--previewing');
                viewerPanel.classList.add('explorer-workspace__viewer-panel--active');
                filenameLabel.textContent = fileName + ' (' + url + ')';
                externalLink.href = url;

                // Load iframe safely
                runnerFrame.src = url;
                showToast('Запущен файл: ' + fileName);
            }

            function reloadFrame() {
                const runnerFrame = document.getElementById('runnerFrame');
                if (runnerFrame && activeUrl) {
                    runnerFrame.src = activeUrl;
                    showToast('Фрейм перезапущен');
                }
            }

            function closeViewer() {
                const treePanel = document.getElementById('treePanel');
                const viewerPanel = document.getElementById('viewerPanel');
                const runnerFrame = document.getElementById('runnerFrame');

                treePanel.classList.remove('explorer-workspace__tree-panel--previewing');
                viewerPanel.classList.remove('explorer-workspace__viewer-panel--active');
                runnerFrame.src = 'about:blank';
                activeUrl = null;

                document.querySelectorAll('.tree-node__row').forEach(row => {
                    row.classList.remove('tree-node__row--active');
                });
            }

            function showToast(message) {
                const toast = document.getElementById('toastMessage');
                if (!toast) return;
                toast.textContent = message;
                toast.classList.add('viewer-toast--visible');
                setTimeout(() => {
                    toast.classList.remove('viewer-toast--visible');
                }, 2200);
            }

            return {
                toggle: toggle,
                expandAll: expandAll,
                collapseAll: collapseAll,
                filterSearch: filterSearch,
                runFile: runFile,
                reloadFrame: reloadFrame,
                closeViewer: closeViewer
            };
        })();
    </script>
</body>
</html>
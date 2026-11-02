# Local Web File Explorer & Runner

A lightweight, standalone single-file PHP application designed to recursively scan directories for web documents (`.html`, `.htm`, `.php`), present them in an interactive tree view, and run/preview them instantly within an isolated workspace.

Built with **BEM methodology**, **zero third-party dependencies**, a fluid layout from 320 px phones to wide desktops, and **26 switchable themes** from [fhnb16/theme-catalog](https://github.com/fhnb16/theme-catalog).

---

## ✨ Features

- **Zero External Dependencies**:
  - No external CDNs, JS libraries, or CSS frameworks. Still one file: themes are embedded into `index.php`.
  - All icons are rendered via lightweight, inline SVGs.
  - Completely operational in offline or air-gapped development environments.

- **Recursive Directory Scanner**:
  - Traverses nested subdirectories to arbitrary depths.
  - Automatically filters for supported extensions (`.html`, `.htm`, `.php`).
  - Skips hidden files and folders (`.git` and the like) and the runner script itself.
  - Links are URL-encoded per path segment, so names with spaces, `#`, `&`, `%` or Cyrillic open correctly.

- **Themes & Configurator** (palette button in the top bar):
  - 26 themes from the catalog — Patch (the classic Steam look, default), Gloss, Frost, Metro, Material 3, Fluent, Olive (Windows 98 in light mode), GRiD, MMO, Vapor and more.
  - Light / dark / system mode, accent colour (presets or any colour), solid bars.
  - Applied live, remembered in cookies, rendered server-side on the next load — no flash of the default theme.

- **Fluid Layout**:
  - Tree rows adapt to the width of their own folder (container queries), not the window: metadata moves to a second line, buttons collapse to icons.
  - Nothing overlaps or overflows from 320 px up, in every theme.
  - On screens narrower than 760 px the list and the preview take turns, switched by a bottom tab bar.

- **Independent Dual-Pane Workspace**:
  - The tree and the viewer `iframe` scroll independently; the page itself never scrolls.
  - **Resizer**: drag with mouse or touch, arrow keys when focused (Shift for bigger steps), double-click to reset. Width is remembered.
  - **Hide list**, **real fullscreen** for the preview, **reload** only the frame, **open in new tab**.

- **Instant Live Search**:
  - Filters by file name and path, opens matching folders, shows an empty state when nothing matches.
  - `/` focuses search, `Esc` clears it or closes the preview.

---

## 📋 Requirements

- **PHP 8.0** or higher (compatible with PHP 8.1, 8.2, 8.3+).
- Any standard web server:
  - PHP Built-in Development Server (`php -S`)
  - Apache (with `mod_php`)
  - Nginx (with `php-fpm`)
  - Caddy, OpenLiteSpeed, or local stacks (XAMPP, Laragon, Docker, etc.)

---

## 🚀 Quick Start

### 1. Place the File
Drop `index.php` into the root folder of the project or directory you want to explore and run files from.

```bash
my-projects-folder/
├── project-alpha/
│   ├── index.html
│   └── script.js
├── legacy-demos/
│   └── test.php
└── index.php    <--- Place the script here
```

### 2. Launch with PHP Built-in Server
Open your terminal inside that directory and start PHP's built-in web server:

```bash
php -S localhost:8080
```

### 3. Open in Browser
Visit [http://localhost:8080](http://localhost:8080) in your web browser.

### Downloads
The [`latest` release](../../releases/tag/latest) is rebuilt automatically whenever the themes change:

- `folderRunner.zip` — `index.php` with all themes and the configurator;
- `folderRunner-<theme>.zip` — the same `index.php` with one theme locked in (the configurator keeps mode, accent and solid bars, but hides the theme list);
- `folderRunner-all-themes.zip` — every single-theme variant at once.

Versioned releases are made by pushing a tag: `git tag v1.2.0 && git push origin v1.2.0`.

---

## 🎨 Themes

### How it works
`index.php` is code plus a data block. Everything after the `__halt_compiler();` line is JSON written by `tools/build.py`: the catalog's `base.css` and every theme with its passport. The page loads only the framework and the selected theme, served by `index.php` itself (`?fr=base.css`, `?fr=theme.css&id=<id>`, long-cached).

Do not edit the data block by hand — edit the code above it and rebuild.

### Adding a theme
- **To the catalog** (preferred): add `themes/<id>.css` to [fhnb16/theme-catalog](https://github.com/fhnb16/theme-catalog) following its `THEME-SPEC.md`. The `themes` workflow here picks it up within a day, or immediately via *Run workflow*.
- **Locally**: put `<id>.css` into `themes/` in this repository. It is added to the catalog's themes or replaces the catalog theme with the same id.

A theme without a `/*!theme … */` passport, with an `id` that differs from the file name, or missing any of the 46 required tokens is skipped with a warning; the build does not fail because of one theme.

### Building
Builds are done by `tools/build.py` — Python 3.8+, standard library only. Normally you don't run it yourself: push and let GitHub Actions rebuild. To build locally:

```bash
git clone https://github.com/fhnb16/theme-catalog ../theme-catalog
python3 tools/build.py --catalog ../theme-catalog               # rewrite the data block of index.php
python3 tools/build.py --catalog ../theme-catalog --dist dist   # plus dist/<id>/index.php per theme
python3 tools/build.py --catalog ../theme-catalog --zip release # release zips
python3 tools/build.py --catalog ../theme-catalog --check       # exit 1 if index.php is stale
```
`--catalog` also accepts the catalog's single-file Markdown export (the «Единый md» button): `### base.css` and `### themes/<id>.css` sections are read from it.

### GitHub Actions
`.github/workflows/themes.yml` runs on push, daily, manually and on `repository_dispatch` (`themes-updated`). It uses only what the runner image already has: checks out the catalog, rebuilds with `python3`, smoke-tests the page and every theme stylesheet under the image's `php -S` (skipped if PHP is absent), commits `index.php` if it changed, uploads the zips as an artifact, refreshes the `latest` release when the themes changed (or it doesn't exist yet) and publishes a versioned release for `v*` tags. Releases are made with the runner's `gh` CLI.

The catalog repository is private, so the workflow needs the `CATALOG_TOKEN` secret (*Settings → Secrets and variables → Actions*): a fine-grained personal access token with access to `fhnb16/theme-catalog` only and *Contents: Read-only*. Without it the run stops at «Доступ к каталогу» with an explanation. If the catalog is made public, the secret is not needed.

To rebuild right after the catalog changes, add a step to the catalog's workflow that sends the dispatch (needs a token with access to this repository in the catalog's secrets):

```bash
curl -X POST -H "Authorization: Bearer $FOLDER_RUNNER_TOKEN" \
  https://api.github.com/repos/fhnb16/folderRunner/dispatches \
  -d '{"event_type":"themes-updated"}'
```

---

## ⚙️ Configuration

At the top of `index.php`:

```php
const FR_DEFAULT_SKIN = 'patch';               // theme for first-time visitors
const FR_EXTENSIONS = ['html', 'htm', 'php'];  // scanned extensions
```

---

## 📂 File Structure (BEM Layout)

The shell uses the catalog's blocks where themes give them character, and its own `fr-*` blocks for the dense tree layout.

| Block / Element | Description |
| :--- | :--- |
| `.nav-bar` (catalog) | Top bar: home, theme configurator, rescan |
| `.fr-toolbar` | Search (`.search__box`), expand/collapse (`.btn`), stats line |
| `.fr-work` | Grid workspace: list, resizer, viewer; `--open`, `--tree-hidden`, `--pane-view` |
| `.fr-tree`, `.fr-dir`, `.fr-file` | Recursive tree: folders on `<details>`, file rows with container-query layouts |
| `.fr-resizer` | Draggable, keyboard-accessible splitter |
| `.fr-viewer` | Preview toolbar (`.trbar__btn`) and `iframe` |
| `.tab-bar` (catalog) | Files / Preview switch on narrow screens |
| `.modal`, `.segmented`, `.swatches`, `.switch` (catalog) | Theme configurator |
| `.toast` (catalog) | Notifications |

---

## 🔒 Security Notice

This tool is designed specifically for **local development and staging sandboxes**. It directly lists and allows execution/display of files within its directory hierarchy.

> ⚠️ **Warning**: Do not deploy this script to public production servers without adding authentication (e.g., HTTP Basic Auth, IP allowlisting) to prevent unauthorized file discovery or arbitrary execution.

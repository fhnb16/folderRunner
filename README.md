# Local Web File Explorer & Runner

A lightweight, standalone single-file PHP application designed to recursively scan directories for web documents (`.html`, `.htm`, `.php`), present them in an interactive Steam-inspired tree view, and run/preview them instantly within an isolated workspace.

Built with strict **BEM methodology**, **zero third-party dependencies**, and an autonomous dual-pane interface.

---

## ✨ Features

- **Zero External Dependencies**:
  - No external CDNs, JS libraries, or CSS frameworks.
  - All icons are rendered via lightweight, inline SVGs.
  - Completely operational in offline or air-gapped development environments.

- **Recursive Directory Scanner**:
  - Traverses nested subdirectories to arbitrary depths.
  - Automatically filters for supported extensions (`.html`, `.htm`, `.php`).
  - Automatically skips hidden files, system files (`.`, `..`), and `.git` tracking repositories.
  - Prevents scanning recursion loops and ignores the runner script itself.

- **Steam Dark Theme & BEM Architecture**:
  - UI styled after classic Steam client aesthetics (`#171a21`, `#1b2838`, `#66c0f4`).
  - Structured following strict **Block Element Modifier (BEM)** conventions for clean, maintainable styling.
  - Custom scrollbar integration matching the theme palette.

- **Independent Dual-Pane Workspace**:
  - **No document-level scrolling**: Page body scroll is fully disabled to prevent scroll hijacking.
  - **Autonomous scrolling**: The tree explorer panel on the left and the viewer `iframe` on the right scroll 100% independently.
  - **Text truncation protection**: File names have defined minimum widths and ellipsis overflow, preventing label squeezing when files are launched.

- **Interactive Workspace Controls**:
  - **Draggable Resizer**: Click and drag the vertical divider to adjust panel proportions to your preference.
  - **Overlay Drag Shield**: Prevents the embedded iframe from capturing mouse pointer events during resize operations.
  - **Sidebar Toggle (`☰`)**: Hide the file catalog to give maximum room to the preview frame.
  - **Fullscreen Toggle (`⛶`)**: Expand the running application over the entire viewport.
  - **Hot Reload**: Reload only the embedded frame without re-rendering or re-scanning the file directory.
  - **New Tab Launcher**: Open any file directly in a new browser tab (`target="_blank"`).

- **Instant Live Search**:
  - Real-time client-side search filtering by filename and extension.
  - Automatically expands parent folders when matching files are found.

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

---

## ⚙️ Configuration & Customization

All configurations are located directly at the top of `index.php`:

### Allowed File Extensions
By default, the script scans for `.html`, `.htm`, and `.php` files. You can extend or modify this list:

```php
// In index.php:
$allowedExtensions = ['html', 'htm', 'php', 'phtml', 'svg'];
```

### Theming Variables
All visual styling is controlled through CSS Custom Properties (variables) in the `<style>` block:

```css
:root {
    --color-top-bar-background: #202020;
    --color-editor-background: #2e2e2e;
    --color-icon-color: #999797;
    --color-steam-deep: #171a21;
    --color-steam-surface: #1b2838;
    --color-steam-border: #2a3f5a;
    --color-steam-blue: #66c0f4;
    --color-text-main: #c7d5e0;
}
```

---

## 📂 File Structure (BEM Layout)

| BEM Block / Element | Description |
| :--- | :--- |
| `.explorer-header` | Top branding banner, active root path, and global file counts |
| `.explorer-toolbar` | Search input, global collapse/expand toggles, and reload button |
| `.explorer-workspace` | Main flex container containing the split panels and splitter |
| `.explorer-workspace__tree-panel` | Left panel holding the recursive directory tree |
| `.explorer-workspace__resizer` | Interactive draggable splitter between panels |
| `.explorer-workspace__viewer-panel` | Right workspace panel holding the execution toolbar and `iframe` |
| `.tree-node` | Tree items (folders and files) with states (`--dir`, `--file`) |
| `.button-steam` | Reusable button components with variants (`--primary`, `--secondary`, `--small`) |

---

## 🔒 Security Notice

This tool is designed specifically for **local development and staging sandboxes**. It directly lists and allows execution/display of files within its directory hierarchy. 

> ⚠️ **Warning**: Do not deploy this script to public production servers without adding authentication (e.g., HTTP Basic Auth, IP allowlisting) to prevent unauthorized file discovery or arbitrary execution.

---

## 📄 License

This project is open-source and available under the [MIT License](https://opensource.org/licenses/MIT). You are free to modify, distribute, and embed it into your local toolchains.
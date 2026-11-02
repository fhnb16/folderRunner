#!/usr/bin/env python3
"""Сборщик тем folderRunner.

Берёт каркас и темы из каталога fhnb16/theme-catalog и записывает их блоком
данных в конец index.php (после __halt_compiler). Код приложения выше блока
не трогает. Только стандартная библиотека Python 3.8+.

Редакции (поле edition в данных) — код один, различаются данные:
  base   — index.php в репозитории: одна тема (--base-skin, по умолчанию
           patch) без описания, только переключатель светлая/тёмная;
  theme  — по файлу на тему: тема закреплена, конфигуратор с режимами,
           акцентом, сплошными панелями и описанием;
  all    — все темы и полный конфигуратор.

    python3 tools/build.py --catalog <папка каталога | единый md каталога>
                           [--local themes] [--target index.php]
                           [--base-skin patch] [--dist dist] [--zip release]
                           [--rev <sha>] [--check]

--catalog    папка с base.css и themes/*.css или единый md каталога
             (разделы «### base.css» и «### themes/<id>.css» из «Исходников»);
--local      папка своих тем: *.css дописывают каталог или заменяют тему
             с тем же id (по умолчанию themes/ рядом с index.php);
--target     куда писать базовую редакцию (по умолчанию index.php);
--dist       собрать <dist>/all-themes/index.php и <dist>/themes/<id>/index.php;
--zip        архивы для релиза: folderRunner.zip (базовая),
             folderRunner-all-themes.zip, folderRunner-<id>.zip и
             folderRunner-single-themes.zip (все версии с одной темой разом);
--rev        ревизия каталога, попадает в блок данных;
--check      ничего не писать, выйти с кодом 1, если index.php устарел.

В GitHub Actions пишет в $GITHUB_OUTPUT: themes (число), ids (через пробел),
changed (true/false — изменился ли index.php в репозитории).
"""
import argparse
import hashlib
import json
import os
import re
import sys
import zipfile
from pathlib import Path

HALT = '\n__halt_compiler();'

# Обязательные токены основного блока темы — как в tools/build-index.py каталога.
REQUIRED_TOKENS = (
    'font radius radius-lg card-radius bar-blur bar-h tab-h hero-size hero-weight hero-tracking nav-grid '
    'nav-align nav-weight sw-w sw-h sw-r sw-thumb sw-thumb-r sw-pad sw-off sw-on sw-shadow seg-r seg-line '
    'seg-text seg-on seg-on-text chip-r bg bg-elev surface surface-press sep sep-strong text text-2 text-3 '
    'bar bar-line danger ok thumb on-danger toast-bg toast-text shadow-soft'
).split()

# Ключи паспорта, которые нужны приложению (описание — для конфигуратора).
PASSPORT_KEYS = {
    'id', 'name', 'mode', 'accent', 'accentLight', 'accentDark', 'companion', 'companionLight',
    'companionDark', 'barsLight', 'barsDark', 'mono', 'description',
}

GITHUB = bool(os.environ.get('GITHUB_ACTIONS'))


def warn(msg):
    print(('::warning::' if GITHUB else 'warning: ') + msg, file=sys.stderr)


def fail(msg):
    print(('::error::' if GITHUB else 'error: ') + msg, file=sys.stderr)
    sys.exit(1)


def read(path):
    with open(path, encoding='utf-8', newline='') as f:
        return f.read()


def normalize(text):
    return text.replace('\r\n', '\n').rstrip(' \t\n\r\0\x0b') + '\n'


def load_catalog(path):
    """Каркас и темы из папки каталога или из его единого md."""
    out = {'base': None, 'themes': {}}
    if path.is_dir():
        if (path / 'base.css').is_file():
            out['base'] = normalize(read(path / 'base.css'))
        for file in sorted((path / 'themes').glob('*.css')):
            out['themes'][file.stem] = normalize(read(file))
        return out

    if not path.is_file():
        fail('каталог не найден: %s' % path)

    md = read(path).replace('\r\n', '\n')
    pattern = r'^### (base\.css|themes/([a-z0-9-]+)\.css)\s*\n+```css\n(.*?)\n```\s*$'
    for name, theme_id, css in re.findall(pattern, md, re.M | re.S):
        if name == 'base.css':
            out['base'] = normalize(css)
        else:
            out['themes'][theme_id] = normalize(css)
    return out


def parse_passport(css):
    m = re.search(r'/\*!theme(.*?)\*/', css, re.S)
    if not m:
        return {}
    meta = {}
    for line in m.group(1).strip().split('\n'):
        if ':' in line:
            key, value = line.split(':', 1)
            meta[key.strip()] = value.strip()
    for key in ('barsLight', 'barsDark'):
        if key in meta:
            parts = [p.strip() for p in meta[key].split(',')]
            try:
                meta[key] = [parts[0], float(parts[1]), parts[2]] if len(parts) == 3 else None
            except ValueError:
                meta[key] = None
    return {k: v for k, v in meta.items() if k in PASSPORT_KEYS}


def validate_theme(theme_id, css, meta):
    """Ошибки темы; пустой список — тема годится."""
    errors = []
    if not re.fullmatch(r'[a-z0-9-]+', theme_id):
        errors.append('недопустимое имя файла')
    if not meta:
        return errors + ['нет паспорта /*!theme … */']
    if meta.get('id', '') != theme_id:
        errors.append('id в паспорте «%s» не совпадает с именем файла' % meta.get('id', ''))
    for key in ('name', 'mode', 'accent'):
        if not meta.get(key):
            errors.append('в паспорте нет «%s»' % key)
    if 'mode' in meta and meta['mode'] not in ('light', 'dark'):
        errors.append('mode должен быть light или dark')
    block = re.search(r':root\[data-skin="%s"\]\s*\{([^}]*)\}' % re.escape(theme_id), css)
    if not block:
        return errors + ['нет основного блока :root[data-skin="%s"]' % theme_id]
    have = set(re.findall(r'--([\w-]+)\s*:', block.group(1)))
    missing = [t for t in REQUIRED_TOKENS if t not in have]
    if missing:
        errors.append('в основном блоке нет токенов: ' + ', '.join(missing))
    return errors


def split_target(path):
    """Код до блока данных включительно и прежние данные."""
    text = read(path)
    pos = text.find(HALT)
    if pos < 0:
        fail('в %s нет строки «__halt_compiler();» — некуда писать данные' % path)
    end = pos + len(HALT)
    try:
        old = json.loads(text[end:])
    except ValueError:
        old = None
    return text, text[:end], old if isinstance(old, dict) else None


def encode(data):
    return json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n'


def write(path, text):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(text)


def github_output(**values):
    target = os.environ.get('GITHUB_OUTPUT')
    if target:
        with open(target, 'a', encoding='utf-8') as f:
            for key, value in values.items():
                f.write('%s=%s\n' % (key, value))


def main():
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, 'reconfigure'):
            stream.reconfigure(encoding='utf-8')

    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description='Темы каталога → блок данных index.php')
    parser.add_argument('--catalog', required=True, type=Path)
    parser.add_argument('--local', type=Path, default=root / 'themes')
    parser.add_argument('--target', type=Path, default=root / 'index.php')
    parser.add_argument('--base-skin', default='patch')
    parser.add_argument('--dist', type=Path)
    parser.add_argument('--zip', type=Path)
    parser.add_argument('--rev')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()

    catalog = load_catalog(args.catalog)
    if catalog['base'] is None:
        fail('в каталоге нет base.css')

    sources = dict(catalog['themes'])
    if args.local.is_dir():
        for file in sorted(args.local.glob('*.css')):
            print(('своя тема заменяет каталожную: %s' if file.stem in sources else 'своя тема: %s') % file.stem)
            sources[file.stem] = normalize(read(file))

    themes = {}
    for theme_id in sorted(sources):
        css = sources[theme_id]
        meta = parse_passport(css)
        errors = validate_theme(theme_id, css, meta)
        if errors:
            warn('тема %s пропущена: %s' % (theme_id, '; '.join(errors)))
            continue
        themes[theme_id] = {'meta': meta, 'css': css}
    if not themes:
        fail('ни одной годной темы')

    print('тем: %d (%s)' % (len(themes), ', '.join(themes)))
    if args.base_skin not in themes:
        fail('тема базовой редакции «%s» не собрана' % args.base_skin)

    def edition(kind, subset, lock=None):
        """Блок данных редакции: каркас и выбранные темы."""
        digest = hashlib.sha1((catalog['base'] + ''.join(t['css'] for t in subset.values())).encode('utf-8'))
        return {
            'format': 1,
            'edition': kind,
            'rev': args.rev,
            'hash': digest.hexdigest()[:12],
            'lock': lock,
            'base': catalog['base'],
            'themes': subset,
        }

    # Базовая редакция: одна тема без описания — переключатель светлая/тёмная.
    base_theme = themes[args.base_skin]
    base_meta = {k: v for k, v in base_theme['meta'].items() if k != 'description'}
    base = edition('base', {args.base_skin: {'meta': base_meta, 'css': base_theme['css']}}, args.base_skin)

    current, code, old = split_target(args.target)

    if args.check:
        # Ревизия каталога не делает файл устаревшим — сравниваем содержимое.
        same = current == code + '\n' + encode(dict(base, rev=(old or {}).get('rev')))
        print('index.php актуален' if same else 'index.php устарел: запустите tools/build.py')
        sys.exit(0 if same else 1)

    nxt = code + '\n' + encode(base)
    changed = nxt != current
    if changed:
        write(args.target, nxt)
        print('записан %s (базовая редакция, тема %s)' % (args.target, args.base_skin))
    else:
        print('%s не изменился' % args.target)

    # Редакции для релиза: тот же код, другие данные.
    everything = code + '\n' + encode(edition('all', themes))
    singles = {
        theme_id: code + '\n' + encode(edition('theme', {theme_id: theme}, theme_id))
        for theme_id, theme in themes.items()
    }

    if args.dist:
        write(args.dist / 'all-themes' / 'index.php', everything)
        for theme_id, text in singles.items():
            write(args.dist / 'themes' / theme_id / 'index.php', text)
        print('собрано в %s: все темы и %d с одной темой' % (args.dist, len(singles)))

    if args.zip:
        args.zip.mkdir(parents=True, exist_ok=True)
        options = {'compression': zipfile.ZIP_DEFLATED, 'compresslevel': 9}
        with zipfile.ZipFile(args.zip / 'folderRunner.zip', 'w', **options) as z:
            z.writestr('index.php', nxt)
        with zipfile.ZipFile(args.zip / 'folderRunner-all-themes.zip', 'w', **options) as z:
            z.writestr('index.php', everything)
        with zipfile.ZipFile(args.zip / 'folderRunner-single-themes.zip', 'w', **options) as pack:
            for theme_id, text in singles.items():
                with zipfile.ZipFile(args.zip / ('folderRunner-%s.zip' % theme_id), 'w', **options) as z:
                    z.writestr('index.php', text)
                pack.writestr('%s/index.php' % theme_id, text)
        print('архивы в %s: %d' % (args.zip, len(singles) + 3))

    github_output(themes=len(themes), ids=' '.join(themes), changed='true' if changed else 'false')


if __name__ == '__main__':
    main()

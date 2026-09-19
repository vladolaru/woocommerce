#!/usr/bin/env bash
set -euo pipefail
[[ "${E2E_TRANSITION_TEST_MODE:-}" == 1 ]] || exit 1
python3 - "$@" <<'PY'
import json
import os
from pathlib import Path
import stat
import sys
import zipfile

args = sys.argv[1:]
fault = os.environ.get('E2E_FAKE_WCS_FAULT', '')
with open(os.environ['E2E_FAKE_WCS_LOG'], 'a') as log:
    log.write(json.dumps(args) + '\n')
versions = {'node': 'v24.17.0', 'npm': '11.13.0', 'php': '8.5.6', 'composer': 'Composer version 2.9.5 2026-01-29 11:40:53', 'wp_cli': 'WP-CLI 2.6.0', 'python': 'Python 3.14.4'}
tool = args[0]
if tool == 'python3':
    tool = 'python'
if tool == 'php' and '-d' in args:
    tool = 'wp_cli'
if '--version' in args or (tool == 'php' and '-r' in args):
    if fault == 'probe-noise':
        print('unexpected warning', file=sys.stderr)
    print('0.0.0' if fault == 'tool-' + tool else versions[tool])
elif args == ['git', 'init', '--quiet']:
    pass
elif args == ['git', 'fetch', '--depth=1', '--no-tags', 'https://github.com/woocommerce/woocommerce-subscriptions.git', 'refs/tags/9.2.0']:
    pass
elif args == ['git', 'checkout', '--detach', 'FETCH_HEAD']:
    package = {'name': 'woocommerce-subscriptions', 'version': '9.2.0', 'engines': {'npm': '>=11.0.0', 'node': '>=24.0.0'},
        'scripts': {'build': 'npm run build:js && npm run makepot && node tasks/release.js && mv release/$npm_package_name.zip ./'}}
    if fault == 'version': package['version'] = '9.3.0'
    if fault == 'engines': package['engines']['node'] = '>=20'
    if fault == 'build-script': package['scripts']['build'] = 'unapproved'
    Path('package.json').write_text(json.dumps(package))
    Path('.nvmrc').write_text('20\n' if fault == 'node-line' else '24\n')
    Path('package-lock.json').write_text('wrong\n' if fault == 'npm-lock' else 'npm-lock\n')
    Path('composer.lock').write_text('wrong\n' if fault == 'composer-lock' else '{"packages":[{"name":"vendor/runtime","version":"1.2.3","source":{"reference":"approved-source"},"dist":{"reference":"approved-dist"}}],"packages-dev":[{"name":"vendor/dev","version":"4.5.6"}]}\n')
elif args == ['git', 'rev-parse', 'HEAD']:
    print('0' * 40 if fault == 'commit' else '4008f7f515f5ea76eea4d9149514b8c1774e51ba')
elif args == ['git', 'show', '-s', '--format=%ct', 'HEAD']:
    print('1' if fault == 'epoch' else '1788854213')
elif args == ['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund']:
    pass
elif args == ['npm', 'run', 'build']:
    assert os.environ['SOURCE_DATE_EPOCH'] == '1788854213'
    root = 'other/' if fault == 'wrong-root' else 'woocommerce-subscriptions/'
    with zipfile.ZipFile('woocommerce-subscriptions.zip', 'w') as archive:
        entries = {'z.txt': b'z\r\n', 'assets/a.js': b'compiled JavaScript\n'}
        packages = [
            {'name': 'automattic/jetpack-constants', 'version': 'v3.0.12', 'source': {'reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27'}, 'dist': {'reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27'}},
            {'name': 'composer/installers', 'version': 'v2.3.0', 'source': {'reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e'}, 'dist': {'reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e'}},
        ]
        if fault == 'dependency-version': packages[0]['version'] = 'v3.0.13'
        if fault == 'dependency-source': packages[0]['source']['reference'] = 'changed-source'
        if fault == 'dependency-dist': packages[0]['dist']['reference'] = 'changed-dist'
        if fault == 'dependency-reference-missing': del packages[0]['source']
        if fault == 'dependency-extra': packages.append({'name': 'vendor/extra', 'version': '1.0.0', 'source': {'reference': 'extra'}})
        if fault == 'dependency-duplicate': packages.append(packages[0].copy())
        if fault == 'dependency-missing': packages = []
        if fault != 'dependency-metadata-missing': entries['vendor/composer/installed.json'] = json.dumps({'packages': packages, 'dev': False, 'dev-package-names': []}).encode()
        if fault != 'missing-main': entries['woocommerce-subscriptions.php'] = ('<?php\n/**\n * Version: ' + ('9.3.0' if fault == 'main-version' else '9.2.0') + '\n */\n').encode()
        if fault != 'missing-pot': entries['languages/woocommerce-subscriptions.pot'] = ('msgid ""\nmsgstr ""\n"POT-Creation-Date: ' + os.environ.get('E2E_FAKE_WCS_POT_DATE', '2020-01-01 00:00+0000') + '\\n"\n').encode()
        if fault == 'escape': entries['../escaped'] = b'bad'
        if fault == 'git-metadata': entries['.git/config'] = b'bad'
        for name, contents in entries.items(): archive.writestr(root + name, contents)
        if fault in ('symlink', 'special'):
            info = zipfile.ZipInfo(root + 'unsafe')
            info.create_system = 3
            info.external_attr = ((stat.S_IFLNK if fault == 'symlink' else stat.S_IFIFO) | 0o777) << 16
            archive.writestr(info, b'target')
        if fault == 'absolute': archive.writestr('/escaped', b'bad')
        if fault == 'duplicate':
            import warnings
            with warnings.catch_warnings():
                warnings.simplefilter('ignore', UserWarning)
                archive.writestr(root + 'z.txt', b'again')
else:
    sys.exit('Unexpected fake release command: ' + repr(args))
PY

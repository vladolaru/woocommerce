#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
test -f "$SCRIPT_DIR/build-wcs-transition-artifact.sh" || {
	echo 'RED: build-wcs-transition-artifact.sh does not exist.' >&2
	exit 1
}
python3 - "$SCRIPT_DIR" <<'PY'
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tarfile
import tempfile

scripts = Path(sys.argv[1])
name = 'woocommerce-subscriptions-9.2.0-4008f7f515f5ea76eea4d9149514b8c1774e51ba'
sha = lambda data: hashlib.sha256(data).hexdigest()
composer_lock = b'{"packages":[{"name":"vendor/runtime","version":"1.2.3","source":{"reference":"approved-source"},"dist":{"reference":"approved-dist"}}],"packages-dev":[{"name":"vendor/dev","version":"4.5.6"}]}\n'
with tempfile.TemporaryDirectory(prefix='wcs-contract.', dir=os.environ['TMPDIR']) as temporary:
    root = Path(temporary)
    env = dict(os.environ, E2E_TRANSITION_TEST_MODE='1',
        E2E_TRANSITION_WCS_RELEASE_BUILDER=str(scripts / 'test-fixtures/fake-wcs-release-builder.sh'),
        E2E_TRANSITION_WCS_NPM_LOCK_SHA256=sha(b'npm-lock\n'),
        E2E_TRANSITION_WCS_COMPOSER_LOCK_SHA256=sha(composer_lock),
        E2E_FAKE_WCS_LOG=str(root / 'commands.jsonl'))

    def run(label, changes=None, output=None, success=False, diagnostic=None):
        destination = output or root / label
        if not destination.exists():
            destination.mkdir()
        result = subprocess.run(['bash', str(scripts / 'build-wcs-transition-artifact.sh'), '--output-dir', str(destination)],
            env=dict(env, **(changes or {})), capture_output=True, text=True)
        if success:
            assert result.returncode == 0, (label, result.stderr)
            assert not result.stderr, (label, 'successful build leaked stderr', result.stderr)
            assert sorted(p.name for p in destination.iterdir()) == [name + '.manifest.json', name + '.tar.gz'], 'missing exact source/build/manifest validation and paired artifacts'
            payload = json.loads(result.stdout)
            assert payload['archive_path'] == str(destination / (name + '.tar.gz'))
            assert payload['manifest_path'] == str(destination / (name + '.manifest.json'))
        else:
            assert result.returncode != 0, (label, 'unsafe input accepted')
            if diagnostic:
                assert diagnostic in result.stderr, (label, result.stderr)
        return destination

    first = run('first', success=True)
    second = run('second', {'E2E_FAKE_WCS_POT_DATE': '2030-12-25 23:59+0000'}, success=True)
    archive = (first / (name + '.tar.gz')).read_bytes()
    manifest_bytes = (first / (name + '.manifest.json')).read_bytes()
    assert archive == (second / (name + '.tar.gz')).read_bytes(), 'generated POT dates must be normalized'
    assert manifest_bytes == (second / (name + '.manifest.json')).read_bytes()
    manifest = json.loads(manifest_bytes)
    expected = {
        'schema_version': 1, 'plugin': 'woocommerce-subscriptions', 'plugin_version': '9.2.0',
        'main_file': 'woocommerce-subscriptions.php', 'main_file_version': '9.2.0',
        'repository': 'https://github.com/woocommerce/woocommerce-subscriptions.git', 'tag': '9.2.0',
        'source_commit': '4008f7f515f5ea76eea4d9149514b8c1774e51ba', 'source_date_epoch': 1788854213,
        'archive_format': 'tar.gz', 'archive_profile': 'wcs-transition-v1',
        'build_command': 'SOURCE_DATE_EPOCH=1788854213 npm run build',
        'install_command': 'npm ci --ignore-scripts --no-audit --no-fund',
        'source_lock_sha256': {'package-lock.json': sha(b'npm-lock\n'), 'composer.lock': sha(composer_lock)},
        'build_toolchain': {'node': 'v24.17.0', 'npm': '11.13.0', 'php': '8.5.6', 'composer': '2.9.5', 'wp_cli': '2.6.0', 'python': '3.14.4'},
        'pot_date_normalization': {'path': 'languages/woocommerce-subscriptions.pot', 'field': 'POT-Creation-Date', 'value': '2026-09-08 07:56+0000'},
        'production_composer_packages': {
            'automattic/jetpack-constants': {'version': 'v3.0.12', 'source_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27', 'dist_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27'},
            'composer/installers': {'version': 'v2.3.0', 'source_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e', 'dist_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e'},
        },
    }
    for key, value in expected.items():
        assert manifest[key] == value, (key, manifest[key], value)
    assert manifest['archive_sha256'] == sha(archive)
    assert manifest['canonical_tar_sha256'] == sha(gzip.decompress(archive))
    assert archive[3:8] == b'\0' * 5, 'gzip must omit filename and timestamp'
    records = []
    files = 0
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tar:
        members = tar.getmembers()
        assert [m.name for m in members] == sorted(m.name for m in members)
        assert members[0].name == 'woocommerce-subscriptions'
        for member in members:
            assert member.name == 'woocommerce-subscriptions' or member.name.startswith('woocommerce-subscriptions/')
            assert member.uid == member.gid == 0 and member.uname == member.gname == ''
            assert member.mtime == 1788854213 and member.mode == (0o555 if member.isdir() else 0o444)
            assert member.isdir() or member.isfile()
            if member.isfile():
                content = tar.extractfile(member).read()
                records.append(f'{sha(content)}  {member.name}\n')
                files += 1
                if member.name.endswith('.pot'):
                    assert b'POT-Creation-Date: 2026-09-08 07:56+0000\\n' in content
    assert manifest['file_count'] == files == 5
    assert manifest['canonical_tree_sha256'] == sha(''.join(records).encode())
    assert str(root).encode() not in manifest_bytes
    for path in first.iterdir():
        assert stat.S_IMODE(path.stat().st_mode) == 0o444
    log = [json.loads(line) for line in (root / 'commands.jsonl').read_text().splitlines()]
    assert ['git', 'fetch', '--depth=1', '--no-tags', 'https://github.com/woocommerce/woocommerce-subscriptions.git', 'refs/tags/9.2.0'] in log
    assert ['git', 'checkout', '--detach', 'FETCH_HEAD'] in log
    assert ['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund'] in log
    assert ['npm', 'run', 'build'] in log
    for fault in ['dependency-version', 'dependency-source', 'dependency-dist', 'dependency-missing', 'dependency-extra', 'dependency-duplicate', 'dependency-reference-missing', 'dependency-metadata-missing']:
        destination = run(fault, {'E2E_FAKE_WCS_FAULT': fault}, diagnostic='production Composer')
        assert not list(destination.iterdir()), (fault, 'dependency drift published artifacts')
    for fault in ['commit', 'epoch', 'version', 'engines', 'build-script', 'node-line', 'npm-lock', 'composer-lock', 'main-version', 'missing-main', 'symlink', 'special', 'escape', 'absolute', 'duplicate', 'git-metadata', 'wrong-root', 'missing-pot']:
        destination = run(fault, {'E2E_FAKE_WCS_FAULT': fault})
        assert not list(destination.iterdir()), (fault, 'failed build published partial artifacts')
    for tool in ['node', 'npm', 'php', 'composer', 'wp_cli', 'python']:
        run('drift-' + tool, {'E2E_FAKE_WCS_FAULT': 'tool-' + tool}, diagnostic='tool version')
    run('probe-noise', {'E2E_FAKE_WCS_FAULT': 'probe-noise'}, diagnostic='version probe')
    run('reuse', output=first)
    dirty = root / 'dirty'
    dirty.mkdir()
    (dirty / 'sentinel').write_text('keep')
    run('dirty', output=dirty)
    assert (dirty / 'sentinel').read_text() == 'keep'
    link = root / 'link'
    link.symlink_to(second, target_is_directory=True)
    run('link', output=link)
    run('normal-override', {'E2E_TRANSITION_TEST_MODE': '0'}, diagnostic='test mode')
    run('unknown-override', {'E2E_TRANSITION_WCS_REPO': '/unapproved'}, diagnostic='override')
print('WCS artifact contracts passed (canonical pair, provenance, toolchain, unsafe inputs, test-only seams).')
PY

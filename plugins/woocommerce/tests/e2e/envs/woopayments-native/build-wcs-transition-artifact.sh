#!/usr/bin/env bash
set -euo pipefail
python3 - "$@" <<'PY'
import datetime
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import zipfile

REPOSITORY = 'https://github.com/woocommerce/woocommerce-subscriptions.git'
COMMIT = '4008f7f515f5ea76eea4d9149514b8c1774e51ba'
EPOCH = 1788854213
PLUGIN = 'woocommerce-subscriptions'
VERSION = '9.2.0'
BUILD_SCRIPT = 'npm run build:js && npm run makepot && node tasks/release.js && mv release/$npm_package_name.zip ./'
TOOLS = {'node': 'v24.17.0', 'npm': '11.13.0', 'php': '8.5.6', 'composer': '2.9.5', 'wp_cli': '2.6.0', 'python': '3.14.4'}
LOCKS = {'package-lock.json': '4fc6359a0b96606110e1659a3b1ee21834b29b42b65861dbf67ccdc2ac3bda58',
    'composer.lock': 'c0c4eeaebc873f03350e26e5e3821d4dba3fca1b663ae0cdc6dc72273f48f9eb'}
# The official release install omits composer.lock. Pin its approved resolution separately from source provenance.
PRODUCTION_COMPOSER_PACKAGES = {
    'automattic/jetpack-constants': {'version': 'v3.0.12', 'source_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27', 'dist_reference': '672be0a51baadfc6eee0ffd3bf8e9db691a8ab27'},
    'composer/installers': {'version': 'v2.3.0', 'source_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e', 'dist_reference': '12fb2dfe5e16183de69e784a7b84046c43d97e8e'},
}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def build():
    require(len(sys.argv) == 3 and sys.argv[1] == '--output-dir', 'Requires only --output-dir DIR.')
    test_mode = os.environ.get('E2E_TRANSITION_TEST_MODE') == '1'
    seams = {'E2E_TRANSITION_WCS_RELEASE_BUILDER', 'E2E_TRANSITION_WCS_NPM_LOCK_SHA256', 'E2E_TRANSITION_WCS_COMPOSER_LOCK_SHA256'}
    for key in os.environ:
        if key.startswith('E2E_TRANSITION_WCS_'):
            require(key in seams, 'Unrecognized WCS override: ' + key)
            require(test_mode, 'WCS overrides require explicit test mode.')
    fake = os.environ.get('E2E_TRANSITION_WCS_RELEASE_BUILDER') if test_mode else None
    if fake:
        require(Path(fake).is_file() and not Path(fake).is_symlink(), 'Invalid test release builder.')
        LOCKS['package-lock.json'] = os.environ['E2E_TRANSITION_WCS_NPM_LOCK_SHA256']
        LOCKS['composer.lock'] = os.environ['E2E_TRANSITION_WCS_COMPOSER_LOCK_SHA256']
    temporary_input = Path(os.path.abspath(os.environ['TMPDIR']))
    temporary_root = temporary_input.resolve(strict=True)
    output = Path(os.path.abspath(sys.argv[2]))
    require(output.is_dir() and not output.is_symlink(), 'Output must be an existing empty directory, not a symlink.')
    relative_output = output.relative_to(temporary_input)
    require(relative_output.parts and output.resolve() == temporary_root / relative_output
        and not any(parent.is_symlink() for parent in [output, *output.parents] if temporary_input in parent.parents),
        'Output must be a nonsymlink directory below TMPDIR.')
    require(output.stat().st_uid == os.getuid() and not list(output.iterdir()), 'Output must be caller-owned and empty; refusing reuse.')
    output_identity = (output.stat().st_dev, output.stat().st_ino)
    with tempfile.TemporaryDirectory(prefix='wcs-transition-build.', dir=temporary_root) as staging:
        work = Path(staging)
        source = work / 'source'
        source.mkdir()
        private_home = work / 'home'
        private_home.mkdir()
        environment = {'PATH': os.environ['PATH'], 'HOME': str(private_home), 'TMPDIR': str(work),
            'LC_ALL': 'C', 'TZ': 'UTC', 'SOURCE_DATE_EPOCH': str(EPOCH), 'CI': '1',
            'COMPOSER_NO_INTERACTION': '1', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null',
            'GIT_NO_REPLACE_OBJECTS': '1'}
        if fake:
            environment.update({k: v for k, v in os.environ.items() if k.startswith('E2E_')})

        def command(args, probe=False):
            actual = ['bash', fake, *args] if fake else args
            command_environment = environment.copy()
            if not fake and args[0] == 'git':
                actual = ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.autocrlf=false', *args[1:]]
                if args[1] == 'fetch':
                    # Credential resolution is needed for this repository; only fetch sees the caller's Git config.
                    command_environment['HOME'] = os.environ['HOME']
                    command_environment.pop('GIT_CONFIG_GLOBAL')
                    if 'XDG_CONFIG_HOME' in os.environ:
                        command_environment['XDG_CONFIG_HOME'] = os.environ['XDG_CONFIG_HOME']
            result = subprocess.run(actual, cwd=source, env=command_environment, capture_output=True, text=True)
            require(result.returncode == 0, 'Command failed: ' + ' '.join(args) + '\n' + result.stderr[-6000:] + result.stdout[-2000:])
            if probe:
                # Composer reports PHP runtime information on stderr even for --version.
                composer_info = args[0] == 'composer' and re.fullmatch(
                    r'PHP version 8\.5\.6 \([^\n]+\)\nRun the "diagnose" command to get more detailed diagnostics output\.\n', result.stderr)
                require(not result.stderr or composer_info, 'Unexpected stderr from version probe: ' + result.stderr)
            return result.stdout.strip()

        wp = shutil.which('wp')
        require(wp is not None or fake, 'WP-CLI is required.')
        probes = {'node': ['node', '--version'], 'npm': ['npm', '--version'],
            'php': ['php', '-r', 'echo PHP_VERSION;'], 'composer': ['composer', '--version', '--no-ansi'],
            # Only this probe suppresses PHP 8.5 deprecations in the pinned WP-CLI 2.6.0.
            'wp_cli': ['php', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', wp or 'wp', '--version'],
            'python': ['python3', '--version']}
        observed = {}
        for tool, args in probes.items():
            version = command(args, probe=True)
            if tool == 'composer':
                match = re.fullmatch(r'Composer version (\S+) \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}', version)
                version = match.group(1) if match else version
            elif tool == 'wp_cli':
                version = version.removeprefix('WP-CLI ')
            elif tool == 'python':
                version = version.removeprefix('Python ')
            require(version == TOOLS[tool], 'Unexpected ' + tool + ' tool version: ' + version)
            observed[tool] = version
        command(['git', 'init', '--quiet'])
        command(['git', 'fetch', '--depth=1', '--no-tags', REPOSITORY, 'refs/tags/9.2.0'])
        command(['git', 'checkout', '--detach', 'FETCH_HEAD'])
        require(command(['git', 'rev-parse', 'HEAD']) == COMMIT, 'Tag does not resolve to the approved commit.')
        require(command(['git', 'show', '-s', '--format=%ct', 'HEAD']) == str(EPOCH), 'Source commit date mismatch.')
        for filename in ['package.json', '.nvmrc', *LOCKS]:
            require((source / filename).is_file() and not (source / filename).is_symlink(), 'Missing regular source metadata: ' + filename)
        package = json.loads((source / 'package.json').read_text())
        require(package.get('name') == PLUGIN and package.get('version') == VERSION, 'Source package version mismatch.')
        require(package.get('engines') == {'npm': '>=11.0.0', 'node': '>=24.0.0'}, 'Source engines mismatch.')
        require(package.get('scripts', {}).get('build') == BUILD_SCRIPT, 'Source official build script mismatch.')
        require((source / '.nvmrc').read_text().strip() == '24', 'Source Node line mismatch.')
        for filename, expected in LOCKS.items():
            require(digest((source / filename).read_bytes()) == expected, 'Source lock hash mismatch: ' + filename)
        command(['npm', 'ci', '--ignore-scripts', '--no-audit', '--no-fund'])
        command(['npm', 'run', 'build'])
        release = source / (PLUGIN + '.zip')
        require(release.is_file() and not release.is_symlink(), 'Official build did not produce its release ZIP.')
        tree = work / 'tree'
        tree.mkdir()
        seen = set()
        with zipfile.ZipFile(release) as archive:
            for member in archive.infolist():
                name = member.filename
                path = PurePosixPath(name)
                require(name and '\\' not in name and not any(ord(c) < 32 or ord(c) == 127 for c in name), 'Unsafe archive path.')
                require(not path.is_absolute() and all(part not in ('', '.', '..', '.git') for part in name.rstrip('/').split('/')),
                    'Unsafe archive member: ' + name)
                require(path.parts[0] == PLUGIN and (len(path.parts) > 1 or member.is_dir()), 'Unexpected release root.')
                require(str(path) not in seen, 'Duplicate archive member.')
                seen.add(str(path))
                kind = stat.S_IFMT(member.external_attr >> 16)
                require(kind in (0, stat.S_IFDIR if member.is_dir() else stat.S_IFREG), 'Symlink or special archive member.')
                destination = tree.joinpath(*path.parts)
                if member.is_dir():
                    destination.mkdir(parents=True, exist_ok=True)
                else:
                    destination.parent.mkdir(parents=True, exist_ok=True)
                    with destination.open('xb') as stream:
                        stream.write(archive.read(member))
        plugin = tree / PLUGIN
        main = plugin / (PLUGIN + '.php')
        require(main.is_file(), 'Missing release main file.')
        versions = re.findall(r'^\s*\*\s*Version:\s*(\S+)\s*$', main.read_text(), re.MULTILINE)
        require(versions == [VERSION], 'Release main-file version mismatch.')
        installed_file = plugin / 'vendor/composer/installed.json'
        require(installed_file.is_file(), 'Missing production Composer metadata.')
        installed = json.loads(installed_file.read_text())
        require(isinstance(installed, dict) and isinstance(installed.get('packages'), list), 'Invalid production Composer metadata.')
        installed_packages = {}
        for package in installed['packages']:
            require(isinstance(package, dict) and isinstance(package.get('name'), str), 'Invalid production Composer package.')
            name = package['name']
            require(name not in installed_packages, 'Duplicate production Composer package.')
            identity = {'version': package.get('version')}
            for kind in ('source', 'dist'):
                reference = package.get(kind)
                require(isinstance(reference, dict) and isinstance(reference.get('reference'), str), 'Missing production Composer reference.')
                identity[kind + '_reference'] = reference['reference']
            installed_packages[name] = identity
        require(installed_packages == PRODUCTION_COMPOSER_PACKAGES, 'Resolved production Composer packages differ from the approved release map.')
        pot_path = 'languages/woocommerce-subscriptions.pot'
        pot = plugin / pot_path
        require(pot.is_file(), 'Missing generated POT file.')
        pot_date = datetime.datetime.fromtimestamp(EPOCH, datetime.timezone.utc).strftime('%Y-%m-%d %H:%M%z')
        normalized, count = re.subn(rb'(?m)^"POT-Creation-Date: [^"\r\n]*\\n"$',
            lambda _: ('"POT-Creation-Date: ' + pot_date + '\\n"').encode(), pot.read_bytes())
        require(count == 1, 'Expected exactly one generated POT-Creation-Date field.')
        pot.write_bytes(normalized)
        canonical = work / 'canonical.tar'
        records = []
        with tarfile.open(canonical, 'w', format=tarfile.PAX_FORMAT) as archive:
            for path in sorted([plugin, *plugin.rglob('*')], key=lambda item: item.relative_to(tree).as_posix()):
                name = path.relative_to(tree).as_posix()
                info = tarfile.TarInfo(name)
                info.mtime = EPOCH
                info.uid = info.gid = 0
                info.uname = info.gname = ''
                if path.is_dir():
                    info.type = tarfile.DIRTYPE
                    info.mode = 0o555
                    archive.addfile(info)
                else:
                    content = path.read_bytes()
                    info.mode = 0o444
                    info.size = len(content)
                    archive.addfile(info, io.BytesIO(content))
                    records.append(digest(content) + '  ' + name + '\n')
        name = PLUGIN + '-' + VERSION + '-' + COMMIT
        prepared = work / 'prepared'
        prepared.mkdir()
        archive_path = prepared / (name + '.tar.gz')
        with archive_path.open('wb') as raw:
            with gzip.GzipFile(filename='', fileobj=raw, mode='wb', compresslevel=9, mtime=0) as zipped:
                with canonical.open('rb') as source_tar:
                    shutil.copyfileobj(source_tar, zipped)
        manifest = {'schema_version': 1, 'plugin': PLUGIN, 'plugin_version': VERSION,
            'main_file': PLUGIN + '.php', 'main_file_version': VERSION,
            'repository': REPOSITORY, 'tag': VERSION, 'source_commit': COMMIT, 'source_date_epoch': EPOCH,
            'source_lock_sha256': LOCKS, 'build_toolchain': observed,
            'production_composer_packages': installed_packages,
            'install_command': 'npm ci --ignore-scripts --no-audit --no-fund',
            'build_command': 'SOURCE_DATE_EPOCH=1788854213 npm run build',
            'pot_date_normalization': {'path': pot_path, 'field': 'POT-Creation-Date', 'value': pot_date},
            'archive_format': 'tar.gz', 'archive_profile': 'wcs-transition-v1',
            'file_count': len(records), 'canonical_tree_sha256': digest(''.join(records).encode()),
            'canonical_tar_sha256': digest(canonical.read_bytes()), 'archive_sha256': digest(archive_path.read_bytes())}
        manifest_path = prepared / (name + '.manifest.json')
        manifest_path.write_text(json.dumps(manifest, sort_keys=True, indent=2) + '\n')
        for path in prepared.iterdir():
            path.chmod(0o444)
        require(not output.is_symlink() and (output.stat().st_dev, output.stat().st_ino) == output_identity and not list(output.iterdir()),
            'Output changed while building; refusing publication.')
        # Rename the prepared directory over the still-empty caller directory: the pair appears together.
        prepared.chmod(stat.S_IMODE(output.stat().st_mode))
        os.replace(prepared, output)
        print(json.dumps({'archive_path': str(output / archive_path.name), 'manifest_path': str(output / manifest_path.name),
            'archive_sha256': manifest['archive_sha256'], 'manifest_sha256': digest((output / manifest_path.name).read_bytes())}))


try:
    build()
except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
    sys.exit('WCS artifact build failed: ' + str(error))
PY

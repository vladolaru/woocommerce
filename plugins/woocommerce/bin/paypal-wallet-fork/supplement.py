#!/usr/bin/env python3
"""Supplement to fork.php.

fork.php copied only the kept modules. Kept code could reach a few classes of dropped modules (gateway ID constants,
helpers, one trait, two JS files), so this script added them under the extension's own namespace layout. Plan B cut
every one of those readers and deleted the files, so the lists below are empty. Re-running the script changes nothing;
re-adding an entry would bring the file back, so add one only for a reader that is meant to stay.

* STUBS: gateway classes that kept code touches only through `X::ID`; written as final classes carrying that constant.
* COPIES: classes with behavior kept code executes; copied with fork.php's rewrite rules.
* JS_COPIES: files of a dropped module's JS that kept JS imports, copied into the JS package.

Every file it writes is recorded in path-map.json (extension path -> core path).

Usage: supplement.py <extension clone> <core clone>   (idempotent)
"""
import json, os, re, sys
from pathlib import Path

if len(sys.argv) < 3 or sys.argv[1] in ("-h", "--help"):
    print(__doc__)
    sys.exit(0 if len(sys.argv) > 1 else 1)

EXT, CORE = sys.argv[1].rstrip('/'), sys.argv[2].rstrip('/')
WALLET = CORE + '/plugins/woocommerce/src/Internal/Payments/Providers/PayPal/Wallet'
NS_NEW = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\PayPal\\Wallet'
MODULE_DIR = {}  # first segment of a STUBS/COPIES name -> the extension module directory

STUBS = []
COPIES = []
JS_COPIES = []  # paths under the extension's modules/ directory, for example 'ppcp-card-fields/resources/js/Render.js'


def source_path(fqcn):
    segment, rest = fqcn.split('\\', 1)
    return '%s/modules/%s/src/%s.php' % (EXT, MODULE_DIR[segment], rest.replace('\\', '/'))


def rewrite(src):
    old = 'WooCommerce\\PayPalCommerce\\'
    src = src.replace(old.replace('\\', '\\\\'), NS_NEW.replace('\\', '\\\\') + '\\\\').replace(old, NS_NEW + '\\')
    src = re.sub(r'(namespace |@package )WooCommerce\\PayPalCommerce(?=;|\s*$)', lambda m: m.group(1) + NS_NEW, src, flags=re.M)
    src = re.sub(r'(?<![A-Za-z0-9_\\])(\\?)Psr\\Log\\', lambda m: m.group(1) + 'Automattic\\WooCommerce\\Vendor\\Psr\\Log\\', src)
    out = []
    for line in src.split('\n'):
        if 'set_source(' not in line:
            line = line.replace("'woocommerce-paypal-payments'", "'woocommerce'").replace('"woocommerce-paypal-payments"', '"woocommerce"')
        out.append(line)
    return '\n'.join(out)


def target_path(fqcn):
    return '%s/%s.php' % (WALLET, fqcn.replace('\\', '/'))


for fqcn in STUBS:
    src = open(source_path(fqcn)).read()
    value = re.search(r"const ID\s*=\s*'([^']+)'", src).group(1)
    ns, cls = fqcn.rsplit('\\', 1)
    path = target_path(fqcn)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w').write("""<?php
/**
 * %(cls)s contract stub.
 *
 * @package %(ns_full)s
 */

declare( strict_types = 1 );

namespace %(ns_full)s;

/**
 * Carries only the gateway ID, which kept wallet code still compares and keys settings by.
 *
 * The gateway itself belongs to a feature the wallet does not ship. Remove this stub together with the last
 * reference to it when the feature's branches are cut.
 *
 * @since 11.3.0
 * @internal
 */
final class %(cls)s {

	/**
	 * The gateway ID, as the extension registers it.
	 */
	public const ID = '%(value)s';
}
""" % {'cls': cls, 'ns_full': NS_NEW + '\\' + ns, 'value': value})
    print('stub', fqcn, value)

for fqcn in COPIES:
    path = target_path(fqcn)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w').write(rewrite(open(source_path(fqcn)).read()))
    print('copy', fqcn)

# JS: files of a dropped module that kept JS imports.
CLIENT = CORE + '/plugins/woocommerce/client/paypal-wallet/modules'
for rel in JS_COPIES:
    dst = '%s/%s' % (CLIENT, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w').write(open('%s/modules/%s' % (EXT, rel)).read())
    print('js copy', rel)

# Path map: record what this script added (nothing to add while the lists are empty).
if not (STUBS or COPIES or JS_COPIES):
    print('nothing to supplement')
    sys.exit(0)
pm_path = str(Path(__file__).resolve().parent / 'path-map.json')
pm = json.load(open(pm_path))
core_rel = lambda p: p[len(CORE) + 1:]
for fqcn in STUBS + COPIES:
    pm[source_path(fqcn)[len(EXT) + 1:]] = core_rel(target_path(fqcn))
for rel in JS_COPIES:
    pm['modules/' + rel] = core_rel('%s/%s' % (CLIENT, rel))
json.dump(dict(sorted(pm.items())), open(pm_path, 'w'), indent=4)
open(pm_path, 'a').write('\n')
print('path-map entries:', len(pm))

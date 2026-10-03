#!/usr/bin/env python3
"""Supplement to fork.php.

fork.php copied only the 19 kept modules. Kept code still reaches a handful of classes in dropped modules
(gateway ID constants, three PropertiesDictionary helpers, TrackingAvailabilityTrait, the Pay upon Invoice helper
and product status). The vendored mirror had them for free; the fork does not. This script adds them, under the
extension's own namespace layout so no `use` line changes:

* STUBS: gateway classes that kept code touches only through `X::ID`; written as final classes carrying that constant.
* COPIES: classes with behavior kept code executes; copied with fork.php's rewrite rules.

Also copies the one dropped module whose JS kept JS imports (ppcp-card-fields: Render.js, CardFieldsHelper.js)
into the JS package, and records every file it writes in path-map.json (extension path -> core path).

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
MODULE_DIR = {
    'Applepay': 'ppcp-applepay',
    'Googlepay': 'ppcp-googlepay',
    'Axo': 'ppcp-axo',
    'LocalAlternativePaymentMethods': 'ppcp-local-alternative-payment-methods',
    'OrderTracking': 'ppcp-order-tracking',
    'PayPalSubscriptions': 'ppcp-paypal-subscriptions',
}

STUBS = [
    'Applepay\\ApplePayGateway',
    'Axo\\Gateway\\AxoGateway',
    'Googlepay\\GooglePayGateway',
    'LocalAlternativePaymentMethods\\BancontactGateway',
    'LocalAlternativePaymentMethods\\BlikGateway',
    'LocalAlternativePaymentMethods\\EPSGateway',
    'LocalAlternativePaymentMethods\\IDealGateway',
    'LocalAlternativePaymentMethods\\MultibancoGateway',
    'LocalAlternativePaymentMethods\\MyBankGateway',
    'LocalAlternativePaymentMethods\\OXXOGateway',
    'LocalAlternativePaymentMethods\\P24Gateway',
    'LocalAlternativePaymentMethods\\PWCGateway',
    'LocalAlternativePaymentMethods\\TrustlyGateway',
    'LocalAlternativePaymentMethods\\PayUponInvoice\\PayUponInvoiceGateway',
]
COPIES = [
    'Applepay\\Assets\\PropertiesDictionary',
    'Googlepay\\Helper\\PropertiesDictionary',
    'Axo\\Helper\\PropertiesDictionary',
    'OrderTracking\\TrackingAvailabilityTrait',
    'LocalAlternativePaymentMethods\\PayUponInvoice\\PayUponInvoiceHelper',
    'LocalAlternativePaymentMethods\\PayUponInvoice\\PayUponInvoiceProductStatus',
]


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
 * reference to it when the feature's branches are cut (plan B).
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

# JS: the card-fields module is imported by kept JS (save-payment-methods, button); copy its two files.
CLIENT = CORE + '/plugins/woocommerce/client/paypal-wallet/modules'
JS_COPIES = ['ppcp-card-fields/resources/js/Render.js', 'ppcp-card-fields/resources/js/CardFieldsHelper.js']
for rel in JS_COPIES:
    dst = '%s/%s' % (CLIENT, rel)
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w').write(open('%s/modules/%s' % (EXT, rel)).read())
    print('js copy', rel)

# Path map: record what this script added.
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

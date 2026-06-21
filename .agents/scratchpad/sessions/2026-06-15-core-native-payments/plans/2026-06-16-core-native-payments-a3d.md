---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-16 16:11
tool: writing-plans
last_updated: 2026-06-16 16:30
reconciles:
  - implementation-log.md
status: final
---

# A3d WooPayments Native Checkout Browser Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a core-owned WooPayments card checkout surface for classic checkout and Checkout Blocks, including Core-owned config/asset registration and shopper-side confirmation handling, while explicitly bridging the remaining zero-total and post-confirmation server callbacks through the existing WooPayments runtime until those contracts are ported natively.

**Architecture:** Introduce a provider-scoped checkout bridge in Core that owns native gateway field rendering, JS config generation, Stripe asset registration, and Blocks payment-method registration for the main `woocommerce_payments` card gateway. Move the shopper-side card path into Core-owned classic and Blocks scripts, but keep the still-missing server callbacks honest: use the existing WooPayments runtime only for setup-intent creation and post-confirmation order-status sync, and leave `WooPaymentsProvider::can_process_payments()` fail-closed because alternate UPE gateways, express checkout, WooPay, and full cutover readiness are still outstanding.

**Tech Stack:** WooCommerce Core PHP, classic frontend JS under `client/legacy`, Blocks payment-method JS under `client/blocks`, Stripe.js v3, PHPUnit, Jest, PHPStan, ESLint, PHPCS.

---

### Task 1: Lock the Checkout Bridge Contract With RED Tests

**Files:**
- Create: `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php`
- Create: `plugins/woocommerce/tests/php/src/Blocks/Payments/Integrations/WooPaymentsTest.php`
- Modify: `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`
- Create: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js`

- [x] **Step 1: Add RED PHP coverage for checkout config and field rendering**

Create `WooPaymentsCheckoutBridgeTest.php` with focused coverage for:

```php
public function test_get_payment_fields_js_config_preserves_card_checkout_shape(): void;
public function test_payment_fields_enqueues_core_owned_assets_and_preserves_wcpay_config_filter(): void;
public function test_get_payment_fields_js_config_exposes_legacy_bridge_nonces_only_when_runtime_is_loaded(): void;
```

Minimum assertions:
- config includes `publishableKey`, `accountId`, `locale`, `gatewayId`, `paymentMethodsConfig`, `enabledBillingFields`, `currency`, and `cartTotal`
- config stays filtered through `wcpay_payment_fields_js_config`
- the bridge exposes only the main card method for this slice
- legacy-bridge values are explicit, not implicit: `createSetupIntentNonce` and `updateOrderStatusNonce` are present only when the plugin runtime can actually serve them

- [x] **Step 2: Add RED gateway coverage for native payment-fields delegation**

Extend `NativeWooPaymentsGatewayTest.php` with:

```php
public function test_payment_fields_delegate_to_checkout_bridge(): void;
public function test_payment_fields_fall_back_safely_when_bridge_is_unavailable(): void;
```

Required assertions:
- the gateway still preserves `has_fields = true`
- `payment_fields()` delegates through the injected checkout bridge instead of embedding direct markup
- direct construction still resolves bridge dependencies from the container

- [x] **Step 3: Add RED Blocks integration coverage**

Create `WooPaymentsTest.php` for a new Blocks payment-method integration class with cases for:

```php
public function test_is_active_requires_native_runtime_and_checkout_bridge_readiness(): void;
public function test_get_payment_method_script_handles_registers_core_owned_woopayments_blocks_script(): void;
public function test_get_payment_method_data_uses_checkout_bridge_config(): void;
```

The script-handle test should prove the integration registers a dedicated Core asset, not a WooPayments plugin script handle.

- [x] **Step 4: Add RED JS coverage for the Blocks card bridge**

Create `client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js` with focused cases for:

```js
it( 'submits wcpay-payment-method metadata for a new card method' );
it( 'returns a payment notice when Stripe element validation fails' );
it( 'confirms #wcpay-confirm-pi redirects through Stripe.js before returning success' );
```

Mock the checkout event emitters and `window.Stripe` so the test proves the Core-owned script emits the same `paymentMethodData` keys the server already parses:
- `wcpay-payment-method`
- `wcpay-payment-method-error-code`
- `wcpay-payment-method-error-message`
- `wcpay-fingerprint`

- [x] **Step 5: Run the RED suites**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCheckoutBridgeTest|NativeWooPaymentsGatewayTest|WooPaymentsTest'
pnpm --filter='@woocommerce/block-library' test:js -- extensions/payment-methods/woopayments/test/index.js
```

Expected RED: missing checkout bridge, missing Blocks integration, and no Core-owned Blocks card bridge script.

### Task 2: Build the Core-Owned Checkout Bridge

**Files:**
- Create: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php`
- Modify: `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`
- Modify: `plugins/woocommerce/includes/class-woocommerce.php`
- Create: `plugins/woocommerce/src/Blocks/Payments/Integrations/WooPayments.php`
- Modify: `plugins/woocommerce/src/Blocks/Payments/Api.php`
- Modify: `plugins/woocommerce/src/Blocks/Domain/Bootstrap.php`

- [x] **Step 1: Add the provider-scoped checkout bridge service**

Create `WooPaymentsCheckoutBridge` with a narrow public contract:

```php
public function should_expose_checkout_surface(): bool;
public function get_payment_fields_js_config(): array;
public function render_payment_fields(): void;
public function get_blocks_payment_method_data(): array;
public function register_classic_assets(): void;
```

Bridge rules for this slice:
- only expose the main card gateway, not alternate `woocommerce_payments_*` UPE gateways
- preserve `wcpay_payment_fields_js_config`
- preserve the posted field names Core already parses
- emit explicit bridge flags such as `usesLegacySetupIntentBridge` and `usesLegacyOrderStatusBridge`

- [x] **Step 2: Extend the legacy runtime with the checkout reads Core needs**

Add safe accessors on `WooPaymentsLegacyRuntime` for the values the bridge cannot synthesize yet:

```php
public function get_gateway_publishable_key(): ?string;
public function get_gateway_account_id(): ?string;
public function get_gateway_upe_enabled_payment_method_ids(): array;
public function get_gateway_prepared_customer_data(): array;
public function can_handle_checkout_bridge_callbacks(): bool;
```

Do not reach into plugin globals from the bridge directly. Keep all legacy reads centralized here.

- [x] **Step 3: Delegate native gateway payment fields to the bridge**

Update `NativeWooPaymentsGateway` so it receives the checkout bridge through `init()` and implements:

```php
public function payment_fields() {
    $this->get_checkout_bridge()->render_payment_fields();
}
```

Keep the failure mode explicit:
- if the bridge cannot expose the shopper surface, render a short merchant-safe fallback message instead of half-rendered payment UI
- do not change `process_payment()` in this task

- [x] **Step 4: Register the Blocks payment-method integration**

Create `src/Blocks/Payments/Integrations/WooPayments.php` similar to the built-in integrations, but fed by `WooPaymentsCheckoutBridge`.

Required behavior:
- `get_name()` returns `woocommerce_payments`
- `is_active()` requires runtime ownership plus bridge surface readiness
- `get_payment_method_script_handles()` registers `assets/client/blocks/wc-payment-method-woopayments.js`
- `get_payment_method_data()` returns the card-only bridge config

Wire it into the Blocks container/bootstrap and `Payments\Api::register_payment_method_integrations()`.

### Task 3: Add Core-Owned Classic and Blocks Card Assets

**Files:**
- Create: `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js`
- Create: `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js`
- Modify: `plugins/woocommerce/client/blocks/bin/webpack-entries.js`

- [x] **Step 1: Add the classic shopper bridge**

Create `client/legacy/js/frontend/woopayments-checkout.js` as a plain frontend script that:
- exits unless the selected gateway is `woocommerce_payments` and bridge config exists
- loads `window.Stripe( publishableKey, { stripeAccount: accountId, locale } )`
- mounts a card Payment Element into the Core-owned placeholder markup
- appends the POST fields Core already understands on submit
- handles `#wcpay-confirm-pi:` redirects via Stripe.js and then calls the legacy `update_order_status` AJAX bridge when configured

For this slice, only support the main card method and saved-card toggle. Do not attempt express buttons, WooPay, or alternate UPE gateways.

- [x] **Step 2: Add the Blocks card bridge**

Create `client/blocks/assets/js/extensions/payment-methods/woopayments/index.js` that registers the `woocommerce_payments` payment method and:
- mounts a card Payment Element with `window.Stripe`
- returns `paymentMethodData` containing `wcpay-payment-method` and related error fields
- runs Stripe confirmation on `onCheckoutSuccess` when the server returns `#wcpay-confirm-pi:...`
- uses the explicit legacy order-status bridge only when that flag is enabled in the PHP config

- [x] **Step 3: Add the Blocks build entry**

Extend `client/blocks/bin/webpack-entries.js` with:

```js
'wc-payment-method-woopayments':
	'./assets/js/extensions/payment-methods/woopayments/index.js',
```

Do not add new Stripe React dependencies in this slice. Keep the shopper bridge on top of global `stripe.js` to avoid broad package churn while the scope is still card-only.

### Task 4: Verification, Logging, and Commit

**Files:**
- Create: `plugins/woocommerce/changelog/add-native-payments-a3d-checkout-browser-bridge`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-a3d.md`
- Modify: `.agents/scratchpad/sessions/2026-06-15-core-native-payments/README.md`
- Modify: `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/staging-log.md`

- [x] **Step 1: Add the changelog**

Create:

```text
plugins/woocommerce/changelog/add-native-payments-a3d-checkout-browser-bridge
```

with:

```text
Significance: patch
Type: dev
Comment: Add a core-owned WooPayments card checkout browser bridge for classic checkout and Checkout Blocks.
```

- [x] **Step 2: Run focused verification**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsCheckoutBridgeTest|NativeWooPaymentsGatewayTest|WooPaymentsTest|WooPaymentsProviderTest|NativePaymentsGatewayRegistryTest'
pnpm --filter='@woocommerce/block-library' test:js -- extensions/payment-methods/woopayments/test/index.js
php -l plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php
php -l plugins/woocommerce/src/Blocks/Payments/Integrations/WooPayments.php
composer exec -- phpstan analyse src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php src/Blocks/Payments/Integrations/WooPayments.php src/Internal/Payments/NativeWooPaymentsGateway.php --memory-limit=2G
git diff --check
```

- [x] **Step 3: Run JS lint and branch lint**

Run:

```bash
pnpm --filter='@woocommerce/block-library' lint:lang:js -- assets/js/extensions/payment-methods/woopayments/index.js assets/js/extensions/payment-methods/woopayments/test/index.js
pnpm --filter='@woocommerce/classic-assets' lint:lang:js -- js/frontend/woopayments-checkout.js
pnpm --filter='@woocommerce/plugin-woocommerce' lint:changes:branch
```

- [x] **Step 4: Commit the package**

Run:

```bash
git add plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridge.php plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php plugins/woocommerce/src/Blocks/Payments/Integrations/WooPayments.php plugins/woocommerce/src/Blocks/Payments/Api.php plugins/woocommerce/src/Blocks/Domain/Bootstrap.php plugins/woocommerce/includes/class-woocommerce.php plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/index.js plugins/woocommerce/client/blocks/bin/webpack-entries.js plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCheckoutBridgeTest.php plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php plugins/woocommerce/tests/php/src/Blocks/Payments/Integrations/WooPaymentsTest.php plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/test/index.js plugins/woocommerce/changelog/add-native-payments-a3d-checkout-browser-bridge
git commit -m "feat(payments): add native woopayments checkout bridge"
```

Commit scope note: this package intentionally remains fail-closed for full native runtime ownership. It moves the main card checkout surface into Core, but it does not yet port alternate UPE gateways, WooPay, express checkout, or the remaining setup-intent and order-status callback contracts away from the legacy WooPayments runtime.

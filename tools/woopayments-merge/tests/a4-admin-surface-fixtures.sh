#!/usr/bin/env bash
#
# Fixture checks for the A4 native WooPayments admin surface gate.

set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS_DIR="$(cd "$SELF_DIR/.." && pwd)"
GATE="$HARNESS_DIR/a4-admin-surface-gate.py"
TMP_BASE="${TMPDIR:?TMPDIR is required}"
WORK_DIR="$(mktemp -d "$TMP_BASE/a4-admin-surface.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

write_repo() {
	local repo="$1"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/admin"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/money-movement"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/components"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/promotions/data"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/woopayments/settings"
	mkdir -p "$repo/plugins/woocommerce/client/admin/client/settings-payments"
	mkdir -p "$repo/plugins/woocommerce/assets/client/admin/chunks"
	mkdir -p "$repo/plugins/woocommerce/includes"
	mkdir -p "$repo/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments"
	mkdir -p "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api"
	mkdir -p "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments"

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx" <<'TS'
const Settings = () => import( /* webpackChunkName: "settings-payments-woopayments-settings" */ '../settings' );
const Overview = () => import( /* webpackChunkName: "settings-payments-woopayments-overview" */ './overview' );
const Payouts = () => import( /* webpackChunkName: "settings-payments-woopayments-payouts" */ './payouts' );
const Transactions = () => import( /* webpackChunkName: "settings-payments-woopayments-money-movement" */ './money-movement/transactions' );
const CardReaders = () => import( /* webpackChunkName: "settings-payments-woopayments-card-readers" */ './card-readers' );
const Capital = () => import( /* webpackChunkName: "settings-payments-woopayments-capital" */ './capital' );
const Documents = () => import( /* webpackChunkName: "settings-payments-woopayments-documents" */ './documents' );

registerSettingsPaymentsProviderRoute( { id: 'woopayments-settings', path: '/woopayments/settings', element: <Settings /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-overview', path: '/woopayments/overview', element: <Overview /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-payouts', path: '/woopayments/payouts', element: <Payouts /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-transactions', path: '/woopayments/transactions', element: <Transactions /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-card-readers', path: '/woopayments/card-readers', element: <CardReaders /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-capital', path: '/woopayments/loans', element: <Capital /> } );
registerSettingsPaymentsProviderRoute( { id: 'woopayments-documents', path: '/woopayments/documents', element: <Documents /> } );
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/settings-payments/provider-routes.tsx" <<'TS'
export const registerSettingsPaymentsProviderRoute = () => {};
TS

	for chunk in settings overview payouts money-movement card-readers capital documents; do
		printf 'console.log("%s");\n' "$chunk" > "$repo/plugins/woocommerce/assets/client/admin/chunks/settings-payments-woopayments-$chunk.js"
	done

	cat > "$repo/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php" <<'PHP'
<?php
class WooPaymentsAdminNavigationController implements RegisterHooksInterface {
	private const CAPABILITY = 'manage_woocommerce';
	private const UNRESOLVED_NOTIFICATION_BADGE_FORMAT = ' <span class="wcpay-menu-badge awaiting-mod count-%1$d"><span class="plugin-count">%1$d</span></span>';
	private const LEGACY_DOCUMENTS_ROUTE = '/payments/documents';
	// Reports legacy routes are intentionally absent until their native UI/API surfaces are ported.
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu_items' ), 70 );
	}
	public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service, WooPaymentsAdminMenuBadgeService $badge_service ) {}
	public function add_menu_items() {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->arbiter->should_native_register() || ! $this->account_service->is_gateway_enabled() ) {
			return;
		}
		$query = array( 'filter' => 'awaiting_response' );
		Utils::wc_payments_settings_url( '/woopayments/onboarding', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/overview', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/payouts', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/transactions', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/disputes', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/card-readers', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		Utils::wc_payments_settings_url( '/woopayments/loans', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		if ( $this->account_service->is_documents_enabled() ) {
			Utils::wc_payments_settings_url( '/woopayments/documents', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
		}
		Utils::wc_payments_settings_url( '/woopayments/settings', array( 'from' => Payments::FROM_PAYMENTS_MENU_ITEM ) );
	}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAccountService.php" <<'PHP'
<?php
class WooPaymentsAccountService {
	public function is_gateway_enabled() {}
	public function is_account_rejected() {}
	public function is_account_under_review() {}
	public function is_details_submitted() {}
	public function has_valid_account_for_admin_navigation() {}
	public function is_card_present_eligible() {}
	public function has_card_readers_available() {}
	public function has_previous_capital_loans() {}
	public function is_documents_enabled() {}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php" <<'PHP'
<?php
class WooPaymentsApiClient {
	public function get_dispute_status_counts() {}
	public function get_authorizations_summary() {}
	public function get_pm_promotions() {
		return $this->request_with_legacy_request_filter( WooPaymentsGetPmPromotionsRequest::from_store_context(), 'wcpay_get_pm_promotions_request' );
	}
	public function activate_pm_promotion() {
		return $this->request_with_legacy_request_filter( WooPaymentsActivatePmPromotionRequest::from_id( 'test' ), 'wcpay_activate_pm_promotion_request' );
	}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsGetPmPromotionsRequest.php" <<'PHP'
<?php
class WooPaymentsGetPmPromotionsRequest {
	private const API = 'payment_method_promotions';
	public static function register_legacy_aliases() {
		class_alias( __CLASS__, 'WCPay\Core\Server\Request\Get_PM_Promotions' );
	}
	public function set_store_context_params() {}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsActivatePmPromotionRequest.php" <<'PHP'
<?php
class WooPaymentsActivatePmPromotionRequest {
	private const API = 'payment_method_promotions';
	public static function from_id( string $id ) {
		$request->set_api( self::API . '/' . rawurlencode( $id ) . '/activate' );
	}
	public static function register_legacy_aliases() {
		class_alias( __CLASS__, 'WCPay\Core\Server\Request\Activate_PM_Promotion' );
	}
	public function get_id() {}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsAdminMenuBadgeService.php" <<'PHP'
<?php
class WooPaymentsAdminMenuBadgeService {
	private const DISPUTE_STATUS_COUNTS_KEY = 'wcpay_dispute_status_counts_cache';
	private const DISPUTE_STATUS_COUNTS_KEY_TEST_MODE = 'wcpay_test_dispute_status_counts_cache';
	private const AUTHORIZATION_SUMMARY_KEY = 'wcpay_authorization_summary_cache';
	private const AUTHORIZATION_SUMMARY_KEY_TEST_MODE = 'wcpay_test_authorization_summary_cache';
	public function get_or_add_cached_array() {}
	public function get_uncaptured_transactions_count() {
		return $this->account_service->get_gateway_setting( 'manual_capture', 'no' );
	}
}
PHP

	cat > "$repo/plugins/woocommerce/includes/class-woocommerce.php" <<'PHP'
<?php
$container->get( Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController::class )->register();
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsService.php" <<'PHP'
<?php
class WooPaymentsPmPromotionsService {
	public const PROMOTIONS_CACHE_KEY = 'wcpay_pm_promotions';
	public const PROMOTION_DISMISSALS_OPTION = '_wcpay_pm_promotion_dismissals';
	public function get_visible_promotions() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}
		$this->payment_method_has_active_discount();
	}
	public function activate_promotion() {}
	public function dismiss_promotion() {}
	public function maybe_activate_promotion_for_payment_method() {}
	private function payment_method_has_active_discount() {}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsRestController.php" <<'PHP'
<?php
class WooPaymentsRestController {
	public function register_routes() {
		register_rest_route( 'wc/v3', '/payments/pm-promotions', array() );
		register_rest_route( 'wc/v3', '/payments/pm-promotions/(?P<promotion_id>[^/]+)/activate', array() );
		register_rest_route( 'wc/v3', '/payments/pm-promotions/(?P<promotion_id>[^/]+)/dismiss', array() );
	}
	protected function get_native_pm_promotions() {}
	protected function activate_native_pm_promotion() {}
	protected function dismiss_native_pm_promotion() {}
	private function validate_pm_promotion_id() {}
}
PHP

	cat > "$repo/plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php" <<'PHP'
<?php
class WooPaymentsSettingsService {
	private ?WooPaymentsPmPromotionsService $service = null;
	public function get_settings() {
		return array( 'pm_promotions' => array() );
	}
	public function update_settings() {
		$this->service->maybe_activate_promotion_for_payment_method();
	}
}
PHP

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/promotions/data/store-name.ts" <<'TS'
export const STORE_NAME = 'wc/payments/pmPromotions';
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/promotions/data/actions.ts" <<'TS'
const path = '/activate';
const dismissPath = '/dismiss';
dispatch( STORE_NAME ).invalidateResolution( 'getPmPromotions', [] );
createSuccessNotice( 'Promotion activated successfully.' );
createSuccessNotice( 'Promotion dismissed.' );
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/promotions/spotlight.tsx" <<'TS'
export const SpotlightPromotion = () => null;
const view = 'wcpay_payment_method_promotion_view';
const activate = 'wcpay_payment_method_promotion_activate_click';
const dismiss = 'wcpay_payment_method_promotion_dismiss_click';
const getNativeRoutePath = () => '';
const getSafeUrl = () => '';
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx" <<'TS'
const PmPromotionBadge = () => null;
const discountBadgeText = '';
const badge = promotion.type === 'badge';
const pmPromotions = [];
TS

	for file in \
		"$repo/plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx" \
		"$repo/plugins/woocommerce/client/admin/client/woopayments/admin/payouts.tsx" \
		"$repo/plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transactions-page.tsx" \
		"$repo/plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/disputes-page.tsx"; do
		cat > "$file" <<'TS'
import { SpotlightPromotion } from '../promotions/spotlight';
export const Page = () => <SpotlightPromotion />;
TS
	done

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/page.tsx" <<'TS'
import { SpotlightPromotion } from '../../promotions/spotlight';
import { getWooPaymentsRecentDeposits, submitWooPaymentsInstantDeposit } from './data';
import { getSelectedBalanceCurrency } from './utils';
export const Page = () => {
	const selectedCurrency = getSelectedBalanceCurrency( overview, null );
	const reloadOverviewAndPayouts = () => submitWooPaymentsInstantDeposit( selectedCurrency );
	getWooPaymentsRecentDeposits( selectedCurrency );
	return (
		<>
			<SpotlightPromotion />
			<AccountBalancesCard selectedCurrency={ selectedCurrency || undefined } onCurrencyChange={ setSelectedCurrency } onInstantPayoutSubmit={ reloadOverviewAndPayouts } />
			<PayoutsOverviewCard selectedCurrency={ selectedCurrency || undefined } />
		</>
	);
};
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/data.ts" <<'TS'
export const submitWooPaymentsInstantDeposit = () => ( { type: 'instant' } );
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/utils.ts" <<'TS'
export const getBalanceCurrencyOptions = () => [];
export const getSelectedBalanceCurrency = () => 'usd';
export const getInstantBalanceForCurrency = () => null;
export const getMonthlyAnchorLabel = () => '1st';
export const getPayoutStatusClassName = () => 'woocommerce-woopayments-overview__status-chip--paid';
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx" <<'TS'
const copy = 'Balance currency Total balance Available funds Get %1$s via instant payout. Get %s now Pay out %s now Instant payout for %s in transit. onInstantPayoutSubmit';
export const AccountBalancesCard = () => copy;
TS

	cat > "$repo/plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/payouts-overview-card.tsx" <<'TS'
import { getSelectedBalanceCurrency, getPayoutStatusClassName } from '../utils';
const copy = 'Available funds are automatically dispatched payout-schedule/ Your payouts are temporarily suspended. You have no funds available. Payouts are currently paused because a recent payout failed. Please View payout %s details woocommerce-woopayments-overview__status-chip Change payout schedule';
export const PayoutsOverviewCard = () => getSelectedBalanceCurrency() + getPayoutStatusClassName() + copy;
TS
}

write_plugin_repo() {
	local repo="$1"
	mkdir -p "$repo/dist/chunks"

	for file in \
		dist/index.js \
		dist/index.css \
		dist/settings.js \
		dist/settings.css \
		dist/chunks/wcpay-overview.js \
		dist/chunks/wcpay-payouts.js \
		dist/chunks/wcpay-money-movement.js \
		dist/chunks/wcpay-card-readers.js \
		dist/chunks/wcpay-capital.js; do
		printf '/* %s */\n' "$file" > "$repo/$file"
	done
}

expect_exit() {
	local expected="$1"
	local label="$2"
	shift 2
	set +e
	"$@" > "$WORK_DIR/out.txt" 2>&1
	local rc=$?
	set -e
	if [ "$rc" -ne "$expected" ]; then
		echo "FAIL: $label expected exit $expected, got $rc" >&2
		cat "$WORK_DIR/out.txt" >&2
		exit 1
	fi
}

PASS_REPO="$WORK_DIR/pass"
PLUGIN_REPO="$WORK_DIR/plugin"
write_repo "$PASS_REPO"
write_plugin_repo "$PLUGIN_REPO"
expect_exit 0 "valid fixture passes" python3 "$GATE" --repo "$PASS_REPO" --plugin-repo "$PLUGIN_REPO" --out "$WORK_DIR/pass.json"
grep -q "RESULT: PASS A4 admin surface gate" "$WORK_DIR/out.txt"
python3 - "$WORK_DIR/pass.json" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding="utf-8"))
assert data["schema"] == "woopayments_a4_admin_surface_gate.v1"
assert data["result"] == "pass"
assert data["chunks"]["settings"]["status"] == "present"
assert data["chunks"]["settings"]["raw_bytes"] > 0
assert data["chunks"]["settings"]["gzip_bytes"] > 0
assert data["reference_plugin"]["baseline_files"]["settings-js"]["status"] == "present"
assert data["summary"]["native_admin_chunks"]["file_count"] == 7
assert data["summary"]["reference_plugin_admin_baseline"]["file_count"] == 9
assert data["source"]["pm_promotions"]["frontend_store"]["required_tokens"]["store-name"] is True
assert data["source"]["pm_promotions"]["spotlight_mounts"]["settings"]["required_tokens"]["mount"] is True
assert data["source"]["overview_financial_summary"]["account_balances"]["required_tokens"]["currency-selector"] is True
assert data["source"]["overview_financial_summary"]["payouts_card"]["required_tokens"]["change-schedule"] is True
assert data["source"]["overview_financial_summary"]["overview_page"]["required_tokens"]["balance-currency-handler"] is True
assert data["source"]["overview_financial_summary"]["utils"]["required_tokens"]["instant-balance"] is True
PY

MISSING_CHUNK_REPO="$WORK_DIR/missing-chunk"
write_repo "$MISSING_CHUNK_REPO"
rm "$MISSING_CHUNK_REPO/plugins/woocommerce/assets/client/admin/chunks/settings-payments-woopayments-capital.js"
expect_exit 1 "missing required chunk fails" python3 "$GATE" --repo "$MISSING_CHUNK_REPO"
grep -q "FAIL: missing required native admin chunk capital" "$WORK_DIR/out.txt"

PLUGIN_ROUTE_REPO="$WORK_DIR/plugin-route"
write_repo "$PLUGIN_ROUTE_REPO"
python3 - "$PLUGIN_ROUTE_REPO/plugins/woocommerce/client/admin/client/woopayments/admin/routes.tsx" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
path.write_text(path.read_text().replace("path: '/woopayments/overview'", "path: '/payments/overview'"), encoding="utf-8")
PY
expect_exit 1 "plugin-era route path fails" python3 "$GATE" --repo "$PLUGIN_ROUTE_REPO"
grep -q "FAIL: plugin-era /payments route path found" "$WORK_DIR/out.txt"

REGISTRY_REPO="$WORK_DIR/registry"
write_repo "$REGISTRY_REPO"
printf 'useRegistry();\n' > "$REGISTRY_REPO/plugins/woocommerce/client/admin/client/settings-payments/registry.ts"
expect_exit 1 "registry token fails" python3 "$GATE" --repo "$REGISTRY_REPO"
grep -q "FAIL: forbidden registry token useRegistry found" "$WORK_DIR/out.txt"

MISSING_NAV_REPO="$WORK_DIR/missing-navigation"
write_repo "$MISSING_NAV_REPO"
rm "$MISSING_NAV_REPO/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php"
expect_exit 1 "missing native navigation controller fails" python3 "$GATE" --repo "$MISSING_NAV_REPO"
grep -q "FAIL: required file missing: plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php" "$WORK_DIR/out.txt"

PLUGIN_NAV_ROUTE_REPO="$WORK_DIR/plugin-navigation-route"
write_repo "$PLUGIN_NAV_ROUTE_REPO"
python3 - "$PLUGIN_NAV_ROUTE_REPO/plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsAdminNavigationController.php" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
path.write_text(path.read_text().replace("'/woopayments/overview'", "'/payments/reports'"), encoding="utf-8")
PY
expect_exit 1 "forbidden reports admin navigation route fails" python3 "$GATE" --repo "$PLUGIN_NAV_ROUTE_REPO"
grep -q "FAIL: Reports legacy redirect found before native surface exists" "$WORK_DIR/out.txt"

MISSING_PM_REPO="$WORK_DIR/missing-pm-promotions"
write_repo "$MISSING_PM_REPO"
rm "$MISSING_PM_REPO/plugins/woocommerce/client/admin/client/woopayments/promotions/data/store-name.ts"
expect_exit 1 "missing PM promotions store fails" python3 "$GATE" --repo "$MISSING_PM_REPO"
grep -q "FAIL: required file missing: plugins/woocommerce/client/admin/client/woopayments/promotions/data/store-name.ts" "$WORK_DIR/out.txt"

MISSING_OVERVIEW_SUMMARY_REPO="$WORK_DIR/missing-overview-summary"
write_repo "$MISSING_OVERVIEW_SUMMARY_REPO"
python3 - "$MISSING_OVERVIEW_SUMMARY_REPO/plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
path.write_text(path.read_text().replace("Balance currency", "Currency"), encoding="utf-8")
PY
expect_exit 1 "missing Overview financial summary parity fails" python3 "$GATE" --repo "$MISSING_OVERVIEW_SUMMARY_REPO"
grep -q "FAIL: plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/account-balances-card.tsx missing token currency-selector" "$WORK_DIR/out.txt"

echo "PASS: A4 admin surface fixture tests passed."

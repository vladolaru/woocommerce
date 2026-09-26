<?php
/**
 * WooPaymentsPluginPersistenceContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use WC_Unit_Test_Case;

/**
 * Pins the plugin 11.1.0 persisted-data and Action Scheduler surface
 * (`plugin-11.1.0-persisted-data.json`) against native, so a key or scheduler hook neither named
 * nor recorded as an allowed difference is a build-time failure instead of a silent data-loss
 * risk on cutover.
 *
 * See `.agents/scratchpad/sessions/2026-09-03-native-payments-program-orientation/`
 * `plan-task-t4-bc-inventories.md`, "5. BC surface pin: persisted data and scheduler names".
 *
 * @since 11.2.0
 */
class WooPaymentsPluginPersistenceContractTest extends WC_Unit_Test_Case {

	use NativeSourceScanTrait;

	/**
	 * Native scan roots for the persisted-data literal harvest.
	 *
	 * @var array<int,string>
	 */
	private const SCAN_ROOTS = array(
		'src/Internal/Payments',
		'src/Internal/MultiCurrency',
		'src/Internal/Admin/Settings/PaymentsProviders/WooPayments',
	);

	/**
	 * The four Action Scheduler hooks proven at runtime (D2/D5): native must actually run a
	 * handler on them, not merely name them in a string literal.
	 *
	 * @var array<int,string>
	 */
	private const RUNTIME_SCHEDULER_HOOKS = array(
		'wcpay_store_setup_sync',
		'wcpay_post_kyc_activation_email_send',
		'wcpay_remediate_canceled_authorization_fees',
		'wcpay_remediate_canceled_authorization_fees_dry_run',
	);

	/**
	 * Fixture keys native intentionally does not carry, with the recorded authority.
	 *
	 * `data/bc-inventory-undecided-classified.tsv` rows 194-197, 200, 201, 202 (Task 0 of
	 * plan-task-t4-bc-inventories.md). The two review-prompt user-meta keys were not part of that
	 * classification round (the extractor did not yet find them, R3 of
	 * `data/t4-bc-tests-1-3-review.md`); they fall under the same in-app-review-prompt authority
	 * already recorded for the corresponding hook and Tracks rows.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_DIFFERENCES = array(
		'wcpay_check_subscriptions_eligibility_after_onboarding' => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_menu_badge_hidden'                      => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_should_redirect_to_onboarding'          => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_survey_payment_overview_submitted'      => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'_wcpay_subscription_item_id'                  => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded).',
		'_cancelled'                                   => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded): `_cancelled` . SUBSCRIPTION_ID_META_KEY, class-wc-payments-subscription-service.php:922.',
		'wcpay_subscription_minimum_recurring_amounts' => 'plan.md Decision 1 (Stripe Billing / WCPay Subscriptions excluded).',
		'wcpay_error_message'                          => 'plan.md T.7 Step 6 (d): superseded by the NOX wcpay-connection-error query arg.',
		'woocommerce_admin_wc_payments_review_prompt_dismissed' => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12).',
		'woocommerce_admin_wc_payments_review_prompt_maybe_later' => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12).',
	);

	/**
	 * Fixture keys that are recorded parity defects, scheduled for a later fix (D8, T.7 Step 6).
	 * Unlike ALLOWED_DIFFERENCES, these are not permanent: `test_allowed_differences_are_not_stale`
	 * fails once native carries the key, forcing the fix to remove the entry here too.
	 *
	 * @var array<string,string>
	 */
	private const KNOWN_GAPS = array(
		'_woopay_has_subscription'    => 'plan.md T.7 Step 6 (e): blocked for the owner (renewal money path).',
		'is_attached_to_subscription' => 'plan.md T.7 Step 6 (e): blocked for the owner (renewal money path).',
		'woopayments_referral_code'   => 'plan.md T.7 Step 6 (b): referral-code capture at onboarding, scheduled to port.',
	);

	/**
	 * Loaded fixture.
	 *
	 * @var array<string,mixed>
	 */
	private static array $fixture;

	/**
	 * Load the plugin-11.1.0-persisted-data.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-persisted-data.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		self::$fixture = json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Put native into the `active` state for the duration of one test (D9).
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array() );
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::ACTIVE );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Invalidate the singleton native state so no later test observes the `active` state this
	 * test put it in (gate: `NativePaymentsBootstrapTest` and friends stay clean). The enabling
	 * filter and the stored option are undone first, the way `NativePaymentsStateTest` does, so
	 * nothing left in `parent::tearDown()` can re-cache `active` for the next test.
	 */
	public function tearDown(): void {
		try {
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
			delete_option( NativePaymentsState::OPTION_NAME );
			wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( NativePaymentsState::class )->invalidate();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox The fixture is complete: its declared count matches its entries, and every key/store pair is unique.
	 */
	public function test_fixture_is_complete(): void {
		$fixture = self::$fixture;

		$this->assertSame( $fixture['count'], count( $fixture['entries'] ), 'count must match the number of entries.' );

		// The same string is legitimately reused across stores (for example `wcpay_currency` is
		// both an order-meta key and a session key), so uniqueness is on the (key, store) pair.
		$pairs = array_map(
			static function ( array $entry ): string {
				return $entry['key'] . '|' . $entry['store'];
			},
			$fixture['entries']
		);
		$this->assertSame( array_unique( $pairs ), array_values( $pairs ), 'Every persisted-data key/store pair must be unique.' );
	}

	/**
	 * @testdox Native names every plugin 11.1.0 persistence key, or the difference is recorded.
	 */
	public function test_native_names_every_plugin_persistence_key_or_allows_it(): void {
		$literals = $this->native_literals();
		$failures = array();

		foreach ( self::$fixture['entries'] as $entry ) {
			$key = $entry['key'];
			if ( isset( self::ALLOWED_DIFFERENCES[ $key ] ) || isset( self::KNOWN_GAPS[ $key ] ) ) {
				continue;
			}

			if ( ! $this->native_has_key( $literals, $key, $entry['match'] ) ) {
				$failures[] = $key . ' (' . $entry['match'] . ', plugin_site: ' . $entry['plugin_site'] . ')';
			}
		}

		$this->assertSame( array(), $failures, "Native must name every plugin 11.1.0 persistence key or list it in ALLOWED_DIFFERENCES/KNOWN_GAPS:\n" . implode( "\n", $failures ) );
	}

	/**
	 * @testdox Native runs a handler on every preserved Action Scheduler hook.
	 *
	 * This proves the two owning services hook the preserved names when registered directly; that
	 * the cron request tier of the bootstrap matrix actually resolves and registers them
	 * (`WooPaymentsProvider::get_bootstrap_root_matrix()`) is owned by `NativePaymentsBootstrapTest`.
	 */
	public function test_native_handles_preserved_action_scheduler_hooks(): void {
		wc_get_container()->get( WooPaymentsOperationalQueueService::class )->register();
		wc_get_container()->get( WooPaymentsCanceledAuthorizationFeeRemediationService::class )->register();

		$missing = array();
		foreach ( self::RUNTIME_SCHEDULER_HOOKS as $hook ) {
			if ( ! has_action( $hook ) ) {
				$missing[] = $hook;
			}
		}

		$this->assertSame( array(), $missing, "Native must run a handler on every preserved Action Scheduler hook:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox No ALLOWED_DIFFERENCES or KNOWN_GAPS key is stale: native must not already name it.
	 */
	public function test_allowed_differences_are_not_stale(): void {
		$literals = $this->native_literals();
		$stale    = array();

		foreach ( self::$fixture['entries'] as $entry ) {
			$key = $entry['key'];
			if ( ! isset( self::ALLOWED_DIFFERENCES[ $key ] ) && ! isset( self::KNOWN_GAPS[ $key ] ) ) {
				continue;
			}

			if ( $this->native_has_key( $literals, $key, $entry['match'] ) ) {
				$stale[] = $key;
			}
		}

		$this->assertSame( array(), $stale, "These allowances are now named natively; remove the entry:\n" . implode( "\n", $stale ) );
	}

	/**
	 * Harvest every string literal from the native scan roots, once per test.
	 *
	 * @return array<int,string>
	 */
	private function native_literals(): array {
		$plugin_path = WC()->plugin_path();
		$roots       = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::SCAN_ROOTS
		);

		return $this->native_all_php_string_literals( $this->native_collect_files( $roots, array( 'php' ) ) );
	}

	/**
	 * Whether the harvested literal set carries the given fixture key under its match rule.
	 *
	 * @param array<int,string> $literals   Harvested native string literals.
	 * @param string            $key        Fixture key.
	 * @param string            $match_rule `exact` or `prefix`.
	 */
	private function native_has_key( array $literals, string $key, string $match_rule ): bool {
		if ( 'exact' === $match_rule ) {
			return in_array( $key, $literals, true );
		}

		foreach ( $literals as $literal ) {
			if ( 0 === strpos( $literal, $key ) ) {
				return true;
			}
		}

		return false;
	}
}

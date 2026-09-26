<?php
/**
 * WooPaymentsPluginHookNamesContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use WC_Unit_Test_Case;

/**
 * Pins the plugin 11.1.0 hook name surface (`plugin-11.1.0-hooks.json`, shared with
 * `WooPaymentsPluginHookArityContractTest`) against native, so an owned hook neither fired natively
 * nor recorded as an allowed difference is a build-time failure instead of a silent regression.
 *
 * Every fixture hook is covered by exactly one of three routes: proven at runtime by the arity
 * test's `probed_hooks()` (70 names), resolved here by a static native fire-site scan, or listed in
 * `ALLOWED_DIFFERENCES` with its recorded authority. `NATIVE_ONLY_HOOKS` (D6) separately pins the 24
 * native-only filters that carry no plugin fixture entry at all.
 *
 * See `.agents/scratchpad/sessions/2026-09-03-native-payments-program-orientation/`
 * `plan-task-t4-bc-inventories.md`, "1. Hook names", D4 and D6, and
 * `data/bc-inventory-undecided-classified.tsv` (the `hooks` rows: 54 DECIDED, 5 FALSE_NEGATIVE).
 *
 * @since 11.2.0
 */
class WooPaymentsPluginHookNamesContractTest extends WC_Unit_Test_Case {

	use NativeSourceScanTrait;

	/**
	 * Native scan roots for the hook fire-site scan.
	 *
	 * @var array<int,string>
	 */
	private const SCAN_ROOTS = array(
		'src/Internal/Payments',
		'src/Internal/MultiCurrency',
		'src/Internal/Admin/Settings/PaymentsProviders/WooPayments',
	);

	/**
	 * Native-owned fire sites outside `SCAN_ROOTS`, needed by three of the five FALSE_NEGATIVE rows
	 * `data/t4-bc-tests-1-3-review.md`'s classification found (the IPP receipt email templates and
	 * the WooPayments React settings-section admin-notices action).
	 *
	 * @var array<int,string>
	 */
	private const EXTRA_FILES = array(
		'templates/emails/customer-ipp-receipt.php',
		'templates/emails/plain/customer-ipp-receipt.php',
		'includes/admin/settings/class-wc-settings-payment-gateways.php',
	);

	/**
	 * Method-call wrappers whose own literal argument becomes the `apply_filters` hook name one
	 * level down (`WooPaymentsApiClient::request_with_legacy_request_filter()` /
	 * `request_with_legacy_filter()`, both of which end in `apply_filters( $hook, $request )`,
	 * arity 1). Needed for `wcpay_get_reporting_balance_summary_request`, the one FALSE_NEGATIVE row
	 * not covered by the runtime probe set (`data/t4-bc-tests-1-3-review.md`).
	 *
	 * @var array<string,int>
	 */
	private const HOOK_ARGUMENT_WRAPPERS = array(
		'request_with_legacy_request_filter' => 1,
		'request_with_legacy_filter'         => 3,
	);

	/**
	 * Fixture hook names native intentionally does not fire, with the recorded authority.
	 *
	 * `data/bc-inventory-undecided-classified.tsv`, the `hooks` rows classified DECIDED (54 rows).
	 * Every citation below is copied verbatim from that TSV's `native_evidence_or_authority` column,
	 * which already corrected the seven wrong Decision 1 citations the review found.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_DIFFERENCES = array(
		'__wcpay_customer_data_localized'                  => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:91 §1(a)',
		'__wcpay_upe_config_localized'                     => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:90 §1(a)',
		'wc_payments_add_upe_payment_fields'               => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:88 §1(a)',
		'wc_payments_api_client'                           => 'data/bc-surface-diff.md:117 §1(b) dropped by design (DI container, no class-substitution filter)',
		'wc_payments_display_save_payment_method_checkbox' => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:92 §1(a)',
		'wc_payments_http'                                 => 'data/bc-surface-diff.md:118 §1(b) dropped by design',
		'wc_payments_save_to_account_text'                 => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:93 §1(a)',
		'wc_payments_set_gateway'                          => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:87 §1(a)',
		'wcpay_add_account_tos_agreement'                  => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:79 §1(a)',
		'wcpay_calculated_total'                           => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:100 §1(a)',
		'wcpay_cancel_intent_request'                      => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:70 §1(a)',
		'wcpay_capture_intent_request'                     => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:69 §1(a)',
		'wcpay_confirm_without_payment_intent'             => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:94 §1(a)',
		'wcpay_create_and_confirm_intent_request'          => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:65 §1(a)',
		'wcpay_create_and_confirm_intent_request_api'      => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:66 §1(a)',
		'wcpay_create_and_confirm_setup_intention_request' => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:72 §1(a)',
		'wcpay_create_intent_request'                      => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:64 §1(a)',
		'wcpay_create_request'                             => 'data/bc-surface-diff.md:119 §1(b) dropped by design (no Request factory)',
		'wcpay_create_setup_intention_request'             => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:71 §1(a)',
		'wcpay_disable_new_onboarding'                     => 'data/bc-surface-diff.md:121 §1(b) dropped by design (NOX onboarding)',
		'wcpay_express_checkout_js_params'                 => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:101 §1(a)',
		'wcpay_frontend_tracks'                            => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:105 §1(a)',
		'wcpay_get_account'                                => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:77 §1(a)',
		'wcpay_get_all_deposits_overviews'                 => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:81 §1(a)',
		'wcpay_get_charge_request'                         => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:74 §1(a)',
		'wcpay_get_deposit'                                => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:82 §1(a)',
		'wcpay_get_intent_request'                         => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:67 §1(a)',
		'wcpay_get_setup_intent_request'                   => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:73 §1(a)',
		'wcpay_get_terminal_location'                      => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:83 §1(a)',
		'wcpay_get_terminal_locations'                     => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:84 §1(a)',
		'wcpay_get_terminal_readers_request'               => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:85 §1(a)',
		'wcpay_get_transactions_summary_request'           => 'data/consumer-map/consumer-map.md:67 (the Request-framework filters as a class are changelog items)',
		'wcpay_is_wcpay_subscriptions_enabled'             => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:107 §1(a)',
		'wcpay_list_charge_refunds_request'                => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:76 §1(a)',
		'wcpay_multi_currency_available_currencies'        => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:108 §1(a)',
		'wcpay_order_intent_id_updated'                    => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:97 §1(a)',
		'wcpay_order_payment_method_id_updated'            => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:98 §1(a)',
		'wcpay_payment_fields_upe'                         => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:89 §1(a)',
		'wcpay_payment_request_button_locale'              => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:102 §1(a)',
		'wcpay_plugins_page_js_settings'                   => 'data/bc-surface-diff.md:120 §1(b) dropped by design (no plugin row)',
		'wcpay_prepare_fraud_config'                       => 'data/consumer-map/consumer-map.md:67 + data/bc-surface-diff.md:131 §1(c) renamed (successor asserted by test_renamed_hooks_fire_their_successor)',
		'wcpay_prepare_terminal_payment_request'           => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:86 §1(a)',
		'wcpay_refund_charge_request'                      => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:75 §1(a)',
		'wcpay_review_prompt_experiment_variant'           => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12)',
		'wcpay_subscriptions_prepare_subscription_data'    => 'data/bc-surface-diff.md:122 §1(b) + plan.md Decision 1 (Stripe Billing excluded)',
		'wcpay_update_account_settings'                    => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:78 §1(a)',
		'wcpay_update_intention_request'                   => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:68 §1(a)',
		'wcpay_update_payment_result_on_error'             => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:95 §1(a)',
		'wcpay_woopay_use_blog_token'                      => 'data/bc-surface-diff.md:123 §1(b) dropped by design (no mode switch)',
		'woocommerce_payments_abilities_enabled'           => 'data/client-delta-10.8.0-11.1.0.tsv:20 (10.9.0 Abilities API registration, n/a)',
		'woocommerce_payments_changed_subscription_payment_method' => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:99 §1(a)',
		'woocommerce_payments_order_failed'                => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:96 §1(a)',
		'woocommerce_woocommerce_payments_updated'         => 'data/bc-surface-diff.md:124 §1(b) dropped by design (no plugin version)',
		'wpcay_get_account_login_data'                     => 'data/consumer-map/consumer-map.md:67 (Request-framework and other non-contract filters are changelog items) + data/bc-surface-diff.md:80 §1(a)',
	);

	/**
	 * `wcpay_prepare_fraud_config` was renamed; its successor is asserted separately with its own
	 * arity (D4's `test_renamed_hooks_fire_their_successor`).
	 *
	 * @var array<string,array{successor:string,arity:int}>
	 */
	private const RENAMED_HOOKS = array(
		'wcpay_prepare_fraud_config' => array(
			'successor' => 'woocommerce_woopayments_fraud_service_config',
			'arity'     => 2,
		),
	);

	/**
	 * The 24 native-only filters `test-native-hook-naming-gate.py` pins (D6). Not plugin data, so
	 * not part of `plugin-11.1.0-hooks.json`. The retired gate itself pinned per-hook *site counts*
	 * (a `Counter`, `expected.total() == 25`), not arity; the arities below come from reading each
	 * hook's fire site directly (`NativePaymentsRuntimeArbiter`, `NativePaymentsShadowMode`, and the ten
	 * `WooPaymentsFailedEventsProvider`/`WooPaymentsEventIngestor`/`WooPaymentsSettingsService`/
	 * `WooPaymentsTokenService`/`NativeWooPaymentsGateway`/`WooPaymentsWooPaySessionService`/
	 * `WooPaymentsExpressCheckoutService`/`WooPaymentsCutoverController`/
	 * `WooPaymentsCutoverPreflightService` sites the gate's `PROVIDER_HOOKS` names).
	 *
	 * `woocommerce_woopayments_is_recurring_payment` fires from two sites at the same arity
	 * (`WooPaymentsCheckoutAjaxController` and `WooPaymentsIntentRequestBuilder`). So does
	 * `woocommerce_native_payments_shadow_mode_enabled`: `NativePaymentsShadowMode::is_shadow_mode_enabled()`
	 * fires it via `self::FILTER_SHADOW_ENABLED` (which the retired gate's `self::`-only regex
	 * counted), and `NativePaymentsBootstrap.php:95` fires it a second time via the cross-class
	 * reference `NativePaymentsShadowMode::FILTER_SHADOW_ENABLED` (which the retired gate's regex
	 * does not match, so its `expected.total() == 25` undercounts by this one site). This scanner
	 * resolves `ClassName::CONST` references too, so the real total below is 26 fire sites for 24
	 * names.
	 *
	 * @var array<string,int>
	 */
	private const NATIVE_ONLY_HOOKS = array(
		'woocommerce_native_payments_enabled'             => 1,
		'woocommerce_native_payments_shadow_mode_enabled' => 1,
		'woocommerce_native_payments_shadow_mode_log_full_surfaces' => 2,
		'woocommerce_native_payments_shadow_mode_allow_live_reads' => 2,
		'woocommerce_woopayments_failed_webhook_events'   => 1,
		'woocommerce_woopayments_live_mode'               => 1,
		'woocommerce_woopayments_gateway_duplicate_payment_method_ids' => 3,
		'woocommerce_woopayments_is_recurring_payment'    => 2,
		'woocommerce_woopayments_related_subscriptions_for_order' => 2,
		'woocommerce_woopayments_subscriptions_for_renewal_order' => 2,
		'woocommerce_woopayments_woopay_blog_id'          => 1,
		'woocommerce_woopayments_woopay_blog_token'       => 1,
		'woocommerce_woopayments_express_checkout_enabled_methods' => 3,
		'woocommerce_woopayments_express_checkout_product_types' => 3,
		'woocommerce_woopayments_express_checkout_is_product_supported' => 3,
		'woocommerce_woopayments_express_checkout_product_data' => 3,
		'woocommerce_woopayments_fraud_services_config'   => 1,
		'woocommerce_woopayments_soft_cutover_enabled'    => 1,
		'woocommerce_woopayments_mandatory_cutover_enabled' => 1,
		'woocommerce_woopayments_cutover_transport_ready' => 1,
		'woocommerce_woopayments_cutover_admin_surfaces_ready' => 1,
		'woocommerce_woopayments_cutover_pending_event_types' => 1,
		'woocommerce_woopayments_cutover_pending_operational_queue_hooks' => 1,
		'woocommerce_woopayments_cutover_preflight_failures' => 1,
	);

	/**
	 * Loaded `plugin-11.1.0-hooks.json` fixture, keyed by hook name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $fixture_by_name;

	/**
	 * Load the shared plugin-11.1.0-hooks.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-hooks.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		$fixture               = json_decode( (string) file_get_contents( $path ), true );
		self::$fixture_by_name = array();
		foreach ( $fixture['entries'] as $entry ) {
			self::$fixture_by_name[ $entry['name'] ] = $entry;
		}
	}

	/**
	 * @testdox The fixture is complete: its declared count matches its entries, and every name is unique and sorted.
	 */
	public function test_fixture_is_complete(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-hooks.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		$fixture = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( $fixture['count'], count( $fixture['entries'] ), 'count must match the number of entries.' );

		$names = array_column( $fixture['entries'], 'name' );
		$this->assertSame( array_unique( $names ), $names, 'Every hook name must be unique.' );

		$sorted = $names;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $names, 'Entries must be sorted by name.' );
	}

	/**
	 * @testdox Every owned plugin 11.1.0 hook is fired natively, proven at runtime, or an allowed difference.
	 */
	public function test_every_plugin_hook_is_fired_natively_or_allowed(): void {
		$probed   = WooPaymentsPluginHookArityContractTest::probed_hooks();
		$sites    = $this->native_hook_fire_sites();
		$failures = array();

		foreach ( self::$fixture_by_name as $name => $entry ) {
			if ( isset( $probed[ $name ] ) || isset( self::ALLOWED_DIFFERENCES[ $name ] ) ) {
				continue;
			}

			if ( $this->native_has_matching_site( $sites, $name, (int) $entry['arity'] ) ) {
				continue;
			}

			$found              = $sites[ $name ] ?? array();
			$found_descriptions = array_map(
				static function ( array $site ): string {
					return $site['site'] . ' (arity ' . $site['arity'] . ')';
				},
				$found
			);
			$failures[]         = $name . ' (expected arity ' . $entry['arity'] . '; plugin_site: ' . $entry['plugin_site'] . '; native sites found: ' . ( $found_descriptions ? implode( ', ', $found_descriptions ) : 'none' ) . ')';
		}

		$this->assertSame( array(), $failures, "Every owned plugin 11.1.0 hook must be probed at runtime, resolved natively, or listed in ALLOWED_DIFFERENCES:\n" . implode( "\n", $failures ) );
	}

	/**
	 * @testdox Every statically resolved native site of a runtime-probed hook also keeps the fixture arity.
	 *
	 * A runtime probe (`WooPaymentsPluginHookArityContractTest`) proves one native call site fires the
	 * hook with the fixture's arity; it says nothing about a second native site of the same name
	 * (R4 of `data/t4-bc-tests-4-5-review.md`: for example `wcpay_database_cache_ttl` also fires from
	 * `MultiCurrencyDatabaseCache` and `WooPaymentsAdminMenuBadgeService`, and
	 * `wcpay_list_transactions_request` also fires from `WooPaymentsReportsRestController` twice).
	 * This closes that gap statically, without a second runtime probe per secondary site.
	 */
	public function test_probed_hook_secondary_sites_keep_the_fixture_arity(): void {
		$probed      = WooPaymentsPluginHookArityContractTest::probed_hooks();
		$sites       = $this->native_hook_fire_sites();
		$wrong_arity = array();

		foreach ( array_keys( $probed ) as $name ) {
			$entry = self::$fixture_by_name[ $name ] ?? null;
			if ( null === $entry ) {
				continue;
			}

			foreach ( $sites[ $name ] ?? array() as $site ) {
				if ( (int) $entry['arity'] !== $site['arity'] ) {
					$wrong_arity[] = $name . ' at ' . $site['site'] . ' (found arity ' . $site['arity'] . ', expected ' . $entry['arity'] . ')';
				}
			}
		}

		$this->assertSame( array(), $wrong_arity, "Every statically resolved native site of a runtime-probed hook must keep the fixture arity:\n" . implode( "\n", $wrong_arity ) );
	}

	/**
	 * @testdox No ALLOWED_DIFFERENCES hook is stale: native must not fire it, and the runtime probe set must not have absorbed it.
	 */
	public function test_allowed_differences_are_not_stale(): void {
		$probed = WooPaymentsPluginHookArityContractTest::probed_hooks();
		$sites  = $this->native_hook_fire_sites();
		$stale  = array();

		foreach ( self::ALLOWED_DIFFERENCES as $name => $authority ) {
			unset( $authority );
			if ( isset( $probed[ $name ] ) || isset( $sites[ $name ] ) ) {
				$stale[] = $name;
			}
		}

		$this->assertSame( array(), $stale, "These ALLOWED_DIFFERENCES hooks are now fired natively; remove the allowance:\n" . implode( "\n", $stale ) );
	}

	/**
	 * @testdox The renamed wcpay_prepare_fraud_config filter fires under its native successor name and arity.
	 */
	public function test_renamed_hooks_fire_their_successor(): void {
		$sites   = $this->native_hook_fire_sites();
		$missing = array();

		foreach ( self::RENAMED_HOOKS as $old_name => $rename ) {
			if ( ! $this->native_has_matching_site( $sites, $rename['successor'], $rename['arity'] ) ) {
				$missing[] = $old_name . ' -> ' . $rename['successor'] . ' (arity ' . $rename['arity'] . ')';
			}
		}

		$this->assertSame( array(), $missing, "Every renamed hook's native successor must fire with the recorded arity:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox The 24 native-only hooks (D6) keep exactly their pinned names and arity, at 26 fire sites total.
	 */
	public function test_native_only_hooks_keep_their_names_and_arity(): void {
		$sites       = $this->native_hook_fire_sites();
		$missing     = array();
		$wrong_arity = array();
		$total_sites = 0;

		foreach ( self::NATIVE_ONLY_HOOKS as $name => $arity ) {
			$found = $sites[ $name ] ?? array();
			if ( array() === $found ) {
				$missing[] = $name;
				continue;
			}

			foreach ( $found as $site ) {
				++$total_sites;
				if ( $arity !== $site['arity'] ) {
					$wrong_arity[] = $name . ' at ' . $site['site'] . ' (found arity ' . $site['arity'] . ', expected ' . $arity . ')';
				}
			}
		}

		$this->assertSame( array(), $missing, "Every native-only hook must have at least one fire site:\n" . implode( "\n", $missing ) );
		$this->assertSame( array(), $wrong_arity, "Every native-only hook fire site must keep its pinned arity:\n" . implode( "\n", $wrong_arity ) );
		$this->assertSame( 26, $total_sites, 'The 24 native-only hooks must fire from exactly 26 sites total (see the NATIVE_ONLY_HOOKS docblock: one more than the retired gate counted).' );
	}

	/**
	 * Whether the scanned native fire sites include the given name at the given arity.
	 *
	 * @param array<string,array<int,array{arity:int,site:string}>> $sites Scanned fire sites.
	 * @param string                                                $name  Hook name.
	 * @param int                                                   $arity Expected arity.
	 */
	private function native_has_matching_site( array $sites, string $name, int $arity ): bool {
		foreach ( $sites[ $name ] ?? array() as $site ) {
			if ( $arity === $site['arity'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scan the native roots (plus the explicit extra files) for every `apply_filters`/`do_action`
	 * call whose hook-name argument resolves to a literal, either directly or through a
	 * `self::`/`static::`/`ClassName::CONST` reference or one of `HOOK_ARGUMENT_WRAPPERS`.
	 *
	 * @return array<string,array<int,array{arity:int,site:string}>>
	 */
	private function native_hook_fire_sites(): array {
		$plugin_path = WC()->plugin_path();
		$roots       = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::SCAN_ROOTS
		);
		$files       = $this->native_collect_files( $roots, array( 'php' ) );
		foreach ( self::EXTRA_FILES as $extra ) {
			$files[] = $plugin_path . '/' . $extra;
		}
		sort( $files );
		$files = array_values( array_unique( $files ) );

		$sites = array();

		foreach ( $files as $file ) {
			$tokens   = $this->native_tokenize( $file );
			$relative = ltrim( str_replace( $plugin_path, '', $file ), '/' );
			$count    = count( $tokens );

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
					continue;
				}

				$call_name  = $token[1];
				$is_hook_fn = in_array( $call_name, array( 'apply_filters', 'do_action' ), true );
				$wrapper    = self::HOOK_ARGUMENT_WRAPPERS[ $call_name ] ?? null;

				if ( ! $is_hook_fn && null === $wrapper ) {
					continue;
				}

				$open_index = $this->native_next_significant_token_index( $tokens, $i + 1 );
				if ( null === $open_index || '(' !== $tokens[ $open_index ] ) {
					continue;
				}

				$close_index = $this->native_matching_paren_index( $tokens, $open_index );
				$args        = $this->native_split_top_level_commas( $tokens, $open_index + 1, $close_index - 1 );
				$line        = is_array( $token ) ? $token[2] : 0;

				if ( $is_hook_fn ) {
					if ( array() === $args ) {
						continue;
					}

					$name = $this->native_resolve_name_arg( $args[0], $file );
					if ( null === $name ) {
						continue;
					}

					$arity            = count( $args ) - 1;
					$sites[ $name ][] = array(
						'arity' => $arity,
						'site'  => $relative . ':' . $line,
					);
					continue;
				}

				// A `HOOK_ARGUMENT_WRAPPERS` method call: only counts as a fire site when this is a
				// `->method(` call (skip a same-named local function/variable, if any).
				$previous = $this->native_previous_significant_token( $tokens, $i );
				if ( ! is_array( $previous ) || T_OBJECT_OPERATOR !== $previous[0] ) {
					continue;
				}

				if ( ! isset( $args[ $wrapper ] ) ) {
					continue;
				}

				$name = $this->native_resolve_name_arg( $args[ $wrapper ], $file );
				if ( null === $name ) {
					continue;
				}

				$sites[ $name ][] = array(
					'arity' => 1,
					'site'  => $relative . ':' . $line,
				);
			}
		}

		return $sites;
	}

	/**
	 * The index of the `)` matching the `(` at `$open_index`.
	 *
	 * @param array<int,mixed> $tokens      PHP tokens.
	 * @param int              $open_index Index of the opening `(`.
	 */
	private function native_matching_paren_index( array $tokens, int $open_index ): int {
		$depth = 0;
		for ( $i = $open_index, $count = count( $tokens ); $i < $count; $i++ ) {
			$char = is_array( $tokens[ $i ] ) ? null : $tokens[ $i ];
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $count - 1;
	}

	/**
	 * Split the token slice `[start, end]` (inside a call's parens) into top-level, comma-separated
	 * argument token slices.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $start  First token index inside the parens.
	 * @param int              $end    Last token index inside the parens.
	 * @return array<int,array<int,mixed>>
	 */
	private function native_split_top_level_commas( array $tokens, int $start, int $end ): array {
		if ( $start > $end ) {
			return array();
		}

		$args    = array();
		$current = array();
		$depth   = 0;

		for ( $i = $start; $i <= $end; $i++ ) {
			$token = $tokens[ $i ];
			$char  = is_array( $token ) ? null : $token;

			if ( in_array( $char, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $char, array( ')', ']', '}' ), true ) ) {
				--$depth;
			} elseif ( 0 === $depth && ',' === $char ) {
				$args[]  = $current;
				$current = array();
				continue;
			}

			$current[] = $token;
		}
		$args[] = $current;

		return $args;
	}

	/**
	 * Resolve a hook-name argument's token slice to a literal string, when it is a plain literal or
	 * a `self::`/`static::`/`ClassName::CONST` reference.
	 *
	 * @param array<int,mixed> $arg_tokens Argument token slice.
	 * @param string           $file       Absolute file path the argument was found in.
	 */
	private function native_resolve_name_arg( array $arg_tokens, string $file ): ?string {
		$significant = $this->native_strip_trivia( $arg_tokens );

		if ( 1 === count( $significant ) && is_array( $significant[0] ) && T_CONSTANT_ENCAPSED_STRING === $significant[0][0] ) {
			return $this->native_resolve_literal( $significant[0][1] );
		}

		if (
			3 === count( $significant )
			&& is_array( $significant[0] ) && in_array( $significant[0][0], array( T_STRING, T_STATIC ), true )
			&& is_array( $significant[1] ) && T_DOUBLE_COLON === $significant[1][0]
			&& is_array( $significant[2] ) && T_STRING === $significant[2][0]
		) {
			return $this->native_resolve_constant_reference( $file, $significant[0][1], $significant[2][1] );
		}

		return null;
	}
}

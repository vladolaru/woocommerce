<?php
/**
 * WooPaymentsPluginPersistenceContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use WC_Unit_Test_Case;

/**
 * Pins the plugin 11.1.0 persisted-data and Action Scheduler surface
 * (`plugin-11.1.0-persisted-data.json`) against native, so a key or scheduler hook neither named
 * nor recorded as an allowed difference is a build-time failure instead of a silent data-loss
 * risk on cutover.
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
	 * The persistence profile, which lists keys without persisting them.
	 *
	 * @var string
	 */
	private const PERSISTENCE_PROFILE_FILE = 'src/Internal/Payments/Providers/WooPayments/WooPaymentsPersistenceVocabulary.php';

	/**
	 * Calls whose key argument only reads a persisted value.
	 *
	 * @var array<int,string>
	 */
	private const READ_FUNCTIONS = array( 'get_meta', 'get_option' );

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
	 * The decided persisted-data rows of the program's BC inventory. The two review-prompt user-meta keys
	 * were not part of that classification round; they fall under the same in-app-review-prompt authority
	 * already recorded for the corresponding hook and Tracks rows.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_DIFFERENCES = array(
		'wcpay_check_subscriptions_eligibility_after_onboarding' => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_menu_badge_hidden'                 => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_should_redirect_to_onboarding'     => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_survey_payment_overview_submitted' => 'data/consumer-map/consumer-map.md:67 (cosmetic options are changelog items).',
		'wcpay_error_message'                     => 'plan.md T.7 Step 6 (d): superseded by the NOX wcpay-connection-error query arg.',
		'woocommerce_admin_wc_payments_review_prompt_dismissed' => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12).',
		'woocommerce_admin_wc_payments_review_prompt_maybe_later' => 'data/client-delta-10.8.0-11.1.0.tsv:81 and :105 (11.0.0 review-prompt rows, n/a, owner review 2026-09-12).',
	);

	/**
	 * Fixture keys client 11.1.0 itself only reads, so a native reader is parity.
	 *
	 * @var array<string,string>
	 */
	private const PLUGIN_READ_ONLY_KEYS = array(
		'wcpay_frt_review_feature_active'      => 'Client 11.1.0 only reads it (class-wc-payments-features.php:262); the platform or support sets it.',
		'wcpay_session_rate_limiter_disabled_' => 'Client 11.1.0 only reads it (class-session-rate-limiter.php:92); support sets it by hand.',
		'_intent_status'                       => 'Client 11.1.0 only reads it (class-wc-rest-payments-charges-controller.php:89); older plugin versions wrote it.',
	);

	/**
	 * Fixture keys that are recorded parity defects, scheduled for a later fix (D8, T.7 Step 6).
	 * Unlike ALLOWED_DIFFERENCES, these are not permanent: `test_allowed_differences_are_not_stale`
	 * fails once native carries the key, forcing the fix to remove the entry here too.
	 *
	 * @var array<string,string>
	 */
	private const KNOWN_GAPS = array(
		'_woopay_has_subscription'    => 'plan.md T.7 Step 6 (e): blocked for the owner as O10 (renewal money path).',
		'is_attached_to_subscription' => 'plan.md T.7 Step 6 (e): blocked for the owner as O10 (renewal money path).',
		'wcpay_activation_timestamp'  => 'Client 11.1.0 adds it on activation (class-wc-payments.php:1589); native only reads it (WooPaymentsOperationalQueueService). Reported to the monitor 2026-09-29 (T.13, V482) for a decision.',
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
			if ( isset( self::ALLOWED_DIFFERENCES[ $key ] ) || isset( self::KNOWN_GAPS[ $key ] ) || isset( self::PLUGIN_READ_ONLY_KEYS[ $key ] ) ) {
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
	 * @testdox Native schedules the preserved Action Scheduler hooks under the plugin's own group.
	 *
	 * The fixture's `group` column is read per scheduling call site at the 11.1.0 tag
	 * (`extract-persisted-data.php` Pass 6): `wcpay_store_setup_sync` under
	 * `WC_Payments_Action_Scheduler_Service::GROUP_ID` (`class-wc-payments-action-scheduler-service.php:111`),
	 * the post-KYC email and fee-remediation hooks under the literal `'woocommerce-payments'`
	 * (`class-wc-payments-post-kyc-activation-email-service.php:113`,
	 * `class-wc-payments-remediate-canceled-auth-fees.php:570,592`).
	 */
	public function test_preserved_action_scheduler_hooks_use_the_plugin_group(): void {
		$native_groups = array(
			'wcpay_store_setup_sync'                      => WooPaymentsActionSchedulerService::GROUP_ID,
			'wcpay_post_kyc_activation_email_send'        => WooPaymentsOperationalQueueService::POST_KYC_ACTIVATION_EMAIL_GROUP,
			'wcpay_remediate_canceled_authorization_fees' => WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID,
			'wcpay_remediate_canceled_authorization_fees_dry_run' => WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID,
		);
		$plugin_groups = array();
		foreach ( self::$fixture['entries'] as $entry ) {
			if ( 'action_scheduler_hook' === $entry['store'] && isset( $native_groups[ $entry['key'] ] ) ) {
				$plugin_groups[ $entry['key'] ] = $entry['group'];
			}
		}

		ksort( $native_groups );
		ksort( $plugin_groups );
		$this->assertSame( $native_groups, $plugin_groups, 'Each preserved Action Scheduler hook must schedule under its plugin 11.1.0 group.' );
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
	 * Harvest the string literals that can show native persists a key, once per test.
	 *
	 * The persistence profile's key list only lists keys, and a get_meta()/get_option() key only
	 * reads one, so neither counts: a key named nowhere else has no native writer (how is_woopay
	 * went unwritten until T.13 W1). The profile's own constant declarations still count.
	 *
	 * @return array<int,string>
	 */
	private function native_literals(): array {
		$plugin_path  = WC()->plugin_path();
		$profile_file = $plugin_path . '/' . self::PERSISTENCE_PROFILE_FILE;
		$roots        = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::SCAN_ROOTS
		);

		$literals = array();
		foreach ( $this->native_collect_files( $roots, array( 'php' ) ) as $path ) {
			$tokens = $this->native_tokenize( $path );
			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] || $this->is_read_call_key( $tokens, $index ) ) {
					continue;
				}

				if ( $profile_file === $path && ! $this->is_constant_declaration_value( $tokens, $index ) ) {
					continue;
				}

				$value = $this->native_resolve_literal( $token[1] );
				if ( null !== $value ) {
					$literals[] = $value;
				}
			}
		}

		return $literals;
	}

	/**
	 * Whether the literal at the given index is the value of a `const NAME = '...';` declaration.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $index  Index of the literal token.
	 */
	private function is_constant_declaration_value( array $tokens, int $index ): bool {
		$previous = $this->previous_significant_tokens( $tokens, $index, 3 );

		return 3 === count( $previous )
			&& '=' === $previous[0]
			&& is_array( $previous[1] )
			&& T_STRING === $previous[1][0]
			&& is_array( $previous[2] )
			&& T_CONST === $previous[2][0];
	}

	/**
	 * The given number of non-trivia tokens before an index, nearest first.
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $index  Index to look back from (exclusive).
	 * @param int              $count  How many tokens to collect.
	 * @return array<int,mixed>
	 */
	private function previous_significant_tokens( array $tokens, int $index, int $count ): array {
		$previous = array();
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$previous[] = $tokens[ $i ];
			if ( count( $previous ) === $count ) {
				break;
			}
		}

		return $previous;
	}

	/**
	 * Whether the literal at the given index is the first argument of a read call such as get_meta().
	 *
	 * @param array<int,mixed> $tokens PHP tokens.
	 * @param int              $index  Index of the literal token.
	 */
	private function is_read_call_key( array $tokens, int $index ): bool {
		$previous = $this->previous_significant_tokens( $tokens, $index, 2 );

		return 2 === count( $previous )
			&& '(' === $previous[0]
			&& is_array( $previous[1] )
			&& T_STRING === $previous[1][0]
			&& in_array( $previous[1][1], self::READ_FUNCTIONS, true );
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

<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTrackingInfoService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTracks;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments Tracks source marking.
 */
class WooPaymentsTracksTest extends WC_Unit_Test_Case {

	/**
	 * The event source scheme the owner approved on 2026-10-07: native events keep the client's names, carry
	 * `payments_runtime` = `woocommerce_core`, and send no `wcpay_version`, which client 11.1.0 fills with the plugin
	 * version.
	 *
	 * @testdox Should record a server-side admin event as sent by WooCommerce core, without a plugin version.
	 */
	public function test_records_admin_event_with_the_builtin_runtime_and_no_plugin_version(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		$capture  = static function ( $properties, $event_name ) use ( &$recorded ) {
			$recorded[ $event_name ] = $properties;

			return $properties;
		};
		add_filter( 'woocommerce_tracks_event_properties', $capture, 10, 2 );

		try {
			WooPaymentsTracks::record_wcadmin_event(
				'wcpay_first_live_sale',
				array(
					'stage'         => 'one',
					'wcpay_version' => '11.1.0',
				)
			);
		} finally {
			remove_filter( 'woocommerce_tracks_event_properties', $capture, 10 );
		}

		$this->assertArrayHasKey( 'wcadmin_wcpay_first_live_sale', $recorded );
		$properties = $recorded['wcadmin_wcpay_first_live_sale'];
		$this->assertSame( 'woocommerce_core', $properties['payments_runtime'] );
		$this->assertSame( 'one', $properties['stage'] );
		$this->assertArrayNotHasKey( 'wcpay_version', $properties );
	}

	/**
	 * Reset the container replacements and the tracking info cache the tests install.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_all_replacements();
		delete_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );

		parent::tearDown();
	}

	/**
	 * Client 11.1.0 merges get_tracking_info() last into the account, onboarding, promotions and settings events
	 * (class-wc-payments-account.php:3210-3220, class-wc-payments-pm-promotions-service.php:1015-1025).
	 *
	 * @testdox Should merge the platform tracking info last into an event that asks for it.
	 */
	public function test_records_the_tracking_info_last_when_asked(): void {
		FixedTrackingInfoService::install();

		$recorded = $this->record_events(
			static function (): void {
				WooPaymentsTracks::record_wcadmin_event_with_tracking_info(
					'wcpay_account_referral',
					array(
						'referral_code'    => 'code',
						'hosting_provider' => 'from-the-event',
					)
				);
			}
		);

		$properties = $recorded['wcadmin_wcpay_account_referral'] ?? array();
		$this->assertSame( 'fixture-host', $properties['hosting_provider'] ?? null );
		$this->assertSame( 'code', $properties['referral_code'] ?? null );
		$this->assertSame( 'woocommerce_core', $properties['payments_runtime'] ?? null );
	}

	/**
	 * Client 11.1.0 records its other admin events without the tracking info, for example the order refund events
	 * (class-wc-payment-gateway-wcpay.php:2979, Tracker::track_admin()).
	 *
	 * @testdox Should record an event outside those families without the tracking info and without reading it.
	 */
	public function test_records_other_events_without_the_tracking_info(): void {
		$service = FixedTrackingInfoService::install();

		$recorded = $this->record_events(
			static function (): void {
				WooPaymentsTracks::record_wcadmin_event( 'wcpay_edit_order_refund_success' );
			}
		);

		$this->assertArrayHasKey( 'wcadmin_wcpay_edit_order_refund_success', $recorded );
		$this->assertArrayNotHasKey( 'hosting_provider', $recorded['wcadmin_wcpay_edit_order_refund_success'] );
		$this->assertSame( 0, $service->reads );
	}

	/**
	 * Cold cache: client 11.1.0 get_tracking_info() reads through Database_Cache::get_or_add() (class-wc-payments-account.php:3156-3169),
	 * which fetches a missing entry except in cron and AJAX requests (class-database-cache.php:295-300) and answers null before the
	 * site is connected (:3157-3159). The event then carries the info only when it was fetched.
	 *
	 * @testdox Should fetch a missing tracking info for an event only where the WooPayments extension would: $label.
	 * @testWith ["an admin request", true, false, 1, "fixture-host-1"]
	 *           ["an AJAX request", true, true, 0, null]
	 *           ["a site that is not connected", false, false, 0, null]
	 *
	 * @param string      $label            Case label.
	 * @param bool        $connected        Whether the site is connected to the platform.
	 * @param bool        $ajax             Whether the request is an AJAX request.
	 * @param int         $platform_calls   Expected platform tracking info requests.
	 * @param string|null $hosting_provider Expected hosting_provider on the event.
	 */
	public function test_cold_cache_fetches_the_tracking_info_only_where_the_extension_would( string $label, bool $connected, bool $ajax, int $platform_calls, ?string $hosting_provider ): void {
		unset( $label );
		$api_client = new class( $connected ) extends WooPaymentsApiClient {
			/**
			 * Whether the site is connected.
			 *
			 * @var bool
			 */
			private bool $connected;

			/**
			 * Number of platform tracking info requests.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 *
			 * @param bool $connected Whether the site is connected.
			 */
			public function __construct( bool $connected ) {
				$this->connected = $connected;
			}

			/**
			 * Tell whether the site is connected.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->connected;
			}

			/**
			 * Answer the platform's tracking info (wpcom class-tracking-controller.php:233-239).
			 *
			 * @return array<string,mixed>
			 */
			public function get_tracking_info(): array {
				++$this->calls;

				return array( 'hosting_provider' => 'fixture-host-1' );
			}
		};
		$cache      = new WooPaymentsDatabaseCache();
		$cache->init( new LegacyProxy() );
		$service = new WooPaymentsTrackingInfoService();
		$service->init( $api_client, $cache );
		wc_get_container()->replace( WooPaymentsTrackingInfoService::class, $service );
		delete_option( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );
		if ( $ajax ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}

		try {
			$recorded = $this->record_events(
				static function (): void {
					WooPaymentsTracks::record_wcadmin_event_with_tracking_info( 'wcpay_payment_method_promotion_dismissed', array( 'promo_id' => 'promo' ) );
				}
			);
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( $platform_calls, $api_client->calls );
		$this->assertSame( $hosting_provider, $recorded['wcadmin_wcpay_payment_method_promotion_dismissed']['hosting_provider'] ?? null );
	}

	/**
	 * Run an action and return the Tracks events it recorded, by name.
	 *
	 * @param callable $act Action.
	 * @return array<string,array<string,mixed>>
	 */
	private function record_events( callable $act ): array {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		$capture  = static function ( $properties, $event_name ) use ( &$recorded ) {
			$recorded[ $event_name ] = $properties;

			return $properties;
		};
		// An AJAX request sends the Tracks pixel at once; answer it with core's test stub so no event leaves the test
		// (tests/php/includes/tracks/class-wc-tracks-client-test.php:64-71).
		$answer_pixel = static function ( $response, $parsed_args, $url ) {
			unset( $parsed_args );

			if ( str_contains( $url, 'pixel.wp.com/t.gif' ) ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '',
				);
			}

			return $response;
		};
		add_filter( 'woocommerce_tracks_event_properties', $capture, 10, 2 );
		add_filter( 'pre_http_request', $answer_pixel, 10, 3 );

		try {
			$act();
		} finally {
			remove_filter( 'woocommerce_tracks_event_properties', $capture, 10 );
			remove_filter( 'pre_http_request', $answer_pixel, 10 );
		}

		return $recorded;
	}
}

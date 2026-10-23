<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Admin\Onboarding\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\ProfilerCard;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\DetachesShellCallbacks;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the PayPal Wallet card on the core profiler's Plugins page and for entering the collecting state when the
 * profiler completes.
 *
 * @group paypal-wallet
 */
class ProfilerTest extends WalletTestCase {
	use DetachesShellCallbacks;
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var ProfilerCard
	 */
	private $sut;

	/**
	 * The fake transport.
	 *
	 * @var FakePlatformTransport
	 */
	private FakePlatformTransport $transport;

	/**
	 * The owner the arbiter reports.
	 *
	 * @var string
	 */
	private string $owner = PayPalWalletRuntimeArbiter::OWNER_NATIVE;

	/**
	 * Build the card over a ready fake transport, on a store core owns.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->transport = new FakePlatformTransport();
		$this->build_sut();
		// The shell's own card would answer the completion first, over the store's real transport.
		$this->detach_shell_callbacks( 'woocommerce_onboarding_profile_completed', ProfilerCard::class );
		$this->detach_shell_callbacks( 'rest_post_dispatch', ProfilerCard::class );
		update_option( 'admin_email', 'admin@example.com' );
		update_option( OnboardingProfile::DATA_OPTION, array( 'completed' => false ) );
		$this->set_wallet_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );
	}

	/**
	 * Build the card over the current transport and owner.
	 */
	private function build_sut(): void {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )->onlyMethods( array( 'get_runtime_owner' ) )->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturnCallback(
			function (): string {
				return $this->owner;
			}
		);
		$this->sut = new ProfilerCard(
			new Options(),
			function () {
				return $this->transport;
			},
			$arbiter
		);
	}

	/**
	 * The free extensions response, as core's route answers it: bundles with plugin objects.
	 *
	 * @return WP_REST_Response
	 */
	private function free_extensions_response(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				array(
					'key'     => 'obw/grow',
					'plugins' => array( (object) array( 'key' => 'mailpoet' ) ),
				),
				array(
					'key'     => ProfilerCard::BUNDLE,
					'title'   => 'Grow your store',
					'plugins' => array(
						(object) array(
							'key'          => 'woocommerce-payments',
							'is_activated' => false,
						),
						(object) array(
							'key'          => 'woocommerce-shipping',
							'is_activated' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Run the response through the card's dispatch filter, as the REST server does for a route.
	 *
	 * @param string                $route    The route the request addressed.
	 * @param WP_REST_Response|null $response The response; the free extensions one by default.
	 * @return array The response data.
	 */
	private function serve( string $route = ProfilerCard::ROUTE, ?WP_REST_Response $response = null ): array {
		$response = $this->sut->handle_rest_post_dispatch( $response ?? $this->free_extensions_response(), rest_get_server(), new WP_REST_Request( 'GET', $route ) );

		return $response->get_data();
	}

	/**
	 * The keys of the core profiler bundle's plugins.
	 *
	 * @param array $bundles The bundles.
	 * @return string[]
	 */
	private function profiler_keys( array $bundles ): array {
		return array_map(
			static function ( $plugin ): string {
				return $plugin->key;
			},
			$bundles[1]['plugins']
		);
	}

	/**
	 * Complete the profiler as core does: the onboarding profile is saved with `completed`.
	 *
	 * @param array $profile The other profile fields.
	 */
	private function complete_profiler( array $profile = array() ): void {
		$this->set_wallet_option( OnboardingProfile::DATA_OPTION, array( 'completed' => false ) );
		update_option( OnboardingProfile::DATA_OPTION, array_merge( $profile, array( 'completed' => true ) ) );
	}

	/**
	 * @testdox Should add the PayPal Wallet card next to WooPayments in the core profiler bundle, as included, with the D5 copy.
	 */
	public function test_serves_the_included_card_next_to_woopayments(): void {
		$bundles = $this->serve();

		$this->assertSame( array( 'woocommerce-payments', ProfilerCard::KEY, 'woocommerce-shipping' ), $this->profiler_keys( $bundles ) );
		$card = $bundles[1]['plugins'][1];
		$this->assertSame( 'Give shoppers a variety of ways to pay', $card->label );
		$this->assertSame( 'Offer additional payment options with PayPal Wallet', $card->description );
		$this->assertTrue( $card->is_included );
		$this->assertTrue( $card->is_visible );
		$this->assertStringEndsWith( 'assets/images/onboarding/icons/paypal.svg', $card->image_url );
		$this->assertCount( 1, $bundles[0]['plugins'], 'Other bundles are untouched' );
		$this->assertNotFalse( get_option( ProfilerCard::SERVED_OPTION ), 'Serving the card is recorded' );
	}

	/**
	 * @testdox Should mark the card installed and activated, so the profiler never selects it and never asks to install it.
	 */
	public function test_the_card_is_never_installed(): void {
		$card = $this->serve()[1]['plugins'][1];

		$this->assertTrue( $card->is_installed );
		$this->assertTrue( $card->is_activated, 'The Plugins page selects and installs only plugins that are not activated' );
		$this->assertFalse( property_exists( $card, 'requires_jpc' ), 'The card never sends the merchant to the Jetpack connection' );
	}

	/**
	 * @testdox Should leave an error response from the free extensions route alone and record nothing.
	 */
	public function test_does_not_touch_an_error_response(): void {
		$response = rest_convert_error_to_response( new \WP_Error( 'woocommerce_rest_cannot_view', 'Sorry.', array( 'status' => 403 ) ) );

		$data = $this->serve( ProfilerCard::ROUTE, $response );

		$this->assertSame( 'woocommerce_rest_cannot_view', $data['code'] );
		$this->assertFalse( get_option( ProfilerCard::SERVED_OPTION ) );
	}

	/**
	 * @testdox Should add the card after WooPayments in a plugin list with gaps in its keys, as core's Jetpack filter leaves it.
	 */
	public function test_serves_the_card_in_a_list_with_gaps(): void {
		$response = $this->free_extensions_response();
		$bundles  = $response->get_data();
		$plugins  = array_filter(
			array_merge( array( (object) array( 'key' => 'jetpack' ) ), $bundles[1]['plugins'] ),
			static function ( $plugin ): bool {
				return 'jetpack' !== $plugin->key;
			}
		);
		$this->assertSame( array( 1, 2 ), array_keys( $plugins ), 'The list starts at key 1, as array_filter() leaves it' );
		$bundles[1]['plugins'] = $plugins;
		$response->set_data( $bundles );

		$bundles = $this->serve( ProfilerCard::ROUTE, $response );

		$this->assertSame( array( 'woocommerce-payments', ProfilerCard::KEY, 'woocommerce-shipping' ), $this->profiler_keys( $bundles ) );
		$this->assertSame( array( 0, 1, 2 ), array_keys( $bundles[1]['plugins'] ) );
	}

	/**
	 * @testdox Should leave the response alone and record nothing when $scenario.
	 * @testWith ["the extension owns the wallet"]
	 *           ["the store is collecting"]
	 *           ["the transport is not configured"]
	 *           ["another route was requested"]
	 *           ["the profiler has completed"]
	 *           ["the response is not a success"]
	 *
	 * @param string $scenario The case.
	 */
	public function test_does_not_serve_the_card( string $scenario ): void {
		$route    = ProfilerCard::ROUTE;
		$response = $this->free_extensions_response();
		if ( 'the profiler has completed' === $scenario ) {
			// The Home Marketing task fetches the same route after the profiler.
			update_option( OnboardingProfile::DATA_OPTION, array( 'completed' => true ) );
		} elseif ( 'the response is not a success' === $scenario ) {
			$response->set_status( 500 );
		} elseif ( 'the extension owns the wallet' === $scenario ) {
			$this->owner = PayPalWalletRuntimeArbiter::OWNER_EXTENSION;
		} elseif ( 'the store is collecting' === $scenario ) {
			$this->set_collecting();
		} elseif ( 'the transport is not configured' === $scenario ) {
			$this->transport = new FakePlatformTransport( array( 'ready' => false ) );
		} else {
			$route = '/wc-admin/onboarding/profile';
		}

		$bundles = $this->serve( $route, $response );

		$this->assertSame( array( 'woocommerce-payments', 'woocommerce-shipping' ), $this->profiler_keys( $bundles ) );
		$this->assertFalse( get_option( ProfilerCard::SERVED_OPTION ) );
	}

	/**
	 * @testdox Should enter the collecting state with the admin email and the transport's environment when the profiler completes after the card was served, turning the gateway on.
	 */
	public function test_completing_the_profiler_enters_collecting(): void {
		$this->sut->register();
		$this->serve();

		$this->complete_profiler();

		$collecting = get_option( Options::COLLECTING );
		$this->assertSame( 'admin@example.com', $collecting['payee_email'] );
		$this->assertSame( 'sandbox', $collecting['environment'] );
		$this->assertNotSame( '', $collecting['tracking_id'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_ppcp-gateway_settings' )['enabled'], 'enter() turns the gateway on (R174)' );
		$this->assertFalse( get_option( ProfilerCard::SERVED_OPTION ), 'The record is used once' );
	}

	/**
	 * @testdox Should not enter the collecting state when the profiler completes but $scenario.
	 * @testWith ["the card was never served"]
	 *           ["the merchant skipped the Plugins page"]
	 *           ["the transport is no longer configured"]
	 *           ["the store is already collecting"]
	 *           ["the extension now owns the wallet"]
	 *           ["the store is platform connected"]
	 *
	 * @param string $scenario The case.
	 */
	public function test_completing_the_profiler_does_not_enter( string $scenario ): void {
		$this->sut->register();
		if ( 'the card was never served' !== $scenario ) {
			$this->serve();
		}
		$profile = array();
		if ( 'the merchant skipped the Plugins page' === $scenario ) {
			$profile['is_plugins_page_skipped'] = true;
		} elseif ( 'the transport is no longer configured' === $scenario ) {
			$this->transport = new FakePlatformTransport( array( 'ready' => false ) );
		} elseif ( 'the store is already collecting' === $scenario ) {
			$this->set_collecting();
		} elseif ( 'the extension now owns the wallet' === $scenario ) {
			$this->owner = PayPalWalletRuntimeArbiter::OWNER_EXTENSION;
		} elseif ( 'the store is platform connected' === $scenario ) {
			$this->set_platform_connected();
		}
		$before = get_option( Options::COLLECTING );

		$this->complete_profiler( $profile );

		$this->assertSame( $before, get_option( Options::COLLECTING ) );
		$this->assertSame( 'no', get_option( 'woocommerce_ppcp-gateway_settings' )['enabled'] );
	}

	/**
	 * @testdox Should hook the dispatch filter and the completion action once, however often register() runs.
	 */
	public function test_register_hooks_once(): void {
		$this->sut->register();
		$this->sut->register();

		$this->assertSame( 10, has_filter( 'rest_post_dispatch', array( $this->sut, 'handle_rest_post_dispatch' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_onboarding_profile_completed', array( $this->sut, 'handle_woocommerce_onboarding_profile_completed' ) ) );
	}
}

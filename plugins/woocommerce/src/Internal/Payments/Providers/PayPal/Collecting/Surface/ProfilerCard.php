<?php
/**
 * ProfilerCard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Admin\Onboarding\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\RuntimeServices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Throwable;
use WP_HTTP_Response;
use WP_REST_Request;

/**
 * The PayPal Wallet card on the core profiler's Plugins page, shown as included rather than offered for install, and
 * the collecting state the store enters when the profiler completes after the card was shown.
 *
 * Core's free extensions route has no filter of its own, so the card is added to that route's response. It is shown
 * only while the core profiler has not completed, core owns the wallet, the store is dormant and the platform transport
 * is configured. Serving it is recorded in a small option, which the profiler's completion reads once and deletes.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class ProfilerCard {

	/**
	 * The card's key in the bundle. It names no plugin, and the card is never installed.
	 *
	 * @since 11.3.0
	 */
	public const KEY = 'paypal-wallet';

	/**
	 * The free extensions bundle the core profiler shows.
	 *
	 * @since 11.3.0
	 */
	public const BUNDLE = 'obw/core-profiler';

	/**
	 * The route that answers the free extensions.
	 *
	 * @since 11.3.0
	 */
	public const ROUTE = '/wc-admin/onboarding/free-extensions';

	/**
	 * Records that the card was served to the profiler: the time it was first served. Not autoloaded; deleted when the
	 * profiler completes.
	 *
	 * @since 11.3.0
	 */
	public const SERVED_OPTION = 'wc_paypal_wallet_profiler_card_served';

	/**
	 * The card goes after this plugin, next to it on the Plugins page.
	 */
	private const NEXT_TO = 'woocommerce-payments';

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Gives the transport, or null for the runtime one.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * The arbiter, or null for core's.
	 *
	 * @var PayPalWalletRuntimeArbiter|null
	 */
	private ?PayPalWalletRuntimeArbiter $arbiter;

	/**
	 * Constructor.
	 *
	 * @param Options|null                    $options   The option reader; the stored options by default.
	 * @param callable|null                   $transport Gives the transport; the runtime one by default.
	 * @param PayPalWalletRuntimeArbiter|null $arbiter   The arbiter; core's by default.
	 */
	public function __construct( ?Options $options = null, ?callable $transport = null, ?PayPalWalletRuntimeArbiter $arbiter = null ) {
		$this->options   = $options ?? new Options();
		$this->transport = $transport;
		$this->arbiter   = $arbiter;
	}

	/**
	 * Hook the card into the free extensions response and the profiler's completion. Hooking reads nothing.
	 *
	 * @since 11.3.0
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'handle_rest_post_dispatch' ), 10, 3 );
		add_action( 'woocommerce_onboarding_profile_completed', array( $this, 'handle_woocommerce_onboarding_profile_completed' ) );
	}

	/**
	 * Add the card to the core profiler bundle of the free extensions response while the profiler has not completed, and
	 * record that it was served.
	 *
	 * Any other route returns at once, without a query.
	 *
	 * @internal
	 * @since 11.3.0
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The request.
	 * @return mixed The response, with the card added when it applies.
	 */
	public function handle_rest_post_dispatch( $response, $server = null, $request = null ) {
		unset( $server );
		if ( ! $request instanceof WP_REST_Request || self::ROUTE !== $request->get_route() || ! $response instanceof WP_HTTP_Response || 200 !== $response->get_status() ) {
			return $response;
		}
		$bundles = $response->get_data();
		// The route also serves the Home Marketing task: only a profiler that can still complete gets the card.
		if ( ! is_array( $bundles ) || ! OnboardingProfile::needs_completion() || ! $this->should_offer() ) {
			return $response;
		}

		$added = false;
		foreach ( $bundles as $index => $bundle ) {
			if ( ! is_array( $bundle ) || self::BUNDLE !== ( $bundle['key'] ?? null ) || ! is_array( $bundle['plugins'] ?? null ) ) {
				continue;
			}
			$bundles[ $index ]['plugins'] = $this->with_card( $bundle['plugins'] );
			$added                        = true;
		}
		if ( ! $added ) {
			return $response;
		}

		$response->set_data( $bundles );
		add_option( self::SERVED_OPTION, time(), '', false );

		return $response;
	}

	/**
	 * Enter the collecting state with the admin email when the profiler completes after the card was served, unless the
	 * merchant skipped the Plugins page or the store can no longer collect. The record is deleted either way.
	 *
	 * @internal
	 * @since 11.3.0
	 */
	public function handle_woocommerce_onboarding_profile_completed(): void {
		if ( false === get_option( self::SERVED_OPTION, false ) ) {
			return;
		}
		delete_option( self::SERVED_OPTION );

		$profile = get_option( OnboardingProfile::DATA_OPTION, array() );
		if ( is_array( $profile ) && ! empty( $profile['is_plugins_page_skipped'] ) ) {
			return;
		}
		$email = get_option( 'admin_email' );
		if ( ! is_string( $email ) || ! is_email( $email ) || ! $this->should_offer() ) {
			return;
		}

		try {
			( new CollectingState( $this->options, new HeldOrders() ) )->enter( $email, $this->transport()->environment() );
		} catch ( Throwable $failure ) {
			wc_get_logger()->warning(
				sprintf( 'PayPal wallet could not start collecting after the profiler: %1$s: %2$s', get_class( $failure ), $failure->getMessage() ),
				array( 'source' => 'woocommerce-paypal-wallet' )
			);
		}
	}

	/**
	 * The card, as the profiler reads a plugin. It reads as installed and activated, so the Plugins page never selects
	 * it and the profiler never asks to install it; `is_included` tells the page to show it as included.
	 *
	 * @since 11.3.0
	 *
	 * @return object
	 */
	public function card(): object {
		return (object) array(
			'key'            => self::KEY,
			'name'           => 'PayPal Wallet',
			'label'          => __( 'Give shoppers a variety of ways to pay', 'woocommerce' ),
			'description'    => __( 'Offer additional payment options with PayPal Wallet', 'woocommerce' ),
			'image_url'      => plugins_url( 'assets/images/onboarding/icons/paypal.svg', WC_PLUGIN_FILE ),
			'manage_url'     => '',
			'is_built_by_wc' => true,
			'is_visible'     => true,
			'is_installed'   => true,
			'is_activated'   => true,
			'is_included'    => true,
		);
	}

	/**
	 * Whether the store can be offered collecting: core owns the wallet, the store is dormant and the transport is ready.
	 *
	 * @return bool
	 */
	private function should_offer(): bool {
		if ( ! RuntimeServices::core_owns_wallet( $this->arbiter ) ) {
			return false;
		}

		return ConnectionState::DORMANT === ( new ConnectionState( $this->options ) )->resolve() && $this->transport()->is_ready();
	}

	/**
	 * The plugins with the card right after WooPayments, or first when WooPayments is not offered. Unchanged when the
	 * card is already there.
	 *
	 * @param array $plugins The bundle's plugins.
	 * @return array
	 */
	private function with_card( array $plugins ): array {
		$position = 0;
		foreach ( array_values( $plugins ) as $index => $plugin ) {
			$key = is_object( $plugin ) ? ( $plugin->key ?? '' ) : ( is_array( $plugin ) ? ( $plugin['key'] ?? '' ) : '' );
			$key = is_string( $key ) ? explode( ':', $key )[0] : '';
			if ( self::KEY === $key ) {
				return $plugins;
			}
			if ( self::NEXT_TO === $key ) {
				$position = $index + 1;
			}
		}

		$plugins = array_values( $plugins );
		array_splice( $plugins, $position, 0, array( $this->card() ) );

		return $plugins;
	}

	/**
	 * The transport.
	 *
	 * @return PlatformTransport
	 */
	private function transport(): PlatformTransport {
		return null === $this->transport ? RuntimeServices::transport() : ( $this->transport )();
	}
}

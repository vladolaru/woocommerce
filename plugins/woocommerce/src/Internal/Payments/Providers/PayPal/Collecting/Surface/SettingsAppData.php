<?php
/**
 * SettingsAppData class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Rest\CollectingRestEndpoint;

/**
 * What the wallet's settings app gets on a store the platform serves: the collecting panel's data and the Troubleshooting
 * block's webhooks note as `ppcpSettings.collecting`, and a style that hides the Order Intent block, since such a store
 * always captures. The style is a POC hack: it depends on the forked app's `ppcp--order-intent` class.
 *
 * The wallet's script data has no filter, so both are added from the action it fires once the settings app's script and
 * stylesheet are registered and localized. The inline script runs after the localized `ppcpSettings`.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class SettingsAppData {

	/**
	 * The handle of the settings app's script and stylesheet.
	 *
	 * @since 11.3.0
	 */
	public const HANDLE = 'ppcp-admin-settings';

	/**
	 * Hides the Order Intent block, whose two toggles only apply to authorize-only.
	 */
	private const HIDE_ORDER_INTENT = '#ppcp-settings-container .ppcp--order-intent { display: none; }';

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The collecting panel's endpoint, which builds the data.
	 *
	 * @var CollectingRestEndpoint
	 */
	private CollectingRestEndpoint $endpoint;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState        $connection_state The connection state.
	 * @param CollectingRestEndpoint $endpoint         The collecting panel's endpoint, which builds the data.
	 */
	public function __construct( ConnectionState $connection_state, CollectingRestEndpoint $endpoint ) {
		$this->connection_state = $connection_state;
		$this->endpoint         = $endpoint;
	}

	/**
	 * Add the collecting key and the Order Intent style to the settings app, while the platform serves the store.
	 *
	 * @internal
	 * @since 11.3.0
	 */
	public function handle_woocommerce_paypal_payments_settings_scripts_enqueued(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) || ! $this->connection_state->is_served_by_platform() ) {
			return;
		}

		$payload                  = $this->endpoint->payload();
		$payload['webhooks_note'] = $this->webhooks_note( $payload['state'] );

		$data = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
		if ( false === $data ) {
			return;
		}

		wp_add_inline_script( self::HANDLE, 'window.ppcpSettings = window.ppcpSettings || {}; window.ppcpSettings.collecting = ' . $data . ';', 'before' );
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_add_inline_style( self::HANDLE, self::HIDE_ORDER_INTENT );
		}
	}

	/**
	 * The note the settings app's Troubleshooting block shows in place of its webhook tools, escaped: the block renders it as HTML.
	 *
	 * @param string $state The connection state.
	 * @return string
	 */
	private function webhooks_note( string $state ): string {
		return ConnectionState::COLLECTING === $state
			? esc_html__( 'Webhooks are managed by WooCommerce while PayPal Wallet setup is in progress.', 'woocommerce' )
			: esc_html__( 'Webhooks are managed by WooCommerce.', 'woocommerce' );
	}
}

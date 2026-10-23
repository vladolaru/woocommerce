<?php
/**
 * The endpoint for saving the Pay Later messaging config from the configurator.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Endpoint;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PayLaterMessagingSettings;

/**
 * Class SaveConfig.
 */
class SaveConfig {
	const ENDPOINT = 'ppc-save-message-config';

	/**
	 * The Pay Later messaging settings.
	 *
	 * @var PayLaterMessagingSettings
	 */
	protected PayLaterMessagingSettings $settings;

	/**
	 * The request data helper.
	 *
	 * @var RequestData
	 */
	protected RequestData $request_data;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * SaveConfig constructor.
	 *
	 * @param PayLaterMessagingSettings $settings     The Pay Later messaging settings.
	 * @param RequestData               $request_data The request data helper.
	 * @param LoggerInterface           $logger       The logger.
	 */
	public function __construct(
		PayLaterMessagingSettings $settings,
		RequestData $request_data,
		LoggerInterface $logger
	) {
		$this->settings     = $settings;
		$this->request_data = $request_data;
		$this->logger       = $logger;
	}

	/**
	 * Returns the nonce action of the endpoint.
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Not admin.', 403 );
		}

		try {
			$data = $this->request_data->read_request( $this->nonce() );

			$this->save_config( $data['config']['config'] );

			wp_send_json_success();
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( Throwable $error ) {
			$this->logger->error( "SaveConfig execution failed. {$error->getMessage()} {$error->getFile()}:{$error->getLine()}" );

			wp_send_json_error();
		}
	}

	/**
	 * Saves the configurator's config as Pay Later messaging settings.
	 *
	 * @param array $config The config, keyed by placement.
	 */
	public function save_config( array $config ): void {
		$this->settings->set_styling_per_location( true );
		$this->settings->set_messaging_enabled( true );

		$enabled_locations = array();
		foreach ( $config as $placement => $data ) {
			if ( 'custom_placement' === $placement ) {
				$data = $data[0] ?? array();
			}

			$this->settings->set_location_from_config( $placement, $data );

			if ( ( $data['status'] ?? '' ) === 'enabled' ) {
				$enabled_locations[] = $placement;
			}
		}

		$this->settings->set_messaging_locations( $enabled_locations );
		$this->settings->save();
	}
}

<?php
/**
 * Creates Webhooks.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class WebhookFactory
 */
class WebhookFactory {

	/**
	 * Returns a webhook for a URL with an array of event types associated to this URL.
	 *
	 * @param string   $url The URL.
	 * @param string[] $event_types The event types to which this URL listens to.
	 *
	 * @return Webhook
	 */
	public function for_url_and_events( string $url, array $event_types ): Webhook {
		$event_types = array_map(
			static function ( string $type ) {
				return (object) array( 'name' => $type );
			},
			$event_types
		);
		return new Webhook(
			$url,
			$event_types
		);
	}

	/**
	 * Returns a webhook from a given data array.
	 *
	 * @param array $data The data array.
	 *
	 * @return Webhook
	 */
	public function from_array( array $data ): Webhook {
		return $this->from_paypal_response( (object) $data );
	}

	/**
	 * Returns a Webhook based of a PayPal JSON response.
	 *
	 * @param object $data The JSON object.
	 *
	 * @return Webhook
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( $data ): Webhook {
		if ( ! isset( $data->id ) ) {
			throw new RuntimeException( 'No id for webhook given.' );
		}
		if ( ! isset( $data->url ) ) {
			throw new RuntimeException( 'No URL for webhook given.' );
		}
		if ( ! isset( $data->event_types ) ) {
			throw new RuntimeException( 'No event types for webhook given.' );
		}

		return new Webhook(
			(string) $data->url,
			(array) $data->event_types,
			(string) $data->id
		);
	}
}

<?php
/**
 * The PaymentSource object.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use stdClass;

/**
 * Class PaymentSource
 */
class PaymentSource {

	/**
	 * Payment source name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Payment source properties.
	 *
	 * @var object
	 */
	private $properties;

	/**
	 * PaymentSource constructor.
	 *
	 * @param string $name Payment source name.
	 * @param object $properties Payment source properties.
	 */
	public function __construct( string $name, object $properties ) {
		$this->name       = $name;
		$this->properties = $properties;
	}

	/**
	 * Payment source name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Payment source properties.
	 *
	 * @return object
	 */
	public function properties(): object {
		return $this->properties;
	}
}

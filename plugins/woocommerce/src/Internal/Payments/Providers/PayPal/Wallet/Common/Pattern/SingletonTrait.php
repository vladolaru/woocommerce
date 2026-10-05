<?php
/**
 * The Singleton Trait can be used to add singleton behaviour to a class.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Common\Pattern
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Common\Pattern;

/**
 * Class SingletonTrait.
 *
 * @phpstan-ignore trait.unused (inherited from the extension and used by no wallet class; kept for drift porting)
 */
trait SingletonTrait {
	/**
	 * The single instance of the class.
	 *
	 * @var self
	 */
	protected static $instance = null;

	/**
	 * Static method to get the instance of the Singleton class
	 *
	 * @return self|null
	 */
	public static function get_instance(): ?self {
		return self::$instance;
	}

	/**
	 * Static method to get the instance of the Singleton class
	 *
	 * @param self $instance The instance to store.
	 * @return self
	 */
	protected static function set_instance( self $instance ): self {
		self::$instance = $instance;
		return self::$instance;
	}
}

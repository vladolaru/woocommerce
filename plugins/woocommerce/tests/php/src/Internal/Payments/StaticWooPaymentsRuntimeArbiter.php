<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;

/**
 * Static arbiter for registry tests.
 */
class StaticWooPaymentsRuntimeArbiter extends WooPaymentsRuntimeArbiter {

	/**
	 * Whether native should register.
	 *
	 * @var bool
	 */
	private bool $is_builtin_owner;

	/**
	 * Constructor.
	 *
	 * @param bool $is_builtin_owner Whether native should register.
	 */
	public function __construct( bool $is_builtin_owner ) {
		$this->is_builtin_owner = $is_builtin_owner;
	}

	/**
	 * Tell whether core-native code may perform mutating registration for this site.
	 *
	 * @return bool
	 */
	public function is_builtin_owner(): bool {
		return $this->is_builtin_owner;
	}
}

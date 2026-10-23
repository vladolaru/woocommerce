<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;

/**
 * A module that binds a given transport as `collecting.transport`, added after the collecting module.
 */
final class TransportBindingModule implements ExtendingModule {
	use ModuleClassNameIdTrait;

	/**
	 * The transport to bind.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * Constructor.
	 *
	 * @param PlatformTransport $transport The transport to bind.
	 */
	public function __construct( PlatformTransport $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Replace the collecting transport with the bound one.
	 *
	 * @return array
	 */
	public function extensions(): array {
		$transport = $this->transport;

		return array(
			'collecting.transport' => static function () use ( $transport ): PlatformTransport {
				return $transport;
			},
		);
	}
}

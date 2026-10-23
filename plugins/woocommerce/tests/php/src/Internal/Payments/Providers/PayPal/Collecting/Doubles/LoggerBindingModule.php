<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * A module that binds a given logger as the wallet's logger, added before the collecting module so its extension wraps it.
 */
final class LoggerBindingModule implements ExtendingModule {
	use ModuleClassNameIdTrait;

	/**
	 * The logger to bind.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger to bind.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Replace the wallet's logger with the bound one.
	 *
	 * @return array
	 */
	public function extensions(): array {
		$logger = $this->logger;

		return array(
			'woocommerce.logger.woocommerce' => static function () use ( $logger ): LoggerInterface {
				return $logger;
			},
		);
	}
}

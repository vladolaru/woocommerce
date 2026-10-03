<?php
/**
 * The logging services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\Logger\NullLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\Logger\WooCommerceLogger;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

return array(
	'woocommerce.logger.source'      => function (): string {
		return 'woocommerce';
	},
	'woocommerce.logger.woocommerce' => function ( ContainerInterface $container ): LoggerInterface {
		if ( ! class_exists( \WC_Logger::class ) ) {
			return new NullLogger();
		}

		$source = $container->get( 'woocommerce.logger.source' );

		return new WooCommerceLogger(
			wc_get_logger(),
			$source
		);
	},
);

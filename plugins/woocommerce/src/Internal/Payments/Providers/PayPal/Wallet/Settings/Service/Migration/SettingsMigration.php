<?php
/**
 * Handles migration of general settings from legacy format to new structure.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\SellerTypeEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SellerTypeResolver;

/**
 * Class SettingsMigration
 *
 * Handles migration of general plugin settings.
 */
class SettingsMigration implements SettingsMigrationInterface {

	/**
	 * The legacy settings of the PayPal Payments extension.
	 *
	 * @var array<string, mixed>
	 */
	protected array $settings;

	/**
	 * The general settings.
	 *
	 * @var GeneralSettings
	 */
	protected GeneralSettings $general_settings;

	/**
	 * The partners endpoint.
	 *
	 * @var PartnersEndpoint
	 */
	protected PartnersEndpoint $partners_endpoint;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected LoggerInterface $logger;

	/**
	 * The seller type resolver.
	 *
	 * @var SellerTypeResolver
	 */
	protected SellerTypeResolver $seller_type_resolver;

	/**
	 * Constructor.
	 *
	 * @param array              $settings The legacy settings of the PayPal Payments extension.
	 * @param GeneralSettings    $general_settings The general settings.
	 * @param PartnersEndpoint   $partners_endpoint The partners endpoint.
	 * @param LoggerInterface    $logger The logger.
	 * @param SellerTypeResolver $seller_type_resolver The seller type resolver.
	 */
	public function __construct(
		array $settings,
		GeneralSettings $general_settings,
		PartnersEndpoint $partners_endpoint,
		LoggerInterface $logger,
		SellerTypeResolver $seller_type_resolver
	) {
		$this->settings             = $settings;
		$this->general_settings     = $general_settings;
		$this->partners_endpoint    = $partners_endpoint;
		$this->logger               = $logger;
		$this->seller_type_resolver = $seller_type_resolver;
	}

	/**
	 * Whether the general settings hold a connected merchant.
	 *
	 * @return bool
	 */
	public function is_merchant_connected(): bool {
		return $this->general_settings->is_merchant_connected();
	}

	/**
	 * Migrates the connection details and the seller type from the legacy settings.
	 */
	public function migrate(): void {
		if ( empty( $this->settings['client_id'] )
			|| empty( $this->settings['client_secret'] )
			|| empty( $this->settings['merchant_id'] ) ) {
			return;
		}

		// Save credentials first so they persist even if the API call fails.
		$connection = new MerchantConnectionDTO(
			! empty( $this->settings['sandbox_on'] ),
			$this->settings['client_id'],
			$this->settings['client_secret'],
			$this->settings['merchant_id'],
			$this->settings['merchant_email'] ?? '',
			'',
			SellerTypeEnum::UNKNOWN
		);
		$this->general_settings->set_merchant_data( $connection );
		$this->general_settings->save();

		// Resolve seller type — exception propagates so migration can retry.
		$seller_status = $this->partners_endpoint->seller_status();
		$connection    = new MerchantConnectionDTO(
			! empty( $this->settings['sandbox_on'] ),
			$this->settings['client_id'],
			$this->settings['client_secret'],
			$this->settings['merchant_id'],
			$this->settings['merchant_email'] ?? '',
			$seller_status->country(),
			$this->seller_type_resolver->resolve( $seller_status )
		);
		$this->general_settings->set_merchant_data( $connection );
		$this->general_settings->save();
	}
}

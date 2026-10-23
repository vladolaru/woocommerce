<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\VaultComponent\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ReferenceTransactionStatus;

/**
 * Decides whether the vault component applies to the merchant and their country.
 */
class VaultComponentApplies {

	/**
	 * The merchant country.
	 *
	 * @var string
	 */
	private string $country;

	/**
	 * The reference transaction status helper.
	 *
	 * @var ReferenceTransactionStatus
	 */
	private ReferenceTransactionStatus $reference_transaction_status;

	/**
	 * VaultComponentApplies constructor.
	 *
	 * @param string                     $country                      The merchant country.
	 * @param ReferenceTransactionStatus $reference_transaction_status The reference transaction status helper.
	 */
	public function __construct(
		string $country,
		ReferenceTransactionStatus $reference_transaction_status
	) {
		$this->country                      = $country;
		$this->reference_transaction_status = $reference_transaction_status;
	}

	/**
	 * Whether the vault component is supported in the merchant country.
	 */
	public function for_country(): bool {
		return in_array( $this->country, $this->supported_countries(), true );
	}

	/**
	 * The countries where the vault component is supported.
	 *
	 * @return string[]
	 */
	private function supported_countries(): array {
		/**
		 * Filters the countries where the vault component is supported.
		 *
		 * @since 11.3.0
		 *
		 * @param string[] $countries The supported country codes.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_vault_component_supported_countries',
			array( 'US' )
		);
	}

	/**
	 * Checks PAYPAL_WALLET_VAULTING_ADVANCED capability.
	 */
	public function for_merchant(): bool {
		return $this->reference_transaction_status->reference_transaction_enabled();
	}
}

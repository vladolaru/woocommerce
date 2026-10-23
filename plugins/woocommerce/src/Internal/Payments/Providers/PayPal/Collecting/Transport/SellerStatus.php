<?php
/**
 * SellerStatus class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

/**
 * Where a seller's onboarding stands, as the platform reads it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class SellerStatus {

	/**
	 * The seller's merchant ID, or an empty string while PayPal has none.
	 *
	 * @var string
	 */
	private string $merchant_id;

	/**
	 * Whether the seller can receive payments.
	 *
	 * @var bool
	 */
	private bool $payments_receivable;

	/**
	 * Whether the seller confirmed their primary email.
	 *
	 * @var bool
	 */
	private bool $primary_email_confirmed;

	/**
	 * Whether the seller granted the platform consent.
	 *
	 * @var bool
	 */
	private bool $consent_granted;

	/**
	 * Constructor.
	 *
	 * @param string $merchant_id             The seller's merchant ID, or an empty string.
	 * @param bool   $payments_receivable     Whether the seller can receive payments.
	 * @param bool   $primary_email_confirmed Whether the seller confirmed their primary email.
	 * @param bool   $consent_granted         Whether the seller granted the platform consent.
	 */
	public function __construct( string $merchant_id, bool $payments_receivable, bool $primary_email_confirmed, bool $consent_granted ) {
		$this->merchant_id             = $merchant_id;
		$this->payments_receivable     = $payments_receivable;
		$this->primary_email_confirmed = $primary_email_confirmed;
		$this->consent_granted         = $consent_granted;
	}

	/**
	 * The seller's merchant ID, or an empty string.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function merchant_id(): string {
		return $this->merchant_id;
	}

	/**
	 * Whether the seller can receive payments.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function payments_receivable(): bool {
		return $this->payments_receivable;
	}

	/**
	 * Whether the seller confirmed their primary email.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function primary_email_confirmed(): bool {
		return $this->primary_email_confirmed;
	}

	/**
	 * Whether the seller granted the platform consent.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function consent_granted(): bool {
		return $this->consent_granted;
	}

	/**
	 * Whether onboarding is complete: a merchant ID, receivable payments, a confirmed email and granted consent.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return '' !== $this->merchant_id && $this->payments_receivable && $this->primary_email_confirmed && $this->consent_granted;
	}
}

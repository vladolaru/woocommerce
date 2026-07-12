<?php
/**
 * WooPaymentsOrderEffectPlan class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectPlan;

/**
 * Immutable request-scoped plan for WooPayments order effects.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderEffectPlan implements ProviderOperationEffectPlan {

	/**
	 * PaymentIntent effect plan.
	 *
	 * @var string
	 */
	public const TYPE_PAYMENT_INTENT = 'payment_intent';

	/**
	 * SetupIntent effect plan.
	 *
	 * @var string
	 */
	public const TYPE_SETUP_INTENT = 'setup_intent';

	/**
	 * Capture effect plan.
	 *
	 * @var string
	 */
	public const TYPE_CAPTURE = 'capture';

	/**
	 * Authorization cancellation effect plan.
	 *
	 * @var string
	 */
	public const TYPE_CANCEL = 'cancel';

	/**
	 * Refund effect plan.
	 *
	 * @var string
	 */
	public const TYPE_REFUND = 'refund';

	/**
	 * Effect plan type.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Provider response facts.
	 *
	 * @var array<string,mixed>
	 */
	private array $provider_result;

	/**
	 * Whether authorized token effects should run.
	 *
	 * @var bool
	 */
	private bool $apply_token_effects;

	/**
	 * Whether token persistence is required for a recurring payment.
	 *
	 * @var bool
	 */
	private bool $is_recurring;

	/**
	 * SetupIntent metadata to persist before lifecycle application.
	 *
	 * @var array<string,string>
	 */
	private array $setup_meta;

	/**
	 * Constructor.
	 *
	 * @param string               $type                Effect plan type.
	 * @param array<string,mixed>  $provider_result     Provider response facts.
	 * @param bool                 $apply_token_effects Whether token effects should run.
	 * @param bool                 $is_recurring        Whether recurring token persistence is required.
	 * @param array<string,string> $setup_meta          SetupIntent metadata.
	 */
	private function __construct( string $type, array $provider_result, bool $apply_token_effects, bool $is_recurring, array $setup_meta = array() ) {
		$this->type                = $type;
		$this->provider_result     = $provider_result;
		$this->apply_token_effects = $apply_token_effects;
		$this->is_recurring        = $is_recurring;
		$this->setup_meta          = $setup_meta;
	}

	/**
	 * Build a PaymentIntent effect plan.
	 *
	 * @param array<string,mixed> $provider_result Provider PaymentIntent response.
	 * @param bool                $is_recurring    Whether recurring token persistence is required.
	 * @return self
	 */
	public static function for_payment_intent( array $provider_result, bool $is_recurring ): self {
		$status = isset( $provider_result['status'] ) ? (string) $provider_result['status'] : '';

		return new self(
			self::TYPE_PAYMENT_INTENT,
			$provider_result,
			in_array( $status, array( 'succeeded', 'requires_capture', 'processing' ), true ),
			$is_recurring
		);
	}

	/**
	 * Build a SetupIntent effect plan.
	 *
	 * @param array<string,mixed>  $provider_result Provider SetupIntent response.
	 * @param bool                 $is_recurring    Whether recurring token persistence is required.
	 * @param array<string,string> $setup_meta      SetupIntent metadata.
	 * @return self
	 */
	public static function for_setup_intent( array $provider_result, bool $is_recurring, array $setup_meta ): self {
		$status = isset( $provider_result['status'] ) ? (string) $provider_result['status'] : '';

		return new self(
			self::TYPE_SETUP_INTENT,
			$provider_result,
			'succeeded' === $status,
			$is_recurring,
			$setup_meta
		);
	}

	/**
	 * Build a capture effect plan.
	 *
	 * @param array<string,mixed> $provider_result Provider capture response.
	 * @return self
	 */
	public static function for_capture( array $provider_result ): self {
		return new self( self::TYPE_CAPTURE, $provider_result, false, false );
	}

	/**
	 * Build an authorization cancellation effect plan.
	 *
	 * @param array<string,mixed> $provider_result Provider cancellation response.
	 * @return self
	 *
	 * @since 11.0.0
	 */
	public static function for_cancel( array $provider_result ): self {
		return new self( self::TYPE_CANCEL, $provider_result, false, false );
	}

	/**
	 * Build a refund effect plan.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $provider_result Provider refund response.
	 * @return self
	 */
	public static function for_refund( array $provider_result ): self {
		return new self( self::TYPE_REFUND, $provider_result, false, false );
	}

	/**
	 * Get the plan type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Get provider response facts.
	 *
	 * @return array<string,mixed>
	 */
	public function get_provider_result(): array {
		return $this->provider_result;
	}

	/**
	 * Tell whether authorized token effects should run.
	 *
	 * @return bool
	 */
	public function should_apply_token_effects(): bool {
		return $this->apply_token_effects;
	}

	/**
	 * Tell whether recurring token persistence is required.
	 *
	 * @return bool
	 */
	public function is_recurring(): bool {
		return $this->is_recurring;
	}

	/**
	 * Get SetupIntent metadata.
	 *
	 * @return array<string,string>
	 */
	public function get_setup_meta(): array {
		return $this->setup_meta;
	}
}

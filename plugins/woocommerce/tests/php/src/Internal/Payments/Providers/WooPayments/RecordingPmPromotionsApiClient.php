<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Recording API client for promotion service tests.
 */
class RecordingPmPromotionsApiClient extends WooPaymentsApiClient {

	/**
	 * Promotions response.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $promotions_response = array();

	/**
	 * Platform cache-for header sent with the promotions, in seconds; '0' means do not cache (client 11.1.0
	 * `includes/class-wc-payments-pm-promotions-service.php:224-254`).
	 *
	 * @var string
	 */
	public string $cache_for = '';

	/**
	 * Activated promotion IDs.
	 *
	 * @var string[]
	 */
	public array $activated_promotion_ids = array();

	/**
	 * Number of promotion fetches.
	 *
	 * @var int
	 */
	public int $get_pm_promotions_calls = 0;

	/**
	 * Exception the promotions fetch throws, when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $promotions_exception = null;

	/**
	 * Exception the activation throws, when set.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $activation_exception = null;

	/**
	 * Retrieve PM promotions.
	 *
	 * @param array<string,mixed> $store_context Store context.
	 * @return array{promotions:array<int|string,mixed>,cache_for:string}
	 */
	public function get_pm_promotions( array $store_context ): array {
		++$this->get_pm_promotions_calls;
		if ( null !== $this->promotions_exception ) {
			throw $this->promotions_exception;
		}

		return array(
			'promotions' => $this->promotions_response,
			'cache_for'  => $this->cache_for,
		);
	}

	/**
	 * Activate a PM promotion.
	 *
	 * @param string $promotion_id Promotion ID.
	 * @return array<string,mixed>
	 */
	public function activate_pm_promotion( string $promotion_id ): array {
		if ( null !== $this->activation_exception ) {
			throw $this->activation_exception;
		}

		$this->activated_promotion_ids[] = $promotion_id;

		return array( 'success' => true );
	}
}

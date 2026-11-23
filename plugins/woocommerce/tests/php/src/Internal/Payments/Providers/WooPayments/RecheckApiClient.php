<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;

/**
 * A platform client double: it is always available, and answers account reads and deletes with the case's callbacks.
 */
class RecheckApiClient extends Client {

	/**
	 * Answers an account read: returns the account or throws.
	 *
	 * @var callable|null
	 */
	public $answer = null;

	/**
	 * Answers an account delete: returns the answer or throws.
	 *
	 * @var callable|null
	 */
	public $delete_answer = null;

	/**
	 * Account reads made without side effects (the re-check's).
	 *
	 * @var int
	 */
	public int $plain_gets = 0;

	/**
	 * Ordinary account reads.
	 *
	 * @var int
	 */
	public int $foreground_gets = 0;

	/**
	 * The platform is reachable.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Answer an ordinary account read.
	 *
	 * @param string $woocommerce_store_id WooCommerce store ID.
	 * @return array<string,mixed>
	 */
	public function get_account( string $woocommerce_store_id = '' ): array {
		++$this->foreground_gets;

		return null === $this->answer ? array() : ( $this->answer )();
	}

	/**
	 * Answer the re-check's account read.
	 *
	 * @param string $woocommerce_store_id WooCommerce store ID.
	 * @return array<string,mixed>
	 */
	public function get_account_without_side_effects( string $woocommerce_store_id = '' ): array {
		++$this->plain_gets;

		return null === $this->answer ? array() : ( $this->answer )();
	}

	/**
	 * Answer an account delete.
	 *
	 * @param bool $test_mode Whether to delete a test-mode account.
	 * @return array<string,mixed>
	 */
	public function delete_account( bool $test_mode = false ): array {
		return null === $this->delete_answer ? array( 'result' => 'success' ) : ( $this->delete_answer )();
	}
}

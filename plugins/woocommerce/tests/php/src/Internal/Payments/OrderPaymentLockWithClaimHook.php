<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use WC_Order;

/**
 * Order payment lock that runs a change on its first claim, as another request finishing just before it would, then
 * grants every claim without holding a lock.
 */
class OrderPaymentLockWithClaimHook extends OrderPaymentLock {

	/**
	 * Whether the change ran.
	 *
	 * @var bool
	 */
	public bool $hook_ran = false;

	/**
	 * Change to run on the first claim, given the order being locked.
	 *
	 * @var callable(WC_Order):void
	 */
	private $hook;

	/**
	 * Constructor.
	 *
	 * @param callable(WC_Order):void $hook Change to run on the first claim.
	 */
	public function __construct( callable $hook ) {
		$this->hook = $hook;
	}

	/**
	 * Run the change once, then grant the claim.
	 *
	 * @param WC_Order                               $order      Order being locked.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary Persistence vocabulary.
	 * @param string|null                            $reference  Payment reference.
	 * @param string                                 $operation  Operation claiming the lock.
	 * @return string|null
	 */
	public function claim( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
		unset( $vocabulary, $reference, $operation );
		if ( ! $this->hook_ran ) {
			$this->hook_ran = true;
			( $this->hook )( $order );
		}

		return 'test_lock_token';
	}

	/**
	 * Release nothing: the claim holds no lock.
	 *
	 * @param WC_Order                               $order      Order being unlocked.
	 * @param ProviderPersistenceVocabularyInterface $vocabulary Persistence vocabulary.
	 * @param string                                 $lock_token Claim token.
	 */
	public function release( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
		unset( $order, $vocabulary, $lock_token );
	}
}

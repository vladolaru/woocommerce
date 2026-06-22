<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Throwable;

/**
 * Event ingestor test double that always throws a preset exception.
 */
class ThrowingEventIngestor extends WooPaymentsEventIngestor {

	/**
	 * Exception to throw when processing.
	 *
	 * @var Throwable
	 */
	private Throwable $exception;

	/**
	 * Constructor.
	 *
	 * @param Throwable $exception Exception to throw from process().
	 */
	public function __construct( Throwable $exception ) {
		$this->exception = $exception;
	}

	/**
	 * Throw the preset exception instead of processing the event.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws Throwable Always.
	 */
	public function process( array $event ): void {
		unset( $event ); // Avoid parameter not used PHPCS errors.
		throw $this->exception;
	}
}

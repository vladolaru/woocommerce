<?php
/**
 * Request and registry fixtures for WooPayments abilities.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities;

/** Give each test its own lazy WordPress registry and WooPayments setup. */
abstract class AbilityTestCase extends \WC_Unit_Test_Case {
	use AbilityRegistryFixture;

	protected const PREFIX = 'woocommerce-payments/';
	protected const GATE   = 'woocommerce_payments_abilities_enabled';
	protected const NAMES  = array(
		'get-account',
		'get-active-loan-summary',
		'get-authorizations',
		'get-authorizations-summary',
		'get-balance',
		'get-charge',
		'get-deposits',
		'get-deposits-overview',
		'get-deposits-summary',
		'get-dispute',
		'get-disputes',
		'get-disputes-summary',
		'get-fees-summary',
		'get-fraud-outcomes',
		'get-payment-intent',
		'get-timeline',
		'get-transactions',
		'get-transactions-summary',
		'refund-charge',
		'submit-dispute-evidence',
		'upload-dispute-evidence-file',
	);

	/** Start a fresh request before discovering abilities. */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_ability_registry();
	}

	/** Restore the previous request. */
	public function tearDown(): void {
		try {
			$this->restore_ability_registry();
		} finally {
			parent::tearDown();
		}
	}

	/** Discover the provider names through the WordPress registry. */
	protected function provider_names(): array {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), self::PREFIX ) ) {
				$names[] = substr( $ability->get_name(), strlen( self::PREFIX ) );
			}
		}
		sort( $names );
		return $names;
	}
}

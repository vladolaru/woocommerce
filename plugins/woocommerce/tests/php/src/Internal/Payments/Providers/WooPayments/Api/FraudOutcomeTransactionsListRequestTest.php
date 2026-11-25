<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\FraudOutcomeTransactionsListRequest;

/** Fraud outcome endpoint vocabulary. */
class FraudOutcomeTransactionsListRequestTest extends \WC_Unit_Test_Case {

	/**
	 * @testdox Dropping review or reordering the fraud status vocabulary changes the endpoint contract.
	 */
	public function test_supported_status_paths(): void {
		$statuses = array( 'allow', 'block', 'review' );
		$this->assertSame( $statuses, FraudOutcomeTransactionsListRequest::STATUSES );
		foreach ( $statuses as $status ) {
			$request = FraudOutcomeTransactionsListRequest::from_params( array( 'status' => $status ) );
			$this->assertSame( 'fraud_outcomes/status/' . $status, $request->get_api() );
		}
	}

	/**
	 * @testdox Removing the request guard allows an unknown fraud status into the path.
	 */
	public function test_unknown_status(): void {
		$request = FraudOutcomeTransactionsListRequest::from_params( array( 'status' => 'unknown' ) );
		try {
			$request->get_api();
			$this->fail( 'Expected the unknown fraud outcome to be refused.' );
		} catch ( ApiException $error ) {
			$this->assertSame( 'invalid_fraud_outcome_status', $error->get_error_code() );
			$this->assertSame( 400, $error->get_http_code() );
		}
	}
}

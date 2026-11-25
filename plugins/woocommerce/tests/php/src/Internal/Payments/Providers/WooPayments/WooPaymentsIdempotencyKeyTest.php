<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIdempotencyKey;
use PHPUnit\Framework\TestCase;

/**
 * Tests for caller keys shared by the REST payment routes and the refund Ability.
 */
class WooPaymentsIdempotencyKeyTest extends TestCase {

	/**
	 * @testdox Each operation preserves the distinction between caller keys, including whitespace, binary and long keys.
	 */
	public function test_caller_key_platform_digests(): void {
		$examples = array(
			array( 'k', '8254c329a92850f6d539dd376f4816ee2764517da5e0235514af433164480d7a' ),
			array( '0', '5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9' ),
			array( '   ', '0aad7da77d2ed59c396c99a74e49f3a4524dcdbcb5163251b1433d640247aeb4' ),
			array( ' 0 ', 'f4ceb835f4e4d2e72886c987db84373b4dec63525e7e12507920d4d762371584' ),
			array( "a\n", '87428fc522803d31065e7bce3cf03fe475096631e5e07bbd7a0fde60c4cf25c7' ),
			array( "\r\nX: y", '681a096c64bd4b802c08596c617f77699176b677b99e87d6ebe911fbc06aa5c6' ),
			array( "\x00", '6e340b9cffb37a989ca544e6bb780a2c78901d3fb33738768511a30617afa01d' ),
			array( str_repeat( 'a', 10000 ), '27dd1f61b867b6a0f6e9d8a41c43231de52107e53ae424de8f847b821db4b711' ),
			array( 'é', '4a99557e4033c3539de2eb65472017cad5f9557f7a0625a09f1c3f6e2ba69c4c' ),
		);

		$operations = array(
			'intent' => WooPaymentsIdempotencyKey::OPERATION_INTENT,
			'refund' => WooPaymentsIdempotencyKey::OPERATION_REFUND,
		);
		foreach ( $operations as $prefix => $operation ) {
			$this->assertSame( '', WooPaymentsIdempotencyKey::for_platform( '', $operation ) );
			foreach ( $examples as list( $key, $digest ) ) {
				$this->assertSame( "wc-{$prefix}-{$digest}", WooPaymentsIdempotencyKey::for_platform( $key, $operation ) );
			}
		}
	}
}

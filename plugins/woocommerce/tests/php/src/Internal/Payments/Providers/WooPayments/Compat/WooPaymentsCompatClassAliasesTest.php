<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsCompatClassAliases;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments extension class names declared as aliases of the native classes.
 */
class WooPaymentsCompatClassAliasesTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The extension class name $alias answers for $original once $registered declares its names.
	 * @dataProvider alias_provider
	 *
	 * @param class-string $registered Class whose names are declared.
	 * @param string       $alias      Extension class name.
	 * @param class-string $original   Native class the name aliases.
	 */
	public function test_registers_every_extension_class_name( string $registered, string $alias, string $original ): void {
		// The retry email extends a WooCommerce email class, which loads with the mailer.
		WC()->mailer();

		WooPaymentsCompatClassAliases::register( $registered );

		$this->assertTrue( class_exists( $alias, false ), $alias . ' should be declared without autoloading the extension.' );
		$this->assertTrue( is_a( $original, $alias, true ), $original . ' should satisfy ' . $alias . '.' );
	}

	/**
	 * @testdox A payment type name an autoloader can load is left to it.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_payment_type_name_an_autoloader_provides_is_left_to_it(): void {
		$asked    = array();
		$provided = get_class( new class() {} );
		$loader   = static function ( string $class_name ) use ( &$asked, $provided ): void {
			$asked[] = $class_name;
			if ( 'WCPay\Constants\Payment_Type' === $class_name ) {
				class_alias( $provided, $class_name );
			}
		};
		spl_autoload_register( $loader );

		try {
			WooPaymentsCompatClassAliases::register( WooPaymentsPaymentType::class );
		} finally {
			spl_autoload_unregister( $loader );
		}

		$this->assertFalse( is_a( WooPaymentsPaymentType::class, 'WCPay\Constants\Payment_Type', true ), 'The autoloaded payment type is kept.' );
		$this->assertContains( 'WCPay\Constants\Payment_Type', $asked );
	}

	/**
	 * @testdox A retry email name an autoloader can load is left to it.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_retry_email_name_an_autoloader_provides_is_left_to_it(): void {
		// The retry email extends a WooCommerce email class, which loads with the mailer.
		WC()->mailer();
		$asked    = array();
		$provided = get_class( new class() {} );
		$loader   = static function ( string $class_name ) use ( &$asked, $provided ): void {
			$asked[] = $class_name;
			if ( 'WC_Payments_Email_Failed_Authentication_Retry' === $class_name ) {
				class_alias( $provided, $class_name );
			}
		};
		spl_autoload_register( $loader );

		try {
			WooPaymentsCompatClassAliases::register( WooPaymentsFailedAuthenticationRetryEmail::class );
		} finally {
			spl_autoload_unregister( $loader );
		}

		$this->assertContains( 'WC_Payments_Email_Failed_Authentication_Retry', $asked );
		$this->assertTrue( is_a( $provided, 'WC_Payments_Email_Failed_Authentication_Retry', true ), 'The class the autoloader supplied is kept.' );
		$this->assertFalse( is_a( WooPaymentsFailedAuthenticationRetryEmail::class, 'WC_Payments_Email_Failed_Authentication_Retry', true ), 'Native does not alias the name over it.' );
	}

	/**
	 * Provide every extension class name, the class whose registration declares it, and the class it aliases.
	 *
	 * @return array<string,array{class-string,string,class-string}>
	 */
	public function alias_provider(): array {
		return array(
			'payment type'                      => array( WooPaymentsPaymentType::class, 'WCPay\Constants\Payment_Type', WooPaymentsPaymentType::class ),
			'failed authentication retry email' => array( WooPaymentsFailedAuthenticationRetryEmail::class, 'WC_Payments_Email_Failed_Authentication_Retry', WooPaymentsFailedAuthenticationRetryEmail::class ),
		);
	}
}

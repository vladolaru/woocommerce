<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsActivatePmPromotionRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountCapitalLinkRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountLoginDataRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetPmPromotionsRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsCompatClassAliases;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDepositsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaginatedListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentType;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportingBalanceSummaryRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
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
	 * @testdox A request without its own filtered names is filtered under the generic request's names, and only those.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_without_its_own_filtered_names_gets_the_generic_request_names(): void {
		WooPaymentsCompatClassAliases::register_for_request( new WooPaymentsTransactionsListRequest() );

		$this->assertTrue( class_exists( 'WCPay\Core\Server\Request\Get_Request', false ) );
		$this->assertTrue( class_exists( 'WCPay\Core\Server\Request', false ) );
		$this->assertTrue( class_exists( 'WCPay\Core\Server\Response', false ) );
		$this->assertFalse( class_exists( 'WCPay\Core\Server\Request\List_Transactions', false ), 'The transactions name is declared by the REST route that builds the request.' );
	}

	/**
	 * @testdox A filtered request gets its own names and its parents', not the generic request's when it is not one.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_filtered_request_gets_its_own_names(): void {
		WooPaymentsCompatClassAliases::register_for_request( new WooPaymentsDocumentsListRequest() );

		$this->assertTrue( class_exists( 'WCPay\Core\Server\Request\List_Documents', false ) );
		$this->assertTrue( class_exists( 'WCPay\Core\Server\Request\Paginated', false ) );
		$this->assertFalse( class_exists( 'WCPay\Core\Server\Request\Get_Request', false ), 'A documents request is not a generic request.' );
	}

	/**
	 * @testdox A payment type name an autoloader can load is left to it; a request name is declared without asking autoloaders.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_payment_type_and_retry_email_names_consult_autoloaders(): void {
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
			WooPaymentsCompatClassAliases::register( WooPaymentsTransactionsListRequest::class );
		} finally {
			spl_autoload_unregister( $loader );
		}

		$this->assertFalse( is_a( WooPaymentsPaymentType::class, 'WCPay\Constants\Payment_Type', true ), 'The autoloaded payment type is kept.' );
		$this->assertContains( 'WCPay\Constants\Payment_Type', $asked );
		$this->assertNotContains( 'WCPay\Core\Server\Request\List_Transactions', $asked );
		$this->assertTrue( is_a( WooPaymentsTransactionsListRequest::class, 'WCPay\Core\Server\Request\List_Transactions', true ) );
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
			'response'                          => array( WooPaymentsResponse::class, 'WCPay\Core\Server\Response', WooPaymentsResponse::class ),
			'base request'                      => array( WooPaymentsPaginatedListRequest::class, 'WCPay\Core\Server\Request', WooPaymentsPaginatedListRequest::class ),
			'paginated request'                 => array( WooPaymentsPaginatedListRequest::class, 'WCPay\Core\Server\Request\Paginated', WooPaymentsPaginatedListRequest::class ),
			'response with a request'           => array( WooPaymentsPaginatedListRequest::class, 'WCPay\Core\Server\Response', WooPaymentsResponse::class ),
			'authorizations list'               => array( WooPaymentsAuthorizationsListRequest::class, 'WCPay\Core\Server\Request\List_Authorizations', WooPaymentsAuthorizationsListRequest::class ),
			'deposits list'                     => array( WooPaymentsDepositsListRequest::class, 'WCPay\Core\Server\Request\List_Deposits', WooPaymentsDepositsListRequest::class ),
			'disputes list'                     => array( WooPaymentsDisputesListRequest::class, 'WCPay\Core\Server\Request\List_Disputes', WooPaymentsDisputesListRequest::class ),
			'documents list'                    => array( WooPaymentsDocumentsListRequest::class, 'WCPay\Core\Server\Request\List_Documents', WooPaymentsDocumentsListRequest::class ),
			'fraud outcomes list'               => array( WooPaymentsFraudOutcomeTransactionsListRequest::class, 'WCPay\Core\Server\Request\List_Fraud_Outcome_Transactions', WooPaymentsFraudOutcomeTransactionsListRequest::class ),
			'transactions list'                 => array( WooPaymentsTransactionsListRequest::class, 'WCPay\Core\Server\Request\List_Transactions', WooPaymentsTransactionsListRequest::class ),
			'reporting balance summary'         => array( WooPaymentsReportingBalanceSummaryRequest::class, 'WCPay\Core\Server\Request\Get_Reporting_Balance_Summary', WooPaymentsReportingBalanceSummaryRequest::class ),
			'generic get request'               => array( WooPaymentsApiRequest::class, 'WCPay\Core\Server\Request\Get_Request', WooPaymentsApiRequest::class ),
			'get PM promotions'                 => array( WooPaymentsGetPmPromotionsRequest::class, 'WCPay\Core\Server\Request\Get_PM_Promotions', WooPaymentsGetPmPromotionsRequest::class ),
			'generic get with a child'          => array( WooPaymentsGetPmPromotionsRequest::class, 'WCPay\Core\Server\Request\Get_Request', WooPaymentsApiRequest::class ),
			'activate PM promotion'             => array( WooPaymentsActivatePmPromotionRequest::class, 'WCPay\Core\Server\Request\Activate_PM_Promotion', WooPaymentsActivatePmPromotionRequest::class ),
			'get account capital link'          => array( WooPaymentsGetAccountCapitalLinkRequest::class, 'WCPay\Core\Server\Request\Get_Account_Capital_Link', WooPaymentsGetAccountCapitalLinkRequest::class ),
			'get account login data'            => array( WooPaymentsGetAccountLoginDataRequest::class, 'WCPay\Core\Server\Request\Get_Account_Login_Data', WooPaymentsGetAccountLoginDataRequest::class ),
			'payment type'                      => array( WooPaymentsPaymentType::class, 'WCPay\Constants\Payment_Type', WooPaymentsPaymentType::class ),
			'failed authentication retry email' => array( WooPaymentsFailedAuthenticationRetryEmail::class, 'WC_Payments_Email_Failed_Authentication_Retry', WooPaymentsFailedAuthenticationRetryEmail::class ),
		);
	}
}

<?php
/**
 * WooPaymentsCompatClassAliases class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsActivatePmPromotionRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountCapitalLinkRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetAccountLoginDataRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsGetPmPromotionsRequest;
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

/**
 * Registers the WooPayments extension's class names as aliases of the classes that answer for them.
 *
 * Each alias is declared on first use, where the class is about to reach a filter, a hook or WooCommerce Subscriptions,
 * and never when the name is already declared. Removed in WooCommerce 12.0.0, together with its call sites (see README.md).
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsCompatClassAliases {

	/**
	 * Extension class names each class declares, with the class each name aliases, in declaration order.
	 *
	 * A class also declares the names of its listed parent classes, first. The base request class declares the response
	 * name too, because its requests are formatted into that response.
	 */
	private const ALIASES = array(
		WooPaymentsResponse::class                       => array(
			'WCPay\Core\Server\Response' => WooPaymentsResponse::class,
		),
		WooPaymentsPaginatedListRequest::class           => array(
			'WCPay\Core\Server\Response'          => WooPaymentsResponse::class,
			'WCPay\Core\Server\Request'           => WooPaymentsPaginatedListRequest::class,
			'WCPay\Core\Server\Request\Paginated' => WooPaymentsPaginatedListRequest::class,
		),
		WooPaymentsApiRequest::class                     => array(
			'WCPay\Core\Server\Request\Get_Request' => WooPaymentsApiRequest::class,
		),
		WooPaymentsGetPmPromotionsRequest::class         => array(
			'WCPay\Core\Server\Request\Get_PM_Promotions' => WooPaymentsGetPmPromotionsRequest::class,
		),
		WooPaymentsActivatePmPromotionRequest::class     => array(
			'WCPay\Core\Server\Request\Activate_PM_Promotion' => WooPaymentsActivatePmPromotionRequest::class,
		),
		WooPaymentsGetAccountCapitalLinkRequest::class   => array(
			'WCPay\Core\Server\Request\Get_Account_Capital_Link' => WooPaymentsGetAccountCapitalLinkRequest::class,
		),
		WooPaymentsGetAccountLoginDataRequest::class     => array(
			'WCPay\Core\Server\Request\Get_Account_Login_Data' => WooPaymentsGetAccountLoginDataRequest::class,
		),
		WooPaymentsAuthorizationsListRequest::class      => array(
			'WCPay\Core\Server\Request\List_Authorizations' => WooPaymentsAuthorizationsListRequest::class,
		),
		WooPaymentsDepositsListRequest::class            => array(
			'WCPay\Core\Server\Request\List_Deposits' => WooPaymentsDepositsListRequest::class,
		),
		WooPaymentsDisputesListRequest::class            => array(
			'WCPay\Core\Server\Request\List_Disputes' => WooPaymentsDisputesListRequest::class,
		),
		WooPaymentsDocumentsListRequest::class           => array(
			'WCPay\Core\Server\Request\List_Documents' => WooPaymentsDocumentsListRequest::class,
		),
		WooPaymentsFraudOutcomeTransactionsListRequest::class => array(
			'WCPay\Core\Server\Request\List_Fraud_Outcome_Transactions' => WooPaymentsFraudOutcomeTransactionsListRequest::class,
		),
		WooPaymentsReportingBalanceSummaryRequest::class => array(
			'WCPay\Core\Server\Request\Get_Reporting_Balance_Summary' => WooPaymentsReportingBalanceSummaryRequest::class,
		),
		WooPaymentsTransactionsListRequest::class        => array(
			'WCPay\Core\Server\Request\List_Transactions' => WooPaymentsTransactionsListRequest::class,
		),
		WooPaymentsPaymentType::class                    => array(
			'WCPay\Constants\Payment_Type' => WooPaymentsPaymentType::class,
		),
		WooPaymentsFailedAuthenticationRetryEmail::class => array(
			'WC_Payments_Email_Failed_Authentication_Retry' => WooPaymentsFailedAuthenticationRetryEmail::class,
		),
	);

	/**
	 * Extension class names checked with autoloading before they are declared, so a class an autoloader can load is
	 * left to it; every other name is checked without autoloading.
	 */
	private const AUTOLOADED_NAMES = array(
		'WCPay\Constants\Payment_Type',
		'WC_Payments_Email_Failed_Authentication_Retry',
	);

	/**
	 * Request classes the API client filters under their own extension names, in the order they are matched; any other
	 * request is filtered under the generic request's names.
	 */
	private const FILTERED_REQUEST_CLASSES = array(
		WooPaymentsGetPmPromotionsRequest::class,
		WooPaymentsActivatePmPromotionRequest::class,
		WooPaymentsGetAccountCapitalLinkRequest::class,
		WooPaymentsGetAccountLoginDataRequest::class,
		WooPaymentsAuthorizationsListRequest::class,
		WooPaymentsDocumentsListRequest::class,
		WooPaymentsReportingBalanceSummaryRequest::class,
	);

	/**
	 * Declare the extension class names of a class and of its listed parent classes, parents first.
	 *
	 * @param class-string $class_name Class whose extension names are declared.
	 */
	public static function register( string $class_name ): void {
		$parents = class_parents( $class_name );
		$classes = false === $parents ? array() : array_reverse( array_values( $parents ) );
		array_push( $classes, $class_name );

		foreach ( $classes as $class ) {
			foreach ( self::ALIASES[ $class ] ?? array() as $alias => $original ) {
				if ( ! class_exists( $alias, in_array( $alias, self::AUTOLOADED_NAMES, true ) ) ) {
					class_alias( $original, $alias );
				}
			}
		}
	}

	/**
	 * Declare the extension class names a request is filtered under before the API client applies its request filter.
	 *
	 * @param WooPaymentsPaginatedListRequest $request Request about to be filtered.
	 */
	public static function register_for_request( WooPaymentsPaginatedListRequest $request ): void {
		foreach ( self::FILTERED_REQUEST_CLASSES as $class ) {
			if ( $request instanceof $class ) {
				self::register( $class );
				return;
			}
		}

		self::register( WooPaymentsApiRequest::class );
	}
}

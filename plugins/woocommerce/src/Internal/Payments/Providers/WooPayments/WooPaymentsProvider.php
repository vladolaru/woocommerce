<?php
/**
 * WooPaymentsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\DataMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NavigationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NoticesPassthrough;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\SharedSettings;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\LinkController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\PaymentMethodsMap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\MultiCurrencyController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\ExtensionSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\OrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapperInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\PreflightGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NotesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\CurrencyComplianceNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\UserPreferenceFields;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\HomeTasks;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;

/**
 * Connects WooPayments to the payments runtime: its gateways, its charge, capture, cancel and refund operations with their
 * order effects, and the classes each setup tier loads on each request type.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsProvider implements ProviderInterface, ProviderOperationEffectApplierInterface, ProviderOutcomeMetadataMapperInterface, ProviderPostLifecycleEffectApplierInterface {

	/**
	 * WooPayments payment operation service.
	 *
	 * @var WooPaymentsPaymentOperationService
	 */
	private WooPaymentsPaymentOperationService $payment_operations;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments payment method definition registry.
	 *
	 * @var PaymentMethodRegistry
	 */
	private PaymentMethodRegistry $payment_method_registry;

	/**
	 * This request's payment gateways, keyed by payment method ID.
	 *
	 * @var array<string,WooPaymentsGateway>|null
	 */
	private ?array $payment_gateways = null;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier
	 */
	private WooPaymentsOrderEffectApplier $order_effect_applier;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsPaymentOperationService $payment_operations      WooPayments payment operation service.
	 * @param Client                             $api_client              WooPayments API client.
	 * @param WooPaymentsAccountService          $account_service         WooPayments account service.
	 * @param PaymentMethodRegistry              $payment_method_registry Payment method registry.
	 * @param WooPaymentsOrderEffectApplier      $order_effect_applier    Order effect applier.
	 */
	final public function init(
		WooPaymentsPaymentOperationService $payment_operations,
		Client $api_client,
		WooPaymentsAccountService $account_service,
		PaymentMethodRegistry $payment_method_registry,
		WooPaymentsOrderEffectApplier $order_effect_applier
	): void {
		$this->payment_operations      = $payment_operations;
		$this->api_client              = $api_client;
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry;
		$this->order_effect_applier    = $order_effect_applier;
		$this->payment_gateways        = null;
	}

	/**
	 * Get the provider's root classes for each setup tier and request type.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,array<string,array<int,class-string>>> Root classes in registration order.
	 */
	public static function get_classes_by_setup_tier(): array {
		// The cutover from the WooPayments extension adds these roots, and every row that needs one reads it from here.
		// Cutover/README.md lists everything to remove with the Cutover folder.
		$cutover_admin         = array( CutoverController::class, ReconciliationJob::class );
		$cutover_cron          = array( ReconciliationJob::class );
		$cutover_every_request = array( PluginLifecycleListener::class );

		$connected_rest_controllers = WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots();
		$connected_admin            = array(
			...$cutover_admin,
			NavigationController::class,
			SharedSettings::class,
			WooPaymentsAdminRestRouteRegistrar::class,
			WooPaymentsAccountService::class,
			WebhookReliabilityService::class,
			LinkController::class,
			WooPaymentsCustomerService::class,
			WooPaymentsOrderFraudMetaBox::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsOrderStatusChangeController::class,
			OrderStatusSync::class,
			ExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			CurrencyComplianceNotice::class,
			NoticesPassthrough::class,
			WooPaymentsFraudOrderTrackingService::class,
			WooPaymentsStoreSetupReportController::class,
			WooPaymentsAccountMilestonesController::class,
			WooPaymentsFeeDetailsNoteController::class,
			WooPaymentsTestModeOrderEmailService::class,
			UserPreferenceFields::class,
			HomeTasks::class,
			NotesController::class,
			WooPaymentsLoanApprovedNote::class,
		);
		$connected_ajax             = array(
			WooPaymentsAccountService::class,
			WebhookReliabilityService::class,
			WooPaymentsCustomerService::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsOrderStatusChangeController::class,
			OrderStatusSync::class,
			ExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsFraudOrderTrackingService::class,
			WooPaymentsStoreSetupReportController::class,
			WooPaymentsAccountMilestonesController::class,
			WooPaymentsFeeDetailsNoteController::class,
			WooPaymentsTestModeOrderEmailService::class,
			WooPaymentsLoanApprovedNote::class,
		);
		$connected_rest             = array_merge(
			array(
				WooPaymentsAccountService::class,
				WebhookReliabilityService::class,
				WooPaymentsCustomerService::class,
				WooPaymentsOrderAdminActionsController::class,
				OrderStatusSync::class,
				// Plugins are also (de)activated over REST (wp/v2/plugins); client 11.1.0 hooks the WooPay extension sync on every
				// request (`includes/class-wc-payments.php:597`, `includes/woopay/class-woopay-scheduler.php:45-52`).
				ExtensionSync::class,
				WooPaymentsApplePayDomainService::class,
			),
			$connected_rest_controllers,
			array(
				WooPaymentsFraudOrderTrackingService::class,
				WooPaymentsStoreSetupReportController::class,
				WooPaymentsAccountMilestonesController::class,
				WooPaymentsFeeDetailsNoteController::class,
				WooPaymentsTestModeOrderEmailService::class,
				UserPreferenceFields::class,
				HomeTasks::class,
				WooPaymentsLoanApprovedNote::class,
			)
		);
		$connected_cron             = array(
			...$cutover_cron,
			WooPaymentsAccountService::class,
			WebhookReliabilityService::class,
			WooPaymentsStoreSetupReportController::class,
			WooPaymentsAccountMilestonesController::class,
			WooPaymentsFeeDetailsNoteController::class,
			WooPaymentsFraudOrderTrackingService::class,
			OrderStatusSync::class,
			ExtensionSync::class,
			WooPaymentsApplePayDomainService::class,
			WooPaymentsCanceledAuthorizationFeeRemediationService::class,
			WooPaymentsOrderAdminActionsController::class,
			WooPaymentsTestModeOrderEmailService::class,
			WooPaymentsLoanApprovedNote::class,
		);
		$gateway_prefix             = array(
			self::class,
			WooPaymentsGatewayListController::class,
			// Card info for orders paid with WooPayments, wherever an order renders: client 11.1.0 registers it on every request
			// (`includes/class-wc-payments.php:674-675`).
			WooPaymentsOrderCardInfo::class,
		);
		$active_maintenance_prefix  = array_merge(
			array( DataMigration::class ),
			$gateway_prefix
		);

		$matrix = array(
			WooPaymentsSetupTier::AVAILABLE => array(
				// A store that can switch keeps the switch notice on the Payments pages, which strip other notices.
				RequestTypeUtil::ADMIN => array( ...$cutover_admin, NoticesPassthrough::class ),
				RequestTypeUtil::CRON  => $cutover_cron,
			),
			WooPaymentsSetupTier::CONNECTED => array(
				// The VAT details link lands on a front-end URL (client 11.1.0 `includes/class-wc-payments-vat-redirect-service.php:25`).
				RequestTypeUtil::FRONT => array_merge( $gateway_prefix, array( WooPaymentsVatDetailsRedirect::class ) ),
				RequestTypeUtil::ADMIN => array_merge( $gateway_prefix, $connected_admin ),
				RequestTypeUtil::AJAX  => array_merge( $gateway_prefix, $connected_ajax ),
				RequestTypeUtil::REST  => array_merge( $gateway_prefix, $connected_rest ),
				RequestTypeUtil::CRON  => array_merge( $gateway_prefix, $connected_cron ),
			),
			WooPaymentsSetupTier::ACTIVE    => array(
				RequestTypeUtil::FRONT => array_merge(
					$gateway_prefix,
					array(
						WooPaymentsAccountService::class,
						WebhookReliabilityService::class,
						WooPaymentsAddressProvider::class,
						WooPaymentsCustomerService::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsRedirectReturnController::class,
						WooPaymentsOrderAdminActionsController::class,
						WooPaymentsOrderStatusChangeController::class,
						WooPaymentsTokenizedCartSessionController::class,
						SessionController::class,
						OrderStatusSync::class,
						ExtensionSync::class,
						WooPaymentsExpressCheckoutController::class,
						// The Cart and Checkout blocks preload the Store API cart inside the page request (Blocks Cart.php:263),
						// so the express checkout data it adds to that cart must exist there too; client 11.1.0 registers it on every request
						// (class-wc-payments.php:697-709).
						WooPaymentsExpressCheckoutStoreApiExtension::class,
						WooPaymentsPaymentMethodMessaging::class,
						WooPaymentsApplePayDomainService::class,
						WooPaymentsFrontendTrackingController::class,
						WooPaymentsFraudOrderTrackingService::class,
						WooPaymentsStoreSetupReportController::class,
						WooPaymentsAccountMilestonesController::class,
						WooPaymentsFeeDetailsNoteController::class,
						WooPaymentsTestModeOrderEmailService::class,
						WooPaymentsVatDetailsRedirect::class,
					)
				),
				RequestTypeUtil::ADMIN => array_merge( $active_maintenance_prefix, $connected_admin ),
				RequestTypeUtil::AJAX  => array_merge(
					$gateway_prefix,
					$connected_ajax,
					array(
						WooPaymentsAddressProvider::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsCheckoutAjaxController::class,
						WooPaymentsTokenizedCartSessionController::class,
						SessionController::class,
						WooPaymentsExpressCheckoutController::class,
						WooPaymentsPaymentMethodMessaging::class,
						WooPaymentsFrontendTrackingController::class,
					)
				),
				RequestTypeUtil::REST  => array_merge(
					$gateway_prefix,
					$connected_rest,
					array(
						PreflightGuard::class,
						WooPaymentsAddressProvider::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
						WooPaymentsTokenizedCartSessionController::class,
						SessionController::class,
						WooPaymentsExpressCheckoutController::class,
						WooPaymentsExpressCheckoutStoreApiExtension::class,
						WooPaymentsExpressCheckoutCurrencyGuard::class,
						// The shopper scripts post Tracks events to its REST route.
						WooPaymentsFrontendTrackingController::class,
					)
				),
				RequestTypeUtil::CRON  => array_merge(
					$active_maintenance_prefix,
					$connected_cron,
					array(
						WooPaymentsOrderStatusChangeController::class,
						WooPaymentsDuplicatePaymentPreventionService::class,
					)
				),
			),
		);

		// WP-CLI runs Action Scheduler queues and cron on many hosts, so it gets the cron roots. Client 11.1.0 attaches its
		// scheduled-action handlers whenever it loads (`includes/class-wc-payments.php:603,657`).
		foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			$matrix[ $state ][ RequestTypeUtil::CLI ] = $matrix[ $state ][ RequestTypeUtil::CRON ];
		}

		// WordPress fires its plugin lifecycle hooks in whatever request activates or deactivates a plugin: wp-admin,
		// admin-ajax, the plugins REST routes (wp/v2/plugins), Action Scheduler, WP-CLI, and XML-RPC (a front request), where
		// Jetpack's remote plugin management runs. Client 11.1.0 registers its lifecycle callbacks on every request
		// (`woocommerce-payments.php:67-68`); the listener resolves the cutover controller only when a plugin changes.
		foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( RequestTypeUtil::FRONT, RequestTypeUtil::ADMIN, RequestTypeUtil::AJAX, RequestTypeUtil::REST, RequestTypeUtil::CRON, RequestTypeUtil::CLI ) as $request ) {
				$matrix[ $state ][ $request ] = array_merge( $matrix[ $state ][ $request ] ?? array(), $cutover_every_request );
			}
		}

		// Renewals, their emails and saved-card hooks run on any request of a connected or active store, with or
		// without the gateway enabled, as in the client (client 11.1.0 `includes/class-wc-payments.php:611,649`).
		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( RequestTypeUtil::FRONT, RequestTypeUtil::ADMIN, RequestTypeUtil::AJAX, RequestTypeUtil::REST, RequestTypeUtil::CRON, RequestTypeUtil::CLI ) as $request ) {
				$matrix[ $state ][ $request ][] = SubscriptionsController::class;
				// Every request that can write a WooPayments log line numbers it under one request id, as client 11.1.0's logger
				// context does on every request (`includes/class-wc-payments.php:552`, `src/Internal/LoggerContext.php:73-79`).
				$matrix[ $state ][ $request ][] = WooPaymentsLogEntryFormat::class;
				$matrix[ $state ][ $request ][] = StripeBillingModule::class;
				$matrix[ $state ][ $request ][] = WooPaymentsTokenService::class;
				// Saved SEPA, Link and Amazon Pay tokens load on any of these requests (renewals, admin subscription views);
				// the client loads their token classes on every request (client 11.1.0 `includes/class-wc-payments.php:468,540-541`).
				$matrix[ $state ][ $request ][] = WooPaymentsTokenClassMapController::class;
				// A Multibanco order keeps its voucher on revisits of its order pages and in its on-hold email, however that
				// email is sent (Store API checkout, deferred cron send, admin resend) and after the gateway is switched off;
				// the client hooks these on every request (client 11.1.0 `includes/class-wc-payments.php:588`,
				// `includes/class-wc-payments-order-success-page.php:40`).
				$matrix[ $state ][ $request ][] = WooPaymentsOrderSuccessPage::class;
				// Theme, template and style saves happen in admin, REST, AJAX and WP-CLI requests, and a WooCommerce update
				// finishes on the init of any request; each must drop the stored checkout appearance version, as client 11.1.0's
				// hooks on every request do (`includes/class-wc-payments.php:378-383`).
				$matrix[ $state ][ $request ][] = WooPaymentsFrontendStylesService::class;
				// The client refreshes the account and syncs the store setup on its own update (client 11.1.0
				// `includes/class-wc-payments-account.php:143-147`); here, a WooCommerce update is that event.
				$matrix[ $state ][ $request ][] = WooPaymentsWooCommerceUpdateListener::class;
				// Jetpack enables connection features on plugins_loaded, and every request can call WordPress.com; client 11.1.0
				// asks for them on every request (`woocommerce-payments.php:85-156`).
				$matrix[ $state ][ $request ][] = WooPaymentsConnectionFeatures::class;
				// The gateway settings can be written directly (classic toggle, REST, WP-CLI); keep the tier in step.
				if ( RequestTypeUtil::FRONT !== $request ) {
					$matrix[ $state ][ $request ][] = WooPaymentsGatewaySettingsSynchronizer::class;
				}
				// The first page after a login links the pre-login Sift session; client 11.1.0 hooks it on init of every request,
				// returns early without a connection, and skips AJAX, REST and WP-CLI (`includes/class-wc-payments.php:605`,
				// `includes/class-wc-payments-fraud-service.php:82,151-177`).
				if ( RequestTypeUtil::FRONT === $request || RequestTypeUtil::ADMIN === $request ) {
					$matrix[ $state ][ $request ][] = WooPaymentsFraudService::class;
				}
				// Selling locations are written by the general settings form, the REST settings API, WP-CLI and scheduled
				// imports; the client only watches the form save (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:582`).
				if ( RequestTypeUtil::FRONT !== $request && RequestTypeUtil::AJAX !== $request ) {
					$matrix[ $state ][ $request ][] = WooPaymentsSellingLocationsFraudSync::class;
				}
			}
		}

		return $matrix;
	}

	/**
	 * Get the classes WooPayments adds to Multi-Currency, which the Multi-Currency bootstrap registers ahead of its own.
	 *
	 * @since 11.2.0
	 *
	 * @return array<int,class-string> Class names in registration order.
	 */
	public static function get_multi_currency_classes(): array {
		return array( MultiCurrencyController::class, PaymentMethodsMap::class );
	}

	/**
	 * Get the provider/gateway ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return WooPaymentsPersistenceVocabulary::GATEWAY_ID;
	}

	/**
	 * Tell whether a zero-total checkout should reach charge().
	 *
	 * Always true: like client 11.1.0, charge() creates a setup intent only when the payment saves a new
	 * payment method and otherwise completes the order without an intent (class-wc-payment-gateway-wcpay.php:1688, 1983-2005).
	 *
	 * @param PaymentOperationContext $context Checkout payment context.
	 * @return bool
	 */
	public function supports_zero_amount_setup( PaymentOperationContext $context ): bool {
		unset( $context );

		return true;
	}

	/**
	 * Get the WooPayments persistence vocabulary.
	 *
	 * @return ProviderPersistenceVocabularyInterface
	 *
	 * @since 11.0.0
	 */
	public function get_persistence_vocabulary(): ProviderPersistenceVocabularyInterface {
		return new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * Map a payment outcome to WooPayments order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome );
	}

	/**
	 * Map a failed capture or cancel to WooPayments order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_failed_capture_or_cancel_outcome_meta( $outcome );
	}

	/**
	 * Get payment gateway instances registered by WooPayments.
	 *
	 * @return array<int,WooPaymentsGateway>
	 *
	 * @since 11.0.0
	 */
	public function get_payment_gateways(): array {
		return array_values(
			array_filter(
				$this->get_payment_gateway_map(),
				static fn( WooPaymentsGateway $gateway ): bool => $gateway->get_payment_method_definition()->should_publish_gateway()
			)
		);
	}

	/**
	 * Get the WooPayments gateway for a payment method or gateway ID.
	 *
	 * @param string $payment_method_or_gateway_id Payment method ID or gateway ID.
	 * @return WooPaymentsGateway|null
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_for_method( string $payment_method_or_gateway_id ): ?WooPaymentsGateway {
		$payment_method_id = $this->normalize_payment_method_id( $payment_method_or_gateway_id );
		$gateways          = $this->get_payment_gateway_map();

		return $gateways[ $payment_method_id ] ?? null;
	}

	/**
	 * Tell whether WooPayments can process payments: the platform can be reached and the account can take payments.
	 *
	 * @return bool
	 */
	public function can_process_payments(): bool {
		return $this->api_client->is_available() && $this->account_service->can_process_payments();
	}

	/**
	 * Tell whether WooPayments onboarding and account operations can run: always, since onboarding creates the
	 * WordPress.com connection that payments need.
	 *
	 * @return bool
	 */
	public function can_manage_onboarding(): bool {
		return true;
	}

	/**
	 * Charge an order through WooPayments.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->payment_operations->charge( $context, $idempotency_key );
	}

	/**
	 * Capture an authorized WooPayments charge.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted for this call; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		$outcome = $this->payment_operations->capture( $context, $idempotency_key );
		// Client 11.1.0 records this after every capture attempt (class-wc-payment-gateway-wcpay.php:4032).
		WooPaymentsTracks::record_wcadmin_event( 'wcpay_merchant_captured_auth' );

		return $outcome;
	}

	/**
	 * Cancel an authorized WooPayments charge.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted for this call; not sent to the provider.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->payment_operations->cancel( $context, $idempotency_key );
	}

	/**
	 * Refund a WooPayments charge.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this refund call.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		return $this->payment_operations->refund( $context, $idempotency_key );
	}

	/**
	 * Apply a request-scoped WooPayments effect plan.
	 *
	 * The plan also records whether the order already had the payment's success or capture note, which the
	 * post-lifecycle effects read. A failed charge on a subscription payment-method change first keeps the subscription's
	 * status, also when the charge threw, and a charge that threw gets the payment-failed note.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Provider outcome.
	 * @param string                  $operation Operation name.
	 * @return PaymentOutcome
	 */
	public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
		if ( PaymentProcessingService::OPERATION_CHARGE === $operation ) {
			$outcome = $this->payment_operations->keep_status_of_failed_subscription_change( $context, $this->with_thrown_charge_note( $context, $outcome ) );
		}

		$plan = $outcome->get_effect_plan();
		if ( ! $plan instanceof WooPaymentsOrderEffectPlan ) {
			return $outcome;
		}

		$outcome      = $this->order_effect_applier->apply( $context, $outcome, $plan );
		$note_existed = $this->get_fee_details_note_controller()->has_outcome_note( $context->get_order(), $outcome );
		$plan         = $outcome->get_effect_plan();
		if ( null === $note_existed || ! $plan instanceof WooPaymentsOrderEffectPlan ) {
			return $outcome;
		}

		// The runtime applies the payment lifecycle next; the post-lifecycle effects schedule the Fee details job when it added the note.
		return $outcome->with_effect_plan( $plan->with_success_or_capture_note_existed( $note_existed ) );
	}

	/**
	 * Add the payment-failed note to a charge that threw instead of returning an outcome, such as from an extension's callback.
	 *
	 * Client 11.1.0 notes any exception raised once its payment information exists, with the exception's message
	 * (class-wc-payment-gateway-wcpay.php:1354-1400), on a payment-method change too.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @param PaymentOutcome          $outcome Charge outcome.
	 * @return PaymentOutcome
	 */
	private function with_thrown_charge_note( PaymentOperationContext $context, PaymentOutcome $outcome ): PaymentOutcome {
		$data = $outcome->get_data();
		if ( true !== ( $data[ PaymentOutcome::DATA_PROVIDER_THREW ] ?? false ) ) {
			return $outcome;
		}

		$note_candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_checkout_payment_failed_note_candidates(
			$context->get_order(),
			(string) ( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? '' ),
			'',
			'',
			''
		);

		$data[ PaymentOutcome::DATA_NOTE ]             = $note_candidates[0];
		$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] = $note_candidates;

		return new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);
	}

	/**
	 * Apply the WooPayments effects that follow the generic payment lifecycle.
	 *
	 * It schedules the Fee details job when the lifecycle added the payment's success or capture note, and after a charge
	 * it also retires the order's charge idempotency key once the outcome is definitive, through
	 * WooPaymentsPaymentOperationService::finalize_charge_idempotency_key().
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Applied provider outcome.
	 * @param string                  $operation Operation name.
	 */
	public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
		$plan = $outcome->get_effect_plan();
		if ( $plan instanceof WooPaymentsOrderEffectPlan && false === $plan->success_or_capture_note_existed() ) {
			$this->get_fee_details_note_controller()->schedule_when_outcome_note_added( $context->get_order(), $outcome );
		}

		if ( PaymentProcessingService::OPERATION_CHARGE === $operation ) {
			$this->payment_operations->finalize_charge_idempotency_key( $context->get_order(), $outcome );
		}
	}

	/**
	 * Get the Fee details note controller.
	 *
	 * Read from the container when used, not taken in init(): the onboarding availability check resolves this provider,
	 * and the controller's collaborators are only needed once a payment outcome is applied.
	 *
	 * @return WooPaymentsFeeDetailsNoteController
	 */
	private function get_fee_details_note_controller(): WooPaymentsFeeDetailsNoteController {
		return wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class );
	}

	/**
	 * Get this request's gateway map.
	 *
	 * @return array<string,WooPaymentsGateway>
	 */
	private function get_payment_gateway_map(): array {
		if ( null === $this->payment_gateways ) {
			$this->payment_gateways = $this->build_payment_gateway_map();
		}

		return $this->payment_gateways;
	}

	/**
	 * Build the gateway instances for the payment methods WooPayments registers on this store.
	 *
	 * @return array<string,WooPaymentsGateway>
	 */
	private function build_payment_gateway_map(): array {
		$definitions = $this->payment_method_registry->get_registered( $this->account_service );
		if ( ! empty( $definitions ) ) {
			// Prime caches to reduce future queries.
			wp_prime_option_caches(
				array_map(
					static function ( PaymentMethodDefinition $definition ): string {
						$payment_method_id = $definition->get_id();

						return sprintf( 'woocommerce_%s_settings', 'card' === $payment_method_id ? WooPaymentsPersistenceVocabulary::GATEWAY_ID : WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . $payment_method_id );
					},
					$definitions
				)
			);
		}

		$gateways = array();

		// The card gateway is read from the container when needed: the container builds it with this provider, so the
		// provider cannot take it in init().
		foreach ( $definitions as $definition ) {
			$gateways[ $definition->get_id() ] = 'card' === $definition->get_id()
				? wc_get_container()->get( WooPaymentsGateway::class )
				: new WooPaymentsGateway( $definition );
		}

		return $gateways;
	}

	/**
	 * Normalize a payment method ID from a payment method or gateway ID.
	 *
	 * @param string $payment_method_or_gateway_id Payment method ID or gateway ID.
	 * @return string
	 */
	private function normalize_payment_method_id( string $payment_method_or_gateway_id ): string {
		$payment_method_or_gateway_id = strtolower( trim( $payment_method_or_gateway_id ) );

		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $payment_method_or_gateway_id || '' === $payment_method_or_gateway_id ) {
			return 'card';
		}

		$gateway_prefix = WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX;
		if ( str_starts_with( $payment_method_or_gateway_id, $gateway_prefix ) ) {
			return (string) substr( $payment_method_or_gateway_id, strlen( $gateway_prefix ) );
		}

		return $payment_method_or_gateway_id;
	}
}

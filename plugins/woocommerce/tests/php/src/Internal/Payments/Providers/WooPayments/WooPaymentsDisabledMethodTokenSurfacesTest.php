<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\StoreApi\Utilities\PaymentUtils;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;
use WC_Unit_Test_Case;

/**
 * Where a saved token of a disabled WooPayments method (SEPA, Amazon Pay) shows up.
 *
 * Native keeps such a token and hides it; client 11.1.0 deletes it on the unscoped read
 * (`class-wc-payments-token-service.php:199-250, 323-354`). Deleting the row would break the token reference a
 * subscription holds while the provider still has the payment method (decided divergence, monitor ruling 2026-10-04).
 * Every gateway here is registered and enabled, so the native token filter alone decides what an unscoped read shows.
 */
class WooPaymentsDisabledMethodTokenSurfacesTest extends WC_Unit_Test_Case {

	private const SEPA_GATEWAY_ID       = WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'sepa_debit';
	private const AMAZON_PAY_GATEWAY_ID = WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay';

	/**
	 * Token services created during a test.
	 *
	 * @var WooPaymentsTokenService[]
	 */
	private array $services = array();

	/**
	 * Gateway initializer registered for the test.
	 *
	 * @var callable|null
	 */
	private $gateway_initializer = null;

	/**
	 * Shopper user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Saved card token.
	 *
	 * @var WC_Payment_Token_CC
	 */
	private WC_Payment_Token_CC $card_token;

	/**
	 * Saved SEPA token.
	 *
	 * @var WooPaymentsSepaToken
	 */
	private WooPaymentsSepaToken $sepa_token;

	/**
	 * Saved Amazon Pay token.
	 *
	 * @var WooPaymentsAmazonPayToken
	 */
	private WooPaymentsAmazonPayToken $amazon_pay_token;

	/**
	 * Register the three WooPayments gateways and save one token of each type for a shopper.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->register_gateways();
		$controller = new WooPaymentsTokenClassMapController();
		$controller->init( new StaticWooPaymentsRuntimeArbiter( true ) );
		$controller->register();

		$this->user_id    = $this->factory()->user->create( array( 'role' => 'customer' ) );
		$this->card_token = new WC_Payment_Token_CC();
		$this->card_token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$this->card_token->set_user_id( $this->user_id );
		$this->card_token->set_token( 'pm_card' );
		$this->card_token->set_card_type( 'visa' );
		$this->card_token->set_last4( '4242' );
		$this->card_token->set_expiry_month( '12' );
		$this->card_token->set_expiry_year( '2030' );
		$this->card_token->save();

		$this->sepa_token = new WooPaymentsSepaToken();
		$this->sepa_token->set_gateway_id( self::SEPA_GATEWAY_ID );
		$this->sepa_token->set_user_id( $this->user_id );
		$this->sepa_token->set_token( 'pm_sepa' );
		$this->sepa_token->set_last4( '3000' );
		$this->sepa_token->save();

		$this->amazon_pay_token = new WooPaymentsAmazonPayToken();
		$this->amazon_pay_token->set_gateway_id( self::AMAZON_PAY_GATEWAY_ID );
		$this->amazon_pay_token->set_user_id( $this->user_id );
		$this->amazon_pay_token->set_token( 'pm_amazon_pay' );
		$this->amazon_pay_token->set_email( 'shopper@example.com' );
		$this->amazon_pay_token->save();

		wp_set_current_user( $this->user_id );
	}

	/**
	 * Remove the hooks and gateways the test added.
	 */
	public function tearDown(): void {
		foreach ( $this->services as $service ) {
			remove_action( 'woocommerce_payment_token_deleted', array( $service, 'handle_woocommerce_payment_token_deleted' ), 10 );
			remove_action( 'woocommerce_payment_token_set_default', array( $service, 'handle_woocommerce_payment_token_set_default' ), 10 );
			remove_filter( 'woocommerce_get_customer_payment_tokens', array( $service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
			remove_filter( 'woocommerce_payment_methods_list_item', array( $service, 'handle_woocommerce_payment_methods_list_item' ), 10 );
			remove_filter( 'woocommerce_get_credit_card_type_label', array( $service, 'normalize_saved_method_label' ), 10 );
		}
		$this->services = array();
		remove_action( 'wc_payment_gateways_initialized', $this->gateway_initializer, 100 );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();

		parent::tearDown();
	}

	/**
	 * @testdox My Account > Payment methods lists only the card while SEPA and Amazon Pay are disabled.
	 */
	public function test_my_account_payment_methods_list_leaves_out_disabled_method_tokens(): void {
		$this->create_service( array( 'card' ) );

		$this->assertSame( array( $this->card_token->get_id() ), $this->get_listed_token_ids( wc_get_customer_saved_methods_list( $this->user_id ) ) );
	}

	/**
	 * @testdox Classic checkout lists only the card under the card gateway; the disabled gateways keep their own tokens.
	 *
	 * A disabled split gateway is not offered at classic checkout (its `enabled` setting follows the enabled-method
	 * list), so its own list never renders there; it is rendered here only to show the gateway-scoped read at parity.
	 */
	public function test_classic_checkout_saved_methods_show_disabled_tokens_only_under_their_own_gateway(): void {
		$this->create_service( array( 'card' ) );
		$gateways = WC()->payment_gateways()->payment_gateways();

		$this->assertSame( array( $this->card_token->get_id() ), $this->get_rendered_token_ids( $gateways[ WooPaymentsPersistenceVocabulary::GATEWAY_ID ] ) );
		$this->assertSame( array( $this->sepa_token->get_id() ), $this->get_rendered_token_ids( $gateways[ self::SEPA_GATEWAY_ID ] ) );
		$this->assertSame( array( $this->amazon_pay_token->get_id() ), $this->get_rendered_token_ids( $gateways[ self::AMAZON_PAY_GATEWAY_ID ] ) );
	}

	/**
	 * @testdox The Blocks checkout saved methods hold only the card while SEPA and Amazon Pay are disabled.
	 */
	public function test_blocks_checkout_saved_methods_leave_out_disabled_method_tokens(): void {
		$this->create_service( array( 'card' ) );

		$saved = PaymentUtils::get_saved_payment_methods();

		$this->assertSame( array( $this->card_token->get_id() ), $this->get_listed_token_ids( $saved['enabled'] ) );
	}

	/**
	 * @testdox The admin subscription select lists a disabled method's tokens only under that method's own gateway.
	 *
	 * Native and client 11.1.0 add the payment-meta field for the reusable card and Amazon Pay gateways only (client
	 * `trait-wc-payment-gateway-wcpay-subscriptions.php:646-666`), so a SEPA subscription has no token field in the admin.
	 */
	public function test_admin_subscription_select_lists_disabled_tokens_only_under_their_own_gateway(): void {
		$handler      = new WooPaymentsSubscriptionAdminPaymentMethodHandler( $this->create_service( array( 'card' ) ) );
		$subscription = wc_create_order( array( 'customer_id' => $this->user_id ) );

		$fields = $this->get_admin_payment_meta_fields( $handler, $subscription );

		$this->assertSame( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, self::AMAZON_PAY_GATEWAY_ID ), array_keys( $fields ), 'Only the card and Amazon Pay gateways get a token field.' );
		$this->assertSame( array( $this->card_token->get_id() ), $this->get_selectable_token_ids( $subscription, WooPaymentsPersistenceVocabulary::GATEWAY_ID ) );
		$this->assertSame( array( $this->amazon_pay_token->get_id() ), $this->get_selectable_token_ids( $subscription, self::AMAZON_PAY_GATEWAY_ID ) );
	}

	/**
	 * @testdox A SEPA subscription keeps its saved token across disabling and re-enabling SEPA.
	 */
	public function test_subscription_keeps_its_sepa_token_across_disable_and_reenable(): void {
		$subscription = wc_create_order( array( 'customer_id' => $this->user_id ) );
		$subscription->set_payment_method( self::SEPA_GATEWAY_ID );
		$subscription->add_payment_token( $this->sepa_token );
		$subscription->save();
		$payment_method_ids = array(
			'card'       => 'pm_card',
			'sepa_debit' => 'pm_sepa',
			'amazon_pay' => 'pm_amazon_pay',
		);
		$provider_methods   = array();
		foreach ( $payment_method_ids as $type => $payment_method_id ) {
			$provider_methods[ $type ] = array(
				array(
					'id'   => $payment_method_id,
					'type' => $type,
				),
			);
		}

		$disabled = $this->create_service( array( 'card' ), $provider_methods );
		$hidden   = $this->get_listed_token_ids( wc_get_customer_saved_methods_list( $this->user_id ) );
		$this->remove_service_filters( $disabled );
		$enabled = $this->create_service( array( 'card', 'sepa_debit' ), $provider_methods );
		$shown   = $this->get_listed_token_ids( wc_get_customer_saved_methods_list( $this->user_id ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertNotContains( $this->sepa_token->get_id(), $hidden, 'The SEPA token is hidden while SEPA is disabled.' );
		$this->assertContains( $this->sepa_token->get_id(), $shown, 'The same SEPA token is listed again after SEPA is re-enabled.' );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, WC_Payment_Tokens::get( $this->sepa_token->get_id() ), 'The SEPA token row survives.' );
		$this->assertSame( array( $this->sepa_token->get_id() ), array_map( 'absint', $subscription->get_payment_tokens() ) );
		$this->assertSame( 'pm_sepa', $enabled->resolve_payment_method_id_from_order_token_id( (string) $this->sepa_token->get_id(), $subscription ), 'The subscription token still resolves to the provider payment method.' );
	}

	/**
	 * Create a token service with the given enabled methods, attached to the token filters.
	 *
	 * @param string[]                                     $enabled_method_ids Enabled WooPayments payment method IDs.
	 * @param array<string,array<int,array<string,mixed>>> $provider_methods   Provider payment methods by type; reconciliation is skipped when empty.
	 * @return WooPaymentsTokenService
	 */
	private function create_service( array $enabled_method_ids, array $provider_methods = array() ): WooPaymentsTokenService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturn( $enabled_method_ids );

		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_customer_id_by_user_id', 'get_payment_methods_for_customer' ) )
			->getMock();
		$customer_service->method( 'get_customer_id_by_user_id' )->willReturn( empty( $provider_methods ) ? null : 'cus_surfaces' );
		$customer_service->method( 'get_payment_methods_for_customer' )->willReturnCallback(
			static function ( string $customer_id, string $type = 'card' ) use ( $provider_methods ): array {
				unset( $customer_id );

				return $provider_methods[ $type ] ?? array();
			}
		);

		$service = new WooPaymentsTokenService();
		$service->init( $this->createMock( WooPaymentsPaymentMethodDetailsService::class ), new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), $customer_service, $account_service );
		$this->services[] = $service;

		return $service;
	}

	/**
	 * Detach a token service from the token filters.
	 *
	 * @param WooPaymentsTokenService $service Token service.
	 */
	private function remove_service_filters( WooPaymentsTokenService $service ): void {
		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
		remove_filter( 'woocommerce_payment_methods_list_item', array( $service, 'handle_woocommerce_payment_methods_list_item' ), 10 );
	}

	/**
	 * Register enabled, tokenizing gateways for the card, SEPA and Amazon Pay gateway IDs.
	 */
	private function register_gateways(): void {
		$this->gateway_initializer = static function ( \WC_Payment_Gateways $wc_payment_gateways ): void {
			$wc_payment_gateways->payment_gateways = array();
			foreach ( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, self::SEPA_GATEWAY_ID, self::AMAZON_PAY_GATEWAY_ID ) as $gateway_id ) {
				$gateway = new class( $gateway_id ) extends \WC_Payment_Gateway {
					/**
					 * Constructor.
					 *
					 * @param string $gateway_id Gateway ID.
					 */
					public function __construct( string $gateway_id ) {
						$this->id       = $gateway_id;
						$this->enabled  = 'yes';
						$this->supports = array( 'products', 'tokenization' );
					}
				};

				$wc_payment_gateways->payment_gateways[ $gateway_id ] = $gateway;
			}
		};
		add_action( 'wc_payment_gateways_initialized', $this->gateway_initializer, 100 );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Get the token IDs of a saved payment methods list, from each item's delete action URL.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $saved_methods Saved payment methods grouped by token type.
	 * @return int[]
	 */
	private function get_listed_token_ids( array $saved_methods ): array {
		$ids = array();
		foreach ( $saved_methods as $items ) {
			foreach ( $items as $item ) {
				$this->assertSame( 1, preg_match( '/delete-payment-method[\/=](\d+)/', (string) $item['actions']['delete']['url'], $matches ) );
				$ids[] = (int) $matches[1];
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * Get the token IDs a gateway renders in its classic checkout saved methods list.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway.
	 * @return int[]
	 */
	private function get_rendered_token_ids( \WC_Payment_Gateway $gateway ): array {
		ob_start();
		$gateway->saved_payment_methods();
		$html = (string) ob_get_clean();
		preg_match_all( '/name="wc-[a-z_]+-payment-token" value="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Get the payment-meta fields WooCommerce Subscriptions shows in the subscription admin, keyed by gateway ID.
	 *
	 * @param WooPaymentsSubscriptionAdminPaymentMethodHandler $handler      Admin handler.
	 * @param WC_Order                                         $subscription Subscription.
	 * @return array<string,mixed>
	 */
	private function get_admin_payment_meta_fields( WooPaymentsSubscriptionAdminPaymentMethodHandler $handler, WC_Order $subscription ): array {
		remove_all_filters( 'woocommerce_subscription_payment_meta' );
		add_filter( 'woocommerce_subscription_payment_meta', array( $handler, 'add_subscription_payment_meta' ), 10, 2 );
		try {
			$fields = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
		} finally {
			remove_filter( 'woocommerce_subscription_payment_meta', array( $handler, 'add_subscription_payment_meta' ), 10 );
		}

		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Get the token IDs a gateway's payment-meta field offers, rendered through the action WooCommerce Subscriptions fires
	 * for the field (`woocommerce_subscription_payment_meta_input_{gateway}_{table}_{key}`).
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $gateway_id   Gateway ID.
	 * @return int[]
	 */
	private function get_selectable_token_ids( WC_Order $subscription, string $gateway_id ): array {
		ob_start();
		do_action( 'woocommerce_subscription_payment_meta_input_' . $gateway_id . '_wc_order_tokens_token', $subscription, '_payment_method_meta[' . $gateway_id . '][wc_order_tokens][token]', null );
		$html = (string) ob_get_clean();
		preg_match_all( '/<option value="(\d+)"/', $html, $matches );

		return array_map( 'intval', $matches[1] );
	}
}

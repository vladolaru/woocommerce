<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedRenewalAuthenticationEmail;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFailedRenewalAuthenticationEmail class.
 */
class WooPaymentsFailedRenewalAuthenticationEmailTest extends WC_Unit_Test_Case {

	/**
	 * Mail the emails sent, as wp_mail() received it.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = array();

	/**
	 * Capture outgoing mail instead of sending it.
	 */
	public function setUp(): void {
		parent::setUp();
		// The emails run with the mailer initialized; the email parent classes load with it.
		WC()->mailer();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, array $atts ) {
				unset( $short_circuit );
				$this->sent[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * Clear the subscription registry and the retry store the doubles read.
	 */
	public function tearDown(): void {
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS['wcpay_test_retry_store'] );
		parent::tearDown();
	}

	/**
	 * @testdox A renewal that needs authentication emails the shopper once, with a link to authorize the payment, and turns off the retry system's own customer email for that order.
	 *
	 * Client 11.1.0 `WC_Payments_Email_Failed_Renewal_Authentication::trigger()` and its template (link to the order's pay page).
	 */
	public function test_renewal_needing_authentication_emails_the_shopper_with_the_authorization_link(): void {
		$order = $this->create_subscription_order();
		$email = new WooPaymentsFailedRenewalAuthenticationEmail();

		$email->trigger( $order );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'shopper@example.com', $this->sent[0]['to'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), (string) $this->sent[0]['subject'] );
		// The email styler writes the link's ampersands as &amp;.
		$this->assertStringContainsString( 'href="' . str_replace( '&', '&amp;', $order->get_checkout_payment_url( false ) ) . '"', (string) $this->sent[0]['message'] );
		$rule = apply_filters( 'wcs_get_retry_rule_raw', array( 'email_template_customer' => 'WCS_Email_Customer_Payment_Retry' ), 1, $order->get_id() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertSame( '', $rule['email_template_customer'], 'The retry system does not send its own customer email too.' );
	}

	/**
	 * @testdox No authentication email goes out for $_dataName.
	 * @testWith ["an order unrelated to a subscription", false, "yes"]
	 *           ["a disabled email", true, "no"]
	 *
	 * @param string $label           What makes no email go out.
	 * @param bool   $is_subscription Whether the order is a subscription.
	 * @param string $enabled         The email's enabled setting.
	 */
	public function test_no_authentication_email_for_plain_orders_or_when_disabled( string $label, bool $is_subscription, string $enabled ): void {
		unset( $label );
		update_option( 'woocommerce_failed_renewal_authentication_settings', array( 'enabled' => $enabled ) );
		$order = $is_subscription ? $this->create_subscription_order() : $this->create_order();
		$email = new WooPaymentsFailedRenewalAuthenticationEmail();

		$email->trigger( $order );

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * @testdox The store owner's retry email goes to the admin address with the next retry time, and is skipped when no retry is recorded.
	 *
	 * Client 11.1.0 `WC_Payments_Email_Failed_Authentication_Retry::trigger()`: it reads the last retry from WooCommerce
	 * Subscriptions' retry store (`WCS_Retry_Manager::store()->get_last_retry_for_order()`) and its time label.
	 */
	public function test_retry_email_goes_to_the_admin_with_the_retry_time(): void {
		$this->load_retry_doubles();
		$order = $this->create_order();
		$email = new WooPaymentsFailedAuthenticationRetryEmail();

		$email->trigger( $order->get_id(), $order );
		$this->assertSame( array(), $this->sent, 'Nothing is sent without a recorded retry.' );

		$GLOBALS['wcpay_test_retry_store'] = new class() {
			/**
			 * Get the last retry of an order: one due in two days.
			 *
			 * @param int $order_id Order ID.
			 * @return object
			 */
			public function get_last_retry_for_order( $order_id ) {
				unset( $order_id );
				return new class() {
					/**
					 * Get the retry time.
					 *
					 * @return int
					 */
					public function get_time() {
						return time() + 2 * DAY_IN_SECONDS;
					}
				};
			}
		};
		$email->trigger( $order->get_id(), $order );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( get_option( 'admin_email' ), $this->sent[0]['to'] );
		$this->assertStringContainsString( 'in 2 days', (string) $this->sent[0]['subject'] );
		$this->assertStringContainsString( 'in 2 days', (string) $this->sent[0]['message'] );
	}

	/**
	 * Create an order with a billing email.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( 'shopper@example.com' );
		$order->set_total( '24.90' );
		$order->save();

		return $order;
	}

	/**
	 * Create an order WooCommerce Subscriptions knows as a subscription.
	 *
	 * @return WC_Order
	 */
	private function create_subscription_order(): WC_Order {
		WooCommerceSubscriptionsDoubles::load();
		$order = $this->create_order();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $order->get_id();

		return $order;
	}

	/**
	 * Define WooCommerce Subscriptions' retry store and time label, which only this email reads.
	 *
	 * WooCommerce Subscriptions `WCS_Retry_Manager::store()` and `wcs_get_human_time_diff()`; the store is the test's
	 * `wcpay_test_retry_store` global, none by default.
	 */
	private function load_retry_doubles(): void {
		if ( ! class_exists( 'WCS_Retry_Manager' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; the email reads its retry store.
			eval( 'class WCS_Retry_Manager { public static function store() { return $GLOBALS["wcpay_test_retry_store"] ?? null; } }' );
		}
		if ( ! function_exists( 'wcs_get_human_time_diff' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; the email formats the retry time with it.
			eval( 'function wcs_get_human_time_diff( $timestamp ) { return "in " . human_time_diff( time(), $timestamp ); }' );
		}
	}

	/**
	 * @testdox Should make the retry rule's admin email class resolvable by its extension-era global name.
	 */
	public function test_set_store_owner_custom_email_makes_named_class_resolvable(): void {
		// The retry flow runs with the mailer initialized; the email parent classes load with it.
		WC()->mailer();
		$order = wc_create_order();
		$email = new WooPaymentsFailedRenewalAuthenticationEmail();
		// The filter only applies to the email's current order.
		$email->object = $order;

		$rule = $email->set_store_owner_custom_email(
			array( 'email_template_admin' => 'WCS_Email_Payment_Retry' ),
			1,
			$order->get_id()
		);

		$this->assertSame( 'WC_Payments_Email_Failed_Authentication_Retry', $rule['email_template_admin'] );
		$this->assertTrue(
			class_exists( $rule['email_template_admin'] ),
			'Subscriptions instantiates the retry email by this global name; an unresolvable class silently skips the email.'
		);
		$this->assertInstanceOf( WooPaymentsFailedAuthenticationRetryEmail::class, new \WC_Payments_Email_Failed_Authentication_Retry() );
	}

	/**
	 * @testdox Should leave the retry rule alone for an unrelated order.
	 */
	public function test_set_store_owner_custom_email_ignores_unrelated_orders(): void {
		$order         = wc_create_order();
		$email         = new WooPaymentsFailedRenewalAuthenticationEmail();
		$email->object = $order;

		$rule = $email->set_store_owner_custom_email(
			array( 'email_template_admin' => 'WCS_Email_Payment_Retry' ),
			1,
			$order->get_id() + 1
		);

		$this->assertSame( 'WCS_Email_Payment_Retry', $rule['email_template_admin'] );
	}
}

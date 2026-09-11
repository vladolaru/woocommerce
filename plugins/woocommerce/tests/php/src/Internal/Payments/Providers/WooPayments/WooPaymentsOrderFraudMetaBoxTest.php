<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderFraudMetaBox;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderFraudMetaBox class.
 */
class WooPaymentsOrderFraudMetaBoxTest extends WC_Unit_Test_Case {

	private const META_BOX_ID = 'wcpay-order-fraud-and-risk-meta-box';

	/**
	 * Created meta box instances.
	 *
	 * @var array<int,object>
	 */
	private array $meta_boxes = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->meta_boxes as $meta_box ) {
			remove_action( 'add_meta_boxes', array( $meta_box, 'handle_add_meta_boxes' ) );
			remove_action( 'admin_enqueue_scripts', array( $meta_box, 'handle_admin_enqueue_scripts' ) );
		}

		$this->clear_registered_meta_boxes();

		parent::tearDown();
	}

	/**
	 * @testdox Should register the meta box hook only when native owns the runtime.
	 */
	public function test_registers_meta_box_hook_only_when_native_owns_runtime(): void {
		$plugin_owned = $this->create_meta_box( false );
		$plugin_owned->register();

		$this->assertFalse(
			has_action( 'add_meta_boxes', array( $plugin_owned, 'handle_add_meta_boxes' ) ),
			'Plugin-owned runtime should not register native order meta boxes.'
		);

		$native_owned = $this->create_meta_box( true );
		$native_owned->register();

		$this->assertSame(
			10,
			has_action( 'add_meta_boxes', array( $native_owned, 'handle_add_meta_boxes' ) ),
			'Native-owned runtime should register the Fraud & Risk meta box callback.'
		);
	}

	/**
	 * @testdox Should add the Fraud and Risk meta box to HPOS and legacy order screens.
	 */
	public function test_adds_meta_box_to_hpos_and_legacy_order_screens(): void {
		$meta_box = $this->create_meta_box( true );

		$meta_box->handle_add_meta_boxes();

		foreach ( $this->get_order_edit_screen_ids() as $screen_id ) {
			$registered_meta_box = $this->get_registered_meta_box( $screen_id );

			$this->assertSame( 'Fraud &amp; Risk', $registered_meta_box['title'] );
			$this->assertSame( array( $meta_box, 'render_meta_box' ), $registered_meta_box['callback'] );
		}
	}

	/**
	 * @testdox Should render extension-compatible copy for each fraud meta box type.
	 *
	 * @dataProvider fraud_meta_box_type_provider
	 *
	 * @param string      $meta_box_type        Fraud meta box type.
	 * @param string      $expected_status      Expected status copy.
	 * @param string      $expected_description Expected description copy.
	 * @param string|null $expected_link_label  Optional expected action link label.
	 * @param string|null $expected_status_arg  Optional status tracking query value.
	 */
	public function test_renders_extension_copy_for_each_fraud_meta_box_type( string $meta_box_type, string $expected_status, string $expected_description, ?string $expected_link_label, ?string $expected_status_arg ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => $meta_box_type,
				'_intent_id'                 => 'pi_mock',
				'_charge_id'                 => 'ch_mock',
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'wcpay-fraud-risk-action', $html );
		$this->assertStringContainsString( $expected_status, $html );
		$this->assertStringContainsString( $expected_description, $html );
		$this->assertStringContainsString( 'Adjust risk filters', $html );
		$this->assertStringContainsString( 'path=/woopayments/settings/fraud-protection', $html );

		if ( null === $expected_link_label ) {
			$this->assertStringNotContainsString( '/woopayments/transactions/details', $html );
			return;
		}

		$this->assertStringContainsString( $expected_link_label, $html );
		$this->assertStringContainsString( 'path=/woopayments/transactions/details', $html );

		if ( 'block' === $meta_box_type ) {
			$this->assertStringContainsString( 'id=' . $order->get_id(), $html );
		} else {
			$this->assertStringContainsString( 'id=pi_mock', $html );
		}

		if ( null !== $expected_status_arg ) {
			$this->assertStringContainsString( 'status_is=' . $expected_status_arg, $html );
			$this->assertStringContainsString( 'type_is=meta_box', $html );
		}
	}

	/**
	 * @testdox Should honor native fraud outcome status when the preserved meta box type is allow.
	 *
	 * @dataProvider fraud_outcome_status_provider
	 *
	 * @param string $fraud_outcome_status Expected fraud outcome status.
	 * @param string $expected_status      Expected status copy.
	 * @param string $expected_description Expected description copy.
	 */
	public function test_honors_native_fraud_outcome_status_when_meta_box_type_is_allow( string $fraud_outcome_status, string $expected_status, string $expected_description ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type'  => 'allow',
				'_wcpay_fraud_outcome_status' => $fraud_outcome_status,
				'_intent_id'                  => 'pi_mock',
				'_charge_id'                  => 'ch_mock',
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( $expected_status, $html );
		$this->assertStringContainsString( $expected_description, $html );
	}

	/**
	 * @testdox Should render the risk level block for supported risk levels.
	 *
	 * @dataProvider risk_level_provider
	 *
	 * @param string $risk_level           Risk level meta value.
	 * @param string $expected_title       Expected title copy.
	 * @param string $expected_description Expected description copy.
	 */
	public function test_renders_supported_risk_level_blocks( string $risk_level, string $expected_title, string $expected_description ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_charge_risk_level'         => $risk_level,
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'wcpay-fraud-risk-level--' . $risk_level, $html );
		$this->assertStringContainsString( $expected_title, $html );
		$this->assertStringContainsString( $expected_description, $html );
	}

	/**
	 * @testdox Should omit the risk level block for unknown risk levels.
	 */
	public function test_omits_unknown_risk_level_block(): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_charge_risk_level'         => 'unknown',
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringNotContainsString( 'wcpay-fraud-risk-level', $html );
		$this->assertStringContainsString( 'The payment for this order passed your risk filtering.', $html );
	}

	/**
	 * @testdox Should render Learn more copy without risk-filter settings for non-card WooPayments orders.
	 */
	public function test_renders_non_card_woopayments_copy_without_adjust_filters_link(): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
			),
			'woocommerce_payments_bancontact',
			'Bancontact'
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'Risk filtering is only available for orders processed using credit cards with WooPayments. This order was processed with Bancontact.', $html );
		$this->assertStringContainsString( 'Learn more', $html );
		$this->assertStringContainsString( 'fraud-meta-box-not-wcpay-learn-more', $html );
		$this->assertStringNotContainsString( 'Adjust risk filters', $html );
	}

	/**
	 * @testdox Should render Learn more copy without risk-filter settings for non-WooPayments orders.
	 */
	public function test_renders_non_woopayments_copy_without_adjust_filters_link(): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
			),
			'bacs',
			'Direct bank transfer'
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'Risk filtering is only available for orders processed using credit cards with WooPayments. This order was processed with Direct bank transfer.', $html );
		$this->assertStringContainsString( 'Learn more', $html );
		$this->assertStringNotContainsString( 'Adjust risk filters', $html );
	}

	/**
	 * @testdox Should render no output when the provided order cannot be resolved.
	 */
	public function test_renders_no_output_when_order_cannot_be_resolved(): void {
		$meta_box = $this->create_meta_box( true );

		ob_start();
		$meta_box->render_meta_box( 'not-an-order' );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Fraud meta box type render cases.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string|null,4:string|null}>
	 */
	public function fraud_meta_box_type_provider(): array {
		return array(
			'allow'            => array( 'allow', 'No action taken', 'The payment for this order passed your risk filtering.', null, null ),
			'block'            => array( 'block', 'Blocked', 'The payment for this order was blocked by your risk filtering. There is no pending authorization, and the order can be cancelled to reduce any held stock.', 'View more details', 'block' ),
			'payment started'  => array( 'payment_started', 'No action taken', 'The payment for this order has not yet been passed to the fraud and risk filters to determine its outcome status.', null, null ),
			'review'           => array( 'review', 'Held for review', 'The payment for this order was held for review by your risk filtering. You can review the details and determine whether to approve or block the payment.', 'Review payment', 'review' ),
			'review allowed'   => array( 'review_allowed', 'Approved', 'The payment for this order was held for review by your risk filtering and manually approved.', null, null ),
			'review blocked'   => array( 'review_blocked', 'Held for review', 'This transaction was held for review by your risk filters, and the charge was manually blocked after review.', 'Review payment', null ),
			'review expired'   => array( 'review_expired', 'Held for review', 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have expired.', 'Review payment', null ),
			'review failed'    => array( 'review_failed', 'Held for review', 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have failed.', 'Review payment', null ),
			'terminal payment' => array( 'terminal_payment', 'No action taken', 'The payment for this order was done in person and has bypassed your risk filtering.', null, null ),
		);
	}

	/**
	 * Native fraud outcome render cases.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function fraud_outcome_status_provider(): array {
		return array(
			'block outcome'  => array( 'block', 'Blocked', 'The payment for this order was blocked by your risk filtering. There is no pending authorization, and the order can be cancelled to reduce any held stock.' ),
			'review outcome' => array( 'review', 'Held for review', 'The payment for this order was held for review by your risk filtering. You can review the details and determine whether to approve or block the payment.' ),
		);
	}

	/**
	 * Risk level render cases.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function risk_level_provider(): array {
		return array(
			'normal'   => array( 'normal', 'Normal', 'This payment shows a lower than normal risk of fraudulent activity.' ),
			'elevated' => array( 'elevated', 'Elevated', 'This order has a moderate risk of being fraudulent. We suggest contacting the customer to confirm their details before fulfilling it.' ),
			'highest'  => array( 'highest', 'High', 'This order has a high risk of being fraudulent. We suggest contacting the customer to confirm their details before fulfilling it.' ),
		);
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool $native_register Whether native should register.
	 * @return object
	 */
	private function create_meta_box( bool $native_register ) {
		$this->assertTrue( class_exists( WooPaymentsOrderFraudMetaBox::class ), 'WooPaymentsOrderFraudMetaBox should exist.' );

		$meta_box = new WooPaymentsOrderFraudMetaBox();
		$meta_box->init( new StaticNativeRuntimeArbiter( $native_register ) );

		$this->meta_boxes[] = $meta_box;

		return $meta_box;
	}

	/**
	 * Create a WooPayments order with fraud meta.
	 *
	 * @param array<string,string> $meta                 Order meta.
	 * @param string               $payment_method       Payment method ID.
	 * @param string               $payment_method_title Payment method title.
	 * @return WC_Order
	 */
	private function create_woopayments_order( array $meta, string $payment_method = 'woocommerce_payments', string $payment_method_title = 'WooPayments' ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_payment_method( $payment_method );
		$order->set_payment_method_title( $payment_method_title );

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();

		return $order;
	}

	/**
	 * Render the meta box for an order.
	 *
	 * @param WC_Order $order Order instance.
	 * @return string
	 */
	private function render_meta_box( WC_Order $order ): string {
		$meta_box = $this->create_meta_box( true );

		ob_start();
		$meta_box->render_meta_box( $order );

		return (string) ob_get_clean();
	}

	/**
	 * Get order edit screen IDs that should receive the meta box.
	 *
	 * @return string[]
	 */
	private function get_order_edit_screen_ids(): array {
		$screen_ids = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}

	/**
	 * Get a registered Fraud & Risk meta box for a screen.
	 *
	 * @param string $screen_id Screen ID.
	 * @return array<string,mixed>
	 */
	private function get_registered_meta_box( string $screen_id ): array {
		global $wp_meta_boxes;

		$this->assertArrayHasKey( $screen_id, $wp_meta_boxes, "Screen {$screen_id} should have registered meta boxes." );
		$this->assertArrayHasKey( self::META_BOX_ID, $wp_meta_boxes[ $screen_id ]['side']['default'], "Screen {$screen_id} should register the Fraud & Risk meta box." );

		return $wp_meta_boxes[ $screen_id ]['side']['default'][ self::META_BOX_ID ];
	}

	/**
	 * Clear registered Fraud & Risk meta boxes.
	 */
	private function clear_registered_meta_boxes(): void {
		global $wp_meta_boxes;

		if ( ! is_array( $wp_meta_boxes ) ) {
			return;
		}

		foreach ( $this->get_order_edit_screen_ids() as $screen_id ) {
			unset( $wp_meta_boxes[ $screen_id ]['side']['default'][ self::META_BOX_ID ] );
		}
	}
}

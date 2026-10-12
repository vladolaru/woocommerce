<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderFraudMetaBox;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
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
	 * @testdox Should register the meta box hook only when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_meta_box_hook_only_when_builtin_owns_runtime(): void {
		$extension_owned = $this->create_meta_box( false );
		$extension_owned->register();

		$this->assertFalse(
			has_action( 'add_meta_boxes', array( $extension_owned, 'handle_add_meta_boxes' ) ),
			'Plugin-owned runtime should not register native order meta boxes.'
		);

		$builtin_owned = $this->create_meta_box( true );
		$builtin_owned->register();

		$this->assertSame(
			10,
			has_action( 'add_meta_boxes', array( $builtin_owned, 'handle_add_meta_boxes' ) ),
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
	 * @param string   $meta_box_type         Fraud meta box type.
	 * @param string   $fraud_outcome_status  Fraud outcome status meta.
	 * @param string   $expected_status       Expected status copy.
	 * @param string   $expected_description  Expected description copy.
	 * @param string[] $expected_link_parts   Parts of the transaction details link the panel shows, with {order_id} for the order ID.
	 * @param string[] $unexpected_link_parts Parts of a transaction details link the panel must not show.
	 */
	public function test_renders_extension_copy_for_each_fraud_meta_box_type( string $meta_box_type, string $fraud_outcome_status, string $expected_status, string $expected_description, array $expected_link_parts, array $unexpected_link_parts ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type'  => $meta_box_type,
				'_wcpay_fraud_outcome_status' => $fraud_outcome_status,
				'_intent_id'                  => 'pi_mock',
				'_charge_id'                  => 'ch_mock',
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'wcpay-fraud-risk-action', $html );
		$this->assertStringContainsString( $expected_status, $html );
		$this->assertStringContainsString( $expected_description, $html );
		$this->assertStringContainsString( 'Adjust risk filters', $html );
		// Client 11.1.0 `class-order-fraud-and-risk-meta-box.php:108`: the main settings page at its Fraud protection section.
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings#fraud-protection' ) ) . '"', $html );
		$this->assertStringNotContainsString( '/woopayments/settings/fraud-protection', $html );
		foreach ( str_replace( '{order_id}', (string) $order->get_id(), $expected_link_parts ) as $part ) {
			$this->assertStringContainsString( $part, $html );
		}
		foreach ( $unexpected_link_parts as $part ) {
			$this->assertStringNotContainsString( $part, $html );
		}
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
	 * @testdox Should render an actionable early fraud warning before the normal risk panel.
	 */
	public function test_renders_actionable_early_fraud_warning_before_risk_level(): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_charge_id'                 => 'ch_early_warning',
				'_charge_risk_level'         => 'normal',
				'_wcpay_early_fraud_warning' => array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => 'made_with_stolen_card',
					'created'        => 123,
				),
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'Early fraud warning', $html );
		$this->assertStringContainsString( 'Reported reason: Made with stolen card', $html );
		$this->assertStringContainsString( 'Refund this payment', $html );
		$this->assertStringContainsString( 'wcpay-efw-refund-link', $html );
		$this->assertStringContainsString( 'id=ch_early_warning', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$warning_position = strpos( $html, 'Early fraud warning' );
		$risk_position    = strpos( $html, 'Normal' );
		$action_position  = strpos( $html, 'Adjust risk filters' );
		$this->assertIsInt( $warning_position );
		$this->assertIsInt( $risk_position );
		$this->assertIsInt( $action_position );
		$this->assertLessThan( $risk_position, $warning_position );
		$this->assertLessThan( $action_position, $warning_position );
	}

	/**
	 * @testdox Should render a resolved early fraud warning without a refund action.
	 */
	public function test_renders_resolved_early_fraud_warning_without_refund_action(): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_wcpay_early_fraud_warning' => array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => false,
					'efw_type'       => 'unknown_reason',
					'created'        => 123,
				),
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'Early fraud warning resolved', $html );
		$this->assertStringContainsString( 'This payment was refunded or disputed, so the warning is no longer actionable.', $html );
		$this->assertStringNotContainsString( 'Refund this payment', $html );
		$this->assertStringNotContainsString( 'Reported reason:', $html );
	}

	/**
	 * @testdox Should omit malformed early fraud warning evidence.
	 *
	 * @dataProvider malformed_early_fraud_warning_provider
	 *
	 * @param mixed $stored_warning Malformed stored warning evidence.
	 */
	public function test_omits_malformed_early_fraud_warning_evidence( $stored_warning ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_wcpay_early_fraud_warning' => $stored_warning,
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringNotContainsString( 'Early fraud warning', $html );
	}

	/**
	 * @testdox Should keep provider charge data inside the actionable warning link URL.
	 */
	public function test_escapes_the_early_fraud_warning_transaction_link(): void {
		$order    = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
				'_charge_id'                 => 'ch_" onmouseover="alert(1)',
				'_wcpay_early_fraud_warning' => array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => 123,
				),
			)
		);
		$html     = $this->render_meta_box( $order );
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$links = ( new \DOMXPath( $document ) )->query( '//a[contains(concat(" ", normalize-space(@class), " "), " wcpay-efw-refund-link ")]' );

		$this->assertInstanceOf( \DOMNodeList::class, $links );
		$this->assertSame( 1, $links->length );
		$link = $links->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $link );
		$this->assertFalse( $link->hasAttribute( 'onmouseover' ) );
		$this->assertStringContainsString( 'id=ch_', $link->getAttribute( 'href' ) );
	}

	/**
	 * @testdox Active block and review panels render escaped triggered filters before their primary action.
	 *
	 * @dataProvider active_fraud_type_provider
	 *
	 * @param string $type           Active fraud panel type.
	 * @param string $primary_action Primary action label.
	 */
	public function test_renders_triggered_rules_for_block_and_review_before_primary_action( string $type, string $primary_action ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type'   => $type,
				'_wcpay_fraud_ruleset_results' => '{"avs_verification":"review","new_<rule>":"block","order_items_threshold":"allow"}',
				'_intent_id'                   => 'pi_review',
			)
		);

		$html = $this->render_meta_box( $order );

		$this->assertSame( 1, substr_count( $html, 'Triggered risk filters:' ) );
		$this->assertStringContainsString( '<ul class="wcpay-fraud-risk-triggered-filters"><li>Place in review if the AVS verification fails</li><li>New &lt;rule&gt;</li></ul>', $html );
		$this->assertStringNotContainsString( 'Order items threshold', $html );
		$this->assertLessThan( strpos( $html, '>' . $primary_action . '</a>' ), strpos( $html, 'Triggered risk filters:' ) );
	}

	/**
	 * @testdox Empty, invalid, and resolved-review fraud results do not render a triggered-filter list.
	 *
	 * @dataProvider non_active_or_invalid_ruleset_provider
	 *
	 * @param string $type    Fraud panel type.
	 * @param string $ruleset Stored ruleset JSON.
	 */
	public function test_omits_triggered_rules_for_empty_invalid_and_resolved_review_states( string $type, string $ruleset ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type'   => $type,
				'_wcpay_fraud_ruleset_results' => $ruleset,
			)
		);

		$this->assertStringNotContainsString( 'Triggered risk filters:', $this->render_meta_box( $order ) );
	}

	/**
	 * @testdox Should render Learn more copy without risk-filter settings for $_dataName orders.
	 * @dataProvider provider_non_card_orders
	 *
	 * @param string $payment_method       Payment method ID.
	 * @param string $payment_method_title Payment method title.
	 */
	public function test_renders_learn_more_copy_without_adjust_filters_link_for_non_card_orders( string $payment_method, string $payment_method_title ): void {
		$order = $this->create_woopayments_order(
			array(
				'_wcpay_fraud_meta_box_type' => 'allow',
			),
			$payment_method,
			$payment_method_title
		);

		$html = $this->render_meta_box( $order );

		$this->assertStringContainsString( 'Risk filtering is only available for orders processed using credit cards with WooPayments. This order was processed with ' . $payment_method_title . '.', $html );
		$this->assertStringContainsString( 'Learn more', $html );
		$this->assertStringContainsString( 'fraud-meta-box-not-wcpay-learn-more', $html );
		$this->assertStringNotContainsString( 'Adjust risk filters', $html );
	}

	/**
	 * Orders paid without a WooPayments card.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function provider_non_card_orders(): array {
		return array(
			'non-card WooPayments' => array( 'woocommerce_payments_bancontact', 'Bancontact' ),
			'non-WooPayments'      => array( 'bacs', 'Direct bank transfer' ),
		);
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
	 * @return array<string,array{string,string,string,string,string[],string[]}>
	 */
	public function fraud_meta_box_type_provider(): array {
		$details        = 'path=/woopayments/transactions/details';
		$no_link        = array( '/woopayments/transactions/details' );
		$block_link     = array( 'View more details', $details, 'id={order_id}', 'status_is=block', 'type_is=meta_box' );
		$review_link    = array( 'Review payment', $details, 'id=pi_mock', 'status_is=review', 'type_is=meta_box' );
		$reviewed_link  = array( 'Review payment', $details, 'id=pi_mock' );
		$blocked_copy   = 'The payment for this order was blocked by your risk filtering. There is no pending authorization, and the order can be cancelled to reduce any held stock.';
		$in_review_copy = 'The payment for this order was held for review by your risk filtering. You can review the details and determine whether to approve or block the payment.';

		return array(
			'allow'                       => array( 'allow', '', 'No action taken', 'The payment for this order passed your risk filtering.', array(), $no_link ),
			'block'                       => array( 'block', '', 'Blocked', $blocked_copy, $block_link, array() ),
			'payment started'             => array( 'payment_started', '', 'No action taken', 'The payment for this order has not yet been passed to the fraud and risk filters to determine its outcome status.', array(), $no_link ),
			'review'                      => array( 'review', '', 'Held for review', $in_review_copy, $review_link, array() ),
			'review allowed'              => array( 'review_allowed', '', 'Approved', 'The payment for this order was held for review by your risk filtering and manually approved.', array(), $no_link ),
			'review blocked'              => array( 'review_blocked', '', 'Held for review', 'This transaction was held for review by your risk filters, and the charge was manually blocked after review.', $reviewed_link, array() ),
			'review expired'              => array( 'review_expired', '', 'Held for review', 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have expired.', $reviewed_link, array() ),
			'review failed'               => array( 'review_failed', '', 'Held for review', 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have failed.', $reviewed_link, array() ),
			'terminal payment'            => array( 'terminal_payment', '', 'No action taken', 'The payment for this order was done in person and has bypassed your risk filtering.', array(), $no_link ),
			'allow with a block outcome'  => array( 'allow', 'block', 'Blocked', $blocked_copy, $block_link, array() ),
			'allow with a review outcome' => array( 'allow', 'review', 'Held for review', $in_review_copy, $review_link, array() ),
		);
	}

	/**
	 * Active fraud panels with a primary action.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function active_fraud_type_provider(): array {
		return array(
			'block'  => array( 'block', 'View more details' ),
			'review' => array( 'review', 'Review payment' ),
		);
	}

	/**
	 * Inactive panels and unusable persisted results.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function non_active_or_invalid_ruleset_provider(): array {
		return array(
			'empty JSON array' => array( 'review', '[]' ),
			'invalid JSON'     => array( 'review', '{invalid' ),
			'all allowed'      => array( 'block', '{"avs_verification":"allow"}' ),
			'review approved'  => array( 'review_allowed', '{"avs_verification":"review"}' ),
			'non-card'         => array( 'not_card', '{"avs_verification":"review"}' ),
			'terminal'         => array( 'terminal_payment', '{"avs_verification":"review"}' ),
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
	 * Malformed stored early fraud warning cases.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function malformed_early_fraud_warning_provider(): array {
		return array(
			'scalar'       => array( 'not-an-array' ),
			'missing key'  => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
				),
			),
			'extra key'    => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => 123,
					'charge'         => 'ch_123',
				),
			),
			'wrong ID'     => array(
				array(
					'efw_id'         => 123,
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => 123,
				),
			),
			'wrong state'  => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => 'yes',
					'efw_type'       => '',
					'created'        => 123,
				),
			),
			'wrong reason' => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => array(),
					'created'        => 123,
				),
			),
			'wrong time'   => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => -1,
				),
			),
		);
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool $builtin_register Whether the built-in WooPayments owns the runtime.
	 * @return object
	 */
	private function create_meta_box( bool $builtin_register ) {

		$meta_box = new WooPaymentsOrderFraudMetaBox();
		$meta_box->init( new StaticWooPaymentsRuntimeArbiter( $builtin_register ) );

		$this->meta_boxes[] = $meta_box;

		return $meta_box;
	}

	/**
	 * Create a WooPayments order with fraud meta.
	 *
	 * @param array<string,mixed> $meta                 Order meta.
	 * @param string              $payment_method       Payment method ID.
	 * @param string              $payment_method_title Payment method title.
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

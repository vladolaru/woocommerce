<?php
/**
 * WooPaymentsOrderFraudMetaBox class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Native WooPayments Fraud & Risk order meta box.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderFraudMetaBox implements RegisterHooksInterface {

	private const META_BOX_ID = 'wcpay-order-fraud-and-risk-meta-box';

	private const PATH_TRANSACTION_DETAILS = '/woopayments/transactions/details';

	private const PATH_FRAUD_PROTECTION_SETTINGS = '/woopayments/settings/fraud-protection';

	private const META_INTENT_ID = '_intent_id';

	private const META_CHARGE_ID = '_charge_id';

	private const META_RISK_LEVEL = '_charge_risk_level';

	private const META_FRAUD_META_BOX_TYPE = '_wcpay_fraud_meta_box_type';

	private const META_FRAUD_OUTCOME_STATUS = '_wcpay_fraud_outcome_status';

	private const TYPE_ALLOW = 'allow';

	private const TYPE_BLOCK = 'block';

	private const TYPE_NOT_CARD = 'not_card';

	private const TYPE_NOT_WCPAY = 'not_wcpay';

	private const TYPE_PAYMENT_STARTED = 'payment_started';

	private const TYPE_REVIEW = 'review';

	private const TYPE_REVIEW_ALLOWED = 'review_allowed';

	private const TYPE_REVIEW_BLOCKED = 'review_blocked';

	private const TYPE_REVIEW_EXPIRED = 'review_expired';

	private const TYPE_REVIEW_FAILED = 'review_failed';

	private const TYPE_TERMINAL_PAYMENT = 'terminal_payment';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register order meta box hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'add_meta_boxes', array( $this, 'handle_add_meta_boxes' ) ) ) {
			add_action( 'add_meta_boxes', array( $this, 'handle_add_meta_boxes' ) );
		}

		if ( false === has_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) );
		}
	}

	/**
	 * Handle the add_meta_boxes hook.
	 *
	 * @internal
	 */
	public function handle_add_meta_boxes(): void {
		foreach ( $this->get_order_edit_screen_ids() as $screen_id ) {
			add_meta_box(
				self::META_BOX_ID,
				__( 'Fraud &amp; Risk', 'woocommerce' ),
				array( $this, 'render_meta_box' ),
				$screen_id,
				'side',
				'default'
			);
		}
	}

	/**
	 * Handle the admin_enqueue_scripts hook.
	 *
	 * @internal
	 */
	public function handle_admin_enqueue_scripts(): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';
		if ( ! in_array( $screen_id, $this->get_order_edit_screen_ids(), true ) ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_add_inline_style( 'woocommerce_admin_styles', $this->get_inline_styles() );
	}

	/**
	 * Render the Fraud & Risk order meta box.
	 *
	 * @param mixed $order_or_post Order, post, or order ID.
	 */
	public function render_meta_box( $order_or_post ): void {
		$order = wc_get_order( $order_or_post );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$intent_id     = (string) $order->get_meta( self::META_INTENT_ID, true );
		$charge_id     = (string) $order->get_meta( self::META_CHARGE_ID, true );
		$meta_box_type = $this->get_fraud_meta_box_type( $order );
		$risk_level    = (string) $order->get_meta( self::META_RISK_LEVEL, true );

		$this->print_risk_level_block( $risk_level );

		$show_adjust_risk_filters_link = true;

		echo '<div class="wcpay-fraud-risk-action">';

		switch ( $meta_box_type ) {
			case self::TYPE_ALLOW:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-allow',
					'wcpay-fraud-risk-meta-icon--allow',
					__( 'No action taken', 'woocommerce' ),
					__( 'The payment for this order passed your risk filtering.', 'woocommerce' )
				);
				break;

			case self::TYPE_BLOCK:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-blocked',
					'wcpay-fraud-risk-meta-icon--blocked',
					__( 'Blocked', 'woocommerce' ),
					__( 'The payment for this order was blocked by your risk filtering. There is no pending authorization, and the order can be cancelled to reduce any held stock.', 'woocommerce' )
				);
				$this->print_action_link(
					__( 'View more details', 'woocommerce' ),
					$this->get_transaction_url(
						(string) $order->get_id(),
						'',
						array(
							'status_is' => self::TYPE_BLOCK,
							'type_is'   => 'meta_box',
						)
					)
				);
				break;

			case self::TYPE_NOT_CARD:
			case self::TYPE_NOT_WCPAY:
				$show_adjust_risk_filters_link = false;
				$this->print_non_card_action( $order );
				break;

			case self::TYPE_PAYMENT_STARTED:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-review',
					'wcpay-fraud-risk-meta-icon--review',
					__( 'No action taken', 'woocommerce' ),
					__( 'The payment for this order has not yet been passed to the fraud and risk filters to determine its outcome status.', 'woocommerce' )
				);
				break;

			case self::TYPE_REVIEW:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-review',
					'wcpay-fraud-risk-meta-icon--review',
					__( 'Held for review', 'woocommerce' ),
					__( 'The payment for this order was held for review by your risk filtering. You can review the details and determine whether to approve or block the payment.', 'woocommerce' )
				);
				$this->print_action_link(
					__( 'Review payment', 'woocommerce' ),
					$this->get_transaction_url(
						$intent_id,
						$charge_id,
						array(
							'status_is' => self::TYPE_REVIEW,
							'type_is'   => 'meta_box',
						)
					)
				);
				break;

			case self::TYPE_REVIEW_ALLOWED:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-allow',
					'wcpay-fraud-risk-meta-icon--allow',
					__( 'Approved', 'woocommerce' ),
					__( 'The payment for this order was held for review by your risk filtering and manually approved.', 'woocommerce' )
				);
				break;

			case self::TYPE_REVIEW_BLOCKED:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-blocked',
					'wcpay-fraud-risk-meta-icon--blocked',
					__( 'Held for review', 'woocommerce' ),
					__( 'This transaction was held for review by your risk filters, and the charge was manually blocked after review.', 'woocommerce' )
				);
				$this->print_action_link(
					__( 'Review payment', 'woocommerce' ),
					$this->get_transaction_url( $intent_id, $charge_id )
				);
				break;

			case self::TYPE_REVIEW_EXPIRED:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-review',
					'wcpay-fraud-risk-meta-icon--review',
					__( 'Held for review', 'woocommerce' ),
					__( 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have expired.', 'woocommerce' )
				);
				$this->print_action_link(
					__( 'Review payment', 'woocommerce' ),
					$this->get_transaction_url( $intent_id, $charge_id )
				);
				break;

			case self::TYPE_REVIEW_FAILED:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-review',
					'wcpay-fraud-risk-meta-icon--review',
					__( 'Held for review', 'woocommerce' ),
					__( 'The payment for this order was held for review by your risk filtering. The authorization for the charge appears to have failed.', 'woocommerce' )
				);
				$this->print_action_link(
					__( 'Review payment', 'woocommerce' ),
					$this->get_transaction_url( $intent_id, $charge_id )
				);
				break;

			case self::TYPE_TERMINAL_PAYMENT:
				$this->print_status_with_description(
					'wcpay-fraud-risk-meta-allow',
					'wcpay-fraud-risk-meta-icon--allow',
					__( 'No action taken', 'woocommerce' ),
					__( 'The payment for this order was done in person and has bypassed your risk filtering.', 'woocommerce' )
				);
				break;

			default:
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: WooPayments. */
							__( 'Risk filtering through %s was not found on this order, it may have been created while filtering was not enabled.', 'woocommerce' ),
							'WooPayments'
						)
					)
				);
				break;
		}

		if ( $show_adjust_risk_filters_link ) {
			$this->print_action_link( __( 'Adjust risk filters', 'woocommerce' ), Utils::wc_payments_settings_url( self::PATH_FRAUD_PROTECTION_SETTINGS ) );
		}

		echo '</div>';
	}

	/**
	 * Get the meta box type to render.
	 *
	 * @param WC_Order $order Order instance.
	 * @return string
	 */
	private function get_fraud_meta_box_type( WC_Order $order ): string {
		$payment_method = (string) $order->get_payment_method();
		if ( str_starts_with( $payment_method, 'woocommerce_payments_' ) ) {
			return self::TYPE_NOT_CARD;
		}

		if ( 'woocommerce_payments' !== $payment_method ) {
			return self::TYPE_NOT_WCPAY;
		}

		$meta_box_type        = (string) $order->get_meta( self::META_FRAUD_META_BOX_TYPE, true );
		$fraud_outcome_status = (string) $order->get_meta( self::META_FRAUD_OUTCOME_STATUS, true );
		if (
			in_array( $fraud_outcome_status, array( self::TYPE_BLOCK, self::TYPE_REVIEW ), true ) &&
			in_array( $meta_box_type, array( '', self::TYPE_ALLOW ), true )
		) {
			return $fraud_outcome_status;
		}

		return $meta_box_type;
	}

	/**
	 * Print the non-card or non-WooPayments copy and action.
	 *
	 * @param WC_Order $order Order instance.
	 */
	private function print_non_card_action( WC_Order $order ): void {
		$payment_method_title = $order->get_payment_method_title();

		if ( ! empty( $payment_method_title ) && 'Popular payment methods' !== $payment_method_title ) {
			$description = sprintf(
				/* translators: 1: WooPayments, 2: payment method title. */
				__( 'Risk filtering is only available for orders processed using credit cards with %1$s. This order was processed with %2$s.', 'woocommerce' ),
				'WooPayments',
				$payment_method_title
			);
		} else {
			$description = sprintf(
				/* translators: %s: WooPayments. */
				__( 'Risk filtering is only available for orders processed using credit cards with %s.', 'woocommerce' ),
				'WooPayments'
			);
		}

		echo '<p>' . esc_html( $description ) . '</p>';
		$this->print_action_link(
			__( 'Learn more', 'woocommerce' ),
			add_query_arg(
				'status_is',
				'fraud-meta-box-not-wcpay-learn-more',
				'https://woocommerce.com/document/woopayments/fraud-and-disputes/fraud-protection/'
			)
		);
	}

	/**
	 * Print the risk level block when the risk level is known.
	 *
	 * @param string $risk_level Risk level.
	 */
	private function print_risk_level_block( string $risk_level ): void {
		$titles = array(
			'normal'   => __( 'Normal', 'woocommerce' ),
			'elevated' => __( 'Elevated', 'woocommerce' ),
			'highest'  => __( 'High', 'woocommerce' ),
		);

		$descriptions = array(
			'normal'   => __( 'This payment shows a lower than normal risk of fraudulent activity.', 'woocommerce' ),
			'elevated' => __( 'This order has a moderate risk of being fraudulent. We suggest contacting the customer to confirm their details before fulfilling it.', 'woocommerce' ),
			'highest'  => __( 'This order has a high risk of being fraudulent. We suggest contacting the customer to confirm their details before fulfilling it.', 'woocommerce' ),
		);

		if ( ! isset( $titles[ $risk_level ], $descriptions[ $risk_level ] ) ) {
			return;
		}

		echo '<div class="wcpay-fraud-risk-level wcpay-fraud-risk-level--' . esc_attr( $risk_level ) . '">';
		echo '<p class="wcpay-fraud-risk-level__title">' . esc_html( $titles[ $risk_level ] ) . '</p>';
		echo '<div class="wcpay-fraud-risk-level__bar"></div>';
		echo '<p>' . esc_html( $descriptions[ $risk_level ] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Print a status line and description.
	 *
	 * @param string $status_class Status paragraph class.
	 * @param string $icon_class   Status icon class.
	 * @param string $status       Status text.
	 * @param string $description  Description text.
	 */
	private function print_status_with_description( string $status_class, string $icon_class, string $status, string $description ): void {
		echo '<p class="' . esc_attr( $status_class ) . '"><span class="wcpay-fraud-risk-meta-icon ' . esc_attr( $icon_class ) . '" aria-hidden="true"></span> ' . esc_html( $status ) . '</p>';
		echo '<p>' . esc_html( $description ) . '</p>';
	}

	/**
	 * Print a linked action when a URL is available.
	 *
	 * @param string $label Link label.
	 * @param string $url   Link URL.
	 */
	private function print_action_link( string $label, string $url ): void {
		if ( '' === $url ) {
			return;
		}

		echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Get the native transaction details URL.
	 *
	 * @param string               $primary_id  Usually the Payment Intent ID, but can be an order ID.
	 * @param string               $fallback_id Usually the Charge ID.
	 * @param array<string,string> $query       Extra tracking query.
	 * @return string
	 */
	private function get_transaction_url( string $primary_id, string $fallback_id = '', array $query = array() ): string {
		if ( false !== strpos( $primary_id, 'seti_' ) ) {
			return '';
		}

		$id = '' !== $primary_id ? $primary_id : $fallback_id;
		if ( '' === $id ) {
			return '';
		}

		return Utils::wc_payments_settings_url(
			self::PATH_TRANSACTION_DETAILS,
			array_merge(
				array(
					'id' => $id,
				),
				$query
			)
		);
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
	 * Get native Fraud & Risk meta box styles.
	 *
	 * @return string
	 */
	private function get_inline_styles(): string {
		return '
#wcpay-order-fraud-and-risk-meta-box div.inside {
	margin-top: 0;
	padding: 0;
}

.wcpay-fraud-risk-level {
	border-bottom: 1px solid #ddd;
	padding: 8px 12px;
}

.wcpay-fraud-risk-level > p {
	margin: 0;
}

.wcpay-fraud-risk-level__title {
	font-weight: 600;
}

.wcpay-fraud-risk-level__bar {
	display: grid;
	gap: 4px;
	grid-template-columns: 50% auto;
	margin: 6px 0 8px;
}

.wcpay-fraud-risk-level__bar::after,
.wcpay-fraud-risk-level__bar::before {
	background-color: #bbb;
	border-radius: 4px;
	content: "";
	height: 4px;
}

.wcpay-fraud-risk-level--normal .wcpay-fraud-risk-level__title,
.wcpay-fraud-risk-meta-allow {
	color: #008a20;
}

.wcpay-fraud-risk-level--normal .wcpay-fraud-risk-level__bar {
	grid-template-columns: 15% auto;
}

.wcpay-fraud-risk-level--normal .wcpay-fraud-risk-level__bar::before {
	background-color: #008a20;
}

.wcpay-fraud-risk-level--elevated .wcpay-fraud-risk-level__title,
.wcpay-fraud-risk-meta-review {
	color: #b16202;
}

.wcpay-fraud-risk-level--elevated .wcpay-fraud-risk-level__bar {
	grid-template-columns: 60% auto;
}

.wcpay-fraud-risk-level--elevated .wcpay-fraud-risk-level__bar::before {
	background-color: #b16202;
}

.wcpay-fraud-risk-level--highest .wcpay-fraud-risk-level__title,
.wcpay-fraud-risk-meta-blocked {
	color: #b32d2e;
}

.wcpay-fraud-risk-level--highest .wcpay-fraud-risk-level__bar {
	grid-template-columns: 100% auto;
}

.wcpay-fraud-risk-level--highest .wcpay-fraud-risk-level__bar::before {
	background-color: #b32d2e;
}

.wcpay-fraud-risk-action {
	padding: 8px 12px 12px;
}

.wcpay-fraud-risk-action > p {
	margin: 0 0 6px;
}

.wcpay-fraud-risk-action > p:last-child {
	margin-bottom: 0;
}

.wcpay-fraud-risk-meta-allow,
.wcpay-fraud-risk-meta-review,
.wcpay-fraud-risk-meta-blocked {
	font-weight: 600;
}

.wcpay-fraud-risk-meta-icon {
	background: currentColor;
	border-radius: 50%;
	display: inline-block;
	height: 11px;
	margin-right: 5px;
	vertical-align: -1px;
	width: 11px;
}

.wcpay-fraud-risk-meta-icon--review {
	background: transparent;
	border: 2px solid currentColor;
	height: 9px;
	width: 9px;
}
';
	}
}

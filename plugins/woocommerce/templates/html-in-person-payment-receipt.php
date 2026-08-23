<?php
/**
 * In-Person Payments print receipt template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/html-in-person-payment-receipt.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wcpay_format_price_helper' ) ) {
	/**
	 * Helper to generate markup to render a price.
	 *
	 * Kept under the name the WooPayments plugin's receipt template uses so
	 * theme overrides written against the plugin keep working unchanged.
	 *
	 * @param  array  $product  The product to display.
	 * @param  string $currency The currency to display.
	 * @return string
	 */
	function wcpay_format_price_helper( array $product, string $currency ): string {
		$active_price  = $product['price'];
		$regular_price = $product['regular_price'];
		$has_discount  = $active_price !== $regular_price;

		if ( $has_discount ) {
			return '<s>' . wc_price( $regular_price, array( 'currency' => $currency ) ) . '</s> ' . wc_price( $active_price, array( 'currency' => $currency ) );
		}

		return wc_price( $active_price, array( 'currency' => $currency ) );
	}
}

$payment_method_display_name = $payment_method_display_name ?? '';

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Print Receipt</title>
	<style>
		body {
			margin: 0;
			padding: 0;
			border: 0;
		}

		.align-left {
			text-align: left;
		}

		.align-right {
			text-align: right;
		}
		.align-top {
			vertical-align: top;
		}

		.receipt {
			min-width: 130px;
			max-width: 300px;
			margin: 0 auto;
			text-align: center;
			font-family: SF Pro Text, sans-serif;
			font-size: 10px;

		}

		.receipt-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0 2px;
			font-size: 10px;
		}

		.receipt__header .title {
			font-size: 14px;
			line-height: 17px;
			margin-bottom: 12px;
			margin-top: 12px;
			font-weight: 700;
		}

		.receipt__header .store {
			padding: 0 12px;
		}

		.receipt__header .store__address {
			margin-top: 12px;
			line-height: 2px;
		}

		.receipt__header .store__contact {
			margin-top: 4px;
		}

		.receipt__header .order__title {
			font-weight: 800;
		}

		.receipt__transaction {
			line-height: 2px;
		}

		.branding-logo {
			max-width: 250px;
			margin: 20px auto;
		}

		#powered_by {
			font-size: 7px;
			padding-top: 5px;
		}

	</style>
</head>
<body>
	<div class="receipt">
		<div class="receipt__header">
			<?php if ( ! empty( $branding_logo['content_type'] ) ) { ?>
				<img class="branding-logo" src="data:<?php echo esc_html( $branding_logo['content_type'] ); ?>;base64,<?php echo esc_html( $branding_logo['file_content'] ); ?>" alt="<?php echo esc_html( $business_name ); ?>"/>
			<?php } ?>
			<h1 class="title"><?php echo esc_html( $business_name ); ?></h1>
			<hr />
			<div class="store">
				<?php if ( $support_address ) { ?>
				<div class="store__address">
					<p><?php echo esc_html( $support_address['line1'] ); ?></p>
					<p><?php echo esc_html( $support_address['line2'] ); ?></p>
					<p><?php echo esc_html( implode( ' ', array( $support_address['city'], $support_address['state'], $support_address['postal_code'], $support_address['country'] ) ) ); ?></p>
					<?php echo esc_html( gmdate( 'Y/m/d - H:iA' ) ); ?>
				</div>
				<?php } ?>
				<p class="store__contact">
					<?php echo esc_html( implode( ' ', array( $support_phone, $support_email ) ) ); ?>
				</p>
			</div>
			<div class="order">
				<p class="order__title"><?php printf( '%s %s', esc_html__( 'Order', 'woocommerce' ), esc_html( $order['id'] ) ); ?></p>
			</div>
		</div>
		<hr />
		<div class="receipt__products">
			<table class="receipt-table">
				<?php foreach ( $line_items as $item ) { ?>
				<tr>
					<td class="align-left">
						<div><?php echo esc_html( $item['name'] ); ?></div>
						<div><?php echo esc_html( $item['quantity'] ); ?> @ <?php echo wp_kses( wcpay_format_price_helper( $item['product'], $order['currency'] ), 'post' ); ?></div>
						<div><?php printf( '%s: %s', esc_html__( 'SKU', 'woocommerce' ), esc_html( $item['product']['id'] ) ); ?></div>
					</td>
					<td class="align-right align-top"><?php echo wp_kses( wc_price( $item['subtotal'], array( 'currency' => $order['currency'] ) ), 'post' ); ?></td>
				</tr>
				<?php } ?>
			</table>
		</div>
		<hr />
		<div class="receipt__subtotal">
			<table class="receipt-table">
				<tr>
					<td class="align-left"><b><?php echo esc_html__( 'SUBTOTAL', 'woocommerce' ); ?></b></td>
					<td class="align-right"><b><?php echo wp_kses( wc_price( $order['subtotal'], array( 'currency' => $order['currency'] ) ), 'post' ); ?></b></td>
				</tr>
				<?php foreach ( $coupon_lines as $order_coupon ) { ?>
				<tr>
					<td class="align-left">
						<div><?php printf( '%s: %s', esc_html__( 'Discount', 'woocommerce' ), esc_html( $order_coupon['code'] ) ); ?></div>
						<div><?php echo esc_html( $order_coupon['description'] ); ?></div>
					</td>
					<td class="align-right align-top"><?php echo wp_kses( wc_price( abs( $order_coupon['discount'] ) * -1, array( 'currency' => $order['currency'] ) ), 'post' ); ?></td>
				</tr>
				<?php } ?>
				<?php if ( 0 < $order['total_fees'] ) : ?>
					<tr>
						<td class="align-left"><?php esc_html_e( 'Fees:', 'woocommerce' ); ?></td>
						<td class="align-right align-top">
							<?php echo wp_kses( wc_price( $order['total_fees'], array( 'currency' => $order['currency'] ) ), 'post' ); ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( 0 < $order['shipping_tax'] ) : ?>
					<tr>
						<td class="align-left"><?php esc_html_e( 'Shipping:', 'woocommerce' ); ?></td>
						<td class="align-right align-top">
							<?php echo wp_kses( wc_price( $order['shipping_tax'], array( 'currency' => $order['currency'] ) ), 'post' ); ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $tax_lines as $tax_line ) { ?>
				<tr>
					<td class="align-left">
						<div><?php echo esc_html__( 'Tax', 'woocommerce' ); ?></div>
						<div><?php echo esc_html( wc_round_tax_total( $tax_line['rate_percent'] ) ); ?>%</div>
					</td>
					<td class="align-right align-top"><?php echo wp_kses( wc_price( $tax_line['tax_total'] + $tax_line['shipping_tax_total'], array( 'currency' => $order['currency'] ) ), 'post' ); ?></td>
				</tr>
				<?php } ?>
				<tr>
					<td colspan="2" class="align-left"></td>
				</tr>
				<tr>
					<td class="align-left"><b><?php echo esc_html__( 'TOTAL', 'woocommerce' ); ?></b></td>
					<td class="align-right"><b><?php echo wp_kses( wc_price( $order['total'], array( 'currency' => $order['currency'] ) ), 'post' ); ?></b></td>
				</tr>
			</table>
		</div>
		<hr />
		<div class="receipt__amount-paid">
			<table class="receipt-table">
				<tr>
					<td class="align-left"><b><?php echo esc_html__( 'AMOUNT PAID', 'woocommerce' ); ?></b>:</td>
					<td class="align-right"><b><?php echo wp_kses( wc_price( $amount_captured, array( 'currency' => $order['currency'] ) ), 'post' ); ?></b></td>
				</tr>
				<tr>
					<td colspan="2" class="align-left"><?php echo esc_html( sprintf( '%s - %s', $payment_method_display_name, $payment_method_details['last4'] ) ); ?></td>
				</tr>
			</table>
		</div>
		<hr />
		<div class="receipt__transaction">
			<p id="application-preferred-name"><?php printf( '%s: %s', esc_html__( 'Application name', 'woocommerce' ), esc_html( ucfirst( $receipt['application_preferred_name'] ) ) ); ?></p>
			<p id="dedicated-file-name"><?php printf( '%s: %s', esc_html__( 'AID', 'woocommerce' ), esc_html( ucfirst( $receipt['dedicated_file_name'] ) ) ); ?></p>
			<p id="account_type"><?php printf( '%s: %s', esc_html__( 'Account Type', 'woocommerce' ), esc_html( ucfirst( $receipt['account_type'] ) ) ); ?></p>
			<p id="powered_by"><?php echo esc_html__( 'Powered by WooCommerce', 'woocommerce' ); ?></p>
		</div>
	</div>
</body>
</html>

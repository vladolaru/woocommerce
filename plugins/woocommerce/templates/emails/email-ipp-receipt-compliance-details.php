<?php
/**
 * IPP receipt compliance details.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/email-ipp-receipt-compliance-details.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

$payment_method_display_name = $payment_method_display_name ?? '';
?>

<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<tbody>
			<tr>
				<th class="td" scope="row" colspan="2">
					<?php esc_html_e( 'Payment Method', 'woocommerce' ); ?>
				</th>
				<td class="td">
					<div><?php echo esc_html( sprintf( '%s - %s', $payment_method_display_name, $payment_method_details['last4'] ?? '' ) ); ?></div>
				</td>
			</tr>
			<tr>
				<th class="td" scope="row" colspan="2">
					<?php esc_html_e( 'Application Name', 'woocommerce' ); ?>
				</th>
				<td class="td">
					<div id="application-preferred-name"><?php echo esc_html( ucfirst( (string) ( $receipt['application_preferred_name'] ?? '' ) ) ); ?></div>
				</td>
			</tr>
			<tr>
				<th class="td" scope="row" colspan="2">
					<?php esc_html_e( 'AID', 'woocommerce' ); ?>
				</th>
				<td class="td">
					<div id="dedicated-file-name"><?php echo esc_html( ucfirst( (string) ( $receipt['dedicated_file_name'] ?? '' ) ) ); ?></div>
				</td>
			</tr>
			<tr>
				<th class="td" scope="row" colspan="2">
					<?php esc_html_e( 'Account Type', 'woocommerce' ); ?>
				</th>
				<td class="td">
					<div id="account-type"><?php echo esc_html( ucfirst( (string) ( $receipt['account_type'] ?? '' ) ) ); ?></div>
				</td>
			</tr>
		</tbody>
	</table>
</div>

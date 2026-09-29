/**
 * WooPayments test-mode notice for the WooCommerce order-edit screen.
 *
 * Client 11.1.0 order/test-mode-notice/index.tsx, mounted by order/index.js:141 for orders whose
 * `_wcpay_mode` meta is `test` (class-wc-payments-admin.php:881).
 */

/**
 * External dependencies
 */
import { ExternalLink, Notice } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const WooPaymentsOrderTestModeNotice = () => (
	<Notice status="warning" isDismissible={ false }>
		<span>
			{ createInterpolateElement(
				__(
					'WooPayments was in test mode when this order was placed. <learnMoreLink />',
					'woocommerce'
				),
				{
					learnMoreLink: (
						<ExternalLink href="https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/">
							{ __(
								'Learn more about test mode',
								'woocommerce'
							) }
						</ExternalLink>
					),
				}
			) }
		</span>
	</Notice>
);

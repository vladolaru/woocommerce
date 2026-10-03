/**
 * WooPayments test-mode notice for the WooCommerce order-edit screen.
 *
 * Client 11.1.0 order/test-mode-notice/index.tsx, mounted by order/index.js:141 for orders whose
 * `_wcpay_mode` meta is `test` (class-wc-payments-admin.php:881). Like the client's `InlineNotice`
 * with `icon`, the message follows the warning icon.
 */

/**
 * External dependencies
 */
import {
	ExternalLink,
	Flex,
	FlexItem,
	Icon,
	Notice,
} from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import NoticeOutlineIcon from 'gridicons/dist/notice-outline';

export const WooPaymentsOrderTestModeNotice = () => (
	<Notice status="warning" isDismissible={ false }>
		<Flex align="center" justify="flex-start">
			<FlexItem className="woocommerce-woopayments-order-notice__icon">
				<Icon icon={ <NoticeOutlineIcon /> } size={ 24 } />
			</FlexItem>
			<FlexItem>
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
			</FlexItem>
		</Flex>
	</Notice>
);

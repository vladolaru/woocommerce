/**
 * External dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const PROVIDER_NAME = 'WooPayments';
const WOOPAYMENTS_DOC_URL = 'https://woocommerce.com/document/woopayments/';
const BNPL_DOC_URL =
	'https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/';
const NOTIFICATIONS_DOC_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/#account-notifications';
const ADVANCED_SETTINGS_DOC_URL =
	'https://woocommerce.com/document/woopayments/settings-guide/#advanced-settings';

// Client 11.1.0 settings/settings-manager/index.js and
// settings/payment-methods-section/index.js: the loading and loaded states
// show the same section descriptions.
const GeneralSectionDescription = () => (
	<p>
		{ sprintf(
			/* translators: %s: Payment provider name. */
			__( 'Enable or disable %s on your store.', 'woocommerce' ),
			PROVIDER_NAME
		) }
	</p>
);

const PaymentMethodsSectionDescription = () => (
	<p>
		{ __(
			'Add and edit payments available to customers at checkout. Based on their device type, location, and purchase history, your customers will only see the most relevant payment methods.',
			'woocommerce'
		) }
	</p>
);

const ExpressCheckoutsSectionDescription = () => (
	<>
		<p>
			{ __(
				'Let your customers use their favorite express payment methods and digital wallets for faster, more secure checkouts across different parts of your store.',
				'woocommerce'
			) }
		</p>
		<ExternalLink href="https://woocommerce.com/document/woopayments/settings-guide/#express-checkouts">
			{ __( 'Learn more', 'woocommerce' ) }
		</ExternalLink>
	</>
);

const FraudProtectionSectionDescription = () => (
	<>
		<p>
			{ __(
				'Help avoid unauthorized transactions and disputes by setting your fraud protection level.',
				'woocommerce'
			) }
		</p>
		<ExternalLink href="https://woocommerce.com/document/woopayments/fraud-and-disputes/fraud-protection/">
			{ __( 'Learn more about fraud protection', 'woocommerce' ) }
		</ExternalLink>
	</>
);

const BuyNowPayLaterSectionDescription = () => (
	<>
		<p>
			{ __(
				'Boost sales by offering customers additional buying power and flexible payment options.',
				'woocommerce'
			) }
		</p>
		<ExternalLink href={ BNPL_DOC_URL }>
			{ __( 'Learn more', 'woocommerce' ) }
		</ExternalLink>
	</>
);

const TransactionsSectionDescription = () => (
	<>
		<p>
			{ __(
				"Update your store's configuration to ensure smooth transactions.",
				'woocommerce'
			) }
		</p>
		<ExternalLink href={ WOOPAYMENTS_DOC_URL }>
			{ __( 'View our documentation', 'woocommerce' ) }
		</ExternalLink>
	</>
);

const NotificationsSectionDescription = () => (
	<>
		<p>
			{ __(
				'Receive important notifications about your WooPayments account.',
				'woocommerce'
			) }
		</p>
		<ExternalLink href={ NOTIFICATIONS_DOC_URL }>
			{ __( 'Learn more', 'woocommerce' ) }
		</ExternalLink>
	</>
);

const AdvancedSettingsSectionDescription = () => (
	<>
		<p>
			{ __( 'More options for specific payment needs.', 'woocommerce' ) }
		</p>
		<ExternalLink href={ ADVANCED_SETTINGS_DOC_URL }>
			{ __( 'View our documentation', 'woocommerce' ) }
		</ExternalLink>
	</>
);

export {
	PROVIDER_NAME,
	WOOPAYMENTS_DOC_URL,
	GeneralSectionDescription,
	PaymentMethodsSectionDescription,
	ExpressCheckoutsSectionDescription,
	FraudProtectionSectionDescription,
	BuyNowPayLaterSectionDescription,
	TransactionsSectionDescription,
	NotificationsSectionDescription,
	AdvancedSettingsSectionDescription,
};

/**
 * External dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const PROVIDER_NAME = 'WooPayments';

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

export {
	PROVIDER_NAME,
	GeneralSectionDescription,
	PaymentMethodsSectionDescription,
	ExpressCheckoutsSectionDescription,
	FraudProtectionSectionDescription,
};

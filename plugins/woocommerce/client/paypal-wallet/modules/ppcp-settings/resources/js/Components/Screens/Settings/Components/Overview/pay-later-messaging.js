import { __, sprintf } from '@wordpress/i18n';

export const payLaterMessaging = {
	US: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. PayPal’s Pay Later helps boost merchants\' conversion rates and increases cart sizes by 39%%.¹ You get paid in full up front. <a target="_blank" href="%s">More about Pay Later</a>',
				'woocommerce'
			),
			'https://www.paypal.com/us/business/accept-payments/checkout/installments'
		),
		notes: [
			__( '¹PayPal Q2 Earnings-2021.', 'woocommerce' ),
		],
	},
	GB: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. Pay in 3 gets a 216%% higher Average Order Value than a standard PayPal transaction.¹ There’s <strong>no extra cost</strong> and you get paid up front. <a target="_blank" href="%s">More about Pay in 3</a>',
				'woocommerce'
			),
			'https://www.paypal.com/uk/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Based on PayPal internal data from Q1 2022, results include Pay in 3 (UK).',
				'woocommerce'
			),
		],
	},
	FR: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. Pay in 4x gets a 65%% higher Average Order Value than a standard PayPal transaction.¹ <strong>There\'s no extra cost on top of your PayPal Checkout rate</strong>, and you get paid up front. <a target="_blank" href="%s">More about Pay in 4x</a>',
				'woocommerce'
			),
			'https://www.paypal.com/fr/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Internal Data Analysis of 1124 SMB across integrated partners and non-integrated partners, November 2022. SMB internally defined as up to 100,000€ in annual estimated ecommerce online payment volume.',
				'woocommerce'
			),
		],
	},
	AU: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. Pay in 4 gets more than a 100%% higher Average Order Value than a standard PayPal transaction.¹ There’s <strong>no extra cost</strong> and you get paid up front. <a target="_blank" href="%s">More about Pay in 4</a>',
				'woocommerce'
			),
			'https://www.paypal.com/au/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Based on PayPal internal data from Q1 2022, results include Pay in 4 (AU). Consumer eligibility applies.',
				'woocommerce'
			),
		],
	},
	IT: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. Pay in 3 installments gets about a 275%% higher Average Order Value than a standard PayPal transaction.¹ <strong>There\'s no extra cost on top of your PayPal Checkout rate</strong>, and you get paid up front. <a target="_blank" href="%s">More about Pay in 3 installments</a>',
				'woocommerce'
			),
			'https://www.paypal.com/it/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Based on PayPal internal data from Q1 2022, results include Pay in 3 installments (IT).',
				'woocommerce'
			),
		],
	},
	ES: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. Pay in 3 installments gets about a 275%% higher Average Order Value than a standard PayPal transaction.¹ <strong>There\'s no extra cost on top of your PayPal Checkout rate</strong>, and you get paid up front. <a target="_blank" href="%s">More about Pay in 3 installments</a>',
				'woocommerce'
			),
			'https://www.paypal.com/es/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Based on PayPal internal data from Q1 2022, results include Pay in 3 installments (ES).',
				'woocommerce'
			),
		],
	},
	DE: {
		description: sprintf(
			__(
				'Your customers can already buy now and pay later with PayPal — add messaging to your site to let them know. When you offer your customers Pay Later options, 57%% will be more likely to buy from you again.¹ <strong>There\'s no extra cost</strong> and you get paid up front. <a target="_blank" href="%s">More about Pay Later</a>',
				'woocommerce'
			),
			'https://www.paypal.com/de/business/accept-payments/checkout/installments'
		),
		notes: [
			__(
				'Average order value in 2020 with PayPal installments compared to total PayPal sales.',
				'woocommerce'
			),
		],
	},
};

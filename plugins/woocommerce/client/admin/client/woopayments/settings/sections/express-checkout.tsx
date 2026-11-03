/**
 * External dependencies
 */
import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { createInterpolateElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	getSettingsPaymentsProviderRouteUrl,
	handleSettingsPaymentsProviderRouteClick,
} from '../../admin/utils';
import {
	DuplicatePaymentMethodNotice,
	PaymentMethodRowNotices,
	getPaymentMethodAvailability,
} from '../payment-methods-list';
import { SettingsSection } from '../settings-shell';
import { TextLink } from '../text-link';
import { AMAZON_PAY_DEFINITION } from '../amazon-pay-definition';
import {
	isAmazonPayExpressCheckoutAvailable,
	isWooPayAvailable,
} from '../express-checkout/settings-utils';
import { EXPRESS_CHECKOUT_METHOD_ICONS } from '../express-checkout/method-icons';
import {
	useAmazonPayEnabledSettings,
	useEnabledPaymentMethodIds,
	useGetAvailablePaymentMethodIds,
	useGetPaymentMethodStatuses,
	useGetSavedSettings,
	useGetSettings,
	useLinkEnabledSettings,
	usePaymentRequestEnabledSettings,
	useWooPayEnabledSettings,
	useWooPayShowIncompatibilityNotice,
} from '../data/hooks';
import { asSettingsRecord, asStringArray } from '../settings-values';
import { ExpressCheckoutsSectionDescription } from './section-descriptions';
import {
	type BooleanSetting,
	type StringArraySetting,
	type PaymentMethodStatus,
	useDuplicatePaymentMethodNotices,
} from './utils';

type CustomizableExpressCheckoutMethod =
	'woopay' | 'payment_request' | 'amazon_pay';
type ExpressCheckoutOverviewMethod = CustomizableExpressCheckoutMethod | 'link';
type ExpressCheckoutOverviewItem = {
	label: string;
	iconSrc: string;
	description: React.ReactNode;
};
type ExpressCheckoutOverviewRow = {
	id: ExpressCheckoutOverviewMethod;
	label: string;
	checked: boolean;
	disabled?: boolean;
	onChange: ( value: boolean ) => void;
	items: ExpressCheckoutOverviewItem[];
	notice?: React.ReactNode;
	noticeStatus?: 'info' | 'warning' | 'error';
	action?: React.ReactNode;
	duplicatePaymentMethodId?: string;
};

const createTermsLink = ( href: string ) => <TextLink href={ href } />;

const getCustomizePath = ( methodId: CustomizableExpressCheckoutMethod ) =>
	`/woopayments/settings/express-checkout/${ methodId }?from=woopayments-settings`;

const isCustomizableExpressCheckoutMethod = (
	methodId: ExpressCheckoutOverviewMethod
): methodId is CustomizableExpressCheckoutMethod => methodId !== 'link';

const getExpressCheckoutCheckboxId = (
	methodId: ExpressCheckoutOverviewMethod
) => `woopayments-express-checkout-${ methodId }-input`;

const ExpressCheckoutSettingsSection = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const isWooPayAvailableForStore = isWooPayAvailable( settings );
	const [ isPaymentRequestEnabled, setIsPaymentRequestEnabled ] =
		usePaymentRequestEnabledSettings() as BooleanSetting;
	const [ isWooPayEnabled, setIsWooPayEnabled ] =
		useWooPayEnabledSettings() as BooleanSetting;
	const [ isLinkEnabled, setIsLinkEnabled ] =
		useLinkEnabledSettings() as BooleanSetting;
	const [ enabledMethodIds ] =
		useEnabledPaymentMethodIds() as StringArraySetting;
	const [ isAmazonPayEnabled, setIsAmazonPayEnabled ] =
		useAmazonPayEnabledSettings() as BooleanSetting;
	// Owner decision N-280, a recorded improvement over client 11.1.0: each description follows the saved state,
	// so toggling a checkbox never moves the row; the terms text goes only after a successful save.
	const savedSettings = asSettingsRecord( useGetSavedSettings() );
	const savedEnabledMethodIds = asStringArray(
		savedSettings.enabled_payment_method_ids
	);
	const isWooPaySaved = Boolean( savedSettings.is_woopay_enabled );
	const isPaymentRequestSaved = Boolean(
		savedSettings.is_payment_request_enabled
	);
	const isLinkSaved = savedEnabledMethodIds.includes( 'link' );
	const isAmazonPaySaved = savedEnabledMethodIds.includes( 'amazon_pay' );
	const availablePaymentMethodIds = asStringArray(
		useGetAvailablePaymentMethodIds()
	);
	const statuses = asSettingsRecord( useGetPaymentMethodStatuses() );
	const {
		duplicatedPaymentMethodIds,
		dismissedDuplicatePaymentMethodNotices,
		onDismissDuplicateNotice,
	} = useDuplicatePaymentMethodNotices();
	const isLinkAvailable =
		enabledMethodIds.includes( 'card' ) &&
		availablePaymentMethodIds.includes( 'link' );
	const isAmazonPayAvailable =
		isAmazonPayExpressCheckoutAvailable( settings );
	const amazonPayAvailability = getPaymentMethodAvailability(
		AMAZON_PAY_DEFINITION,
		asSettingsRecord(
			statuses[ AMAZON_PAY_DEFINITION.stripeKey ]
		) as PaymentMethodStatus,
		false
	);
	const showWooPayIncompatibilityNotice = Boolean(
		useWooPayShowIncompatibilityNotice()
	);
	// Client 11.2.0 `settings/express-checkout/link-item.tsx:46, :61-62, :117-118` and `woopay-item.tsx:35-38, :60,
	// :135-136`: each checkbox only stops being turned on while the other method is on, so a store holding both can turn
	// either off, with a conflict notice on both rows meanwhile. Link counts only with card, as its row shows only then.
	const isWooPayOn = isWooPayAvailableForStore && isWooPayEnabled;
	const isStripeLinkOn = isLinkEnabled && enabledMethodIds.includes( 'card' );
	const isLinkWooPayConflict = isWooPayOn && isStripeLinkOn;
	const conflictNotice = __(
		"Link by Stripe and WooPay can't be enabled at the same time. Disable one of them.",
		'woocommerce'
	);
	let wooPayNotice = '';

	if ( isLinkWooPayConflict ) {
		wooPayNotice = conflictNotice;
	} else if ( isStripeLinkOn ) {
		wooPayNotice = __(
			'To enable WooPay, you must first disable Link by Stripe.',
			'woocommerce'
		);
	} else if ( showWooPayIncompatibilityNotice ) {
		wooPayNotice = __(
			'One or more of your extensions are incompatible with WooPay.',
			'woocommerce'
		);
	}
	let linkNotice = '';

	if ( isLinkWooPayConflict ) {
		linkNotice = conflictNotice;
	} else if ( isWooPayOn ) {
		linkNotice = __(
			'To enable Link by Stripe, you must first disable WooPay.',
			'woocommerce'
		);
	}

	const expressRows: ExpressCheckoutOverviewRow[] = [];

	// Rows, logos and copy follow client 11.1.0 client/settings/express-checkout/*-item.tsx.
	if ( isWooPayAvailableForStore ) {
		expressRows.push( {
			id: 'woopay',
			label: __( 'WooPay', 'woocommerce' ),
			checked: isWooPayEnabled,
			disabled: isStripeLinkOn && ! isWooPayEnabled,
			onChange: setIsWooPayEnabled,
			items: [
				{
					label: __( 'WooPay', 'woocommerce' ),
					iconSrc: EXPRESS_CHECKOUT_METHOD_ICONS.woopay[ 0 ].src,
					description: isWooPaySaved
						? __(
								'Boost conversion and customer loyalty by offering a single click, secure way to pay.',
								'woocommerce'
							)
						: createInterpolateElement(
								__(
									'Boost conversion and customer loyalty by offering a single click, secure way to pay. In order to use <wooPayLink>WooPay</wooPayLink>, you must agree to our <termsLink>WooCommerce Terms of Service</termsLink> and <privacyLink>Privacy Policy</privacyLink>. <trackingLink>Click here</trackingLink> to learn more about the data you will be sharing and opt-out options.',
									'woocommerce'
								),
								{
									wooPayLink: createTermsLink(
										'https://woocommerce.com/document/woopay-merchant-documentation/'
									),
									termsLink: createTermsLink(
										'https://wordpress.com/tos/'
									),
									privacyLink: createTermsLink(
										'https://automattic.com/privacy/'
									),
									trackingLink: createTermsLink(
										'https://woocommerce.com/usage-tracking/'
									),
								}
							),
				},
			],
			notice: wooPayNotice,
		} );
	}

	expressRows.push( {
		id: 'payment_request',
		label: __( 'Apple Pay / Google Pay', 'woocommerce' ),
		checked: isPaymentRequestEnabled,
		onChange: setIsPaymentRequestEnabled,
		items: [
			{
				label: __( 'Apple Pay', 'woocommerce' ),
				iconSrc: EXPRESS_CHECKOUT_METHOD_ICONS.payment_request[ 0 ].src,
				description: isPaymentRequestSaved
					? __(
							'Apple Pay is an easy and secure way for customers to pay on your store.',
							'woocommerce'
						)
					: createInterpolateElement(
							__(
								"Apple Pay is an easy and secure way for customers to pay on your store. By enabling this feature, you agree to <stripeLink>Stripe</stripeLink> and <appleLink>Apple</appleLink>'s terms of use.",
								'woocommerce'
							),
							{
								stripeLink: createTermsLink(
									'https://stripe.com/apple-pay/legal'
								),
								appleLink: createTermsLink(
									'https://developer.apple.com/apple-pay/acceptable-use-guidelines-for-websites/'
								),
							}
						),
			},
			{
				label: __( 'Google Pay', 'woocommerce' ),
				iconSrc: EXPRESS_CHECKOUT_METHOD_ICONS.payment_request[ 1 ].src,
				description: isPaymentRequestSaved
					? __(
							'Offer customers a fast, secure checkout experience with Google Pay.',
							'woocommerce'
						)
					: createInterpolateElement(
							__(
								"Offer customers a fast, secure checkout experience with Google Pay. By enabling this feature, you agree to <stripeLink>Stripe</stripeLink>, and <googleLink>Google</googleLink>'s terms of use.",
								'woocommerce'
							),
							{
								stripeLink: createTermsLink(
									'https://stripe.com/apple-pay/legal'
								),
								googleLink: createTermsLink(
									'https://androidpay.developers.google.com/terms/sellertos'
								),
							}
						),
			},
		],
		duplicatePaymentMethodId: 'apple_pay_google_pay',
	} );

	if ( isLinkAvailable ) {
		expressRows.push( {
			id: 'link',
			label: __( 'Link', 'woocommerce' ),
			checked: isLinkEnabled,
			disabled: isWooPayOn && ! isLinkEnabled,
			onChange: setIsLinkEnabled,
			items: [
				{
					label: __( 'Link', 'woocommerce' ),
					iconSrc: EXPRESS_CHECKOUT_METHOD_ICONS.link[ 0 ].src,
					description: isLinkSaved
						? __(
								"Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
								'woocommerce'
							)
						: createInterpolateElement(
								__(
									"Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience. By enabling this feature, you agree to the <termsLink>Link by Stripe terms</termsLink>, and <privacyLink>Privacy Policy</privacyLink>.",
									'woocommerce'
								),
								{
									termsLink: createTermsLink(
										'https://link.com/terms'
									),
									privacyLink: createTermsLink(
										'https://link.com/privacy'
									),
								}
							),
				},
			],
			notice: linkNotice,
			action: (
				<Button
					variant="secondary"
					href="https://woocommerce.com/document/woopayments/payment-methods/link-by-stripe/"
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'Read more', 'woocommerce' ) }
				</Button>
			),
		} );
	}

	if ( isAmazonPayAvailable ) {
		expressRows.push( {
			id: 'amazon_pay',
			label: __( 'Amazon Pay', 'woocommerce' ),
			checked: isAmazonPayEnabled,
			disabled: ! amazonPayAvailability.isActionable,
			onChange: setIsAmazonPayEnabled,
			items: [
				{
					label: __( 'Amazon Pay', 'woocommerce' ),
					iconSrc: EXPRESS_CHECKOUT_METHOD_ICONS.amazon_pay[ 0 ].src,
					// The short description is new copy: client 11.1.0 always shows the terms text.
					description: isAmazonPaySaved
						? __(
								'Offer customers a fast, secure checkout experience with Amazon Pay.',
								'woocommerce'
							)
						: createInterpolateElement(
								__(
									"Offer customers a fast, secure checkout experience with Amazon Pay. By activating this feature, you accept <stripeLink>Stripe</stripeLink> and <amazonLink>Amazon</amazonLink>'s terms of use.",
									'woocommerce'
								),
								{
									stripeLink: createTermsLink(
										'https://stripe.com/legal/ssa'
									),
									amazonLink: createTermsLink(
										'https://stripe.com/legal/amazon-pay'
									),
								}
							),
				},
			],
			notice: amazonPayAvailability.notice || '',
			noticeStatus: amazonPayAvailability.noticeStatus,
			// Owner-decided improvement: client 11.1.0's amazon-pay-item.tsx shows no duplicate notice.
			duplicatePaymentMethodId: 'amazon_pay',
		} );
	}

	return (
		<SettingsSection
			id="express-checkouts"
			title={ __( 'Express checkouts', 'woocommerce' ) }
			description={ <ExpressCheckoutsSectionDescription /> }
		>
			<ul className="woopayments-settings-payment-methods-list woopayments-settings-express-checkout-list">
				{ expressRows.map( ( row ) => (
					<li
						key={ row.id }
						className={
							row.items.length > 1
								? 'woopayments-settings-payment-method-item woopayments-settings-payment-method-item--grouped'
								: 'woopayments-settings-payment-method-item'
						}
					>
						<div className="woopayments-settings-payment-method-item__main">
							<CheckboxControl
								id={ getExpressCheckoutCheckboxId( row.id ) }
								checked={ row.checked }
								disabled={ row.disabled }
								label={ row.label }
								onChange={ ( value ) =>
									row.onChange( Boolean( value ) )
								}
								__nextHasNoMarginBottom
							/>
							{ row.items.map( ( item ) => (
								<Fragment key={ item.label }>
									<img
										className="woopayments-settings-payment-method-item__icon"
										src={ item.iconSrc }
										alt={ sprintf(
											/* translators: %s: Payment method label. */
											__( '%s logo', 'woocommerce' ),
											item.label
										) }
									/>
									<div className="woopayments-settings-payment-method-item__body">
										<div className="woopayments-settings-payment-method-item__heading">
											<span className="woopayments-settings-payment-method-item__label">
												{ item.label }
											</span>
										</div>
										<p>{ item.description }</p>
									</div>
								</Fragment>
							) ) }
							<div className="woopayments-settings-payment-method-item__actions">
								{ isCustomizableExpressCheckoutMethod(
									row.id
								) && (
									<Button
										variant="secondary"
										href={ getSettingsPaymentsProviderRouteUrl(
											getCustomizePath( row.id )
										) }
										onClick={ handleSettingsPaymentsProviderRouteClick(
											getCustomizePath( row.id )
										) }
										aria-label={ sprintf(
											/* translators: %s: Express checkout payment method name. */
											__( 'Customize %s', 'woocommerce' ),
											row.label
										) }
									>
										{ __( 'Customize', 'woocommerce' ) }
									</Button>
								) }
								{ row.action }
							</div>
						</div>
						<PaymentMethodRowNotices>
							{ row.notice && (
								<Notice
									status={ row.noticeStatus || 'warning' }
									isDismissible={ false }
									className="woopayments-settings-payment-method-item__notice"
								>
									{ row.notice }
								</Notice>
							) }
							{ row.duplicatePaymentMethodId &&
								( duplicatedPaymentMethodIds[
									row.duplicatePaymentMethodId
								]?.length ?? 0 ) > 0 && (
									<DuplicatePaymentMethodNotice
										paymentMethodId={
											row.duplicatePaymentMethodId
										}
										gatewayIds={
											duplicatedPaymentMethodIds[
												row.duplicatePaymentMethodId
											] || []
										}
										dismissedNotices={
											dismissedDuplicatePaymentMethodNotices
										}
										onDismiss={ onDismissDuplicateNotice }
										onRestoreFocus={ () => {
											document
												.getElementById(
													getExpressCheckoutCheckboxId(
														row.id
													)
												)
												?.focus();
										} }
									/>
								) }
						</PaymentMethodRowNotices>
					</li>
				) ) }
			</ul>
		</SettingsSection>
	);
};

export { ExpressCheckoutSettingsSection };

/**
 * External dependencies
 */
import { Notice, Spinner } from '@wordpress/components';
import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { AccountModeNotice } from '../account-mode-notice';
import { ApplePayDomainErrorNotice } from '../apple-pay-domain-error-notice';
import { SaveSettingsSection } from '../save-settings-section';
import {
	SettingsBusyState,
	useDeferredStatusText,
} from '../settings-busy-state';
import { SettingsSubpage } from '../settings-subpage';
import {
	asSettingsRecord,
	isAmazonPayExpressCheckoutAvailable,
	isWooPayAvailable,
} from './settings-utils';
import { useDevMode, useGetSettings, useSettings } from '../data/hooks';
import './style.scss';

type ExpressCheckoutMethodId = 'woopay' | 'payment_request' | 'amazon_pay';

const METHOD_TITLES: Record< ExpressCheckoutMethodId, string > = {
	woopay: 'WooPay',
	payment_request: 'Apple Pay / Google Pay',
	amazon_pay: 'Amazon Pay',
};

const METHOD_COMPONENTS = {
	woopay: lazy( () =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-express-checkout-woopay" */ './woopay-settings'
		).then( ( module ) => ( { default: module.WooPaySettings } ) )
	),
	payment_request: lazy( () =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-express-checkout-payment-request" */ './payment-request-settings'
		).then( ( module ) => ( { default: module.PaymentRequestSettings } ) )
	),
	amazon_pay: lazy( () =>
		import(
			/* webpackChunkName: "settings-payments-woopayments-express-checkout-amazon-pay" */ './amazon-pay-settings'
		).then( ( module ) => ( { default: module.AmazonPaySettings } ) )
	),
};

const isExpressCheckoutMethodId = (
	methodId: string
): methodId is ExpressCheckoutMethodId =>
	[ 'woopay', 'payment_request', 'amazon_pay' ].includes( methodId );

const isExpressCheckoutMethodAvailable = (
	methodId: ExpressCheckoutMethodId,
	settings: Record< string, unknown >
) => {
	if ( methodId === 'woopay' ) {
		return isWooPayAvailable( settings );
	}

	if ( methodId === 'amazon_pay' ) {
		return isAmazonPayExpressCheckoutAvailable( settings );
	}

	return true;
};

const getExpressCheckoutMethodUnavailableMessage = (
	methodId: ExpressCheckoutMethodId
) => {
	if ( methodId === 'woopay' ) {
		return __( 'WooPay is not available for this store.', 'woocommerce' );
	}

	return __( 'Amazon Pay is not available for this store.', 'woocommerce' );
};

/**
 * The loading message in a status region that is on the page before its text, for the settings read and for
 * the method's code chunk.
 *
 * @param props           The component props.
 * @param props.isLoading Whether to show the message.
 */
const LoadingStatus = ( { isLoading }: { isLoading: boolean } ) => {
	const loadingStatus = useDeferredStatusText(
		isLoading ? __( 'Loading WooPayments settings…', 'woocommerce' ) : ''
	);

	return (
		<p
			className={
				loadingStatus
					? 'woopayments-express-checkout-settings__loading'
					: 'screen-reader-text'
			}
			role="status"
			aria-live="polite"
		>
			{ loadingStatus && (
				<>
					<Spinner />
					{ loadingStatus }
				</>
			) }
		</p>
	);
};

export const WooPaymentsExpressCheckoutSettings = ( {
	methodId,
}: {
	methodId: string;
} ) => {
	const { isLoading, isSaving } = useSettings();
	const settings = asSettingsRecord( useGetSettings() );
	const hasSettings = Object.keys( settings ).length > 0;
	const isDevModeEnabled = Boolean( useDevMode() );

	if ( ! isExpressCheckoutMethodId( methodId ) ) {
		return (
			<p>
				{ __(
					'Invalid express checkout method ID specified.',
					'woocommerce'
				) }
			</p>
		);
	}

	const MethodSettings = METHOD_COMPONENTS[ methodId ];
	const title = METHOD_TITLES[ methodId ];
	const headingId = `woopayments-express-checkout-settings-${ methodId }`;
	let content;

	if ( isLoading ) {
		content = null;
	} else if ( ! hasSettings ) {
		content = (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Unable to load WooPayments settings.', 'woocommerce' ) }
			</Notice>
		);
	} else if ( ! isExpressCheckoutMethodAvailable( methodId, settings ) ) {
		content = (
			<Notice status="warning" isDismissible={ false }>
				{ getExpressCheckoutMethodUnavailableMessage( methodId ) }
			</Notice>
		);
	} else {
		content = (
			<SettingsBusyState isBusy={ Boolean( isSaving ) }>
				<Suspense fallback={ <LoadingStatus isLoading /> }>
					<MethodSettings />
					<SaveSettingsSection />
				</Suspense>
			</SettingsBusyState>
		);
	}

	return (
		<SettingsSubpage
			headingId={ headingId }
			title={ title }
			backPath="/woopayments/settings?from=woopayments-settings"
			from="woopayments_express_checkout_settings"
			className="woopayments-express-checkout-settings"
		>
			<ApplePayDomainErrorNotice />
			{ ! isLoading && (
				<AccountModeNotice isDevModeEnabled={ isDevModeEnabled } />
			) }
			<LoadingStatus isLoading={ isLoading } />
			{ content }
		</SettingsSubpage>
	);
};

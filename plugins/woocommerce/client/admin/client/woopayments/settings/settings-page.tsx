/**
 * External dependencies
 */
import { ExternalLink } from '@wordpress/components';
import {
	lazy,
	Suspense,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { WOOPAYMENTS_SETTINGS_HEADING_ID } from '~/settings-payments/constants';
import { registerPaymentsRuntimeTracksPropertyWhenPreloaded } from '../tracks-runtime';
import { AccountModeNotice } from './account-mode-notice';
import { ApplePayDomainErrorNotice } from './apple-pay-domain-error-notice';
import { getWooPaymentsSettingsBootstrap } from './bootstrap';
import { FraudProtectionSettings } from './fraud-protection';
import { PaymentMethodsSettingsSection } from './sections/payment-methods';
import {
	BNPL_DOC_URL,
	BuyNowPayLaterSettingsSection,
} from './sections/buy-now-pay-later';
import { ExpressCheckoutSettingsSection } from './sections/express-checkout';
import { SettingsBusyState } from './settings-busy-state';
import {
	WOOPAYMENTS_DOC_URL,
	TransactionsSettingsSection,
} from './sections/transactions';
import {
	NOTIFICATIONS_DOC_URL,
	NotificationsSettingsSection,
} from './sections/notifications';
import { SaveSettingsSection } from './save-settings-section';
import { GeneralSettingsSection } from './sections/general';
import {
	PAYOUT_SCHEDULE_DOC_URL,
	PayoutsSettingsSection,
} from './sections/payouts';
import {
	ADVANCED_SETTINGS_DOC_URL,
	AdvancedSettingsSection,
} from './sections/advanced';
import { SettingsSection, type FieldValidationError } from './settings-shell';
import { SpotlightPromotion } from '../promotions/spotlight';
import { useDevMode, useGetSettings, useSettings } from './data/hooks';
import { store as settingsStore } from './data/store';
import { registerStoreUnlessTaken } from '../data/register-store';
import { getWooPaymentsDocumentsAccount } from '../admin/documents/data';
import type { WooPaymentsVatDetails } from '../admin/documents/types';
import { asSettingsRecord } from './settings-values';
import {
	GeneralSectionDescription,
	PaymentMethodsSectionDescription,
	ExpressCheckoutsSectionDescription,
	FraudProtectionSectionDescription,
} from './sections/section-descriptions';
import './settings-page-only.scss';
import './style.scss';

// The settings embed can render this page outside the native routes.
registerPaymentsRuntimeTracksPropertyWhenPreloaded();

const VAT_DETAILS_MODAL_QUERY_PARAM = 'woopayments-vat-details-modal';
const WooPaymentsVatModal = lazy( () =>
	import(
		/* webpackChunkName: "settings-payments-woopayments-vat-modal" */ '../admin/documents/vat-modal'
	).then( ( module ) => ( { default: module.WooPaymentsVatModal } ) )
);

type VatDetailsModalState = {
	isOpen: boolean;
	country: string;
	hasSubmittedVatData: boolean;
};

const isVatDetailsModalDeepLinkActive = () => {
	if ( typeof window === 'undefined' ) {
		return false;
	}

	return (
		new URLSearchParams( window.location.search ).get(
			VAT_DETAILS_MODAL_QUERY_PARAM
		) === 'true'
	);
};

const removeVatDetailsModalQueryParam = () => {
	if ( typeof window === 'undefined' ) {
		return;
	}

	const url = new URL( window.location.href );
	url.searchParams.delete( VAT_DETAILS_MODAL_QUERY_PARAM );
	window.history.replaceState(
		window.history.state,
		'',
		`${ url.pathname }${ url.search }${ url.hash }`
	);
};

const SettingsSectionLoadingPlaceholder = ( { lines }: { lines: number } ) => (
	<div
		className="woopayments-settings-loadable-placeholder-group"
		aria-hidden="true"
	>
		{ Array.from( { length: lines }, ( _value, index ) => (
			<span
				key={ index }
				className="woopayments-settings-loadable-placeholder"
				aria-hidden="true"
			/>
		) ) }
	</div>
);

const SettingsLoadingSections = () => {
	const sections = [
		{
			id: 'general',
			title: __( 'General', 'woocommerce' ),
			lines: 5,
			description: <GeneralSectionDescription />,
		},
		{
			id: 'payment-methods',
			title: __( 'Payments accepted on checkout', 'woocommerce' ),
			lines: 8,
			description: <PaymentMethodsSectionDescription />,
		},
		{
			id: 'buy-now-pay-later-methods',
			title: __( 'Buy now, pay later', 'woocommerce' ),
			lines: 7,
			description: (
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
			),
		},
		{
			id: 'express-checkouts',
			title: __( 'Express checkouts', 'woocommerce' ),
			lines: 8,
			description: <ExpressCheckoutsSectionDescription />,
		},
		{
			id: 'transactions',
			title: __( 'Transactions', 'woocommerce' ),
			lines: 8,
			description: (
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
			),
		},
		{
			id: 'payouts',
			title: __( 'Payouts', 'woocommerce' ),
			lines: 6,
			description: (
				<ExternalLink href={ PAYOUT_SCHEDULE_DOC_URL }>
					{ __( 'Learn more about payout schedules', 'woocommerce' ) }
				</ExternalLink>
			),
		},
		{
			id: 'notifications',
			title: __( 'Account notifications', 'woocommerce' ),
			lines: 4,
			description: (
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
			),
		},
		{
			id: 'fraud-protection',
			title: __( 'Fraud protection', 'woocommerce' ),
			lines: 5,
			description: <FraudProtectionSectionDescription />,
		},
		{
			id: 'advanced',
			title: __( 'Advanced settings', 'woocommerce' ),
			lines: 5,
			description: (
				<>
					<p>
						{ __(
							'More options for specific payment needs.',
							'woocommerce'
						) }
					</p>
					<ExternalLink href={ ADVANCED_SETTINGS_DOC_URL }>
						{ __( 'View our documentation', 'woocommerce' ) }
					</ExternalLink>
				</>
			),
		},
	];

	return (
		<div className="woopayments-settings-loading-state">
			<div
				className="woopayments-settings-loading-state__status screen-reader-text"
				role="status"
				aria-live="polite"
			>
				{ __( 'WooPayments settings are loading.', 'woocommerce' ) }
			</div>
			<div
				className="woopayments-settings-loading-state__content"
				aria-busy="true"
			>
				{ sections.map( ( section ) => (
					<SettingsSection
						key={ section.id }
						id={ section.id }
						title={ section.title }
						description={ section.description }
					>
						<SettingsSectionLoadingPlaceholder
							lines={ section.lines }
						/>
					</SettingsSection>
				) ) }
			</div>
		</div>
	);
};

const FraudProtectionSettingsSection = () => {
	return (
		<SettingsSection
			id="fraud-protection"
			title={ __( 'Fraud protection', 'woocommerce' ) }
			description={ <FraudProtectionSectionDescription /> }
		>
			<FraudProtectionSettings />
		</SettingsSection>
	);
};

export const WooPaymentsSettingsPage = () => {
	registerStoreUnlessTaken( settingsStore );
	getWooPaymentsSettingsBootstrap();

	const { isLoading, isSaving } = useSettings();
	const [ transactionsValidationError, setTransactionsValidationError ] =
		useState< FieldValidationError | null >( null );
	const [ notificationsValidationError, setNotificationsValidationError ] =
		useState< FieldValidationError | null >( null );
	const settings = asSettingsRecord( useGetSettings() );
	const [ vatDetailsModalState, setVatDetailsModalState ] =
		useState< VatDetailsModalState >( {
			isOpen: false,
			country: '',
			hasSubmittedVatData: false,
		} );
	const hasHandledVatDetailsDeepLink = useRef( false );
	const hasSettings = Object.keys( settings ).length > 0;
	const isDevModeEnabled = Boolean( useDevMode() );
	const isShowingSettings = ! ( isLoading && ! hasSettings );

	// Client 11.1.0 settings/settings-manager/index.js:174-198: once settings have loaded, scroll to the
	// section named by the `anchor` query argument or the URL hash, clear of the admin header.
	useLayoutEffect( () => {
		const scrollTarget =
			new URLSearchParams( window.location.search ).get( 'anchor' ) ||
			window.location.hash;

		if ( ! isShowingSettings || ! scrollTarget ) {
			return;
		}

		let element: Element | null = null;
		try {
			element = document.querySelector( scrollTarget );
		} catch {
			return;
		}

		if ( ! element ) {
			return;
		}

		const headerElement = document.querySelector(
			'.woocommerce-layout__header'
		);
		const headerOffset =
			( headerElement ? headerElement.clientHeight : 60 ) + 50;

		window.scrollTo( {
			top:
				element.getBoundingClientRect().top +
				window.pageYOffset -
				headerOffset,
			behavior: 'smooth',
		} );
	}, [ isShowingSettings ] );

	useEffect( () => {
		if (
			hasHandledVatDetailsDeepLink.current ||
			! isVatDetailsModalDeepLinkActive()
		) {
			return;
		}

		hasHandledVatDetailsDeepLink.current = true;
		let isMounted = true;

		getWooPaymentsDocumentsAccount()
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				const documents = response.documents;
				if ( ! documents?.enabled ) {
					dispatch( 'core/notices' ).createErrorNotice(
						__(
							'Tax details collection is not available for your account.',
							'woocommerce'
						)
					);
					return;
				}

				if ( documents.has_submitted_vat_data ) {
					dispatch( 'core/notices' ).createInfoNotice(
						__(
							'Tax details are already submitted.',
							'woocommerce'
						)
					);
					return;
				}

				setVatDetailsModalState( {
					isOpen: true,
					country: documents.country || '',
					hasSubmittedVatData: false,
				} );
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				dispatch( 'core/notices' ).createErrorNotice(
					__(
						'Tax details collection is not available for your account.',
						'woocommerce'
					)
				);
			} );

		return () => {
			isMounted = false;
		};
	}, [] );

	const closeVatDetailsModal = () => {
		setVatDetailsModalState( ( current ) => ( {
			...current,
			isOpen: false,
		} ) );
		removeVatDetailsModalQueryParam();
	};

	const completeVatDetailsModal = ( details: WooPaymentsVatDetails ) => {
		setVatDetailsModalState( ( current ) => ( {
			...current,
			isOpen: false,
			hasSubmittedVatData: true,
		} ) );
		dispatch( 'core/notices' ).createInfoNotice(
			__( 'Tax details updated', 'woocommerce' )
		);
		removeVatDetailsModalQueryParam();

		return details;
	};

	return (
		<section
			className="woopayments-settings-page"
			aria-labelledby={ WOOPAYMENTS_SETTINGS_HEADING_ID }
		>
			<ApplePayDomainErrorNotice />
			<AccountModeNotice isDevModeEnabled={ isDevModeEnabled } />

			{ ! isShowingSettings ? (
				<SettingsLoadingSections />
			) : (
				<SettingsBusyState isBusy={ isSaving }>
					<SpotlightPromotion />
					<GeneralSettingsSection />
					<PaymentMethodsSettingsSection />
					<BuyNowPayLaterSettingsSection />
					<ExpressCheckoutSettingsSection />
					<TransactionsSettingsSection
						onValidationChange={ setTransactionsValidationError }
					/>
					<PayoutsSettingsSection />
					<NotificationsSettingsSection
						onValidationChange={ setNotificationsValidationError }
					/>
					<FraudProtectionSettingsSection />
					<AdvancedSettingsSection />
					<SaveSettingsSection
						disabled={ isSaving }
						validationError={
							transactionsValidationError ??
							notificationsValidationError
						}
					/>
					{ vatDetailsModalState.isOpen &&
						! vatDetailsModalState.hasSubmittedVatData && (
							<Suspense fallback={ null }>
								<WooPaymentsVatModal
									country={ vatDetailsModalState.country }
									onClose={ closeVatDetailsModal }
									onCompleted={ completeVatDetailsModal }
								/>
							</Suspense>
						) }
				</SettingsBusyState>
			) }
		</section>
	);
};

export default WooPaymentsSettingsPage;

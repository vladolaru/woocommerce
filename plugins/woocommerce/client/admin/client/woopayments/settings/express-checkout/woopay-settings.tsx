/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	CheckboxControl,
	ExternalLink,
	FormFileUpload,
	Notice,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { image } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';
import type { ChangeEvent } from 'react';

/**
 * Internal dependencies
 */
import { ExpressCheckoutAppearanceSettings } from './appearance-settings';
import {
	ExpressCheckoutInlineNotice,
	ExpressCheckoutLocationCheckboxes,
} from './components';
import { SettingsSection } from '../settings-shell';
import { ExpressCheckoutMethodIcons } from './method-icons';
import { TextLink } from '../text-link';
import {
	asSettingsRecord,
	asString,
	getExpressCheckoutFeatureFlags,
} from './settings-utils';
import {
	useEnabledPaymentMethodIds,
	useGetSavedSettings,
	useGetSettings,
	useWooPayCustomMessage,
	useWooPayEnabledSettings,
	useWooPayGlobalThemeSupportEnabledSettings,
	useWooPayLocations,
	useWooPayShowIncompatibilityNotice,
	useWooPayStoreLogo,
} from '../data/hooks';
import { WooPayPreview } from './woopay-preview';
import { StatusChip } from '../../admin/overview/components/status-chip';

const MAX_LOGO_FILE_SIZE = 510000;
const WOOPAY_MERCHANT_DOCS_URL =
	'https://woocommerce.com/document/woopay-merchant-documentation/';
const WOOPAY_CHECKOUT_APPEARANCE_DOCS_URL =
	'https://woocommerce.com/document/woopay-merchant-documentation/#checkout-appearance';

type WindowWithWooSettings = typeof window & {
	wcSettings?: {
		storePages?: {
			privacy?: { permalink?: string };
			terms?: { permalink?: string };
		};
	};
};

const getStorePagePermalink = ( page: 'privacy' | 'terms' ) => {
	const storePages =
		typeof window !== 'undefined'
			? ( window as WindowWithWooSettings ).wcSettings?.storePages
			: undefined;
	const permalink = storePages?.[ page ]?.permalink;

	return typeof permalink === 'string' && permalink ? permalink : undefined;
};

const WooPayLogoUpload = ( {
	logoId,
	setLogoId,
}: {
	logoId: string;
	setLogoId: ( value: string ) => void;
} ) => {
	const [ isUploading, setIsUploading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ uploadedFileName, setUploadedFileName ] = useState( '' );
	const [ uploadStatusMessage, setUploadStatusMessage ] = useState( '' );

	const handleUpload = async ( event: ChangeEvent< HTMLInputElement > ) => {
		const file = event.target.files?.[ 0 ];

		if ( ! file ) {
			return;
		}

		if ( file.size > MAX_LOGO_FILE_SIZE ) {
			const message = __(
				'The selected logo exceeds the maximum file size.',
				'woocommerce'
			);

			setError( message );
			event.target.value = '';
			return;
		}

		const body = new FormData();
		body.append( 'file', file );
		body.append( 'purpose', 'business_logo' );

		setIsUploading( true );
		setError( null );
		setUploadStatusMessage( '' );
		// Client 11.1.0 `settings/express-checkout-settings/file-upload.tsx:73-102`.
		const tracksProperties = { type: 'woopay-store-logo' };
		recordEvent(
			'wcpay_merchant_settings_file_upload_started',
			tracksProperties
		);

		try {
			const uploadedFile = ( await apiFetch( {
				path: '/wc/v3/payments/file',
				method: 'post',
				body,
			} ) ) as { id?: string };

			setLogoId( uploadedFile.id || '' );
			recordEvent(
				'wcpay_merchant_settings_file_upload_success',
				tracksProperties
			);
			setUploadedFileName( file.name );
			setUploadStatusMessage(
				sprintf(
					/* translators: %s: Uploaded file name. */
					__( 'Logo uploaded: %s', 'woocommerce' ),
					file.name
				)
			);
		} catch ( uploadError ) {
			const message =
				uploadError &&
				typeof uploadError === 'object' &&
				'message' in uploadError &&
				typeof uploadError.message === 'string' &&
				uploadError.message
					? uploadError.message
					: __( 'Error uploading logo.', 'woocommerce' );

			recordEvent( 'wcpay_merchant_settings_upload_failed', { message } );
			setError( message );
			setLogoId( '' );
			setUploadedFileName( '' );
			setUploadStatusMessage( '' );
		} finally {
			setIsUploading( false );
			event.target.value = '';
		}
	};

	return (
		<BaseControl
			id="woopayments-express-checkout-woopay-logo"
			help={ __(
				'Upload a custom logo. Upload a horizontal image with a white or transparent background for best results. Use a PNG or JPG image format. Recommended width: 512 pixels minimum.',
				'woocommerce'
			) }
			__nextHasNoMarginBottom
		>
			<BaseControl.VisualLabel>
				{ __( 'Checkout logo', 'woocommerce' ) }
			</BaseControl.VisualLabel>
			<div className="woopayments-express-checkout-settings__logo-upload">
				<FormFileUpload
					accept="image/png,image/jpeg"
					onChange={ handleUpload }
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							icon={ image }
							disabled={ isUploading }
							aria-describedby="woopayments-express-checkout-woopay-logo__help"
							onClick={ openFileDialog }
							__next40pxDefaultSize
						>
							{ __( 'Upload custom logo', 'woocommerce' ) }
						</Button>
					) }
				/>
				{ isUploading && (
					<p
						aria-live="polite"
						className="woopayments-express-checkout-settings__inline-status"
					>
						<Spinner />
						{ __( 'Uploading logo…', 'woocommerce' ) }
					</p>
				) }
				{ logoId && ! isUploading && (
					<p
						aria-live="polite"
						className="woopayments-express-checkout-settings__inline-status"
					>
						{ sprintf(
							/* translators: 1: Uploaded file name, 2: Uploaded file ID. */
							__(
								'Current logo file: %1$s (%2$s)',
								'woocommerce'
							),
							uploadedFileName ||
								__( 'Uploaded file', 'woocommerce' ),
							logoId
						) }
						<Button
							variant="link"
							onClick={ () => {
								setLogoId( '' );
								setUploadedFileName( '' );
								setUploadStatusMessage( '' );
							} }
						>
							{ __( 'Remove', 'woocommerce' ) }
						</Button>
					</p>
				) }
				{ uploadStatusMessage && ! isUploading && (
					<span className="screen-reader-text" aria-live="polite">
						{ uploadStatusMessage }
					</span>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
			</div>
		</BaseControl>
	);
};

export const WooPaySettings = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const featureFlags = getExpressCheckoutFeatureFlags( settings );
	const [ enabledMethodIds ] = useEnabledPaymentMethodIds() as [ string[] ];
	const [ isWooPayEnabled, setIsWooPayEnabled ] =
		useWooPayEnabledSettings() as [ boolean, ( value: boolean ) => void ];
	const isWooPaySaved = Boolean(
		asSettingsRecord( useGetSavedSettings() ).is_woopay_enabled
	);
	const [ wooPayLocations, updateWooPayLocation ] = useWooPayLocations() as [
		string[],
		( location: 'product' | 'cart' | 'checkout', value: boolean ) => void,
	];
	const [ isWooPayGlobalThemeSupportEnabled, setWooPayGlobalThemeSupport ] =
		useWooPayGlobalThemeSupportEnabledSettings() as [
			boolean,
			( value: boolean ) => void,
		];
	const [ wooPayCustomMessage, setWooPayCustomMessage ] =
		useWooPayCustomMessage() as [ string, ( value: string ) => void ];
	const [ wooPayStoreLogo, setWooPayStoreLogo ] = useWooPayStoreLogo() as [
		string,
		( value: string ) => void,
	];
	const showIncompatibilityNotice = Boolean(
		useWooPayShowIncompatibilityNotice()
	);
	const isStripeLinkEnabled = enabledMethodIds.includes( 'link' );
	const isGlobalThemeSupportEligible = Boolean(
		settings.is_woopay_global_theme_support_eligible
	);
	const previewAppearance = isWooPayGlobalThemeSupportEnabled
		? settings.woopay_appearance
		: undefined;
	const previewFontRules = isWooPayGlobalThemeSupportEnabled
		? settings.woopay_font_rules
		: undefined;
	const privacyPolicyPermalink = getStorePagePermalink( 'privacy' );
	const termsOfServicePermalink = getStorePagePermalink( 'terms' );
	// Client 11.1.0 woopay-settings.js:291-296 links these with a plain same-tab anchor.
	const privacyPolicyHelp = privacyPolicyPermalink ? (
		<a href={ privacyPolicyPermalink }>
			{ __( 'privacy policy', 'woocommerce' ) }
		</a>
	) : (
		__( 'privacy policy', 'woocommerce' )
	);
	const termsOfServiceHelp = termsOfServicePermalink ? (
		<a href={ termsOfServicePermalink }>
			{ __( 'terms of service', 'woocommerce' ) }
		</a>
	) : (
		__( 'terms of service', 'woocommerce' )
	);

	return (
		<>
			<SettingsSection
				className="woopayments-express-checkout-settings__enable"
				title={ __( 'WooPay', 'woocommerce' ) }
				hideTitle
				description={
					<>
						<ExpressCheckoutMethodIcons methodId="woopay" />
						<p>
							{ __(
								'Allow your customers to collect payments via WooPay.',
								'woocommerce'
							) }
						</p>
					</>
				}
			>
				{ showIncompatibilityNotice && ! isStripeLinkEnabled && (
					<ExpressCheckoutInlineNotice>
						{ __(
							'One or more of your extensions are incompatible with WooPay.',
							'woocommerce'
						) }
					</ExpressCheckoutInlineNotice>
				) }
				{ isStripeLinkEnabled && (
					<ExpressCheckoutInlineNotice>
						{ __(
							'To enable WooPay, you must first disable Link by Stripe.',
							'woocommerce'
						) }
					</ExpressCheckoutInlineNotice>
				) }
				<CheckboxControl
					checked={ isWooPayEnabled }
					disabled={ isStripeLinkEnabled }
					label={ __( 'Enable WooPay', 'woocommerce' ) }
					// Owner decision N-280: the terms text follows the saved state, not the unsaved toggle.
					help={
						isWooPaySaved ? (
							__(
								'When enabled, customers will be able to checkout using WooPay.',
								'woocommerce'
							)
						) : (
							<>
								{ __(
									'When enabled, customers will be able to checkout using WooPay. In order to use ',
									'woocommerce'
								) }
								<TextLink href={ WOOPAY_MERCHANT_DOCS_URL }>
									{ __( 'WooPay', 'woocommerce' ) }
								</TextLink>
								{ __(
									', you must agree to our ',
									'woocommerce'
								) }
								<TextLink href="https://wordpress.com/tos/">
									{ __(
										'WooCommerce Terms of Service',
										'woocommerce'
									) }
								</TextLink>
								{ __( ' and ', 'woocommerce' ) }
								<TextLink href="https://automattic.com/privacy/">
									{ __( 'Privacy Policy', 'woocommerce' ) }
								</TextLink>
								{ __( '. ', 'woocommerce' ) }
								<TextLink href="https://woocommerce.com/usage-tracking/">
									{ __( 'Click here', 'woocommerce' ) }
								</TextLink>
								{ __(
									' to learn more about the data you will be sharing and opt-out options.',
									'woocommerce'
								) }
							</>
						)
					}
					onChange={ ( value ) =>
						setIsWooPayEnabled( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
				<ExpressCheckoutLocationCheckboxes
					enabledLocations={ wooPayLocations }
					isMethodEnabled={ isWooPayEnabled }
					onChange={ updateWooPayLocation }
				/>
			</SettingsSection>
			<SettingsSection
				className="woopayments-express-checkout-settings__appearance-card"
				title={ __( 'Checkout appearance', 'woocommerce' ) }
			>
				<WooPayLogoUpload
					logoId={ wooPayStoreLogo }
					setLogoId={ setWooPayStoreLogo }
				/>
				{ isGlobalThemeSupportEligible && (
					<div className="woopayments-express-checkout-settings__global-theme-label">
						<CheckboxControl
							checked={ isWooPayGlobalThemeSupportEnabled }
							disabled={ ! isWooPayEnabled }
							label={ __(
								'Enable global theme support',
								'woocommerce'
							) }
							help={
								<>
									{ __(
										"When enabled, WooPay checkout will be themed with your store's brand colors and fonts. ",
										'woocommerce'
									) }
									<TextLink
										href={
											WOOPAY_CHECKOUT_APPEARANCE_DOCS_URL
										}
									>
										{ __( 'Learn more', 'woocommerce' ) }
									</TextLink>
								</>
							}
							onChange={ ( value ) =>
								setWooPayGlobalThemeSupport( Boolean( value ) )
							}
							__nextHasNoMarginBottom
						/>
						<StatusChip
							message={ __( 'Beta', 'woocommerce' ) }
							type="info"
						/>
					</div>
				) }
				<TextareaControl
					label={ __( 'Checkout policies', 'woocommerce' ) }
					help={
						<>
							{ __( 'Override the default ', 'woocommerce' ) }
							{ privacyPolicyHelp }
							{ __( ' and ', 'woocommerce' ) }
							{ termsOfServiceHelp }
							{ __(
								', or add custom text to WooPay checkout. ',
								'woocommerce'
							) }
							<ExternalLink
								href={ WOOPAY_CHECKOUT_APPEARANCE_DOCS_URL }
							>
								{ __( 'Learn more', 'woocommerce' ) }
							</ExternalLink>
							{ __( '.', 'woocommerce' ) }
						</>
					}
					value={ wooPayCustomMessage }
					onChange={ setWooPayCustomMessage }
					__nextHasNoMarginBottom
				/>
				<BaseControl
					id="woopayments-express-checkout-woopay-preview"
					label={ __( 'Preview of checkout', 'woocommerce' ) }
					__nextHasNoMarginBottom
				>
					<div
						className="woopayments-express-checkout-settings__woopay-preview"
						role="region"
						aria-label={ __(
							'Preview of checkout',
							'woocommerce'
						) }
					>
						<WooPayPreview
							appearance={ previewAppearance }
							customMessage={ wooPayCustomMessage }
							fontRules={ previewFontRules }
							siteLogoUrl={ asString( settings.site_logo_url ) }
							storeName={ asString(
								settings.store_name,
								__( 'Store', 'woocommerce' )
							) }
							storeLogo={ wooPayStoreLogo }
						/>
					</div>
				</BaseControl>
			</SettingsSection>
			{ featureFlags.woopayExpressCheckout && (
				<SettingsSection
					className="woopayments-express-checkout-settings__general"
					title={ __( 'Settings', 'woocommerce' ) }
					description={
						<>
							<p>
								{ __(
									'Configure the display of WooPay buttons on your store.',
									'woocommerce'
								) }
							</p>
						</>
					}
				>
					<ExpressCheckoutAppearanceSettings currentMethod="woopay" />
				</SettingsSection>
			) }
		</>
	);
};

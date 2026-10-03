/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	CardBody,
	CheckboxControl,
	ExternalLink,
	Icon,
	Modal,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { info } from '@wordpress/icons';
import { getSetting } from '@woocommerce/settings';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { SettingsSection } from './settings-section';
import type {
	RenderingMode,
	StoreSettingsBoolean,
	StoreSettingsResponse,
	StoreSettingsState,
} from './types';

const REST_BASE = '/wc/v3/payments/multi-currency';

const isEnabled = ( value: StoreSettingsBoolean ): boolean =>
	value === true || value === 'yes';

const normalizeStoreSettings = (
	response: StoreSettingsResponse
): StoreSettingsState => ( {
	enableAutoCurrency: isEnabled(
		response.wcpay_multi_currency_enable_auto_currency
	),
	enableStorefrontSwitcher: isEnabled(
		response.wcpay_multi_currency_enable_storefront_switcher
	),
	renderingMode: response.wcpay_multi_currency_rendering_mode || 'speed',
	shouldRecommendCacheMode: response.should_recommend_cache_mode,
	cacheRecommendationDismissed: response.cache_recommendation_dismissed,
	isCacheOptimizedFeatureEnabled: response.is_cache_optimized_feature_enabled,
	siteTheme: response.site_theme,
	storeUrl: response.store_url,
} );

// Client 11.1.0 components/preview-modal/index.js:37-44 opens the shop page in the simulation that shows the switch banner.
// It builds the URL from the domain root; native starts from the home URL so subdirectory installs work.
const getPreviewUrl = ( storeUrl: string ): string =>
	`${ String( getSetting( 'homeUrl', '' ) ).replace(
		/\/$/,
		''
	) }/${ storeUrl }` +
	'?is_mc_onboarding_simulation=1&enable_storefront_switcher=false&enable_auto_currency=true';

const serializeStoreSettings = ( settings: StoreSettingsState ) => ( {
	wcpay_multi_currency_enable_auto_currency: settings.enableAutoCurrency
		? 'yes'
		: 'no',
	wcpay_multi_currency_enable_storefront_switcher:
		settings.enableStorefrontSwitcher ? 'yes' : 'no',
	wcpay_multi_currency_rendering_mode: settings.renderingMode,
	wcpay_multi_currency_cache_recommendation_dismissed:
		settings.cacheRecommendationDismissed ? 'yes' : 'no',
} );

const areSettingsEqual = (
	currentSettings: StoreSettingsState | null,
	draftSettings: StoreSettingsState | null
): boolean => {
	if ( ! currentSettings || ! draftSettings ) {
		return true;
	}

	return (
		currentSettings.enableAutoCurrency ===
			draftSettings.enableAutoCurrency &&
		currentSettings.enableStorefrontSwitcher ===
			draftSettings.enableStorefrontSwitcher &&
		currentSettings.renderingMode === draftSettings.renderingMode &&
		currentSettings.shouldRecommendCacheMode ===
			draftSettings.shouldRecommendCacheMode &&
		currentSettings.cacheRecommendationDismissed ===
			draftSettings.cacheRecommendationDismissed
	);
};

const StoreSettingsSection = ( { children }: { children?: ReactNode } ) => (
	<SettingsSection
		title={ __( 'Store settings', 'woocommerce' ) }
		description={ createInterpolateElement(
			__(
				'Store settings allow your customers to choose which currency they would like to use when shopping at your store. <learnMoreLink>Learn more</learnMoreLink>',
				'woocommerce'
			),
			{
				learnMoreLink: (
					<ExternalLink href="https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/#store-settings">
						<></>
					</ExternalLink>
				),
			}
		) }
	>
		{ children && (
			<CardBody className="woocommerce-multi-currency-settings__store-settings">
				{ children }
			</CardBody>
		) }
	</SettingsSection>
);

export function StoreLevelSettings() {
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const [ currentSettings, setCurrentSettings ] =
		useState< StoreSettingsState | null >( null );
	const [ draftSettings, setDraftSettings ] =
		useState< StoreSettingsState | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isPreviewOpen, setIsPreviewOpen ] = useState( false );

	useEffect( () => {
		let isMounted = true;

		apiFetch< StoreSettingsResponse >( {
			path: `${ REST_BASE }/get-settings`,
		} )
			.then( ( response ) => {
				if ( ! isMounted ) {
					return;
				}

				const normalizedSettings = normalizeStoreSettings( response );
				setCurrentSettings( normalizedSettings );
				setDraftSettings( normalizedSettings );
			} )
			.catch( () => {
				if ( ! isMounted ) {
					return;
				}

				// Client 11.1.0 `multi-currency/client/data/resolvers.js:59-68`: the snackbar alone reports it.
				createErrorNotice(
					__( 'Error retrieving store settings.', 'woocommerce' )
				);
			} )
			.finally( () => {
				if ( isMounted ) {
					setIsLoading( false );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ createErrorNotice ] );

	const isDirty = useMemo(
		() => ! areSettingsEqual( currentSettings, draftSettings ),
		[ currentSettings, draftSettings ]
	);

	const updateDraftSettings = (
		values: Partial< StoreSettingsState >
	): void => {
		setDraftSettings( ( settings ) =>
			settings
				? {
						...settings,
						...values,
				  }
				: settings
		);
	};

	const saveSettings = async ( settings = draftSettings, force = false ) => {
		if ( ! settings || isSaving || ( ! force && ! isDirty ) ) {
			return;
		}

		setIsSaving( true );

		try {
			const response = await apiFetch< StoreSettingsResponse >( {
				path: `${ REST_BASE }/update-settings`,
				method: 'POST',
				data: serializeStoreSettings( settings ),
			} );
			const normalizedSettings = normalizeStoreSettings( response );

			setCurrentSettings( normalizedSettings );
			setDraftSettings( normalizedSettings );
			createSuccessNotice( __( 'Store settings saved.', 'woocommerce' ) );
		} catch {
			createErrorNotice(
				__( 'Error saving store settings.', 'woocommerce' )
			);
		} finally {
			setIsSaving( false );
		}
	};

	const useCacheRenderingMode = (): void => {
		if ( ! draftSettings || isSaving ) {
			return;
		}

		const nextSettings = {
			...draftSettings,
			renderingMode: 'cache' as RenderingMode,
		};
		setDraftSettings( nextSettings );
		void saveSettings( nextSettings, true );
	};

	const dismissCacheRecommendation = (): void => {
		if ( ! draftSettings || isSaving ) {
			return;
		}

		const nextSettings = {
			...draftSettings,
			cacheRecommendationDismissed: true,
		};
		setDraftSettings( nextSettings );
		void saveSettings( nextSettings, true );
	};

	if ( isLoading ) {
		return (
			<StoreSettingsSection>
				<p aria-live="polite">
					<Spinner />
					{ __( 'Loading store settings…', 'woocommerce' ) }
				</p>
			</StoreSettingsSection>
		);
	}

	// No controls without the stored values, so a save cannot overwrite settings the page never read.
	if ( ! draftSettings ) {
		return <StoreSettingsSection />;
	}

	// Client 11.1.0 store-settings/index.js:84-237: the controls in one card in the client's order, Save below it.
	return (
		<>
			<StoreSettingsSection>
				<CheckboxControl
					__nextHasNoMarginBottom
					checked={ draftSettings.enableAutoCurrency }
					label={ __(
						'Automatically switch customers to their local currency if it has been enabled',
						'woocommerce'
					) }
					help={ createInterpolateElement(
						__(
							'Customers will be notified via store alert banner. <previewLink>Preview</previewLink>',
							'woocommerce'
						),
						{
							previewLink: (
								<Button
									variant="link"
									onClick={ () => setIsPreviewOpen( true ) }
								/>
							),
						}
					) }
					onChange={ ( checked ) =>
						updateDraftSettings( {
							enableAutoCurrency: Boolean( checked ),
						} )
					}
				/>
				{ draftSettings.shouldRecommendCacheMode && (
					<Notice
						status="info"
						politeness="polite"
						onRemove={ dismissCacheRecommendation }
					>
						<div className="woocommerce-multi-currency-settings__cache-recommendation-content">
							<Icon icon={ info } aria-hidden="true" />
							<p>
								{ __(
									'We detected that your store uses page caching. Switching Multi-Currency to the caching-optimized rendering mode lets your host cache pages effectively.',
									'woocommerce'
								) }
							</p>
						</div>
						<Button
							variant="secondary"
							isBusy={ isSaving }
							disabled={ isSaving }
							accessibleWhenDisabled
							onClick={ useCacheRenderingMode }
						>
							{ __( 'Use caching mode', 'woocommerce' ) }
						</Button>
					</Notice>
				) }
				{ draftSettings.isCacheOptimizedFeatureEnabled && (
					<RadioControl
						label={ __( 'Price rendering mode', 'woocommerce' ) }
						help={ __(
							'Choose how multi-currency prices are rendered. "Optimized for caching" outputs identical HTML for all visitors and converts prices client-side, allowing hosting providers to cache pages effectively.',
							'woocommerce'
						) }
						selected={ draftSettings.renderingMode }
						options={ [
							{
								label: __(
									'Optimized for speed (default)',
									'woocommerce'
								),
								value: 'speed',
							},
							{
								label: __(
									'Optimized for caching',
									'woocommerce'
								),
								value: 'cache',
							},
						] }
						onChange={ ( value ) =>
							updateDraftSettings( {
								renderingMode: value as RenderingMode,
							} )
						}
					/>
				) }
				{ draftSettings.siteTheme === 'Storefront' && (
					<CheckboxControl
						__nextHasNoMarginBottom
						checked={ draftSettings.enableStorefrontSwitcher }
						label={ __(
							'Add a currency switcher to the Storefront theme on breadcrumb section.',
							'woocommerce'
						) }
						help={ createInterpolateElement(
							__(
								'A currency switcher is also available in your widgets. <linkToWidgets>Configure now</linkToWidgets>',
								'woocommerce'
							),
							{
								// eslint-disable-next-line jsx-a11y/anchor-has-content -- The link text comes from the interpolated string.
								linkToWidgets: <a href="widgets.php" />,
							}
						) }
						onChange={ ( checked ) =>
							updateDraftSettings( {
								enableStorefrontSwitcher: Boolean( checked ),
							} )
						}
					/>
				) }
			</StoreSettingsSection>
			<div className="woocommerce-multi-currency-settings__save">
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
					accessibleWhenDisabled
					onClick={ () => void saveSettings() }
				>
					{ __( 'Save changes', 'woocommerce' ) }
				</Button>
			</div>
			{ isPreviewOpen && (
				<Modal
					title={ __( 'Preview', 'woocommerce' ) }
					className="woocommerce-multi-currency-settings__preview-modal"
					shouldCloseOnClickOutside={ false }
					onRequestClose={ () => setIsPreviewOpen( false ) }
				>
					<iframe
						title={ __( 'Preview', 'woocommerce' ) }
						className="woocommerce-multi-currency-settings__preview-frame"
						src={ getPreviewUrl( draftSettings.storeUrl ) }
					/>
				</Modal>
			) }
		</>
	);
}

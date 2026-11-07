/**
 * External dependencies
 */
import { CheckboxControl, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../bootstrap';
import { SettingsSection } from '../settings-shell';
import { StripeBillingSection } from '../stripe-billing/stripe-billing-section';
import { useDebugLog, useDevMode, useMultiCurrency } from '../data/hooks';
import { FieldGroup } from './field-group';
import type { BooleanSetting } from './utils';
import { AdvancedSettingsSectionDescription } from './section-descriptions';

const AdvancedSettingsSection = () => {
	const [ isMultiCurrencyEnabled, setIsMultiCurrencyEnabled ] =
		useMultiCurrency() as BooleanSetting;
	const [ isDebugLogEnabled, setIsDebugLogEnabled ] =
		useDebugLog() as BooleanSetting;
	const isDevModeEnabled = Boolean( useDevMode() );
	const bootstrap = getWooPaymentsSettingsBootstrap();
	// Client 11.1.0 `advanced-settings/index.js:22-27`; both values come from the server.
	const isStripeBillingShown =
		bootstrap.isSubscriptionsActive === true &&
		bootstrap.isStripeBillingEligible === true;

	return (
		<SettingsSection
			id="advanced"
			title={ __( 'Advanced settings', 'woocommerce' ) }
			description={ <AdvancedSettingsSectionDescription /> }
		>
			<CheckboxControl
				className="woopayments-settings-advanced-option"
				checked={ isMultiCurrencyEnabled }
				label={ __( 'Enable Multi-Currency', 'woocommerce' ) }
				help={
					<>
						{ __(
							'Allow customers to shop and pay in multiple currencies.',
							'woocommerce'
						) }{ ' ' }
						<ExternalLink href="https://woocommerce.com/document/woopayments/currencies/multi-currency-setup/">
							{ __( 'Learn more', 'woocommerce' ) }
						</ExternalLink>
					</>
				}
				onChange={ ( value ) =>
					setIsMultiCurrencyEnabled( Boolean( value ) )
				}
				__nextHasNoMarginBottom
			/>
			{ isStripeBillingShown && (
				<FieldGroup title={ __( 'Subscriptions', 'woocommerce' ) }>
					<StripeBillingSection />
				</FieldGroup>
			) }
			<FieldGroup title={ __( 'Debug mode', 'woocommerce' ) }>
				<CheckboxControl
					checked={ isDevModeEnabled || isDebugLogEnabled }
					disabled={ isDevModeEnabled }
					help={ __(
						'When enabled, payment error logs will be saved to WooCommerce > Status > Logs.',
						'woocommerce'
					) }
					label={
						isDevModeEnabled
							? __(
									'Log error messages (defaulted on for test accounts)',
									'woocommerce'
								)
							: __( 'Log error messages', 'woocommerce' )
					}
					onChange={ ( value ) =>
						setIsDebugLogEnabled( Boolean( value ) )
					}
					__nextHasNoMarginBottom
				/>
			</FieldGroup>
		</SettingsSection>
	);
};

export { AdvancedSettingsSection };

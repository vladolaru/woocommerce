import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import {
	ControlRadioGroup,
	ControlToggleButton,
	ControlTextInput,
	ControlSelect,
} from '@ppcp-settings/Components/ReusableComponents/Controls';
import SettingsBlock from '@ppcp-settings/Components/ReusableComponents/SettingsBlock';
import Accordion from '@ppcp-settings/Components/ReusableComponents/AccordionSection';
import { SettingsHooks } from '@ppcp-settings/data';
import SoftDescriptorInput from '@ppcp-settings/Components/ReusableComponents/Controls/SoftdescriptorInput';
import { useRegisteredSettings, SLOTS } from '@ppcp-settings/extensions';

const PaypalSettings = ( { hasContactModule } ) => {
	const {
		contactModule,
		setContactModule,
		subtotalAdjustment,
		setSubtotalAdjustment,
		instantPaymentsOnly,
		setInstantPaymentsOnly,
		brandName,
		setBrandName,
		softDescriptor,
		setSoftDescriptor,
		landingPage,
		setLandingPage,
		buttonLanguage,
		setButtonLanguage,
	} = SettingsHooks.useSettings();
	const siteData = useSelect( ( select ) => select( 'core' ).getSite(), [] );
	const siteTitle = siteData?.title;
	const buttonLanguageChoices = window.ppcpSettings.buttonLanguageChoices;

	// Get registered settings for this slot
	const footerSettings = useRegisteredSettings( SLOTS.PAYPAL_SETTINGS_END );

	return (
		<Accordion
			className="ppcp--paypal-settings"
			title={ __( 'PayPal Settings', 'woocommerce' ) }
			description={ __(
				'Modify the PayPal checkout experience.',
				'woocommerce'
			) }
		>
			<SettingsBlock
				title={ __(
					'Subtotal mismatch fallback',
					'woocommerce'
				) }
				description={ __(
					'Due to differences in how WooCommerce and PayPal calculates taxes, some transactions may fail due to a rounding error. This settings determines the fallback behavior.',
					'woocommerce'
				) }
			>
				<ControlRadioGroup
					options={ subtotalAdjustmentChoices }
					value={ subtotalAdjustment }
					onChange={ setSubtotalAdjustment }
				/>
			</SettingsBlock>

			<SettingsBlock>
				<ControlToggleButton
					label={ __(
						'Instant payments only',
						'woocommerce'
					) }
					description={ __(
						'If enabled, PayPal will not allow buyers to use funding sources that take additional time to complete, such as eChecks.',
						'woocommerce'
					) }
					value={ instantPaymentsOnly }
					onChange={ setInstantPaymentsOnly }
				/>
			</SettingsBlock>

			<SettingsBlock visible={ hasContactModule }>
				<ControlToggleButton
					label={ __(
						'Contact selection on payment',
						'woocommerce'
					) }
					description={ __(
						'Allow customers to choose an alternative email and phone number from their PayPal contacts during payment. Order confirmations and tracking updates are sent to the selected contacts instead of checkout details. Perfect for gift orders.',
						'woocommerce'
					) }
					value={ contactModule }
					onChange={ setContactModule }
				/>
			</SettingsBlock>

			<SettingsBlock
				title={ __( 'Brand name', 'woocommerce' ) }
				description={ __(
					'What business name to show to your buyers during checkout and on receipts.',
					'woocommerce'
				) }
			>
				<ControlTextInput
					value={ brandName }
					onChange={ setBrandName }
					placeholder={
						siteTitle ||
						__( 'Brand name', 'woocommerce' )
					}
				/>
			</SettingsBlock>

			<SettingsBlock
				title={ __( 'Soft Descriptor', 'woocommerce' ) }
				description={ __(
					"The dynamic text used to construct the statement descriptor that appears on a payer's card statement. Applies to PayPal and Credit Card transactions. Max value of 22 characters.",
					'woocommerce'
				) }
			>
				<SoftDescriptorInput
					value={ softDescriptor }
					onChange={ setSoftDescriptor }
					placeholder={ __(
						'Soft Descriptor',
						'woocommerce'
					) }
				/>
			</SettingsBlock>

			<SettingsBlock
				title={ __(
					'PayPal landing page',
					'woocommerce'
				) }
				description={ __(
					'Determine which experience a buyer sees when they click the PayPal button.',
					'woocommerce'
				) }
			>
				<ControlRadioGroup
					options={ landingPageChoices }
					value={ landingPage }
					onChange={ setLandingPage }
				/>
			</SettingsBlock>

			<SettingsBlock
				title={ __( 'Button Language', 'woocommerce' ) }
				description={ __(
					"If left blank, PayPal and other buttons will present in the user's detected language. Enter a language here to force all buttons to display in that language.",
					'woocommerce'
				) }
			>
				<ControlSelect
					options={ buttonLanguageChoices }
					value={ buttonLanguage }
					onChange={ setButtonLanguage }
					placeholder={ __(
						'Browser language',
						'woocommerce'
					) }
				/>
			</SettingsBlock>

			{ /* Extension point */ }
			{ footerSettings.map( ( { component: Component, id } ) => (
				<Component key={ id } />
			) ) }
		</Accordion>
	);
};

const subtotalAdjustmentChoices = [
	{
		value: 'correction',
		label: __( 'Add a correction', 'woocommerce' ),
		description: __(
			'Adds an additional line item with the missing amount.',
			'woocommerce'
		),
	},
	{
		value: 'no_details',
		label: __( 'Do not send line items', 'woocommerce' ),
		description: __(
			'Resubmit the transaction without line item details.',
			'woocommerce'
		),
	},
];

const landingPageChoices = [
	{
		value: 'any',
		label: __( 'No preference', 'woocommerce' ),
		description: __(
			'Shows the buyer the PayPal login for a recognized PayPal buyer.',
			'woocommerce'
		),
	},
	{
		value: 'login',
		label: __( 'Login page', 'woocommerce' ),
		description: __(
			'Always show the buyer the PayPal login screen.',
			'woocommerce'
		),
	},
	{
		value: 'guest_checkout',
		label: __( 'Guest checkout page', 'woocommerce' ),
		description: __(
			'Always show the buyer the guest checkout fields first.',
			'woocommerce'
		),
	},
];

export default PaypalSettings;

/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	CheckboxControl,
	Disabled,
	ExternalLink,
	PanelBody,
	RangeControl,
} from '@wordpress/components';
import {
	ColorPaletteControl,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';

export const BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';

export const BLOCK_ATTRIBUTES = {
	symbol: {
		type: 'boolean',
		default: true,
	},
	flag: {
		type: 'boolean',
		default: false,
	},
	fontSize: {
		type: 'integer',
		default: 14,
	},
	fontLineHeight: {
		type: 'number',
		default: 1.5,
	},
	fontColor: {
		type: 'string',
		default: '#000000',
	},
	border: {
		type: 'boolean',
		default: true,
	},
	borderRadius: {
		type: 'integer',
		default: 3,
	},
	borderColor: {
		type: 'string',
		default: '#000000',
	},
	backgroundColor: {
		type: 'string',
		default: 'transparent',
	},
};

const getAttributeSetter =
	( setAttributes, attribute, defaultValue ) => ( value ) =>
		setAttributes( {
			[ attribute ]: value === undefined ? defaultValue : value,
		} );

export const Edit = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Multi-currency settings', 'woocommerce' ) }
				>
					<ExternalLink href="admin.php?page=wc-settings&tab=wcpay_multi_currency">
						{ __(
							'Adjust multi-currency settings',
							'woocommerce'
						) }
					</ExternalLink>
				</PanelBody>
				<PanelBody title={ __( 'Layout', 'woocommerce' ) }>
					<CheckboxControl
						label={ __( 'Display flags', 'woocommerce' ) }
						checked={ attributes.flag }
						onChange={ getAttributeSetter(
							setAttributes,
							'flag',
							false
						) }
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={ __(
							'Display currency symbols',
							'woocommerce'
						) }
						checked={ attributes.symbol }
						onChange={ getAttributeSetter(
							setAttributes,
							'symbol',
							true
						) }
						__nextHasNoMarginBottom
					/>
					<CheckboxControl
						label={ __( 'Show border', 'woocommerce' ) }
						checked={ attributes.border }
						onChange={ getAttributeSetter(
							setAttributes,
							'border',
							true
						) }
						__nextHasNoMarginBottom
					/>
					<RangeControl
						label={ __( 'Border radius', 'woocommerce' ) }
						value={ attributes.borderRadius }
						onChange={ getAttributeSetter(
							setAttributes,
							'borderRadius',
							3
						) }
						min={ 0 }
						max={ 20 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelBody title={ __( 'Typography', 'woocommerce' ) }>
					<RangeControl
						label={ __( 'Font size', 'woocommerce' ) }
						value={ attributes.fontSize }
						onChange={ getAttributeSetter(
							setAttributes,
							'fontSize',
							14
						) }
						min={ 6 }
						max={ 48 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Line height', 'woocommerce' ) }
						value={ attributes.fontLineHeight }
						onChange={ getAttributeSetter(
							setAttributes,
							'fontLineHeight',
							1.5
						) }
						min={ 1 }
						max={ 3 }
						step={ 0.1 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelBody title={ __( 'Colors', 'woocommerce' ) }>
					<ColorPaletteControl
						label={ __( 'Text color', 'woocommerce' ) }
						value={ attributes.fontColor }
						onChange={ getAttributeSetter(
							setAttributes,
							'fontColor',
							'#000000'
						) }
					/>
					<ColorPaletteControl
						label={ __( 'Background color', 'woocommerce' ) }
						value={ attributes.backgroundColor }
						onChange={ getAttributeSetter(
							setAttributes,
							'backgroundColor',
							'transparent'
						) }
					/>
					<ColorPaletteControl
						label={ __( 'Border color', 'woocommerce' ) }
						value={ attributes.borderColor }
						onChange={ getAttributeSetter(
							setAttributes,
							'borderColor',
							'#000000'
						) }
					/>
				</PanelBody>
			</InspectorControls>
			<Disabled>
				<ServerSideRender
					block={ BLOCK_NAME }
					attributes={ attributes }
				/>
			</Disabled>
		</div>
	);
};

export const blockSettings = {
	apiVersion: 3,
	title: __( 'Currency switcher', 'woocommerce' ),
	description: __(
		'Let customers switch between enabled currencies.',
		'woocommerce'
	),
	icon: 'money-alt',
	category: 'widgets',
	attributes: BLOCK_ATTRIBUTES,
	edit: Edit,
	save: () => null,
};

import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { PayPalScriptProvider, PayPalMessages } from '@paypal/react-paypal-js';
import { WithScriptParams } from './components/with-script-params';
import { usePreviewTimeout } from './hooks/use-preview-timeout';
import { usePreviewController } from './hooks/use-preview-controller';
import { PreviewPlaceholder } from './components/preview-placeholder';

export default function Edit( { attributes, clientId, setAttributes } ) {
	const {
		layout,
		logo,
		position,
		color,
		size,
		flexColor,
		flexRatio,
		placement,
		id,
	} = attributes;

	// v6 styles text only; the stored attribute stays, so flag-off restores it.
	const isSdkV6Active = !! PcpPayLaterBlock.isSdkV6Active;
	const effectiveLayout = isSdkV6Active ? 'text' : layout;
	const isFlex = effectiveLayout === 'flex';

	const [ loaded, setLoaded ] = useState( false );
	const timedOut = usePreviewTimeout( loaded );
	const { containerRef, renderKey } = usePreviewController(
		loaded,
		setLoaded
	);

	let amount;
	const postContent = String(
		wp.data.select( 'core/editor' )?.getEditedPostContent()
	);
	if (
		postContent.includes( 'woocommerce/checkout' ) ||
		postContent.includes( 'woocommerce/cart' )
	) {
		amount = 50.0;
	}

	const previewStyle = {
		layout: effectiveLayout,
		logo: {
			position,
			type: logo,
		},
		color: flexColor,
		ratio: flexRatio,
		text: {
			color,
			size,
		},
	};

	const classes = [ 'ppcp-paylater-block-preview', 'ppcp-overlay-parent' ];
	if ( ! PcpPayLaterBlock.placementEnabled ) {
		classes.push( 'ppcp-paylater-unavailable', 'block-editor-warning' );
	}
	const props = useBlockProps( { className: classes.join( ' ' ) } );

	useEffect( () => {
		if ( ! id ) {
			setAttributes( { id: `ppcp-${ clientId }` } );
		}
	}, [ id, clientId ] );

	if ( ! PcpPayLaterBlock.placementEnabled ) {
		return (
			<div { ...props }>
				<div className="block-editor-warning__contents">
					<p className="block-editor-warning__message">
						{ __(
							'Pay Later Messaging cannot be used while the “WooCommerce Block” messaging placement is disabled. Enable the placement in the PayPal Payments Pay Later settings to reactivate this block.',
							'woocommerce'
						) }
					</p>
					<div className="block-editor-warning__actions">
						<span className="block-editor-warning__action">
							<a href={ PcpPayLaterBlock.payLaterSettingsUrl }>
								<button
									type="button"
									className="components-button is-primary"
								>
									{ __(
										'PayPal Payments Settings',
										'woocommerce'
									) }
								</button>
							</a>
						</span>
						<span className="block-editor-warning__action">
							<button
								onClick={ () =>
									wp.data
										.dispatch( 'core/block-editor' )
										.removeBlock( clientId )
								}
								type="button"
								className="components-button is-secondary"
							>
								{ __( 'Remove Block', 'woocommerce' ) }
							</button>
						</span>
					</div>
				</div>
			</div>
		);
	}

	return (
		<WithScriptParams
			requestConfig={ PcpPayLaterBlock.ajax.cart_script_params }
			fallback={
				<div { ...props }>
					<PreviewPlaceholder timedOut={ timedOut } />
				</div>
			}
		>
			{ ( scriptParams ) => {
				const urlParams = {
					...scriptParams.url_params,
					components: 'messages',
					dataNamespace: 'ppcp-block-editor-paylater-message',
				};

				return (
					<>
						<InspectorControls>
							<PanelBody
								title={ __( 'Settings', 'woocommerce' ) }
							>
								{ ! isSdkV6Active && (
									<SelectControl
										label={ __( 'Layout', 'woocommerce' ) }
										options={ [
											{
												label: __(
													'Text',
													'woocommerce'
												),
												value: 'text',
											},
											{
												label: __(
													'Banner',
													'woocommerce'
												),
												value: 'flex',
											},
										] }
										value={ layout }
										onChange={ ( value ) =>
											setAttributes( { layout: value } )
										}
									/>
								) }
								{ ! isFlex && (
									<SelectControl
										label={ __( 'Logo', 'woocommerce' ) }
										options={ [
											{
												label: __(
													'Full logo',
													'woocommerce'
												),
												value: 'primary',
											},
											{
												label: __(
													'Monogram',
													'woocommerce'
												),
												value: 'alternative',
											},
											{
												label: __(
													'Inline',
													'woocommerce'
												),
												value: 'inline',
											},
											{
												label: __(
													'Message only',
													'woocommerce'
												),
												value: 'none',
											},
										] }
										value={ logo }
										onChange={ ( value ) =>
											setAttributes( { logo: value } )
										}
									/>
								) }
								{ ! isFlex && logo === 'primary' && (
									<SelectControl
										label={ __(
											'Logo Position',
											'woocommerce'
										) }
										options={ [
											{
												label: __(
													'Left',
													'woocommerce'
												),
												value: 'left',
											},
											{
												label: __(
													'Right',
													'woocommerce'
												),
												value: 'right',
											},
											{
												label: __(
													'Top',
													'woocommerce'
												),
												value: 'top',
											},
										] }
										value={ position }
										onChange={ ( value ) =>
											setAttributes( { position: value } )
										}
									/>
								) }
								{ ! isFlex && (
									<SelectControl
										label={ __(
											'Text Color',
											'woocommerce'
										) }
										options={ [
											{
												label: __(
													'Black / Blue logo',
													'woocommerce'
												),
												value: 'black',
											},
											{
												label: __(
													'White / White logo',
													'woocommerce'
												),
												value: 'white',
											},
											{
												label: __(
													'Monochrome',
													'woocommerce'
												),
												value: 'monochrome',
											},
											{
												label: __(
													'Black / Gray logo',
													'woocommerce'
												),
												value: 'grayscale',
											},
										] }
										value={ color }
										onChange={ ( value ) =>
											setAttributes( { color: value } )
										}
									/>
								) }
								{ ! isFlex && (
									<SelectControl
										label={ __(
											'Text Size',
											'woocommerce'
										) }
										options={ [
											{
												label: __(
													'Small',
													'woocommerce'
												),
												value: '12',
											},
											{
												label: __(
													'Medium',
													'woocommerce'
												),
												value: '14',
											},
											{
												label: __(
													'Large',
													'woocommerce'
												),
												value: '16',
											},
										] }
										value={ size }
										onChange={ ( value ) =>
											setAttributes( { size: value } )
										}
									/>
								) }
								{ isFlex && (
									<SelectControl
										label={ __( 'Color', 'woocommerce' ) }
										options={ [
											{
												label: __(
													'Blue',
													'woocommerce'
												),
												value: 'blue',
											},
											{
												label: __(
													'Black',
													'woocommerce'
												),
												value: 'black',
											},
											{
												label: __(
													'White',
													'woocommerce'
												),
												value: 'white',
											},
											{
												label: __(
													'White (no border)',
													'woocommerce'
												),
												value: 'white-no-border',
											},
										] }
										value={ flexColor }
										onChange={ ( value ) =>
											setAttributes( {
												flexColor: value,
											} )
										}
									/>
								) }
								{ isFlex && (
									<SelectControl
										label={ __( 'Ratio', 'woocommerce' ) }
										options={ [
											{
												label: __(
													'8x1',
													'woocommerce'
												),
												value: '8x1',
											},
											{
												label: __(
													'20x1',
													'woocommerce'
												),
												value: '20x1',
											},
										] }
										value={ flexRatio }
										onChange={ ( value ) =>
											setAttributes( {
												flexRatio: value,
											} )
										}
									/>
								) }
								<SelectControl
									label={ __(
										'Placement page',
										'woocommerce'
									) }
									help={ __(
										'Used for the analytics dashboard in the merchant account.',
										'woocommerce'
									) }
									options={ [
										{
											label: __(
												'Detect automatically',
												'woocommerce'
											),
											value: 'auto',
										},
										{
											label: __(
												'Product Page',
												'woocommerce'
											),
											value: 'product',
										},
										{
											label: __( 'Cart', 'woocommerce' ),
											value: 'cart',
										},
										{
											label: __(
												'Checkout',
												'woocommerce'
											),
											value: 'checkout',
										},
										{
											label: __( 'Home', 'woocommerce' ),
											value: 'home',
										},
										{
											label: __( 'Shop', 'woocommerce' ),
											value: 'shop',
										},
									] }
									value={ placement }
									onChange={ ( value ) =>
										setAttributes( { placement: value } )
									}
								/>
							</PanelBody>
						</InspectorControls>
						<div { ...props }>
							<div
								className="ppcp-overlay-child"
								ref={ containerRef }
							>
								<PayPalScriptProvider
									key={ renderKey }
									options={ urlParams }
								>
									<PayPalMessages
										style={ previewStyle }
										forceReRender={ [ previewStyle ] }
										onRender={ () => setLoaded( true ) }
										amount={ amount }
									/>
								</PayPalScriptProvider>
							</div>
							<div className="ppcp-overlay-child ppcp-unclicable-overlay">
								{ ' ' }
								{ /* make the message not clickable */ }
								{ ! loaded && (
									<PreviewPlaceholder timedOut={ timedOut } />
								) }
							</div>
						</div>
					</>
				);
			} }
		</WithScriptParams>
	);
}

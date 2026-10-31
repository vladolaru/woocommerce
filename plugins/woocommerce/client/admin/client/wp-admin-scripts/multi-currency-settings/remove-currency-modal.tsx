/**
 * External dependencies
 */
import { Button, Icon, Modal } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { cancelCircleFilled } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { MultiCurrencyCurrency } from './types';

declare global {
	interface Window {
		// Printed by the payments provider on this page: method titles keyed by method ID, keyed by the currency they need.
		multiCurrencyPaymentMethodsMap?: Record<
			string,
			Record< string, string >
		>;
		// Printed with it: the settings icon URL of each of those methods, keyed by method ID.
		multiCurrencyPaymentMethodIcons?: Record< string, string >;
	}
}

/**
 * The enabled payment methods that need a currency, as titles keyed by method ID.
 *
 * @param code Currency code.
 */
export const getDependentPaymentMethods = (
	code: string
): Record< string, string > =>
	window.multiCurrencyPaymentMethodsMap?.[ code ] ?? {};

interface RemoveCurrencyModalProps {
	currency: MultiCurrencyCurrency;
	onConfirm: () => void;
	onCancel: () => void;
}

/**
 * Client 11.1.0 enabled-currencies-list/delete-button.js:50-128: confirm removing a currency that enabled payment methods need.
 */
export function RemoveCurrencyModal( {
	currency,
	onConfirm,
	onCancel,
}: RemoveCurrencyModalProps ) {
	const symbol = decodeEntities( currency.symbol );
	const codeAndSymbol =
		currency.code === symbol
			? currency.code
			: `${ currency.code } ${ symbol }`;

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: Name of the currency being removed. */
				__( 'Remove %s', 'woocommerce' ),
				currency.name
			) }
			className="woocommerce-multi-currency-settings__remove-modal"
			onRequestClose={ onCancel }
		>
			<div
				className="woocommerce-multi-currency-settings__remove-illustration"
				aria-hidden="true"
			>
				{ symbol }
				<Icon icon={ cancelCircleFilled } size={ 34 } />
			</div>
			<p>
				{ createInterpolateElement(
					sprintf(
						/* translators: 1: Currency name, 2: Currency code and symbol. */
						__(
							'Are you sure you want to remove <strong>%1$s (%2$s)</strong>? Your customers will no longer be able to pay in this currency and use payment methods listed below.',
							'woocommerce'
						),
						currency.name,
						codeAndSymbol
					),
					{ strong: <strong /> }
				) }
			</p>
			<ul className="woocommerce-multi-currency-settings__remove-methods">
				{ Object.entries(
					getDependentPaymentMethods( currency.code )
				).map( ( [ paymentMethodId, title ] ) => {
					const iconUrl =
						window.multiCurrencyPaymentMethodIcons?.[
							paymentMethodId
						];

					return (
						<li key={ paymentMethodId }>
							{ /* The title follows, so the logo is decorative. */ }
							{ iconUrl && <img src={ iconUrl } alt="" /> }
							<span>{ title }</span>
						</li>
					);
				} ) }
			</ul>
			<p>
				{ sprintf(
					/* translators: 1: Currency name, 2: Currency code and symbol. */
					__(
						'You can add %1$s (%2$s) again at any time in Multi-Currency settings.',
						'woocommerce'
					),
					currency.name,
					codeAndSymbol
				) }
			</p>
			<div className="woocommerce-multi-currency-settings__modal-actions">
				<Button variant="primary" isDestructive onClick={ onConfirm }>
					{ __( 'Remove', 'woocommerce' ) }
				</Button>
				<Button variant="secondary" onClick={ onCancel }>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
			</div>
		</Modal>
	);
}

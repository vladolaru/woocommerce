/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import { Button, Notice, TextControl } from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import type { ComponentType, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import SettingsCard from '../app/Components/ReusableComponents/SettingsCard';
import AppSettingsBlock from '../app/Components/ReusableComponents/SettingsBlock';
import {
	Action as AppAction,
	Description,
} from '../app/Components/ReusableComponents/Elements';
import { HStack } from '../app/Components/ReusableComponents/Stack';
import {
	checkStatus,
	requestReferral,
	updatePayeeEmail,
	type CollectingData,
	type MerchantState,
} from './api';
import './style.scss';

type Message = { status: 'success' | 'error'; text: string };

/**
 * The settings app's components are plain JS, so TypeScript reads every prop they destructure as required, optional
 * ones included. The panel uses them through this looser type.
 */
type AppComponent = ComponentType< {
	[ prop: string ]: unknown;
	children?: ReactNode;
} >;
const SettingsBlock = AppSettingsBlock as unknown as AppComponent;
const Action = AppAction as unknown as AppComponent;

/**
 * The panel data the page was served with, as `ppcpSettings.collecting`. Present only while the platform serves the store.
 */
const getInitialData = (): CollectingData | null => {
	const data = window.ppcpSettings?.collecting;

	return data && typeof data === 'object' ? ( data as CollectingData ) : null;
};

const merchantStateCopy = ( state: MerchantState, payee: string ): string => {
	switch ( state ) {
		case 'connected':
			return sprintf(
				/* translators: %s: the PayPal email address payments go to. */
				__( 'PayPal Wallet is connected to %s.', 'woocommerce' ),
				payee
			);
		case 'confirmed_not_connected':
			return sprintf(
				/* translators: %s: the PayPal email address payments go to. */
				__(
					'Your PayPal account %s is confirmed. Complete setup to connect it to your store.',
					'woocommerce'
				),
				payee
			);
		case 'email_unconfirmed':
			return sprintf(
				/* translators: %s: the PayPal email address payments go to. */
				__(
					'Confirm the email PayPal sent to %s to release the payment.',
					'woocommerce'
				),
				payee
			);
		default:
			return sprintf(
				/* translators: %s: the PayPal email address payments go to. */
				__(
					'PayPal has no account for %s yet. Complete setup to create one and receive your payments.',
					'woocommerce'
				),
				payee
			);
	}
};

const errorText = ( error: unknown ): string =>
	error &&
	typeof error === 'object' &&
	'message' in error &&
	typeof error.message === 'string'
		? error.message
		: __( 'Something went wrong. Please try again.', 'woocommerce' );

/**
 * The collecting panel on the PayPal Wallet settings Overview: the payee email, where the merchant stands with PayPal,
 * the orders waiting for setup, and the actions that complete it. It is laid out as the Overview's other cards are.
 */
export const CollectingPanel = () => {
	const [ data, setData ] = useState< CollectingData | null >(
		getInitialData
	);
	const [ email, setEmail ] = useState( data?.payee_email ?? '' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ message, setMessage ] = useState< Message | null >( null );
	const payeeHelpId = `paypal-wallet-collecting-payee-help-${ useInstanceId(
		CollectingPanel
	) }`;

	if ( ! data ) {
		return null;
	}

	const run = async (
		action: string,
		request: () => Promise< void >
	): Promise< void > => {
		// The buttons stay focusable while a request runs, so a second press is ignored here.
		if ( busy !== null ) {
			return;
		}
		setBusy( action );
		setMessage( null );
		try {
			await request();
		} catch ( error ) {
			setMessage( { status: 'error', text: errorText( error ) } );
		} finally {
			setBusy( null );
		}
	};

	const savePayee = () =>
		run( 'payee', async () => {
			const next = await updatePayeeEmail( email );
			setData( next );
			setEmail( next.payee_email );
			setMessage( {
				status: 'success',
				text: __( 'The PayPal email was saved.', 'woocommerce' ),
			} );
		} );

	// Same tab: a window opened after the request would be blocked as a popup, and PayPal returns to these settings.
	const completeSetup = () =>
		run( 'referral', async () => {
			const { url } = await requestReferral();
			window.location.assign( url );
		} );

	const refreshStatus = () =>
		run( 'status', async () => {
			const next = await checkStatus();
			setData( next );
			setMessage(
				next.check === 'failed'
					? {
							status: 'error',
							text: __(
								'PayPal could not be reached. Please try again later.',
								'woocommerce'
							),
					  }
					: {
							status: 'success',
							text: __( 'Status updated.', 'woocommerce' ),
					  }
			);
		} );

	const isConnected = data.merchant_state === 'connected';
	const dateFormat = getDateSettings().formats.date;
	const moreOrders = data.held_orders_count - data.held_orders.length;
	const hasOrders = data.held_orders.length > 0;
	let payeeHelp: string = __(
		'A customer has paid to this email, so it can no longer change.',
		'woocommerce'
	);
	if ( data.can_change_payee_email ) {
		payeeHelp = __(
			'Customers pay to this email until setup is complete. You can change it until the first payment.',
			'woocommerce'
		);
	} else if ( data.state === 'platform_connected' ) {
		payeeHelp = __(
			'This is the PayPal account connected to your store.',
			'woocommerce'
		);
	}

	return (
		<SettingsCard
			className="paypal-wallet-collecting-panel"
			title={ __( 'PayPal Wallet setup', 'woocommerce' ) }
			description={
				<p role="status">
					{ data.transport_ready
						? merchantStateCopy(
								data.merchant_state,
								data.payee_email
						  )
						: __(
								'PayPal Wallet setup is not available on this store yet.',
								'woocommerce'
						  ) }
				</p>
			}
			// The card holds the payee, the actions and the orders; a store with none of them shows the description alone.
			contentContainer={ data.transport_ready || hasOrders }
		>
			{ message && (
				<Notice
					className="paypal-wallet-collecting-panel__message"
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }

			{ data.transport_ready && (
				<SettingsBlock
					className="paypal-wallet-collecting-panel__payee"
					title={ __( 'PayPal email', 'woocommerce' ) }
				>
					{ /* The value, then its help, as the app's own fields show them. */ }
					<Action>
						{ data.can_change_payee_email ? (
							<HStack className="paypal-wallet-collecting-panel__payee-field">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="email"
									label={ __(
										'PayPal email',
										'woocommerce'
									) }
									hideLabelFromVision
									aria-describedby={ payeeHelpId }
									value={ email }
									onChange={ setEmail }
								/>
								<Button
									variant="secondary"
									isBusy={ busy === 'payee' }
									disabled={
										busy !== null ||
										email === data.payee_email
									}
									accessibleWhenDisabled
									onClick={ savePayee }
								>
									{ __( 'Save', 'woocommerce' ) }
								</Button>
							</HStack>
						) : (
							<div className="ppcp--static-value">
								{ data.payee_email }
							</div>
						) }
						<Description className="paypal-wallet-collecting-panel__payee-help">
							<span id={ payeeHelpId }>{ payeeHelp }</span>
						</Description>
					</Action>

					<Action>
						<HStack className="paypal-wallet-collecting-panel__actions">
							{ ! isConnected && (
								<Button
									variant="primary"
									isBusy={ busy === 'referral' }
									disabled={ busy !== null }
									accessibleWhenDisabled
									onClick={ completeSetup }
								>
									{ __( 'Complete setup', 'woocommerce' ) }
								</Button>
							) }
							<Button
								variant="secondary"
								isBusy={ busy === 'status' }
								disabled={ busy !== null }
								accessibleWhenDisabled
								onClick={ refreshStatus }
							>
								{ __( 'Check status', 'woocommerce' ) }
							</Button>
						</HStack>
					</Action>
				</SettingsBlock>
			) }

			{ hasOrders && (
				<SettingsBlock
					className="paypal-wallet-collecting-panel__orders"
					title={ __( 'Orders waiting for setup', 'woocommerce' ) }
				>
					<ul className="paypal-wallet-collecting-panel__order-list">
						{ data.held_orders.map( ( order ) => (
							<li key={ order.id }>
								<a href={ order.edit_url }>
									{ sprintf(
										/* translators: %s: the order number. */
										__( 'Order #%s', 'woocommerce' ),
										order.number
									) }
								</a>
								<Description>
									<>
										{ sprintf(
											/* translators: %s: the date PayPal returns the payment. */
											__(
												'Returned to the customer on %s if setup is not complete',
												'woocommerce'
											),
											dateI18n(
												dateFormat,
												order.deadline * 1000,
												undefined
											)
										) }
									</>
								</Description>
							</li>
						) ) }
					</ul>
					{ moreOrders > 0 && (
						<Description>
							<>
								{ sprintf(
									/* translators: %d: the number of other orders waiting for setup. */
									_n(
										'%d more order is waiting.',
										'%d more orders are waiting.',
										moreOrders,
										'woocommerce'
									),
									moreOrders
								) }
							</>
						</Description>
					) }
				</SettingsBlock>
			) }
		</SettingsCard>
	);
};

export default CollectingPanel;

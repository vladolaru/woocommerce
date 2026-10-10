/**
 * External dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
} from '@wordpress/components';
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import {
	checkStatus,
	requestReferral,
	updatePayeeEmail,
	type CollectingData,
	type MerchantState,
} from './api';

type Message = { status: 'success' | 'error'; text: string };

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
 * the orders waiting for setup, and the actions that complete it.
 */
export const CollectingPanel = () => {
	const [ data, setData ] = useState< CollectingData | null >(
		getInitialData
	);
	const [ email, setEmail ] = useState( data?.payee_email ?? '' );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ message, setMessage ] = useState< Message | null >( null );

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

	return (
		<Card className="paypal-wallet-collecting-panel">
			<CardHeader>
				<h2>{ __( 'PayPal Wallet setup', 'woocommerce' ) }</h2>
			</CardHeader>
			<CardBody>
				{ message && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }

				{ ! data.transport_ready ? (
					<p>
						{ __(
							'PayPal Wallet setup is not available on this store yet.',
							'woocommerce'
						) }
					</p>
				) : (
					<>
						<p role="status">
							{ merchantStateCopy(
								data.merchant_state,
								data.payee_email
							) }
						</p>

						{ data.can_change_payee_email ? (
							<div className="paypal-wallet-collecting-panel__payee">
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="email"
									label={ __(
										'PayPal email',
										'woocommerce'
									) }
									help={ __(
										'Customers pay to this email until setup is complete. You can change it until the first payment.',
										'woocommerce'
									) }
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
							</div>
						) : (
							<div className="paypal-wallet-collecting-panel__payee">
								<strong>
									{ __( 'PayPal email', 'woocommerce' ) }
								</strong>
								<p>{ data.payee_email }</p>
								<p>
									{ data.state === 'platform_connected'
										? __(
												'This is the PayPal account connected to your store.',
												'woocommerce'
										  )
										: __(
												'A customer has paid to this email, so it can no longer change.',
												'woocommerce'
										  ) }
								</p>
							</div>
						) }

						<div className="paypal-wallet-collecting-panel__actions">
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
						</div>
					</>
				) }

				{ data.held_orders.length > 0 && (
					<div className="paypal-wallet-collecting-panel__orders">
						<h3>
							{ __( 'Orders waiting for setup', 'woocommerce' ) }
						</h3>
						<ul>
							{ data.held_orders.map( ( order ) => (
								<li key={ order.id }>
									<a href={ order.edit_url }>
										{ sprintf(
											/* translators: %s: the order number. */
											__( 'Order #%s', 'woocommerce' ),
											order.number
										) }
									</a>{ ' ' }
									<span>
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
									</span>
								</li>
							) ) }
						</ul>
						{ moreOrders > 0 && (
							<p>
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
							</p>
						) }
					</div>
				) }
			</CardBody>
		</Card>
	);
};

export default CollectingPanel;

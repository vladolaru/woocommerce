/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { ExternalLink } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';
import { addQueryArgs } from '@wordpress/url';

type ExternalAccount = {
	currency?: string;
	status?: string;
};

type DepositsOverview = {
	account?: {
		account_link?: string | false;
		default_external_accounts?: ExternalAccount[];
	};
};

type PayoutBankAccountState = {
	accountLink?: string;
	hasErroredExternalAccount: boolean;
};

const getPayoutOverview = async (): Promise< DepositsOverview > =>
	apiFetch< DepositsOverview >( {
		path: '/wc/v3/payments/deposits/overview-all',
		method: 'GET',
	} );

const getAccountLinkWithFailureSource = ( accountLink: string ) =>
	addQueryArgs( accountLink, {
		from: 'WCPAY_PAYOUTS',
		source: 'wcpay-payout-failure-notice',
	} );

export const PayoutBankAccount = () => {
	const payoutFailureMessage = __(
		'Payouts are currently paused because a recent payout failed.',
		'woocommerce'
	);
	const [ state, setState ] = useState< PayoutBankAccountState >( {
		hasErroredExternalAccount: false,
	} );

	useEffect( () => {
		let isMounted = true;

		getPayoutOverview()
			.then( ( overview ) => {
				if ( ! isMounted ) {
					return;
				}

				const externalAccounts =
					overview.account?.default_external_accounts ?? [];
				const accountLink =
					typeof overview.account?.account_link === 'string'
						? overview.account.account_link
						: undefined;
				const hasErroredExternalAccount = externalAccounts.some(
					( externalAccount ) => externalAccount.status === 'errored'
				);

				setState( {
					accountLink,
					hasErroredExternalAccount,
				} );

				if ( hasErroredExternalAccount ) {
					speak( payoutFailureMessage, 'assertive' );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setState( { hasErroredExternalAccount: false } );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ payoutFailureMessage ] );

	if ( state.hasErroredExternalAccount ) {
		const accountLink = state.accountLink
			? getAccountLinkWithFailureSource( state.accountLink )
			: undefined;

		return (
			<p
				className="woopayments-settings-payout-bank-account__notice"
				role="status"
			>
				<span>{ payoutFailureMessage }</span>{ ' ' }
				{ accountLink ? (
					<a href={ accountLink }>
						{ __(
							'update your bank account details',
							'woocommerce'
						) }
					</a>
				) : (
					__( 'Update your bank account details.', 'woocommerce' )
				) }
			</p>
		);
	}

	return (
		<p className="woopayments-settings-payout-bank-account">
			<span>
				{ __(
					'Manage and update your bank account information to receive payouts.',
					'woocommerce'
				) }
			</span>{ ' ' }
			{ state.accountLink && (
				<ExternalLink
					href={ state.accountLink }
					// Client 11.1.0 `settings/deposits/index.js:239-248`.
					onClick={ () => {
						recordEvent(
							'wcpay_settings_deposits_manage_in_stripe_click'
						);
						recordEvent( 'wcpay_account_details_link_clicked', {
							source: 'settings-deposits',
						} );
					} }
				>
					{ __( 'Manage in Stripe', 'woocommerce' ) }
				</ExternalLink>
			) }
		</p>
	);
};

/**
 * External dependencies
 */
import { ExternalLink } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import moment from 'moment';

/**
 * Internal dependencies
 */
import type { WooPaymentsDepositSchedule } from '../types';
import { getMonthlyAnchorLabel } from '../utils';
import { HelpPopover } from './help-popover';

const PAYOUT_SCHEDULE_DOCS_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/';

// Client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:47-51`: the English anchor names the day, moment's current locale (the site's, from WordPress) names it on screen.
const formatScheduleAnchor = ( weeklyAnchor: string ) =>
	moment()
		.locale( 'en' )
		.day( weeklyAnchor )
		.locale( moment.locale() )
		.format( 'dddd' );

// Client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:29-104`.
export const getPayoutScheduleText = (
	schedule: WooPaymentsDepositSchedule | undefined
) => {
	const interval = schedule?.interval;
	let message = '';

	if ( ! interval || interval === 'manual' ) {
		return null;
	}

	if ( interval === 'daily' ) {
		message = __(
			'Available funds are automatically dispatched <strong>every day</strong>.',
			'woocommerce'
		);
	} else if ( interval === 'weekly' && schedule.weekly_anchor ) {
		message = sprintf(
			/* translators: %s: Day of the week. */
			__(
				'Available funds are automatically dispatched <strong>every %s</strong>.',
				'woocommerce'
			),
			formatScheduleAnchor( schedule.weekly_anchor )
		);
	} else if ( interval === 'monthly' && schedule.monthly_anchor ) {
		message =
			schedule.monthly_anchor === 31
				? __(
						'Available funds are automatically dispatched <strong>on the last day of every month</strong>.',
						'woocommerce'
				  )
				: sprintf(
						/* translators: %s: Day of the month. */
						__(
							'Available funds are automatically dispatched <strong>on the %s of every month</strong>.',
							'woocommerce'
						),
						getMonthlyAnchorLabel( schedule.monthly_anchor )
				  );
	}

	return message
		? createInterpolateElement( message, { strong: <strong /> } )
		: null;
};

/**
 * The payout schedule sentence and its help icon, as client 11.1.0 `components/deposits-overview/deposit-schedule.tsx:111-152` renders them
 * on the Overview payouts card and in the payouts page notice. Renders nothing without an automatic schedule.
 *
 * @param props                  Component props.
 * @param props.depositsSchedule The account's payout schedule.
 */
export const PayoutSchedule = ( {
	depositsSchedule,
}: {
	depositsSchedule: WooPaymentsDepositSchedule | undefined;
} ) => {
	const scheduleText = getPayoutScheduleText( depositsSchedule );

	if ( ! scheduleText ) {
		return null;
	}

	return (
		<>
			{ /* Its own element: an interpolated fragment among siblings trips React's missing-key warning. */ }
			<span>{ scheduleText }</span>
			<HelpPopover
				label={ __( 'Payout schedule tooltip', 'woocommerce' ) }
			>
				{ createInterpolateElement(
					__(
						'The timing and amount of your payouts may vary due to several factors. Check out our <a>payout schedule guide</a> for details.',
						'woocommerce'
					),
					{
						a: (
							<ExternalLink href={ PAYOUT_SCHEDULE_DOCS_URL }>
								<></>
							</ExternalLink>
						),
					}
				) }
			</HelpPopover>
		</>
	);
};

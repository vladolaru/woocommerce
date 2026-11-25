/**
 * External dependencies
 */
import { ExternalLink, Notice, SelectControl } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PayoutBankAccount } from '../payout-bank-account';
import { SettingsSection } from '../settings-shell';
import { TextLink } from '../text-link';
import {
	useCompletedWaitingPeriod,
	useDepositDelayDays,
	useDepositRestrictions,
	useDepositScheduleInterval,
	useDepositScheduleMonthlyAnchor,
	useDepositScheduleWeeklyAnchor,
	useDepositStatus,
	useGetSettings,
} from '../data/hooks';
import { asSettingsRecord, asString } from '../settings-values';
import { FieldGroup } from './field-group';
import type { StringSetting } from './utils';

const PAYOUT_SCHEDULE_DOC_URL =
	'https://woocommerce.com/document/woopayments/payouts/payout-schedule/';

type PayoutInterval = 'daily' | 'weekly' | 'monthly';
type PayoutWeeklyAnchor =
	'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday';

const asPayoutInterval = ( value: string ): PayoutInterval =>
	[ 'daily', 'weekly', 'monthly' ].includes( value )
		? ( value as PayoutInterval )
		: 'daily';

const asPayoutWeeklyAnchor = ( value: string ): PayoutWeeklyAnchor =>
	[ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' ].includes( value )
		? ( value as PayoutWeeklyAnchor )
		: 'monday';

const getMonthlyAnchorLabel = ( anchor: number ) => {
	if ( anchor === 31 ) {
		return __( 'Last day of the month', 'woocommerce' );
	}

	if ( [ 1, 21 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%dst', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	if ( [ 2, 22 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%dnd', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	if ( [ 3, 23 ].includes( anchor ) ) {
		return sprintf(
			/* translators: %d: Day of the month. */
			_x( '%drd', 'monthly payout schedule day option', 'woocommerce' ),
			anchor
		);
	}

	return sprintf(
		/* translators: %d: Day of the month. */
		_x( '%dth', 'monthly payout schedule day option', 'woocommerce' ),
		anchor
	);
};

const getPayoutScheduleHelpText = ( payoutInterval: PayoutInterval ) => {
	if ( payoutInterval === 'monthly' ) {
		return __(
			'Payouts scheduled on a weekend will be sent on the next business day.',
			'woocommerce'
		);
	}

	if ( payoutInterval === 'weekly' ) {
		return __(
			'Payouts that fall on a holiday will initiate on the next business day.',
			'woocommerce'
		);
	}

	return __( 'Payouts will occur every business day.', 'woocommerce' );
};

const PayoutsSettingsSection = () => {
	const [ interval, setInterval ] =
		useDepositScheduleInterval() as StringSetting;
	const [ weeklyAnchor, setWeeklyAnchor ] =
		useDepositScheduleWeeklyAnchor() as StringSetting;
	const [ monthlyAnchor, setMonthlyAnchor ] =
		useDepositScheduleMonthlyAnchor() as StringSetting;
	const depositDelayDays = useDepositDelayDays();
	const completedWaitingPeriod = Boolean( useCompletedWaitingPeriod() );
	const depositStatus = asString( useDepositStatus() );
	const depositRestrictions = asString( useDepositRestrictions() );
	const accountCountry = asString(
		asSettingsRecord( useGetSettings() ).account_country
	);
	const intervalOptions = [
		...( accountCountry === 'JP'
			? []
			: [ { label: __( 'Daily', 'woocommerce' ), value: 'daily' } ] ),
		{ label: __( 'Weekly', 'woocommerce' ), value: 'weekly' },
		{ label: __( 'Monthly', 'woocommerce' ), value: 'monthly' },
	] as const;
	const payoutInterval =
		accountCountry === 'JP' && interval === 'daily'
			? 'weekly'
			: asPayoutInterval( interval );
	const isScheduleRestricted =
		depositStatus !== 'enabled' ||
		depositRestrictions === 'schedule_restricted';
	const isWaitingPeriodIncomplete = ! completedWaitingPeriod;

	const handleIntervalChange = ( newInterval: string ) => {
		switch ( newInterval ) {
			case 'weekly':
				setWeeklyAnchor( weeklyAnchor || 'monday' );
				break;

			case 'monthly':
				setMonthlyAnchor( monthlyAnchor || '1' );
				break;
		}

		setInterval( newInterval );
	};

	return (
		<SettingsSection
			id="payouts"
			title={ __( 'Payouts', 'woocommerce' ) }
			description={
				<>
					<p>
						{ sprintf(
							/* translators: %s: Number of business days. */
							__(
								'Funds are available for payout %s business days after they’re received.',
								'woocommerce'
							),
							String( depositDelayDays )
						) }
					</p>
					<ExternalLink href={ PAYOUT_SCHEDULE_DOC_URL }>
						{ __(
							'Learn more about payout schedules',
							'woocommerce'
						) }
					</ExternalLink>
				</>
			}
		>
			<FieldGroup
				id="payout-schedule"
				title={ __( 'Payout schedule', 'woocommerce' ) }
			>
				{ /* Client 11.1.0 settings/deposits/index.js:160-203. */ }
				{ isScheduleRestricted && (
					<Notice status="warning" isDismissible={ false }>
						{ createInterpolateElement(
							__(
								'Payout scheduling is currently unavailable for your store. <learnMoreLink>Learn more</learnMoreLink>',
								'woocommerce'
							),
							{
								learnMoreLink: (
									<TextLink
										href={ PAYOUT_SCHEDULE_DOC_URL }
									/>
								),
							}
						) }
					</Notice>
				) }
				{ ! isScheduleRestricted && isWaitingPeriodIncomplete && (
					<Notice status="warning" isDismissible={ false }>
						{ createInterpolateElement(
							__(
								'Payout scheduling becomes available after the standard 7-day waiting period for new accounts is complete. <learnMoreLink>Learn more</learnMoreLink>',
								'woocommerce'
							),
							{
								learnMoreLink: (
									<TextLink
										href={ PAYOUT_SCHEDULE_DOC_URL }
									/>
								),
							}
						) }
					</Notice>
				) }
				{ ! isScheduleRestricted && ! isWaitingPeriodIncomplete && (
					<div className="woopayments-settings-control-grid woopayments-settings-control-grid--inline">
						<SelectControl
							label={ __( 'Frequency', 'woocommerce' ) }
							value={ payoutInterval }
							options={ intervalOptions }
							onChange={ handleIntervalChange }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						{ payoutInterval === 'weekly' && (
							<SelectControl
								label={ __( 'Day', 'woocommerce' ) }
								value={ asPayoutWeeklyAnchor( weeklyAnchor ) }
								options={ [
									{
										label: __( 'Monday', 'woocommerce' ),
										value: 'monday',
									},
									{
										label: __( 'Tuesday', 'woocommerce' ),
										value: 'tuesday',
									},
									{
										label: __( 'Wednesday', 'woocommerce' ),
										value: 'wednesday',
									},
									{
										label: __( 'Thursday', 'woocommerce' ),
										value: 'thursday',
									},
									{
										label: __( 'Friday', 'woocommerce' ),
										value: 'friday',
									},
								] }
								onChange={ setWeeklyAnchor }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
						{ payoutInterval === 'monthly' && (
							<SelectControl
								label={ __( 'Date', 'woocommerce' ) }
								value={ monthlyAnchor }
								options={ [
									...Array.from(
										{ length: 28 },
										( _value, index ) => ( {
											label: getMonthlyAnchorLabel(
												index + 1
											),
											value: String( index + 1 ),
										} )
									),
									{
										label: getMonthlyAnchorLabel( 31 ),
										value: '31',
									},
								] }
								onChange={ setMonthlyAnchor }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						) }
					</div>
				) }
				{ ! isScheduleRestricted && ! isWaitingPeriodIncomplete && (
					<p className="woopayments-settings-muted">
						{ getPayoutScheduleHelpText( payoutInterval ) }
					</p>
				) }
			</FieldGroup>
			<FieldGroup title={ __( 'Payout bank account', 'woocommerce' ) }>
				<PayoutBankAccount />
			</FieldGroup>
		</SettingsSection>
	);
};

export { PAYOUT_SCHEDULE_DOC_URL, PayoutsSettingsSection };

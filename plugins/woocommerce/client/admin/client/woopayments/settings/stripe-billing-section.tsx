/**
 * External dependencies
 */
import {
	Button,
	CheckboxControl,
	ExternalLink,
	Modal,
	Notice,
} from '@wordpress/components';
import {
	Children,
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	useManualCapture,
	useSettings,
	useStripeBilling,
	useStripeBillingMigration,
} from './data/hooks';

const STRIPE_BILLING_DOC_URL =
	'https://woocommerce.com/document/woopayments/subscriptions/stripe-billing/';

type StripeBillingMigrationState = [
	boolean,
	number,
	number,
	() => void,
	boolean,
	boolean,
];

const withLearnMoreLink = ( text: string, href: string ) =>
	Children.toArray(
		createInterpolateElement( text, {
			learnMoreLink: (
				<ExternalLink href={ href }>
					<></>
				</ExternalLink>
			),
		} )
	);

/**
 * Stripe Billing settings for stores with WooCommerce Subscriptions: the toggle, the migration notices, and the
 * conflict with manual capture. Behavior follows client 11.1.0 `client/settings/advanced-settings/stripe-billing-section.tsx`,
 * `stripe-billing-toggle.tsx` and `stripe-billing-notices/*`: the notices react to the toggle as it was last saved.
 */
export const StripeBillingSection = () => {
	const [ isStripeBillingEnabled, updateIsStripeBillingEnabled ] =
		useStripeBilling() as [ boolean, ( enabled: boolean ) => void ];
	const [ isManualCaptureEnabled ] = useManualCapture() as [ boolean ];
	const [
		isMigrationInProgress,
		migratedCount,
		subscriptionCount,
		startMigration,
		isResolvingMigrateRequest,
		hasResolvedMigrateRequest,
	] = useStripeBillingMigration() as StripeBillingMigrationState;
	const { isLoading, isSaving } = useSettings() as {
		isLoading: boolean;
		isSaving: boolean;
	};

	// Notices follow the saved toggle, so the save is tracked from start to finish.
	const [ hasSavedSettings, setHasSavedSettings ] = useState( false );
	const [ savedIsStripeBillingEnabled, setSavedIsStripeBillingEnabled ] =
		useState( isStripeBillingEnabled );
	const hasFinishedSavingSettings = ! isSaving && hasSavedSettings;

	useEffect( () => {
		if ( isSaving && ! isLoading ) {
			setHasSavedSettings( true );
		}
	}, [ isLoading, isSaving ] );

	useEffect( () => {
		if ( hasFinishedSavingSettings ) {
			setSavedIsStripeBillingEnabled( isStripeBillingEnabled );
		}
	}, [ hasFinishedSavingSettings, isStripeBillingEnabled ] );

	const [ isMigrationInProgressLocal, setIsMigrationInProgressLocal ] =
		useState( false );
	const isMigrating = isMigrationInProgress || isMigrationInProgressLocal;

	// The option to migrate is offered only when Stripe Billing was off on load, until it is saved as on.
	const [ isMigrationOptionEligible, setIsMigrationOptionEligible ] =
		useState( ! isStripeBillingEnabled );
	useEffect( () => {
		if ( savedIsStripeBillingEnabled ) {
			setIsMigrationOptionEligible( false );
		}
	}, [ savedIsStripeBillingEnabled ] );

	const isMigrationOptionShown =
		! hasResolvedMigrateRequest &&
		! isMigrating &&
		subscriptionCount > 0 &&
		isMigrationOptionEligible &&
		! isStripeBillingEnabled;

	useEffect( () => {
		if ( hasResolvedMigrateRequest ) {
			setIsMigrationInProgressLocal( true );
		}
	}, [ hasResolvedMigrateRequest ] );

	// Turning Stripe Billing off once saved migrates the remaining subscriptions automatically.
	const [ isAutomaticMigrationEligible, setIsAutomaticMigrationEligible ] =
		useState( isStripeBillingEnabled );
	const [ isProgressEligible, setIsProgressEligible ] =
		useState( isMigrating );
	useEffect( () => {
		if ( hasResolvedMigrateRequest ) {
			setIsProgressEligible( true );
		}
	}, [ hasResolvedMigrateRequest ] );
	useEffect( () => {
		if ( hasFinishedSavingSettings ) {
			setIsAutomaticMigrationEligible( savedIsStripeBillingEnabled );
			setIsProgressEligible( ! savedIsStripeBillingEnabled );
		}
	}, [ hasFinishedSavingSettings, savedIsStripeBillingEnabled ] );
	const [ isProgressDismissed, setIsProgressDismissed ] = useState( false );

	const [ isCompletedEligible ] = useState(
		! isStripeBillingEnabled && ! isMigrating
	);
	const [ isCompletedDismissed, setIsCompletedDismissed ] = useState( false );

	const [ isConflictModalOpen, setIsConflictModalOpen ] = useState( false );

	const onToggle = ( enabled: boolean ) => {
		if ( enabled && isManualCaptureEnabled ) {
			setIsConflictModalOpen( true );
			return;
		}

		updateIsStripeBillingEnabled( enabled );
		setHasSavedSettings( false );
	};

	return (
		<>
			{ isCompletedEligible &&
				! isCompletedDismissed &&
				migratedCount > 0 && (
					<Notice
						status="info"
						onRemove={ () => setIsCompletedDismissed( true ) }
					>
						{ sprintf(
							/* translators: %1$d: number of subscriptions, %2$s: Woo Subscriptions, %3$s: WooPayments. */
							_n(
								'%1$d customer subscription was successfully migrated from Stripe off-site billing to on-site billing powered by %2$s and %3$s.',
								'%1$d customer subscriptions were successfully migrated from Stripe off-site billing to on-site billing powered by %2$s and %3$s.',
								migratedCount,
								'woocommerce'
							),
							migratedCount,
							'Woo Subscriptions',
							'WooPayments'
						) }
					</Notice>
				) }
			{ isMigrationOptionShown && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Begin migration', 'woocommerce' ),
							onClick: startMigration,
							className: isResolvingMigrateRequest
								? 'is-busy'
								: undefined,
						},
					] }
				>
					{ withLearnMoreLink(
						sprintf(
							/* translators: %1$d: number of subscriptions, %2$s: Woo Subscriptions. */
							_n(
								'There is %1$d customer subscription using Stripe Billing for subscription renewals. We suggest migrating it to on-site billing powered by the %2$s plugin. <learnMoreLink>Learn more</learnMoreLink>',
								'There are %1$d customer subscriptions using Stripe Billing for payment processing. We suggest migrating them to on-site billing powered by the %2$s plugin. <learnMoreLink>Learn more</learnMoreLink>',
								subscriptionCount,
								'woocommerce'
							),
							subscriptionCount,
							'Woo Subscriptions'
						),
						`${ STRIPE_BILLING_DOC_URL }#migrating-subscribers`
					) }
				</Notice>
			) }
			{ isAutomaticMigrationEligible &&
				! isMigrationOptionShown &&
				subscriptionCount > 0 &&
				! isStripeBillingEnabled && (
					<Notice status="warning" isDismissible={ false }>
						{ withLearnMoreLink(
							sprintf(
								/* translators: %1$d: number of subscriptions, %2$s: Woo Subscriptions. */
								_n(
									'There is currently %1$d customer subscription using Stripe Billing for payment processing. This subscription will be automatically migrated to use the on-site billing engine built into %2$s once Stripe Billing is disabled. <learnMoreLink>Learn more</learnMoreLink>',
									'There are currently %1$d customer subscriptions using Stripe Billing for payment processing. These subscriptions will be automatically migrated to use the on-site billing engine built into %2$s once Stripe Billing is disabled. <learnMoreLink>Learn more</learnMoreLink>',
									subscriptionCount,
									'woocommerce'
								),
								subscriptionCount,
								'Woo Subscriptions'
							),
							`${ STRIPE_BILLING_DOC_URL }#disabling`
						) }
					</Notice>
				) }
			{ isProgressEligible &&
				! isProgressDismissed &&
				subscriptionCount > 0 &&
				! isMigrationOptionShown && (
					<Notice
						status="info"
						onRemove={ () => setIsProgressDismissed( true ) }
					>
						{ sprintf(
							/* translators: %1$d: number of subscriptions, %2$s: Woo Subscriptions, %3$s: WooPayments. */
							_n(
								'%1$d customer subscription is being migrated from Stripe off-site billing to billing powered by %2$s and %3$s.',
								'%1$d customer subscriptions are being migrated from Stripe off-site billing to billing powered by %2$s and %3$s.',
								subscriptionCount,
								'woocommerce'
							),
							subscriptionCount,
							'Woo Subscriptions',
							'WooPayments'
						) }
					</Notice>
				) }
			<CheckboxControl
				checked={ isStripeBillingEnabled }
				onChange={ onToggle }
				label={ __(
					'Enable Stripe Billing for future subscriptions',
					'woocommerce'
				) }
				help={ withLearnMoreLink(
					isMigrationOptionShown && migratedCount === 0
						? sprintf(
								/* translators: %s: WooPayments. */
								__(
									'Alternatively, you can enable this setting and future %s subscription purchases will also utilize Stripe Billing for payment processing. Note: This feature supports card payments only and may lack support for key subscription features. <learnMoreLink>Learn more</learnMoreLink>',
									'woocommerce'
								),
								'WooPayments'
						  )
						: sprintf(
								/* translators: %s: WooPayments. */
								__(
									'By enabling this setting, future %s subscription purchases will utilize Stripe Billing for payment processing. Note: This feature supports card payments only and may lack support for key subscription features. <learnMoreLink>Learn more</learnMoreLink>',
									'woocommerce'
								),
								'WooPayments'
						  ),
					STRIPE_BILLING_DOC_URL
				) }
				__nextHasNoMarginBottom
			/>
			{ isConflictModalOpen && (
				<Modal
					title={ __( 'Enable Stripe Billing', 'woocommerce' ) }
					onRequestClose={ () => setIsConflictModalOpen( false ) }
					className="woopayments-settings-modal"
				>
					<p>
						{ createInterpolateElement(
							__(
								'Stripe Billing is not available with <b>manual capture enabled</b>. To use Stripe Billing, disable manual capture in your settings list.',
								'woocommerce'
							),
							{ b: <strong /> }
						) }
					</p>
					<div className="woopayments-settings-modal__actions">
						<Button
							variant="primary"
							onClick={ () => setIsConflictModalOpen( false ) }
						>
							{ __( 'OK', 'woocommerce' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

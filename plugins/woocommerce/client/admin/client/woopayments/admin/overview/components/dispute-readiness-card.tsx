/**
 * External dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardHeader,
	DropdownMenu,
	ExternalLink,
	MenuGroup,
	MenuItem,
	Modal,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { moreVertical } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';
import { List, TaskItem } from '@woocommerce/experimental';

/**
 * Internal dependencies
 */
import {
	confirmWooPaymentsDisputeReadinessStatementDescriptor,
	dismissWooPaymentsDisputeReadinessCard,
	getWooPaymentsDisputeReadiness,
} from '../data';
import type {
	WooPaymentsDisputeReadinessPayload,
	WooPaymentsDisputeReadinessSignal,
} from '../types';

// Client 11.1.0 `overview/dispute-readiness/index.tsx:36-37`.
const LEARN_MORE_URL =
	'https://woocommerce.com/document/woopayments/fraud-and-disputes/preventing-disputes/';

const ACTIONS_POPOVER_CLASS =
	'woocommerce-woopayments-dispute-readiness__actions-popover';

const isVisiblePayload = ( payload: WooPaymentsDisputeReadinessPayload ) =>
	!! payload.overview?.enabled && ! payload.overview.isDismissed;

export const DisputeReadinessCard = ( {
	enabled,
	focusAfterDismissId,
}: {
	enabled?: boolean;
	focusAfterDismissId?: string;
} ) => {
	const sectionRef = useRef< HTMLElement >( null );
	const [ payload, setPayload ] =
		useState< WooPaymentsDisputeReadinessPayload | null >( null );
	const [ announcement, setAnnouncement ] = useState( '' );
	const [ reviewSignal, setReviewSignal ] =
		useState< WooPaymentsDisputeReadinessSignal | null >( null );
	const headingRef = useRef< HTMLHeadingElement >( null );
	const viewedRef = useRef( false );
	const overview = payload?.overview;

	// Client 11.1.0 `overview/dispute-readiness/index.tsx:67-129`.
	useEffect( () => {
		if ( ! overview || overview.isDismissed || viewedRef.current ) {
			return;
		}
		recordEvent( 'wcpay_dispute_readiness_overview_viewed', {
			score: overview.score,
			total: overview.total,
			complete_signal_ids: overview.completeSignalIds,
			incomplete_signal_ids: overview.incompleteSignalIds,
			is_dismissed: overview.isDismissed,
		} );
		viewedRef.current = true;
	}, [ overview ] );

	const recordCtaClick = ( signal: WooPaymentsDisputeReadinessSignal ) =>
		recordEvent( 'wcpay_dispute_readiness_signal_cta_clicked', {
			signal_id: signal.id,
			surface: 'overview',
			score: overview?.score,
			total: overview?.total,
		} );

	useEffect( () => {
		let isMounted = true;

		if ( ! enabled ) {
			setPayload( null );
			return () => {
				isMounted = false;
			};
		}

		getWooPaymentsDisputeReadiness()
			.then( ( nextPayload ) => {
				if ( isMounted ) {
					setPayload( nextPayload );
				}
			} )
			.catch( () => {
				if ( isMounted ) {
					setPayload( null );
				}
			} );

		return () => {
			isMounted = false;
		};
	}, [ enabled ] );

	const dismiss = async () => {
		recordEvent( 'wcpay_dispute_readiness_card_dismissed', {
			score: overview?.score,
			total: overview?.total,
			complete_signal_ids: overview?.completeSignalIds,
			incomplete_signal_ids: overview?.incompleteSignalIds,
			state: overview?.state,
		} );
		const nextPayload = await dismissWooPaymentsDisputeReadinessCard();
		const ownerDocument = sectionRef.current?.ownerDocument;
		const activeElement = ownerDocument?.activeElement;
		// The card's actions menu renders in a popover outside the card.
		const shouldRestoreFocus =
			!! focusAfterDismissId &&
			!! activeElement &&
			( !! sectionRef.current?.contains( activeElement ) ||
				!! activeElement.closest( `.${ ACTIONS_POPOVER_CLASS }` ) );
		setPayload( nextPayload );
		setAnnouncement( __( 'Dispute readiness dismissed.', 'woocommerce' ) );

		if ( ! shouldRestoreFocus ) {
			return;
		}

		ownerDocument?.defaultView?.requestAnimationFrame( () => {
			ownerDocument.getElementById( focusAfterDismissId )?.focus();
		} );
	};

	const confirmDescriptor = async () => {
		recordEvent( 'wcpay_dispute_readiness_statement_descriptor_confirmed', {
			surface: 'overview',
			score: overview?.score,
			total: overview?.total,
		} );
		const nextPayload =
			await confirmWooPaymentsDisputeReadinessStatementDescriptor();
		const activeElement = headingRef.current?.ownerDocument.activeElement;
		const shouldRestoreFocus =
			!! activeElement?.closest( '[role="dialog"]' );
		setPayload( nextPayload );
		setReviewSignal( null );
		setAnnouncement(
			__( 'Statement descriptor confirmed.', 'woocommerce' )
		);

		if ( shouldRestoreFocus ) {
			headingRef.current?.focus();
		}
	};

	const signals = payload?.overview?.signals ?? [];

	return (
		<>
			<div
				className="screen-reader-text"
				role="status"
				aria-live="polite"
				aria-label={ __( 'Dispute readiness status', 'woocommerce' ) }
			>
				{ announcement }
			</div>
			{ enabled && payload && isVisiblePayload( payload ) && (
				// Client 11.1.0 `overview/dispute-readiness/index.tsx:136-226`.
				<Card
					as="section"
					ref={ sectionRef }
					className="woocommerce-woopayments-dispute-readiness"
				>
					<CardHeader className="woocommerce-woopayments-dispute-readiness__header">
						<div className="woocommerce-woopayments-dispute-readiness__header-text">
							<h2
								ref={ headingRef }
								tabIndex={ -1 }
								className="woocommerce-woopayments-overview-card__title"
							>
								{ __( 'Dispute readiness', 'woocommerce' ) }
							</h2>
							<p className="woocommerce-woopayments-dispute-readiness__description">
								{ sprintf(
									/* translators: %d: total number of dispute readiness steps. */
									__(
										'These %d steps help customers recognize charges, understand your policies, and contact you before opening a dispute.',
										'woocommerce'
									),
									payload.overview?.total ?? signals.length
								) }{ ' ' }
								<ExternalLink href={ LEARN_MORE_URL }>
									{ __( 'Learn more', 'woocommerce' ) }
								</ExternalLink>
							</p>
						</div>
						<DropdownMenu
							icon={ moreVertical }
							label={ __(
								'Dispute readiness actions',
								'woocommerce'
							) }
							popoverProps={ {
								placement: 'bottom-end',
								className: ACTIONS_POPOVER_CLASS,
							} }
						>
							{ ( { onClose } ) => (
								<MenuGroup>
									<MenuItem
										onClick={ () => {
											void dismiss();
											onClose();
										} }
									>
										{ __( 'Dismiss', 'woocommerce' ) }
									</MenuItem>
								</MenuGroup>
							) }
						</DropdownMenu>
					</CardHeader>
					<List className="woocommerce-woopayments-dispute-readiness__signals">
						{ signals.map( ( signal ) => {
							const isComplete = signal.status === 'complete';

							return (
								<TaskItem
									key={ signal.id }
									data-status={ signal.status }
									title={ signal.label }
									completed={ isComplete }
									inProgress={ false }
									inProgressLabel=""
									content={ signal.description || '' }
									expanded
									showActionButton={
										! isComplete &&
										( !! signal.reviewPrompt ||
											!! signal.actionUrl )
									}
									level={ 3 }
									action={ () => {
										recordCtaClick( signal );

										if ( signal.reviewPrompt ) {
											setReviewSignal( signal );
										} else if ( signal.actionUrl ) {
											window.location.assign(
												signal.actionUrl
											);
										}
									} }
									actionLabel={
										signal.actionLabel ||
										__( 'Fix it', 'woocommerce' )
									}
								/>
							);
						} ) }
					</List>
				</Card>
			) }
			{ reviewSignal?.reviewPrompt && (
				<Modal
					title={ __( 'Review statement descriptor', 'woocommerce' ) }
					onRequestClose={ () => setReviewSignal( null ) }
				>
					<p>{ reviewSignal.reviewPrompt.text }</p>
					<p>
						<strong>
							{ __(
								'Current statement descriptor',
								'woocommerce'
							) }
						</strong>
					</p>
					<p>{ reviewSignal.reviewPrompt.currentDescriptor }</p>
					<div className="woocommerce-woopayments-dispute-readiness__modal-actions">
						<Button
							variant="secondary"
							onClick={ confirmDescriptor }
						>
							{ reviewSignal.reviewPrompt.confirmLabel }
						</Button>
						<Button
							variant="primary"
							href={ reviewSignal.actionUrl }
						>
							{ reviewSignal.reviewPrompt.updateLabel }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

/**
 * External dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { recordEvent } from '@woocommerce/tracks';

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
		const shouldRestoreFocus =
			!! focusAfterDismissId &&
			!! activeElement &&
			!! sectionRef.current?.contains( activeElement );
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
				<section
					ref={ sectionRef }
					className="woocommerce-woopayments-overview-card woocommerce-woopayments-dispute-readiness"
				>
					<div className="woocommerce-woopayments-dispute-readiness__header">
						<h2 ref={ headingRef } tabIndex={ -1 }>
							{ __( 'Dispute readiness', 'woocommerce' ) }
						</h2>
						<Button
							variant="tertiary"
							onClick={ dismiss }
							aria-label={ __(
								'Dismiss dispute readiness',
								'woocommerce'
							) }
						>
							{ __( 'Dismiss', 'woocommerce' ) }
						</Button>
					</div>
					<p>
						{ sprintf(
							/* translators: 1: complete steps, 2: total steps. */
							__( '%1$d of %2$d steps complete.', 'woocommerce' ),
							payload.overview?.score ?? 0,
							payload.overview?.total ?? signals.length
						) }
					</p>
					<ul className="woocommerce-woopayments-dispute-readiness__signals">
						{ signals.map( ( signal ) => {
							const isComplete = signal.status === 'complete';
							const hasReview = !! signal.reviewPrompt;

							return (
								<li key={ signal.id }>
									<div>
										<strong>{ signal.label }</strong>
										{ signal.description && (
											<p>{ signal.description }</p>
										) }
									</div>
									{ ! isComplete && hasReview && (
										<Button
											variant="secondary"
											onClick={ () => {
												recordCtaClick( signal );
												setReviewSignal( signal );
											} }
										>
											{ signal.actionLabel ||
												__( 'Review', 'woocommerce' ) }
										</Button>
									) }
									{ ! isComplete &&
										! hasReview &&
										signal.actionUrl && (
											<Button
												variant="secondary"
												href={ signal.actionUrl }
												onClick={ () =>
													recordCtaClick( signal )
												}
											>
												{ signal.actionLabel ||
													__(
														'Fix it',
														'woocommerce'
													) }
											</Button>
										) }
								</li>
							);
						} ) }
					</ul>
				</section>
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

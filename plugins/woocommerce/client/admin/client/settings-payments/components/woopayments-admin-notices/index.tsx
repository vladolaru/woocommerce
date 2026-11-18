/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { dispatch, resolveSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { paymentSettingsStore } from '@woocommerce/data';
import type { WooPaymentsAdminNotice } from '@woocommerce/data';
import type { RefObject } from 'react';

const SNOOZE_SUPPRESSION_MS = 7 * 24 * 60 * 60 * 1000;
const suppressedCachedNotices = new WeakMap< WooPaymentsAdminNotice, number >();

const isCachedNoticeSuppressed = ( notice: WooPaymentsAdminNotice ) => {
	const expiresAt = suppressedCachedNotices.get( notice );
	if ( expiresAt === undefined ) {
		return false;
	}
	if ( expiresAt > Date.now() ) {
		return true;
	}
	suppressedCachedNotices.delete( notice );
	return false;
};

const suppressCachedNotice = (
	notice: WooPaymentsAdminNotice,
	action: 'dismiss' | 'snooze' | 'cta'
) => {
	suppressedCachedNotices.set(
		notice,
		action === 'snooze' ? Date.now() + SNOOZE_SUPPRESSION_MS : Infinity
	);
};

interface WooPaymentsAdminNoticeItemProps {
	notice: WooPaymentsAdminNotice;
	focusTargetRef: RefObject< HTMLElement >;
	onDismiss: () => void;
}

/**
 * Render one WooPayments notice on the Payments settings page.
 */
export const WooPaymentsAdminNoticeItem = ( {
	notice,
	focusTargetRef,
	onDismiss,
}: WooPaymentsAdminNoticeItemProps ) => {
	const [ pendingAction, setPendingAction ] = useState< string | null >(
		null
	);
	const shownKey = notice.id;
	const shownRef = useRef< string | null >( null );
	const noticeRef = useRef< HTMLDivElement >( null );
	const isSuppressed = isCachedNoticeSuppressed( notice );
	const { createErrorNotice } = dispatch( 'core/notices' );
	const { invalidateResolutionForStoreSelector } =
		useDispatch( paymentSettingsStore );

	useEffect( () => {
		if ( isSuppressed || shownRef.current === shownKey ) {
			return;
		}
		shownRef.current = shownKey;
		// A background request the merchant did not ask for, so a failure is not theirs to retry.
		void apiFetch( {
			url: notice._links.shown.href,
			method: 'POST',
		} ).catch( () => undefined );
	}, [ isSuppressed, shownKey, notice._links.shown.href ] );

	const finish = () => {
		// Move focus only when it is still on the notice, or was lost with it; a user who moved on keeps their place.
		const ownerDocument = focusTargetRef.current?.ownerDocument ?? document;
		const activeElement = ownerDocument.activeElement;
		if (
			! activeElement ||
			activeElement === ownerDocument.body ||
			noticeRef.current?.contains( activeElement )
		) {
			focusTargetRef.current?.focus();
		}
		onDismiss();
	};

	const refreshPaymentProviders = async () => {
		void invalidateResolutionForStoreSelector( 'getPaymentProviders' );
		try {
			await resolveSelect( paymentSettingsStore ).getPaymentProviders();
			suppressedCachedNotices.delete( notice );
		} catch {
			// Keep the acted-on cached notice suppressed until a later refresh replaces it.
		}
	};

	const postAction = async (
		action: 'dismiss' | 'snooze' | 'cta',
		onSuccess: () => void = finish
	) => {
		if ( pendingAction ) {
			return;
		}
		const href = notice._links[ action ]?.href;
		if ( ! href ) {
			return;
		}
		setPendingAction( action );
		try {
			await apiFetch( {
				url: href,
				method: 'POST',
			} );
			suppressCachedNotice( notice, action );
			void refreshPaymentProviders();
			onSuccess();
		} catch {
			createErrorNotice(
				__(
					'We could not update this notice. Please try again.',
					'woocommerce'
				),
				{ type: 'snackbar', explicitDismiss: true }
			);
			setPendingAction( null );
		}
	};

	if ( isSuppressed ) {
		return null;
	}
	const spokenMessage = sprintf(
		/* translators: %s: Payment notice message. */
		__( 'Notice: %s', 'woocommerce' ),
		notice.message
	);

	return (
		<div ref={ noticeRef }>
			<Notice
				status="info"
				isDismissible={ false }
				spokenMessage={ spokenMessage }
			>
				<p>{ notice.message }</p>
				{ notice.primary.kind === 'navigate_and_dismiss' && (
					<Button
						variant="primary"
						aria-disabled={ pendingAction !== null }
						onClick={ () => {
							const navigationHref = notice.primary.href;
							if ( ! navigationHref ) {
								return;
							}
							void postAction( 'cta', () => {
								finish();
								window.location.assign( navigationHref );
							} );
						} }
					>
						{ notice.primary.label }
					</Button>
				) }
				{ notice.secondary?.kind === 'snooze' && (
					<Button
						variant="secondary"
						isBusy={ pendingAction === 'snooze' }
						aria-disabled={ pendingAction !== null }
						onClick={ () => void postAction( 'snooze' ) }
					>
						{ notice.secondary.label }
					</Button>
				) }
				<Button
					variant="tertiary"
					aria-label={ __(
						'Dismiss WooPayments notice',
						'woocommerce'
					) }
					aria-disabled={ pendingAction !== null }
					isBusy={ pendingAction === 'dismiss' }
					onClick={ () => void postAction( 'dismiss' ) }
				>
					{ __( 'Dismiss', 'woocommerce' ) }
				</Button>
			</Notice>
		</div>
	);
};

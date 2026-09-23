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
import type { MouseEvent, RefObject } from 'react';

/**
 * Internal dependencies
 */
import { recordPaymentsEvent } from '~/settings-payments/utils';
import {
	wooPaymentsExtensionSlug,
	wooPaymentsProviderId,
	wooPaymentsSuggestionId,
} from '~/settings-payments/constants';

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
	action: 'dismiss' | 'snooze'
) => {
	suppressedCachedNotices.set(
		notice,
		action === 'snooze' ? Date.now() + SNOOZE_SUPPRESSION_MS : Infinity
	);
};

interface WooPaymentsAdminNoticesProps {
	notice: WooPaymentsAdminNotice;
	focusTargetRef: RefObject< HTMLElement >;
	onDismiss: () => void;
}

/**
 * Render one native WooPayments notice on the Payments settings page.
 */
export const WooPaymentsAdminNotices = ( {
	notice,
	focusTargetRef,
	onDismiss,
}: WooPaymentsAdminNoticesProps ) => {
	const [ pendingAction, setPendingAction ] = useState< string | null >(
		null
	);
	const shownKey = `${ notice.id }:${ notice.stage ?? '' }`;
	const shownRef = useRef< string | null >( null );
	const isSuppressed = isCachedNoticeSuppressed( notice );
	const { createSuccessNotice, createErrorNotice } =
		dispatch( 'core/notices' );
	const { invalidateResolutionForStoreSelector } =
		useDispatch( paymentSettingsStore );

	useEffect( () => {
		if ( isSuppressed || shownRef.current === shownKey ) {
			return;
		}
		shownRef.current = shownKey;
		void apiFetch( {
			url: notice._links.shown.href,
			method: 'POST',
			data: notice.stage ? { stage: notice.stage } : undefined,
		} ).catch( () => {
			createErrorNotice(
				__(
					'We could not update this notice. Please try again.',
					'woocommerce'
				),
				{ type: 'snackbar', explicitDismiss: true }
			);
		} );
	}, [
		isSuppressed,
		shownKey,
		notice._links.shown.href,
		notice.stage,
		createErrorNotice,
	] );

	const finish = () => {
		focusTargetRef.current?.focus();
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
		action: 'dismiss' | 'snooze',
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
				data: notice.stage ? { stage: notice.stage } : undefined,
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

	const enableLivePayments = async () => {
		if ( pendingAction ) {
			return;
		}
		setPendingAction( 'primary' );
		recordPaymentsEvent( 'reactivate_payments_button_click', {
			provider_id: wooPaymentsProviderId,
			provider_extension_slug: wooPaymentsExtensionSlug,
			suggestion_id: wooPaymentsSuggestionId,
		} );

		try {
			await apiFetch( {
				path: '/wc/v3/payments/settings',
				method: 'POST',
				data: {
					is_test_mode_enabled: false,
				},
			} );
			createSuccessNotice(
				sprintf(
					/* translators: %s: WooPayments */
					__(
						'%s is now processing live payments (real payment methods and charges).',
						'woocommerce'
					),
					'WooPayments'
				),
				{ type: 'snackbar', explicitDismiss: false }
			);
			suppressCachedNotice( notice, 'dismiss' );
			void refreshPaymentProviders();
			setPendingAction( null );
			finish();
		} catch {
			setPendingAction( null );
			recordPaymentsEvent( 'reactivate_payments_error', {
				provider_id: wooPaymentsProviderId,
				provider_extension_slug: wooPaymentsExtensionSlug,
				suggestion_id: wooPaymentsSuggestionId,
			} );
			createErrorNotice(
				__(
					'We could not turn on live payments. Please try again.',
					'woocommerce'
				),
				{ type: 'snackbar', explicitDismiss: true }
			);
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
		<div>
			<Notice
				status="info"
				isDismissible={ false }
				spokenMessage={ spokenMessage }
			>
				<p>{ notice.message }</p>
				{ notice.primary.kind === 'disable_test_mode' && (
					<Button
						variant="primary"
						isBusy={ pendingAction === 'primary' }
						aria-disabled={ pendingAction !== null }
						onClick={ () => void enableLivePayments() }
					>
						{ notice.primary.label }
					</Button>
				) }
				{ notice.primary.kind === 'onboard' && (
					<Button
						variant="primary"
						href={ notice.primary.href }
						aria-disabled={ pendingAction !== null }
						onClick={ ( event: MouseEvent ) => {
							if ( pendingAction !== null ) {
								event.preventDefault();
							}
						} }
					>
						{ notice.primary.label }
					</Button>
				) }
				{ notice.primary.kind === 'navigate_and_dismiss' && (
					<Button
						variant="primary"
						aria-disabled={ pendingAction !== null }
						onClick={ () => {
							const navigationHref = notice.primary.href;
							if ( ! navigationHref ) {
								return;
							}
							void postAction( 'dismiss', () => {
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

/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { dispatch, useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { paymentSettingsStore } from '@woocommerce/data';
import type { WooPaymentsAdminNotice } from '@woocommerce/data';
import type { MouseEvent, RefObject } from 'react';

/**
 * Internal dependencies
 */
import { ReactivateLivePaymentsButton } from '../buttons/reactivate-live-payments-button';

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
	const { createErrorNotice } = dispatch( 'core/notices' );
	const { invalidateResolutionForStoreSelector } =
		useDispatch( paymentSettingsStore );

	useEffect( () => {
		if ( shownRef.current === shownKey ) {
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
		shownKey,
		notice._links.shown.href,
		notice.stage,
		createErrorNotice,
	] );

	const finish = () => {
		focusTargetRef.current?.focus();
		onDismiss();
	};

	const postAction = async ( action: 'dismiss' | 'snooze' ) => {
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
			void invalidateResolutionForStoreSelector( 'getPaymentProviders' );
			finish();
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

	return (
		<div>
			<Notice status="info" isDismissible={ false }>
				<p>{ notice.message }</p>
				{ notice.primary.kind === 'disable_test_mode' && (
					<ReactivateLivePaymentsButton
						buttonText={ notice.primary.label }
						settingsHref={ notice.primary.href ?? '' }
						asButton
						onSuccess={ finish }
						onUpdatingChange={ ( updating ) =>
							setPendingAction( updating ? 'primary' : null )
						}
						disabled={ pendingAction !== null }
					/>
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

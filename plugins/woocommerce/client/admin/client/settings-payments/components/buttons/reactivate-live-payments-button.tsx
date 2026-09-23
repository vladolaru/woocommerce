/**
 * External dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch, useDispatch } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';
import apiFetch from '@wordpress/api-fetch';
import type { MouseEvent } from 'react';

/**
 * Internal dependencies
 */
import { recordPaymentsEvent } from '~/settings-payments/utils';
import {
	wooPaymentsExtensionSlug,
	wooPaymentsProviderId,
	wooPaymentsSuggestionId,
} from '~/settings-payments/constants';

interface ReactivateLivePaymentsButtonProps {
	/**
	 * The text of the button.
	 */
	buttonText?: string;
	/**
	 * The settings URL to navigate to when the enable gateway button is clicked.
	 */
	settingsHref: string;
	/**
	 * Called after live mode is enabled successfully.
	 */
	onSuccess?: () => void;
	/**
	 * Reports whether the live-mode request is pending.
	 */
	onUpdatingChange?: ( isUpdating: boolean ) => void;
	/**
	 * Prevents activation while another notice action is pending.
	 */
	disabled?: boolean;
	/**
	 * Renders a button when navigation is not the control's primary action.
	 */
	asButton?: boolean;
}

/**
 * A button component that allows users to disable test mode payments (only for WooPayments at the moment).
 */
export const ReactivateLivePaymentsButton = ( {
	buttonText = __( 'Reactivate payments', 'woocommerce' ),
	settingsHref,
	onSuccess,
	onUpdatingChange,
	disabled = false,
	asButton = false,
}: ReactivateLivePaymentsButtonProps ) => {
	const [ isUpdating, setIsUpdating ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		dispatch( 'core/notices' );
	const { invalidateResolutionForStoreSelector } =
		useDispatch( paymentSettingsStore );

	const disableTestModePayments = ( e: MouseEvent ) => {
		e.preventDefault();
		if ( isUpdating || disabled ) {
			return;
		}
		setIsUpdating( true );
		onUpdatingChange?.( true );

		recordPaymentsEvent( 'reactivate_payments_button_click', {
			provider_id: wooPaymentsProviderId,
			provider_extension_slug: wooPaymentsExtensionSlug,
			suggestion_id: wooPaymentsSuggestionId,
		} );

		apiFetch( {
			path: '/wc/v3/payments/settings',
			method: 'POST',
			data: {
				is_test_mode_enabled: false,
			},
		} )
			.then( () => {
				createSuccessNotice(
					sprintf(
						/* translators: %s: WooPayments */
						__(
							'%s is now processing live payments (real payment methods and charges).',
							'woocommerce'
						),
						'WooPayments'
					),
					{
						type: 'snackbar',
						explicitDismiss: false,
					}
				);

				// Note: Switching from test to live payments is tracked on the backend (the `provider_live_payments_enabled` event).

				// Force the providers to be refreshed.
				void invalidateResolutionForStoreSelector(
					'getPaymentProviders'
				);
				setIsUpdating( false );
				onUpdatingChange?.( false );
				onSuccess?.();
			} )
			.catch( () => {
				// In case of errors, redirect to the gateway settings page.
				setIsUpdating( false );
				onUpdatingChange?.( false );

				recordPaymentsEvent( 'reactivate_payments_error', {
					provider_id: wooPaymentsProviderId,
					provider_extension_slug: wooPaymentsExtensionSlug,
					suggestion_id: wooPaymentsSuggestionId,
				} );

				createErrorNotice(
					sprintf(
						/* translators: %s: WooPayments */
						__(
							'An error occurred. You will be redirected to the %s settings page to manage payments processing mode from there.',
							'woocommerce'
						),
						'WooPayments'
					),
					{
						type: 'snackbar',
						explicitDismiss: true,
					}
				);

				window.location.href = settingsHref;
			} );
	};

	return (
		<Button
			variant={ 'primary' }
			isBusy={ isUpdating }
			disabled={ isUpdating || disabled }
			aria-disabled={ isUpdating || disabled }
			onClick={ disableTestModePayments }
			href={ asButton ? undefined : settingsHref }
		>
			{ buttonText }
		</Button>
	);
};

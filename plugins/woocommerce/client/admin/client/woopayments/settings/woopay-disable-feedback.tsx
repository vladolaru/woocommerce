/**
 * External dependencies
 */
import { Modal, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WooPayLogoImage from './express-checkout/assets/woopay-preview-logo.svg';
import { useDeferredStatusText } from './settings-busy-state';

export const WOOPAY_DISABLE_FEEDBACK_URL =
	'https://woocommerce.survey.fm/woopay-disabled-merchants-feedback-triggered';

export const WooPayDisableFeedback = ( {
	onRequestClose,
}: {
	onRequestClose: () => void;
} ) => {
	const [ isLoading, setIsLoading ] = useState( true );
	const loadingStatus = useDeferredStatusText(
		isLoading ? __( 'Loading feedback form…', 'woocommerce' ) : ''
	);

	return (
		<Modal
			// Client 11.1.0 settings/woopay-disable-feedback/index.js:15-23 shows the WooPay logo in place of a text title.
			icon={
				<img
					src={ WooPayLogoImage }
					alt=""
					className="woopayments-woopay-disable-feedback__logo"
				/>
			}
			contentLabel={ __( 'WooPay feedback', 'woocommerce' ) }
			isDismissible
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc
			onRequestClose={ onRequestClose }
			className="woopayments-woopay-disable-feedback"
		>
			<div className="woopayments-woopay-disable-feedback__body">
				<p
					className={
						loadingStatus
							? 'woopayments-woopay-disable-feedback__status'
							: 'screen-reader-text'
					}
					role="status"
					aria-live="polite"
				>
					{ loadingStatus && (
						<>
							<Spinner />
							{ loadingStatus }
						</>
					) }
				</p>
				<iframe
					title={ __( 'WooPay disable feedback', 'woocommerce' ) }
					src={ WOOPAY_DISABLE_FEEDBACK_URL }
					className="woopayments-woopay-disable-feedback__iframe"
					onLoad={ () => setIsLoading( false ) }
				/>
			</div>
		</Modal>
	);
};

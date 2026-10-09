/**
 * External dependencies
 */
import { useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import type { PaymentsProviderNotice } from '@woocommerce/data';

type ProviderNoticeProps = {
	/**
	 * The notice the provider reported, if any.
	 */
	notice?: PaymentsProviderNotice;
	/**
	 * Called when the merchant dismisses the notice. Without it, the notice
	 * requests the dismissal URL the provider reported.
	 */
	onDismiss?: () => void;
};

/**
 * A notice under a provider row, such as the PayPal Wallet setup reminder.
 *
 * This is the proof of concept slot of the provider list: the provider data
 * carries the title, text, action and dismissal, and the item renders them.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.notice    The provider's notice.
 * @param {Function} props.onDismiss Optional dismiss handler.
 */
export const ProviderNotice = ( {
	notice,
	onDismiss,
}: ProviderNoticeProps ) => {
	const [ isDismissed, setIsDismissed ] = useState( false );

	if ( ! notice || isDismissed ) {
		return null;
	}

	const dismiss = () => {
		setIsDismissed( true );

		if ( onDismiss ) {
			onDismiss();
			return;
		}

		if ( notice.dismiss_url ) {
			// The dismissal is stored server-side; the notice is already hidden here.
			window
				.fetch( notice.dismiss_url, {
					method: 'POST',
					credentials: 'same-origin',
				} )
				.catch( () => {} );
		}
	};

	return (
		<div className="woocommerce-list__item-notice">
			<Notice
				status="warning"
				isDismissible={ notice.dismissible }
				onRemove={ dismiss }
				actions={ [
					{
						label: notice.action_label,
						url: notice.action_url,
					},
				] }
			>
				<strong className="woocommerce-list__item-notice-title">
					{ notice.title }
				</strong>
				<p className="woocommerce-list__item-notice-text">
					{ notice.text }
				</p>
			</Notice>
		</div>
	);
};

/**
 * External dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import type { PaymentsProviderNotice } from '@woocommerce/data';

type ProviderNoticeProps = {
	/**
	 * The notice the provider reported, if any.
	 */
	notice?: PaymentsProviderNotice;
};

const FOCUSABLE = 'button, a[href], [tabindex]:not([tabindex="-1"])';

/**
 * A notice under a provider row, such as the PayPal Wallet setup reminder.
 *
 * This is the proof of concept slot of the provider list: the provider data
 * carries the title, text, action and dismissal, and the item renders them.
 * A dismissal hides the notice at once and stores it through the dismissal
 * URL; a refusal or a failed request brings the notice back.
 *
 * @param {Object} props        Component props.
 * @param {Object} props.notice The provider's notice.
 */
export const ProviderNotice = ( { notice }: ProviderNoticeProps ) => {
	const [ isDismissed, setIsDismissed ] = useState( false );
	const [ failures, setFailures ] = useState( 0 );
	const wrapper = useRef< HTMLDivElement >( null );

	useEffect( () => {
		if ( failures === 0 ) {
			return;
		}
		const button = wrapper.current?.querySelector< HTMLElement >(
			'.components-notice__dismiss'
		);
		button?.focus();
	}, [ failures ] );

	if ( ! notice || isDismissed ) {
		return null;
	}

	const focusRow = () => {
		const item = wrapper.current?.closest( '.woocommerce-list__item' );
		const actions = item?.querySelector(
			'.woocommerce-list__item-after__actions'
		);
		const target =
			actions?.querySelector< HTMLElement >( FOCUSABLE ) ??
			item?.querySelector< HTMLElement >( FOCUSABLE );
		target?.focus();
	};

	const dismiss = () => {
		focusRow();
		setIsDismissed( true );

		if ( ! notice.dismiss_url ) {
			return;
		}

		window
			.fetch( notice.dismiss_url, {
				method: 'POST',
				credentials: 'same-origin',
			} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
				speak( __( 'Notice dismissed.', 'woocommerce' ) );
			} )
			.catch( () => {
				setIsDismissed( false );
				setFailures( ( count ) => count + 1 );
				speak(
					__( 'The notice could not be dismissed.', 'woocommerce' ),
					'assertive'
				);
			} );
	};

	return (
		<div className="woocommerce-list__item-notice" ref={ wrapper }>
			<Notice
				status="warning"
				isDismissible={ notice.dismissible }
				onRemove={ dismiss }
				actions={ [
					{
						label: notice.action_label,
						url: notice.action_url,
						// A button, as the row's own action is; without this the Notice turns a URL action into a link.
						variant: 'secondary',
						noDefaultClasses: true,
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

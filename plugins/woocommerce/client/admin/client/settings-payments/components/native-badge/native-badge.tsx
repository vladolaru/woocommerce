/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { Popover } from '@wordpress/components';
import { Link, Pill } from '@woocommerce/components';
import { createInterpolateElement, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';
import { recordPaymentsEvent } from '~/settings-payments/utils';

const LEARN_MORE_URL = 'https://woocommerce.com/payments/';

interface NativeBadgeProps {
	/**
	 * The id of the suggestion matching the native provider.
	 */
	suggestionId: string;
}

/**
 * A badge for a payments provider built into WooCommerce, such as native WooPayments.
 *
 * It looks and behaves like the official badge and records the same Tracks events.
 *
 * @example
 * <NativeBadge suggestionId="some_id" />
 */
export const NativeBadge = ( { suggestionId }: NativeBadgeProps ) => {
	const [ isPopoverVisible, setPopoverVisible ] = useState( false );
	const buttonRef = useRef< HTMLButtonElement >( null );

	const handleClick = ( event: React.MouseEvent | React.KeyboardEvent ) => {
		const clickedElement = event.target as HTMLElement;
		const parentSpan = clickedElement.closest(
			'.woocommerce-official-extension-badge__container'
		);

		if ( buttonRef.current && parentSpan !== buttonRef.current ) {
			return;
		}

		setPopoverVisible( ( prev ) => ! prev );

		recordPaymentsEvent( 'official_badge_click', {
			suggestion_id: suggestionId,
		} );
	};

	const handleFocusOutside = () => {
		setPopoverVisible( false );
	};

	const handleKeyDown = ( event: React.KeyboardEvent ) => {
		if ( event.key === 'Escape' && isPopoverVisible ) {
			event.stopPropagation();
			setPopoverVisible( false );
			buttonRef.current?.focus();
		} else if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			handleClick( event );
		}
	};

	return (
		<Pill className="woocommerce-official-extension-badge">
			<span
				className="woocommerce-official-extension-badge__container"
				tabIndex={ 0 }
				role="button"
				ref={ buttonRef }
				onClick={ handleClick }
				onKeyDown={ handleKeyDown }
			>
				<img
					src={ WC_ASSET_URL + 'images/icons/official-extension.svg' }
					alt={ __(
						'Native WooCommerce payments badge',
						'woocommerce'
					) }
				/>
				<span>{ __( 'Native', 'woocommerce' ) }</span>
				{ isPopoverVisible && (
					<Popover
						className="woocommerce-official-extension-badge-popover"
						placement="top-start"
						offset={ 4 }
						variant="unstyled"
						focusOnMount={ true }
						noArrow={ true }
						shift={ true }
						onFocusOutside={ handleFocusOutside }
						onKeyDown={ handleKeyDown }
					>
						{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions */ }
						<div
							className="components-popover__content-container"
							onKeyDown={ handleKeyDown }
						>
							<p>
								{ createInterpolateElement(
									__(
										'This is a native WooCommerce payments solution. <learnMoreLink />',
										'woocommerce'
									),
									{
										learnMoreLink: (
											<Link
												href={ LEARN_MORE_URL }
												target="_blank"
												rel="noreferrer"
												type="external"
												onClick={ () => {
													recordPaymentsEvent(
														'official_badge_learn_more_click',
														{
															suggestion_id:
																suggestionId,
														}
													);
												} }
											>
												{ __(
													'Learn more',
													'woocommerce'
												) }
											</Link>
										),
									}
								) }
							</p>
						</div>
					</Popover>
				) }
			</span>
		</Pill>
	);
};

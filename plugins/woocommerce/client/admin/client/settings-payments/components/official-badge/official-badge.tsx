/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { Popover } from '@wordpress/components';
import { Pill } from '@woocommerce/components';
import { Link } from '@wordpress/ui';
import { createInterpolateElement, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';
import { recordPaymentsEvent } from '~/settings-payments/utils';

interface OfficialBadgeProps {
	/**
	 * The style of the badge.
	 */
	variant: 'expanded' | 'compact';

	/**
	 * The id of the official suggestion.
	 */
	suggestionId: string;

	/**
	 * The badge label. Defaults to "Official".
	 */
	label?: string;

	/**
	 * The alternative text of the badge image.
	 */
	imageAlt?: string;

	/**
	 * The popover sentence, with a `<learnMoreLink />` placeholder for the "Learn more" link.
	 */
	popoverText?: string;

	/**
	 * The "Learn more" link URL.
	 */
	learnMoreUrl?: string;

	/**
	 * A test id for the badge trigger. Omitted when not set.
	 */
	testId?: string;
}

/**
 * A component that displays an official badge.
 *
 * The style of the badge can be either "expanded" or "compact".
 *
 * @example
 * // Render an official badge with icon and text.
 * <OfficialBadge variant="expanded" suggestionId="some_id" />
 *
 * @example
 * // Render an official badge with just the icon.
 * <OfficialBadge variant="compact" suggestionId="some_id" />
 */
export const OfficialBadge = ( {
	variant,
	suggestionId,
	label = __( 'Official', 'woocommerce' ),
	imageAlt = __( 'Official WooCommerce extension badge', 'woocommerce' ),
	popoverText = __(
		'This is an Official WooCommerce payment extension. <learnMoreLink />',
		'woocommerce'
	),
	learnMoreUrl = 'https://woocommerce.com/learn-more-about-official-partner-badging/',
	testId,
}: OfficialBadgeProps ) => {
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

		// Record the event when the user clicks on the badge.
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
		} else if (
			( event.key === 'Enter' || event.key === ' ' ) &&
			event.target === buttonRef.current
		) {
			event.preventDefault();
			handleClick( event );
		}
	};

	return (
		<Pill className={ `woocommerce-official-extension-badge` }>
			<span
				className="woocommerce-official-extension-badge__container"
				data-testid={ testId }
				tabIndex={ 0 }
				role="button"
				ref={ buttonRef }
				onClick={ handleClick }
				onKeyDown={ handleKeyDown }
			>
				<img
					src={ WC_ASSET_URL + 'images/icons/official-extension.svg' }
					alt={ imageAlt }
				/>
				{ variant === 'expanded' && <span>{ label }</span> }
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
								{ createInterpolateElement( popoverText, {
									learnMoreLink: (
										<Link
											href={ learnMoreUrl }
											rel="noopener noreferrer"
											openInNewTab
											onClick={ () => {
												// Record the event when the user clicks on the learn more link.
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
								} ) }
							</p>
						</div>
					</Popover>
				) }
			</span>
		</Pill>
	);
};

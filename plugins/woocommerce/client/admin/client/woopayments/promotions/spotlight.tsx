/**
 * External dependencies
 */
import { speak } from '@wordpress/a11y';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	CardMedia,
	Flex,
	Icon,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import {
	RawHTML,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { registerWooPaymentsPmPromotionsStore } from './data/register';
import { usePmPromotionActions, usePmPromotions } from './data/hooks';
import type { PmPromotion } from './types';
import './style.scss';

// Client 11.1.0 `components/spotlight/index.tsx:30-49`, `66` and `140`.
const BADGE_TYPES = [ 'primary', 'success', 'light', 'warning', 'alert' ];
const SHOW_DELAY_MS = 4000;
const CLOSE_ANIMATION_MS = 300;

const getSafeUrl = ( url?: string ) => {
	if ( ! url ) {
		return '';
	}

	try {
		const parsedUrl = new URL( url );

		return [ 'http:', 'https:' ].includes( parsedUrl.protocol ) ? url : '';
	} catch {
		return '';
	}
};

const getNativeRoutePath = () => {
	try {
		return (
			new URLSearchParams( window.location.search ).get( 'path' ) || ''
		);
	} catch {
		return '';
	}
};

const getPageSource = () => {
	const currentPath = window.location.pathname + window.location.search;
	const routePath = getNativeRoutePath();
	const path = routePath || currentPath;

	if ( path.includes( '/woopayments/overview' ) ) {
		return 'wcpay-overview';
	}
	if ( path.includes( '/woopayments/payouts' ) ) {
		return 'wcpay-payouts';
	}
	if ( path.includes( '/woopayments/transactions' ) ) {
		return 'wcpay-transactions';
	}
	if ( path.includes( '/woopayments/disputes' ) ) {
		return 'wcpay-disputes';
	}
	if ( path.includes( '/woopayments/settings' ) ) {
		return 'wcpay-settings';
	}
	if (
		currentPath.includes( 'page=wc-settings' ) &&
		currentPath.includes( 'tab=checkout' )
	) {
		return 'wc-settings-payments';
	}

	return 'unknown';
};

const getEventProperties = ( promotion: PmPromotion ) => ( {
	promo_id: promotion.promo_id,
	payment_method: promotion.payment_method,
	display_context: 'spotlight',
	source: getPageSource(),
} );

const FOCUSABLE_SELECTOR =
	'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * The payment method promotion spotlight.
 *
 * Client 11.1.0 `promotions/spotlight/index.tsx` over `components/spotlight/index.tsx`: a dialog that floats at the
 * bottom right 4 seconds after the page renders, takes focus, closes on Escape and gives focus back when it closes.
 */
export const SpotlightPromotion = () => {
	registerWooPaymentsPmPromotionsStore();

	const { pmPromotions, isLoading } = usePmPromotions();
	const { activatePmPromotion, dismissPmPromotion } = usePmPromotionActions();
	const headingId = useInstanceId(
		SpotlightPromotion,
		'woopayments-promotion-spotlight-heading'
	);
	const [ isVisible, setIsVisible ] = useState( false );
	const [ isAnimatingIn, setIsAnimatingIn ] = useState( false );
	const dialogRef = useRef< HTMLDivElement >( null );
	const closeTimeoutRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const spotlightPromotion = useMemo(
		() =>
			pmPromotions?.find(
				( promotion: PmPromotion ) => promotion.type === 'spotlight'
			),
		[ pmPromotions ]
	);
	const hasSpotlight = ! isLoading && !! spotlightPromotion;

	// Client 11.1.0 `components/spotlight/index.tsx:81-100`: the delay starts once the spotlight renders.
	useEffect( () => {
		if ( ! hasSpotlight ) {
			return;
		}

		const timer = setTimeout( () => {
			setIsVisible( true );
			// Two frames, so the browser paints the hidden state before the slide-in.
			window.requestAnimationFrame( () =>
				window.requestAnimationFrame( () => setIsAnimatingIn( true ) )
			);
		}, SHOW_DELAY_MS );

		return () => clearTimeout( timer );
	}, [ hasSpotlight ] );

	useEffect(
		() => () => {
			if ( closeTimeoutRef.current ) {
				clearTimeout( closeTimeoutRef.current );
			}
		},
		[]
	);

	// Client 11.1.0 `components/spotlight/index.tsx:112-129`.
	useEffect( () => {
		if ( ! isAnimatingIn || ! spotlightPromotion ) {
			return;
		}

		speak(
			sprintf(
				/* translators: %s: heading text of the spotlight dialog */
				__( 'Dialog opened: %s', 'woocommerce' ),
				spotlightPromotion.title
			),
			'polite'
		);
		recordEvent(
			'wcpay_payment_method_promotion_view',
			getEventProperties( spotlightPromotion )
		);
		// Once per appearance; the promotion object does not change while it shows.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isAnimatingIn ] );

	const handleClose = useCallback(
		( shouldDismiss = true ) => {
			setIsAnimatingIn( false );
			// Hide once the slide-out ends.
			closeTimeoutRef.current = setTimeout( () => {
				setIsVisible( false );
				if ( shouldDismiss && spotlightPromotion ) {
					dismissPmPromotion( spotlightPromotion.id );
				}
			}, CLOSE_ANIMATION_MS );
		},
		[ dismissPmPromotion, spotlightPromotion ]
	);

	// Client 11.1.0 `components/spotlight/index.tsx:145-201`: focus the dialog, trap Tab, close on Escape, restore focus.
	useEffect( () => {
		const dialog = dialogRef.current;

		if ( ! isVisible || ! dialog ) {
			return;
		}

		const ownerDocument = dialog.ownerDocument;
		const previouslyFocused = ownerDocument.activeElement as HTMLElement;

		dialog.focus();

		const handleKeyDown = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				handleClose();
				return;
			}

			if ( event.key !== 'Tab' ) {
				return;
			}

			const focusable =
				dialog.querySelectorAll< HTMLElement >( FOCUSABLE_SELECTOR );
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			const active = ownerDocument.activeElement;

			if ( event.shiftKey && active === first ) {
				event.preventDefault();
				last?.focus();
			} else if ( ! event.shiftKey && active === last ) {
				event.preventDefault();
				first?.focus();
			}
		};

		ownerDocument.addEventListener( 'keydown', handleKeyDown );

		return () => {
			ownerDocument.removeEventListener( 'keydown', handleKeyDown );
			previouslyFocused?.focus?.();
		};
	}, [ isVisible, handleClose ] );

	if ( ! hasSpotlight || ! spotlightPromotion || ! isVisible ) {
		return null;
	}

	const eventProperties = getEventProperties( spotlightPromotion );
	const badgeType = BADGE_TYPES.includes(
		spotlightPromotion.badge_type ?? ''
	)
		? spotlightPromotion.badge_type
		: 'success';
	const badge = spotlightPromotion.badge_text && (
		<span
			className={ `woopayments-promotion-spotlight__badge is-${ badgeType }` }
		>
			{ spotlightPromotion.badge_text }
		</span>
	);
	const heading = (
		<h2
			id={ headingId }
			className="woopayments-promotion-spotlight__heading"
		>
			{ spotlightPromotion.title }
		</h2>
	);
	const image = spotlightPromotion.image;

	return (
		<div
			className={ `woopayments-promotion-spotlight${
				isAnimatingIn ? ' is-visible' : ''
			}` }
			data-testid="woopayments-promotion-spotlight"
		>
			<div
				ref={ dialogRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ headingId }
				tabIndex={ -1 }
				className="woopayments-promotion-spotlight__container"
			>
				<Card
					className={ `woopayments-promotion-spotlight__card${
						image ? ' has-image' : ''
					}` }
				>
					{ image && (
						<CardMedia className="woopayments-promotion-spotlight__image">
							<img
								src={ image }
								alt=""
								aria-hidden="true"
								role="presentation"
							/>
						</CardMedia>
					) }
					<CardHeader
						isBorderless
						size="small"
						className="woopayments-promotion-spotlight__header"
					>
						<Flex justify="space-between" align="center">
							{ /* Without an image the badge, or else the heading, sits in the header. */ }
							{ ! image && ( badge || heading ) }
							{ image && <span /> }
							<Button
								className="woopayments-promotion-spotlight__close"
								label={ __( 'Close', 'woocommerce' ) }
								// Client 11.1.0 `components/spotlight/index.tsx:303-309`: the glyph fills the 16px icon.
								icon={
									<Icon
										icon={ closeSmall }
										viewBox="6 4 12 14"
									/>
								}
								iconSize={ 16 }
								onClick={ () => {
									recordEvent(
										'wcpay_payment_method_promotion_dismiss_click',
										eventProperties
									);
									handleClose();
								} }
							/>
						</Flex>
					</CardHeader>
					<CardBody
						size="small"
						className="woopayments-promotion-spotlight__body"
					>
						{ image && badge }
						{ ( image || badge ) && heading }
						{ spotlightPromotion.description && (
							<RawHTML className="woopayments-promotion-spotlight__description">
								{ spotlightPromotion.description }
							</RawHTML>
						) }
						{ spotlightPromotion.footnote && (
							<RawHTML className="woopayments-promotion-spotlight__footnote">
								{ spotlightPromotion.footnote }
							</RawHTML>
						) }
					</CardBody>
					<CardFooter
						isBorderless
						size="small"
						className="woopayments-promotion-spotlight__footer"
					>
						<Flex justify="flex-start" gap={ 3 }>
							{ spotlightPromotion.tc_label && (
								<Button
									variant="tertiary"
									size="compact"
									onClick={ () => {
										recordEvent(
											'wcpay_payment_method_promotion_link_click',
											{
												...eventProperties,
												link_type: 'terms',
											}
										);
										const termsUrl = getSafeUrl(
											spotlightPromotion.tc_url
										);
										if ( termsUrl ) {
											window.open(
												termsUrl,
												'_blank',
												'noopener,noreferrer'
											);
										}
									} }
								>
									{ spotlightPromotion.tc_label }
								</Button>
							) }
							<Button
								variant="primary"
								size="compact"
								onClick={ () => {
									recordEvent(
										'wcpay_payment_method_promotion_activate_click',
										eventProperties
									);
									activatePmPromotion(
										spotlightPromotion.id
									);
									// The platform dismisses an activated promotion itself.
									handleClose( false );
								} }
							>
								{ spotlightPromotion.cta_label ||
									__( 'Activate', 'woocommerce' ) }
							</Button>
						</Flex>
					</CardFooter>
				</Card>
			</div>
		</div>
	);
};

export default SpotlightPromotion;

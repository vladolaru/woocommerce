/**
 * External dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Icon,
	chevronDown,
	chevronUp,
	comment,
	envelope,
	page,
} from '@wordpress/icons';
import InfoOutlineIcon from 'gridicons/dist/info-outline';
import type { ElementType, ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import type { WooPaymentsBillingDetails, WooPaymentsDispute } from './types';
import { formatExplicitCurrency, formatSiteDateTime } from './utils';

// Client 11.1.0 `components/inline-notice`: the info-outline gridicon; the shared typings omit its class name.
const InfoIcon = InfoOutlineIcon as ElementType< { className?: string } >;

/**
 * A collapsible panel with a title, a subtitle and a chevron. Client 11.1.0 `components/accordion` (`lg` body).
 *
 * @param props              Component props.
 * @param props.title        Panel title.
 * @param props.subtitle     Text under the title.
 * @param props.subtitleNode Text under the toggle, shown only when open; for content with links.
 * @param props.initialOpen  Whether the panel starts open.
 * @param props.className    Extra class name.
 * @param props.children     Panel content.
 */
export const WooPaymentsAccordion = ( {
	title,
	subtitle,
	subtitleNode,
	initialOpen = false,
	className = '',
	children,
}: {
	title: string;
	subtitle?: ReactNode;
	subtitleNode?: ReactNode;
	initialOpen?: boolean;
	className?: string;
	children: ReactNode;
} ) => {
	const [ isOpen, setIsOpen ] = useState( initialOpen );

	return (
		<div
			className={ `woocommerce-woopayments-accordion ${ className }${
				isOpen ? ' is-opened' : ''
			}` }
		>
			<h2 className="woocommerce-woopayments-accordion__title">
				<Button
					className="woocommerce-woopayments-accordion__toggle"
					aria-expanded={ isOpen }
					onClick={ () => setIsOpen( ! isOpen ) }
				>
					<span className="woocommerce-woopayments-accordion__title-content">
						{ title }
						{ subtitle && (
							<span className="woocommerce-woopayments-accordion__subtitle">
								{ subtitle }
							</span>
						) }
					</span>
					<Icon
						className="woocommerce-woopayments-accordion__arrow"
						icon={ isOpen ? chevronUp : chevronDown }
					/>
				</Button>
			</h2>
			{ /* Client 11.1.0 `components/accordion/body.tsx:98-106`: links cannot sit inside the toggle. */ }
			{ subtitleNode && isOpen && (
				<div className="woocommerce-woopayments-accordion__subtitle woocommerce-woopayments-accordion__subtitle--external">
					{ subtitleNode }
				</div>
			) }
			{ isOpen && (
				<div className="woocommerce-woopayments-accordion__content">
					{ children }
				</div>
			) }
		</div>
	);
};

/**
 * One row of "Steps you can take": icon, title, description and an optional action.
 * Client 11.1.0 `components/dispute-step-item`.
 *
 * @param props             Component props.
 * @param props.icon        Icon in the 44px box.
 * @param props.title       Row title.
 * @param props.description Row description.
 * @param props.action      Right-aligned action.
 * @param props.className   Extra class name.
 * @param props.as          Wrapper element.
 * @param props.titleAs     Title element.
 * @param props.titlePrefix Screen reader prefix for the title.
 */
export const WooPaymentsDisputeStepItem = ( {
	icon,
	title,
	description,
	action,
	className = '',
	as: Tag = 'div',
	titleAs: TitleTag = 'div',
	titlePrefix,
}: {
	icon: ReactElement;
	title: string;
	description: string;
	action?: ReactNode;
	className?: string;
	as?: 'div' | 'article';
	titleAs?: 'div' | 'h3';
	titlePrefix?: string;
} ) => (
	<Tag className={ `woocommerce-woopayments-dispute-step ${ className }` }>
		<div
			className="woocommerce-woopayments-dispute-step__icon"
			aria-hidden="true"
		>
			{ icon }
		</div>
		<div className="woocommerce-woopayments-dispute-step__content">
			<TitleTag className="woocommerce-woopayments-dispute-step__name">
				{ titlePrefix && (
					<span className="screen-reader-text">{ `${ titlePrefix } ` }</span>
				) }
				{ title }
			</TitleTag>
			<div className="woocommerce-woopayments-dispute-step__description">
				{ description }
			</div>
		</div>
		{ action && (
			<div className="woocommerce-woopayments-dispute-step__action">
				{ action }
			</div>
		) }
	</Tag>
);

const LearnMoreButton = ( { href }: { href: string } ) => (
	<Button
		variant="secondary"
		href={ href }
		target="_blank"
		rel="noopener noreferrer"
	>
		{ __( 'Learn more', 'woocommerce' ) + ' ' }
		&#8599;
	</Button>
);

const OutcomeNotice = ( { children }: { children: string } ) => (
	<Notice
		status="info"
		isDismissible={ false }
		className="woocommerce-woopayments-dispute-steps__notice"
	>
		<InfoIcon className="woocommerce-woopayments-dispute-steps__notice-icon" />
		<span>
			{ createInterpolateElement( children, { strong: <strong /> } ) }
		</span>
	</Notice>
);

type StepsProps = {
	dispute: WooPaymentsDispute;
	customer?: WooPaymentsBillingDetails;
	chargeCreated?: number | string;
	bankName?: string;
};

// Client 11.1.0 `dispute-steps.tsx`: a prefilled email to the customer.
const getEmailLink = (
	{ dispute, customer, chargeCreated }: StepsProps,
	isInquiry: boolean
) => {
	if ( ! customer?.email ) {
		return undefined;
	}

	const chargeDate = formatSiteDateTime( chargeCreated, isInquiry );
	const disputeDate = formatSiteDateTime( dispute.created, isInquiry );
	const subject = sprintf(
		/* translators: 1: store name, 2: charge date. */
		__( 'Problem with your purchase from %1$s on %2$s?', 'woocommerce' ),
		window.wcSettings?.siteTitle || '',
		chargeDate
	);
	// The client's single translated body, split into its paragraphs.
	const body = [
		sprintf(
			/* translators: %s: customer name. */
			__( 'Hello %s,', 'woocommerce' ),
			customer.name || ''
		),
		isInquiry
			? sprintf(
					/* translators: 1: inquiry date, 2: disputed amount, 3: charge date. */
					__(
						"We noticed that on %1$s, you raised a question with your payment provider about a %2$s charge made on %3$s. We wanted to reach out to ensure everything is all right with your purchase and to see if there's anything we can do to resolve any problems you might have had.",
						'woocommerce'
					),
					disputeDate,
					formatExplicitCurrency( dispute.amount, dispute.currency ),
					chargeDate
			  )
			: sprintf(
					/* translators: 1: dispute date, 2: disputed amount, 3: charge date. */
					__(
						"We noticed that on %1$s, you disputed a %2$s charge on %3$s. We wanted to contact you to make sure everything was all right with your purchase and see if there's anything else we can do to resolve any problems you might have had.",
						'woocommerce'
					),
					disputeDate,
					formatExplicitCurrency( dispute.amount, dispute.currency ),
					chargeDate
			  ),
		isInquiry
			? __(
					'Alternatively, if this was a mistake, please contact your payment provider to resolve it. Thank you so much - we appreciate your business and look forward to working with you.',
					'woocommerce'
			  )
			: __(
					'Alternatively, if the dispute was a mistake, you can easily withdraw it by calling the number on the back of your card. Thank you so much - we appreciate your business and look forward to working with you.',
					'woocommerce'
			  ),
	].join( '\n\n' );

	return `mailto:${ customer.email }?subject=${ encodeURIComponent(
		subject
	) }&body=${ encodeURIComponent( body ) }`;
};

const ContactCustomerStep = ( {
	emailLink,
	description = __(
		'Identify the issue and work towards a resolution where possible.',
		'woocommerce'
	),
}: {
	emailLink?: string;
	description?: string;
} ) => (
	<WooPaymentsDisputeStepItem
		icon={ <Icon icon={ envelope } /> }
		title={ __( 'Contact your customer', 'woocommerce' ) }
		description={ description }
		action={
			emailLink ? (
				<Button
					variant="secondary"
					href={ emailLink }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Email customer', 'woocommerce' ) }
				</Button>
			) : null
		}
	/>
);

const getOutcomeText = ( bankName: string | undefined, isInquiry: boolean ) => {
	if ( isInquiry ) {
		return bankName
			? sprintf(
					/* translators: %s: the customer's bank. */
					__(
						'<strong>The outcome of this inquiry will be determined by %s.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
						'woocommerce'
					),
					bankName
			  )
			: __(
					"<strong>The outcome of this inquiry will be determined by the cardholder's bank.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.",
					'woocommerce'
			  );
	}

	return bankName
		? sprintf(
				/* translators: %s: the customer's bank. */
				__(
					'<strong>The outcome of this dispute will be determined by %s.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
					'woocommerce'
				),
				bankName
		  )
		: __(
				"<strong>The outcome of this dispute will be determined by the cardholder's bank.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.",
				'woocommerce'
		  );
};

const StepsPanel = ( {
	subtitle,
	initialOpen = false,
	children,
	notice,
}: {
	subtitle: string;
	initialOpen?: boolean;
	children: ReactNode;
	notice: string;
} ) => (
	<WooPaymentsAccordion
		className="woocommerce-woopayments-dispute-steps"
		title={ __( 'Steps you can take', 'woocommerce' ) }
		subtitle={ subtitle }
		initialOpen={ initialOpen }
	>
		<div className="woocommerce-woopayments-dispute-steps__items">
			{ children }
		</div>
		<OutcomeNotice>{ notice }</OutcomeNotice>
	</WooPaymentsAccordion>
);

/**
 * Client 11.1.0 `dispute-steps.tsx` `DisputeSteps`.
 *
 * @param props Component props.
 */
export const DisputeSteps = ( props: StepsProps ) => (
	<StepsPanel
		subtitle={ __(
			'We recommend reviewing your options before responding before the deadline.',
			'woocommerce'
		) }
		notice={ getOutcomeText( props.bankName, false ) }
	>
		<ContactCustomerStep emailLink={ getEmailLink( props, false ) } />
		<WooPaymentsDisputeStepItem
			icon={ <Icon icon={ comment } /> }
			title={ __( 'Ask for the dispute to be withdrawn', 'woocommerce' ) }
			description={ __(
				"If you've managed to resolve the issue with your customer, help them with the withdrawal of their dispute.",
				'woocommerce'
			) }
			action={
				<LearnMoreButton href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#withdrawals" />
			}
		/>
		<WooPaymentsDisputeStepItem
			icon={ <Icon icon={ page } /> }
			title={ __( 'Challenge or accept the dispute', 'woocommerce' ) }
			description={ __(
				"Disagree with the dispute? You can challenge it with the customer's bank. Otherwise, accept it to close the case — the order amount and dispute fee won't be refunded.",
				'woocommerce'
			) }
		/>
	</StepsPanel>
);

/**
 * Client 11.1.0 `dispute-steps.tsx` `NonCompliantDisputeSteps`, open by default.
 */
export const NonCompliantDisputeSteps = () => (
	<StepsPanel
		initialOpen
		subtitle={ __(
			'We recommend reviewing your options before responding by the deadline.',
			'woocommerce'
		) }
		notice={ __(
			'<strong>The outcome of this dispute will be determined by Visa.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
			'woocommerce'
		) }
	>
		<WooPaymentsDisputeStepItem
			icon={ <Icon icon={ page } /> }
			title={ __( 'Accepting the dispute', 'woocommerce' ) }
			description={ __(
				'Accepting the dispute means you’ll forfeit the funds, pay the standard dispute fee, and avoid the $500 USD Visa network fee.',
				'woocommerce'
			) }
			action={
				<LearnMoreButton href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#visa-compliance-disputes" />
			}
		/>
		<WooPaymentsDisputeStepItem
			icon={ <Icon icon={ envelope } /> }
			title={ __( 'Challenge the dispute', 'woocommerce' ) }
			description={ __(
				'Challenging the dispute will incur a $500 USD Visa network fee, which is charged when you submit evidence. This fee will be refunded if you win the dispute.',
				'woocommerce'
			) }
			action={
				<LearnMoreButton href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#visa-compliance-disputes" />
			}
		/>
	</StepsPanel>
);

/**
 * Client 11.1.0 `dispute-steps.tsx` `InquirySteps`.
 *
 * @param props Component props.
 */
export const InquirySteps = ( props: StepsProps ) => (
	<StepsPanel
		subtitle={ __(
			'We recommend reviewing your options before responding by the deadline.',
			'woocommerce'
		) }
		notice={ getOutcomeText( props.bankName, true ) }
	>
		<ContactCustomerStep emailLink={ getEmailLink( props, true ) } />
		<WooPaymentsDisputeStepItem
			icon={ <Icon icon={ page } /> }
			title={ __( 'Submit evidence or issue a refund', 'woocommerce' ) }
			description={ __(
				"Disagree with the claim? You can challenge it by submitting evidence to the customer's bank. Otherwise, you can settle the inquiry by issuing a refund.",
				'woocommerce'
			) }
			action={
				<LearnMoreButton href="https://woocommerce.com/document/woopayments/fraud-and-disputes/managing-disputes/#inquiries" />
			}
		/>
	</StepsPanel>
);

/**
 * Client 11.1.0 `dispute-steps.tsx` `NotDefendableInquirySteps`, for Klarna inquiries.
 *
 * @param props Component props.
 */
export const NotDefendableInquirySteps = ( props: StepsProps ) => {
	const isReturn = props.dispute.reason === 'credit_not_processed';

	return (
		<StepsPanel
			subtitle={ __(
				'We recommend reviewing your options before responding by the deadline.',
				'woocommerce'
			) }
			notice={ sprintf(
				/* translators: %s: the payment provider, such as Klarna. */
				__(
					'<strong>The outcome of this inquiry will be determined by %s.</strong> WooPayments has no influence over the decision and is not liable for any chargebacks.',
					'woocommerce'
				),
				props.bankName || ''
			) }
		>
			<ContactCustomerStep
				emailLink={ getEmailLink( props, true ) }
				description={
					isReturn
						? __(
								"Reach out to the customer to check if they're returning the item(s).",
								'woocommerce'
						  )
						: undefined
				}
			/>
			<WooPaymentsDisputeStepItem
				icon={ <Icon icon={ page } /> }
				title={ __( 'Issue a refund', 'woocommerce' ) }
				description={
					isReturn
						? __(
								"Once you've received the item(s), refund the customer before the deadline to prevent this escalating to a dispute.",
								'woocommerce'
						  )
						: __(
								'If appropriate, issue a refund to resolve the inquiry before the deadline.',
								'woocommerce'
						  )
				}
			/>
			{ isReturn && (
				<WooPaymentsDisputeStepItem
					icon={ <Icon icon={ envelope } /> }
					title={ __(
						'Respond when the inquiry becomes a dispute',
						'woocommerce'
					) }
					description={ __(
						"If the returned item(s) aren't received, the inquiry may escalate to a dispute after 21 days. You can then submit evidence and challenge it (a dispute fee applies), or accept the dispute and forfeit the funds.",
						'woocommerce'
					) }
					action={
						<LearnMoreButton href="https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/#klarna-inquiries-returns" />
					}
				/>
			) }
		</StepsPanel>
	);
};

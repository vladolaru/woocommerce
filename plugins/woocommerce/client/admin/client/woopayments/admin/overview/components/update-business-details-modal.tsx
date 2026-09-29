/**
 * External dependencies
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import type { WooPaymentsOverviewShell } from '../types';
import {
	formatRequirementDeadline,
	getRequirementErrorMessages,
} from './requirement-error-messages';

export const UpdateBusinessDetailsModal = ( {
	shell,
	onClose,
}: {
	shell: WooPaymentsOverviewShell;
	onClose: () => void;
} ) => {
	const accountLink = shell.account_status.account_link;
	const accountLinkWithSource = accountLink
		? addQueryArgs( accountLink, {
				from: 'WCPAY_OVERVIEW',
				source: 'wcpay-update-business-details-task',
		  } )
		: '';
	const { status, current_deadline: currentDeadline } = shell.account_status;
	const errorMessages = getRequirementErrorMessages(
		shell.account_status.requirements?.errors
	);

	const openAccountLink = () => {
		recordEvent( 'wcpay_account_details_link_clicked', {
			source: 'wcpay-update-business-details-task',
		} );
		if ( accountLinkWithSource ) {
			window.open(
				accountLinkWithSource,
				'_blank',
				'noopener,noreferrer'
			);
		}
	};

	return (
		<Modal
			title={ __( 'Update business details', 'woocommerce' ) }
			className="woocommerce-woopayments-update-business-details-modal"
			onRequestClose={ onClose }
		>
			{ /* Client 11.1.0 `overview/modal/update-business-details/index.tsx:54-77` and `strings.tsx:16-24`. */ }
			<p>
				{ status === 'restricted_soon' && currentDeadline
					? sprintf(
							/* translators: %s: Formatted requirements deadline, for example "5pm Oct 5, 2026". */
							__(
								'Additional information is required to verify your business. Update by %s to avoid a disruption in payouts.',
								'woocommerce'
							),
							formatRequirementDeadline( currentDeadline )
					  )
					: __(
							'Payments and payouts are disabled for this account until missing information is updated. Please update the following information in the Stripe dashboard.',
							'woocommerce'
					  ) }
			</p>
			{ errorMessages.length > 0 && (
				<div className="woocommerce-woopayments-update-business-details-modal__errors">
					{ errorMessages.map( ( errorMessage, index ) => (
						<Notice
							key={ index }
							status="warning"
							isDismissible={ false }
						>
							{ errorMessage }
						</Notice>
					) ) }
				</div>
			) }
			<div className="woocommerce-woopayments-update-business-details-modal__footer">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ openAccountLink }
					disabled={ ! accountLinkWithSource }
				>
					{ __( 'Update business details', 'woocommerce' ) }
				</Button>
			</div>
		</Modal>
	);
};

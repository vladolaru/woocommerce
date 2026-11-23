/**
 * External dependencies
 */
// eslint-disable-next-line @wordpress/use-recommended-components -- Match the extension’s notice and avoid bundling UI Link in the admin routes.
import { ExternalLink } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from '../settings/bootstrap';
import { TextLink } from '../settings/text-link';

const DEV_MODE_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/#developer-notes';

// Client 11.3.0 client/components/dev-mode-message/index.tsx:24-56.
// Client 11.3.0 includes/admin/class-wc-payments-admin-settings.php:246-260 uses a plain link on Settings.
export const DevModeMessage = ( {
	plainLink = false,
	onLearnMoreClick,
}: {
	plainLink?: boolean;
	onLearnMoreClick?: () => void;
} ) => {
	const reason = getWooPaymentsSettingsBootstrap().devModeReason;

	return (
		<>
			<strong>
				{ __(
					'Your store is in development mode, so payments are simulated.',
					'woocommerce'
				) }
			</strong>{ ' ' }
			{ typeof reason === 'string' && reason ? `${ reason } ` : null }
			{ createInterpolateElement(
				__(
					'Real payments only work on your live store. <learnMoreLink>Learn more</learnMoreLink>',
					'woocommerce'
				),
				{
					learnMoreLink: plainLink ? (
						<TextLink href={ DEV_MODE_URL } />
					) : (
						<ExternalLink
							href={ DEV_MODE_URL }
							onClick={ onLearnMoreClick }
						>
							{ null }
						</ExternalLink>
					),
				}
			) }
		</>
	);
};

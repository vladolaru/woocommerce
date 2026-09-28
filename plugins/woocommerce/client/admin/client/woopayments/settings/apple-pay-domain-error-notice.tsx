/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ExternalLink, Notice } from '@wordpress/components';
import { createInterpolateElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getWooPaymentsSettingsBootstrap } from './bootstrap';

type ApplePayDomainError = {
	error: string;
	errorId: string;
	logsUrl: string;
};

const LEARN_MORE_URL =
	'https://woocommerce.com/document/woopayments/payment-methods/apple-pay/#button-does-not-appear';

const SHOWN_PATH =
	'/wc-admin/settings/payments/woopayments/admin-notices/apple_pay_domain_error/shown';

// Bootstrap payloads whose detailed error this page load already reported as shown.
const acknowledgedPayloads = new WeakSet< object >();

const getApplePayDomainErrorPayload = () => {
	const value = getWooPaymentsSettingsBootstrap().applePayDomainError as
		| Partial< ApplePayDomainError >
		| undefined;

	return value && typeof value.logsUrl === 'string' ? value : null;
};

/**
 * Shows the Apple Pay domain verification error on in-app visits to the settings routes.
 *
 * Full page loads of those routes show the server notice instead, and the bootstrap then
 * carries nothing, so the two never show together.
 */
export const ApplePayDomainErrorNotice = () => {
	const payload = getApplePayDomainErrorPayload();
	const detail = typeof payload?.error === 'string' ? payload.error : '';
	const errorId = typeof payload?.errorId === 'string' ? payload.errorId : '';

	useEffect( () => {
		// The server keeps the detailed error until a notice shows it, as client 11.1.0 does.
		if (
			! payload ||
			! detail ||
			! errorId ||
			acknowledgedPayloads.has( payload )
		) {
			return;
		}

		acknowledgedPayloads.add( payload );
		// The server clears the error only if it is still the one shown here.
		apiFetch( {
			path: SHOWN_PATH,
			method: 'POST',
			data: { error_id: errorId },
		} ).catch( () => {
			// Leave the error stored; the next settings visit shows it again.
			acknowledgedPayloads.delete( payload );
		} );
	}, [ payload, detail, errorId ] );

	if ( ! payload ) {
		return null;
	}

	const domainError = { error: detail, logsUrl: payload.logsUrl as string };

	return (
		<Notice
			className="woopayments-settings-apple-pay-domain-notice"
			status="error"
			isDismissible={ false }
		>
			<p>
				<strong>{ __( 'Express checkouts:', 'woocommerce' ) }</strong>{ ' ' }
				<span>
					{ domainError.error
						? __(
								'Apple Pay domain verification failed with the following error:',
								'woocommerce'
						  )
						: __(
								'Apple Pay domain verification failed.',
								'woocommerce'
						  ) }
				</span>{ ' ' }
				<ExternalLink href={ LEARN_MORE_URL }>
					{ __( 'Learn more', 'woocommerce' ) }
				</ExternalLink>
				.
			</p>
			{ domainError.error && (
				<p>
					{ /* `error` is wp_kses'd server-side to plain text and <a href>. */ }
					<i
						dangerouslySetInnerHTML={ {
							__html: domainError.error,
						} }
					/>
				</p>
			) }
			<p>
				{ createInterpolateElement(
					__(
						'Please check the <a>logs</a> for more details on this issue. Debug log must be enabled under <strong>Advanced settings</strong> to see recorded logs.',
						'woocommerce'
					),
					{
						// eslint-disable-next-line jsx-a11y/anchor-has-content -- Content comes from the interpolated string.
						a: <a href={ domainError.logsUrl } />,
						strong: <strong />,
					}
				) }
			</p>
		</Notice>
	);
};

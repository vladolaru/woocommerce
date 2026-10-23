/**
 * The page-level part of the PayPal wallet settings app, registered by PHP as `ppcp-admin-settings`, which carries the
 * localized `ppcpSettings`. The app itself is a lazy chunk of the Payments settings route (client/paypal-wallet), so it
 * ships once. This entry adds the app's stylesheet and lists the WordPress scripts the chunk reads from the page: the
 * asset file of an entry names the scripts its own modules import, never those of a lazy chunk.
 */

/**
 * External dependencies
 */
import '@wordpress/a11y';
import '@wordpress/api-fetch';
import '@wordpress/blob';
import '@wordpress/components';
import '@wordpress/compose';
import '@wordpress/data';
import '@wordpress/element';
import '@wordpress/i18n';
import '@wordpress/notices';
import '@wordpress/primitives';
import '@wordpress/url';
import 'react';

/**
 * Internal dependencies
 */
import '../../paypal-wallet/app/style/styles.scss';

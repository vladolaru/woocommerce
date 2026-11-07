/**
 * External dependencies
 */
import { useNavigate } from 'react-router-dom';

type RouterNavigate = (
	to: { pathname: string; search: string },
	options: { replace: boolean }
) => void;

let navigateInRouter: RouterNavigate | null = null;

// The settings shell's router reads the route from the `path` query argument of admin.php.
const follow = ( to: string, replace: boolean ) => {
	const url = new URL(
		to,
		'http://example.com/wp-admin/admin.php?page=wc-settings'
	);
	navigateInRouter?.(
		{ pathname: url.searchParams.get( 'path' ) || '/', search: url.search },
		{ replace }
	);
};

/**
 * Stands in for `getHistory()` from `@woocommerce/navigation`: records each URL and follows it in the test router.
 */
export const shellHistory = {
	push: jest.fn( ( to: string ) => follow( to, false ) ),
	replace: jest.fn( ( to: string ) => follow( to, true ) ),
};

/**
 * Render inside the test router so `shellHistory` can move it.
 */
export const SettingsShellHistoryBridge = () => {
	const navigate = useNavigate();
	navigateInRouter = ( to, options ) => navigate( to, options );

	return null;
};

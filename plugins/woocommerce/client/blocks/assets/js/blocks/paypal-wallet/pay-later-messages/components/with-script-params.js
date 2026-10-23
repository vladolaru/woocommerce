import { useScriptParams } from '../hooks/script-params';

/**
 * Loads the cart script params and hands them to the render function.
 *
 * The hook lives here so a block's `edit` component can return early (for
 * example while its placement is disabled) without skipping the hook, and the
 * request only starts once this component is rendered.
 *
 * @param {Object}   props
 * @param {Object}   props.requestConfig - The localized request config (endpoint).
 * @param {Object}   props.fallback      - Rendered while the params are loading.
 * @param {Function} props.children      - Called with the params, or `false` if the request failed.
 * @return {Object} The fallback while loading, otherwise the render function's result.
 */
export function WithScriptParams( { requestConfig, fallback, children } ) {
	const scriptParams = useScriptParams( requestConfig );

	if ( scriptParams === null ) {
		return fallback;
	}

	return children( scriptParams );
}

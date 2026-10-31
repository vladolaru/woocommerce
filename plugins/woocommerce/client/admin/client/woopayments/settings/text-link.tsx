/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * A link inside running text that opens in a new tab without the external-link glyph, as the
 * client's plain `<a target="_blank">` links do. Also usable as a `createInterpolateElement` tag.
 */
export const TextLink = ( {
	href,
	children,
}: {
	href: string;
	children?: ReactNode;
} ) => (
	<a href={ href } target="_blank" rel="noopener noreferrer">
		{ children }
	</a>
);

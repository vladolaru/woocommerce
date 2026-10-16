/**
 * External dependencies
 */
import { useLayoutEffect, useRef } from '@wordpress/element';
import type { RefObject } from 'react';

/**
 * Scrolls the page up to the list's top when it sits above the viewport, below the admin bar.
 *
 * @param list The list's outer element.
 */
const scrollListTopIntoView = ( list: HTMLElement | null ) => {
	if ( ! list ) {
		return;
	}

	const adminBarHeight =
		document.getElementById( 'wpadminbar' )?.offsetHeight ?? 0;
	const { top } = list.getBoundingClientRect();

	if ( top >= adminBarHeight ) {
		return;
	}

	window.scrollTo( { top: top + window.scrollY - adminBarHeight } );
};

/**
 * DataViews' loading state for a list whose rows reload on a view change, like Gutenberg's
 * post list: no rows while loading, so the table shows its centered spinner rather than the
 * infinite-scroll spinner under the old rows. Each reload after the first also scrolls the
 * list's top into view.
 *
 * @param rows      The loaded rows.
 * @param isLoading Whether the rows are loading.
 * @return The rows to pass to DataViews and the ref for the list's outer element.
 */
export function useDataViewsReloadState<
	Item,
	ListElement extends HTMLElement,
>(
	rows: Item[],
	isLoading: boolean
): { data: Item[]; listRef: RefObject< ListElement > } {
	const listRef = useRef< ListElement >( null );
	const hasLoadedRef = useRef( false );
	const isAnchoringOffRef = useRef( false );

	useLayoutEffect( () => {
		const root = document.documentElement;

		if ( isLoading ) {
			if ( hasLoadedRef.current ) {
				scrollListTopIntoView( listRef.current );
				// Without the old rows the page shrinks around the focused pager button, and
				// the browser's scroll anchoring would follow it back down once the new rows
				// render; it stays off until they have.
				root.style.overflowAnchor = 'none';
				isAnchoringOffRef.current = true;
			}
			return;
		}

		hasLoadedRef.current = true;

		if ( isAnchoringOffRef.current ) {
			isAnchoringOffRef.current = false;
			// Two frames: the first lays out the new rows with anchoring still off.
			window.requestAnimationFrame( () =>
				window.requestAnimationFrame( () => {
					root.style.overflowAnchor = '';
				} )
			);
		}
	}, [ isLoading ] );

	useLayoutEffect(
		() => () => {
			if ( isAnchoringOffRef.current ) {
				document.documentElement.style.overflowAnchor = '';
			}
		},
		[]
	);

	return { data: isLoading ? [] : rows, listRef };
}

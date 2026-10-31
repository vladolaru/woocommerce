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
 * Scrolls a DataViews list's top into view on each reload after the first, when it sits above
 * the viewport. DataViews keeps the previous rows, dimmed, while the new ones load.
 *
 * @param isLoading Whether the rows are loading.
 * @return The ref for the list's outer element.
 */
export function useDataViewsReloadState< ListElement extends HTMLElement >(
	isLoading: boolean
): RefObject< ListElement > {
	const listRef = useRef< ListElement >( null );
	const hasLoadedRef = useRef( false );
	const isAnchoringOffRef = useRef( false );

	useLayoutEffect( () => {
		const root = document.documentElement;

		if ( isLoading ) {
			if ( hasLoadedRef.current ) {
				scrollListTopIntoView( listRef.current );
				// The browser's scroll anchoring could pull the page back toward the pager
				// when the new rows render; it stays off until they have.
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

	return listRef;
}

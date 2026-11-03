/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef } from 'react';

type ReportsDatePreset = 'last_month' | 'last_year';

type ReportsDatePresetsProps = {
	selectedPreset: string;
	onPresetChange: ( preset: ReportsDatePreset ) => void;
};

const injectedClass = 'woocommerce-woopayments-reports-date-preset';
const buttonClass = `components-button is-tertiary is-small dataviews-controls__date-preset ${ injectedClass }`;

const getPresets = (): Array< {
	preset: ReportsDatePreset;
	label: string;
} > => [
	{ preset: 'last_month', label: __( 'Previous month', 'woocommerce' ) },
	{ preset: 'last_year', label: __( 'Previous year', 'woocommerce' ) },
];

// DataViews owns this label and translates it with the default domain.
// eslint-disable-next-line @wordpress/i18n-text-domain
const getCustomPresetLabel = () => __( 'Custom' );

const isPressed = ( button: HTMLElement ) =>
	button.getAttribute( 'aria-pressed' ) === 'true' ||
	button.classList.contains( 'is-pressed' );

// DataViews has no API to add date presets; these selectors are its private markup (the bundled @wordpress/dataviews 17.1.0).
// If they change, the two presets stop showing and the date filter keeps working.
const getInsertionPoint = () => {
	for ( const popover of Array.from(
		document.querySelectorAll< HTMLElement >(
			'.dataviews-filters__summary-popover'
		)
	) ) {
		const rangeInputs = popover.querySelector< HTMLElement >(
			'.dataviews-controls__date-range-inputs'
		);
		if ( ! rangeInputs ) {
			continue;
		}

		const firstNativePreset = popover.querySelector< HTMLElement >(
			`.dataviews-controls__date-preset:not(.${ injectedClass })`
		);
		if ( firstNativePreset?.parentElement ) {
			return {
				popover,
				parent: firstNativePreset.parentElement,
				before: firstNativePreset as Element,
			};
		}

		if ( rangeInputs.parentElement ) {
			return {
				popover,
				parent: rangeInputs.parentElement,
				before: rangeInputs as Element,
			};
		}
	}

	return null;
};

/**
 * Adds "Previous month" and "Previous year" before the presets of the DataViews date range popover, whenever it opens.
 *
 * Client 11.2.0 `reports/date-filter/dataviews-date-range-preset-portal.tsx`: the two calendar presets join DataViews' own six.
 * When one of them is the applied range, DataViews' presets show unpressed (pressed again once it is not) and its Custom preset stays usable.
 *
 * @param props                The component props.
 * @param props.selectedPreset The preset the applied range matches.
 * @param props.onPresetChange Applies a preset.
 */
const ReportsDatePresets = ( {
	selectedPreset,
	onPresetChange,
}: ReportsDatePresetsProps ) => {
	const nodeRef = useRef< HTMLDivElement | null >( null );
	const selectedRef = useRef( selectedPreset );
	selectedRef.current = selectedPreset;
	const onPresetChangeRef = useRef( onPresetChange );
	onPresetChangeRef.current = onPresetChange;
	// DataViews' presets shown unpressed here, pressed again when the selection leaves the two presets.
	const unpressedRef = useRef( new Set< HTMLButtonElement >() );

	const sync = useCallback( ( force: boolean ) => {
		const point = getInsertionPoint();

		if ( ! point ) {
			nodeRef.current?.remove();
			nodeRef.current = null;
			unpressedRef.current.clear();
			return;
		}

		const inPlace =
			nodeRef.current?.parentElement === point.parent &&
			nodeRef.current.nextElementSibling === point.before;
		if ( inPlace && ! force ) {
			return;
		}

		if ( ! inPlace ) {
			nodeRef.current?.remove();
			nodeRef.current = document.createElement( 'div' );
			nodeRef.current.className = `${ injectedClass }-insert`;
			nodeRef.current.style.display = 'contents';
			point.parent.insertBefore( nodeRef.current, point.before );
		}

		const selected = getPresets().some(
			( { preset } ) => preset === selectedRef.current
		);
		const nativePresets = Array.from(
			point.popover.querySelectorAll< HTMLButtonElement >(
				`.dataviews-controls__date-preset:not(.${ injectedClass })`
			)
		);
		const unpressed = unpressedRef.current;
		for ( const button of nativePresets ) {
			if ( selected ) {
				if ( isPressed( button ) ) {
					unpressed.add( button );
				}
				button.setAttribute( 'aria-pressed', 'false' );
				button.classList.remove( 'is-pressed' );
			}
		}
		// React does not repair attributes changed outside it, so press them again, unless DataViews pressed one since.
		if ( ! selected && unpressed.size ) {
			if ( ! nativePresets.some( isPressed ) ) {
				unpressed.forEach( ( button ) => {
					button.setAttribute( 'aria-pressed', 'true' );
					button.classList.add( 'is-pressed' );
				} );
			}
			unpressed.clear();
		}
		nativePresets
			.find(
				( button ) =>
					button.textContent?.trim() === getCustomPresetLabel()
			)
			?.removeAttribute( 'aria-disabled' );

		for ( const { preset, label } of getPresets() ) {
			let button = nodeRef.current?.querySelector< HTMLButtonElement >(
				`[data-preset="${ preset }"]`
			);
			if ( ! button ) {
				button = document.createElement( 'button' );
				button.type = 'button';
				button.className = buttonClass;
				button.dataset.preset = preset;
				button.textContent = label;
				button.onclick = () => onPresetChangeRef.current( preset );
				nodeRef.current?.appendChild( button );
			}

			const isSelected = selectedRef.current === preset;
			button.setAttribute(
				'aria-pressed',
				isSelected ? 'true' : 'false'
			);
			button.classList.toggle( 'is-pressed', isSelected );
		}
	}, [] );

	useEffect( () => {
		sync( true );
	}, [ selectedPreset, sync ] );

	// The popover mounts in the popover slot or the fallback container; watch both, as the client does.
	useEffect( () => {
		let frame: number | null = null;
		const observed = new Set< Element >();
		const unpressed = unpressedRef.current;
		const observeFallback = ( target: MutationObserver ) => {
			const fallback = document.querySelector(
				'.components-popover__fallback-container'
			);
			if ( fallback && ! observed.has( fallback ) ) {
				target.observe( fallback, {
					childList: true,
					subtree: true,
				} );
				observed.add( fallback );
			}
		};
		const observer = new MutationObserver( ( _records, self ) => {
			if ( frame !== null ) {
				return;
			}
			frame = window.requestAnimationFrame( () => {
				frame = null;
				sync( false );
				observeFallback( self );
			} );
		} );

		observeFallback( observer );
		const content = document.getElementById( 'wpbody-content' );
		if ( content ) {
			observer.observe( content, { childList: true, subtree: true } );
		}
		observer.observe( document.body, { childList: true } );

		return () => {
			if ( frame !== null ) {
				window.cancelAnimationFrame( frame );
			}
			observer.disconnect();
			nodeRef.current?.remove();
			nodeRef.current = null;
			unpressed.clear();
		};
	}, [ sync ] );

	return null;
};

export { ReportsDatePresets };
export type { ReportsDatePreset };

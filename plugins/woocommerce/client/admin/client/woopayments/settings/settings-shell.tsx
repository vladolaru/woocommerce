/**
 * External dependencies
 */
import { Button, Card } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useConfirmUnsavedChanges } from '@woocommerce/navigation';
import clsx from 'clsx';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * A field whose value blocks saving, and the message the save bar shows for it.
 */
export type FieldValidationError = { inputId: string; message: string };

const SAVE_STATUS_ID = 'woopayments-settings-save-status';

/**
 * Scroll a field into view and focus it.
 *
 * @param element The field to focus.
 */
export const focusField = ( element: HTMLElement | null | undefined ) => {
	if ( ! element || typeof element.focus !== 'function' ) {
		return;
	}

	const reduceMotion =
		typeof window.matchMedia === 'function' &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	element.scrollIntoView?.( {
		behavior: reduceMotion ? 'auto' : 'smooth',
		block: 'center',
	} );
	element.focus( { preventScroll: true } );
};

/**
 * One settings section of the WooPayments settings pages: a title and description beside a card of controls,
 * as client 11.1.0's SettingsSection lays out its main page and subpages.
 */
export const SettingsSection = ( {
	id,
	title,
	hideTitle = false,
	description,
	className,
	hasCard = true,
	children,
}: {
	id?: string;
	title: string;
	/** Name the section without showing the title, for a section whose description leads with icons. */
	hideTitle?: boolean;
	description?: ReactNode;
	className?: string;
	/** Set to false when the controls bring their own cards. */
	hasCard?: boolean;
	children: ReactNode;
} ) => {
	const generatedId = useId();
	const headingId = `${ id ?? generatedId }__heading`;
	const Controls = hasCard ? Card : 'div';

	return (
		<section
			className={ clsx( 'woopayments-settings-section', className ) }
			id={ id }
			aria-labelledby={ hideTitle ? undefined : headingId }
			aria-label={ hideTitle ? title : undefined }
		>
			<div className="woopayments-settings-section__details">
				{ ! hideTitle && <h2 id={ headingId }>{ title }</h2> }
				{ description && (
					<div className="woopayments-settings-section__description">
						{ description }
					</div>
				) }
			</div>
			<Controls className="woopayments-settings-section__controls">
				{ children }
			</Controls>
		</section>
	);
};

/**
 * The save bar of the WooPayments settings pages: the Save button, the save status, and the prompt before leaving
 * with unsaved changes.
 */
export const SettingsSaveBar = ( {
	isDirty,
	isSaving,
	isDisabled = false,
	validationError = null,
	onSave,
	children,
}: {
	isDirty: boolean;
	isSaving: boolean;
	/** Keep Save inactive for a reason other than a clean or saving page, such as settings still loading. */
	isDisabled?: boolean;
	/** A field that blocks saving: Save stays clickable and leads to it. */
	validationError?: FieldValidationError | null;
	onSave: () => void;
	children?: ReactNode;
} ) => {
	useConfirmUnsavedChanges( isDirty );

	const isInactive = isSaving || isDisabled || ! isDirty;
	const isBlockedByValidation = isDirty && !! validationError;

	const saveOnClick = () => {
		if ( isBlockedByValidation ) {
			focusField( document.getElementById( validationError.inputId ) );
			return;
		}

		if ( isInactive ) {
			return;
		}

		onSave();
	};

	return (
		<div className="woopayments-settings-save-bar">
			<Button
				variant="primary"
				isBusy={ isSaving }
				// A validation block keeps the button clickable so it can lead to the invalid field.
				disabled={ isInactive && ! isBlockedByValidation }
				aria-disabled={
					isInactive || isBlockedByValidation || undefined
				}
				accessibleWhenDisabled
				aria-describedby={ SAVE_STATUS_ID }
				onClick={ saveOnClick }
			>
				{ __( 'Save changes', 'woocommerce' ) }
			</Button>
			<p
				id={ SAVE_STATUS_ID }
				aria-live="polite"
				className={ clsx( 'woopayments-settings-save-bar__status', {
					'is-error': isBlockedByValidation,
				} ) }
			>
				{ isBlockedByValidation && validationError.message }
				{ ! isBlockedByValidation &&
					( isDirty
						? __( 'You have unsaved changes.', 'woocommerce' )
						: __( 'Settings are up to date.', 'woocommerce' ) ) }
			</p>
			{ children }
		</div>
	);
};

/**
 * External dependencies
 */
import { useId, type Ref } from '@wordpress/element';

const FieldGroup = ( {
	id,
	title,
	headingRef,
	children,
}: {
	id?: string;
	title: string;
	/** Makes the heading a programmatic focus target, for focus that has nowhere else to land. */
	headingRef?: Ref< HTMLHeadingElement >;
	children: React.ReactNode;
} ) => {
	// Expose each field group as a `group` named by its heading, so it can be
	// found by role and accessible name rather than by CSS class. `useId` gives the heading a stable, unique id even when no
	// `id` prop is passed.
	const headingId = useId();

	return (
		<div
			className="woopayments-settings-field-group"
			id={ id }
			role="group"
			aria-labelledby={ headingId }
		>
			<h3
				id={ headingId }
				ref={ headingRef }
				// Focusable by script only, not in the tab order, as the wrapper in settings-recommendations/dismissable-list.tsx.
				tabIndex={ headingRef ? -1 : undefined }
			>
				{ title }
			</h3>
			{ children }
		</div>
	);
};

export { FieldGroup };

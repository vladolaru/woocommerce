/**
 * External dependencies
 */
import { useId } from '@wordpress/element';

const FieldGroup = ( {
	id,
	title,
	children,
}: {
	id?: string;
	title: string;
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
			<h3 id={ headingId }>{ title }</h3>
			{ children }
		</div>
	);
};

export { FieldGroup };

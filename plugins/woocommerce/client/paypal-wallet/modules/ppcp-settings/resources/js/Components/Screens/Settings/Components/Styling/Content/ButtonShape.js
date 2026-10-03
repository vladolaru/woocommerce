import { __ } from '@wordpress/i18n';

import { StylingHooks } from '@ppcp-settings/data';
import { RadiobuttonStylingSection } from '../Layout';

const SectionButtonShape = ( { location } ) => {
	const { shape, setShape, choices } = StylingHooks.useShapeProps( location );

	return (
		<RadiobuttonStylingSection
			title={ __( 'Shape', 'woocommerce' ) }
			className="button-shape"
			options={ choices }
			selected={ shape }
			onChange={ setShape }
		/>
	);
};

export default SectionButtonShape;

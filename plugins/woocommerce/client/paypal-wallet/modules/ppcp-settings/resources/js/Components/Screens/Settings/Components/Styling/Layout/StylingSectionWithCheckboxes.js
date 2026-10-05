import classNames from 'classnames';

import { CheckboxGroup } from '@ppcp-settings/Components/ReusableComponents/Fields';
import { VStack } from '@ppcp-settings/Components/ReusableComponents/Stack';
import StylingSection from './StylingSection';

const StylingSectionWithCheckboxes = ( {
	title,
	name,
	className = '',
	description = '',
	separatorAndGap = true,
	options,
	value,
	onChange,
	children,
} ) => {
	className = classNames( 'ppcp--has-checkboxes', name, className );

	return (
		<StylingSection
			title={ title }
			className={ className }
			description={ description }
			separatorAndGap={ separatorAndGap }
		>
			<VStack spacing={ 6 }>
				<CheckboxGroup
					name={ name }
					options={ options }
					value={ value }
					onChange={ onChange }
				/>
			</VStack>

			{ children }
		</StylingSection>
	);
};

export default StylingSectionWithCheckboxes;

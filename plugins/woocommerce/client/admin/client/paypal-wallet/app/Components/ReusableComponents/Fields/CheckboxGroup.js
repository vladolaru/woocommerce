import { useCallback } from '@wordpress/element';
import { PayPalCheckbox } from './index';

const CheckboxGroup = ( { name, options, value, onChange } ) => {
	const handleChange = useCallback(
		( key, checked ) => {
			const getNewValue = () => {
				if ( typeof value === 'boolean' ) {
					return checked;
				}

				if ( checked ) {
					return [ ...value, key ];
				}
				return value.filter( ( val ) => val !== key );
			};

			onChange( getNewValue() );
		},
		[ onChange, value ]
	);

	const isItemChecked = ( checked, itemValue ) => {
		if ( typeof checked === 'boolean' ) {
			return checked;
		}

		if ( Array.isArray( value ) ) {
			return value.includes( itemValue );
		}

		if ( typeof value === 'boolean' ) {
			return value;
		}

		return value === itemValue;
	};

	return (
		<>
			{ options.map(
				( {
					value: itemValue,
					label,
					checked,
					disabled,
					description,
				} ) => (
					<PayPalCheckbox
						key={ name + itemValue }
						value={ itemValue }
						label={ label }
						checked={ isItemChecked( checked, itemValue ) }
						disabled={ disabled }
						description={ description }
						changeCallback={ handleChange }
					/>
				)
			) }
		</>
	);
};

export default CheckboxGroup;

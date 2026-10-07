import { FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { useRef } from '@wordpress/element';

const SettingsToggleBlock = ( {
	isToggled,
	setToggled,
	disabled = false,
	...props
} ) => {
	const toggleRef = useRef( null );
	const labelId = useInstanceId(
		SettingsToggleBlock,
		'ppcp-r-toggle-block__label'
	);
	const blockClasses = [ 'ppcp-r-toggle-block' ];

	const handleLabelClick = () => {
		if ( ! toggleRef.current || disabled ) {
			return;
		}

		toggleRef.current.click();
		toggleRef.current.focus();
	};

	return (
		<div className={ blockClasses.join( ' ' ) }>
			<div className="ppcp-r-toggle-block__wrapper">
				<div className="ppcp-r-toggle-block__content">
					{ props?.label && (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events,jsx-a11y/no-static-element-interactions -- keyboard element is ToggleControl
						<div
							id={ labelId }
							className="ppcp-r-toggle-block__content-label"
							onClick={ handleLabelClick }
						>
							{ props.label }
						</div>
					) }
					{ props?.description && (
						<p
							className="ppcp-r-toggle-block__content-description"
							dangerouslySetInnerHTML={ {
								__html: props.description,
							} }
						></p>
					) }
				</div>
				<div className="ppcp-r-toggle-block__switch">
					<FormToggle
						ref={ toggleRef }
						checked={ isToggled }
						onChange={ ( event ) =>
							setToggled( event.target.checked )
						}
						disabled={ disabled }
						aria-labelledby={ props?.label ? labelId : undefined }
					/>
				</div>
			</div>
			{ props.children && isToggled && (
				<div className="ppcp-r-toggle-block__toggled-content">
					{ props.children }
				</div>
			) }
		</div>
	);
};

export default SettingsToggleBlock;

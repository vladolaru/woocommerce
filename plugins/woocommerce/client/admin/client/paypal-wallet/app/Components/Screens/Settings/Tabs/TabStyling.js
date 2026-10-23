import { StylingHooks } from '../../../../data';
import SpinnerOverlay from '../../../ReusableComponents/SpinnerOverlay';
import PreviewPanel from '../Components/Styling/PreviewPanel';
import SettingsPanel from '../Components/Styling/SettingsPanel';

const TabStyling = () => {
	const { isReady } = StylingHooks.useStore();
	const { location, setLocation } = StylingHooks.useStylingLocation();

	if ( ! isReady ) {
		return <SpinnerOverlay asModal={ true } />;
	}

	return (
		<div className="ppcp-r-styling">
			<SettingsPanel location={ location } setLocation={ setLocation } />
			<PreviewPanel location={ location } />
		</div>
	);
};

export default TabStyling;

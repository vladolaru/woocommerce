import { useEffect } from '@wordpress/element';
import Container from '../../ReusableComponents/Container';
import HelpSection from '../../ReusableComponents/HelpSection';
import { useNavigation } from '../../../hooks/useNavigation';
import { PaymentHooks, SettingsHooks } from '../../../data';
import SettingsNavigation from './Components/Navigation';
import { getSettingsTabs } from './Tabs';

const SettingsScreen = ( { activePanel, setActivePanel } ) => {
	const tabs = getSettingsTabs();
	const { Component } = tabs.find( ( tab ) => tab.name === activePanel );
	const { handleHighlightFromUrl } = useNavigation();
	const { isReady: isPaymentStoreReady } = PaymentHooks.useStore();
	const { isReady: isSettingsStoreReady } = SettingsHooks.useStore();

	useEffect( () => {
		if ( isPaymentStoreReady && isSettingsStoreReady ) {
			handleHighlightFromUrl();
		}
	}, [ handleHighlightFromUrl, isPaymentStoreReady, isSettingsStoreReady ] );

	return (
		<>
			<SettingsNavigation
				tabs={ tabs }
				activePanel={ activePanel }
				setActivePanel={ setActivePanel }
			/>
			<Container page="settings">
				{ Component }
				<HelpSection />
			</Container>
		</>
	);
};

export default SettingsScreen;

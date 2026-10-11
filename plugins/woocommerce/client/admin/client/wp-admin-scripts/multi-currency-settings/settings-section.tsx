/**
 * External dependencies
 */
import { Card } from '@wordpress/components';
import type { ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { Settings } from '~/settings-payments/components/settings';

interface SettingsSectionProps {
	title: string;
	description: ReactNode;
	children: ReactNode;
}

// The shell section types its description as a string, but renders it inside a <p>,
// so a description that carries a Learn more link renders the same way.
const ShellSection = Settings.Section as unknown as (
	props: Omit< SettingsSectionProps, 'children' > & {
		children?: ReactNode;
	}
) => ReactElement;

/**
 * A settings-payments shell section with its controls in a card, like the client's settings sections.
 */
export function SettingsSection( {
	title,
	description,
	children,
}: SettingsSectionProps ) {
	return (
		<ShellSection title={ title } description={ description }>
			<Card className="woocommerce-multi-currency-settings__card">
				{ children }
			</Card>
		</ShellSection>
	);
}

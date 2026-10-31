/**
 * External dependencies
 */
import { useSyncExternalStore } from 'react';

/**
 * In-memory stand-in for @woocommerce/data useUserPreferences(): the decoded
 * woocommerce_meta values plus an updateUserPreferences() that stores them and
 * re-renders subscribers, like the optimistic update in core's hook.
 */
let preferences: Record< string, unknown > = {};
const listeners = new Set< () => void >();

export const mockUpdateUserPreferences = jest.fn(
	( next: Record< string, unknown > ) => {
		preferences = { ...preferences, ...next };
		listeners.forEach( ( listener ) => listener() );

		return Promise.resolve( { updatedUser: {} } );
	}
);

export const setMockUserPreferences = ( next: Record< string, unknown > ) => {
	preferences = next;
	mockUpdateUserPreferences.mockClear();
};

const subscribe = ( listener: () => void ) => {
	listeners.add( listener );

	return () => {
		listeners.delete( listener );
	};
};

export const useMockUserPreferences = () => ( {
	...useSyncExternalStore( subscribe, () => preferences ),
	updateUserPreferences: mockUpdateUserPreferences,
} );

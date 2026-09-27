/**
 * External dependencies
 */
import { useMemo } from 'react';
import { useUserPreferences } from '@woocommerce/data';

/**
 * The client's user meta keys for hidden list columns, registered in PHP by
 * WooPaymentsUserPreferenceFields like WC_Payments::add_user_data_fields().
 */
export type WooPaymentsHiddenColumnsKey =
	| 'wc_payments_transactions_hidden_columns'
	| 'wc_payments_transactions_blocked_hidden_columns'
	| 'wc_payments_transactions_uncaptured_hidden_columns'
	| 'wc_payments_payouts_hidden_columns'
	| 'wc_payments_disputes_hidden_columns'
	| 'wc_payments_documents_hidden_columns';

const SAME_COLUMN_KEYS: Record< string, string > = {};

/**
 * Keeps a list's hidden columns in user meta through core's user preferences
 * store, like the client's usePersistedColumnVisibility(). The stored value is
 * the client's list of hidden column keys; `columnKeys` maps native field ids
 * to those keys where they differ, and keys native does not show are kept.
 *
 * @param preferenceKey The client's user meta key for this list.
 * @param defaultFields The list's fields, visible when nothing is hidden.
 * @param columnKeys    Native field id to client column key, where they differ.
 */
export const usePersistedHiddenFields = (
	preferenceKey: WooPaymentsHiddenColumnsKey,
	defaultFields: string[],
	columnKeys: Record< string, string > = SAME_COLUMN_KEYS
) => {
	const { updateUserPreferences, ...preferences } = useUserPreferences();
	const stored = ( preferences as Record< string, unknown > )[
		preferenceKey
	];
	const hidden = Array.isArray( stored ) ? ( stored as string[] ) : [];
	const hiddenKey = hidden.join( ',' );
	const toColumnKey = ( field: string ) => columnKeys[ field ] ?? field;

	const visibleFields = useMemo(
		() =>
			defaultFields.filter(
				( field ) => ! hidden.includes( columnKeys[ field ] ?? field )
			),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- hidden is a new array on every render.
		[ defaultFields, columnKeys, hiddenKey ]
	);

	// Writes only when the hidden set changes, never on paging, sorting or load.
	const saveFields = ( fields: string[] = [] ) => {
		const ownKeys = defaultFields.map( toColumnKey );
		const nextHidden = [
			...hidden.filter( ( key ) => ! ownKeys.includes( key ) ),
			...defaultFields
				.filter( ( field ) => ! fields.includes( field ) )
				.map( toColumnKey ),
		];

		if (
			nextHidden.length !== hidden.length ||
			nextHidden.some( ( key ) => ! hidden.includes( key ) )
		) {
			void updateUserPreferences( { [ preferenceKey ]: nextHidden } );
		}
	};

	return { visibleFields, saveFields };
};

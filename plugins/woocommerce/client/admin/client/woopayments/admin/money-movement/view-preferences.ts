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
const NO_DEFAULT_HIDDEN_COLUMNS: string[] = [];

/**
 * Keeps a list's hidden columns in user meta through core's user preferences
 * store, like the client's usePersistedColumnVisibility(). The stored value is
 * the client's list of hidden column keys; `columnKeys` maps native field ids
 * to those keys where they differ, and keys native does not show are kept.
 * With nothing stored, the client's hidden-by-default columns stay hidden.
 *
 * @param preferenceKey        The client's user meta key for this list.
 * @param fields               The list's fields, in display order.
 * @param columnKeys           Native field id to client column key, where they differ.
 * @param defaultHiddenColumns Client column keys with `visible: false`, used until a preference is stored.
 */
export const usePersistedHiddenFields = (
	preferenceKey: WooPaymentsHiddenColumnsKey,
	fields: string[],
	columnKeys: Record< string, string > = SAME_COLUMN_KEYS,
	defaultHiddenColumns: string[] = NO_DEFAULT_HIDDEN_COLUMNS
) => {
	const { updateUserPreferences, ...preferences } = useUserPreferences();
	const stored = ( preferences as Record< string, unknown > )[
		preferenceKey
	];
	// The client stores '' until the first change, which means "use the defaults".
	const hidden = Array.isArray( stored )
		? ( stored as string[] )
		: defaultHiddenColumns;
	const hiddenKey = hidden.join( ',' );
	const toColumnKey = ( field: string ) => columnKeys[ field ] ?? field;

	const visibleFields = useMemo(
		() =>
			fields.filter(
				( field ) => ! hidden.includes( columnKeys[ field ] ?? field )
			),
		// eslint-disable-next-line react-hooks/exhaustive-deps -- hidden is a new array on every render.
		[ fields, columnKeys, hiddenKey ]
	);

	// Writes only when the hidden set changes, never on paging, sorting or load.
	const saveFields = ( shownFields: string[] = [] ) => {
		const ownKeys = fields.map( toColumnKey );
		const nextHidden = [
			...hidden.filter( ( key ) => ! ownKeys.includes( key ) ),
			...fields
				.filter( ( field ) => ! shownFields.includes( field ) )
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

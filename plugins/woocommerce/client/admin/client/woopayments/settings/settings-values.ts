/**
 * Readers for values of the WooPayments settings response, which the settings pages treat as untyped: each returns the
 * value when it has the expected type, and the fallback otherwise.
 */
export type SettingsRecord = Record< string, unknown >;

export const asSettingsRecord = ( value: unknown ): SettingsRecord =>
	value && typeof value === 'object' ? ( value as SettingsRecord ) : {};

export const asString = ( value: unknown, fallback = '' ) =>
	typeof value === 'string' ? value : fallback;

export const asStringArray = ( value: unknown ) =>
	Array.isArray( value )
		? value.filter( ( item ): item is string => typeof item === 'string' )
		: [];

export const asBoolean = ( value: unknown, fallback = false ) =>
	typeof value === 'boolean' ? value : fallback;

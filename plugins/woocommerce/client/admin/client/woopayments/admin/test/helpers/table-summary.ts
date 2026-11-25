/**
 * Matches one item of a list's footer summary (`@woocommerce/components` `TableSummary`), read as
 * "value label", for example "3 transactions" or "$18.38 total".
 *
 * @param text The item's value and label, separated by a space.
 */
export const summaryItem =
	( text: string ) => ( _content: string, element: Element | null ) =>
		!! element?.matches( '.woocommerce-table__summary-item' ) &&
		Array.from( element.children )
			.map( ( child ) => child.textContent )
			.join( ' ' ) === text;

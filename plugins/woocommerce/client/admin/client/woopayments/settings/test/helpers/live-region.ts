type MutationRecords = ReturnType<
	InstanceType< typeof window.MutationObserver >[ 'takeRecords' ]
>;

/**
 * Records DOM mutations while a callback runs, so a test can tell whether a live region was already
 * on the page when its text arrived (screen readers do not announce text mounted with its region).
 *
 * @param callback Renders the component.
 * @return The mutation records.
 */
export const recordMutations = ( callback: () => void ) => {
	const records: MutationRecords = [];
	const observer = new window.MutationObserver( ( list ) =>
		records.push( ...list )
	);
	observer.observe( document.body, {
		childList: true,
		subtree: true,
		characterData: true,
	} );
	callback();
	records.push( ...observer.takeRecords() );
	observer.disconnect();

	return records;
};

export const wasTextAddedToExistingNode = (
	records: MutationRecords,
	node: Pick< typeof document.body, 'contains' >,
	text: string
) =>
	records.some(
		( record ) =>
			node.contains( record.target ) &&
			( record.type === 'characterData'
				? record.target.textContent?.includes( text )
				: Array.from( record.addedNodes ).some(
						( added ) => added.textContent?.includes( text )
				  ) )
	);

/**
 * Makes `getFullYear()`, `getMonth()` and `getDate()` read the calendar at a fixed UTC offset, so the browser-local
 * day the date code takes is the same on every host. Setting `process.env.TZ` in a test does not do this: Jest's
 * `process.env` is a copy, so the engine keeps the host's zone. Pair it with a `getTimezoneOffset()` spy at the same offset.
 *
 * @param offsetMinutes Minutes east of UTC, such as 180 for +03:00.
 * @return A function that restores the three getters.
 */
export const pinLocalCalendarAt = ( offsetMinutes: number ) => {
	const atOffset = ( date: Date ) =>
		new Date( date.getTime() + offsetMinutes * 60 * 1000 );
	const spies = [
		jest
			.spyOn( Date.prototype, 'getFullYear' )
			.mockImplementation( function ( this: Date ) {
				return atOffset( this ).getUTCFullYear();
			} ),
		jest.spyOn( Date.prototype, 'getMonth' ).mockImplementation( function (
			this: Date
		) {
			return atOffset( this ).getUTCMonth();
		} ),
		jest.spyOn( Date.prototype, 'getDate' ).mockImplementation( function (
			this: Date
		) {
			return atOffset( this ).getUTCDate();
		} ),
	];

	return () => spies.forEach( ( spy ) => spy.mockRestore() );
};

/**
 * The counting picks of the sum row: the chosen members of each column, kept locally so quick successive
 * ticks all apply, and the count a sums request carries (a grant holder's own, never stored; topic 6a G1).
 * No WordPress imports, so Jest runs it as it is.
 */

/** The members after ticking or unticking one, keeping the order of the current picks. */
export function toggleMember( chosen, member, on ) {
	if ( ! on ) {
		return chosen.filter( ( value ) => value !== member );
	}
	return chosen.includes( member ) ? chosen : chosen.concat( member );
}

/**
 * The picks since the last reset, by column. A column's first pick starts from the stored choice (the
 * fallback); a tick that arrives before the next render still builds on the previous tick.
 */
export function createPicks() {
	let local = {};
	return {
		reset() {
			local = {};
		},
		chosen( column, fallback ) {
			return Object.prototype.hasOwnProperty.call( local, column )
				? local[ column ]
				: fallback;
		},
		toggle( column, member, on, fallback ) {
			const next = toggleMember( this.chosen( column, fallback ), member, on );
			// Unticking the last member drops the pick, so the column counts its stored choice again (the
			// server's default for Állapot) and the menu shows the same thing as the sum row.
			if ( next.length === 0 ) {
				delete local[ column ];
				return this.chosen( column, fallback );
			}
			local[ column ] = next;
			return next;
		},
			all() {
			return { ...local };
		},
	};
}

/** Whether the picks count anything different from the stored choice (the view's default). */
export function countsDiffer( columns, stored, local ) {
	const base = countsToSend( columns, stored, {} ) || {};
	const now = countsToSend( columns, stored, local ) || {};
	const keys = Object.keys( base ).sort();
	if ( keys.length !== Object.keys( now ).length || keys.some( ( key ) => ! ( key in now ) ) ) {
		return true;
	}
	return keys.some(
		( key ) => [ ...base[ key ] ].sort().join( '\u0000' ) !== [ ...now[ key ] ].sort().join( '\u0000' )
	);
}

/**
 * What a sums request carries: the stored choice with the local picks on top, only for the view's listed
 * columns, and only where members are picked. Null when nothing is picked, so the server uses its default.
 *
 * @param {Array}  columns the tab's columns ({ column })
 * @param {Object} stored  the stored counted members, by column
 * @param {Object} local   the picks since the last reset, by column
 */
export function countsToSend( columns, stored, local ) {
	const merged = { ...( stored || {} ), ...( local || {} ) };
	const listed = new Set( columns.map( ( column ) => column.column ) );
	const counts = {};
	for ( const [ column, members ] of Object.entries( merged ) ) {
		if ( listed.has( column ) && Array.isArray( members ) && members.length ) {
			counts[ column ] = members;
		}
	}
	return Object.keys( counts ).length ? counts : null;
}

/**
 * Unsaved entries of the event page (a typed layout, a body chosen but not yet saved). The tab panel unmounts a tab
 * when another is opened, so the entries live in the page, one level above: changing tabs keeps them, saving or
 * reloading the page drops them. [UNAPPROVED N276]
 */
import { useMemo, useState } from '@wordpress/element';

/** @return {{get: Function, set: Function, clear: Function}} `get( key, fallback )`, `set( key, value )`, `clear( key )` */
export default function useDrafts() {
	const [ values, setValues ] = useState( {} );
	return useMemo(
		() => ( {
			get: ( key, fallback ) => ( Object.prototype.hasOwnProperty.call( values, key ) ? values[ key ] : fallback ),
			set: ( key, value ) => setValues( ( old ) => ( { ...old, [ key ]: value } ) ),
			clear: ( key ) =>
				setValues( ( old ) => {
					const { [ key ]: dropped, ...rest } = old; // eslint-disable-line no-unused-vars
					return rest;
				} ),
		} ),
		[ values ]
	);
}

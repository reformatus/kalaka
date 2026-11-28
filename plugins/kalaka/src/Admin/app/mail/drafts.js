/**
 * Unsaved entries of the event page (a typed layout, a body chosen but not yet saved). The tab panel unmounts a tab
 * when another is opened, so the entries live in the page, one level above: changing tabs keeps them, saving or
 * reloading the page drops them. [UNAPPROVED N276]
 */
import { useMemo, useState } from '@wordpress/element';

/** @return {{get: Function, set: Function, clear: Function, values: Object}} `get( key, fallback )`, `set( key, value )`, `clear( key )` */
export default function useDrafts() {
	const [ values, setValues ] = useState( {} );
	return useMemo(
		() => ( {
			values,
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

/**
 * Whether the page holds an entry that differs from what the event's mail read shows: a body chosen but not saved, or
 * a layout typed and not saved. An entry equal to the saved state is not unsaved.
 */
export function hasUnsaved( values, data ) {
	if ( ! data ) {
		return false;
	}
	if ( Object.prototype.hasOwnProperty.call( values, 'layout' ) && values.layout !== data.layout_html ) {
		return true;
	}
	return data.mails.some( ( mail ) => {
		const key = 'body:' + mail.mail;
		return Object.prototype.hasOwnProperty.call( values, key ) && values[ key ] !== ( mail.body_id === null ? '' : String( mail.body_id ) );
	} );
}

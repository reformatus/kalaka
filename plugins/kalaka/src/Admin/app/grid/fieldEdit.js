/**
 * Staff editing of a registration's field values in the detail panel (B5-2 on #249): which fields the panel
 * offers an edit for, what each control opens on, what is sent, and the "full" flag of the picked value. No
 * WordPress imports, so Jest runs it as it is. The UI decides no access and no seats: `writable`, `edit.answer`,
 * `edit.control`, `edit.multiple`, `edit.expected`, `options[].full` and `full` are the server's, and `expected`
 * is sent back as it came, never rebuilt from `answer`. Every choice is an [UNAPPROVED N23x] box in internal/docs/b5-panel-prototype.md.
 */

/** The controls the server names in `edit.control`; any other value, and null, is no control. [UNAPPROVED N234] */
const CONTROLS = [ 'text', 'number', 'choice', 'multi_choice', 'consent' ];

/**
 * The control a writable field gets, as the read's `edit.control` names it: `text`, `number`, `choice`,
 * `multi_choice` (`edit.multiple` on a choice) or `consent`; null when the field is not writable, the read names
 * no control (a ranking, the congregation picker, a type of an extension) or a choice has no options: no edit
 * button then. A date arrives as `text`. [UNAPPROVED N234] [UNAPPROVED N245] [UNAPPROVED N246]
 *
 * @param {?Object} field one `fields[]` item of the read
 */
export function controlOf( field ) {
	if ( ! field || field.writable !== true || ! field.edit || ! CONTROLS.includes( field.edit.control ) ) {
		return null;
	}
	const { control, multiple, options } = field.edit;
	if ( control === 'choice' || control === 'multi_choice' ) {
		if ( ! Array.isArray( options ) ) {
			return null;
		}
		return control === 'multi_choice' || multiple === true ? 'multi_choice' : 'choice';
	}
	return control;
}

/** The control's state when the dialog opens: the server's `edit.answer.value`. */
export function openingOf( field ) {
	const value = field.edit.answer.value;
	switch ( controlOf( field ) ) {
		case 'consent':
			return value === true;
		case 'multi_choice':
			return Array.isArray( value ) ? value : [];
		default:
			return value === null || value === undefined ? '' : String( value );
	}
}

/**
 * The value the ability takes: text and a single choice send null for empty (a clear), a list sends null when
 * empty, a consent sends "1" or "0". [UNAPPROVED N244] [UNAPPROVED N242]
 */
export function valueOf( field, picked ) {
	switch ( controlOf( field ) ) {
		case 'consent':
			return picked ? '1' : '0';
		case 'multi_choice':
			return picked.length ? picked : null;
		default:
			return picked === '' ? null : picked;
	}
}

/**
 * The ability's input; `expected` is the read's, untouched. A preview carries no reason (none is needed).
 * [UNAPPROVED N236]
 */
export function inputOf( event, code, field, picked, reason, preview = false ) {
	const input = {
		event_id: event,
		code,
		field: field.key,
		value: valueOf( field, picked ),
		expected: field.edit.expected === undefined ? null : field.edit.expected,
	};
	if ( preview ) {
		input.preview = true;
	} else if ( reason !== '' ) {
		input.reason = reason; // optional (J21)
	}
	return input;
}

/**
 * Whether the picked value is one whose pool the read says would go over for this registration: a picked option
 * with `full`, or a ticked consent with `full`. Only then the dialog asks the preview for its warning; the text is
 * the preview's. [UNAPPROVED N236]
 */
export function isFull( field, picked ) {
	const edit = field.edit;
	switch ( controlOf( field ) ) {
		case 'consent':
			return picked === true && edit.full === true;
		case 'multi_choice':
			return ( edit.options || [] ).some( ( option ) => option.full === true && picked.includes( option.value ) );
		case 'choice':
			return ( edit.options || [] ).some( ( option ) => option.full === true && option.value === picked );
		default:
			return false;
	}
}

/** What the list shows for a field: the server's text. [UNAPPROVED N231] */
export const textOf = ( field ) => ( field.text === undefined || field.text === null ? '' : String( field.text ) );

/** The fields to list: what the read carries, in its order. */
export const fieldsOf = ( detail ) => ( detail && Array.isArray( detail.fields ) ? detail.fields : [] );

/**
 * The fields grouped with their request (ruling 3): `requests.items[].fields` is `{ ask: [keys], answer: key }`
 * (N1100). Walks the field list in its own order; at the first field of a request's group the whole group comes,
 * the request's ask fields then its answer field, as far as the read lists them (a field the views leave out is
 * absent and leaves no gap, and a group with no field at all is not made). A field belongs to the first request
 * that names it; fields no request names stay single. [UNAPPROVED N1010] [UNAPPROVED N1045]
 *
 * @param {Array} fields  the read's `fields[]`
 * @param {Array} items   the read's `requests.items[]`
 * @return {Array} entries `{ field }` or `{ request, fields }`
 */
export function groupedFields( fields, items ) {
	const keyed = {};
	fields.forEach( ( field ) => {
		keyed[ field.key ] = field;
	} );
	const claimed = new Set();
	const groups = [];
	items.forEach( ( item ) => {
		const ask = item.fields && Array.isArray( item.fields.ask ) ? item.fields.ask : [];
		const answer = item.fields && item.fields.answer ? [ item.fields.answer ] : [];
		const members = [];
		ask.concat( answer ).forEach( ( key ) => {
			if ( keyed[ key ] && ! claimed.has( key ) ) {
				claimed.add( key );
				members.push( keyed[ key ] );
			}
		} );
		if ( members.length > 0 ) {
			groups.push( { request: item, fields: members } );
		}
	} );
	const out = [];
	const done = new Set();
	fields.forEach( ( field ) => {
		const group = groups.find( ( entry ) => entry.fields.includes( field ) );
		if ( ! group ) {
			out.push( { field } );
		} else if ( ! done.has( group ) ) {
			done.add( group );
			out.push( group );
		}
	} );
	return out;
}

/**
 * Staff editing of a registration's field values in the detail panel (B5-2 on #249): which fields the panel
 * offers an edit for, what each control opens on, what is sent, and the "full" flag of the picked value. No
 * WordPress imports, so Jest runs it as it is. The UI decides no access and no seats: `writable`, `edit.answer`,
 * `edit.expected`, `options[].full` and `full` are the server's, and `expected` is sent back as it came, never
 * rebuilt from `answer`. Every choice is an [UNAPPROVED N23x] box in internal/docs/b5-panel-prototype.md.
 */

/** The block names whose value is one line of text for the control. [UNAPPROVED N234] */
const TEXT_KINDS = [
	'kalaka/text-field',
	'kalaka/name-field',
	'kalaka/email-field',
	'kalaka/phone-field',
	'kalaka/date-field',
	'kalaka/number-field',
	'kalaka/scale-field',
];

/**
 * The control a writable field gets: `text`, `choice` (one option), `multi` (several options, when the answer
 * is a list), `consent`; null for a kind the panel has no control for (no edit button then). [UNAPPROVED N234]
 *
 * @param {?Object} field one `fields[]` item of the read
 */
export function controlOf( field ) {
	if ( ! field || field.writable !== true || ! field.edit || ! field.edit.answer ) {
		return null;
	}
	const { kind, value } = field.edit.answer;
	if ( TEXT_KINDS.includes( kind ) ) {
		return 'text';
	}
	if ( kind === 'kalaka/choice-field' && Array.isArray( field.edit.options ) ) {
		return Array.isArray( value ) ? 'multi' : 'choice';
	}
	return kind === 'kalaka/consent-field' ? 'consent' : null;
}

/** The control's state when the dialog opens: the server's `edit.answer.value`. */
export function openingOf( field ) {
	const value = field.edit.answer.value;
	switch ( controlOf( field ) ) {
		case 'consent':
			return value === true;
		case 'multi':
			return Array.isArray( value ) ? value : [];
		default:
			return value === null || value === undefined ? '' : String( value );
	}
}

/**
 * The value the ability takes: text and a single choice send null for empty (a clear), a list sends null when
 * empty, a consent sends "1" or "0". [UNAPPROVED N244]
 */
export function valueOf( field, picked ) {
	switch ( controlOf( field ) ) {
		case 'consent':
			return picked ? '1' : '0';
		case 'multi':
			return picked.length ? picked : null;
		default:
			return picked === '' ? null : picked;
	}
}

/** The ability's input; `expected` is the read's, untouched. */
export function inputOf( event, code, field, picked, reason ) {
	return {
		event_id: event,
		code,
		field: field.key,
		value: valueOf( field, picked ),
		expected: field.edit.expected === undefined ? null : field.edit.expected,
		reason,
	};
}

/**
 * Whether the picked value is one whose pool the read says would go over for this registration: a picked option
 * with `full`, or a ticked consent with `full`. [UNAPPROVED N236]
 */
export function isFull( field, picked ) {
	const edit = field.edit;
	switch ( controlOf( field ) ) {
		case 'consent':
			return picked === true && edit.full === true;
		case 'multi':
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

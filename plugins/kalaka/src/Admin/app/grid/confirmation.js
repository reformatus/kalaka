/**
 * The manual confirmation's pure logic (S20): the buttons of the confirmation row, the warning before the unconfirm,
 * the snackbar after a save, and the dialog's one check; and the icon a decision kind's state declares, which the
 * grid's cell draws. No WordPress imports, so Jest runs it as it is.
 */
import { CONFIRM } from './texts';

/**
 * The icon class and tone class of a state, from the kind's `marks` map as the server declares it (owner Q51 (b),
 * 2026-11-26; N1107). A state with no entry has no mark: the grid draws exactly what the server declares.
 */
export const markOf = ( marks, state ) => {
	const mark = marks && marks[ state ];
	if ( ! mark || ! mark.icon ) {
		return [ '', '' ];
	}
	return [ 'dashicons-' + mark.icon, mark.tone ? 'kg-' + mark.tone : '' ];
};

/** The longest reason the server takes (`kalaka/set-manual-confirmation` and `kalaka/unconfirm`), in characters. */
export const REASON_MAX = 500;

/**
 * The dialog's one check, on the text that is sent (trimmed): at most 500 characters (counted as the server counts
 * them, by character, not by UTF-16 unit); a message, or an empty string when it may be sent. The reason is never
 * required (owner 2026-11-26). The field itself has no length limit: its own `maxLength` would
 * count UTF-16 units, not characters.
 */
export function validateReason( reason ) {
	const text = String( reason ).trim();
	return Array.from( text ).length > REASON_MAX ? CONFIRM.reasonTooLong : '';
}

/**
 * The buttons of the manual confirmation block: `confirm` (the manual flag) with `can.confirm_manually` while the
 * flag is not set and the registration is not confirmed, so the two buttons never show together; it shows also when
 * the read does not carry the flag, which is only under the money gate: the set is idempotent; `unconfirm` only with
 * `can.unconfirm`, which the server makes true only on a confirmed registration, whatever the flag says, and with no
 * required reason. No status logic here: the server's `can` decides alone. A flag on a registration that is not
 * confirmed has no button that revokes it; the server clears the manual flag when the registration is withdrawn or
 * removed, which is Backend's work, not built here.
 *
 * @param {?Object}  manual    the read's flag: null when not set, undefined when the read does not carry it
 * @param {Object}   can       the read's `can`
 * @param {?boolean} confirmed the read's `registration.confirmed`; anything but `true` (an old server too) keeps the set
 */
export function manualActions( manual, can, confirmed ) {
	const actions = [];
	if ( can && can.confirm_manually && ! manual && confirmed !== true ) {
		actions.push( 'confirm' );
	}
	if ( can && can.unconfirm ) {
		actions.push( 'unconfirm' );
	}
	return actions;
}

/**
 * Whether the registration is withdrawn or removed: the confirmation row is not shown on one, since neither can
 * be confirmed or revoked any more (owner 2026-11-27). [UNAPPROVED N30 reboxed: the row is left out on
 * „Lemondott” and „Törölt” (the panel's own status key), as before while the read is stale or has no
 * `registration.confirmed`]
 *
 * @param {?string} status the read's `registration.status`
 */
export function cancelled( status ) {
	return status === 'withdrawn' || status === 'removed';
}

/**
 * Whether the unconfirm is disabled: the rules would confirm the registration again at once, which the read says
 * in `registration.unconfirm_reconfirms` (`payments`, `nothing_due`, or `yes` for a viewer without the money
 * view); null when it would really take effect. [UNAPPROVED N71 reboxed]
 */
export function unconfirmBlocked( registration ) {
	return typeof ( registration && registration.unconfirm_reconfirms ) === 'string';
}

/**
 * The reason the unconfirm is disabled: the server's own text from `registration.unconfirm_warning` (#213's T5a or
 * T5b for a viewer with the money view, T5c without it), only while `can.unconfirm` is true and the text is not
 * null. [UNAPPROVED N71 reboxed: no mail sentence (T6) in it — the owner dropped it on 2026-11-27; the client shows
 * the read's text as it comes and cuts nothing, so Backend must stop appending that sentence]
 * It is the visible help text under the disabled button, tied to it by `aria-describedby`, and it wraps as a
 * paragraph, so it reaches a touch screen too ([UNAPPROVED N1263]). The panel adds no wording and no rule of its
 * own. The text is as old as the panel's last read, and a holder without the money view gets the server's text for
 * that case.
 */
export function warningOf( registration, can ) {
	const text = registration && registration.unconfirm_warning;
	return can && can.unconfirm && typeof text === 'string' && text !== '' ? text : null;
}

/**
 * The snackbar after a save, from what the server answered and the re-read detail (null when the re-read failed:
 * the panel's load-error message shows, and no snackbar claims a state that was not read). `changed: false` wrote
 * nothing, so the panel only shows what is now. A set says it is saved; an unconfirm always says it was revoked,
 * as it cannot be sent while the rules would confirm again at once. The re-confirm snackbar is gone with its
 * string.
 *
 * @param {boolean}  unconfirm the act was the unconfirm (else the set)
 * @param {?Object}  result    the ability's answer: { changed }
 * @param {?Object}  detail    the re-read detail, null when the re-read failed
 * @return {?string} the snackbar text, or null for none
 */
export function snackbarAfter( unconfirm, result, detail ) {
	if ( ! detail || ( result && result.changed === false ) ) {
		return null;
	}
	return unconfirm ? CONFIRM.unconfirmed : CONFIRM.saved;
}

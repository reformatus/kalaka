/**
 * The manual confirmation's pure logic (S20): the buttons of the confirmation row, the warning before the unconfirm,
 * the snackbar after a save, and the dialog's one check. No WordPress imports, so Jest runs it as it is.
 */
import { CONFIRM } from './texts';

/** The longest reason the server takes (`kalaka/set-manual-confirmation` and `kalaka/unconfirm`), in characters. */
export const REASON_MAX = 500;

/**
 * The dialog's one check, on the text that is sent (trimmed): at most 500 characters (counted as the server counts
 * them, by character, not by UTF-16 unit); a message, or an empty string when it may be sent. The reason is never
 * required ([UNAPPROVED N1222], owner 2026-11-26). The field itself has no length limit: its own `maxLength` would
 * count UTF-16 units, not characters.
 */
export function validateReason( reason ) {
	const text = String( reason ).trim();
	return Array.from( text ).length > REASON_MAX ? CONFIRM.reasonTooLong : '';
}

/**
 * The buttons of the manual confirmation block: `confirm` (the manual flag) with `can.confirm_manually` while the
 * flag is not set and the registration is not confirmed, so the two buttons never show together ([UNAPPROVED N34
 * reboxed] [UNAPPROVED N75 reboxed]); it shows also when the read does not carry the flag, which is only under the
 * money gate: the set is idempotent; `unconfirm` only with `can.unconfirm`, which the server makes true only on a confirmed registration,
 * whatever the flag says ([UNAPPROVED N33 reboxed: confirmed only, with no required reason]). No status logic here:
 * the server's `can` decides alone. A flag on a registration that is not confirmed has no button that revokes it
 * ([UNAPPROVED N70 reboxed: the server clears the manual flag when the registration is withdrawn or removed, which
 * is Backend's work, not built here]).
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
 * Whether the unconfirm is disabled: the rules would confirm the registration again at once, which the read says
 * in `registration.unconfirm_reconfirms` (`payments`, `nothing_due`, or `yes` for a viewer without the money
 * view); null when it would really take effect. [UNAPPROVED N33 reboxed] [UNAPPROVED N71 reboxed]
 */
export function unconfirmBlocked( registration ) {
	return typeof ( registration && registration.unconfirm_reconfirms ) === 'string';
}

/**
 * The reason the unconfirm is disabled: the server's own text from `registration.unconfirm_warning` (#213's T5a,
 * T5b, T5c, with T6 appended when that mail is on), only while `can.unconfirm` is true and the text is not null.
 * It is the disabled button's tooltip. The panel adds no wording and no rule of its own. [UNAPPROVED N76 reboxed]
 * The text is as old as the panel's last read ([UNAPPROVED N79 reboxed]), and a holder without the money view gets
 * the server's text for that case, which was not seen in a browser ([UNAPPROVED N81 reboxed]).
 */
export function warningOf( registration, can ) {
	const text = registration && registration.unconfirm_warning;
	return can && can.unconfirm && typeof text === 'string' && text !== '' ? text : null;
}

/**
 * The snackbar after a save, from what the server answered and the re-read detail (null when the re-read failed:
 * the panel's load-error message shows, and no snackbar claims a state that was not read). `changed: false` wrote
 * nothing, so the panel only shows what is now. A set says it is saved; an unconfirm always says it was revoked,
 * as it cannot be sent while the rules would confirm again at once. [UNAPPROVED N72 reboxed] The re-confirm
 * snackbar is gone with its string ([UNAPPROVED N80 struck], T46 struck).
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

/** The refusal for a number control that holds text the browser cannot parse (HTML's `badInput`). */
export const BAD_NUMBER = 'Csak számot írhatsz ide.';

/**
 * The first shown number field whose native control holds invalid text (`validity.badInput`: the browser
 * reports an empty value) or that the number guard refused (`validity.customError`: the field was emptied):
 * the send is stopped and the field says why.
 *
 * @param {HTMLFormElement} form
 * @return {{wrapper: Element, control: HTMLInputElement}|null}
 */
export function firstBadNumber(form) {
	for (const wrapper of form.querySelectorAll('[data-kalaka-field]')) {
		const control = [...wrapper.querySelectorAll('input, select, textarea')].find(
			(candidate) =>
				candidate.name !== '' &&
				!candidate.disabled &&
				(candidate.validity?.badInput === true || candidate.validity?.customError === true)
		);
		if (control !== undefined) {
			return { wrapper, control };
		}
	}

	return null;
}

/** The offsets of the affixes in core's neutral CSS (0.75rem and 2rem). */
const PREFIX_OFFSET = 12;
const SUFFIX_OFFSET = 32;

/**
 * Sizes the room each affix needs, on the wrapper, so no fixed rem can overlap the digits (A3). It measures
 * each affix once, on resize and after the fonts load; the CSS reads the two properties.
 *
 * @param {Document} doc
 */
export function measureNumberAffixes(doc = document) {
	for (const wrapper of doc.querySelectorAll('.kalaka-number')) {
		const prefix = wrapper.querySelector('.kalaka-number__prefix');
		const suffix = wrapper.querySelector('.kalaka-number__suffix');
		if (prefix !== null) {
			wrapper.style.setProperty(
				'--kalaka-number-prefix-room',
				`${prefix.offsetWidth + PREFIX_OFFSET}px`
			);
		}
		if (suffix !== null) {
			wrapper.style.setProperty(
				'--kalaka-number-suffix-room',
				`${suffix.offsetWidth + SUFFIX_OFFSET}px`
			);
		}
	}
}

/**
 * The number field's wheel guard (owner ruling, topic 7): the mouse wheel never changes a focused number
 * field. The event is left to the page, so the wheel scrolls as usual; the field loses focus instead.
 *
 * @param {Document} doc the document whose number inputs to guard
 */
export function guardNumberWheel(doc = document) {
	doc.addEventListener(
		'wheel',
		(event) => {
			const target = event.target;
			if (!(target instanceof Element) || target.tagName !== 'INPUT' || target.type !== 'number') {
				return;
			}
			if (doc.activeElement === target) {
				target.blur();
			}
		},
		{ passive: true }
	);
}

/** The refusal for a separator typed into a whole-number field (the server's own text, as the client's). */
export const WHOLE_NUMBER = 'Csak egész számot írhatsz ide.';

/** The input types that carry text into the control. */
const INSERTS = [
	'insertText',
	'insertFromPaste',
	'insertFromDrop',
	'insertReplacementText',
	'insertCompositionText',
];

/** A paste in Hungarian grouping ("5 000", "1 234 567,5"): spaces, no-break spaces, narrow no-break spaces. */
const GROUPED = /^-?\d{1,3}([ \u00a0\u202f]\d{3})+(,\d+)?$/;

/**
 * The comma guard (B4): Chromium stores a typed "1,5" in a number control as 15 (the comma dropped, no
 * `badInput`) and sends "1 5" as 15, a silent times ten. Before the input changes, for every insert that
 * carries text: valid text goes in (digits, a leading minus, on a decimal field one dot; a typed comma becomes
 * a dot, a pasted "1,5" is pasted with a dot, a pasted Hungarian grouping is pasted without its spaces; a
 * replacement text that is a plain number, Firefox's spinner step, is accepted) and clears any refusal.
 * Anything else is refused: the insert is cancelled, the field is EMPTIED (it cannot carry a sanitised wrong
 * number), the refusal is shown, and the control's custom validity holds the same text, so the send stops
 * with the field's own message. A second dot is refused as any other, and a paste whose result is not what was
 * pasted (Chromium drops a pasted dot put into the middle of a number) is refused after the browser applied
 * it. There is no dead end: the next accepted insert, a deletion or the spinner
 * clears the refusal. The native control and its arrows stay. Client-side only: the server still refuses what
 * it cannot parse.
 *
 * @param {Document}                                       doc
 * @param {function(HTMLInputElement, string): void}       refuse Shows the refusal in the field's error element.
 * @param {function(HTMLInputElement): void}               clear Removes that message when the field is edited again.
 */
export function guardNumberSeparators(doc, refuse, clear = () => {}) {
	// Chromium reports a number control's SANITISED value, so after "1." it reads "1": the trailing dot is
	// invisible to the script, and a number control exposes no caret (selectionStart is null). So "the field
	// already holds a dot" cannot be read from `value` alone. The guard tracks it: `dotted` maps a control to
	// the value it read right after an insert or deletion that left a dot in it, the dot being visible in the
	// value or not. A control is dotted while its value still equals that snapshot (a value set by script
	// since then makes the flag stale, so it is ignored) or its value contains a dot. A typed dot is refused
	// in a dotted control. After a deletion the dot is kept when the value has one, or when it was cut back
	// to the integer part of the old one ("1.5" -> "1": the dot is the trailing one); any other result drops
	// it. A spinner step recomputes it from the value, a refusal clears it.
	const dotted = new WeakMap();
	const isDotted = (control) =>
		control.value.includes('.') || (dotted.has(control) && dotted.get(control) === control.value);
	// What each control held, and whether it was dotted, before the insert or deletion now under way.
	const before = new WeakMap();
	// The text a multi-character insert (a paste, a drop) is expected to leave in a control.
	const pending = new WeakMap();
	const isNumber = (control) =>
		control instanceof Element && control.tagName === 'INPUT' && control.type === 'number';
	const refusing = (control, message) => {
		control.value = '';
		dotted.delete(control);
		pending.delete(control);
		control.setCustomValidity(message);
		refuse(control, message);
	};
	const clearing = (control) => {
		if (control.validity?.customError) {
			control.setCustomValidity('');
			clear(control);
		}
	};
	// Whatever changes the field after a refusal (the spinner, a deletion) leaves no refusal behind.
	doc.addEventListener('input', (event) => {
		const control = event.target;
		if (!isNumber(control)) {
			return;
		}
		const value = control.value;
		const type = event.inputType ?? '';
		const was = before.get(control);
		const expected = pending.get(control);
		before.delete(control);
		pending.delete(control);
		let dot = value.includes('.');
		if (was !== undefined && type.startsWith('delete')) {
			dot = dot || (was.value.includes('.') && value !== '' && value === was.value.split('.')[0]);
		} else if (was !== undefined && INSERTS.includes(type) && type !== 'insertReplacementText') {
			dot = dot || was.dotted || was.adds;
		}
		if (dot) {
			dotted.set(control, value);
		} else {
			dotted.delete(control);
		}
		// A multi-character insert is checked after the browser applied it. Chromium drops the dot of a pasted
		// "1.5" put into the middle of "2.5" and stores 215.5, and with no caret to read the guard cannot tell
		// beforehand. The result must contain the inserted text verbatim: a full replacement leaves exactly it,
		// an insertion keeps it as a substring ("25" + "5000" -> "250005"). Text with a dot put into a control
		// that held one can only be a full replacement, so the value must be exactly it. A trailing dot is
		// invisible in the value, so it is not compared. Text the browser rejects (`badInput`) is left for the
		// send to catch.
		if (expected !== undefined && control.validity?.badInput !== true) {
			const kept = expected.exact ? value === expected.text : value.includes(expected.text);
			if (!kept) {
				refusing(control, BAD_NUMBER);
				return;
			}
		}
		clearing(control);
	});
	doc.addEventListener('beforeinput', (event) => {
		const control = event.target;
		if (!isNumber(control)) {
			return;
		}
		const entry = { value: control.value, dotted: isDotted(control), adds: false };
		before.set(control, entry);
		if (event.inputType?.startsWith('delete')) {
			clearing(control);
			return;
		}
		if (!INSERTS.includes(event.inputType)) {
			return;
		}
		const data = event.data ?? event.dataTransfer?.getData('text/plain') ?? '';
		const decimal = control.getAttribute('inputmode') === 'decimal';
		const typedComma = decimal && event.inputType === 'insertText' && data === ',';
		let text = data;
		if (typedComma) {
			text = '.';
		} else if (event.inputType !== 'insertText' && GROUPED.test(data)) {
			text = data.replace(/[ \u00a0\u202f]/g, '');
			text = decimal ? text.replace(',', '.') : text;
		} else if (decimal && event.inputType !== 'insertText' && /^-?\d+,\d+$/.test(data)) {
			text = data.replace(',', '.');
		}
		const valid = decimal ? /^-?\d*\.?\d*$/ : /^-?\d*$/;
		const secondDot = decimal && text === '.' && entry.dotted;
		if (!valid.test(text) || secondDot) {
			event.preventDefault();
			refusing(control, !decimal && /[,.]/.test(data) ? WHOLE_NUMBER : BAD_NUMBER);
			return;
		}
		clearing(control);
		entry.adds = text.includes('.');
		if (text.length > 1) {
			pending.set(control, { text: text.replace(/\.$/, ''), exact: entry.adds && entry.dotted });
		}
		if (text !== data) {
			event.preventDefault();
			if (typeof doc.execCommand === 'function' && doc.execCommand('insertText', false, text)) {
				return;
			}
			refusing(control, BAD_NUMBER);
		}
	});
}

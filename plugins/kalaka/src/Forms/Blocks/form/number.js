/** The refusal for a number control that holds text the browser cannot parse (HTML's `badInput`). */
// [UNAPPROVED T4]
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
 * with the field's own message. There is no dead end: the next accepted insert, a deletion or the spinner
 * clears the refusal. The native control and its arrows stay. Client-side only: the server still refuses what
 * it cannot parse.
 *
 * @param {Document}                                       doc
 * @param {function(HTMLInputElement, string): void}       refuse Shows the refusal in the field's error element.
 * @param {function(HTMLInputElement): void}               clear Removes that message when the field is edited again.
 */
export function guardNumberSeparators(doc, refuse, clear = () => {}) {
	const isNumber = (control) =>
		control instanceof Element && control.tagName === 'INPUT' && control.type === 'number';
	const refusing = (control, message) => {
		control.value = '';
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
		if (isNumber(event.target)) {
			clearing(event.target);
		}
	});
	doc.addEventListener('beforeinput', (event) => {
		const control = event.target;
		if (!isNumber(control)) {
			return;
		}
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
		const secondDot = decimal && text === '.' && control.value.includes('.');
		if (!valid.test(text) || secondDot) {
			event.preventDefault();
			refusing(control, !decimal && /[,.]/.test(data) ? WHOLE_NUMBER : BAD_NUMBER);
			return;
		}
		clearing(control);
		if (text !== data) {
			event.preventDefault();
			if (typeof doc.execCommand === 'function' && doc.execCommand('insertText', false, text)) {
				return;
			}
			refusing(control, BAD_NUMBER);
		}
	});
}

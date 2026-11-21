/** The refusal for a number control that holds text the browser cannot parse (HTML's `badInput`). */
// [UNAPPROVED T4]
export const BAD_NUMBER = 'Csak számot írhatsz ide.';

/**
 * The first shown number field whose native control holds invalid text (`validity.badInput`): the browser
 * reports an empty value, so the send is stopped and the field says why.
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
				candidate.validity?.badInput === true
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

const TYPED = ['insertText', 'insertFromPaste', 'insertReplacementText'];

/**
 * The comma guard (B4): Chromium stores a typed "1,5" in a number control as 15 (the comma dropped, no
 * `badInput`), a silent times ten. Before the input changes: a whole-number field (`inputmode="numeric"`)
 * refuses any comma or dot; a decimal field turns a typed comma into a dot, and a pasted "1,5" into "1.5",
 * and refuses other pasted text with a comma. The native control and its arrows stay. Client-side only: the
 * server still refuses what it cannot parse.
 *
 * @param {Document}                                       doc
 * @param {function(HTMLInputElement, string): void}       refuse Shows the refusal in the field's error element.
 */
export function guardNumberSeparators(doc, refuse) {
	doc.addEventListener('beforeinput', (event) => {
		const control = event.target;
		if (
			!(control instanceof Element) ||
			control.tagName !== 'INPUT' ||
			control.type !== 'number' ||
			!TYPED.includes(event.inputType)
		) {
			return;
		}
		const data = event.data ?? event.dataTransfer?.getData('text/plain') ?? '';
		if (control.getAttribute('inputmode') !== 'decimal') {
			if (/[,.]/.test(data)) {
				event.preventDefault();
				refuse(control, WHOLE_NUMBER);
			}
			return;
		}
		if (!data.includes(',')) {
			return;
		}
		const typed = event.inputType === 'insertText' && data === ',';
		const pasted = event.inputType !== 'insertText' && /^-?\d+,\d+$/.test(data);
		event.preventDefault();
		if (typed || pasted) {
			const text = typed ? '.' : data.replace(',', '.');
			if (typeof doc.execCommand === 'function' && doc.execCommand('insertText', false, text)) {
				return;
			}
		}
		refuse(control, BAD_NUMBER);
	});
}

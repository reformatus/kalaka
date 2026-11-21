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

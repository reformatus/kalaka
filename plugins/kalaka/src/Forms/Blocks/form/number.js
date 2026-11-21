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

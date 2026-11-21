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

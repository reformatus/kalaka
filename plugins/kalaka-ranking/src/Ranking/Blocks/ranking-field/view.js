/**
 * The ranking field on the form page (`render.php`): its options are the browser's own checkboxes, named
 * `<key>[]`, and the form sends the ticked ones in document order, so the order of the ranked list is the
 * answer. Ticking an option adds it at the end of the ranked list (`data-kalaka-ranked`); unticking it puts
 * it back among the others (`data-kalaka-unranked`) at its authored place. A ranked option's two buttons
 * move it up or down, by the keyboard as by the pointer; the focus stays on the button. After a move the
 * option's checkbox fires `change`, which bubbles to the form like any answer's change.
 *
 * It holds no rule: which options are offered, and how many may be ranked, the server checks at submit.
 */

const RANKED = '[data-kalaka-ranked]';
const UNRANKED = '[data-kalaka-unranked]';

function checkboxOf(item) {
	return item.querySelector('input[type="checkbox"]');
}

function buttonsOf(item) {
	return [...item.querySelectorAll('[data-kalaka-move]')];
}

function position(item) {
	return Number(item.getAttribute('data-kalaka-option'));
}

/** The buttons of a ranked option show; an unranked option has none. */
function showButtons(item, ranked) {
	for (const button of buttonsOf(item)) {
		button.hidden = !ranked;
	}
}

/** Puts an unticked option back among the others, at its authored place. */
function unrank(field, item) {
	const others = field.querySelector(UNRANKED);
	const after = [...others.children].find((other) => position(other) > position(item));
	others.insertBefore(item, after ?? null);
	showButtons(item, false);
}

/** Adds a ticked option at the end of the ranked list. */
function rank(field, item) {
	field.querySelector(RANKED).appendChild(item);
	showButtons(item, true);
}

/**
 * Moves a ranked option one place up or down. The neighbour moves, not the option, so the focused button
 * never leaves the document. Returns whether anything moved.
 */
export function move(item, direction) {
	const list = item.parentElement;
	if (direction === 'up' && item.previousElementSibling !== null) {
		list.insertBefore(item.previousElementSibling, item.nextElementSibling);
		return true;
	}
	if (direction === 'down' && item.nextElementSibling !== null) {
		list.insertBefore(item.nextElementSibling, item);
		return true;
	}
	return false;
}

/** Wires one ranking field (the element with `data-kalaka-ranking`). */
export function start(field) {
	field.addEventListener('change', (event) => {
		const box = event.target;
		const item = box?.closest?.('[data-kalaka-option]');
		if (item === null || item === undefined || box !== checkboxOf(item)) {
			return;
		}
		const inRanked = item.parentElement === field.querySelector(RANKED);
		if (box.checked && !inRanked) {
			rank(field, item);
		} else if (!box.checked && inRanked) {
			unrank(field, item);
		}
	});
	field.addEventListener('click', (event) => {
		const button = event.target?.closest?.('[data-kalaka-move]');
		if (button === null || button === undefined) {
			return;
		}
		const item = button.closest('[data-kalaka-option]');
		if (move(item, button.getAttribute('data-kalaka-move'))) {
			checkboxOf(item).dispatchEvent(new Event('change', { bubbles: true }));
		}
	});
}

for (const field of document.querySelectorAll('[data-kalaka-ranking]')) {
	start(field);
}

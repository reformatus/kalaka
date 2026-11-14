/**
 * The ranking field on the form page (`render.php`): its options are the browser's own checkboxes, named
 * `<key>[]`, and the form sends the ticked ones in document order, so the order of the ranked list is the
 * answer. Ticking an option adds it at the end of the ranked list (`data-kalaka-ranked`); unticking it puts
 * it back among the others (`data-kalaka-unranked`) at its authored place.
 *
 * A ranked option is reordered by dragging it (pointer events: a mouse, a pen or a finger) or by its two
 * buttons, the keyboard's way; the focus stays on a pressed button. After a move the option's checkbox
 * fires `change`, which bubbles to the form like any answer's change, and the field's live region tells a
 * screen reader the option's new place. Dragging needs no library: WordPress ships no drag support for a
 * view script (its `Draggable` is a React component of the editor), so it is a few pointer handlers.
 *
 * It holds no rule: which options are offered, and how many may be ranked, the server checks at submit.
 */

const RANKED = '[data-kalaka-ranked]';
const UNRANKED = '[data-kalaka-unranked]';

/** How far, in CSS pixels, a pointer moves before a press on a ranked option becomes a drag. */
export const DRAG_THRESHOLD = 6;

/** What the live region says after a move: the option's label and its new place (text in N9). */
export const announcement = (label, place) => `${label}, ${place}. hely`;

function checkboxOf(item) {
	return item.querySelector('input[type="checkbox"]');
}

function buttonsOf(item) {
	return [...item.querySelectorAll('[data-kalaka-move]')];
}

function position(item) {
	return Number(item.getAttribute('data-kalaka-option'));
}

function labelOf(item) {
	return (item.querySelector('label')?.textContent ?? '').trim();
}

/** A ranked option shows its buttons and takes a drag without scrolling the page; an unranked one neither. */
function setRanked(item, ranked) {
	for (const button of buttonsOf(item)) {
		button.hidden = !ranked;
	}
	item.style.touchAction = ranked ? 'none' : '';
}

/** Puts an unticked option back among the others, at its authored place. */
function unrank(field, item) {
	const others = field.querySelector(UNRANKED);
	const after = [...others.children].find((other) => position(other) > position(item));
	others.insertBefore(item, after ?? null);
	setRanked(item, false);
}

/** Adds a ticked option at the end of the ranked list. */
function rank(field, item) {
	field.querySelector(RANKED).appendChild(item);
	setRanked(item, true);
}

/** Tells a screen reader where the option now is (1 = most wanted). */
function announce(field, item) {
	const region = field.querySelector('[data-kalaka-announce]');
	if (region !== null) {
		region.textContent = announcement(labelOf(item), [...item.parentElement.children].indexOf(item) + 1);
	}
}

/** The form learns the new order: a `change` of the option's checkbox. */
function moved(field, item) {
	announce(field, item);
	checkboxOf(item).dispatchEvent(new Event('change', { bubbles: true }));
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

/**
 * The place a dragged option goes to: the number of the other options whose middle lies above the pointer.
 *
 * @param {number[]} middles The vertical middles of the other options, in list order.
 * @param {number}   y       The pointer's vertical position, in the same coordinates.
 * @return {number} The index among the other options to insert before (their count: at the end).
 */
export function dropIndex(middles, y) {
	return middles.filter((middle) => middle < y).length;
}

/** Puts the option at the index among the list's other options. Returns whether its place changed. */
export function placeAt(item, index) {
	const list = item.parentElement;
	const others = [...list.children].filter((other) => other !== item);
	const before = others[index] ?? null;
	if (before === item.nextElementSibling || (before === null && item.nextElementSibling === null)) {
		return false;
	}
	list.insertBefore(item, before);
	return true;
}

/** Whether a press starts on a control of its own (the checkbox, a button), which never drags. */
function onControl(target) {
	return target?.closest?.('input, button') != null;
}

/**
 * Dragging within the ranked list. The list holds the pointer capture, since the option itself moves in
 * the document while it is dragged. A press that moves less than `DRAG_THRESHOLD` stays a click (a label's
 * click ticks its checkbox); after a drag, the click that follows is swallowed.
 */
function startDragging(field) {
	const list = field.querySelector(RANKED);
	let press = null;
	let swallowClick = false;

	// The click a drag ends with is not a tick of the option.
	list.addEventListener(
		'click',
		(event) => {
			if (swallowClick) {
				swallowClick = false;
				event.preventDefault();
				event.stopPropagation();
			}
		},
		{ capture: true }
	);

	list.addEventListener('pointerdown', (event) => {
		const item = event.target?.closest?.('[data-kalaka-option]');
		if (press !== null || item == null || item.parentElement !== list || onControl(event.target)) {
			return;
		}
		if (event.pointerType === 'mouse' && event.button !== 0) {
			return;
		}
		if (event.pointerType === 'mouse') {
			// No text selection while a mouse drags; the click that follows a press still ticks.
			event.preventDefault();
		}
		press = { id: event.pointerId, item, startY: event.clientY, dragging: false, changed: false };
	});

	list.addEventListener('pointermove', (event) => {
		if (press === null || event.pointerId !== press.id) {
			return;
		}
		if (!press.dragging) {
			if (Math.abs(event.clientY - press.startY) < DRAG_THRESHOLD) {
				return;
			}
			press.dragging = true;
			list.setPointerCapture?.(event.pointerId);
			press.item.setAttribute('data-kalaka-dragging', '');
			press.item.style.opacity = '0.5';
		}
		event.preventDefault();
		const others = [...list.children].filter((other) => other !== press.item);
		const middles = others.map((other) => {
			const box = other.getBoundingClientRect();
			return box.top + box.height / 2;
		});
		if (placeAt(press.item, dropIndex(middles, event.clientY))) {
			press.changed = true;
		}
	});

	const end = (event) => {
		if (press === null || event.pointerId !== press.id) {
			return;
		}
		const { item, dragging, changed } = press;
		press = null;
		if (!dragging) {
			return;
		}
		item.removeAttribute('data-kalaka-dragging');
		item.style.opacity = '';
		swallowClick = true;
		// A drag that ended outside the option is followed by no click on it.
		setTimeout(() => {
			swallowClick = false;
		}, 0);
		if (changed) {
			moved(field, item);
		}
	};
	list.addEventListener('pointerup', end);
	list.addEventListener('pointercancel', end);
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
			moved(field, item);
		}
	});
	for (const item of field.querySelectorAll(`${RANKED} > [data-kalaka-option]`)) {
		setRanked(item, true);
	}
	startDragging(field);
}

for (const field of document.querySelectorAll('[data-kalaka-ranking]')) {
	start(field);
}

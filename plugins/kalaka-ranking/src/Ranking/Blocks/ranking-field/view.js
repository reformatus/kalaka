/**
 * The ranking field on the form page (`render.php`): its options are the browser's own checkboxes, named
 * `<key>[]`, and the form sends the ticked ones in document order, so the order of the ranked list is the
 * answer. Ticking an option adds it at the end of the ranked list (`data-kalaka-ranked`); unticking it puts
 * it back among the others (`data-kalaka-unranked`) at its authored place. The focus stays on the checkbox
 * that was ticked or unticked.
 *
 * A ranked option is reordered by dragging its handle (pointer events: a mouse, a pen or a finger; owner
 * 2026-11-16: only the handle drags, the rest of the row scrolls and taps as usual) or by its two
 * buttons, the keyboard's and the screen reader's way; the focus stays on a pressed button. The first option's up button and the
 * last one's down button are `aria-disabled` (they stay focusable, so a move that makes a button an edge
 * leaves the focus where it was). After a move the option's checkbox fires `change`, which bubbles to the
 * form like any answer's change. The field's live region tells a screen reader an option's place after a
 * tick or a move, and is emptied after an untick, so it never holds a place that is no longer true.
 * Dragging needs no library: WordPress ships no drag support for a view script (its `Draggable` is a React
 * component of the editor), so it is a few pointer handlers.
 *
 * It holds no rule: which options are offered, and how many may be ranked, the server checks at submit.
 */

const RANKED = '[data-kalaka-ranked]';
const UNRANKED = '[data-kalaka-unranked]';

/** How far, in CSS pixels, a pointer moves before a press on a ranked option becomes a drag. */
export const DRAG_THRESHOLD = 6;

/** What the live region says after a tick or a move: the option's label and its place (text in N9). */
export const announcement = (label, place) => `${label}, ${place}. hely`;

function checkboxOf(item) {
	return item.querySelector('input[type="checkbox"]');
}

function buttonsOf(item) {
	return [...item.querySelectorAll('[data-kalaka-move]')];
}

function handleOf(item) {
	return item.querySelector('[data-kalaka-handle]');
}

function position(item) {
	return Number(item.getAttribute('data-kalaka-option'));
}

function labelOf(item) {
	return (item.querySelector('label')?.textContent ?? '').trim();
}

function placeOf(item) {
	return [...item.parentElement.children].indexOf(item) + 1;
}

/**
 * A ranked option shows its handle and its buttons; an unranked one neither. What a drag needs (no
 * scrolling, no selection, no callout) is the handle's own, in its markup.
 */
function setRanked(item, ranked) {
	for (const button of buttonsOf(item)) {
		button.hidden = !ranked;
	}
	const handle = handleOf(item);
	if (handle !== null) {
		handle.hidden = !ranked;
	}
}

/** The edge buttons of the ranked list are `aria-disabled`: up on the first option, down on the last. */
export function markEdges(field) {
	const items = [...field.querySelector(RANKED).children];
	items.forEach((item, index) => {
		for (const button of buttonsOf(item)) {
			const direction = button.getAttribute('data-kalaka-move');
			const edge = (direction === 'up' && index === 0) || (direction === 'down' && index === items.length - 1);
			if (edge) {
				button.setAttribute('aria-disabled', 'true');
			} else {
				button.removeAttribute('aria-disabled');
			}
		}
	});
	for (const item of field.querySelector(UNRANKED).children) {
		for (const button of buttonsOf(item)) {
			button.removeAttribute('aria-disabled');
		}
	}
}

/** Runs a move of the option, keeping the focus on the element inside it that held it. */
function keepingFocus(item, change) {
	const focused = item.ownerDocument.activeElement;
	change();
	if (focused instanceof HTMLElement && item.contains(focused) && item.ownerDocument.activeElement !== focused) {
		focused.focus({ preventScroll: true });
	}
}

/** Puts an unticked option back among the others, at its authored place. */
function unrank(field, item) {
	const others = field.querySelector(UNRANKED);
	const after = [...others.children].find((other) => position(other) > position(item));
	keepingFocus(item, () => others.insertBefore(item, after ?? null));
	setRanked(item, false);
	markEdges(field);
}

/** Adds a ticked option at the end of the ranked list. */
function rank(field, item) {
	const list = field.querySelector(RANKED);
	keepingFocus(item, () => list.appendChild(item));
	setRanked(item, true);
	markEdges(field);
}

function regionOf(field) {
	return field.querySelector('[data-kalaka-announce]');
}

/** Tells a screen reader where the option now is (1 = most wanted). */
function announce(field, item) {
	const region = regionOf(field);
	if (region !== null) {
		region.textContent = announcement(labelOf(item), placeOf(item));
	}
}

/** Empties the live region: an unranked option has no place to tell. */
function silence(field) {
	const region = regionOf(field);
	if (region !== null) {
		region.textContent = '';
	}
}

/** The form learns the new order: a `change` of the option's checkbox. */
function moved(field, item) {
	markEdges(field);
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


/**
 * Dragging within the ranked list, by an option's handle only. The list holds the pointer capture once a
 * press becomes a drag, since
 * the option itself moves in the document while it is dragged. A press that moves less than
 * `DRAG_THRESHOLD` stays a click; after a drag, the click that follows
 * is swallowed. A press ends wherever its pointer is released or cancelled (the window's `pointerup` and
 * `pointercancel`, the list's `lostpointercapture`), and a move with no button held ends it too, so
 * nothing reorders without a held pointer. A drag that ends where it started tells nothing.
 */
function startDragging(field) {
	const list = field.querySelector(RANKED);
	const view = field.ownerDocument.defaultView ?? window;
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

	const end = (event) => {
		if (press === null || (event.pointerId !== undefined && event.pointerId !== press.id)) {
			return;
		}
		const { item, dragging, from } = press;
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
		if (item.parentElement === list && placeOf(item) !== from) {
			moved(field, item);
		}
	};

	list.addEventListener('pointerdown', (event) => {
		const handle = event.target?.closest?.('[data-kalaka-handle]');
		const item = handle?.closest('[data-kalaka-option]');
		if (press !== null || item == null || item.parentElement !== list) {
			return;
		}
		if (event.pointerType === 'mouse' && event.button !== 0) {
			return;
		}
		if (event.pointerType === 'mouse') {
			// No text selection while a mouse drags.
			event.preventDefault();
		}
		press = { id: event.pointerId, item, startY: event.clientY, dragging: false, from: placeOf(item) };
	});

	list.addEventListener('pointermove', (event) => {
		if (press === null || event.pointerId !== press.id) {
			return;
		}
		if (event.buttons === 0) {
			// The button was released where no `pointerup` reached us: the press is over.
			end(event);
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
		placeAt(press.item, dropIndex(middles, event.clientY));
	});

	list.addEventListener('lostpointercapture', end);
	view.addEventListener('pointerup', end, true);
	view.addEventListener('pointercancel', end, true);
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
			announce(field, item);
		} else if (!box.checked && inRanked) {
			unrank(field, item);
			silence(field);
		}
	});
	field.addEventListener('click', (event) => {
		const button = event.target?.closest?.('[data-kalaka-move]');
		if (button === null || button === undefined || button.getAttribute('aria-disabled') === 'true') {
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
	markEdges(field);
	startDragging(field);
}

for (const field of document.querySelectorAll('[data-kalaka-ranking]')) {
	start(field);
}

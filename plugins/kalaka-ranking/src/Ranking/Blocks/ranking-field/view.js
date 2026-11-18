/**
 * The ranking field on the form page (`render.php`): its options are the browser's own checkboxes, named
 * `<key>[]`, and the form sends the ticked ones in document order, so the order of the ranked list is the
 * answer. Each checkbox is drawn as „+” while unticked and „×” while ticked (owner, r2), and its
 * accessible name says what it does (`addName()` / `removeName()`, swapped here with its state; wording
 * after the lektor, r2). Ticking an option adds it at the end of the ranked list (`data-kalaka-ranked`);
 * unticking it puts it back among the others (`data-kalaka-unranked`) at its authored place. Enter works
 * the toggle like Space, as on a button, instead of sending the form. The focus stays on the toggle that was used.
 *
 * A ranked option is reordered by a drag or by its two icon buttons, the keyboard's and the screen
 * reader's way (owner, r2):
 * - a mouse drags from anywhere on the row at once;
 * - a finger or a pen drags by the handle at once (the only part of the row with `touch-action: none`),
 *   or from anywhere else on the row after a long press (`LONG_PRESS`); a swipe without the hold scrolls
 *   the page as usual;
 * - a press on a button or the toggle never drags, and a tap on the option's text does nothing.
 * The row selects no text and opens no context menu (`style.css`, and `contextmenu` here). The focus stays
 * on a pressed button. The first option's up button and the last one's down button are `aria-disabled`
 * (they stay focusable, so a move that makes a button an edge leaves the focus where it was). After a
 * move the option's checkbox fires `change`, which bubbles to the form like any answer's change. The
 * field's live region tells a screen reader an option's place after a tick or a move, and is emptied
 * after an untick, so it never holds a place that is no longer true. Dragging needs no library:
 * WordPress ships no drag support for a view script (its `Draggable` is a React component of the
 * editor), so it is a few pointer handlers.
 *
 * It holds no rule: which options are offered, and how many may be ranked, the server checks at submit.
 */

const RANKED = '[data-kalaka-ranked]';
const UNRANKED = '[data-kalaka-unranked]';

/** How far, in CSS pixels, a pointer moves before a press on a ranked option becomes a drag. */
export const DRAG_THRESHOLD = 6;

/** How long, in milliseconds, a finger rests on a ranked row before the row drags. */
export const LONG_PRESS = 300;

/** How far, in CSS pixels, a finger may move during the long press before it is a swipe instead. */
export const PRESS_SLOP = 10;

/** The toggle's accessible name while the option is unranked (`RankingFieldType::ADD_NAME`). */
export const addName = (label) => `${label} hozzáadása a sorrendhez`;

/** The toggle's accessible name while the option is ranked (`RankingFieldType::REMOVE_NAME`). */
export const removeName = (label) => `${label} eltávolítása a sorrendből`;

/** What the live region says after a tick or a move: the option's label and its place (owner, Q4). */
export const announcement = (label, place) => `${label}, ${place}. helyen`;

/** What the live region says after an untick (owner, Q4). */
export const removal = (label) => `${label}, kivéve a sorrendből`;

/** The live counter under the ranked options (`RankingFieldType::COUNTER`; owner, Q1). */
export const counterText = (count, max) => `${count} / ${max} kiválasztva`;

/** A count in the texts below: „egy” for one, else the number (owner, Q1). */
const amount = (n) => (n === 1 ? 'egy' : String(n));

/**
 * The line in the first empty row while the minimum is not reached (`RankingFieldType::minimumLine()`;
 * owner, Q1): with nothing added, the minimum; from the first addition on, how many are still missing.
 */
export const minimumLine = (min, count) =>
	count === 0
		? `Adj hozzá legalább ${amount(min)} lehetőséget a sorrendhez.`
		: `Adj hozzá még legalább ${amount(min - count)} lehetőséget a sorrendhez.`;

/**
 * Said when a tick reaches the maximum, and again on a „+” pressed at the maximum (owner, Q1). Only the script
 * says it, so it has no copy in `RankingFieldType`.
 */
export const fullText = (max) => `Megtelt a sorrend: legfeljebb ${amount(max)} lehetőséget választhatsz.`;

/** What the live region says when a tick reaches the maximum: the place, then that the order is full. */
export const atLimit = (label, place, max) => `${announcement(label, place)}. ${fullText(max)}`;

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
	return (item.querySelector('[data-kalaka-label]')?.textContent ?? '').trim();
}

function placeOf(item) {
	return [...item.parentElement.children].indexOf(item) + 1;
}

/**
 * A ranked option shows its handle and its buttons; an unranked one neither. Its toggle's name says what
 * it now does.
 */
function setRanked(item, ranked) {
	for (const button of buttonsOf(item)) {
		button.hidden = !ranked;
	}
	const handle = handleOf(item);
	if (handle !== null) {
		handle.hidden = !ranked;
	}
	const label = labelOf(item);
	checkboxOf(item).setAttribute('aria-label', ranked ? removeName(label) : addName(label));
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

/** A text waiting to be said again, per field, so that a later text is never overwritten by it. */
const pending = new WeakMap();

function say(field, text) {
	clearTimeout(pending.get(field));
	const region = regionOf(field);
	if (region !== null) {
		region.textContent = text;
	}
}

/** How long, in milliseconds, the live region stays empty before a text is said again. */
export const SAY_AGAIN_DELAY = 100;

/**
 * Says a text that the live region may hold already: the region is emptied first and takes the text a
 * moment later, since a region whose text does not change says nothing.
 */
function sayAgain(field, text) {
	say(field, '');
	pending.set(
		field,
		setTimeout(() => say(field, text), SAY_AGAIN_DELAY)
	);
}

/** Tells a screen reader where the option now is (1 = most wanted). */
function announce(field, item) {
	say(field, announcement(labelOf(item), placeOf(item)));
}

/** The field's bounds and whether it must be answered, as `render.php` writes them on the field. */
export function boundsOf(field) {
	const min = Number(field.getAttribute('data-kalaka-min')) || 1;
	const max = Math.max(min, Number(field.getAttribute('data-kalaka-max')) || min);

	return { min, max, required: field.hasAttribute('data-kalaka-required') };
}

/**
 * How many empty rows show under the ranked options: as many as the minimum still lacks. On an optional
 * field none until the first option is added (owner, Q2: none, or at least the minimum).
 */
export function emptyRows(count, { min, required }) {
	return required || count > 0 ? Math.max(0, min - count) : 0;
}

/**
 * The id of the first empty row, which holds the minimum's line: every toggle is described by it and by
 * the counter (`render.php`; `FieldMarkup::controlId()` plus `-minimum`).
 */
function minimumIdOf(field) {
	return `kalaka-field-${field.getAttribute('data-kalaka-field')}-minimum`;
}

/**
 * Brings the counter, the empty rows and the „+” toggles up to date with the ranked count: at the maximum
 * every unranked option's „+” is `aria-disabled` (it stays focusable) and does nothing (owner, Q1).
 */
export function refresh(field) {
	const bounds = boundsOf(field);
	const count = field.querySelector(RANKED).children.length;
	const counter = field.querySelector('[data-kalaka-counter]');
	if (counter !== null) {
		counter.textContent = counterText(count, bounds.max);
	}
	const empty = field.querySelector('[data-kalaka-empty]');
	if (empty !== null) {
		const rows = emptyRows(count, bounds);
		empty.setAttribute('start', String(count + 1));
		empty.hidden = rows === 0;
		empty.replaceChildren(
			...Array.from({ length: rows }, (_, index) => {
				const row = field.ownerDocument.createElement('li');
				row.setAttribute('data-kalaka-empty-row', '');
				if (index === 0) {
					row.id = minimumIdOf(field);
					row.textContent = minimumLine(bounds.min, count);
				} else {
					row.setAttribute('aria-hidden', 'true');
				}
				return row;
			})
		);
	}
	const full = count >= bounds.max;
	for (const item of field.querySelector(UNRANKED).children) {
		if (full) {
			checkboxOf(item).setAttribute('aria-disabled', 'true');
		} else {
			checkboxOf(item).removeAttribute('aria-disabled');
		}
	}
	for (const item of field.querySelector(RANKED).children) {
		checkboxOf(item).removeAttribute('aria-disabled');
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
 * Dragging within the ranked list. A mouse drags from anywhere on a ranked row, a finger or a pen by the
 * handle at once or from anywhere else on the row after a long press; a press on a button or the toggle
 * never drags. The list holds the pointer capture once a press becomes a drag, since the option itself
 * moves in the document while it is dragged. A press that moves less than `DRAG_THRESHOLD` stays a
 * click; after a drag, the click that follows is swallowed. A finger that moves more than `PRESS_SLOP`
 * before `LONG_PRESS` is a swipe: the press is dropped and the page scrolls. Once a long press holds,
 * the row drags (`data-kalaka-dragging`, its cue) and the finger's moves no longer scroll (the list's
 * `touchmove`, which is not passive, is cancelled). A press ends wherever its pointer is released or
 * cancelled (the window's `pointerup` and `pointercancel`, the list's loss of its own capture), and a
 * move with no button held ends it too, so nothing reorders without a held pointer. A drag that ends
 * where it started tells nothing.
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

	const lift = (pointerId) => {
		press.dragging = true;
		try {
			list.setPointerCapture?.(pointerId);
		} catch {
			// The pointer is gone already; its end follows.
		}
		press.item.setAttribute('data-kalaka-dragging', '');
	};

	// The window's listeners exist only while a press does: each press adds them and its end removes them, so
	// a field wired again (or many fields on a page) leaves none behind.
	const release = () => {
		clearTimeout(press.timer);
		press = null;
		view.removeEventListener('pointerup', end, true);
		view.removeEventListener('pointercancel', end, true);
	};

	function end(event) {
		if (press === null || (event.pointerId !== undefined && event.pointerId !== press.id)) {
			return;
		}
		const { item, dragging, from } = press;
		release();
		if (!dragging) {
			return;
		}
		item.removeAttribute('data-kalaka-dragging');
		swallowClick = true;
		// A drag that ended outside the option is followed by no click on it.
		setTimeout(() => {
			swallowClick = false;
		}, 0);
		if (item.parentElement === list && placeOf(item) !== from) {
			moved(field, item);
		}
	}

	list.addEventListener('pointerdown', (event) => {
		const target = event.target;
		const item = target?.closest?.('[data-kalaka-option]');
		if (
			press !== null ||
			event.isPrimary === false ||
			item == null ||
			item.parentElement !== list ||
			target.closest('button, input')
		) {
			return;
		}
		const mouse = event.pointerType === 'mouse';
		if (mouse && event.button !== 0) {
			return;
		}
		if (mouse) {
			// No text selection while a mouse drags.
			event.preventDefault();
		}
		const atOnce = mouse || target.closest('[data-kalaka-handle]') !== null;
		press = {
			id: event.pointerId,
			item,
			startX: event.clientX,
			startY: event.clientY,
			armed: atOnce,
			dragging: false,
			from: placeOf(item),
			timer: null,
		};
		view.addEventListener('pointerup', end, true);
		view.addEventListener('pointercancel', end, true);
		if (!atOnce) {
			const pointerId = event.pointerId;
			press.timer = setTimeout(() => {
				if (press !== null && press.id === pointerId && !press.armed) {
					press.armed = true;
					lift(pointerId);
				}
			}, LONG_PRESS);
		}
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
		if (!press.armed) {
			// A finger that moves before the long press holds is a swipe: the page scrolls.
			if (Math.hypot(event.clientX - press.startX, event.clientY - press.startY) > PRESS_SLOP) {
				release();
			}
			return;
		}
		if (!press.dragging) {
			if (Math.abs(event.clientY - press.startY) < DRAG_THRESHOLD) {
				return;
			}
			lift(event.pointerId);
		}
		event.preventDefault();
		const others = [...list.children].filter((other) => other !== press.item);
		const middles = others.map((other) => {
			const box = other.getBoundingClientRect();
			return box.top + box.height / 2;
		});
		placeAt(press.item, dropIndex(middles, event.clientY));
	});

	// A finger that drags does not scroll the page: the row keeps `touch-action`, so its moves are
	// cancelled here once the press is armed.
	list.addEventListener(
		'touchmove',
		(event) => {
			if (press !== null && press.armed && event.cancelable) {
				event.preventDefault();
			}
		},
		{ passive: false }
	);

	// A long press opens no context menu on a ranked row.
	list.addEventListener('contextmenu', (event) => {
		if (event.target?.closest?.('[data-kalaka-option]')?.parentElement === list) {
			event.preventDefault();
		}
	});

	// Only the list's own capture: a touch's implicit capture by the row is lost when the list takes it,
	// and that loss, bubbling up from the row, is no end of the press.
	list.addEventListener('lostpointercapture', (event) => {
		if (event.target === list) {
			end(event);
		}
	});
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
			refresh(field);
			const count = field.querySelector(RANKED).children.length;
			const { max } = boundsOf(field);
			say(
				field,
				count >= max
					? atLimit(labelOf(item), placeOf(item), max)
					: announcement(labelOf(item), placeOf(item))
			);
		} else if (!box.checked && inRanked) {
			unrank(field, item);
			refresh(field);
			say(field, removal(labelOf(item)));
		}
	});
	// At the maximum a „+” is `aria-disabled`: a click, a tap, Space or Enter on it changes nothing, and
	// says again that the order is full, since nothing else would tell a screen reader why.
	field.addEventListener(
		'click',
		(event) => {
			if (event.target?.matches?.('[data-kalaka-toggle][aria-disabled="true"]')) {
				event.preventDefault();
				sayAgain(field, fullText(boundsOf(field).max));
			}
		},
		{ capture: true }
	);
	// Enter works the toggle like Space, as on a button, instead of sending the form.
	field.addEventListener('keydown', (event) => {
		if (event.key === 'Enter' && event.target?.matches?.('[data-kalaka-toggle]')) {
			event.preventDefault();
			// A held key repeats: one press is one toggle, as with Space.
			if (!event.repeat) {
				event.target.click();
			}
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
	refresh(field);
	startDragging(field);
	// The handle and the option's text listen for clicks, so a touch browser takes each as a tap target of
	// its own: a finger on them stays there and is not moved to a button next to them. A tap on them does
	// nothing.
	for (const part of field.querySelectorAll('[data-kalaka-handle], [data-kalaka-label]')) {
		part.addEventListener('click', (event) => event.preventDefault());
	}
}

for (const field of document.querySelectorAll('[data-kalaka-ranking]')) {
	start(field);
}

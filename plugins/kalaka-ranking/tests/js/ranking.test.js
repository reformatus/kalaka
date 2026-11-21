/**
 * The ranking field on the form page (`Blocks/ranking-field/view.js`): ticking ranks an option, unticking
 * puts it back at its authored place, the buttons reorder, and the form reads the ticked ones in rank order.
 */
import { readAnswer } from '../../../kalaka/src/Forms/Blocks/form/answers';
import {
	LONG_PRESS,
	SAY_AGAIN_DELAY,
	addName,
	announcement,
	counterText,
	dropIndex,
	emptyRows,
	fullText,
	minimumLine,
	move,
	placeAt,
	removeName,
	restart,
	start,
} from '../../src/Ranking/Blocks/ranking-field/view';

const OPTIONS = ['modul_a', 'modul_b', 'modul_c'];

/**
 * The field as `render.php` renders it: by default required, at least 1, at most 3, no option ranked;
 * `ticked` options come ticked (still unranked), and `wire` false leaves it unwired.
 */
function render({ min = 1, max = 3, required = true, options = OPTIONS, ticked = [], wire = true } = {}) {
	const items = options.map(
		(value, index) =>
			`<li data-kalaka-option="${index}"><div data-kalaka-row>` +
			`<span data-kalaka-handle aria-hidden="true" hidden>⠿</span>` +
			`<span data-kalaka-label>${value}</span><span data-kalaka-controls>` +
			`<button type="button" data-kalaka-move="up" aria-label="${value} feljebb mozgatása" hidden>` +
			`<svg></svg></button>` +
			`<button type="button" data-kalaka-move="down" aria-label="${value} lejjebb mozgatása" hidden>` +
			`<svg></svg></button>` +
			`<input type="checkbox" name="modulok[]" value="${value}" data-kalaka-toggle` +
			` aria-label="${value} hozzáadása a sorrendhez"` +
			` aria-describedby="kalaka-field-modulok-counter kalaka-field-modulok-minimum" autocomplete="off"` +
			`${ticked.includes(value) ? ' checked' : ''}>` +
			`</span></div></li>`
	).join('');
	document.body.innerHTML =
		`<form><div class="kalaka-field" data-kalaka-field="modulok" data-kalaka-ranking data-kalaka-min="${min}"` +
		` data-kalaka-max="${max}"${required ? ' data-kalaka-required' : ''}><fieldset><legend>Modulok</legend>` +
		`<ol data-kalaka-ranked role="list" aria-label="Választott sorrend"></ol>` +
		`<ol data-kalaka-empty role="list" aria-label="Üres helyek a sorrendben" start="1"></ol>` +
		`<p data-kalaka-counter id="kalaka-field-modulok-counter"></p>` +
		`<ul data-kalaka-unranked role="list" aria-label="Többi lehetőség">${items}</ul></fieldset>` +
		`<span class="kalaka-visually-hidden" aria-live="polite" data-kalaka-announce></span></div></form>`;
	const field = document.querySelector('[data-kalaka-ranking]');
	if (wire) {
		start(field);
	}
	return field;
}

const box = (field, value) => field.querySelector(`input[value="${value}"]`);
const itemOf = (field, value) => box(field, value).closest('li');
const ranked = (field) =>
	[...field.querySelectorAll('[data-kalaka-ranked] input')].map((input) => input.value);
const unranked = (field) =>
	[...field.querySelectorAll('[data-kalaka-unranked] input')].map((input) => input.value);

function tick(field, value) {
	const input = box(field, value);
	input.checked = !input.checked;
	input.dispatchEvent(new Event('change', { bubbles: true }));
}

function press(field, value, direction) {
	itemOf(field, value).querySelector(`[data-kalaka-move="${direction}"]`).click();
}

describe('the ranking field', () => {
	test('ticking ranks an option at the end, and shows its buttons', () => {
		const field = render();
		tick(field, 'modul_c');
		tick(field, 'modul_a');

		expect(ranked(field)).toEqual(['modul_c', 'modul_a']);
		expect(unranked(field)).toEqual(['modul_b']);
		expect(itemOf(field, 'modul_c').querySelector('button').hidden).toBe(false);
		expect(itemOf(field, 'modul_b').querySelector('button').hidden).toBe(true);
	});

	test('the form reads the ticked options in rank order', () => {
		const field = render();
		tick(field, 'modul_c');
		tick(field, 'modul_a');

		expect(readAnswer(field, 'modulok')).toEqual({ present: true, value: ['modul_c', 'modul_a'] });
	});

	test('unticking puts an option back at its authored place', () => {
		const field = render();
		tick(field, 'modul_b');
		tick(field, 'modul_c');
		tick(field, 'modul_b');

		expect(ranked(field)).toEqual(['modul_c']);
		expect(unranked(field)).toEqual(['modul_a', 'modul_b']);
		expect(itemOf(field, 'modul_b').querySelector('button').hidden).toBe(true);
	});

	test('the buttons move an option up and down, and keep the focus', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		const up = itemOf(field, 'modul_c').querySelector('[data-kalaka-move="up"]');
		up.focus();

		press(field, 'modul_c', 'up');
		expect(ranked(field)).toEqual(['modul_a', 'modul_c', 'modul_b']);
		expect(document.activeElement).toBe(up);

		press(field, 'modul_a', 'down');
		expect(ranked(field)).toEqual(['modul_c', 'modul_a', 'modul_b']);
	});

	test('a move tells the form with a change of the option, and nothing moves past the ends', () => {
		const field = render();
		tick(field, 'modul_a');
		tick(field, 'modul_b');
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));

		press(field, 'modul_a', 'up');
		press(field, 'modul_b', 'down');
		expect(changes).toEqual([]);
		expect(move(itemOf(field, 'modul_a'), 'up')).toBe(false);

		press(field, 'modul_b', 'up');
		expect(changes).toEqual(['modul_b']);
		expect(ranked(field)).toEqual(['modul_b', 'modul_a']);
		expect(box(field, 'modul_b').checked).toBe(true);
	});

	test('a move is announced with the option and its new place', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		press(field, 'modul_c', 'up');

		expect(field.querySelector('[data-kalaka-announce]').textContent).toBe('modul_c, 2. helyen');
		expect(announcement('Modul A', 1)).toBe('Modul A, 1. helyen');
	});

	test('a ranked option shows its handle; an unranked one hides it; the row itself scrolls as usual', () => {
		const field = render();
		tick(field, 'modul_a');

		expect(itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]').hidden).toBe(false);
		expect(itemOf(field, 'modul_b').querySelector('[data-kalaka-handle]').hidden).toBe(true);
		expect(itemOf(field, 'modul_a').style.touchAction).toBeFalsy();
		tick(field, 'modul_a');
		expect(itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]').hidden).toBe(true);
	});

	test("the toggle's name says what it does, and follows its state", () => {
		const field = render();
		expect(box(field, 'modul_a').getAttribute('aria-label')).toBe('modul_a hozzáadása a sorrendhez');

		tick(field, 'modul_a');
		expect(box(field, 'modul_a').getAttribute('aria-label')).toBe('modul_a eltávolítása a sorrendből');
		tick(field, 'modul_a');
		expect(box(field, 'modul_a').getAttribute('aria-label')).toBe('modul_a hozzáadása a sorrendhez');
		expect(addName('Modul A')).toBe('Modul A hozzáadása a sorrendhez');
		expect(removeName('Modul A')).toBe('Modul A eltávolítása a sorrendből');
	});

	test('Enter works the toggle like Space and does not send the form', () => {
		const field = render();
		const enter = new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });

		box(field, 'modul_b').dispatchEvent(enter);

		expect(enter.defaultPrevented).toBe(true);
		expect(ranked(field)).toEqual(['modul_b']);

		// A held Enter repeats: no further toggle.
		const held = new window.KeyboardEvent('keydown', { key: 'Enter', repeat: true, bubbles: true, cancelable: true });
		box(field, 'modul_b').dispatchEvent(held);
		expect(held.defaultPrevented).toBe(true);
		expect(ranked(field)).toEqual(['modul_b']);
	});

	test('a tap on the handle is its own click, and neither ticks nor unticks the option', () => {
		const field = render();
		tick(field, 'modul_a');
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');
		const click = new MouseEvent('click', { bubbles: true, cancelable: true });

		handle.dispatchEvent(click);

		expect(click.defaultPrevented).toBe(true);
		expect(box(field, 'modul_a').checked).toBe(true);
		expect(ranked(field)).toEqual(['modul_a']);
	});
});

describe('the drop place', () => {
	test('is the number of the other options whose middle lies above the pointer', () => {
		expect(dropIndex([10, 30, 50], 0)).toBe(0);
		expect(dropIndex([10, 30, 50], 31)).toBe(2);
		expect(dropIndex([10, 30, 50], 99)).toBe(3);
		expect(dropIndex([], 5)).toBe(0);
	});

	test('placeAt moves the option among the others and says whether its place changed', () => {
		document.body.innerHTML = '<ol><li id="a"></li><li id="b"></li><li id="c"></li></ol>';
		const order = () => [...document.querySelectorAll('li')].map((item) => item.id).join('');
		const a = document.getElementById('a');

		expect(placeAt(a, 0)).toBe(false);
		expect(placeAt(a, 2)).toBe(true);
		expect(order()).toBe('bca');
		expect(placeAt(a, 2)).toBe(false);
		expect(placeAt(a, 1)).toBe(true);
		expect(order()).toBe('bac');
	});
});

describe('dragging a ranked option', () => {
	/** A pointer event as a browser sends it (jsdom may lack PointerEvent). */
	function pointer(type, target, y, extra = {}) {
		const buttons = extra.buttons ?? (type === 'pointerup' ? 0 : 1);
		const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientY: y, button: 0, buttons });
		Object.defineProperty(event, 'pointerId', { value: 1 });
		Object.defineProperty(event, 'pointerType', { value: extra.pointerType ?? 'touch' });
		target.dispatchEvent(event);
		return event;
	}

	/** Lays the ranked options out 20 px apart, as `getBoundingClientRect()` would report them. */
	function layOut(field) {
		const list = field.querySelector('[data-kalaka-ranked]');
		for (const item of list.children) {
			item.getBoundingClientRect = () => {
				const index = [...list.children].indexOf(item);
				return { top: index * 20, height: 20 };
			};
		}
	}

	test('a drag reorders, fires one change and is announced', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 12);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		pointer('pointermove', handle, 45);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(true);
		pointer('pointermove', handle, 70);
		pointer('pointerup', handle, 70);

		expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
		expect(changes).toEqual(['modul_a']);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		expect(field.querySelector('[data-kalaka-announce]').textContent).toBe('modul_a, 3. helyen');
		expect(readAnswer(field, 'modulok').value).toEqual(['modul_b', 'modul_c', 'modul_a']);
	});

	test('the click a drag ends with does not untick the option', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10, { pointerType: 'mouse' });
		pointer('pointermove', handle, 70, { pointerType: 'mouse' });
		pointer('pointerup', handle, 70, { pointerType: 'mouse' });
		handle.click();

		expect(box(field, 'modul_a').checked).toBe(true);
		expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
	});

	test('a short press is no drag; a finger moving on the text at once swipes; the controls never drag', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));

		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');
		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 13);
		pointer('pointerup', handle, 13);
		for (const other of [
			itemOf(field, 'modul_a').querySelector('[data-kalaka-label]'),
			itemOf(field, 'modul_a').querySelector('[data-kalaka-move="down"]'),
			box(field, 'modul_a'),
		]) {
			pointer('pointerdown', other, 10);
			pointer('pointermove', other, 70);
			pointer('pointerup', other, 70);
		}

		expect(ranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
		expect(changes).toEqual([]);
	});

	test('a mouse drags from the text at once', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const text = itemOf(field, 'modul_a').querySelector('[data-kalaka-label]');

		pointer('pointerdown', text, 10, { pointerType: 'mouse' });
		pointer('pointermove', text, 45, { pointerType: 'mouse' });
		pointer('pointermove', text, 70, { pointerType: 'mouse' });
		pointer('pointerup', text, 70, { pointerType: 'mouse', buttons: 0 });

		expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
	});

	test('a finger drags from the text after a long press, and a move cannot scroll then', () => {
		jest.useFakeTimers();
		try {
			const field = render();
			OPTIONS.forEach((value) => tick(field, value));
			layOut(field);
			const text = itemOf(field, 'modul_a').querySelector('[data-kalaka-label]');

			pointer('pointerdown', text, 10);
			jest.advanceTimersByTime(LONG_PRESS - 1);
			expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
			jest.advanceTimersByTime(1);
			expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(true);

			const scroll = new Event('touchmove', { bubbles: true, cancelable: true });
			text.dispatchEvent(scroll);
			expect(scroll.defaultPrevented).toBe(true);

			pointer('pointermove', text, 70);
			pointer('pointerup', text, 70);
			expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
			expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		} finally {
			jest.useRealTimers();
		}
	});

	test('a finger that moves before the long press holds swipes: no drag, the page scrolls', () => {
		jest.useFakeTimers();
		try {
			const field = render();
			OPTIONS.forEach((value) => tick(field, value));
			layOut(field);
			const text = itemOf(field, 'modul_a').querySelector('[data-kalaka-label]');

			pointer('pointerdown', text, 10);
			pointer('pointermove', text, 40);
			jest.advanceTimersByTime(LONG_PRESS * 2);
			const scroll = new Event('touchmove', { bubbles: true, cancelable: true });
			text.dispatchEvent(scroll);
			pointer('pointermove', text, 70);
			pointer('pointerup', text, 70);

			expect(scroll.defaultPrevented).toBe(false);
			expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
			expect(ranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
		} finally {
			jest.useRealTimers();
		}
	});

	test('a ranked row opens no context menu', () => {
		const field = render();
		tick(field, 'modul_a');
		const menu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });

		itemOf(field, 'modul_a').querySelector('[data-kalaka-label]').dispatchEvent(menu);

		expect(menu.defaultPrevented).toBe(true);
	});

	test('an unranked option is not dragged', () => {
		const field = render();
		tick(field, 'modul_a');
		layOut(field);
		const handle = itemOf(field, 'modul_b').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 70);
		pointer('pointerup', handle, 70);

		expect(ranked(field)).toEqual(['modul_a']);
		expect(unranked(field)).toEqual(['modul_b', 'modul_c']);
	});

	test('a press released outside the list ends there, and a later hover reorders nothing', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10, { pointerType: 'mouse' });
		// Released outside the list (sideways, under the threshold): only the window hears it.
		pointer('pointerup', document.body, 12, { pointerType: 'mouse' });
		pointer('pointermove', handle, 70, { pointerType: 'mouse', buttons: 0 });
		pointer('pointermove', handle, 70, { pointerType: 'mouse', buttons: 1 });

		expect(ranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
		expect(changes).toEqual([]);
	});

	test('a move with no button held ends the drag where it is', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10, { pointerType: 'mouse' });
		pointer('pointermove', handle, 45, { pointerType: 'mouse' });
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(true);
		pointer('pointermove', handle, 70, { pointerType: 'mouse', buttons: 0 });

		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		expect(ranked(field)).toEqual(['modul_b', 'modul_a', 'modul_c']);
		pointer('pointermove', handle, 10, { pointerType: 'mouse', buttons: 1 });
		expect(ranked(field)).toEqual(['modul_b', 'modul_a', 'modul_c']);
	});

	test('a lost pointer capture ends the drag', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 45);
		pointer('lostpointercapture', field.querySelector('[data-kalaka-ranked]'), 45);
		pointer('pointermove', handle, 70);

		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		expect(ranked(field)).toEqual(['modul_b', 'modul_a', 'modul_c']);
	});

	test('a touch on the handle drags, though the list takes the capture the handle held', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 45);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(true);
		// A touch is captured by the handle it starts on; the list taking the capture makes the handle lose it.
		pointer('lostpointercapture', handle, 45);
		pointer('pointermove', handle, 70);
		pointer('pointerup', handle, 70);

		expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
	});

	test('the window hears a press only while it lasts: every end removes its listeners', () => {
		jest.useFakeTimers();
		const added = jest.spyOn(window, 'addEventListener');
		const removed = jest.spyOn(window, 'removeEventListener');
		/** The window's `pointerup` and `pointercancel` listeners the field holds now. */
		const held = () => {
			const ofPress = (spy) =>
				spy.mock.calls.filter(([type]) => type === 'pointerup' || type === 'pointercancel').length;
			return ofPress(added) - ofPress(removed);
		};
		try {
			const field = render();
			OPTIONS.forEach((value) => tick(field, value));
			layOut(field);
			const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');
			const text = itemOf(field, 'modul_b').querySelector('[data-kalaka-label]');
			expect(held()).toBe(0);

			// A drag released, a tap, a cancelled press, a swipe, a lost capture, a move with no button held.
			pointer('pointerdown', handle, 10);
			expect(held()).toBe(2);
			pointer('pointermove', handle, 45);
			pointer('pointerup', handle, 45);
			expect(held()).toBe(0);
			pointer('pointerdown', handle, 10);
			pointer('pointerup', handle, 10);
			pointer('pointerdown', handle, 10);
			pointer('pointercancel', handle, 10);
			pointer('pointerdown', text, 30);
			pointer('pointermove', text, 60);
			jest.advanceTimersByTime(LONG_PRESS * 2);
			pointer('pointerdown', handle, 10);
			pointer('pointermove', handle, 45);
			pointer('lostpointercapture', field.querySelector('[data-kalaka-ranked]'), 45);
			pointer('pointerdown', handle, 10, { pointerType: 'mouse' });
			pointer('pointermove', handle, 45, { pointerType: 'mouse', buttons: 0 });
			expect(held()).toBe(0);

			// A ranking the form re-rendered and restarted, again and again (S44): nothing stacks on the window.
			for (let i = 0; i < 3; i++) {
				const swapped = render({ ticked: ['modul_a', 'modul_b'], wire: false });
				restart(swapped, ['modul_a', 'modul_b']);
				layOut(swapped);
				const grip = itemOf(swapped, 'modul_a').querySelector('[data-kalaka-handle]');
				pointer('pointerdown', grip, 10);
				expect(held()).toBe(2);
				pointer('pointerup', grip, 10);
			}
			expect(held()).toBe(0);
		} finally {
			added.mockRestore();
			removed.mockRestore();
			jest.useRealTimers();
		}
	});

	test('a drag that ends where it started tells nothing', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));
		field.querySelector('[data-kalaka-announce]').textContent = '';
		const handle = itemOf(field, 'modul_a').querySelector('[data-kalaka-handle]');

		pointer('pointerdown', handle, 10);
		pointer('pointermove', handle, 45);
		pointer('pointermove', handle, 5);
		pointer('pointerup', handle, 5);

		expect(ranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
		expect(changes).toEqual([]);
		expect(field.querySelector('[data-kalaka-announce]').textContent).toBe('');
	});
});

describe('the keyboard and the screen reader', () => {
	test('ticking and unticking keeps the focus on the checkbox', () => {
		const field = render();
		const input = box(field, 'modul_b');
		input.focus();

		tick(field, 'modul_b');
		expect(document.activeElement).toBe(input);
		expect(ranked(field)).toEqual(['modul_b']);

		tick(field, 'modul_b');
		expect(document.activeElement).toBe(input);
		expect(unranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
	});

	test('a tick tells its place, and an untick empties the live region', () => {
		const field = render();
		const region = field.querySelector('[data-kalaka-announce]');

		tick(field, 'modul_c');
		expect(region.textContent).toBe('modul_c, 1. helyen');
		tick(field, 'modul_a');
		expect(region.textContent).toBe('modul_a, 2. helyen');
		tick(field, 'modul_c');
		expect(region.textContent).toBe('modul_c, kivéve a sorrendből');
	});

	test('the edge buttons are aria-disabled, stay focusable and do nothing', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		const disabled = () =>
			[...field.querySelectorAll('[data-kalaka-ranked] [aria-disabled="true"]')].map(
				(button) => `${button.closest('li').querySelector('input').value}:${button.getAttribute('data-kalaka-move')}`
			);
		expect(disabled()).toEqual(['modul_a:up', 'modul_c:down']);

		const down = itemOf(field, 'modul_b').querySelector('[data-kalaka-move="down"]');
		down.focus();
		down.click();
		// modul_b is now last: its down button is the edge, and keeps the focus.
		expect(ranked(field)).toEqual(['modul_a', 'modul_c', 'modul_b']);
		expect(disabled()).toEqual(['modul_a:up', 'modul_b:down']);
		expect(document.activeElement).toBe(down);
		expect(down.disabled).toBe(false);

		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));
		down.click();
		expect(changes).toEqual([]);
		expect(ranked(field)).toEqual(['modul_a', 'modul_c', 'modul_b']);
	});

	test('an unranked option has no aria-disabled button', () => {
		const field = render();
		tick(field, 'modul_a');
		tick(field, 'modul_a');

		expect(field.querySelectorAll('[data-kalaka-unranked] [aria-disabled]').length).toBe(0);
	});

	test("a tap on an option's text does nothing, ranked or not", () => {
		const field = render();
		tick(field, 'modul_a');

		itemOf(field, 'modul_a').querySelector('[data-kalaka-label]').click();
		itemOf(field, 'modul_b').querySelector('[data-kalaka-label]').click();

		expect(ranked(field)).toEqual(['modul_a']);
		expect(box(field, 'modul_b').checked).toBe(false);
	});

	test('a ranking the form re-rendered keeps the order of what it still offers (S44, C2)', () => {
		// Ranked PRO, modul_c, modul_a; the track changed, so PRO is no longer offered. The form ticked the
		// offered ones it remembered (core's swap) and hands the earlier order over.
		const field = render({ options: ['modul_a', 'modul_b', 'modul_c'], ticked: ['modul_a', 'modul_c'], wire: false });
		restart(field, ['pro', 'modul_c', 'modul_a']);

		expect(ranked(field)).toEqual(['modul_c', 'modul_a']);
		expect(unranked(field)).toEqual(['modul_b']);
		expect(readAnswer(field, 'modulok')).toEqual({ present: true, value: ['modul_c', 'modul_a'] });
		// The new markup is wired: a tick ranks at the end.
		tick(field, 'modul_b');
		expect(ranked(field)).toEqual(['modul_c', 'modul_a', 'modul_b']);
	});

	test('a swapped ranking is restarted from the form\'s event', () => {
		const field = render({ options: ['modul_a', 'modul_b', 'pro'], ticked: ['pro', 'modul_b'], wire: false });
		field.dispatchEvent(
			new window.CustomEvent('kalaka:field-swapped', { bubbles: true, detail: { chosen: ['pro', 'modul_b'] } })
		);

		expect(ranked(field)).toEqual(['pro', 'modul_b']);
	});

	test('an option that comes back after the ranking changed is ranked last (S44, C2)', () => {
		// Core remembers the ticked ones in order, then the ones no longer offered: PRO was hidden while the
		// person re-ranked, so it comes back after them.
		const field = render({ options: ['modul_a', 'modul_b', 'pro'], ticked: ['modul_a', 'modul_b', 'pro'], wire: false });
		restart(field, ['modul_b', 'modul_a', 'pro']);

		expect(ranked(field)).toEqual(['modul_b', 'modul_a', 'pro']);
	});
});

describe('the counter, the empty rows and the maximum (owner, Q1, Q2)', () => {
	const counter = (field) => field.querySelector('[data-kalaka-counter]').textContent;
	const empties = (field) =>
		[...field.querySelectorAll('[data-kalaka-empty] > li')].map((row) => row.textContent);
	const region = (field) => field.querySelector('[data-kalaka-announce]').textContent;

	test('the texts: the minimum line, the more line, the full text, with „egy” for one', () => {
		expect(minimumLine(1, 0)).toBe('Adj hozzá legalább egy lehetőséget a sorrendhez.');
		expect(minimumLine(3, 0)).toBe('Adj hozzá legalább 3 lehetőséget a sorrendhez.');
		expect(minimumLine(3, 2)).toBe('Adj hozzá még legalább egy lehetőséget a sorrendhez.');
		expect(minimumLine(4, 1)).toBe('Adj hozzá még legalább 3 lehetőséget a sorrendhez.');
		expect(fullText(1)).toBe('Megtelt a sorrend: legfeljebb egy lehetőséget választhatsz.');
		expect(fullText(3)).toBe('Megtelt a sorrend: legfeljebb 3 lehetőséget választhatsz.');
		expect(counterText(2, 3)).toBe('2 / 3 kiválasztva');
	});

	test('a required field shows the minimum\'s empty rows at once, the first with the line', () => {
		const field = render({ min: 2, max: 3 });

		expect(counter(field)).toBe('0 / 3 kiválasztva');
		expect(empties(field)).toEqual(['Adj hozzá legalább 2 lehetőséget a sorrendhez.', '']);
		expect(field.querySelectorAll('[data-kalaka-empty] > li')[1].getAttribute('aria-hidden')).toBe('true');

		tick(field, 'modul_b');
		expect(counter(field)).toBe('1 / 3 kiválasztva');
		expect(empties(field)).toEqual(['Adj hozzá még legalább egy lehetőséget a sorrendhez.']);
		// The toggles stay described by the counter and the line, which is rebuilt with its id.
		expect(field.querySelector('[data-kalaka-empty] > li').id).toBe('kalaka-field-modulok-minimum');
		expect(document.getElementById('kalaka-field-modulok-minimum').textContent).toBe(
			'Adj hozzá még legalább egy lehetőséget a sorrendhez.'
		);
		expect(field.querySelector('[data-kalaka-empty]').getAttribute('start')).toBe('2');

		tick(field, 'modul_a');
		expect(empties(field)).toEqual([]);
		expect(field.querySelector('[data-kalaka-empty]').hidden).toBe(true);
	});

	test('an optional field shows no empty rows until the first option is added', () => {
		const field = render({ min: 2, max: 3, required: false });

		expect(empties(field)).toEqual([]);
		tick(field, 'modul_a');
		expect(empties(field)).toEqual(['Adj hozzá még legalább egy lehetőséget a sorrendhez.']);
		tick(field, 'modul_a');
		expect(empties(field)).toEqual([]);
		expect(emptyRows(0, { min: 2, required: false })).toBe(0);
		expect(emptyRows(0, { min: 2, required: true })).toBe(2);
	});

	test('with min = max, the counter and the line both show while places are empty', () => {
		const field = render({ min: 2, max: 2 });

		expect(counter(field)).toBe('0 / 2 kiválasztva');
		expect(empties(field)).toEqual(['Adj hozzá legalább 2 lehetőséget a sorrendhez.', '']);
		tick(field, 'modul_a');
		expect(counter(field)).toBe('1 / 2 kiválasztva');
		expect(empties(field)).toEqual(['Adj hozzá még legalább egy lehetőséget a sorrendhez.']);
	});

	test('only the tick that reaches the maximum says the order is full, once', () => {
		const field = render({ min: 1, max: 2 });

		tick(field, 'modul_a');
		expect(region(field)).toBe('modul_a, 1. helyen');
		tick(field, 'modul_b');
		expect(region(field)).toBe(
			'modul_b, 2. helyen. Megtelt a sorrend: legfeljebb 2 lehetőséget választhatsz.'
		);
		press(field, 'modul_b', 'up');
		expect(region(field)).toBe('modul_b, 1. helyen');
		tick(field, 'modul_a');
		expect(region(field)).toBe('modul_a, kivéve a sorrendből');
	});

	test('at the maximum a „+” is aria-disabled; a click or Enter changes nothing and says it is full again', () => {
		jest.useFakeTimers();
		try {
			const field = render({ min: 1, max: 1 });
			tick(field, 'modul_a');
			const plus = box(field, 'modul_b');
			const changes = jest.fn();
			field.addEventListener('change', changes);

			expect(plus.getAttribute('aria-disabled')).toBe('true');
			expect(box(field, 'modul_a').hasAttribute('aria-disabled')).toBe(false);
			expect(region(field)).toBe(
				'modul_a, 1. helyen. Megtelt a sorrend: legfeljebb egy lehetőséget választhatsz.'
			);
			for (const pressIt of [
				() => plus.click(),
				() =>
					plus.dispatchEvent(
						new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
					),
			]) {
				pressIt();
				// Emptied first, so the same text is said again.
				expect(region(field)).toBe('');
				jest.advanceTimersByTime(SAY_AGAIN_DELAY);
				expect(region(field)).toBe('Megtelt a sorrend: legfeljebb egy lehetőséget választhatsz.');
			}
			expect(plus.checked).toBe(false);
			expect(ranked(field)).toEqual(['modul_a']);
			expect(changes).not.toHaveBeenCalled();

			// A text said meanwhile is not overwritten by the one waiting.
			plus.click();
			tick(field, 'modul_a');
			jest.advanceTimersByTime(SAY_AGAIN_DELAY);
			expect(region(field)).toBe('modul_a, kivéve a sorrendből');
			expect(plus.hasAttribute('aria-disabled')).toBe(false);
		} finally {
			jest.useRealTimers();
		}
	});
});


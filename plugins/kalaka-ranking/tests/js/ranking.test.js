/**
 * The ranking field on the form page (`Blocks/ranking-field/view.js`): ticking ranks an option, unticking
 * puts it back at its authored place, the buttons reorder, and the form reads the ticked ones in rank order.
 */
import { readAnswer } from '../../../kalaka/src/Forms/Blocks/form/answers';
import {
	announcement,
	dropIndex,
	move,
	placeAt,
	start,
} from '../../src/Ranking/Blocks/ranking-field/view';

const OPTIONS = ['modul_a', 'modul_b', 'modul_c'];

/** The field as `render.php` renders it, with no option ranked. */
function render() {
	const items = OPTIONS.map(
		(value, index) =>
			`<li data-kalaka-option="${index}"><span data-kalaka-handle aria-hidden="true" style="touch-action:none" hidden>⠿</span> <label><input type="checkbox" name="modulok[]" value="${value}" autocomplete="off"> ${value}</label>` +
			` <button type="button" data-kalaka-move="up" aria-label="${value} feljebb mozgatása" hidden>Feljebb</button>` +
			` <button type="button" data-kalaka-move="down" aria-label="${value} lejjebb mozgatása" hidden>Lejjebb</button></li>`
	).join('');
	document.body.innerHTML =
		`<form><div class="kalaka-field" data-kalaka-field="modulok" data-kalaka-ranking><fieldset><legend>Modulok</legend>` +
		`<ol data-kalaka-ranked></ol><ul data-kalaka-unranked>${items}</ul></fieldset>` +
		`<span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span></div></form>`;
	const field = document.querySelector('[data-kalaka-ranking]');
	start(field);
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

		expect(field.querySelector('[data-kalaka-announce]').textContent).toBe('modul_c, 2. hely');
		expect(announcement('Modul A', 1)).toBe('Modul A, 1. hely');
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
		expect(field.querySelector('[data-kalaka-announce]').textContent).toBe('modul_a, 3. hely');
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

	test('a short press is no drag, and a press on the handle, a button or a checkbox never drags', () => {
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
			itemOf(field, 'modul_a').querySelector('label'),
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
		expect(region.textContent).toBe('modul_c, 1. hely');
		tick(field, 'modul_a');
		expect(region.textContent).toBe('modul_a, 2. hely');
		tick(field, 'modul_c');
		expect(region.textContent).toBe('');
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

	test('a tap on the label of a ranked option still unticks it: only the handle drags', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		const label = itemOf(field, 'modul_b').querySelector('label');

		label.click();

		expect(box(field, 'modul_b').checked).toBe(false);
		expect(ranked(field)).toEqual(['modul_a', 'modul_c']);
	});
});

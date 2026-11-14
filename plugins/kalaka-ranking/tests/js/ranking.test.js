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
			`<li data-kalaka-option="${index}"><label><input type="checkbox" name="modulok[]" value="${value}" autocomplete="off"> ${value}</label>` +
			` <button type="button" data-kalaka-move="up" hidden>Feljebb</button>` +
			` <button type="button" data-kalaka-move="down" hidden>Lejjebb</button></li>`
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

	test('a ranked option takes a drag without scrolling; an unranked one does not', () => {
		const field = render();
		tick(field, 'modul_a');

		expect(itemOf(field, 'modul_a').style.touchAction).toBe('none');
		expect(itemOf(field, 'modul_b').style.touchAction).toBeFalsy();
		tick(field, 'modul_a');
		expect(itemOf(field, 'modul_a').style.touchAction).toBeFalsy();
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
		const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientY: y, button: 0 });
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
		const label = itemOf(field, 'modul_a').querySelector('label');

		pointer('pointerdown', label, 10);
		pointer('pointermove', label, 12);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(false);
		pointer('pointermove', label, 45);
		expect(itemOf(field, 'modul_a').hasAttribute('data-kalaka-dragging')).toBe(true);
		pointer('pointermove', label, 70);
		pointer('pointerup', label, 70);

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
		const label = itemOf(field, 'modul_a').querySelector('label');

		pointer('pointerdown', label, 10, { pointerType: 'mouse' });
		pointer('pointermove', label, 70, { pointerType: 'mouse' });
		pointer('pointerup', label, 70, { pointerType: 'mouse' });
		label.click();

		expect(box(field, 'modul_a').checked).toBe(true);
		expect(ranked(field)).toEqual(['modul_b', 'modul_c', 'modul_a']);
	});

	test('a short press is no drag, and a press on a button or checkbox never drags', () => {
		const field = render();
		OPTIONS.forEach((value) => tick(field, value));
		layOut(field);
		const changes = [];
		document.querySelector('form').addEventListener('change', (event) => changes.push(event.target.value));

		const label = itemOf(field, 'modul_a').querySelector('label');
		pointer('pointerdown', label, 10);
		pointer('pointermove', label, 13);
		pointer('pointerup', label, 13);
		const button = itemOf(field, 'modul_a').querySelector('[data-kalaka-move="down"]');
		pointer('pointerdown', button, 10);
		pointer('pointermove', button, 70);
		pointer('pointerup', button, 70);

		expect(ranked(field)).toEqual(['modul_a', 'modul_b', 'modul_c']);
		expect(changes).toEqual([]);
	});

	test('an unranked option is not dragged', () => {
		const field = render();
		tick(field, 'modul_a');
		layOut(field);
		const label = itemOf(field, 'modul_b').querySelector('label');

		pointer('pointerdown', label, 10);
		pointer('pointermove', label, 70);
		pointer('pointerup', label, 70);

		expect(ranked(field)).toEqual(['modul_a']);
		expect(unranked(field)).toEqual(['modul_b', 'modul_c']);
	});
});


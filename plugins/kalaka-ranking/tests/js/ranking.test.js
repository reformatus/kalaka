/**
 * The ranking field on the form page (`Blocks/ranking-field/view.js`): ticking ranks an option, unticking
 * puts it back at its authored place, the buttons reorder, and the form reads the ticked ones in rank order.
 */
import { readAnswer } from '../../../kalaka/src/Forms/Blocks/form/answers';
import { move, start } from '../../src/Ranking/Blocks/ranking-field/view';

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
		`<ol data-kalaka-ranked></ol><ul data-kalaka-unranked>${items}</ul></fieldset></div></form>`;
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
});

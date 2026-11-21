// The number field's wheel guard (topic 7): the wheel never changes a focused number field.

import {
	BAD_NUMBER,
	firstBadNumber,
	guardNumberWheel,
} from '../../src/Forms/Blocks/form/number';

guardNumberWheel(document);

describe('a focused number field and the mouse wheel', () => {

	beforeEach(() => {
		document.body.innerHTML = '<input type="number" id="number"><input type="text" id="text">';
	});

	it('loses focus when the wheel turns over it', () => {
		const number = document.getElementById('number');
		number.focus();
		expect(document.activeElement).toBe(number);

		number.dispatchEvent(new Event('wheel', { bubbles: true }));

		expect(document.activeElement).not.toBe(number);
	});

	it('leaves a focused text field alone', () => {
		const text = document.getElementById('text');
		text.focus();

		text.dispatchEvent(new Event('wheel', { bubbles: true }));

		expect(document.activeElement).toBe(text);
	});

	it('leaves an unfocused number field alone', () => {
		const number = document.getElementById('number');
		const text = document.getElementById('text');
		text.focus();

		number.dispatchEvent(new Event('wheel', { bubbles: true }));

		expect(document.activeElement).toBe(text);
	});
});

describe('a number control with text the browser cannot parse', () => {
	it('is found with its field, and the T4 refusal is shown', () => {
		document.body.innerHTML =
			'<form><div data-kalaka-field="adomany"><input type="number" name="adomany"></div></form>';
		const form = document.querySelector('form');
		const input = document.querySelector('input');
		Object.defineProperty(input, 'validity', { value: { badInput: true } });

		const bad = firstBadNumber(form);

		expect(bad.wrapper).toBe(document.querySelector('[data-kalaka-field]'));
		expect(bad.control).toBe(input);
		expect(BAD_NUMBER).toBe('Csak számot írhatsz ide.');
	});

	it('leaves a valid number control and a disabled one alone', () => {
		document.body.innerHTML =
			'<form><div data-kalaka-field="a"><input type="number" name="a"></div>' +
			'<div data-kalaka-field="b"><input type="number" name="b"></div></form>';
		const form = document.querySelector('form');
		const [a, b] = document.querySelectorAll('input');
		Object.defineProperty(a, 'validity', { value: { badInput: false } });
		Object.defineProperty(b, 'validity', { value: { badInput: true } });
		b.disabled = true;

		expect(firstBadNumber(form)).toBeNull();
	});
});

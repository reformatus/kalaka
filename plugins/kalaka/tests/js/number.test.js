// The number field's wheel guard (topic 7): the wheel never changes a focused number field.

import { guardNumberWheel } from '../../src/Forms/Blocks/form/number';

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

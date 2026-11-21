/* global InputEvent */
// The number field's wheel guard (topic 7): the wheel never changes a focused number field.

import {
	BAD_NUMBER,
	firstBadNumber,
	guardNumberSeparators,
	guardNumberWheel,
	WHOLE_NUMBER,
	measureNumberAffixes,
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

describe("the affixes' room", () => {
	it('is measured from each affix and set on the wrapper', () => {
		document.body.innerHTML =
			'<form><span class="kalaka-number kalaka-number--prefix kalaka-number--suffix">' +
			'<span class="kalaka-number__affix kalaka-number__prefix">Ft</span>' +
			'<input type="number">' +
			'<span class="kalaka-number__affix kalaka-number__suffix">/ fő</span></span></form>';
		const wrapper = document.querySelector('.kalaka-number');
		const [prefix, suffix] = document.querySelectorAll('.kalaka-number__affix');
		Object.defineProperty(prefix, 'offsetWidth', { value: 24 });
		Object.defineProperty(suffix, 'offsetWidth', { value: 40 });

		measureNumberAffixes(document);

		expect(wrapper.style.getPropertyValue('--kalaka-number-prefix-room')).toBe('36px');
		expect(wrapper.style.getPropertyValue('--kalaka-number-suffix-room')).toBe('72px');
	});
});

describe('the comma guard (B4)', () => {
	const refusals = [];
	let inserted;
	const cleared = [];
	guardNumberSeparators(
		document,
		(control, message) => refusals.push([control.id, message]),
		(control) => cleared.push(control.id)
	);

	beforeEach(() => {
		refusals.length = 0;
		cleared.length = 0;
		inserted = [];
		document.execCommand = jest.fn((command, ui, text) => {
			inserted.push(text);
			return true;
		});
		document.body.innerHTML =
			'<form><div data-kalaka-field="whole"><input type="number" id="whole" inputmode="numeric" name="whole"></div>' +
			'<div data-kalaka-field="decimal"><input type="number" id="decimal" inputmode="decimal" step="any" name="decimal"></div></form>';
	});

	/** What the browser does after "1" and a refused ",": the next digit still goes in. */
	function typeDigit(id, digit) {
		const input = document.getElementById(id);
		input.value += digit;
		input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: digit }));
	}

	function type(id, inputType, data) {
		const event = new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType, data });
		document.getElementById(id).dispatchEvent(event);
		return event;
	}

	it('refuses a comma or a dot, typed or pasted, in a whole-number field', () => {
		for (const [inputType, data] of [
			['insertText', ','],
			['insertText', '.'],
			['insertFromPaste', '1,5'],
			['insertFromPaste', '1.5'],
			['insertReplacementText', '2,0'],
		]) {
			refusals.length = 0;
			expect(type('whole', inputType, data).defaultPrevented).toBe(true);
			expect(refusals).toEqual([['whole', WHOLE_NUMBER]]);
		}
		expect(WHOLE_NUMBER).toBe('Csak egész számot írhatsz ide.');
	});

	it('leaves digits, a minus sign and deletion alone in a whole-number field', () => {
		for (const [inputType, data] of [['insertText', '5'], ['insertText', '-'], ['deleteContentBackward', null]]) {
			expect(type('whole', inputType, data).defaultPrevented).toBe(false);
		}
		expect(refusals).toEqual([]);
	});

	it('types a dot for a typed comma in a decimal field', () => {
		expect(type('decimal', 'insertText', ',').defaultPrevented).toBe(true);
		expect(inserted).toEqual(['.']);
		expect(refusals).toEqual([]);
	});

	it('leaves a typed dot and digits alone in a decimal field', () => {
		expect(type('decimal', 'insertText', '.').defaultPrevented).toBe(false);
		expect(type('decimal', 'insertText', '5').defaultPrevented).toBe(false);
		expect(inserted).toEqual([]);
	});

	it('pastes 1,5 as 1.5 in a decimal field, and refuses other pasted text with a comma', () => {
		expect(type('decimal', 'insertFromPaste', '1,5').defaultPrevented).toBe(true);
		expect(type('decimal', 'insertFromPaste', '-12,25').defaultPrevented).toBe(true);
		expect(inserted).toEqual(['1.5', '-12.25']);
		expect(refusals).toEqual([]);

		expect(type('decimal', 'insertFromPaste', '1,000,5').defaultPrevented).toBe(true);
		expect(type('decimal', 'insertFromPaste', '5 000,5').defaultPrevented).toBe(true);
		expect(inserted).toEqual(['1.5', '-12.25']);
		expect(refusals).toEqual([['decimal', BAD_NUMBER], ['decimal', BAD_NUMBER]]);
	});

	it('refuses a typed comma when the browser cannot insert text', () => {
		document.execCommand = undefined;
		expect(type('decimal', 'insertText', ',').defaultPrevented).toBe(true);
		expect(refusals).toEqual([['decimal', BAD_NUMBER]]);
	});

	it('ignores other inputs', () => {
		document.body.insertAdjacentHTML('beforeend', '<input type="text" id="text">');
		expect(type('text', 'insertText', ',').defaultPrevented).toBe(false);
	});

	it('keeps a refused comma invalid, so 1,5 never submits as 15', () => {
		const input = document.getElementById('whole');
		const form = document.querySelector('form');
		input.value = '1';
		type('whole', 'insertText', ',');
		typeDigit('whole', '5');

		expect(input.value).toBe('15');
		expect(input.validity.customError).toBe(true);
		expect(input.validationMessage).toBe(WHOLE_NUMBER);
		expect(form.checkValidity()).toBe(false);
		// What the send reads (the form is novalidate): the field is found, so the send stops there.
		expect(firstBadNumber(form).control).toBe(input);
		expect(cleared).toEqual([]);
	});

	it('sets the custom validity on a refused paste and on the execCommand fallback', () => {
		const whole = document.getElementById('whole');
		type('whole', 'insertFromPaste', '1,5');
		expect(whole.validity.customError).toBe(true);
		expect(whole.validationMessage).toBe(WHOLE_NUMBER);

		document.execCommand = undefined;
		const decimal = document.getElementById('decimal');
		type('decimal', 'insertText', ',');
		expect(decimal.validity.customError).toBe(true);
		expect(decimal.validationMessage).toBe(BAD_NUMBER);
	});

	it('clears when the field is edited again: a deletion, or an emptied field', () => {
		const input = document.getElementById('whole');
		input.value = '1';
		type('whole', 'insertText', ',');
		typeDigit('whole', '5');
		input.value = '1';
		input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'deleteContentBackward' }));
		expect(input.validity.customError).toBe(false);
		expect(cleared).toEqual(['whole']);

		type('whole', 'insertText', '.');
		expect(input.validity.customError).toBe(true);
		input.value = '';
		input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: '7' }));
		expect(input.validity.customError).toBe(false);
		expect(cleared).toEqual(['whole', 'whole']);
	});

	it('never sets custom validity when a decimal field turns 1,5 into 1.5', () => {
		const input = document.getElementById('decimal');
		type('decimal', 'insertText', ',');
		type('decimal', 'insertFromPaste', '1,5');
		expect(inserted).toEqual(['.', '1.5']);
		expect(input.validity.customError).toBe(false);
		expect(input.validationMessage).toBe('');
	});
});

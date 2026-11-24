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

describe('the number guard (B4, N17)', () => {
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

	function type(id, inputType, data) {
		const event = new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType, data });
		document.getElementById(id).dispatchEvent(event);
		return event;
	}

	/** What the browser does once an insert is not cancelled. */
	function typeAs(id, inputType, data) {
		const event = type(id, inputType, data);
		if (!event.defaultPrevented) {
			const input = document.getElementById(id);
			input.value += data;
			input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType, data }));
		}
		return event;
	}

	it('refuses a comma or a dot in a whole-number field, and empties the field', () => {
		const input = document.getElementById('whole');
		for (const [inputType, data] of [
			['insertText', ','],
			['insertText', '.'],
			['insertFromPaste', '1,5'],
			['insertFromPaste', '1.5'],
			['insertReplacementText', '2,0'],
			['insertFromDrop', '1,5'],
		]) {
			refusals.length = 0;
			input.value = '12';
			expect(type('whole', inputType, data).defaultPrevented).toBe(true);
			expect(input.value).toBe('');
			expect(refusals).toEqual([['whole', WHOLE_NUMBER]]);
			expect(input.validationMessage).toBe(WHOLE_NUMBER);
		}
		expect(WHOLE_NUMBER).toBe('Csak egész számot írhatsz ide.');
	});

	it('ends 1,5 and 1 5 empty on a whole-number field, blocks the send, and the next digit clears it', () => {
		const input = document.getElementById('whole');
		const form = document.querySelector('form');
		typeAs('whole', 'insertText', '1');
		typeAs('whole', 'insertText', ',');
		typeAs('whole', 'insertText', '5');
		// The "5" went in after the refusal and cleared it: the field holds the retyped number, not 15 from 1,5.
		expect(input.value).toBe('5');
		expect(input.validity.customError).toBe(false);

		input.value = '1';
		type('whole', 'insertText', ' ');
		expect(input.value).toBe('');
		expect(refusals.at(-1)).toEqual(['whole', BAD_NUMBER]);
		expect(input.validity.customError).toBe(true);
		expect(form.checkValidity()).toBe(false);
		expect(firstBadNumber(form).control).toBe(input);

		typeAs('whole', 'insertText', '7');
		expect(input.value).toBe('7');
		expect(input.validity.customError).toBe(false);
		expect(cleared.at(-1)).toBe('whole');
		expect(firstBadNumber(form)).toBeNull();
	});

	it('refuses letters, e, plus and a second dot, and empties the field', () => {
		const input = document.getElementById('decimal');
		for (const [inputType, data] of [
			['insertText', 'a'],
			['insertText', 'e'],
			['insertText', '+'],
			['insertText', ' '],
			['insertFromPaste', 'abc'],
			['insertFromPaste', '1e3'],
			['insertFromPaste', '1.2.3'],
		]) {
			input.value = '4';
			expect(type('decimal', inputType, data).defaultPrevented).toBe(true);
			expect(input.value).toBe('');
			expect(input.validationMessage).toBe(BAD_NUMBER);
		}
		input.value = '1.5';
		expect(type('decimal', 'insertText', '.').defaultPrevented).toBe(true);
		expect(input.value).toBe('');
		expect(type('whole', 'insertText', 'e').defaultPrevented).toBe(true);
		expect(document.getElementById('whole').validationMessage).toBe(BAD_NUMBER);
	});

	it('accepts digits, a minus sign and, on a decimal field, one dot', () => {
		for (const [id, data] of [['whole', '5'], ['whole', '-'], ['whole', '-12'], ['decimal', '.'], ['decimal', '5'], ['decimal', '-1.5']]) {
			expect(type(id, 'insertText', data).defaultPrevented).toBe(false);
		}
		expect(type('decimal', 'insertFromPaste', '12.75').defaultPrevented).toBe(false);
		expect(refusals).toEqual([]);
		expect(inserted).toEqual([]);
	});

	it('clears on a deletion and on a step that fires no beforeinput text', () => {
		const input = document.getElementById('whole');
		type('whole', 'insertText', ',');
		expect(input.validity.customError).toBe(true);
		type('whole', 'deleteContentBackward', null);
		expect(input.validity.customError).toBe(false);
		expect(cleared).toEqual(['whole']);

		type('whole', 'insertText', ',');
		input.value = '3';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		expect(input.validity.customError).toBe(false);
		expect(cleared).toEqual(['whole', 'whole']);
	});

	it("clears on Firefox's spinner step, an insertReplacementText with a plain number", () => {
		const input = document.getElementById('whole');
		type('whole', 'insertText', 'x');
		expect(input.validity.customError).toBe(true);
		expect(type('whole', 'insertReplacementText', '21').defaultPrevented).toBe(false);
		expect(input.validity.customError).toBe(false);
		expect(cleared).toEqual(['whole']);
	});

	it('has no dead end: select-all and 20 are accepted after a refusal', () => {
		const input = document.getElementById('whole');
		input.value = '1';
		type('whole', 'insertText', ',');
		expect(input.validity.customError).toBe(true);
		input.value = '20';
		typeAs('whole', 'insertText', '');
		expect(type('whole', 'insertText', '20').defaultPrevented).toBe(false);
		expect(input.validity.customError).toBe(false);
	});

	it('pastes a Hungarian grouping without its spaces', () => {
		expect(type('whole', 'insertFromPaste', '5 000').defaultPrevented).toBe(true);
		expect(type('whole', 'insertFromPaste', '1 234 567').defaultPrevented).toBe(true);
		expect(type('decimal', 'insertFromPaste', '5 000,5').defaultPrevented).toBe(true);
		expect(inserted).toEqual(['5000', '1234567', '5000.5']);
		expect(refusals).toEqual([]);
		expect(type('whole', 'insertFromPaste', '5 000,5').defaultPrevented).toBe(true);
		expect(refusals).toEqual([['whole', WHOLE_NUMBER]]);
		expect(inserted).toHaveLength(3);
	});

	it('types a dot for a typed comma, and pastes 1,5 as 1.5, in a decimal field', () => {
		expect(type('decimal', 'insertText', ',').defaultPrevented).toBe(true);
		expect(type('decimal', 'insertFromPaste', '1,5').defaultPrevented).toBe(true);
		expect(type('decimal', 'insertFromPaste', '-12,25').defaultPrevented).toBe(true);
		expect(inserted).toEqual(['.', '1.5', '-12.25']);
		expect(refusals).toEqual([]);
		expect(document.getElementById('decimal').validity.customError).toBe(false);

		expect(type('decimal', 'insertFromPaste', '1,000,5').defaultPrevented).toBe(true);
		expect(refusals).toEqual([['decimal', BAD_NUMBER]]);
	});

	it('refuses a typed comma when the browser cannot insert text', () => {
		document.execCommand = undefined;
		expect(type('decimal', 'insertText', ',').defaultPrevented).toBe(true);
		expect(refusals).toEqual([['decimal', BAD_NUMBER]]);
		expect(document.getElementById('decimal').validity.customError).toBe(true);
	});

	/**
	 * Chromium's number control: the insert is applied, and `value` reads what the browser made of it (a trailing
	 * dot is invisible, a pasted dot put into the middle of a number is dropped).
	 */
	function chromium(id, inputType, data, resulting) {
		const event = type(id, inputType, data);
		if (!event.defaultPrevented) {
			const input = document.getElementById(id);
			input.value = resulting;
			input.dispatchEvent(new InputEvent('input', { bubbles: true, inputType, data }));
		}
		return event;
	}

	it('refuses a second typed dot although Chromium reads "1." as 1 (the invisible dot)', () => {
		const input = document.getElementById('decimal');
		input.value = '1';
		expect(chromium('decimal', 'insertText', '.', '1').defaultPrevented).toBe(false);
		expect(chromium('decimal', 'insertText', '.', '1').defaultPrevented).toBe(true);
		expect(input.value).toBe('');
		expect(input.validity.customError).toBe(true);
		expect(refusals).toEqual([['decimal', BAD_NUMBER]]);
		// the refusal cleared the flag: the next number takes a dot again
		expect(chromium('decimal', 'insertText', '2', '2').defaultPrevented).toBe(false);
		expect(chromium('decimal', 'insertText', '.', '2').defaultPrevented).toBe(false);
	});

	it('keeps the dot after a deletion that leaves the integer part, and drops it with the dot deleted', () => {
		const input = document.getElementById('decimal');
		input.value = '1';
		chromium('decimal', 'insertText', '.', '1');
		chromium('decimal', 'insertText', '5', '1.5');
		chromium('decimal', 'deleteContentBackward', null, '1');
		expect(chromium('decimal', 'insertText', '.', '1').defaultPrevented).toBe(true);

		input.value = '1';
		chromium('decimal', 'insertText', '.', '1');
		chromium('decimal', 'deleteContentBackward', null, '1');
		expect(chromium('decimal', 'insertText', '.', '1').defaultPrevented).toBe(false);
	});

	it('ignores a dot flag a script made stale, and recomputes it on a spinner step', () => {
		const input = document.getElementById('decimal');
		input.value = '1';
		chromium('decimal', 'insertText', '.', '1');
		input.value = '7';
		expect(chromium('decimal', 'insertText', '.', '7').defaultPrevented).toBe(false);
		chromium('decimal', 'insertReplacementText', '8', '8');
		expect(chromium('decimal', 'insertText', '.', '8').defaultPrevented).toBe(false);
	});

	it('accepts a paste that Chromium applied whole, and refuses one it changed', () => {
		const input = document.getElementById('decimal');
		// an insertion keeps the pasted text as a substring: "25" + "5000" -> "250005"
		input.value = '25';
		expect(chromium('decimal', 'insertFromPaste', '5000', '250005').defaultPrevented).toBe(false);
		expect(input.validity.customError).toBe(false);
		// a select-all paste of 1.5 over 2.5 leaves exactly 1.5
		input.value = '2.5';
		expect(chromium('decimal', 'insertFromPaste', '1.5', '1.5').defaultPrevented).toBe(false);
		expect(input.value).toBe('1.5');
		expect(refusals).toEqual([]);
		// a trailing dot is invisible
		input.value = '';
		expect(chromium('decimal', 'insertFromPaste', '1.', '1').defaultPrevented).toBe(false);
		expect(refusals).toEqual([]);
		// 1.5 into the middle of 2.5: Chromium stores 215.5
		input.value = '2.5';
		chromium('decimal', 'insertFromPaste', '1.5', '215.5');
		expect(input.value).toBe('');
		expect(input.validity.customError).toBe(true);
		expect(refusals).toEqual([['decimal', BAD_NUMBER]]);
		// a dotted number takes a pasted dot only as a full replacement, whatever else contains it
		input.value = '2.5';
		input.setCustomValidity('');
		chromium('decimal', 'insertFromPaste', '1.5', '1.55');
		expect(input.value).toBe('');
		expect(refusals).toHaveLength(2);
	});

	it('leaves a paste the browser rejects for the send', () => {
		const input = document.getElementById('whole');
		Object.defineProperty(input, 'validity', { value: { badInput: true, customError: false } });
		chromium('whole', 'insertFromPaste', '-5', '');
		expect(refusals).toEqual([]);
		expect(input.validity.customError).toBe(false);
	});

	it('ignores other inputs and other input types', () => {
		document.body.insertAdjacentHTML('beforeend', '<input type="text" id="text">');
		expect(type('text', 'insertText', ',').defaultPrevented).toBe(false);
		expect(type('whole', 'insertLineBreak', null).defaultPrevented).toBe(false);
	});
});

// (a) A field's message clears when its answer changes: on the first edit inside the field's wrapper.
import {
	checkboxes,
	choose,
	consent,
	form,
	load,
	radios,
	respond,
	settle,
	submit,
	text,
	type,
} from './helpers';

const REFUSED = 'Néhány válasz hiányzik.';
const MESSAGE = 'Ezt a mezőt ki kell töltened.';

const page = form(
	text('name') +
		radios('size', ['s', 'm']) +
		checkboxes('extras', ['a', 'b']) +
		consent('terms') +
		'<div class="kalaka-field" data-kalaka-field="pick">' +
		'<input type="checkbox" name="pick" value="1" hidden>' +
		'<span id="kalaka-field-pick-error" class="kalaka-field__error" hidden></span></div>' +
		'<div data-kalaka-account><p id="kalaka-account-error" hidden></p></div>'
);

const root = () => document.querySelector('form');
const alert = () => document.querySelector('[role="alert"]');
const account = () => document.querySelector('#kalaka-account-error');
const slot = (key) =>
	document.querySelector(`[data-kalaka-field="${key}"] .kalaka-field__error`);
const control = (name, value) =>
	document.querySelector(
		value === undefined
			? `[name="${name}"]`
			: `[name="${name}"][value="${value}"]`
	);

/** A refused send that puts a message on the named fields. */
async function refuse(fields) {
	globalThis.fetch.mockResolvedValueOnce(
		respond(400, {
			code: 'kalaka_invalid_answers',
			message: REFUSED,
			data: { status: 400, fields },
		})
	);
	submit(root());
	await settle();
}

beforeEach(() => {
	globalThis.fetch = jest
		.fn()
		.mockResolvedValue(respond(200, { repeated: false, after_submit: '<p>ok</p>' }));
});

describe('a field message on an edit', () => {
	test('typing in a text field clears its message, aria-invalid and describedby, and leaves the others', async () => {
		await load(page);
		await refuse({ name: MESSAGE, size: MESSAGE });

		const input = control('name');
		expect(slot('name').textContent).toBe(MESSAGE);
		expect(input.getAttribute('aria-invalid')).toBe('true');
		expect(input.getAttribute('aria-describedby')).toBe(slot('name').id);

		type(root(), 'name', 'Minta Anna');

		expect(slot('name').textContent).toBe('');
		expect(slot('name').hidden).toBe(true);
		expect(input.hasAttribute('aria-invalid')).toBe(false);
		expect(input.hasAttribute('aria-describedby')).toBe(false);
		expect(slot('size').textContent).toBe(MESSAGE);
	});

	test('an input event alone clears a text field’s message', async () => {
		await load(page);
		await refuse({ name: MESSAGE });

		const input = control('name');
		expect(slot('name').textContent).toBe(MESSAGE);

		input.dispatchEvent(new Event('input', { bubbles: true }));

		expect(slot('name').textContent).toBe('');
		expect(slot('name').hidden).toBe(true);
	});

	test.each([
		['a radio', 'size', () => choose(root(), 'size', 'm')],
		['a checkbox', 'extras', () => control('extras[]', 'a').click()],
		['a consent', 'terms', () => control('terms').click()],
	])('%s clears its message on change', async (name, key, edit) => {
		await load(page);
		await refuse({ [key]: MESSAGE });

		expect(slot(key).textContent).toBe(MESSAGE);

		edit();

		expect(slot(key).textContent).toBe('');
		expect(slot(key).hidden).toBe(true);
	});

	test('an extension-like field clears when its control dispatches a bubbling change', async () => {
		await load(page);
		await refuse({ pick: MESSAGE });

		expect(slot('pick').textContent).toBe(MESSAGE);

		control('pick').dispatchEvent(new Event('change', { bubbles: true }));

		expect(slot('pick').textContent).toBe('');
		expect(slot('pick').hidden).toBe(true);
	});

	test('the form alert and the account error do not clear on a field edit', async () => {
		await load(page);
		await refuse({ name: MESSAGE });
		account().textContent = 'Fiók hiba';
		account().hidden = false;

		type(root(), 'name', 'Minta Anna');

		expect(alert().textContent).toBe(REFUSED);
		expect(account().textContent).toBe('Fiók hiba');
		expect(account().hidden).toBe(false);
	});

	test('the message shows again from the server on the next send', async () => {
		await load(page);
		await refuse({ name: MESSAGE });
		type(root(), 'name', 'Minta Anna');
		expect(slot('name').textContent).toBe('');

		await refuse({ name: MESSAGE });

		expect(slot('name').textContent).toBe(MESSAGE);
		expect(slot('name').hidden).toBe(false);
	});
});

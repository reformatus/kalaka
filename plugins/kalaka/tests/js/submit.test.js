import {
	area,
	checkboxes,
	choose,
	consent,
	FAILURE,
	form,
	TOKEN,
	load,
	radios,
	respond,
	sentBody,
	settle,
	submit,
	text,
	type,
} from './helpers';

const page = form(
	text('name') +
		area('note') +
		consent('terms') +
		radios('size', ['s', 'm']) +
		checkboxes('extras', ['a', 'b'])
);

const root = () => document.querySelector('form');
const button = () => document.querySelector('button');
const alert = () => document.querySelector('[role="alert"]');
const slot = (key) =>
	document.querySelector(`[data-kalaka-field="${key}"] .kalaka-field__error`);

beforeEach(() => {
	globalThis.fetch = jest.fn().mockResolvedValue(respond(200, { repeated: false, after_submit: '<p>ok</p>' }));
});

// AC6: the request body and the token.
describe('the submit request', () => {
	test('is a JSON POST to the submit URL with the answers and the token, in the shape of the submit seam', async () => {
		await load(page);

		submit(root());
		await settle();

		const [url, options] = globalThis.fetch.mock.calls[0];
		expect(url).toBe(
			'http://example.test/wp-json/kalaka-portal/v1/forms/12/submit'
		);
		expect(options.method).toBe('POST');
		expect(options.headers['Content-Type']).toBe('application/json');
		const body = sentBody();
		expect(Object.keys(body).sort()).toEqual(['answers', 'token', 'version']);
		expect(body.version).toBe(34);
		expect(body.token).toBe(TOKEN);
	});

	test('sends each kind of control as its answer and never null', async () => {
		await load(page);

		submit(root());
		await settle();

		// An empty text is "", an unticked consent false, a single choice with nothing chosen is left out and
		// a multiple choice with nothing chosen is [].
		expect(sentBody().answers).toEqual({
			name: '',
			note: '',
			terms: false,
			extras: [],
		});
		expect(JSON.stringify(sentBody())).not.toContain('null');
	});

	test('sends what was typed, ticked and chosen', async () => {
		await load(page);
		type(root(), 'name', '  Anna  ');
		type(root(), 'note', 'sor');
		root().querySelector('[name="terms"]').click();
		choose(root(), 'size', 'm');
		root().querySelector('input[value="a"]').click();
		root().querySelector('input[value="b"]').click();

		submit(root());
		await settle();

		expect(sentBody().answers).toEqual({
			name: '  Anna  ',
			note: 'sor',
			terms: true,
			size: 'm',
			extras: ['a', 'b'],
		});
	});

	test('carries the token of the page on every send of a page load', async () => {
		globalThis.fetch.mockResolvedValue(
			respond(400, { code: 'kalaka_rule_violation', message: 'Hiba', data: { status: 400 } })
		);
		await load(page);

		submit(root());
		await settle();
		submit(root());
		await settle();

		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(sentBody(0).token).toBe(TOKEN);
		expect(sentBody(1).token).toBe(TOKEN);
	});

	test('is the one the page renders, and goes into no cookie or storage', async () => {
		await load(form(text('name'), {}, 'ZyXwVuTsRqPoNmLkJiHg_-'));
		submit(root());
		await settle();

		expect(sentBody().token).toBe('ZyXwVuTsRqPoNmLkJiHg_-');
		expect(document.cookie).not.toContain('ZyXwVuTsRqPoNmLkJiHg_-');
		expect(JSON.stringify(window.localStorage)).not.toContain('ZyXwVuTsRqPoNmLkJiHg_-');
	});

	test('sends nothing before the script has started', async () => {
		document.body.innerHTML = page;

		expect(button().disabled).toBe(true);
		expect(globalThis.fetch).not.toHaveBeenCalled();
	});
});

// AC7: what the answer does.
describe('the answer to a send', () => {
	test('InvalidAnswers puts its message in the message area and each field message after that field, and the next send clears them', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(400, {
				code: 'kalaka_invalid_answers',
				message: 'Néhány válasz hiányzik.',
				data: { status: 400, fields: { name: 'Ezt a mezőt ki kell töltened.' } },
			})
		);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe('Néhány válasz hiányzik.');
		expect(slot('name').textContent).toBe('Ezt a mezőt ki kell töltened.');
		expect(slot('name').hidden).toBe(false);
		const wrapper = document.querySelector('[data-kalaka-field="name"]');
		expect(wrapper.lastElementChild).toBe(slot('name'));
		expect(slot('note').textContent).toBe('');
		expect(button().disabled).toBe(false);
		expect(button().hasAttribute('aria-disabled')).toBe(false);
		// The message is tied to the field's control, which is invalid and has the focus.
		const name = document.querySelector('[name="name"]');
		expect(slot('name').id).toBe('kalaka-field-name-error');
		expect(name.getAttribute('aria-describedby')).toBe(slot('name').id);
		expect(name.getAttribute('aria-invalid')).toBe('true');
		expect(document.activeElement).toBe(name);

		globalThis.fetch.mockResolvedValueOnce(
			respond(400, { code: 'kalaka_rule_violation', message: 'Más hiba', data: { status: 400 } })
		);
		submit(root());
		await settle();

		expect(alert().textContent).toBe('Más hiba');
		expect(slot('name').textContent).toBe('');
		expect(slot('name').hidden).toBe(true);
		expect(name.hasAttribute('aria-invalid')).toBe(false);
		expect(name.hasAttribute('aria-describedby')).toBe(false);
		expect(document.activeElement).toBe(alert());
	});

	test('a message of a field that does not show is not written', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(400, {
				code: 'kalaka_invalid_answers',
				message: 'Néhány válasz hiányzik.',
				data: { status: 400, fields: { note: 'Hiba' } },
			})
		);
		await load(page);
		document.querySelector('[name="note"]').disabled = true;

		submit(root());
		await settle();

		expect(slot('note').textContent).toBe('');
		expect(slot('note').hidden).toBe(true);
		expect(document.activeElement).toBe(alert());
	});

	test.each([
		['a WordPress REST error', respond(404, { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } })],
		['an error of another plugin', respond(403, { code: 'x', message: 'Más hiba', data: { status: 403 } })],
	])('%s shows the system failure text, not its own message', async (name, answer) => {
		globalThis.fetch.mockResolvedValueOnce(answer);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe(FAILURE);
	});

	test('another error response shows its message', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(409, { code: 'kalaka_conflict', message: 'Ütközés.', data: { status: 409 } })
		);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe('Ütközés.');
	});

	test('a network failure shows the system failure text the page passes in', async () => {
		globalThis.fetch.mockRejectedValueOnce(new TypeError('network'));
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe(FAILURE);
		expect(button().disabled).toBe(false);
		expect(document.activeElement).toBe(alert());
	});

	test.each([
		['an answer that is not JSON', respond(502)],
		['an error without a message', respond(500, { code: 'x' })],
		['a success without markup', respond(200, { ok: true })],
	])('%s shows the system failure text', async (name, answer) => {
		globalThis.fetch.mockResolvedValueOnce(answer);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe(FAILURE);
	});

	test('a repeated send answers like the first: the form is replaced by the after-submit section', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(200, {
				repeated: true,
				after_submit: '<div id="after">Kész</div>',
			})
		);
		await load(page);

		submit(root());
		await settle();

		expect(document.querySelector('form')).toBeNull();
		expect(document.activeElement).toBe(document.getElementById('after'));
	});

	test('a spent token (409 kalaka_token_reused) shows the text the server gives', async () => {
		const reused =
			'Ez az űrlap már nem küldhető be. Töltsd újra az oldalt, ha új jelentkezést szeretnél küldeni.';
		globalThis.fetch.mockResolvedValueOnce(
			respond(409, { code: 'kalaka_token_reused', message: reused, data: { status: 409 } })
		);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe(reused);
		expect(document.querySelector('form')).not.toBeNull();
	});

	test('an outdated version on submit (409 kalaka_form_outdated) shows the text the server gives', async () => {
		const outdated =
			'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.';
		globalThis.fetch.mockResolvedValueOnce(
			respond(409, { code: 'kalaka_form_outdated', message: outdated, data: { status: 409 } })
		);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe(outdated);
	});

	test('a refusal of the submit seam shows its own text', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(403, {
				code: 'kalaka_form_refused',
				message: 'Ez az űrlap most nem küldhető be.',
				data: { status: 403, refusals: ['outside_registration_window'] },
			})
		);
		await load(page);

		submit(root());
		await settle();

		expect(alert().textContent).toBe('Ez az űrlap most nem küldhető be.');
		expect(document.activeElement).toBe(alert());
	});

	test('a 2xx response replaces the form with its markup', async () => {
		globalThis.fetch.mockResolvedValueOnce(
			respond(201, { after_submit: '<p id="thanks">Köszönjük!</p>' })
		);
		await load(page);

		submit(root());
		await settle();

		expect(document.querySelector('form')).toBeNull();
		expect(document.getElementById('thanks').textContent).toBe('Köszönjük!');
		expect(document.activeElement).toBe(document.getElementById('thanks'));
		expect(document.getElementById('thanks').getAttribute('tabindex')).toBe('-1');
	});

	test('the button is aria-disabled and the form busy while a request runs, and the button keeps its focus', async () => {
		let finish;
		globalThis.fetch.mockReturnValueOnce(
			new Promise((resolve) => {
				finish = resolve;
			})
		);
		await load(page);

		button().focus();
		submit(root());
		await settle();
		expect(button().getAttribute('aria-disabled')).toBe('true');
		expect(button().disabled).toBe(false);
		expect(root().getAttribute('aria-busy')).toBe('true');
		expect(document.activeElement).toBe(button());

		// A second send while one runs sends nothing.
		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(1);

		finish(respond(400, { code: 'kalaka_rule_violation', message: 'Hiba', data: { status: 400 } }));
		await settle();
		expect(button().hasAttribute('aria-disabled')).toBe(false);
		expect(root().hasAttribute('aria-busy')).toBe(false);
	});

	test('the page is left to the browser: submitting does not navigate', async () => {
		await load(page);
		const event = new Event('submit', { bubbles: true, cancelable: true });

		root().dispatchEvent(event);

		expect(event.defaultPrevented).toBe(true);
	});
});

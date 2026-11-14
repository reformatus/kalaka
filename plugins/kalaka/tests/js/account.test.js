import { FAILURE, TOKEN, field, form, load, respond, sentBody, settle, submit, text } from './helpers';

/** S15: a registration form that begins with the account step, as `Blocks/form/render.php` renders it. */
function stepForm({ skip = false, triggers = [] } = {}) {
	const context = {
		formId: 12,
		version: 34,
		url: 'http://example.test/wp-json/kalaka-portal/v1/forms/12/submit',
		availabilityUrl: 'http://example.test/wp-json/kalaka-portal/v1/forms/12/availability',
		failure: FAILURE,
		triggers,
		account: { skip, email: 'email', name: 'nev' },
	};
	const attribute = JSON.stringify(context).replace(/'/g, '&#039;');
	const skipButton = skip
		? '<button type="button" data-kalaka-account-skip data-wp-on--click="actions.skipAccount" disabled>Fiók nélkül folytatom</button>'
		: '';
	const fields =
		field('email', '<input type="email" name="email" required>') +
		field('nev', '<input type="text" name="nev" required>') +
		field('megjegyzes', '<input type="text" name="megjegyzes">');

	return `<form novalidate data-wp-interactive="kalaka/form" data-wp-context='${attribute}' data-wp-init="callbacks.start" data-wp-on--change="actions.update" data-wp-on--submit="actions.submit"><input type="hidden" data-kalaka-token value="${TOKEN}"><div data-kalaka-account-step><p><button type="button" data-kalaka-account-signup data-wp-on--click="actions.signUp" disabled>Regisztrálok</button><a href="http://example.test/wp-login.php?redirect_to=x" data-kalaka-account-login>Bejelentkezem</a>${skipButton}</p><div data-kalaka-account-form hidden><p><label for="kalaka-account-email">E-mail</label><input type="email" id="kalaka-account-email" data-kalaka-account-email required></p><p><label for="kalaka-account-name">Név</label><input type="text" id="kalaka-account-name" data-kalaka-account-name required></p><button type="button" data-kalaka-account-next data-wp-on--click="actions.signUpNext">Tovább</button></div></div><div data-kalaka-form-body hidden>${fields}<div role="alert" data-kalaka-alert></div><button type="submit" data-kalaka-send disabled>Küldés</button></div><div data-kalaka-spinner hidden></div><div role="status" data-kalaka-status data-kalaka-loading="Betöltés…"></div></form>`;
}

const root = () => document.querySelector('form');
const body = () => document.querySelector('[data-kalaka-form-body]');
const step = () => document.querySelector('[data-kalaka-account-step]');
const control = (name) => document.querySelector(`[data-kalaka-field] input[name="${name}"]`);
const click = (selector) => document.querySelector(selector).click();

function fillStep(email, name) {
	document.querySelector('[data-kalaka-account-email]').value = email;
	document.querySelector('[data-kalaka-account-name]').value = name;
}

beforeEach(() => {
	globalThis.fetch = jest.fn().mockResolvedValue(respond(200, { repeated: false, after_submit: '<p>ok</p>' }));
});

describe('the account step', () => {
	test('shows first, with the form hidden and its buttons enabled; the login is a link', async () => {
		await load(stepForm());

		expect(step().hidden).toBe(false);
		expect(body().hidden).toBe(true);
		expect(document.querySelector('[data-kalaka-account-signup]').disabled).toBe(false);
		expect(document.querySelector('[data-kalaka-account-login]').getAttribute('href')).toContain('wp-login.php');
		expect(document.querySelector('[data-kalaka-account-skip]')).toBeNull();
	});

	test('sends nothing while it shows, Enter included', async () => {
		await load(stepForm());

		submit(root());
		await settle();

		expect(globalThis.fetch).not.toHaveBeenCalled();
	});

	test('sign up: the browser checks the two values, and nothing moves on while one is refused', async () => {
		await load(stepForm());
		click('[data-kalaka-account-signup]');
		expect(document.querySelector('[data-kalaka-account-form]').hidden).toBe(false);

		fillStep('nem-email', 'Teszt Anna');
		click('[data-kalaka-account-next]');
		await settle();

		expect(body().hidden).toBe(true);
		expect(control('email').value).toBe('');
	});

	test('sign up fills in the contact email and display name, locks them, and sends the choice', async () => {
		await load(stepForm());
		click('[data-kalaka-account-signup]');
		fillStep(' teszt.anna@example.org ', 'Teszt Anna');
		click('[data-kalaka-account-next]');
		await settle();

		expect(step().hidden).toBe(true);
		expect(body().hidden).toBe(false);
		expect(control('email').value).toBe('teszt.anna@example.org');
		expect(control('email').readOnly).toBe(true);
		expect(control('nev').value).toBe('Teszt Anna');
		expect(control('nev').readOnly).toBe(true);

		submit(root());
		await settle();

		const sent = sentBody();
		expect(sent.account).toBe('signup');
		expect(sent.answers).toEqual({ email: 'teszt.anna@example.org', nev: 'Teszt Anna', megjegyzes: '' });
	});

	test('Enter in the sign-up fields is its Tovább', async () => {
		await load(stepForm());
		click('[data-kalaka-account-signup]');
		fillStep('teszt.anna@example.org', 'Teszt Anna');

		submit(root());
		await settle();

		expect(globalThis.fetch).not.toHaveBeenCalled();
		expect(body().hidden).toBe(false);
	});

	test('a field the server refuses is unlocked to be corrected', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(400, {
				code: 'kalaka_invalid_answers',
				message: 'Javítsd a megjelölt mezőket.',
				data: { fields: { email: 'Adj meg egy érvényes e-mail-címet.' } },
			})
		);
		await load(stepForm());
		click('[data-kalaka-account-signup]');
		fillStep('teszt.anna@example.org', 'Teszt Anna');
		click('[data-kalaka-account-next]');
		submit(root());
		await settle();

		expect(control('email').readOnly).toBe(false);
		expect(control('nev').readOnly).toBe(true);
	});

	test('skip (optional mode) shows the form and sends the choice, with nothing locked', async () => {
		await load(stepForm({ skip: true }));
		click('[data-kalaka-account-skip]');
		await settle();

		expect(body().hidden).toBe(false);
		expect(control('email').readOnly).toBe(false);

		submit(root());
		await settle();

		expect(sentBody().account).toBe('skip');
	});

	test('a form without a step sends no choice', async () => {
		await load(form(text('nev')));
		control('nev').value = 'Teszt Anna';

		submit(root());
		await settle();

		expect(Object.keys(sentBody())).not.toContain('account');
	});
});

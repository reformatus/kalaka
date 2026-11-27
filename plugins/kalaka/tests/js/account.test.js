/**
 * S15, the account block (owner ruling 2026-11-18): the login window, the in-place refresh after a login
 * (the return page's message, or focus while waiting), the send that never goes out as a guest after a
 * login, the block's error in `mandatory` mode, and the ended session's retry.
 */
import { field, form, load, page, settle, submit, text, type } from './helpers';

const NONCE_URL = 'http://example.test/wp-admin/admin-ajax.php?action=rest-nonce';
const LOGIN_URL = 'http://example.test/wp-login.php?kalaka_form=12';
const POPUP_URL = LOGIN_URL + '&popup=1';
const PAGE_URL = 'http://example.test/jelentkezes/';
const ERROR = 'Előbb készíts fiókot, vagy jelentkezz be.';
const NONCE = 'abcdef0123';

const account = { url: LOGIN_URL, nonceUrl: NONCE_URL, page: PAGE_URL, loggedIn: false, error: ERROR };

const block = () =>
	'<div class="kalaka-account" data-kalaka-account="out"><p>Szöveg.</p><p><button type="button"' +
	' data-kalaka-account-login data-wp-on--click="actions.logIn">Fiókot készítek vagy bejelentkezem</button></p>' +
	'<p id="kalaka-account-error" class="kalaka-field__error" hidden></p></div>';

const loggedIn = '<div class="kalaka-account" data-kalaka-account="in"><p>Bejelentkezve: Teszt Anna.</p></div>';

const email = (key) => field(key, `<input type="email" name="${key}">`);

const root = () => document.querySelector('form');
const alert = () => document.querySelector('[role="alert"]');
const accountBlock = () => document.querySelector('[data-kalaka-account]');

/** A fetch that answers the nonce read (logged in or not) and the routes by their address. */
function server({ nonce = NONCE, submitted = { after_submit: '<p>ok</p>' }, refused = null } = {}) {
	const calls = [];
	globalThis.fetch = jest.fn(async (url, options = {}) => {
		calls.push({ url, options });
		if (url === NONCE_URL) {
			return nonce === null
				? { ok: false, status: 400, text: async () => '0' }
				: { ok: true, status: 200, text: async () => nonce };
		}
		const body = JSON.parse(options.body);
		if (url.endsWith('/submit')) {
			const answer = refused ?? submitted;
			refused = null;
			return { ok: answer.after_submit !== undefined, status: answer.after_submit ? 200 : 403, json: async () => answer };
		}
		const answer = { availability: { fields: {}, chains: [], errors: {}, pages: [] }, parts: {} };
		if (body.account !== undefined) {
			answer.account = { html: loggedIn, filled: { mail: 'teszt.anna@example.org' } };
		}
		return { ok: true, status: 200, json: async () => answer };
	});

	return calls;
}

const message = (origin = window.location.origin, data = { kalaka: 'account' }) =>
	window.dispatchEvent(new window.MessageEvent('message', { origin, data }));

beforeEach(() => {
	window.open = jest.fn(() => ({ closed: false }));
});

describe('the login window', () => {
	test('the block opens WordPress\'s login page in a window of its own, keeping the opener', async () => {
		server();
		await load(form(block() + text('name'), { account }));

		document.querySelector('[data-kalaka-account-login]').click();
		await settle();

		expect(window.open).toHaveBeenCalledTimes(1);
		const [url, name, features] = window.open.mock.calls[0];
		expect(url).toBe(POPUP_URL);
		expect(name).toBe('kalaka-account');
		expect(features).not.toContain('noopener');
	});

	test('the return page\'s message refreshes the form in place: the nonce, the block and an empty email', async () => {
		const calls = server();
		await load(form(block() + text('name') + email('mail'), { account }));
		type(root(), 'name', 'Teszt Anna');

		message();
		await settle();
		await settle();

		expect(calls[0].url).toBe(NONCE_URL);
		const refetch = calls.find((call) => call.url.endsWith('/availability'));
		expect(refetch.options.headers['X-WP-Nonce']).toBe(NONCE);
		expect(JSON.parse(refetch.options.body).account).toBe(PAGE_URL);
		expect(accountBlock().getAttribute('data-kalaka-account')).toBe('in');
		expect(root().querySelector('[name="name"]').value).toBe('Teszt Anna');
		expect(root().querySelector('[name="mail"]').value).toBe('teszt.anna@example.org');
	});

	test('a typed contact email is never overwritten', async () => {
		server();
		await load(form(block() + email('mail'), { account }));
		type(root(), 'mail', 'kezzel@example.org');

		message();
		await settle();
		await settle();

		expect(root().querySelector('[name="mail"]').value).toBe('kezzel@example.org');
	});

	test('a message from another origin, or of another shape, is ignored', async () => {
		const calls = server();
		await load(form(block() + text('name'), { account }));

		message('http://evil.example.com');
		message(window.location.origin, { kalaka: 'other' });
		await settle();

		expect(calls).toEqual([]);
	});

	test('with no message, the form checks again on focus only while it waits for a login', async () => {
		const calls = server({ nonce: null });
		await load(form(block() + text('name'), { account }));

		window.dispatchEvent(new Event('focus'));
		await settle();
		expect(calls).toEqual([]);

		document.querySelector('[data-kalaka-account-login]').click();
		window.dispatchEvent(new Event('focus'));
		await settle();
		expect(calls.map((call) => call.url)).toEqual([NONCE_URL]);
		// Still logged out: nothing changes.
		expect(accountBlock().getAttribute('data-kalaka-account')).toBe('out');
	});

	test('a login window seen closed ends the wait after one last check', async () => {
		const popup = { closed: false };
		window.open = jest.fn(() => popup);
		const calls = server({ nonce: null });
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		// Open: every focus checks (the message may have been lost).
		window.dispatchEvent(new Event('focus'));
		await settle();
		expect(calls).toHaveLength(1);

		popup.closed = true;
		window.dispatchEvent(new Event('focus'));
		await settle();
		expect(calls).toHaveLength(2);

		// The wait is over: later focuses ask nothing.
		window.dispatchEvent(new Event('focus'));
		await settle();
		expect(calls).toHaveLength(2);
	});

	test('a window closed after a login still logs the form in', async () => {
		const popup = { closed: true };
		window.open = jest.fn(() => popup);
		server();
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		window.dispatchEvent(new Event('focus'));
		await settle();
		await settle();

		expect(accountBlock().getAttribute('data-kalaka-account')).toBe('in');
	});

	test('with no window to look at (a tab the person came back from), every focus still checks', async () => {
		window.open = jest.fn(() => null);
		const calls = server({ nonce: null });
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		for (let i = 0; i < 3; i += 1) {
			window.dispatchEvent(new Event('focus'));
			await settle();
		}

		expect(calls).toHaveLength(3);
	});

	test('once the closed window was seen, a send does not wait for another check and goes out as a guest', async () => {
		const popup = { closed: false };
		window.open = jest.fn(() => popup);
		const calls = server({ nonce: null });
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();
		popup.closed = true;
		window.dispatchEvent(new Event('focus'));
		await settle();
		const nonces = () => calls.filter((call) => call.url === NONCE_URL).length;
		expect(nonces()).toBe(1);

		submit(root());
		await settle();
		await settle();

		expect(nonces()).toBe(1);
		const sent = calls.find((call) => call.url.endsWith('/submit'));
		expect(sent.options.headers['X-WP-Nonce']).toBeUndefined();
	});
});

describe('sending after the window opened', () => {
	test('a send waits for the login check and goes out as the account', async () => {
		const calls = server();
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		submit(root());
		await settle();
		await settle();

		const sent = calls.find((call) => call.url.endsWith('/submit'));
		expect(sent.options.headers['X-WP-Nonce']).toBe(NONCE);
	});

	test('nobody logged in: the send goes out as a guest', async () => {
		const calls = server({ nonce: null });
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		submit(root());
		await settle();
		await settle();

		const sent = calls.find((call) => call.url.endsWith('/submit'));
		expect(sent.options.headers['X-WP-Nonce']).toBeUndefined();
	});
});

describe('mandatory mode', () => {
	test('a refused send for the account shows the block\'s error and focuses its button', async () => {
		server({
			nonce: null,
			refused: {
				code: 'kalaka_form_refused',
				message: 'Ez az űrlap most nem küldhető be.',
				data: { refusals: ['account_required'] },
			},
		});
		await load(form(block() + text('name'), { account }));

		submit(root());
		await settle();
		await settle();

		const slot = document.querySelector('#kalaka-account-error');
		expect(slot.hidden).toBe(false);
		expect(slot.textContent).toBe(ERROR);
		expect(document.activeElement).toBe(document.querySelector('[data-kalaka-account-login]'));
	});

	test('a refused send with no block that shows puts the refusal\'s message in the alert', async () => {
		const refusal = 'Ez az űrlap most nem küldhető be.';
		server({
			nonce: null,
			refused: { code: 'kalaka_form_refused', message: refusal, data: { refusals: ['account_required'] } },
		});
		await load(form(text('name'), { account }));

		submit(root());
		await settle();
		await settle();

		expect(alert().textContent).toBe(refusal);
		expect(document.activeElement).toBe(alert());
	});

	test('a site that answers the logged-out nonce read otherwise does not block a guest\'s send', async () => {
		const calls = server({ nonce: null });
		const plain = globalThis.fetch;
		globalThis.fetch = jest.fn((url, options) =>
			url === NONCE_URL
				? Promise.resolve({ ok: false, status: 403, text: async () => '-1' })
				: plain(url, options)
		);
		await load(form(block() + text('name'), { account }));
		document.querySelector('[data-kalaka-account-login]').click();

		submit(root());
		await settle();
		await settle();

		expect(calls.some((call) => call.url.endsWith('/submit'))).toBe(true);
	});

	test('Tovább refused for the account shows the block\'s error under it', async () => {
		globalThis.fetch = jest.fn(async () => ({
			ok: true,
			status: 200,
			json: async () => ({
				availability: { fields: {}, chains: [], errors: { 'kalaka/account': ERROR }, pages: [true, true] },
				parts: {},
			}),
		}));
		await load(form(page(0, block() + text('name')) + page(1, text('more')), { account, pages: [true, true] }));

		document.querySelector('[data-kalaka-next]').click();
		await settle();
		await settle();

		expect(document.querySelector('#kalaka-account-error').textContent).toBe(ERROR);
		expect(document.querySelector('[data-kalaka-page="0"]').hidden).toBe(false);
		expect(document.activeElement).toBe(document.querySelector('[data-kalaka-account-login]'));
	});
});

describe('an ended session', () => {
	test('opens the login window and sends again after the login, with no reload', async () => {
		const calls = server({
			refused: { code: 'kalaka_session_ended', message: 'Lépj be újra.', data: { status: 401 } },
		});
		await load(form(block() + text('name'), { account, nonce: 'old0000000', logIn: 'Bejelentkezés' }));
		type(root(), 'name', 'Teszt Anna');

		submit(root());
		await settle();
		expect(window.open).toHaveBeenCalledWith(POPUP_URL, 'kalaka-account', expect.any(String));
		expect(alert().querySelector('a').getAttribute('href')).toBe(LOGIN_URL);

		message();
		await settle();
		await settle();
		await settle();

		const sends = calls.filter((call) => call.url.endsWith('/submit'));
		expect(sends).toHaveLength(2);
		expect(sends[1].options.headers['X-WP-Nonce']).toBe(NONCE);
		expect(JSON.parse(sends[1].options.body).answers.name).toBe('Teszt Anna');
		expect(root()).toBeNull();
	});

	test('a retry waiting for the ended session\'s login survives its link, but a fresh login from the block drops it', async () => {
		const refused = { code: 'kalaka_session_ended', message: 'Lépj be újra.', data: { status: 401 } };
		let calls = server({ refused });
		await load(form(block() + text('name'), { account, nonce: 'old0000000', logIn: 'Bejelentkezés' }));
		type(root(), 'name', 'Teszt Anna');
		submit(root());
		await settle();

		// The window was abandoned; the person logs in from the block's button instead.
		document.querySelector('[data-kalaka-account-login]').click();
		message();
		await settle();
		await settle();
		await settle();

		expect(calls.filter((call) => call.url.endsWith('/submit'))).toHaveLength(1);
		expect(root()).not.toBeNull();

		// The ended session's own link keeps the retry.
		calls = server({ refused });
		document.body.innerHTML = '';
		await load(form(block() + text('name'), { account, nonce: 'old0000000', logIn: 'Bejelentkezés' }));
		type(root(), 'name', 'Teszt Anna');
		submit(root());
		await settle();
		alert().querySelector('a').click();
		message();
		await settle();
		await settle();
		await settle();

		expect(calls.filter((call) => call.url.endsWith('/submit'))).toHaveLength(2);
	});
});

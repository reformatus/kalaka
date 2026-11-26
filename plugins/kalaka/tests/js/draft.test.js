import { form, load, respond, settle, submit, text, type } from './helpers';

const DRAFT_URL = 'http://example.test/wp-json/kalaka-portal/v1/forms/12/draft';

const CLAIM_URL = DRAFT_URL + '/claim';

const root = () => document.querySelector('form');

const draftCalls = () =>
	globalThis.fetch.mock.calls.filter(([url]) => url === DRAFT_URL);

beforeEach(() => {
	globalThis.fetch = jest.fn().mockResolvedValue(respond(200, { saved: true }));
});

afterEach(() => {
	jest.useRealTimers();
});

// S74: the autosave.
describe('the autosave', () => {
	test('keeps the answers once they rest, with the version, the page and the nonce', async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL, nonce: 'n0nce' }));
		jest.useFakeTimers();

		type(root(), 'name', 'Teszt Anna');
		expect(draftCalls()).toHaveLength(0);
		jest.advanceTimersByTime(3000);

		expect(draftCalls()).toHaveLength(1);
		const [, options] = draftCalls()[0];
		expect(options.method).toBe('POST');
		expect(options.headers['X-WP-Nonce']).toBe('n0nce');
		expect(JSON.parse(options.body)).toEqual({
			version: 34,
			answers: { name: 'Teszt Anna' },
			page: 0,
			claims: 0,
		});
	});

	test('carries the claim count the page opened with', async () => {
		await load(
			form(text('name'), { draftUrl: DRAFT_URL, draftClaims: 2 })
		);
		jest.useFakeTimers();

		type(root(), 'name', 'Teszt Anna');
		jest.advanceTimersByTime(3000);

		expect(JSON.parse(draftCalls()[0][1].body).claims).toBe(2);
	});

	test('saves once for changes made close together', async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL }));
		jest.useFakeTimers();

		type(root(), 'name', 'T');
		jest.advanceTimersByTime(1000);
		type(root(), 'name', 'Teszt');
		jest.advanceTimersByTime(3000);

		expect(draftCalls()).toHaveLength(1);
	});

	test('is not there without a draft route', async () => {
		await load(form(text('name')));
		jest.useFakeTimers();

		type(root(), 'name', 'Teszt Anna');
		jest.advanceTimersByTime(5000);

		expect(draftCalls()).toHaveLength(0);
	});

	test("a guest's first answer change saves at once, the rest after the rest (owner 2026-11-26)", async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL, draftStart: true }));
		jest.useFakeTimers();

		type(root(), 'name', 'T');
		expect(draftCalls()).toHaveLength(1);
		expect(JSON.parse(draftCalls()[0][1].body).answers).toEqual({ name: 'T' });
		expect(draftCalls()[0][1].headers['X-WP-Nonce']).toBeUndefined();

		await jest.advanceTimersByTimeAsync(0);
		type(root(), 'name', 'Teszt Anna');
		expect(draftCalls()).toHaveLength(1);
		await jest.advanceTimersByTimeAsync(3000);
		expect(draftCalls()).toHaveLength(2);
		expect(JSON.parse(draftCalls()[1][1].body).answers).toEqual({
			name: 'Teszt Anna',
		});
	});

	test("a guest's changes wait for the save that gives the cookie", async () => {
		let answer;
		globalThis.fetch = jest.fn(
			() =>
				new Promise((resolve) => {
					answer = resolve;
				})
		);
		await load(form(text('name'), { draftUrl: DRAFT_URL, draftStart: true }));
		jest.useFakeTimers();

		type(root(), 'name', 'T');
		type(root(), 'name', 'Te');
		await jest.advanceTimersByTimeAsync(3000);
		expect(draftCalls()).toHaveLength(1);

		answer(respond(200, { saved: true }));
		await jest.advanceTimersByTimeAsync(3000);
		expect(draftCalls()).toHaveLength(2);
	});

	test('a guest whose page holds a draft saves on the rest, not at once', async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL }));
		jest.useFakeTimers();

		type(root(), 'name', 'T');
		expect(draftCalls()).toHaveLength(0);
		jest.advanceTimersByTime(3000);
		expect(draftCalls()).toHaveLength(1);
	});

	test('saves what is waiting when the page is left, kept alive', async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL }));

		type(root(), 'name', 'Teszt Anna');
		window.dispatchEvent(new Event('pagehide'));

		expect(draftCalls()).toHaveLength(1);
		expect(draftCalls()[0][1].keepalive).toBe(true);
	});

	test('saves nothing once a send starts', async () => {
		await load(form(text('name'), { draftUrl: DRAFT_URL }));

		type(root(), 'name', 'Teszt Anna');
		submit(root());
		await settle();
		window.dispatchEvent(new Event('pagehide'));
		await new Promise((resolve) => setTimeout(resolve, 3100));

		expect(draftCalls()).toHaveLength(0);
	});
});

// S74, pass 2: a save from before a claim must never write over what the claim brought.
describe('the autosave and the claim', () => {
	const claimCalls = () =>
		globalThis.fetch.mock.calls.filter(([url]) => url === CLAIM_URL);

	// What the popup (UX) binds to the choice: here a bare button, no markup of Kaláka's.
	const choice = (value) =>
		`<button type="button" value="${value}" data-wp-on--click="actions.resolveDraftClaim">${value}</button>`;

	test('stops once the choice is sent: nothing waiting, due or sent when the page is left', async () => {
		await load(
			form(text('name') + choice('merge'), {
				draftUrl: DRAFT_URL,
				draftClaimUrl: CLAIM_URL,
				nonce: 'n0nce',
			})
		);

		type(root(), 'name', 'Teszt Anna');
		root().querySelector('button[value="merge"]').click();
		await settle();

		expect(claimCalls()).toHaveLength(1);
		expect(JSON.parse(claimCalls()[0][1].body)).toEqual({ choice: 'merge' });
		expect(claimCalls()[0][1].headers['X-WP-Nonce']).toBe('n0nce');

		window.dispatchEvent(new Event('pagehide'));
		type(root(), 'name', 'Teszt Anna Mária');
		await new Promise((resolve) => setTimeout(resolve, 3100));
		window.dispatchEvent(new Event('pagehide'));

		expect(draftCalls()).toHaveLength(0);
	});

	test('takes the choice from a script outside the form (a modal), and sends nothing by itself', async () => {
		document.body.innerHTML = form(text('name'), {
			draftUrl: DRAFT_URL,
			draftClaimUrl: CLAIM_URL,
			draftClaimChanges: 2,
		});
		let actions;
		jest.isolateModules(() => {
			require('../../src/Forms/Blocks/form/view');
			actions = require('@wordpress/interactivity').store('kalaka/form').actions;
		});
		while (root().querySelector('[data-kalaka-send]').disabled) {
			await settle();
		}
		await settle();
		expect(claimCalls()).toHaveLength(0);

		type(root(), 'name', 'Teszt Anna');
		const answer = await actions.resolveDraftClaim('keep_link');

		expect(answer.ok).toBe(true);
		expect(claimCalls()).toHaveLength(1);
		expect(JSON.parse(claimCalls()[0][1].body)).toEqual({ choice: 'keep_link' });
		window.dispatchEvent(new Event('pagehide'));
		expect(draftCalls()).toHaveLength(0);
	});

	test('sends no choice without a claim route', async () => {
		await load(form(text('name') + choice('keep_link'), { draftUrl: DRAFT_URL }));

		root().querySelector('button[value="keep_link"]').click();
		await settle();

		expect(claimCalls()).toHaveLength(0);
	});

	test('stops once a save is refused as from before a claim (409)', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_version_conflict', message: 'x' })
		);
		await load(form(text('name'), { draftUrl: DRAFT_URL }));

		type(root(), 'name', 'Teszt Anna');
		window.dispatchEvent(new Event('pagehide'));
		await settle();
		expect(draftCalls()).toHaveLength(1);

		type(root(), 'name', 'Teszt Anna Mária');
		await new Promise((resolve) => setTimeout(resolve, 3100));
		window.dispatchEvent(new Event('pagehide'));

		expect(draftCalls()).toHaveLength(1);
	});

	test('keeps saving after another 409, an edited form (kalaka_form_outdated)', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_form_outdated', message: 'x' })
		);
		await load(form(text('name'), { draftUrl: DRAFT_URL }));

		type(root(), 'name', 'Teszt Anna');
		window.dispatchEvent(new Event('pagehide'));
		await settle();
		expect(draftCalls()).toHaveLength(1);

		type(root(), 'name', 'Teszt Anna Mária');
		window.dispatchEvent(new Event('pagehide'));

		expect(draftCalls()).toHaveLength(2);
	});
});

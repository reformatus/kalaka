import { form, load, respond, settle, submit, text, type } from './helpers';

const DRAFT_URL = 'http://example.test/wp-json/kalaka-portal/v1/forms/12/draft';

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
		});
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

	test('is not there without a draft route (a guest)', async () => {
		await load(form(text('name')));
		jest.useFakeTimers();

		type(root(), 'name', 'Teszt Anna');
		jest.advanceTimersByTime(5000);

		expect(draftCalls()).toHaveLength(0);
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

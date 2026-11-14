// Owner rulings 5 and 6: answers kept in the browser (a tick on an option that stops being offered comes
// back with the option) and the loading state (dimmed at once, a spinner after 2 seconds).
import {
	AVAILABILITY_URL,
	checkboxes,
	choose,
	form,
	load,
	radios,
	respond,
	sentBody,
	settle,
} from './helpers';

const root = () => document.querySelector('form');
const ticked = (value) =>
	root().querySelector(`input[value="${value}"]`)?.checked ?? null;

/** The re-fetch answer that re-renders `modules` with the given options. */
const offering = (key, markup) =>
	respond(200, {
		availability: {
			fields: { track: true, [key]: true },
			chains: [],
			options: {},
			triggers: ['track'],
		},
		parts: { [key]: markup },
	});

describe('a ticked option that stops being offered', () => {
	test('is not sent while it is gone, and is ticked again when it is offered again', async () => {
		await load(
			form(radios('track', ['ifi', 'pro']) + checkboxes('modules', ['a', 'b', 'c']), {
				triggers: ['track'],
			})
		);
		globalThis.fetch = jest.fn();
		choose(root(), 'modules[]', 'b');
		choose(root(), 'modules[]', 'c');

		globalThis.fetch.mockResolvedValueOnce(offering('modules', checkboxes('modules', ['a', 'c'])));
		choose(root(), 'track', 'pro');
		await settle();
		expect(ticked('b')).toBeNull();
		expect(ticked('c')).toBe(true);
		expect(sentBody(0).answers.modules).toEqual(['b', 'c']);

		globalThis.fetch.mockResolvedValueOnce(
			offering('modules', checkboxes('modules', ['a', 'b', 'c']))
		);
		choose(root(), 'track', 'ifi');
		await settle();
		expect(sentBody(1).answers.modules).toEqual(['c']);
		expect(ticked('b')).toBe(true);
		expect(ticked('c')).toBe(true);
		expect(ticked('a')).toBe(false);
	});

	test('a single choice comes back unless another option was chosen meanwhile', async () => {
		await load(
			form(radios('track', ['ifi', 'pro']) + radios('size', ['s', 'm', 'l']), {
				triggers: ['track'],
			})
		);
		globalThis.fetch = jest.fn();
		choose(root(), 'size', 'l');

		globalThis.fetch.mockResolvedValueOnce(offering('size', radios('size', ['s', 'm'])));
		choose(root(), 'track', 'pro');
		await settle();
		expect(ticked('l')).toBeNull();
		expect(ticked('s')).toBe(false);

		globalThis.fetch.mockResolvedValueOnce(offering('size', radios('size', ['s', 'm', 'l'])));
		choose(root(), 'track', 'ifi');
		await settle();
		expect(ticked('l')).toBe(true);
	});
});

describe('the loading state', () => {
	afterEach(() => {
		jest.useRealTimers();
	});

	test('dims the form at once and shows the spinner after 2 seconds, until the request ends', async () => {
		await load(form(radios('track', ['ifi', 'pro']), { triggers: ['track'] }));
		let finish;
		globalThis.fetch = jest.fn(
			() =>
				new Promise((resolve) => {
					finish = resolve;
				})
		);
		jest.useFakeTimers();
		const spinner = root().querySelector('[data-kalaka-spinner]');

		choose(root(), 'track', 'pro');
		await Promise.resolve();
		expect(globalThis.fetch.mock.calls[0][0]).toBe(AVAILABILITY_URL);
		expect(root().getAttribute('aria-busy')).toBe('true');
		expect(spinner.hidden).toBe(true);

		jest.advanceTimersByTime(1999);
		expect(spinner.hidden).toBe(true);
		jest.advanceTimersByTime(1);
		expect(spinner.hidden).toBe(false);
		expect(root().hasAttribute('data-kalaka-slow')).toBe(true);
		const status = root().querySelector('[role="status"]');
		expect(status.hidden).toBe(false);
		expect(status.textContent).toBe('Betöltés…');

		finish(
			respond(200, {
				availability: { fields: {}, chains: [], options: {}, triggers: ['track'] },
				parts: {},
			})
		);
		for (let i = 0; i < 10; i++) {
			await Promise.resolve();
		}
		expect(root().hasAttribute('aria-busy')).toBe(false);
		expect(spinner.hidden).toBe(true);
		expect(root().hasAttribute('data-kalaka-slow')).toBe(false);
		expect(root().querySelector('[role="status"]').textContent).toBe('');
	});

	test('a request shorter than 2 seconds never shows the spinner', async () => {
		await load(form(radios('track', ['ifi', 'pro']), { triggers: ['track'] }));
		let finish;
		globalThis.fetch = jest.fn(
			() =>
				new Promise((resolve) => {
					finish = resolve;
				})
		);
		jest.useFakeTimers();
		const spinner = root().querySelector('[data-kalaka-spinner]');

		choose(root(), 'track', 'pro');
		jest.advanceTimersByTime(1500);
		finish(respond(500, undefined));
		for (let i = 0; i < 10; i++) {
			await Promise.resolve();
		}
		jest.advanceTimersByTime(5000);

		expect(spinner.hidden).toBe(true);
		expect(root().hasAttribute('aria-busy')).toBe(false);
	});
});

// (#167, MP-4, MP-9, MP-11, MP-12) multi-page forms: navigation, per-page errors, conditional pages,
// terminal pages, the browser's Back button and errors after a submit.
import {
	AVAILABILITY_URL,
	checkboxes,
	choose,
	form,
	load,
	page,
	radios,
	respond,
	sentBody,
	settle,
	submit,
	text,
	type,
} from './helpers';

const REQUIRED = 'Ezt a mezőt ki kell töltened.';

const off = (markup) => markup.replace(/<input /g, '<input disabled ');

// Page 0: the basics and how the person takes part (the trigger); page 1 only for camp, page 2 only for
// day visitors, page 3 the terminal page for those under the age limit, page 4 for everyone.
const markup = form(
	page(0, text('name') + radios('mode', ['camp', 'day', 'young']), {
		title: 'Alapadatok',
	}) +
		page(1, off(text('room')), { title: 'Szállás' }) +
		page(2, off(text('arrival'))) +
		page(3, '<p id="info">Csak 14 éves kortól.</p>', {
			title: 'Sajnáljuk',
			terminal: true,
		}) +
		page(4, checkboxes('programs', ['a', 'b']), { title: 'Programok' }),
	{ triggers: ['mode'], pages: [true, false, false, false, true] }
);

const root = () => document.querySelector('form');
const pageEl = (number) =>
	document.querySelector(`[data-kalaka-page="${number}"]`);
const visible = () =>
	[...document.querySelectorAll('[data-kalaka-page]')]
		.filter((element) => !element.hidden)
		.map((element) => Number(element.getAttribute('data-kalaka-page')));
const backButton = () => document.querySelector('[data-kalaka-back]');
const nextButton = () => document.querySelector('[data-kalaka-next]');
const sendButton = () => document.querySelector('[data-kalaka-send]');
const alert = () => document.querySelector('[role="alert"]');
const slot = (key) =>
	document.querySelector(`[data-kalaka-field="${key}"] .kalaka-field__error`);

const PAGES = {
	camp: [true, true, false, false, true],
	day: [true, false, true, false, true],
	young: [true, false, false, true, true],
};

/** The re-fetch route's answer for the answers sent: the pages for `mode`, and the errors of the page asked for. */
function availability(body) {
	const mode = body.answers.mode;
	const pages = PAGES[mode] ?? [true, false, false, false, true];
	const errors = {};
	if (body.page === 0 && !body.answers.name) {
		errors.name = REQUIRED;
	}
	if (body.page === 0 && mode === undefined) {
		errors.mode = REQUIRED;
	}

	return {
		availability: {
			fields: {
				name: true,
				mode: true,
				room: pages[1],
				arrival: pages[2],
				programs: true,
			},
			chains: [],
			options: {},
			triggers: ['mode'],
			pages,
			errors,
		},
		parts: {},
	};
}

let submitAnswer;

beforeEach(() => {
	submitAnswer = respond(200, { repeated: false, after_submit: '<p id="thanks">ok</p>' });
	globalThis.fetch = jest.fn(async (url, options) => {
		const body = JSON.parse(options.body);
		return url === AVAILABILITY_URL
			? respond(200, availability(body))
			: submitAnswer;
	});
	Element.prototype.scrollIntoView = jest.fn();
	window.history.replaceState(null, '');
});

async function fillFirstPage(mode) {
	type(root(), 'name', 'Minta Anna');
	choose(root(), 'mode', mode);
	await settle();
}

async function clickNext() {
	nextButton().click();
	await settle();
}

describe('a multi-page form', () => {
	test('starts on its first page, with Tovább only', async () => {
		await load(markup);

		expect(visible()).toEqual([0]);
		expect(nextButton().hidden).toBe(false);
		expect(nextButton().disabled).toBe(false);
		expect(sendButton().hidden).toBe(true);
		expect(backButton().hidden).toBe(true);
		expect(window.history.state).toMatchObject({ kalakaForm: 12, kalakaPage: 0 });
	});

	test('Tovább checks the page with a re-fetch that sends it, and stays with its errors shown', async () => {
		await load(markup);

		await clickNext();

		const sent = sentBody(globalThis.fetch.mock.calls.length - 1);
		expect(sent.page).toBe(0);
		expect(visible()).toEqual([0]);
		expect(slot('name').textContent).toBe(REQUIRED);
		expect(slot('mode').textContent).toBe(REQUIRED);
		expect(document.activeElement).toBe(root().querySelector('[name="name"]'));
	});

	test('a re-fetch on a change sends no page, so no error shows while typing', async () => {
		await load(markup);

		choose(root(), 'mode', 'camp');
		await settle();

		expect(sentBody(0).page).toBeUndefined();
		expect(slot('name').hidden).toBe(true);
	});

	test('Tovább with no errors moves to the next page that shows, scrolls to the form and focuses its title', async () => {
		await load(markup);
		await fillFirstPage('camp');

		await clickNext();

		expect(visible()).toEqual([1]);
		expect(document.activeElement).toBe(pageEl(1).querySelector('h2'));
		expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
		expect(window.history.state).toMatchObject({ kalakaForm: 12, kalakaPage: 1 });
		expect(backButton().hidden).toBe(false);
		expect(nextButton().hidden).toBe(false);
		expect(sendButton().hidden).toBe(true);
	});

	test('a page with no title moves the focus to the form', async () => {
		await load(markup);
		await fillFirstPage('day');

		await clickNext();

		expect(visible()).toEqual([2]);
		expect(document.activeElement).toBe(root());
	});

	test('a conditional page that does not show is skipped by Tovább and by Vissza', async () => {
		await load(markup);
		await fillFirstPage('day');
		await clickNext();
		expect(visible()).toEqual([2]);

		await clickNext();
		expect(visible()).toEqual([4]);
		expect(sendButton().hidden).toBe(false);
		expect(nextButton().hidden).toBe(true);

		backButton().click();
		await settle();
		expect(visible()).toEqual([2]);
		backButton().click();
		await settle();
		expect(visible()).toEqual([0]);
	});

	test("the browser's Back button returns to the previous page of the form", async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		expect(visible()).toEqual([1]);

		window.history.back();
		await settle();

		expect(visible()).toEqual([0]);
		expect(nextButton().hidden).toBe(false);
	});

	test('Back then Forward adds no entry: the next Back goes back again', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		const length = window.history.length;

		window.history.back();
		await settle();
		expect(visible()).toEqual([0]);
		window.history.forward();
		await settle();
		await settle();
		expect(visible()).toEqual([1]);
		expect(window.history.length).toBe(length);
		expect(window.history.state).toMatchObject({ kalakaPage: 1 });

		window.history.back();
		await settle();
		expect(visible()).toEqual([0]);
	});

	test('Forward with errors on the page stays and puts the browser back on its entry', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		window.history.back();
		await settle();
		type(root(), 'name', '');

		window.history.forward();
		await settle();
		await settle();

		expect(visible()).toEqual([0]);
		expect(slot('name').textContent).toBe(REQUIRED);
		expect(window.history.state).toMatchObject({ kalakaPage: 0 });
	});

	test('after a submit the browser is back on the entry the form started from, so one Back leaves', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		await clickNext();
		expect(window.history.state).toMatchObject({ kalakaPage: 4 });

		submit(root());
		await settle();
		await settle();

		expect(root()).toBeNull();
		expect(window.history.state).toMatchObject({ kalakaPage: 0 });
		const fetchesAfterSubmit = globalThis.fetch.mock.calls.length;
		// The later entries are only Forward entries now, and the form ignores them.
		window.history.forward();
		await settle();
		await settle();
		expect(document.getElementById('thanks')).not.toBeNull();
		expect(root()).toBeNull();
		expect(globalThis.fetch).toHaveBeenCalledTimes(fetchesAfterSubmit);
	});

	test('a popstate of another form or of the host page is ignored', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();

		window.dispatchEvent(new PopStateEvent('popstate', { state: { other: true } }));
		await settle();

		expect(visible()).toEqual([1]);
	});

	test('a terminal page shows its content with Vissza and neither Tovább nor Küldés', async () => {
		await load(markup);
		await fillFirstPage('young');

		await clickNext();

		expect(visible()).toEqual([3]);
		expect(nextButton().hidden).toBe(true);
		expect(sendButton().hidden).toBe(true);
		expect(backButton().hidden).toBe(false);
	});

	test('the answers of a page that stops showing stay in the browser but are not sent', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		type(root(), 'room', 'Kati');
		backButton().click();
		await settle();

		choose(root(), 'mode', 'day');
		await settle();
		await clickNext();
		await clickNext();
		submit(root());
		await settle();

		const sent = sentBody(globalThis.fetch.mock.calls.length - 1);
		expect(sent.answers.room).toBeUndefined();
		expect(root()).toBeNull();
		// Kept while the page was open: the control kept what was typed.
	});

	test('the answers of a page that shows again come back', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		type(root(), 'room', 'Kati');
		backButton().click();
		await settle();
		choose(root(), 'mode', 'day');
		await settle();
		choose(root(), 'mode', 'camp');
		await settle();

		await clickNext();

		expect(visible()).toEqual([1]);
		expect(root().querySelector('[name="room"]').value).toBe('Kati');
	});

	test('Küldés sends every page that shows; a field in error sends the form to the earliest page holding one', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		await clickNext();
		expect(visible()).toEqual([4]);
		submitAnswer = respond(400, {
			code: 'kalaka_invalid_answers',
			message: 'Javítsd a megjelölt mezőket.',
			data: { status: 400, fields: { room: REQUIRED } },
		});

		submit(root());
		await settle();

		expect(visible()).toEqual([1]);
		expect(slot('room').textContent).toBe(REQUIRED);
		expect(document.activeElement).toBe(root().querySelector('[name="room"]'));
		const sent = sentBody(globalThis.fetch.mock.calls.length - 1);
		expect(Object.keys(sent.answers).sort()).toEqual(['mode', 'name', 'programs', 'room']);
	});

	test('a submit refused because a terminal page shows goes to that page, with no Vissza', async () => {
		await load(markup);
		await fillFirstPage('camp');
		await clickNext();
		await clickNext();
		// The answer changed where the page could not see it: the server now shows the terminal page.
		PAGES.camp = PAGES.young;
		submitAnswer = respond(403, {
			code: 'kalaka_form_terminal',
			message: 'Ez az űrlap most nem küldhető be.',
			data: { status: 403 },
		});
		try {
			submit(root());
			await settle();
			await settle();

			expect(visible()).toEqual([3]);
			expect(backButton().hidden).toBe(true);
			expect(sendButton().hidden).toBe(true);
			expect(nextButton().hidden).toBe(true);
		} finally {
			PAGES.camp = [true, true, false, false, true];
		}
	});

	test('Enter in a field of a page before the last moves on as Tovább does', async () => {
		await load(markup);
		await fillFirstPage('camp');

		submit(root());
		await settle();

		expect(visible()).toEqual([1]);
		expect(globalThis.fetch.mock.calls.every(([url]) => url === AVAILABILITY_URL)).toBe(true);
	});

	test('a page the server withheld from the markup is skipped', async () => {
		await load(
			form(
				page(0, text('name') + radios('mode', ['camp'])) + page(4, text('note')),
				{ triggers: ['mode'], pages: [true, false, false, false, true] }
			)
		);
		await fillFirstPage('camp');

		await clickNext();

		expect(visible()).toEqual([4]);
	});

	test('a click on Tovább while the form is out of date re-runs the re-fetch and does not move', async () => {
		await load(markup);
		globalThis.fetch.mockRejectedValueOnce(new TypeError('offline'));
		choose(root(), 'mode', 'camp');
		await settle();
		expect(alert().textContent).not.toBe('');
		type(root(), 'name', 'Minta Anna');

		await clickNext();

		expect(visible()).toEqual([0]);
		expect(alert().textContent).toBe('');
		expect(sentBody(globalThis.fetch.mock.calls.length - 1).page).toBeUndefined();
	});

	test('a click on Tovább while a re-fetch runs: its page check takes the place of the follow-up', async () => {
		await load(markup);
		type(root(), 'name', 'Minta Anna');
		const answers = [];
		let running = 0;
		let most = 0;
		const real = globalThis.fetch.getMockImplementation();
		globalThis.fetch.mockImplementation(async (url, options) => {
			running++;
			most = Math.max(most, running);
			await new Promise((resolve) => setTimeout(resolve, 40));
			running--;
			answers.push(JSON.parse(options.body));
			return real(url, options);
		});

		choose(root(), 'mode', 'camp');
		nextButton().click();
		nextButton().click();
		await settle();
		await settle();
		await settle();
		await settle();

		expect(answers).toHaveLength(2);
		expect(answers[0].page).toBeUndefined();
		expect(answers[1].page).toBe(0);
		expect(most).toBe(1);
		expect(visible()).toEqual([1]);
	});
});

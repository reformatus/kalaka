/**
 * The congregation field on the form page (`Picker/Blocks/congregation-field/view.js`): the roots first, a node
 * with children opens, a leaf is picked into the hidden input (which fires `change` and is what the form
 * reads), a search lists matches with their paths, and an answer that arrives late never overwrites a newer
 * one. The directory route is a fake `fetch`.
 */
import { readAnswer } from '../../../kalaka/src/Forms/Blocks/form/answers';
import {
	SEARCH_DELAY,
	TEXT,
	levelLabel,
	pathOf,
	showSearch,
	start,
} from '../../src/Support/Picker/Blocks/congregation-field/view';

const ROOT = { id: 1, name: 'Teszt Egyház', type: 'Egyház', parent: 0, leaf: false, ancestors: [] };
const OTHER = { id: 2, name: 'Másik Közösség', type: 'Közösség', parent: 0, leaf: true, ancestors: [] };
const DISTRICT = {
	id: 3,
	name: 'Északi Teszt Egyházkerület',
	type: 'Egyházkerület',
	parent: 1,
	leaf: false,
	ancestors: [{ id: 1, name: 'Teszt Egyház', type: 'Egyház' }],
};
const CONGREGATION = {
	id: 5,
	name: 'Óbudai Teszt Gyülekezet',
	type: 'Gyülekezet',
	parent: 3,
	leaf: true,
	ancestors: [
		{ id: 1, name: 'Teszt Egyház', type: 'Egyház' },
		{ id: 3, name: 'Északi Teszt Egyházkerület', type: 'Egyházkerület' },
	],
};

const CHILDREN = { 0: [ROOT, OTHER], 1: [DISTRICT], 3: [CONGREGATION] };

/** The parameters of every request made, and pending answers when a test holds them back. */
let requests;
let held;

function respond(items, pages = 1, total = items.length) {
	const headers = { 'X-WP-TotalPages': String(pages), 'X-WP-Total': String(total) };
	return {
		ok: true,
		status: 200,
		json: async () => items,
		headers: { get: (name) => headers[name] ?? null },
	};
}

function fakeFetch(url) {
	const params = Object.fromEntries(new URL(url).searchParams);
	requests.push(params);
	if (held !== null) {
		return new Promise((resolve) => held.push({ params, resolve }));
	}
	if (params.search !== undefined) {
		const items = [ROOT, OTHER, DISTRICT, CONGREGATION].filter((item) =>
			item.name.toLowerCase().includes(params.search.toLowerCase())
		);
		return Promise.resolve(respond(items));
	}
	return Promise.resolve(respond(CHILDREN[params.parent] ?? []));
}

/** Lets the pending promises settle. */
const settle = async () => {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve();
	}
};

/** The field as `render.php` renders it. */
function render() {
	document.body.innerHTML =
		'<form><div class="kalaka-field" data-kalaka-field="gyulekezet" data-kalaka-congregation' +
		' data-kalaka-nodes="https://example.org/wp-json/kalaka-support/v1/nodes">' +
		'<p><label for="kalaka-field-gyulekezet">Gyülekezet</label>' +
		'<input type="search" id="kalaka-field-gyulekezet" autocomplete="off" data-kalaka-search></p>' +
		'<input type="hidden" name="gyulekezet" value="" autocomplete="off">' +
		'<p data-kalaka-trail hidden><button type="button" data-kalaka-up>Egy szinttel feljebb</button>' +
		' <span data-kalaka-path></span></p>' +
		'<p id="kalaka-field-gyulekezet-level" data-kalaka-level></p>' +
		'<ul id="kalaka-field-gyulekezet-nodes" data-kalaka-list></ul>' +
		'<p data-kalaka-message tabindex="-1" hidden></p>' +
		'<p data-kalaka-picked hidden></p>' +
		'<span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span>' +
		'</div></form>';

	return document.querySelector('[data-kalaka-congregation]');
}

const names = (field) =>
	[...field.querySelectorAll('[data-kalaka-node]')].map((button) => button.firstChild.textContent);
const button = (field, id) => field.querySelector(`[data-kalaka-node="${id}"]`);
const part = (field, name) => field.querySelector(`[data-kalaka-${name}]`);

beforeEach(() => {
	requests = [];
	held = null;
	globalThis.fetch = jest.fn(fakeFetch);
});

afterEach(() => {
	jest.useRealTimers();
});

describe('the congregation picker', () => {
	test('a level is labelled by its nodes types, and a path joins the ancestors names', () => {
		expect(levelLabel([ROOT, OTHER, { ...OTHER, id: 9 }])).toBe('Egyház, Közösség');
		expect(pathOf(CONGREGATION)).toBe('Teszt Egyház › Északi Teszt Egyházkerület');
		expect(pathOf(ROOT)).toBe('');
	});

	test('it opens with the live roots, and a node with children opens its level', async () => {
		const field = render();
		start(field);
		await settle();

		expect(requests[0]).toEqual({ parent: '0', page: '1', per_page: '100' });
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
		expect(part(field, 'level').textContent).toBe('Egyház, Közösség');
		expect(part(field, 'trail').hidden).toBe(true);
		// a node with children is no toggle; a leaf is one, not pressed
		expect(button(field, 1).hasAttribute('aria-pressed')).toBe(false);
		expect(button(field, 2).getAttribute('aria-pressed')).toBe('false');

		button(field, 1).click();
		await settle();
		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		expect(part(field, 'level').textContent).toBe('Egyházkerület');
		expect(part(field, 'trail').hidden).toBe(false);
		expect(part(field, 'path').textContent).toBe('Teszt Egyház');
		// opening picks nothing
		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '' });

		part(field, 'up').click();
		await settle();
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
		expect(part(field, 'trail').hidden).toBe(true);
	});

	test('a leaf is picked into the hidden input, which fires change for the form', async () => {
		const field = render();
		const changed = jest.fn();
		document.querySelector('form').addEventListener('change', (event) => changed(event.target.name));
		start(field);
		await settle();
		button(field, 1).click();
		await settle();
		button(field, 3).click();
		await settle();

		button(field, 5).click();

		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '5' });
		expect(changed).toHaveBeenCalledWith('gyulekezet');
		expect(part(field, 'picked').hidden).toBe(false);
		expect(part(field, 'picked').textContent).toBe(
			TEXT.picked('Óbudai Teszt Gyülekezet (Teszt Egyház › Északi Teszt Egyházkerület)')
		);
		expect(part(field, 'announce').textContent).toBe(
			TEXT.picked('Óbudai Teszt Gyülekezet (Teszt Egyház, Északi Teszt Egyházkerület)')
		);
		expect(button(field, 5).getAttribute('aria-pressed')).toBe('true');
	});

	test('a search lists the matches with their paths; a match with children opens its level', async () => {
		jest.useFakeTimers();
		const field = render();
		start(field);
		await settle();

		const search = part(field, 'search');
		search.value = ' a ';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		// one character is no search: the level stays
		expect(requests.some((params) => params.search !== undefined)).toBe(false);

		search.value = 'gyülekezet';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(requests[requests.length - 1]).toEqual({ search: 'gyülekezet', per_page: '20' });
		expect(names(field)).toEqual(['Óbudai Teszt Gyülekezet']);
		expect(field.querySelector('li').textContent).toBe(
			'Óbudai Teszt Gyülekezet (Teszt Egyház › Északi Teszt Egyházkerület)'
		);
		expect(part(field, 'announce').textContent).toBe(TEXT.found(1));

		search.value = 'kerület';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		button(field, 3).click();
		await settle();
		expect(search.value).toBe('');
		expect(names(field)).toEqual(['Óbudai Teszt Gyülekezet']);
		expect(part(field, 'path').textContent).toBe('Teszt Egyház › Északi Teszt Egyházkerület');
		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '' });
	});

	test('opening a node or the level above keeps the focus in the picker and says the level', async () => {
		const field = render();
		start(field);
		await settle();
		// the first level is said, without taking the focus from the page
		expect(part(field, 'announce').textContent).toBe('Egyház, Közösség');
		expect(document.activeElement).toBe(document.body);

		button(field, 1).focus();
		button(field, 1).click();
		await settle();
		expect(document.activeElement).toBe(button(field, 3));
		expect(part(field, 'announce').textContent).toBe('Egyházkerület');

		part(field, 'up').focus();
		part(field, 'up').click();
		await settle();
		expect(document.activeElement).toBe(button(field, 1));
		expect(part(field, 'announce').textContent).toBe('Egyház, Közösség');

		// a level with no live node: the focus goes to its message, which is said
		globalThis.fetch = jest.fn(async () => respond([]));
		button(field, 1).click();
		await settle();
		expect(document.activeElement).toBe(part(field, 'message'));
		expect(part(field, 'announce').textContent).toBe(TEXT.emptyLevel);

		// a level that fails to load: the same
		globalThis.fetch = jest.fn(async () => ({ ok: false, status: 503 }));
		part(field, 'up').click();
		await settle();
		expect(document.activeElement).toBe(part(field, 'message'));
		expect(part(field, 'message').textContent).toBe(TEXT.failed);
		expect(part(field, 'announce').textContent).toBe(TEXT.failed);
	});

	test('a pick shows below the list, so the list does not move', async () => {
		const field = render();
		start(field);
		await settle();
		const list = part(field, 'list');
		const picked = part(field, 'picked');

		button(field, 2).click();

		expect(picked.hidden).toBe(false);
		expect(list.compareDocumentPosition(picked) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
		expect(part(field, 'search').compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
		expect(button(field, 2).getAttribute('aria-pressed')).toBe('true');
		expect(button(field, 1).hasAttribute('aria-pressed')).toBe(false);
	});

	test('the count is said after every search, and a capped search says so', async () => {
		const field = render();
		start(field);
		await settle();

		await showSearch(field, 'gyülekezet');
		expect(part(field, 'announce').textContent).toBe('1 találat');
		await showSearch(field, 'egyház');
		expect(part(field, 'announce').textContent).toBe('2 találat');
		expect(part(field, 'message').hidden).toBe(true);

		const many = Array.from({ length: 20 }, (_, index) => ({ ...OTHER, id: 100 + index }));
		globalThis.fetch = jest.fn(async () => respond(many, 3, 47));
		await showSearch(field, 'teszt');
		expect(names(field)).toHaveLength(20);
		expect(part(field, 'message').hidden).toBe(false);
		expect(part(field, 'message').textContent).toBe(TEXT.capped(20));
		expect(part(field, 'announce').textContent).toBe(TEXT.capped(20));
		expect(TEXT.capped(20)).toBe('Csak az első 20 találat jelenik meg. Pontosítsd a keresést.');
	});

	test('Enter in the search box searches at once and never sends the form', async () => {
		jest.useFakeTimers();
		const field = render();
		const submitted = jest.fn();
		document.querySelector('form').addEventListener('submit', submitted);
		start(field);
		await settle();

		const search = part(field, 'search');
		search.value = 'gyülekezet';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
		search.dispatchEvent(enter);
		await settle();

		expect(enter.defaultPrevented).toBe(true);
		expect(submitted).not.toHaveBeenCalled();
		expect(names(field)).toEqual(['Óbudai Teszt Gyülekezet']);
		// the search the typing scheduled does not run again
		const count = requests.length;
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(requests.length).toBe(count);
	});

	test('no match and an empty level say so differently', async () => {
		const field = render();
		start(field);
		await settle();

		await showSearch(field, 'nincs ilyen');
		expect(part(field, 'message').hidden).toBe(false);
		expect(part(field, 'message').textContent).toBe(TEXT.noMatch);
		expect(part(field, 'announce').textContent).toBe('Nincs találat.');

		globalThis.fetch = jest.fn(async () => respond([]));
		part(field, 'up').click();
		await settle();
		expect(part(field, 'message').textContent).toBe(TEXT.emptyLevel);
	});

	test('a failed read says so, and a late answer never overwrites a newer one', async () => {
		const field = render();
		held = [];
		start(field);
		const first = showSearch(field, 'teszt');
		const second = showSearch(field, 'gyülekezet');
		// the roots and two searches are waiting; the newest answers first, the older ones after it
		held[2].resolve(respond([CONGREGATION]));
		await second;
		held[1].resolve(respond([ROOT, OTHER, DISTRICT, CONGREGATION]));
		held[0].resolve(respond([ROOT, OTHER]));
		await first;
		await settle();
		expect(names(field)).toEqual(['Óbudai Teszt Gyülekezet']);

		held = null;
		globalThis.fetch = jest.fn(async () => ({ ok: false, status: 503 }));
		await showSearch(field, 'teszt');
		expect(names(field)).toEqual([]);
		expect(part(field, 'message').textContent).toBe(TEXT.failed);
	});

	test('a name is put in as text, never as markup', async () => {
		globalThis.fetch = jest.fn(async () =>
			respond([{ ...OTHER, name: '<img src=x onerror=alert(1)>' }])
		);
		const field = render();
		start(field);
		await settle();

		expect(field.querySelector('img')).toBeNull();
		expect(names(field)).toEqual(['<img src=x onerror=alert(1)>']);
	});

	test('a field that arrives later starts when it first takes the focus', async () => {
		const field = render();
		part(field, 'search').dispatchEvent(new Event('focusin', { bubbles: true }));
		await settle();

		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
	});
});

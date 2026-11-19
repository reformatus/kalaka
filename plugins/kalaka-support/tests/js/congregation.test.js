/**
 * The congregation field on the form page (`Picker/Blocks/congregation-field/view.js`), the owner's design: a
 * combobox whose dropdown opens at the top level on focus (H3), a node with children opens its level and a
 * search inside it covers its subtree (H2), the crumbs go back up (H1), a leaf is picked into the hidden input
 * (which fires `change` and is what the form reads), a pick is changed at its level, searched everywhere, and
 * put back by Escape (H4, R2-1), a focus alone never empties it (R2-3), the × removes it and is not shown in
 * change mode (H5, R2-5), a stale value shows its line until replaced (H7), the keys follow the ARIA 1.2
 * combobox (H8), a slow list says it loads and a failed one retries (R2-8), and the pick's path describes the
 * field. The directory route is a fake `fetch`.
 */
import { readAnswer } from '../../../kalaka/src/Forms/Blocks/form/answers';
import { SEARCH_DELAY, TEXT, pathOf, start } from '../../src/Support/Picker/Blocks/congregation-field/view';

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

let failing;

function fakeFetch(url) {
	const params = Object.fromEntries(new URL(url).searchParams);
	requests.push(params);
	if (failing) {
		return Promise.resolve({ ok: false, status: 500, json: async () => ({}), headers: { get: () => null } });
	}
	if (held !== null) {
		return new Promise((resolve) => held.push({ params, resolve }));
	}
	if (params.search !== undefined) {
		const items = [ROOT, OTHER, DISTRICT, CONGREGATION].filter(
			(item) =>
				item.name.toLowerCase().includes(params.search.toLowerCase()) &&
				(params.within === undefined ||
					(item.ancestors ?? []).some((ancestor) => String(ancestor.id) === params.within))
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

/** The field as `render.php` renders it; with a current value when one is given. */
function render({ current = null, required = true } = {}) {
	const attribute = current === null ? '' : ` data-kalaka-current='${JSON.stringify(current)}'`;
	const value = current === null ? '' : String(current.id);
	const stale = current !== null && current.live === false;
	const described = [
		...(current !== null && (current.ancestors ?? []).length > 0 ? ['kalaka-field-gyulekezet-path'] : []),
		...(stale ? ['kalaka-field-gyulekezet-stale'] : []),
	].join(' ');
	document.body.innerHTML =
		'<form><div class="kalaka-field" data-kalaka-field="gyulekezet" data-kalaka-congregation' +
		` data-kalaka-nodes="https://example.org/wp-json/kalaka-support/v1/nodes"${attribute}>` +
		'<div data-kalaka-anchor><p><label for="kalaka-field-gyulekezet">Gyülekezet</label>' +
		'<span role="group" data-kalaka-crumbs hidden></span><span data-kalaka-box>' +
		'<input type="text" id="kalaka-field-gyulekezet" role="combobox" aria-expanded="false"' +
		` aria-controls="kalaka-field-gyulekezet-options" value="${current?.name ?? ''}"` +
		`${described === '' ? '' : ` aria-describedby="${described}"`} data-kalaka-search>` +
		(required ? '' : `<button type="button" data-kalaka-remove${current === null ? ' hidden' : ''}></button>`) +
		'</span></p>' +
		`<span id="kalaka-field-gyulekezet-stale" data-kalaka-stale${stale ? '' : ' hidden'}>A választott elem` +
		' már nem választható. Válassz a listából.</span>' +
		'<span class="kalaka-field__error" hidden></span>' +
		'<div data-kalaka-popup hidden><span data-kalaka-message hidden></span>' +
		'<div role="listbox" id="kalaka-field-gyulekezet-options" data-kalaka-list></div></div></div>' +
		`<input type="hidden" name="gyulekezet" value="${value}" autocomplete="off">` +
		'<span id="kalaka-field-gyulekezet-path" class="kalaka-visually-hidden" data-kalaka-described></span>' +
		'<span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span>' +
		'</div><input type="text" name="other"></form>';

	return document.querySelector('[data-kalaka-congregation]');
}

const part = (field, name) => field.querySelector(`[data-kalaka-${name}]`);
const names = (field) =>
	[...field.querySelectorAll('[role="option"] [data-kalaka-name]')].map((name) => name.textContent);
const option = (field, id) => field.querySelector(`[data-kalaka-node="${id}"]`);
const crumbs = (field) => [...field.querySelectorAll('[data-kalaka-crumb]')].map((crumb) => crumb.textContent);
const key = (field, name) =>
	part(field, 'search').dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
/** What the live region says: it is emptied first and filled 50 ms later, so a repeated text is said again. */
const said = async (field) => {
	await new Promise((resolve) => setTimeout(resolve, 60));
	return part(field, 'announce').textContent;
};

async function opened(options) {
	const field = render(options);
	start(field);
	part(field, 'search').focus();
	await settle();
	return field;
}

/** A field holding a pick, opened by a click into it (change mode). */
async function clicked(options) {
	const field = render(options);
	start(field);
	part(field, 'search').focus();
	part(field, 'search').click();
	await settle();
	return field;
}

const HELD = {
	id: 5,
	name: 'Óbudai Teszt Gyülekezet',
	ancestors: [
		{ id: 1, name: 'Teszt Egyház' },
		{ id: 3, name: 'Északi Teszt Egyházkerület' },
	],
	live: true,
};

const type = (field, text) => {
	const search = part(field, 'search');
	search.value = text;
	search.dispatchEvent(new Event('input', { bubbles: true }));
};

beforeEach(() => {
	requests = [];
	held = null;
	failing = false;
	globalThis.fetch = jest.fn(fakeFetch);
});

afterEach(() => {
	jest.useRealTimers();
});

describe('the congregation picker', () => {
	test('a path joins the ancestors names', () => {
		expect(pathOf(CONGREGATION)).toBe('Teszt Egyház › Északi Teszt Egyházkerület');
		expect(pathOf(ROOT)).toBe('');
	});

	test('focusing the empty field opens the top level and says it (H3)', async () => {
		const field = await opened();

		expect(requests[0]).toEqual({ parent: '0', page: '1', per_page: '100' });
		expect(part(field, 'search').getAttribute('aria-expanded')).toBe('true');
		expect(part(field, 'popup').hidden).toBe(false);
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
		expect(part(field, 'list').getAttribute('aria-label')).toBe(TEXT.top);
		expect(await said(field)).toBe(TEXT.level(TEXT.top, 2));
		expect(part(field, 'crumbs').hidden).toBe(true);
		// a node with children carries the sign and the word; a leaf neither
		expect(option(field, 1).querySelector('[data-kalaka-sign]')).not.toBeNull();
		expect(option(field, 1).textContent).toContain(TEXT.opens);
		expect(option(field, 2).querySelector('[data-kalaka-sign]')).toBeNull();
	});

	test('a node with children opens its level, picks nothing, and the crumbs go back up (H1)', async () => {
		const field = await opened();
		part(field, 'search').value = 'te';
		option(field, 1).click();
		await settle();

		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		expect(part(field, 'search').value).toBe('');
		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '' });
		expect(crumbs(field)).toEqual([TEXT.top, 'Teszt Egyház']);
		expect(await said(field)).toBe(TEXT.level('Teszt Egyház', 1));

		option(field, 3).click();
		await settle();
		expect(crumbs(field)).toEqual([TEXT.top, 'Teszt Egyház', 'Északi Teszt Egyházkerület']);

		field.querySelector('[data-kalaka-crumb="1"]').click();
		await settle();
		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		// the node it came from is the active option
		expect(part(field, 'search').getAttribute('aria-activedescendant')).toBe(option(field, 3).id);
		field.querySelector('[data-kalaka-crumb="0"]').click();
		await settle();
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
		expect(document.activeElement).toBe(part(field, 'search'));
	});

	test('a search inside an opened node covers its subtree (H2), with paths below the level', async () => {
		jest.useFakeTimers();
		const field = await opened();
		option(field, 1).click();
		await settle();
		const search = part(field, 'search');
		search.value = 'óbudai';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();

		expect(requests.at(-1)).toEqual({ search: 'óbudai', per_page: '20', within: '1' });
		expect(names(field)).toEqual(['Óbudai Teszt Gyülekezet']);
		expect(part(field, 'path').textContent).toBe('Északi Teszt Egyházkerület');
		expect(part(field, 'list').getAttribute('aria-label')).toBe(TEXT.results);

		search.value = 'másik';
		search.dispatchEvent(new Event('input', { bubbles: true }));
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(names(field)).toEqual([]);
		expect(part(field, 'message').textContent).toBe(TEXT.noMatchIn('Teszt Egyház'));
	});

	test('typing into a closed field that shows its pick: the old list is gone and not clickable before the search (C2-1)', async () => {
		jest.useFakeTimers();
		const field = await opened();
		option(field, 1).click();
		await settle();
		option(field, 3).click();
		await settle();
		option(field, 5).click();
		expect(part(field, 'popup').hidden).toBe(true);

		const hidden = field.querySelector('input[type="hidden"]');
		const search = part(field, 'search');
		search.value = `${search.value}xy`;
		search.dispatchEvent(new Event('input', { bubbles: true }));

		expect(part(field, 'popup').hidden).toBe(false);
		expect(names(field)).toEqual([]);
		field.querySelectorAll('[role="option"]').forEach((node) => node.click());
		expect(hidden.value).toBe('5');
		expect(requests.at(-1)).not.toEqual(expect.objectContaining({ search: expect.anything() }));

		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(requests.at(-1)).toEqual(expect.objectContaining({ search: 'xy' }));
	});

	test('a leaf is picked: the dropdown closes, the field shows it, its parents are the crumbs', async () => {
		const field = await opened();
		const changed = jest.fn();
		document.querySelector('form').addEventListener('change', (event) => changed(event.target.name));
		option(field, 1).click();
		await settle();
		option(field, 3).click();
		await settle();
		option(field, 5).click();

		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '5' });
		expect(changed).toHaveBeenCalledWith('gyulekezet');
		expect(part(field, 'popup').hidden).toBe(true);
		expect(part(field, 'search').getAttribute('aria-expanded')).toBe('false');
		expect(part(field, 'search').value).toBe('Óbudai Teszt Gyülekezet');
		expect(crumbs(field)).toEqual(['Teszt Egyház', 'Északi Teszt Egyházkerület']);
		expect(await said(field)).toBe(TEXT.picked('Óbudai Teszt Gyülekezet (Teszt Egyház, Északi Teszt Egyházkerület)'));
		expect(document.activeElement).toBe(part(field, 'search'));
	});

	test('a focus alone leaves a held pick as it is: shown, closed, its path the description (R2-3)', async () => {
		const field = await opened({ current: HELD });

		expect(requests).toEqual([]);
		expect(part(field, 'popup').hidden).toBe(true);
		expect(part(field, 'search').value).toBe('Óbudai Teszt Gyülekezet');
		expect(part(field, 'search').getAttribute('aria-describedby')).toBe('kalaka-field-gyulekezet-path');
		expect(part(field, 'described').textContent).toBe('Teszt Egyház, Északi Teszt Egyházkerület');
	});

	test('a click opens a held pick at its level, emptied; Escape and leaving put it back (H4)', async () => {
		const field = await clicked({ current: HELD });

		expect(requests[0]).toEqual({ parent: '3', page: '1', per_page: '100' });
		expect(part(field, 'search').value).toBe('');
		expect(part(field, 'search').getAttribute('aria-activedescendant')).toBe(option(field, 5).id);
		expect(option(field, 5).hasAttribute('data-kalaka-held')).toBe(true);

		key(field, 'Escape');
		expect(part(field, 'popup').hidden).toBe(true);
		expect(part(field, 'search').value).toBe('Óbudai Teszt Gyülekezet');
		expect(await said(field)).toBe(TEXT.unchanged('Óbudai Teszt Gyülekezet'));

		key(field, 'ArrowDown');
		await settle();
		document.querySelector('[name="other"]').focus();
		expect(part(field, 'popup').hidden).toBe(true);
		expect(part(field, 'search').value).toBe('Óbudai Teszt Gyülekezet');
		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '5' });
	});

	test('the keys move, open, pick and close, and Enter never sends the form (H8)', async () => {
		const field = await opened();
		const submitted = jest.fn((event) => event.preventDefault());
		document.querySelector('form').addEventListener('submit', submitted);

		key(field, 'ArrowDown');
		expect(part(field, 'search').getAttribute('aria-activedescendant')).toBe(option(field, 1).id);
		expect(option(field, 1).getAttribute('aria-selected')).toBe('true');
		key(field, 'Enter');
		await settle();
		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		expect(part(field, 'search').getAttribute('aria-activedescendant')).toBe(option(field, 3).id);
		key(field, 'Enter');
		await settle();
		key(field, 'Enter');

		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '5' });
		expect(submitted).not.toHaveBeenCalled();
		expect(document.activeElement).toBe(part(field, 'search'));
	});

	test('the × removes the pick on an optional field (H5)', async () => {
		const current = { id: 2, name: 'Másik Közösség', ancestors: [], live: true };
		const field = render({ current, required: false });
		start(field);
		expect(part(field, 'remove').hidden).toBe(false);

		part(field, 'remove').click();

		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '' });
		expect(part(field, 'search').value).toBe('');
		expect(part(field, 'remove').hidden).toBe(true);
		expect(await said(field)).toBe(TEXT.removed);
		expect(document.activeElement).toBe(part(field, 'search'));
	});

	test('a stale value keeps its line until a new pick (H7)', async () => {
		const current = { id: 9, name: 'Régi Gyülekezet', ancestors: [{ id: 1, name: 'Teszt Egyház' }], live: false };
		const field = await opened({ current });
		// the form's error focus leaves the stale pick in sight above its line (R2-4)
		expect(part(field, 'popup').hidden).toBe(true);
		expect(part(field, 'search').value).toBe('Régi Gyülekezet');
		expect(part(field, 'stale').hidden).toBe(false);
		part(field, 'search').click();
		await settle();

		key(field, 'Escape');
		expect(part(field, 'stale').hidden).toBe(false);
		expect(part(field, 'search').value).toBe('Régi Gyülekezet');

		field.querySelector('[data-kalaka-crumb="1"]').click();
		await settle();
		field.querySelector('[data-kalaka-crumb="0"]').click();
		await settle();
		option(field, 2).click();
		expect(part(field, 'stale').hidden).toBe(true);
		// a root has no path: nothing describes the field any more
		expect(part(field, 'search').hasAttribute('aria-describedby')).toBe(false);
		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '2' });
	});

	test('a late answer never overwrites a newer one, nor reopens a closed dropdown', async () => {
		held = [];
		const field = render();
		start(field);
		part(field, 'search').focus();
		await settle();
		held[0].resolve(respond([ROOT, OTHER]));
		await settle();
		option(field, 1).click();
		await settle();
		key(field, 'Escape');
		held[1].resolve(respond([DISTRICT]));
		await settle();

		expect(part(field, 'popup').hidden).toBe(true);
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
	});

	test('a name is put in as text, never as markup', async () => {
		CHILDREN[0] = [{ ...OTHER, name: '<img src=x onerror=alert(1)>' }];
		const field = await opened();
		CHILDREN[0] = [ROOT, OTHER];

		expect(field.querySelector('img')).toBeNull();
		expect(names(field)).toEqual(['<img src=x onerror=alert(1)>']);
	});

	test('typing in change mode searches everywhere; an opened node searches within it again (R2-1)', async () => {
		jest.useFakeTimers();
		const field = await clicked({ current: HELD });
		type(field, 'másik');
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();

		expect(requests.at(-1)).toEqual({ search: 'másik', per_page: '20' });
		expect(names(field)).toEqual(['Másik Közösség']);

		field.querySelector('[data-kalaka-crumb="1"]').click();
		await settle();
		type(field, 'óbudai');
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(requests.at(-1)).toEqual({ search: 'óbudai', per_page: '20', within: '1' });
	});

	test('leaving the field drops typed text, even with its one match listed (R2-2)', async () => {
		jest.useFakeTimers();
		const field = await opened();
		type(field, 'másik');
		jest.advanceTimersByTime(SEARCH_DELAY);
		await settle();
		expect(names(field)).toEqual(['Másik Közösség']);

		document.querySelector('[name="other"]').focus();

		expect(readAnswer(field, 'gyulekezet')).toEqual({ present: true, value: '' });
		expect(part(field, 'search').value).toBe('');
		expect(part(field, 'announce').textContent).toBe('');
	});

	test('the × is not shown in change mode, and is back when it closes (R2-5)', async () => {
		const field = render({ current: HELD, required: false });
		start(field);
		expect(part(field, 'remove').hidden).toBe(false);
		part(field, 'search').focus();
		part(field, 'search').click();
		await settle();
		expect(part(field, 'remove').hidden).toBe(true);

		key(field, 'Escape');
		expect(part(field, 'remove').hidden).toBe(false);
	});

	test('a slow list says it loads and takes no press meanwhile (R2-8)', async () => {
		jest.useFakeTimers();
		held = [];
		const field = render();
		start(field);
		part(field, 'search').focus();
		held[0].resolve(respond([ROOT, OTHER]));
		await settle();
		option(field, 1).click();
		await settle();
		jest.advanceTimersByTime(250);

		expect(names(field)).toEqual([]);
		expect(part(field, 'message').textContent).toBe(TEXT.loading);
		expect(part(field, 'list').getAttribute('aria-busy')).toBe('true');
		key(field, 'Enter');
		expect(held).toHaveLength(2);

		held[1].resolve(respond([DISTRICT]));
		await settle();
		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		expect(part(field, 'message').hidden).toBe(true);
		expect(part(field, 'list').hasAttribute('aria-busy')).toBe(false);
	});

	test('a failed list offers a retry: the button, or Enter in the field (R2-8)', async () => {
		failing = true;
		const field = await opened();

		expect(part(field, 'message').textContent).toBe(TEXT.failed + TEXT.retry);
		expect(await said(field)).toBe(TEXT.failed);

		failing = false;
		part(field, 'retry').click();
		await settle();
		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);

		failing = true;
		option(field, 1).click();
		await settle();
		failing = false;
		key(field, 'Enter');
		await settle();
		expect(names(field)).toEqual(['Északi Teszt Egyházkerület']);
		expect(document.activeElement).toBe(part(field, 'search'));
	});

	test('a field that arrives later starts and opens when it first takes the focus', async () => {
		const field = render();
		part(field, 'search').dispatchEvent(new Event('focusin', { bubbles: true }));
		await settle();

		expect(names(field)).toEqual(['Teszt Egyház', 'Másik Közösség']);
	});
});

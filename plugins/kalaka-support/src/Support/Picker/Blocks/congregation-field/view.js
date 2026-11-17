/**
 * The congregation field on the form page (`render.php`). [UNAPPROVED N6] (UI) A minimal working picker until
 * UX's prototype is approved: a search box and a list of buttons, one per node of the directory, read from
 * the public route (`data-kalaka-nodes`), which lists live nodes only.
 *
 * - Without a search the list is one level of the tree: the live roots first, then the children of the node
 *   opened, with the nodes' types above it (the step is labelled by its children's type: there is no level
 *   schema) and a Back button with the path above it. A level is read whole, page by page.
 * - With a search of at least two characters the list is the matches, each with its path; clearing the
 *   search goes back to the level. Enter in the search box searches at once and never sends the form.
 * - A button of a terminal leaf picks it: its id goes into the hidden input named by the field's key, which
 *   fires `change` like any answer, and the picked node shows above the list. A button of a node with
 *   children opens it instead. Only a leaf is ever an answer (owner ruling S28-1).
 *
 * A response that arrives after a newer request was made is dropped, so a slow search never overwrites a
 * later one. Names are put in as text, never as markup. It holds no rule: the server checks the answer at
 * submit. A field that arrives later (a branch the server sends on a re-fetch) is started when anything in
 * it first takes the focus.
 */

/** [UNAPPROVED N5] (Text) The texts the picker shows. */
export const TEXT = {
	picked: (name) => `Kiválasztva: ${name}`,
	noMatch: 'Nincs találat.',
	emptyLevel: 'Itt nincs választható elem.',
	failed: 'Nem sikerült betölteni a listát. Próbáld újra később.',
	found: (count) => (count === 0 ? 'Nincs találat.' : `${count} találat`),
};

/** What the path's names are joined with: a sign on the screen, a comma for a screen reader. */
const SHOWN = ' › ';
const READ = ', ';

/** The fewest characters a search has (the route's `minLength`). */
export const SEARCH_MIN = 2;

/** How long typing must pause, in milliseconds, before a search is sent. */
export const SEARCH_DELAY = 300;

/** How many matches a search lists: a longer search narrows them. */
export const SEARCH_PAGE = 20;

/** How many children are read per request; a level is read whole. */
const LEVEL_PAGE = 100;

const fields = new WeakMap();

/** The types of the listed nodes, each once, in the order they first occur: the label of a level. */
export function levelLabel(items) {
	return [...new Set(items.map((item) => item.type))].join(', ');
}

/** A node's path: its ancestors' names, root first, joined by the separator. */
export function pathOf(item, separator = SHOWN) {
	return (item.ancestors ?? []).map((ancestor) => ancestor.name).join(separator);
}

/** What a picked node is shown, or read, as: its name, and its path in brackets. */
function pickedText(item, separator) {
	const path = pathOf(item, separator);

	return TEXT.picked(path === '' ? item.name : `${item.name} (${path})`);
}

function part(field, name) {
	return field.querySelector(`[data-kalaka-${name}]`);
}

function hiddenOf(field) {
	return field.querySelector('input[type="hidden"]');
}

async function read(field, params) {
	const url = new URL(field.getAttribute('data-kalaka-nodes'), document.baseURI);
	for (const [name, value] of Object.entries(params)) {
		url.searchParams.set(name, String(value));
	}
	const response = await fetch(url, { headers: { Accept: 'application/json' } });
	if (!response.ok) {
		throw new Error(`The directory answered ${response.status}.`);
	}

	return {
		items: await response.json(),
		pages: Number(response.headers.get('X-WP-TotalPages') ?? '1'),
	};
}

/** Every live child of the node (0: the roots), page by page. */
async function readLevel(field, parent) {
	const items = [];
	for (let page = 1; ; page++) {
		const answer = await read(field, { parent, page, per_page: LEVEL_PAGE });
		items.push(...answer.items);
		if (page >= answer.pages || answer.items.length === 0) {
			return items;
		}
	}
}

function say(field, text) {
	part(field, 'announce').textContent = text;
}

function showMessage(field, text) {
	const message = part(field, 'message');
	message.textContent = text ?? '';
	message.hidden = text === null;
}

function showList(field, items, withPaths) {
	const state = fields.get(field);
	const list = part(field, 'list');
	const picked = hiddenOf(field).value;
	list.replaceChildren(
		...items.map((item) => {
			const row = document.createElement('li');
			const button = document.createElement('button');
			button.type = 'button';
			button.setAttribute('data-kalaka-node', String(item.id));
			button.textContent = item.name;
			if (item.leaf) {
				button.setAttribute('aria-pressed', String(String(item.id) === picked));
			} else {
				// A node with children opens; the sign is only for the eye.
				const sign = document.createElement('span');
				sign.setAttribute('aria-hidden', 'true');
				sign.textContent = ' ›';
				button.append(sign);
			}
			row.append(button);
			const path = withPaths ? pathOf(item) : '';
			if (path !== '') {
				row.append(` (${path})`);
			}
			return row;
		})
	);
	state.items = new Map(items.map((item) => [String(item.id), item]));
	part(field, 'level').textContent = levelLabel(items);
	showMessage(field, items.length > 0 ? null : withPaths ? TEXT.noMatch : TEXT.emptyLevel);
}

function showTrail(field) {
	const { trail } = fields.get(field);
	part(field, 'trail').hidden = trail.length === 0;
	part(field, 'path').textContent = trail.map((node) => node.name).join(SHOWN);
}

/** Runs one read; its answer is shown only when no newer read was started meanwhile. */
async function load(field, work, show) {
	const state = fields.get(field);
	const request = ++state.request;
	try {
		const result = await work();
		if (request === state.request) {
			show(result);
		}
	} catch {
		if (request === state.request) {
			part(field, 'list').replaceChildren();
			part(field, 'level').textContent = '';
			showMessage(field, TEXT.failed);
		}
	}
}

/** Shows the level the trail ends at: the roots when it is empty. */
export function showLevel(field) {
	const { trail } = fields.get(field);
	showTrail(field);
	const parent = trail.length === 0 ? 0 : trail[trail.length - 1].id;

	return load(field, () => readLevel(field, parent), (items) => showList(field, items, false));
}

/** Shows the matches of a search. */
export function showSearch(field, search) {
	part(field, 'trail').hidden = true;

	return load(
		field,
		() => read(field, { search, per_page: SEARCH_PAGE }),
		({ items }) => {
			showList(field, items, true);
			say(field, TEXT.found(items.length));
		}
	);
}

/** Picks a terminal leaf: the hidden input takes its id and fires `change`, which reaches the form. */
export function pick(field, item) {
	const hidden = hiddenOf(field);
	hidden.value = String(item.id);
	hidden.dispatchEvent(new Event('change', { bubbles: true }));
	const picked = part(field, 'picked');
	picked.textContent = pickedText(item, SHOWN);
	picked.hidden = false;
	for (const button of part(field, 'list').querySelectorAll('button[aria-pressed]')) {
		button.setAttribute('aria-pressed', String(button.getAttribute('data-kalaka-node') === hidden.value));
	}
	say(field, pickedText(item, READ));
}

/** Opens a node with children: its children become the level, and the search is cleared. */
function open(field, item) {
	const state = fields.get(field);
	// A match opened from a search: the trail is its path.
	state.trail = [...(item.ancestors ?? []), item].map((node) => ({ id: node.id, name: node.name }));
	part(field, 'search').value = '';
	clearTimeout(state.timer);

	return showLevel(field);
}

/** Starts one congregation field (the element with `data-kalaka-congregation`); a second call does nothing. */
export function start(field) {
	if (fields.has(field)) {
		return;
	}
	fields.set(field, { trail: [], items: new Map(), request: 0, timer: undefined });

	const search = part(field, 'search');
	const run = () => {
		const text = search.value.trim();
		return text.length >= SEARCH_MIN ? showSearch(field, text) : showLevel(field);
	};
	search.addEventListener('input', () => {
		const state = fields.get(field);
		clearTimeout(state.timer);
		state.timer = setTimeout(run, SEARCH_DELAY);
	});
	// Enter in the search box searches at once and never sends the form.
	search.addEventListener('keydown', (event) => {
		if (event.key !== 'Enter') {
			return;
		}
		event.preventDefault();
		clearTimeout(fields.get(field).timer);
		run();
	});
	field.addEventListener('click', (event) => {
		const state = fields.get(field);
		if (event.target?.closest?.('[data-kalaka-up]')) {
			state.trail = state.trail.slice(0, -1);
			showLevel(field);
			return;
		}
		const button = event.target?.closest?.('[data-kalaka-node]');
		const item = button ? state.items.get(button.getAttribute('data-kalaka-node')) : undefined;
		if (item === undefined) {
			return;
		}
		if (item.leaf) {
			pick(field, item);
		} else {
			open(field, item);
		}
	});

	showLevel(field);
}

function startFrom(event) {
	const field = event.target?.closest?.('[data-kalaka-congregation]');
	if (field && !fields.has(field)) {
		start(field);
	}
}

for (const field of document.querySelectorAll('[data-kalaka-congregation]')) {
	start(field);
}
document.addEventListener('focusin', startFrom);

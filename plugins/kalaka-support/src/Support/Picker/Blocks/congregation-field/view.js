/**
 * The congregation field on the form page (`render.php`). The owner's design
 * (2026-11-17): one text field, an ARIA 1.2 combobox, with a dropdown listbox below it, read from the public
 * route (`data-kalaka-nodes`), which lists live nodes only. H1–H9 as the owner decided them, with the owner's
 * eight changes of 2026-11-17 night (marked „(R2-n)” below):
 *
 * - Focusing the empty field opens the dropdown with the top level (H3). Typing two characters or more lists
 *   the matches, each with its path below the level searched; fewer shows the level again.
 * - Picking an option that has children (it carries a „›”, H6) opens its children in the dropdown: the typed
 *   text is cleared and nothing goes into the field. Typing then searches the whole subtree under it (H2).
 *   A level is read whole and scrolls inside the dropdown.
 * - The breadcrumbs above the field show the path while browsing, starting at the top level; each crumb goes
 *   back to its level (H1). With the dropdown closed they show the picked leaf's parents.
 * - Picking a leaf closes the dropdown and puts the leaf's name in the field; its id goes into the hidden input
 *   named by the field's key, which fires `change` like any answer. Only a leaf is ever an answer (S28-1).
 * - A click, typing or an arrow key in a field that holds a pick opens the dropdown at the leaf's level (its
 *   parent's children), with the leaf active and the field emptied for typing (change mode); Escape, or
 *   leaving the field without a new pick, puts the old pick back (H4). A focus alone (Tab, the form's error
 *   focus, a return to the window) never empties it, so the pick stays in sight above its lines (R2-3,
 *   R2-4). Typed text in change mode searches the whole directory until a node or a crumb is opened (R2-1).
 *   A crumb clicked with the dropdown closed opens that level, searched within it.
 * - Leaving the field drops typed text, even with one match listed: only a pick is an answer (R2-2).
 * - On an optional field, the × inside the field removes the pick (H5); it is not shown in change mode (R2-5).
 * - A current value that is no longer an answer shows in the field with the line below it (H7, `render.php`);
 *   a new pick or the × removes the line.
 * - A screen reader hears the pick's path as the field's description (`-path`).
 * - Keys (H8): the arrow keys move the active option (`aria-activedescendant`), Enter on a parent opens it and
 *   on a leaf picks it, Escape closes. The focus stays in the field throughout: presses on the dropdown, the
 *   crumbs and the × never take it. Every level change is said in the polite live region.
 * - Phone (H9): the dropdown is no taller than the room between the field and the bottom of the visual
 *   viewport (the keyboard shrinks it), and scrolls inside itself; when that room is too small the page
 *   scrolls the field up first, to just below a fixed or sticky header (R2-6). While typing on a phone the
 *   crumbs may scroll out of view (R2-7).
 * - A list being read says so after a moment and takes no press; a list that failed to load offers a retry
 *   (R2-8).
 *
 * A response that arrives after a newer request was made, or after the dropdown closed, is dropped. Names are
 * put in as text, never as markup. It holds no rule: the server checks the answer at submit. A field that
 * arrives later (a branch the server sends on a re-fetch) is started when anything in it first takes the focus.
 */

/** The texts the picker shows and says, after the lektor. */
export const TEXT = {
	picked: (name) => `Kiválasztva: ${name}`,
	noMatch: 'Nincs találat.',
	noMatchIn: (name) => `Nincs találat ezen belül: ${name}.`,
	emptyLevel: 'Itt nincs választható elem.',
	failed: 'Nem sikerült betölteni a listát.',
	retry: 'Próbáld újra',
	loading: 'Betöltés…',
	found: (count) => (count === 0 ? 'Nincs találat.' : `${count} találat`),
	capped: (count) => `Csak az első ${count} találat jelenik meg. Pontosítsd a keresést.`,
	top: 'Összes',
	results: 'Találatok',
	crumb: (name) => `${name} – ugrás ide`,
	topCrumb: 'Összes – ugrás a legfelső szintre',
	opens: 'megnyitható',
	level: (name, count) => `${name}: ${count} elem.`,
	unchanged: (name) => `A választás nem változott: ${name}.`,
	removed: 'A választás törölve.',
};

/** What a path's names are joined with: a sign on the screen, a comma for a screen reader. */
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

/** The least room, in pixels, the dropdown wants below the field before the page scrolls the field up. */
const ROOM = 180;

/** How long, in milliseconds, a list is read before the dropdown says it is loading. */
const LOADING_DELAY = 200;

const fields = new WeakMap();

/** A node's path: its ancestors' names, root first, joined by the separator. */
export function pathOf(item, separator = SHOWN) {
	return (item.ancestors ?? []).map((ancestor) => ancestor.name).join(separator);
}

/** What a picked node is said as: its name, and its path in brackets. */
function pickedText(item) {
	const path = pathOf(item, READ);

	return TEXT.picked(path === '' ? item.name : `${item.name} (${path})`);
}

function part(field, name) {
	return field.querySelector(`[data-kalaka-${name}]`);
}

function hiddenOf(field) {
	return field.querySelector('input[type="hidden"]');
}

const plain = (node) => ({ id: node.id, name: node.name });

async function read(field, params) {
	const url = new URL(field.getAttribute('data-kalaka-nodes'), document.baseURI);
	for (const [name, value] of Object.entries(params)) {
		url.searchParams.set(name, String(value));
	}
	const response = await fetch(url, { headers: { Accept: 'application/json' } });
	if (!response.ok) {
		throw new Error(`The directory answered ${response.status}.`);
	}

	const items = await response.json();

	return {
		items,
		pages: Number(response.headers.get('X-WP-TotalPages') ?? '1'),
		total: Number(response.headers.get('X-WP-Total') ?? items.length),
	};
}

/** Every live child of the node (0: the roots), page by page; a level read once is kept. */
async function readLevel(field, parent) {
	const { levels } = fields.get(field);
	if (levels.has(parent)) {
		return levels.get(parent);
	}
	const items = [];
	for (let page = 1; ; page++) {
		const answer = await read(field, { parent, page, per_page: LEVEL_PAGE });
		items.push(...answer.items);
		if (page >= answer.pages || answer.items.length === 0) {
			levels.set(parent, items);
			return items;
		}
	}
}

function say(field, text) {
	const announce = part(field, 'announce');
	// the same text twice is said twice: the region is emptied first
	announce.textContent = '';
	setTimeout(() => {
		announce.textContent = text;
	}, 50);
}

/** The line at the top of the dropdown; with `retry`, a button that runs the failed read again. */
function showMessage(field, text, retry = false) {
	const message = part(field, 'message');
	const children = [];
	if (text !== null) {
		const line = document.createElement('span');
		line.textContent = text;
		children.push(line);
	}
	if (retry) {
		const button = document.createElement('button');
		button.type = 'button';
		button.setAttribute('data-kalaka-retry', '');
		button.textContent = TEXT.retry;
		children.push(button);
	}
	message.replaceChildren(...children);
	message.hidden = children.length === 0;
}

/** The id of the node whose children are listed: 0 for the top level. */
function scopeOf(state) {
	return state.path.at(-1)?.id ?? 0;
}

function scopeName(state) {
	return state.path.at(-1)?.name ?? TEXT.top;
}

/** The node whose subtree a search covers: the level browsed, or the top (0) in change mode (R2-1). */
function searchScope(state) {
	return state.everywhere ? 0 : scopeOf(state);
}

/** The breadcrumbs: the browsed path from the top while the dropdown is open, else the pick's parents. */
function drawCrumbs(field) {
	const state = fields.get(field);
	const crumbs = part(field, 'crumbs');
	const nodes = state.open
		? state.path.length === 0
			? []
			: [{ id: 0, name: TEXT.top }, ...state.path]
		: (state.pick?.ancestors ?? []);
	const children = [];
	nodes.forEach((node, index) => {
		if (index > 0) {
			const sign = document.createElement('span');
			sign.setAttribute('aria-hidden', 'true');
			sign.setAttribute('data-kalaka-crumb-sign', '');
			sign.textContent = SHOWN;
			children.push(sign);
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.setAttribute('data-kalaka-crumb', String(node.id));
		button.setAttribute('aria-label', node.id === 0 ? TEXT.topCrumb : TEXT.crumb(node.name));
		button.textContent = node.name;
		children.push(button);
	});
	crumbs.replaceChildren(...children);
	crumbs.hidden = nodes.length === 0;
}

/** The × shows on an optional field while it holds a pick, and never in change mode (R2-5). */
function drawRemove(field) {
	const state = fields.get(field);
	const remove = part(field, 'remove');
	if (remove !== null) {
		remove.hidden = state.pick === null || state.open;
	}
}

/**
 * The field's description: the shown pick's path (closed, with a pick that has parents), then the field's other
 * descriptions (H7's line, the form's error) as they stand.
 */
function describe(field) {
	const state = fields.get(field);
	const search = part(field, 'search');
	const described = part(field, 'described');
	const path = state.pick === null ? '' : pathOf(state.pick, READ);
	described.textContent = path;
	const others = (search.getAttribute('aria-describedby') ?? '')
		.split(/\s+/)
		.filter((id) => id !== '' && id !== described.id);
	const ids = !state.open && path !== '' ? [described.id, ...others] : others;
	if (ids.length === 0) {
		search.removeAttribute('aria-describedby');
	} else {
		search.setAttribute('aria-describedby', ids.join(' '));
	}
}

/**
 * How far down fixed or sticky headers (a theme's, the WordPress toolbar above it) cover the top of the viewport:
 * probed from the top, then just below each header found, so stacked headers add up.
 */
function coveredTop() {
	let covered = 0;
	for (let round = 0; round < 4; round++) {
		let next = covered;
		for (const x of [1, Math.floor(window.innerWidth / 2), window.innerWidth - 2]) {
			let element = document.elementFromPoint?.(x, covered + 1) ?? null;
			for (; element; element = element.parentElement) {
				const position = window.getComputedStyle(element).position;
				if (position === 'fixed' || position === 'sticky') {
					const rect = element.getBoundingClientRect();
					if (rect.top <= covered + 1 && rect.bottom < window.innerHeight / 2) {
						next = Math.max(next, rect.bottom);
					}
					break;
				}
			}
		}
		if (next <= covered) {
			break;
		}
		covered = next;
	}

	return covered;
}

/**
 * Fits the dropdown into the room between the field and the bottom of the visual viewport, which a phone's
 * keyboard shrinks; with too little room, and `scroll` set, the page first scrolls the field up.
 */
function fit(field, scroll = false) {
	const state = fields.get(field);
	if (!state.open) {
		return;
	}
	const popup = part(field, 'popup');
	const viewport = window.visualViewport;
	const bottom = () =>
		viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
	let room = bottom() - popup.getBoundingClientRect().top - 8;
	if (scroll && room < ROOM) {
		const top = part(field, 'search').getBoundingClientRect().top;
		window.scrollBy(0, top - (viewport ? viewport.offsetTop : 0) - coveredTop() - 8);
		room = bottom() - popup.getBoundingClientRect().top - 8;
	}
	popup.style.setProperty('--kalaka-popup-room', `${Math.max(Math.floor(room), 96)}px`);
}

/** Moves the active option (an index into the listed items; -1 for none) and shows it. */
function setActive(field, index) {
	const state = fields.get(field);
	const search = part(field, 'search');
	const options = [...part(field, 'list').querySelectorAll('[role="option"]')];
	state.active = options.length === 0 ? -1 : Math.max(-1, Math.min(index, options.length - 1));
	options.forEach((option, at) => option.setAttribute('aria-selected', String(at === state.active)));
	if (state.active === -1) {
		search.removeAttribute('aria-activedescendant');
		return;
	}
	const option = options[state.active];
	search.setAttribute('aria-activedescendant', option.id);
	option.scrollIntoView?.({ block: 'nearest' });
}

/** Lists the items as options; a search's items carry their path below the level browsed. */
function showOptions(field, items, searched) {
	const state = fields.get(field);
	const list = part(field, 'list');
	const scope = searched ? searchScope(state) : scopeOf(state);
	list.removeAttribute('aria-busy');
	list.replaceChildren(
		...items.map((item) => {
			const option = document.createElement('div');
			option.setAttribute('role', 'option');
			option.id = `${list.id}-${item.id}`;
			option.setAttribute('data-kalaka-node', String(item.id));
			option.setAttribute('aria-selected', 'false');
			if (state.pick !== null && state.pick.id === item.id) {
				option.setAttribute('data-kalaka-held', '');
			}
			const name = document.createElement('span');
			name.setAttribute('data-kalaka-name', '');
			name.textContent = item.name;
			option.append(name);
			if (!item.leaf) {
				// A node with children opens: the sign for the eye, the word for a screen reader.
				const word = document.createElement('span');
				word.className = 'kalaka-visually-hidden';
				word.textContent = `${READ}${TEXT.opens}`;
				const sign = document.createElement('span');
				sign.setAttribute('aria-hidden', 'true');
				sign.setAttribute('data-kalaka-sign', '');
				sign.textContent = '›';
				option.append(word, sign);
			}
			if (searched) {
				const ancestors = item.ancestors ?? [];
				const below = ancestors.slice(ancestors.findIndex((node) => node.id === scope) + 1);
				if (below.length > 0) {
					const path = document.createElement('span');
					path.setAttribute('data-kalaka-path', '');
					path.setAttribute('aria-hidden', 'true');
					path.textContent = pathOf({ ancestors: below });
					const read = document.createElement('span');
					read.className = 'kalaka-visually-hidden';
					read.textContent = `${READ}${pathOf({ ancestors: below }, READ)}`;
					option.append(path, read);
				}
			}
			return option;
		})
	);
	list.setAttribute('aria-label', searched ? TEXT.results : scopeName(state));
	state.items = items;
	state.active = -1;
	part(field, 'search').removeAttribute('aria-activedescendant');
	part(field, 'popup').scrollTop = 0;
	let message = null;
	if (items.length === 0) {
		if (searched) {
			message = scope === 0 ? TEXT.noMatch : TEXT.noMatchIn(scopeName(state));
		} else {
			message = TEXT.emptyLevel;
		}
	}
	showMessage(field, message);
}

/** Runs one read; its answer is shown only when no newer read was started meanwhile and the dropdown is open. */
async function load(field, work, show) {
	const state = fields.get(field);
	const request = ++state.request;
	state.loading = true;
	state.failed = null;
	clearTimeout(state.loadingTimer);
	// (R2-8) A list that takes a moment says so, and the list shown before goes: no press acts on it meanwhile.
	state.loadingTimer = setTimeout(() => {
		if (request === state.request && state.loading && state.open) {
			const list = part(field, 'list');
			list.replaceChildren();
			list.setAttribute('aria-busy', 'true');
			state.items = [];
			setActive(field, -1);
			showMessage(field, TEXT.loading);
		}
	}, LOADING_DELAY);
	try {
		const result = await work();
		if (request === state.request) {
			state.loading = false;
			clearTimeout(state.loadingTimer);
			if (state.open) {
				show(result);
			}
		}
	} catch {
		if (request === state.request) {
			state.loading = false;
			clearTimeout(state.loadingTimer);
		}
		if (request === state.request && state.open) {
			showOptions(field, [], false);
			// (R2-8) the read can run again: the button, or Enter, a click or an arrow key in the field
			state.failed = () => load(field, work, show);
			showMessage(field, TEXT.failed, true);
			say(field, TEXT.failed);
		}
	}
}

/** Runs the failed read again when the open dropdown shows a failure; whether it did. */
function retry(field) {
	const state = fields.get(field);
	if (!state.open || state.failed === null) {
		return false;
	}
	state.failed();

	return true;
}

/**
 * Shows the level the path ends at and says it. `active`: the id of the option to make active (the node the
 * level was reached from, or the held pick), or 'first'.
 */
function showLevel(field, active = null) {
	const state = fields.get(field);
	const parent = scopeOf(state);

	return load(
		field,
		() => readLevel(field, parent),
		(items) => {
			showOptions(field, items, false);
			say(field, items.length === 0 ? TEXT.emptyLevel : TEXT.level(scopeName(state), items.length));
			const at = active === 'first' ? 0 : items.findIndex((item) => item.id === active);
			if (at >= 0) {
				setActive(field, at);
			}
			fit(field, true);
		}
	);
}

/** Shows the matches of a search inside the level browsed: its whole subtree (H2), or everything at the top. */
function showSearch(field, search) {
	const state = fields.get(field);
	const scope = searchScope(state);
	const params = { search, per_page: SEARCH_PAGE };
	if (scope !== 0) {
		params.within = scope;
	}

	return load(
		field,
		() => read(field, params),
		({ items, total }) => {
			showOptions(field, items, true);
			const capped = total > items.length;
			if (capped) {
				showMessage(field, TEXT.capped(items.length));
			}
			if (items.length === 0) {
				say(field, scope === 0 ? TEXT.noMatch : TEXT.noMatchIn(scopeName(state)));
			} else {
				say(field, capped ? TEXT.capped(items.length) : TEXT.found(items.length));
			}
			fit(field, true);
		}
	);
}

/** What the field's text asks for now: a search, or the level. */
function run(field) {
	const state = fields.get(field);
	clearTimeout(state.timer);
	state.timer = undefined;
	const text = part(field, 'search').value.trim();

	return text.length >= SEARCH_MIN ? showSearch(field, text) : showLevel(field);
}

/**
 * Opens the dropdown on the path, without reading anything yet. `everywhere`: a search covers the whole
 * directory, not the path's level (change mode, R2-1).
 */
function browse(field, path, everywhere = false) {
	const state = fields.get(field);
	const wasOpen = state.open;
	state.open = true;
	state.everywhere = everywhere;
	state.path = path.map(plain);
	clearTimeout(state.timer);
	state.timer = undefined;
	part(field, 'popup').hidden = false;
	part(field, 'search').setAttribute('aria-expanded', 'true');
	drawCrumbs(field);
	drawRemove(field);
	describe(field);
	fit(field, !wasOpen);
}

/** Opens the dropdown on the path's level, with the field emptied for typing. */
export function openAt(field, path, active = null, everywhere = false) {
	browse(field, path, everywhere);
	part(field, 'search').value = '';

	return showLevel(field, active);
}

/**
 * Opens the dropdown where a focus or a key opens it: the held pick's level with the pick active (H4), else the
 * top level (H3), where `active` may make the first option active.
 */
function openHere(field, active = null) {
	const { pick } = fields.get(field);

	return pick === null ? openAt(field, [], active) : openAt(field, pick.ancestors, pick.id, true);
}

/**
 * Closes the dropdown. The field shows the held pick again (or nothing), with its parents as the crumbs; with
 * `restored`, a pick that was being changed is said to be unchanged (H4).
 */
export function close(field, restored = false) {
	const state = fields.get(field);
	if (!state.open) {
		return;
	}
	state.open = false;
	state.request++;
	state.loading = false;
	state.failed = null;
	clearTimeout(state.timer);
	clearTimeout(state.loadingTimer);
	const search = part(field, 'search');
	part(field, 'popup').hidden = true;
	search.setAttribute('aria-expanded', 'false');
	search.removeAttribute('aria-activedescendant');
	search.value = state.pick?.name ?? '';
	drawCrumbs(field);
	drawRemove(field);
	describe(field);
	if (restored && state.pick !== null) {
		say(field, TEXT.unchanged(state.pick.name));
	} else if (restored) {
		// nothing was picked: no count of a list that is gone stays in the live region
		part(field, 'announce').textContent = '';
	}
}

/** Sets the hidden input, firing `change` only when the value changes. */
function setValue(field, value) {
	const hidden = hiddenOf(field);
	if (hidden.value === value) {
		return;
	}
	hidden.value = value;
	hidden.dispatchEvent(new Event('change', { bubbles: true }));
}

/** Removes H7's line: the field no longer holds the stale pick. */
function dropStale(field) {
	const stale = part(field, 'stale');
	if (stale === null || stale.hidden) {
		return;
	}
	stale.hidden = true;
	const search = part(field, 'search');
	const ids = (search.getAttribute('aria-describedby') ?? '').split(/\s+/).filter((id) => id !== '' && id !== stale.id);
	if (ids.length === 0) {
		search.removeAttribute('aria-describedby');
	} else {
		search.setAttribute('aria-describedby', ids.join(' '));
	}
}

/** Picks a terminal leaf: the hidden input takes its id, the field its name, the crumbs its parents. */
export function pick(field, item) {
	const state = fields.get(field);
	state.pick = { id: item.id, name: item.name, ancestors: (item.ancestors ?? []).map(plain), live: true };
	setValue(field, String(item.id));
	dropStale(field);
	close(field);
	say(field, pickedText(item));
}

/**
 * Removes the pick (H5). The focus goes to the emptied field, which stays closed (UX's default, approved): the ×
 * does not open the dropdown as a focus by the registrant does (H3); typing or an arrow key opens it.
 */
function remove(field) {
	const state = fields.get(field);
	state.pick = null;
	setValue(field, '');
	dropStale(field);
	close(field);
	state.focusing = true;
	part(field, 'search').focus();
	state.focusing = false;
	part(field, 'search').value = '';
	drawCrumbs(field);
	drawRemove(field);
	describe(field);
	say(field, TEXT.removed);
}

/** Acts on an option: a leaf is picked, a node with children opens (its children become the level). */
function choose(field, item, byKey = false) {
	if (item.leaf) {
		pick(field, item);
		return;
	}
	openAt(field, [...(item.ancestors ?? []), item], byKey ? 'first' : null);
}

/** A crumb: open its level; going up, the node it was reached from is the active option. */
function climb(field, id) {
	const state = fields.get(field);
	const trail = state.open ? state.path : (state.pick?.ancestors ?? []);
	const at = id === 0 ? -1 : trail.findIndex((node) => node.id === id);
	const from = trail[at + 1]?.id ?? state.pick?.id ?? null;
	// The focus goes to the field first: the crumb it may be on is redrawn, and a focus lost to the page closes.
	const search = part(field, 'search');
	if (document.activeElement !== search) {
		state.focusing = true;
		search.focus();
		state.focusing = false;
	}
	openAt(field, trail.slice(0, at + 1), from);
}

/** Starts one congregation field (the element with `data-kalaka-congregation`); a second call does nothing. */
export function start(field) {
	if (fields.has(field)) {
		return;
	}
	let current = null;
	try {
		current = JSON.parse(field.getAttribute('data-kalaka-current') ?? 'null');
	} catch {
		current = null;
	}
	fields.set(field, {
		pick:
			current === null
				? null
				: { ...plain(current), ancestors: (current.ancestors ?? []).map(plain), live: current.live !== false },
		path: [],
		open: false,
		items: [],
		active: -1,
		request: 0,
		timer: undefined,
		levels: new Map(),
		focusing: false,
		loading: false,
		loadingTimer: undefined,
		failed: null,
		everywhere: false,
	});
	drawCrumbs(field);
	drawRemove(field);
	describe(field);

	const search = part(field, 'search');
	const state = fields.get(field);

	search.addEventListener('focus', () => {
		// (H3) a focus opens the empty field; a field showing its pick stays as it is (R2-3)
		if (!state.open && !state.focusing && state.pick === null) {
			openHere(field);
		}
	});
	search.addEventListener('click', () => {
		if (!state.open) {
			openHere(field);
		} else {
			retry(field);
		}
	});
	search.addEventListener('input', () => {
		if (!state.open) {
			// Typing into a field that shows its pick (the dropdown closed after the pick, the focus kept):
			// what was typed replaces the name, on the pick's level, searched everywhere (H4, R2-1).
			const name = state.pick?.name ?? '';
			const typed = search.value.startsWith(name) ? search.value.slice(name.length) : search.value;
			browse(field, state.pick?.ancestors ?? [], true);
			search.value = typed;
		}
		clearTimeout(state.timer);
		state.timer = setTimeout(() => run(field), SEARCH_DELAY);
	});
	search.addEventListener('keydown', (event) => {
		if (event.altKey || event.ctrlKey || event.metaKey) {
			return;
		}
		if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			if (!state.open) {
				openHere(field, 'first');
			} else if (retry(field)) {
				return;
			} else if (state.items.length > 0) {
				const step = event.key === 'ArrowDown' ? 1 : -1;
				setActive(field, state.active === -1 ? (step > 0 ? 0 : state.items.length - 1) : state.active + step);
			}
			return;
		}
		if (event.key === 'Enter') {
			// Enter never sends the form.
			event.preventDefault();
			if (event.repeat) {
				// a held Enter acts once: it never walks down the levels, nor reopens after a pick
				return;
			}
			if (!state.open) {
				openHere(field, 'first');
				return;
			}
			if (state.loading) {
				// a list still being read: Enter never acts on the list shown before
				return;
			}
			if (retry(field)) {
				return;
			}
			if (state.timer !== undefined) {
				// a search (or a return to the level) still waiting for its pause runs now
				run(field);
				return;
			}
			const item = state.active >= 0 ? state.items[state.active] : state.items.length === 1 ? state.items[0] : null;
			if (item) {
				choose(field, item, true);
			}
			return;
		}
		if (event.key === 'Escape') {
			if (state.open) {
				event.preventDefault();
				close(field, true);
			}
			return;
		}
		if (!state.open && state.pick !== null && (event.key.length === 1 || event.key === 'Backspace')) {
			// A key that types into a field showing its pick: the field empties first (H4).
			openHere(field);
		}
	});
	field.addEventListener('focusout', (event) => {
		if (state.open && !field.contains(event.relatedTarget)) {
			close(field, true);
		}
	});
	// A press on the dropdown, the crumbs, the × or the label never takes the focus from the field, so a pick
	// is not lost to H4's restore on the way.
	part(field, 'anchor').addEventListener('mousedown', (event) => {
		if (event.target !== search) {
			event.preventDefault();
		}
	});
	field.addEventListener('click', (event) => {
		const crumb = event.target?.closest?.('[data-kalaka-crumb]');
		if (crumb) {
			climb(field, Number(crumb.getAttribute('data-kalaka-crumb')));
			return;
		}
		if (event.target?.closest?.('[data-kalaka-remove]')) {
			remove(field);
			return;
		}
		if (event.target?.closest?.('[data-kalaka-retry]')) {
			retry(field);
			return;
		}
		if (state.loading) {
			// a list still being read: no press acts on the list shown before
			return;
		}
		const option = event.target?.closest?.('[data-kalaka-node]');
		const item = option
			? state.items.find((node) => String(node.id) === option.getAttribute('data-kalaka-node'))
			: undefined;
		if (item !== undefined) {
			choose(field, item);
		}
	});
	const refit = () => fit(field);
	window.visualViewport?.addEventListener('resize', refit);
	window.visualViewport?.addEventListener('scroll', refit);
	window.addEventListener('resize', refit);
}

function startFrom(event) {
	const field = event.target?.closest?.('[data-kalaka-congregation]');
	if (field && !fields.has(field)) {
		start(field);
		if (event.target === part(field, 'search') && fields.get(field).pick === null) {
			openHere(field);
		}
	}
}

for (const field of document.querySelectorAll('[data-kalaka-congregation]')) {
	start(field);
}
document.addEventListener('focusin', startFrom);

/**
 * The script of the embed block (events-and-forms.md, "Forms implementation" and "Conditions"): holds no
 * condition. When the answer of a trigger (a field a condition reads) changes, it re-fetches what the form
 * shows for the current answers and applies it: the controls of the fields that do not show are disabled,
 * the branch of each chain that shows is shown and the others hidden, and the re-rendered choice fields
 * replace their old markup. It reads the answers and sends them with the form's version and the page's
 * submit token, which the server renders into a hidden field and which makes a repeated send answer like
 * the first one.
 *
 * Every answer stays in the browser while the page is open (owner ruling 5): what was typed in a hidden
 * branch stays in its disabled control, and a ticked option that stops being offered is remembered and
 * ticked again when it is offered again. Only the fields that show, with their offered options, are sent.
 * Nothing is kept across a reload.
 *
 * A multi-page form ((#167, MP-4, MP-9, MP-11)) renders every page hidden; the script shows the
 * current one. Tovább re-fetches with the current page, which answers with that page's errors; with none
 * it moves to the next page that shows. Vissza moves to the previous page that shows. The browser's Back
 * button moves between the pages it went through. A terminal page has neither Tovább nor Küldés; it has
 * Vissza unless the server sent the person there by refusing a submit.
 *
 * While a re-fetch, a page check or a send runs, the form is `aria-busy` (dimmed, its fields take no
 * pointer input, by `view.css`) and its buttons `aria-disabled`, though they still take clicks; after 2
 * seconds a spinner shows over it. A failed re-fetch
 * leaves the form out of date: it cannot move on or be sent, and a click on Tovább or Küldés re-runs the
 * re-fetch.
 *
 * One request runs per form at a time (`refetch()`): changes made while a re-fetch runs are coalesced into
 * one follow-up with the latest answers, and only the answer to a request sent after the last change is
 * applied. A click on Tovább or Küldés while a re-fetch runs (the click that blurs a changed field) is not
 * lost: Tovább's page check takes the place of the follow-up, and Küldés waits for the re-fetch and sends.
 * Vissza is ignored while busy. A second Tovább, or Enter, during a page check is ignored too: the running
 * check already moves on.
 *
 * A request refused because the person's session ended (S15, `kalaka_session_ended`) shows its own message
 * with the page's link to log in again, not the system failure text. A send refused by the rate limit (S14,
 * `kalaka_rate_limited`) shows its own message, or its code while that is empty.
 *
 * The account block (S15, owner ruling 2026-11-18; `Engine\AccountMarkup`) opens WordPress's login page in a
 * window of its own (`window.open` in the click; a new tab on phones). A login or sign-up there lands on the
 * return page, which tells this page (`postMessage`, same origin only) and closes itself. The form then reads
 * its new REST nonce from WordPress (`admin-ajax.php?action=rest-nonce`, which answers only someone logged in)
 * and re-fetches with the block re-rendered: the block shows who is logged in, and an empty contact email
 * gets the account's address. Nothing typed is lost or overwritten. If no message comes (the window's opener
 * was gone), the form checks again whenever it regains focus or becomes visible while it waits for a login; a
 * login window seen closed (`window.closed`) ends the wait after one last check.
 * A send waits for that check, so nobody logged in sends as a guest. When the session ends, the login window
 * opens (or its link, where the browser blocks it) and the refused request is retried after the login. In
 * `mandatory` mode the block's error shows under it when „Tovább” or „Küldés” is refused for the account.
 */

import { store, getContext, getElement, withSyncEvent } from '@wordpress/interactivity';
import { readAnswer } from './answers';

/**
 * What each form on the page started from (its context and its submit token), with its state: the
 * re-fetch running (`run`, its promise), whether the answers changed while it ran (`dirty`), the page it
 * checks, the requests running, whether a send runs or waits, whether the form is out of date, the current
 * page, the pages that show, the remembered ticks and the timer of the spinner.
 */
const forms = new WeakMap();

/** Kaláka's own error codes: only their messages are shown as they come. */
const OWN_CODE = /^kalaka_/;

/**
 * The refusal of a request whose session ended (S15, `FormRoutes::SESSION_ENDED`): the person was logged out
 * meanwhile, say by setting the password on another device. A retry never helps; logging in again does.
 */
const SESSION_ENDED = 'kalaka_session_ended';

/**
 * The refusal of a send over the rate limit (S14, `RateLimited`): too many sends from this person or
 * address in a short time. It shows its own message, or its code while the message is empty; the answers
 * stay, and a later send may go through.
 */
const RATE_LIMITED = 'kalaka_rate_limited';

/** The account block's error in a re-fetch's `errors` (`AccountMarkup::ERROR_KEY`). */
const ACCOUNT_KEY = 'kalaka/account';

/** The return page's message (`SignUpPage::RETURN_SCRIPT`). */
const ACCOUNT_MESSAGE = 'account';

/** WordPress's nonces: 10 hexadecimal characters. */
const NONCE = /^[0-9a-f]{10}$/;

/** How long a request runs before the spinner shows. */
export const SPINNER_DELAY = 2000;

/** How long the answers rest before the autosave keeps them (S74). [UNAPPROVED N5] */
export const DRAFT_DELAY = 3000;

function plain(data) {
	return JSON.parse(JSON.stringify(data));
}

function sendButton(form) {
	return form.querySelector('button[type="submit"]');
}

function nextButton(form) {
	return form.querySelector('[data-kalaka-next]');
}

function backButton(form) {
	return form.querySelector('[data-kalaka-back]');
}

function buttonsOf(form) {
	return [sendButton(form), nextButton(form), backButton(form)].filter(
		(element) => element !== null
	);
}

function alertOf(form) {
	return form.querySelector('[data-kalaka-alert]');
}

function spinnerOf(form) {
	return form.querySelector('[data-kalaka-spinner]');
}

function statusOf(form) {
	return form.querySelector('[data-kalaka-status]');
}

function accountOf(form) {
	return form.querySelector('[data-kalaka-account]');
}

function slotOf(wrapper) {
	return wrapper.querySelector('.kalaka-field__error');
}

function controlsOf(wrapper) {
	return [...wrapper.querySelectorAll('input, select, textarea')];
}

function wrapperOf(form, key) {
	return (
		[...form.querySelectorAll('[data-kalaka-field]')].find(
			(wrapper) => wrapper.getAttribute('data-kalaka-field') === key
		) ?? null
	);
}

/** Whether the field shows: it is in no hidden branch and not all of its controls are disabled. */
function shows(wrapper) {
	const controls = controlsOf(wrapper);

	return (
		wrapper.closest('[data-kalaka-chain][hidden]') === null &&
		controls.length > 0 &&
		!controls.every((control) => control.disabled)
	);
}

/** Moves the focus to an element that is not focusable by itself, such as the message area. */
function focusOn(element, options = {}) {
	if (element === null || element === undefined) {
		return;
	}
	if (!element.hasAttribute('tabindex')) {
		element.setAttribute('tabindex', '-1');
	}
	element.focus(options);
}

function isBusy(started) {
	return started.running > 0 || started.sending;
}

/**
 * Sets the form's `aria-busy`, its buttons' `aria-disabled` and the spinner from its state: the spinner
 * shows once the form has been busy for `SPINNER_DELAY`.
 */
function reflect(form) {
	const started = forms.get(form);
	const busy = isBusy(started);
	if (busy) {
		form.setAttribute('aria-busy', 'true');
		if (started.timer === null && spinnerOf(form)?.hidden !== false) {
			started.timer = setTimeout(() => {
				started.timer = null;
				form.setAttribute('data-kalaka-slow', '');
				const spinner = spinnerOf(form);
				if (spinner !== null) {
					spinner.hidden = false;
				}
				// Inserted into the live region, so a screen reader announces it.
				const status = statusOf(form);
				if (status !== null) {
					status.textContent =
						status.getAttribute('data-kalaka-loading') ?? '';
				}
			}, SPINNER_DELAY);
		}
	} else {
		form.removeAttribute('aria-busy');
		if (started.timer !== null) {
			clearTimeout(started.timer);
			started.timer = null;
		}
		form.removeAttribute('data-kalaka-slow');
		const spinner = spinnerOf(form);
		if (spinner !== null) {
			spinner.hidden = true;
		}
		const status = statusOf(form);
		if (status !== null) {
			status.textContent = '';
		}
	}
	const blocked = busy || started.stale;
	for (const button of buttonsOf(form)) {
		if (blocked) {
			button.setAttribute('aria-disabled', 'true');
		} else {
			button.removeAttribute('aria-disabled');
		}
	}
}

/**
 * The answers of the fields that show, on every page that shows: a field whose controls are all disabled
 * does not show, and is neither sent nor validated; what was typed in it stays.
 *
 * @param {HTMLFormElement} form
 * @return {Object} The answers by key.
 */
function answersOf(form) {
	const answers = {};
	for (const wrapper of form.querySelectorAll('[data-kalaka-field]')) {
		const controls = controlsOf(wrapper);
		if (
			controls.length === 0 ||
			controls.every((control) => control.disabled)
		) {
			continue;
		}
		const key = wrapper.getAttribute('data-kalaka-field');
		const answer = readAnswer(wrapper, key);
		if (answer.present) {
			answers[key] = answer.value;
		}
	}

	return answers;
}

function isChoice(control) {
	return control.type === 'radio' || control.type === 'checkbox';
}

/**
 * Remembers the ticks of a choice field after a change (owner ruling 5): the ticked options, and the
 * remembered ones that the field does not offer now, which are ticked again when they are offered again.
 */
function remember(form, key) {
	const started = forms.get(form);
	const wrapper = wrapperOf(form, key);
	if (wrapper === null) {
		return;
	}
	const choices = controlsOf(wrapper).filter(isChoice);
	if (choices.length === 0) {
		return;
	}
	const offered = choices.map((control) => control.value);
	const ticked = choices
		.filter((control) => control.checked)
		.map((control) => control.value);
	const radio = choices[0].type === 'radio';
	const earlier = started.ticks.get(key) ?? [];
	const kept = radio
		? ticked.length > 0
			? []
			: earlier.filter((value) => !offered.includes(value))
		: earlier.filter((value) => !offered.includes(value));
	started.ticks.set(key, [...kept, ...ticked]);
}

/**
 * The re-rendered field in place of the old one, with the remembered options ticked, and the focus, when
 * the old field held it, on the same control (by name and value) in the new markup.
 */
function swap(form, key, html) {
	const started = forms.get(form);
	const old = wrapperOf(form, key);
	if (old === null) {
		return;
	}
	const answer = readAnswer(old, key);
	const chosen =
		started.ticks.get(key) ??
		[answer.present ? answer.value : []].flat();
	const active = document.activeElement;
	const focused =
		active !== null && old.contains(active)
			? { name: active.getAttribute('name'), value: active.value }
			: null;

	const template = document.createElement('template');
	template.innerHTML = html;
	const choices = [...template.content.querySelectorAll('input')].filter(
		isChoice
	);
	const radio = choices.length > 0 && choices[0].type === 'radio';
	// A radio group takes the latest remembered value it offers; checkboxes every remembered one.
	const offered = choices.map((control) => control.value);
	const last = [...chosen]
		.reverse()
		.find((value) => offered.includes(value));
	for (const control of choices) {
		control.checked = radio
			? control.value === last
			: chosen.includes(control.value);
	}
	old.replaceWith(template.content);

	if (focused !== null) {
		const fresh = wrapperOf(form, key);
		const controls = fresh === null ? [] : controlsOf(fresh);
		const same =
			controls.find(
				(control) =>
					control.getAttribute('name') === focused.name &&
					control.value === focused.value
			) ??
			controls.find(
				(control) => control.getAttribute('name') === focused.name
			);
		same?.focus();
	}
}

/**
 * Applies what the server answered: the re-rendered fields, then the fields that show, then the branch of
 * each chain that shows, then the pages that show. A chain, branch or page the server withheld from the
 * page is not there to show; nothing else depends on it.
 *
 * @param {HTMLFormElement} form
 * @param {{availability: Object, parts: Object}} answer
 */
function apply(form, { availability, parts }) {
	const started = forms.get(form);
	for (const [key, html] of Object.entries(parts ?? {})) {
		if (typeof html === 'string') {
			swap(form, key, html);
		}
	}
	for (const [key, show] of Object.entries(availability.fields ?? {})) {
		const wrapper = wrapperOf(form, key);
		for (const control of wrapper === null ? [] : controlsOf(wrapper)) {
			control.disabled = show !== true;
		}
	}
	const chains = Array.isArray(availability.chains)
		? availability.chains
		: [];
	for (const branch of form.querySelectorAll('[data-kalaka-chain]')) {
		const chain = Number(branch.getAttribute('data-kalaka-chain'));
		const index = Number(branch.getAttribute('data-kalaka-branch'));
		branch.hidden = chains[chain] !== index;
	}
	if (Array.isArray(availability.pages) && hasPages(started)) {
		started.pages = availability.pages;
		showButtons(form);
	}
}

function hasPages(started) {
	return Array.isArray(started.pages) && started.pages.length > 0;
}

function pageElement(form, number) {
	return form.querySelector(`[data-kalaka-page="${number}"]`);
}

/** Whether a page shows: the server says so and it is on the page. */
function pageShows(form, number) {
	return (
		forms.get(form).pages[number] === true &&
		pageElement(form, number) !== null
	);
}

function isTerminal(form, number) {
	return pageElement(form, number)?.hasAttribute('data-kalaka-terminal');
}

/** The next page after the given one that shows; null when there is none. */
function nextPage(form, number) {
	const { pages } = forms.get(form);
	for (let index = number + 1; index < pages.length; index++) {
		if (pageShows(form, index)) {
			return index;
		}
	}

	return null;
}

/** The previous page before the given one that shows; null when there is none. */
function previousPage(form, number) {
	for (let index = number - 1; index >= 0; index--) {
		if (pageShows(form, index)) {
			return index;
		}
	}

	return null;
}

/**
 * The buttons of the current page: Vissza when a page before it shows (not on a terminal page the server
 * sent the person to), none of Tovább and Küldés on a terminal page, Küldés on the last page that shows,
 * Tovább before it.
 */
function showButtons(form) {
	const started = forms.get(form);
	if (!hasPages(started)) {
		return;
	}
	const current = started.page;
	const terminal = isTerminal(form, current);
	const last = nextPage(form, current) === null;
	const back = backButton(form);
	if (back !== null) {
		back.hidden =
			previousPage(form, current) === null ||
			(terminal && started.refusedTo === current);
	}
	const next = nextButton(form);
	if (next !== null) {
		next.hidden = terminal || last;
	}
	sendButton(form).hidden = terminal || !last;
}

/**
 * Shows a page and hides the others. With `move`, the form's top scrolls into view and the focus moves to
 * the page's title, or to the form when the page has none.
 */
function showPage(form, number, move = true) {
	const started = forms.get(form);
	started.page = number;
	saveDraft(form);
	if (started.refusedTo !== number) {
		started.refusedTo = null;
	}
	for (const page of form.querySelectorAll('[data-kalaka-page]')) {
		page.hidden = Number(page.getAttribute('data-kalaka-page')) !== number;
	}
	showButtons(form);
	if (move) {
		form.scrollIntoView?.({ block: 'start' });
		const title = pageElement(form, number)?.querySelector(
			'[data-kalaka-page-title]'
		);
		focusOn(title ?? form, { preventScroll: true });
	}
}

/** The history entry of a page of this form. */
function entry(started, number) {
	return { ...(window.history.state ?? {}), kalakaForm: started.formId, kalakaPage: number };
}

/** Moves to a page as a step forward: the browser's Back button returns to the page before. */
function stepTo(form, number) {
	const started = forms.get(form);
	window.history.pushState(entry(started, number), '');
	started.depth++;
	showPage(form, number);
}

/**
 * Posts JSON and reads the answer: `{ok, body}`, `body` null when it is not JSON. Never throws. With a nonce
 * (a logged-in person) it is sent as `X-WP-Nonce`, so the route sees the account (S12 N3).
 */
async function post(url, data, nonce = '') {
	try {
		const headers = { 'Content-Type': 'application/json' };
		if (nonce) {
			headers['X-WP-Nonce'] = nonce;
		}
		const response = await fetch(url, {
			method: 'POST',
			headers,
			body: JSON.stringify(data),
		});
		let body = null;
		try {
			body = await response.json();
		} catch {
			body = null;
		}

		return { ok: response.ok, body };
	} catch {
		return { ok: false, body: null };
	}
}

/**
 * Shows that the session ended: the refusal's message (#191 T8), or its code if a message is missing, and the
 * page's link to log in again and come back (`loginUrl`), named by `logIn`, or by its address if that is missing.
 */
function showSessionEnded(form, message) {
	const started = forms.get(form);
	const alert = alertOf(form);
	alert.textContent =
		typeof message === 'string' && message !== '' ? message : SESSION_ENDED;
	// A form with an account block logs in again through the block's window.
	const url = started.account ? started.account.url : started.loginUrl;
	if (typeof url === 'string' && url !== '') {
		const link = document.createElement('a');
		link.href = url;
		link.textContent =
			typeof started.logIn === 'string' && started.logIn !== ''
				? started.logIn
				: url;
		if (started.account) {
			link.addEventListener('click', (event) => {
				if (openLogin(form)) {
					event.preventDefault();
				}
			});
		}
		alert.append(' ', link);
	}
}

/**
 * After a request refused because the session ended: the login window opens at once where the browser lets
 * it (it may not, this long after the click: then the message's link opens it), and the request is retried
 * after the login (`retry`).
 */
function sessionEnded(form, message, retry) {
	const started = forms.get(form);
	showSessionEnded(form, message);
	if (started.account) {
		started.retry = retry;
		openLogin(form);
	}
}

/**
 * Opens WordPress's login page for the form in a window of its own, from the account block or the ended
 * session's link. Never `noopener`: the return page tells this page through `window.opener`.
 *
 * @return {boolean} Whether the window opened.
 */
function openLogin(form) {
	const started = forms.get(form);
	const url = started?.account?.url;
	if (typeof url !== 'string' || url === '') {
		return false;
	}
	started.awaiting = true;
	const opened = window.open(
		url,
		'kalaka-account',
		'popup=yes,width=520,height=720'
	);
	// Kept to see the window closed; none when the browser gave no reference (then focus alone wakes the form).
	started.popup = opened ?? null;

	return opened !== null && opened !== undefined;
}

/**
 * Reads the REST nonce from WordPress (`admin-ajax.php?action=rest-nonce`): `{ok, nonce}`, `nonce` null while
 * nobody is logged in (WordPress answers 400 `0`; any answer without a nonce counts the same, so a site that
 * answers otherwise never blocks a guest), `ok` false only when the request itself failed.
 */
async function readNonce(url) {
	try {
		const response = await fetch(url, {
			credentials: 'same-origin',
			cache: 'no-store',
		});
		if (!response.ok) {
			return { ok: true, nonce: null };
		}
		const text = (await response.text()).trim();

		return { ok: true, nonce: NONCE.test(text) ? text : null };
	} catch {
		return { ok: false, nonce: null };
	}
}

/**
 * After a login in the block's window: the new nonce, then a re-fetch that re-renders the block for the
 * account. One at a time per form.
 *
 * @return {Promise<string>} `in` when the form is now logged in, `out` when nobody is, `failed` otherwise.
 */
function refresh(form) {
	const started = forms.get(form);
	if (started.refreshing === null) {
		started.refreshing = refreshNow(form).finally(() => {
			started.refreshing = null;
		});
	}

	return started.refreshing;
}

async function refreshNow(form) {
	const started = forms.get(form);
	const { ok, nonce } = await readNonce(started.account.nonceUrl);
	if (!ok) {
		return 'failed';
	}
	if (nonce === null) {
		if (started.popup?.closed === true) {
			// The window was closed without a login: stop waiting, so a focus or a send asks no more.
			started.awaiting = false;
			started.popup = null;
		}

		return 'out';
	}
	started.nonce = nonce;
	started.awaiting = false;
	started.popup = null;
	started.wantAccount = true;
	const applied = await refetch(form);

	return applied === null ? 'failed' : 'in';
}

/** A login may have happened (the return page's message, or the page regained focus): refresh, then retry. */
async function afterLogin(form) {
	const started = forms.get(form);
	if (started === undefined || started.done || !form.isConnected) {
		return;
	}
	const state = await refresh(form);
	if (state !== 'in') {
		return;
	}
	const retry = started.retry;
	started.retry = null;
	if (retry === 'send') {
		await send(form);
	} else if (retry === 'next') {
		await next(form);
	}
}

/**
 * The re-rendered account block in place of the old one, and the account's values filled into empty
 * controls only (the contact email): nothing typed is overwritten.
 */
function applyAccount(form, account) {
	const started = forms.get(form);
	const old = accountOf(form);
	if (old !== null && typeof account?.html === 'string') {
		const template = document.createElement('template');
		template.innerHTML = account.html;
		old.replaceWith(template.content);
	}
	let trigger = false;
	for (const [key, value] of Object.entries(account?.filled ?? {})) {
		const wrapper = wrapperOf(form, key);
		const control =
			wrapper === null
				? undefined
				: controlsOf(wrapper).find((element) => element.name === key);
		if (control !== undefined && control.value === '' && typeof value === 'string') {
			control.value = value;
			trigger = trigger || isTrigger(started, key);
		}
	}
	if (trigger) {
		refetch(form);
	}
}

/**
 * The autosave (S74): while the page has a draft route (a logged-in person on a registration form), the
 * answers that show are kept as the person's draft once they rest for `DRAFT_DELAY`, on every page move,
 * and when the page is left (`keepalive`). It shows nothing: a failed save is tried again with the next
 * change. A send takes what waits: the submit deletes the draft.
 */
function draftChanged(form) {
	const started = forms.get(form);
	if (!started?.draftUrl) {
		return;
	}
	started.draftDirty = true;
	clearTimeout(started.draftTimer);
	started.draftTimer = setTimeout(() => saveDraft(form), DRAFT_DELAY);
}

function saveDraft(form, keepalive = false) {
	const started = forms.get(form);
	if (!started?.draftUrl) {
		return;
	}
	clearTimeout(started.draftTimer);
	started.draftTimer = null;
	if (!started.draftDirty || started.sending || started.done) {
		return;
	}
	started.draftDirty = false;
	const headers = { 'Content-Type': 'application/json' };
	if (started.nonce) {
		headers['X-WP-Nonce'] = started.nonce;
	}
	try {
		fetch(started.draftUrl, {
			method: 'POST',
			keepalive,
			headers,
			body: JSON.stringify({
				version: started.version,
				answers: answersOf(form),
				page: started.page,
			}),
		})
			.then((response) => {
				if (!response.ok) {
					started.draftDirty = true;
				}
			})
			.catch(() => {
				started.draftDirty = true;
			});
	} catch {
		started.draftDirty = true;
	}
}

/** The message of a refusal: Kaláka's own (a `kalaka_` code) as it comes, anything else the failure text. */
function refusalMessage(body, failure) {
	return typeof body?.code === 'string' &&
		OWN_CODE.test(body.code) &&
		typeof body?.message === 'string'
		? body.message
		: failure;
}

/**
 * Re-fetches what the form shows for its current answers; with a page, that page's errors too (Tovább).
 * When it fails, the form stays as it is, the message area shows the refusal (the form's outdated version,
 * say) or the system failure text, and the form is out of date: it cannot move on or be sent until a later
 * re-fetch succeeds.
 *
 * One request in flight per form (owner ruling, 2026-11-13): a re-fetch asked for while one runs does not
 * send; it marks the form dirty and joins the running one. When the request returns to a dirty form, its
 * answer is dropped and ONE follow-up goes with the answers as they are then, however many changes came
 * meanwhile; so the server sees no burst and the latest answers win. A page asked for while a re-fetch runs
 * is carried by that follow-up (and every later one of the run).
 *
 * The route is S12's `Forms/Engine/Rest/FormRoutes` (`POST kalaka-portal/v1/forms/<id>/availability`).
 *
 * @param {HTMLFormElement} form
 * @param {number|null}     page The page to check, or null.
 * @return {Promise<Object|null>} The availability the run applied, or null when it failed.
 */
function refetch(form, page = null) {
	const started = forms.get(form);
	if (page !== null) {
		started.checking = page;
	}
	if (started.run !== null) {
		started.dirty = true;
		return started.run;
	}
	started.run = run(form);

	return started.run;
}

/** Sends re-fetches one at a time until one returns with no change made meanwhile, and applies its answer. */
async function run(form) {
	const started = forms.get(form);
	started.running++;
	reflect(form);

	let response;
	let checked = null;
	do {
		started.dirty = false;
		const request = { version: started.version, answers: answersOf(form) };
		if (started.checking !== null) {
			request.page = started.checking;
			checked = started.checking;
		}
		if (started.wantAccount && started.account) {
			request.account = started.account.page;
		}
		response = await post(started.availabilityUrl, request, started.nonce);
	} while (started.dirty);
	started.run = null;
	started.checking = null;
	started.running--;

	const { ok, body } = response;
	let applied = null;
	if (
		!ok ||
		body === null ||
		typeof body !== 'object' ||
		body.availability === null ||
		typeof body.availability !== 'object'
	) {
		if (!ok && body?.code === SESSION_ENDED) {
			// After the login a page check is retried as Tovább; any other re-fetch by the refresh itself.
			sessionEnded(form, body.message, started.retry ?? (checked === null ? 'refetch' : 'next'));
		} else {
			alertOf(form).textContent = ok
				? started.failure
				: refusalMessage(body, started.failure);
		}
		started.stale = true;
	} else {
		if (started.stale) {
			alertOf(form).textContent = '';
			started.stale = false;
		}
		apply(form, body);
		applied = body.availability;
		if (started.wantAccount && body.account) {
			started.wantAccount = false;
			applyAccount(form, body.account);
		}
	}
	reflect(form);

	return applied;
}

function isTrigger(started, key) {
	return Array.isArray(started.triggers) && started.triggers.includes(key);
}

function messageIdOf(key) {
	return `kalaka-field-${key}-error`;
}

function clearMessages(form) {
	alertOf(form).textContent = '';
	const account = form.querySelector('#kalaka-account-error');
	if (account !== null) {
		account.textContent = '';
		account.hidden = true;
	}
	for (const wrapper of form.querySelectorAll('[data-kalaka-field]')) {
		const slot = slotOf(wrapper);
		if (slot !== null) {
			slot.textContent = '';
			slot.hidden = true;
		}
		for (const control of controlsOf(wrapper)) {
			control.removeAttribute('aria-invalid');
			const ids = (control.getAttribute('aria-describedby') ?? '')
				.split(/\s+/)
				.filter((id) => id !== '' && id !== slot?.id);
			if (ids.length > 0) {
				control.setAttribute('aria-describedby', ids.join(' '));
			} else {
				control.removeAttribute('aria-describedby');
			}
		}
	}
}

/**
 * Puts each field's message in its error element (`.kalaka-field__error`, after its controls), and marks its
 * controls invalid and described by it; a field that does not show gets nothing.
 *
 * @return {Element|null} The first control of the first field with a message, in document order.
 */
function showFieldMessages(form, messages) {
	let first = null;
	for (const wrapper of form.querySelectorAll('[data-kalaka-field]')) {
		const key = wrapper.getAttribute('data-kalaka-field');
		const message = messages[key];
		if (typeof message !== 'string' || !shows(wrapper)) {
			continue;
		}
		const slot = slotOf(wrapper);
		if (slot !== null) {
			slot.id = slot.id || messageIdOf(key);
			slot.textContent = message;
			slot.hidden = false;
		}
		for (const control of controlsOf(wrapper)) {
			control.setAttribute('aria-invalid', 'true');
			if (slot !== null) {
				const ids = (control.getAttribute('aria-describedby') ?? '')
					.split(/\s+/)
					.filter((id) => id !== '' && id !== slot.id);
				control.setAttribute(
					'aria-describedby',
					[...ids, slot.id].join(' ')
				);
			}
		}
		first ??= controlsOf(wrapper).find((control) => !control.disabled);
	}

	return showAccountMessage(form, messages[ACCOUNT_KEY], first ?? null);
}

/**
 * The account block's error (`mandatory` mode) under the block. With one, the block's button is the first
 * thing to focus when it comes before `first` in the page.
 *
 * @return {Element|null} What to focus: the block's button or `first`.
 */
function showAccountMessage(form, message, first) {
	const slot = form.querySelector('#kalaka-account-error');
	const button = form.querySelector('[data-kalaka-account-login]');
	if (typeof message !== 'string' || slot === null) {
		return first;
	}
	slot.textContent = message;
	slot.hidden = false;
	if (button === null) {
		return first;
	}
	button.setAttribute('aria-describedby', slot.id);
	const before =
		first === null ||
		(button.compareDocumentPosition(first) &
			window.Node.DOCUMENT_POSITION_FOLLOWING) !==
			0;

	return before ? button : first;
}

/** The number of the page a field sits on; null outside pages. */
function pageOf(element) {
	const page = element?.closest('[data-kalaka-page]');

	return page === null || page === undefined
		? null
		: Number(page.getAttribute('data-kalaka-page'));
}

/**
 * Tovább: checks the current page with a re-fetch that sends it; with errors, shows them on the page and
 * focuses the first invalid field; with none, moves to the next page that shows.
 */
async function next(form) {
	const started = forms.get(form);
	// A terminal page has no Tovább; a theme that shows the hidden button anyway must not move past it.
	if (started !== undefined && isTerminal(form, started.page)) {
		return;
	}
	const target = await checkPage(form);
	if (target !== null) {
		stepTo(form, target);
	}
}

/**
 * Checks the current page with a re-fetch that sends it. With errors, shows them on the page and focuses
 * the first invalid field.
 *
 * @return {Promise<number|null>} The next page that shows when the page has no errors; null otherwise.
 */
async function checkPage(form) {
	const started = forms.get(form);
	if (
		started === undefined ||
		started.sending ||
		started.waiting ||
		started.checking !== null
	) {
		return null;
	}
	clearMessages(form);
	const current = started.page;
	const availability = await refetch(form, current);
	if (availability === null || started.page !== current) {
		return null;
	}
	const errors =
		availability.errors !== null && typeof availability.errors === 'object'
			? availability.errors
			: {};
	if (Object.keys(errors).length > 0) {
		const field = showFieldMessages(form, errors);
		if (field !== null) {
			field.focus();
		} else {
			focusOn(alertOf(form));
		}
		return null;
	}

	return nextPage(form, current);
}

/** Vissza: the previous page that shows, through the browser's history when the form stepped forward. */
function back(form) {
	const started = forms.get(form);
	if (started === undefined || isBusy(started)) {
		return;
	}
	const target = previousPage(form, started.page);
	if (target === null) {
		return;
	}
	clearMessages(form);
	if (started.depth > 0) {
		window.history.back();
		return;
	}
	window.history.replaceState(entry(started, target), '');
	showPage(form, target);
}

/**
 * The browser's Back or Forward button between the form's pages. Back shows the page of the entry (or the
 * nearest page before it that shows). Forward checks the current page as Tovább does: with no errors the
 * entry becomes the next page that shows; with errors the browser goes back to the entry of the current
 * page, so no entry is ever added twice and Back is never a no-op. After a submit the form's entries are
 * left behind (`collapse()`), and the listener ignores them.
 */
async function travel(form, state) {
	const started = forms.get(form);
	if (started === undefined || started.done || !form.isConnected) {
		return;
	}
	if (started.ignorePops > 0) {
		started.ignorePops--;
		return;
	}
	if (state?.kalakaForm !== started.formId) {
		return;
	}
	const target = Number(state.kalakaPage);
	if (target < started.page) {
		started.depth = Math.max(0, started.depth - 1);
		clearMessages(form);
		const shown = pageShows(form, target)
			? target
			: previousPage(form, target) ?? 0;
		showPage(form, shown);
	} else if (target > started.page) {
		// The browser is on the forward entry already.
		started.depth++;
		const next = await checkPage(form);
		if (next === null) {
			started.depth--;
			started.ignorePops++;
			window.history.back();
			return;
		}
		window.history.replaceState(entry(started, next), '');
		showPage(form, next);
	}
}

/**
 * After a successful send: the browser goes back to the entry the form started from, so that one Back
 * leaves the page; the form's later entries stay only as Forward entries, which the listener ignores.
 */
function collapse(started) {
	started.done = true;
	if (started.depth > 0) {
		window.history.go(-started.depth);
		started.depth = 0;
	}
}

/**
 * What the answer to a send is: the after-submit markup, or a message with the messages of fields and the
 * refusal's code. Only Kaláka's own errors (a `kalaka_` code, or messages of fields) show their message;
 * anything else, a WordPress REST error among them, shows the system failure text.
 */
function outcome({ ok, body }, failure) {
	if (body === null) {
		return { message: failure };
	}
	if (ok) {
		// A repeated send (`repeated: true`) answers with the first one's after-submit content.
		return typeof body?.after_submit === 'string'
			? { html: body.after_submit }
			: { message: failure };
	}
	if (body?.code === SESSION_ENDED) {
		return { sessionEnded: true, message: body.message };
	}
	if (body?.code === RATE_LIMITED) {
		return {
			rateLimited: true,
			message:
				typeof body.message === 'string' && body.message !== ''
					? body.message
					: RATE_LIMITED,
		};
	}
	const refusals = Array.isArray(body?.data?.refusals) ? body.data.refusals : [];
	const fields = body?.data?.fields;
	const hasFields = fields !== null && typeof fields === 'object';
	const own = typeof body?.code === 'string' && OWN_CODE.test(body.code);
	if (typeof body?.message !== 'string' || (!own && !hasFields)) {
		return { message: failure };
	}

	return {
		message: body.message,
		fields: hasFields ? fields : {},
		code: body.code,
		refusals,
	};
}

/**
 * After a refused send of a multi-page form: the server refuses a send while a terminal page shows, which
 * the page may not have known (an answer changed and the re-fetch was overtaken). A re-fetch finds that
 * page, and the form shows it with no Vissza.
 *
 * @return {Promise<boolean>} Whether a terminal page now shows.
 */
async function toTerminal(form) {
	const started = forms.get(form);
	const availability = await refetch(form);
	if (availability === null) {
		return false;
	}
	for (let index = 0; index < started.pages.length; index++) {
		if (pageShows(form, index) && isTerminal(form, index)) {
			alertOf(form).textContent = '';
			started.refusedTo = index;
			window.history.replaceState(entry(started, index), '');
			showPage(form, index);
			return true;
		}
	}

	return false;
}

async function send(form) {
	const started = forms.get(form);
	if (started !== undefined) {
		// What waits for the autosave goes with the send, which deletes the draft (S74): nothing is
		// saved after it but a change made after a send that failed.
		clearTimeout(started.draftTimer);
		started.draftTimer = null;
		started.draftDirty = false;
	}
	if (
		started === undefined ||
		started.sending ||
		started.waiting ||
		started.checking !== null
	) {
		return;
	}
	if (started.run !== null) {
		// A re-fetch runs (the click blurred a changed field): wait for it, so what is sent is what shows.
		started.waiting = true;
		await started.run;
		started.waiting = false;
		if (started.stale) {
			return;
		}
	}
	if (started.awaiting && started.account) {
		// The login window opened: whoever logged in there sends as that account, never as a guest.
		started.waiting = true;
		const state = await refresh(form);
		started.waiting = false;
		if (state === 'failed') {
			alertOf(form).textContent = started.failure;
			focusOn(alertOf(form));
			return;
		}
	}
	if (started.stale) {
		// The form is out of date after a failed re-fetch: try again; it is sent on a later click.
		await refetch(form);
		return;
	}
	// A terminal page sends nothing and moves nowhere, whichever button a theme lets through.
	if (hasPages(started) && isTerminal(form, started.page)) {
		return;
	}
	if (hasPages(started) && nextPage(form, started.page) !== null) {
		// Enter in a field of a page before the last moves on, as Tovább does.
		await next(form);
		return;
	}

	clearMessages(form);
	const answers = answersOf(form);
	started.sending = true;
	reflect(form);

	const result = outcome(
		await post(
			started.url,
			{
				version: started.version,
				answers,
				token: started.token,
			},
			started.nonce
		),
		started.failure
	);
	started.sending = false;

	if (result.html !== undefined) {
		// The after-submit content the server renders; the server escapes everything in it.
		collapse(started);
		const template = document.createElement('template');
		template.innerHTML = result.html;
		const first = template.content.firstElementChild;
		form.replaceWith(template.content);
		focusOn(first);
		return;
	}
	reflect(form);
	if (result.sessionEnded) {
		sessionEnded(form, result.message, 'send');
		focusOn(alertOf(form));
		return;
	}
	if (result.rateLimited) {
		alertOf(form).textContent = result.message;
		focusOn(alertOf(form));
		return;
	}
	if (started.account && (result.refusals ?? []).includes('account_required')) {
		// Logged in meanwhile in another tab: send again once as the account; else the block's error.
		const before = started.nonce;
		if ((await refresh(form)) === 'in' && started.nonce !== before) {
			await send(form);
			return;
		}
		// Under the block when it shows; else (no block, or one in a hidden branch) the refusal's own message.
		const button = form.querySelector('[data-kalaka-account-login]');
		if (button === null || button.closest('[hidden]') !== null) {
			alertOf(form).textContent = result.message;
			focusOn(alertOf(form));
			return;
		}
		focusOn(showAccountMessage(form, started.account.error, null));
		return;
	}
	if (
		hasPages(started) &&
		result.code === 'kalaka_form_terminal' &&
		(await toTerminal(form))
	) {
		return;
	}
	alertOf(form).textContent = result.message;
	const fields = result.fields ?? {};
	if (hasPages(started)) {
		// Jump to the earliest page with an error.
		const pages = Object.keys(fields)
			.map((key) => wrapperOf(form, key))
			.filter((wrapper) => wrapper !== null && shows(wrapper))
			.map((wrapper) => pageOf(wrapper))
			.filter((page) => page !== null);
		if (pages.length > 0) {
			const earliest = Math.min(...pages);
			if (earliest !== started.page) {
				window.history.replaceState(entry(started, earliest), '');
				showPage(form, earliest, false);
			}
		}
	}
	const field = showFieldMessages(form, fields);
	if (field !== null) {
		field.focus();
	} else {
		focusOn(alertOf(form));
	}
}

store('kalaka/form', {
	actions: {
		update(event) {
			const form = getElement().ref;
			const started = forms.get(form);
			const name = event?.target?.name;
			if (started === undefined || typeof name !== 'string') {
				return undefined;
			}
			const key = name.endsWith('[]') ? name.slice(0, -2) : name;
			if (isChoice(event.target)) {
				remember(form, key);
			}
			draftChanged(form);

			return isTrigger(started, key) ? refetch(form) : undefined;
		},
		// Synchronous: preventDefault() must run while the event is dispatched.
		submit: withSyncEvent((event) => {
			event.preventDefault();
			return send(getElement().ref);
		}),
		next() {
			const form = getElement().ref.closest('form');
			const started = forms.get(form);
			if (started?.stale) {
				return refetch(form);
			}

			return next(form);
		},
		back() {
			return back(getElement().ref.closest('form'));
		},
		// Synchronous: the window opens in the click, so the browser does not block it.
		logIn: withSyncEvent(() => {
			const form = getElement().ref.closest('form');
			// A fresh login from the block's button: a pending retry of an earlier, abandoned window is dropped
			// (the ended session's own link keeps it).
			const started = forms.get(form);
			if (started) {
				started.retry = null;
			}
			if (!openLogin(form)) {
				const url = started?.account?.url;
				if (typeof url === 'string' && url !== '') {
					window.location.assign(url);
				}
			}
		}),
	},
	callbacks: {
		start() {
			const form = getElement().ref;
			const started = {
				...plain(getContext()),
				token:
					form
						.querySelector('[data-kalaka-token]')
						?.getAttribute('value') ?? '',
				run: null,
				dirty: false,
				checking: null,
				running: 0,
				sending: false,
				waiting: false,
				stale: false,
				page: 0,
				depth: 0,
				ignorePops: 0,
				done: false,
				refusedTo: null,
				ticks: new Map(),
				timer: null,
				awaiting: false,
				popup: null,
				refreshing: null,
				wantAccount: false,
				retry: null,
				draftDirty: false,
				draftTimer: null,
			};
			forms.set(form, started);
			if (started.account) {
				// The return page's message, same origin only; or, with no opener, focus and visibility.
				window.addEventListener('message', (event) => {
					if (
						event.origin === window.location.origin &&
						event.data?.kalaka === ACCOUNT_MESSAGE
					) {
						afterLogin(form);
					}
				});
				const wake = () => {
					if (started.awaiting && document.visibilityState !== 'hidden') {
						afterLogin(form);
					}
				};
				window.addEventListener('focus', wake);
				document.addEventListener('visibilitychange', wake);
			}
			if (started.draftUrl) {
				window.addEventListener('pagehide', () => saveDraft(form, true));
			}
			for (const button of buttonsOf(form)) {
				button.disabled = false;
			}
			// The ticks the browser restored are remembered like ticks made here.
			for (const wrapper of form.querySelectorAll('[data-kalaka-field]')) {
				remember(form, wrapper.getAttribute('data-kalaka-field'));
			}
			if (hasPages(started)) {
				// Every load starts on the first page: nothing is kept across a reload.
				window.history.replaceState(entry(started, 0), '');
				started.onPopState = (event) => travel(form, event.state);
				window.addEventListener('popstate', started.onPopState);
				showPage(form, 0, false);
			}
			reflect(form);
			// The page renders for its prefilled answers; a browser that restored answers of triggers needs a re-fetch.
			const answers = answersOf(form);
			const answered = (value) =>
				value !== undefined &&
				value !== '' &&
				value !== false &&
				!(Array.isArray(value) && value.length === 0);
			if ((started.triggers ?? []).some((key) => answered(answers[key]))) {
				refetch(form);
			}
		},
	},
});

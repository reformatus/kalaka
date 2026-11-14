import {
	AVAILABILITY_URL,
	branch,
	checkboxes,
	choose,
	FAILURE,
	form,
	load,
	radios,
	respond,
	sentBody,
	settle,
	submit,
	text,
	type,
} from './helpers';

/** The controls of a field as the server renders them when it does not show. */
const off = (markup) => markup.replace(/<input /g, '<input disabled ');

// AC5: the fee chain, with made-up keys, as the server renders it for no answers: `fee` and `fee_reason` are
// the triggers, chain 0 is extra support or else the plain note, chain 1 the help note.
const page = form(
	radios('fee', ['basic', 'extra_support']) +
		radios('fee_reason', ['community_pays', 'needs_help']) +
		branch(0, 0, off(text('supporter') + text('amount'))) +
		branch(0, 1, text('plain_note'), true) +
		branch(1, 0, off(text('help_note'))) +
		checkboxes('modules', ['ifi_a', 'pro_a']) +
		text('note'),
	{ triggers: ['fee', 'fee_reason'] }
);

const root = () => document.querySelector('form');
const alert = () => document.querySelector('[role="alert"]');
const branchOf = (key) =>
	document
		.querySelector(`[data-kalaka-field="${key}"]`)
		.closest('[data-kalaka-chain]');
const disabled = (key) => document.querySelector(`[name="${key}"]`).disabled;

/** The answer of the re-fetch route for the fee `extra_support`. */
const extraSupport = {
	availability: {
		fields: {
			fee: true,
			fee_reason: true,
			supporter: true,
			amount: true,
			plain_note: false,
			help_note: false,
			modules: true,
			note: true,
		},
		chains: [0, null],
		options: {},
		triggers: ['fee', 'fee_reason'],
	},
	parts: {},
};

/** A response whose promise the test resolves itself. */
function deferred() {
	let resolve;
	const promise = new Promise((done) => {
		resolve = done;
	});

	return { promise, resolve };
}

beforeEach(() => {
	globalThis.fetch = jest.fn().mockResolvedValue(respond(200, extraSupport));
});

describe('the page as the server rendered it', () => {
	test('stays as it is when the script starts, and nothing is fetched', async () => {
		await load(page);

		expect(document.querySelector('button').disabled).toBe(false);
		expect(branchOf('supporter').hidden).toBe(true);
		expect(branchOf('plain_note').hidden).toBe(false);
		expect(disabled('supporter')).toBe(true);
		expect(globalThis.fetch).not.toHaveBeenCalled();
	});

	test('is re-fetched when the script starts with an answer of a trigger the browser restored', async () => {
		document.body.innerHTML = '';
		const restored = page.replace(
			'name="fee" value="extra_support"',
			'name="fee" value="extra_support" checked'
		);

		await load(restored);

		expect(globalThis.fetch).toHaveBeenCalledTimes(1);
		expect(branchOf('supporter').hidden).toBe(false);
	});
});

describe('a change of a trigger', () => {
	test('posts the version and the answers of the fields that show, without the token, to the re-fetch route', async () => {
		await load(page);
		type(root(), 'note', 'x');
		expect(globalThis.fetch).not.toHaveBeenCalled();

		choose(root(), 'fee', 'extra_support');
		await settle();

		expect(globalThis.fetch).toHaveBeenCalledTimes(1);
		const [url, options] = globalThis.fetch.mock.calls[0];
		expect(url).toBe(AVAILABILITY_URL);
		expect(options.method).toBe('POST');
		expect(options.headers).toEqual({ 'Content-Type': 'application/json' });
		expect(sentBody()).toEqual({
			version: 34,
			answers: {
				fee: 'extra_support',
				plain_note: '',
				modules: [],
				note: 'x',
			},
		});
	});

	test('changes nothing until the server answers: the script holds no condition', async () => {
		const pending = deferred();
		globalThis.fetch.mockReturnValue(pending.promise);
		await load(page);

		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(branchOf('supporter').hidden).toBe(true);
		expect(branchOf('plain_note').hidden).toBe(false);

		pending.resolve(respond(200, extraSupport));
		await settle();
		expect(branchOf('supporter').hidden).toBe(false);
		expect(branchOf('plain_note').hidden).toBe(true);
	});

	test('shows the branch of each chain that shows, hides the others, and disables the fields that do not show', async () => {
		await load(page);
		type(root(), 'plain_note', 'kept');

		choose(root(), 'fee', 'extra_support');
		await settle();

		expect(branchOf('supporter').hidden).toBe(false);
		expect(disabled('supporter')).toBe(false);
		expect(disabled('amount')).toBe(false);
		expect(branchOf('plain_note').hidden).toBe(true);
		expect(disabled('plain_note')).toBe(true);
		// A chain with no branch that shows (null) has every branch hidden.
		expect(branchOf('help_note').hidden).toBe(true);
		// What was typed stays, and a field that does not show is not sent.
		expect(document.querySelector('[name="plain_note"]').value).toBe('kept');
		globalThis.fetch.mockResolvedValue(respond(200, { html: '<p>ok</p>' }));
		submit(root());
		await settle();
		expect(Object.keys(sentBody(1).answers)).not.toContain('plain_note');
	});

	test('swaps the re-rendered choice fields and keeps the chosen options that are still offered', async () => {
		await load(page);
		root().querySelector('input[value="ifi_a"]').click();
		root().querySelector('input[value="pro_a"]').click();
		globalThis.fetch.mockResolvedValue(
			respond(200, {
				...extraSupport,
				availability: {
					...extraSupport.availability,
					options: { modules: ['ifi_a', 'ifi_b'] },
				},
				parts: { modules: checkboxes('modules', ['ifi_a', 'ifi_b']) },
			})
		);

		choose(root(), 'fee', 'extra_support');
		await settle();

		const values = [
			...root().querySelectorAll('[data-kalaka-field="modules"] input'),
		].map((input) => [input.value, input.checked]);
		expect(values).toEqual([
			['ifi_a', true],
			['ifi_b', false],
		]);
		expect(
			root().querySelectorAll('[data-kalaka-field="modules"]')
		).toHaveLength(1);
	});

	test('applies only the answer to a request sent after the last change', async () => {
		const first = deferred();
		globalThis.fetch.mockReturnValueOnce(first.promise).mockResolvedValueOnce(
			respond(200, {
				...extraSupport,
				availability: { ...extraSupport.availability, chains: [1, 0] },
			})
		);
		await load(page);

		choose(root(), 'fee', 'extra_support');
		choose(root(), 'fee', 'basic');
		await settle();
		first.resolve(respond(200, extraSupport));
		await settle();

		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(sentBody(1).answers.fee).toBe('basic');
		expect(branchOf('plain_note').hidden).toBe(false);
		expect(branchOf('supporter').hidden).toBe(true);
		expect(branchOf('help_note').hidden).toBe(false);
	});

	test('makes the form busy and keeps the button aria-disabled while it runs', async () => {
		const pending = deferred();
		globalThis.fetch.mockReturnValue(pending.promise);
		await load(page);

		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(root().getAttribute('aria-busy')).toBe('true');
		expect(document.querySelector('button').getAttribute('aria-disabled')).toBe('true');
		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(1);

		pending.resolve(respond(200, extraSupport));
		await settle();
		expect(root().hasAttribute('aria-busy')).toBe(false);
		expect(document.querySelector('button').hasAttribute('aria-disabled')).toBe(false);
	});

	test('that fails marks the form out of date: it cannot be sent until a later re-fetch succeeds', async () => {
		globalThis.fetch.mockRejectedValueOnce(new TypeError('offline'));
		await load(page);

		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe(FAILURE);
		expect(document.querySelector('button').getAttribute('aria-disabled')).toBe('true');
		expect(root().hasAttribute('aria-busy')).toBe(false);

		choose(root(), 'fee', 'basic');
		await settle();
		expect(alert().textContent).toBe('');
		expect(document.querySelector('button').hasAttribute('aria-disabled')).toBe(false);
		globalThis.fetch.mockResolvedValue(respond(200, { after_submit: '<p>ok</p>' }));
		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(3);
		expect(globalThis.fetch.mock.calls[2][0]).not.toBe(AVAILABILITY_URL);
	});

	test('a click on Küldés while the form is out of date re-runs the re-fetch instead of sending', async () => {
		globalThis.fetch.mockRejectedValueOnce(new TypeError('offline'));
		await load(page);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe(FAILURE);

		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(globalThis.fetch.mock.calls[1][0]).toBe(AVAILABILITY_URL);
		expect(alert().textContent).toBe('');
		expect(document.querySelector('button').hasAttribute('aria-disabled')).toBe(false);
	});

	test('an outdated version (409 kalaka_form_outdated) shows the text the server gives and keeps the form out of date', async () => {
		const outdated =
			'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.';
		globalThis.fetch.mockResolvedValue(
			respond(409, { code: 'kalaka_form_outdated', message: outdated, data: { status: 409 } })
		);
		await load(page);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe(outdated);
		expect(document.querySelector('button').getAttribute('aria-disabled')).toBe('true');

		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(globalThis.fetch.mock.calls[1][0]).toBe(AVAILABILITY_URL);
		expect(alert().textContent).toBe(outdated);
	});

	test('a form the route refuses (403 kalaka_form_refused, S12 N7) shows the text the server gives and keeps the form out of date', async () => {
		const refused = 'Ez az űrlap most nem küldhető be.';
		globalThis.fetch.mockResolvedValue(
			respond(403, {
				code: 'kalaka_form_refused',
				message: refused,
				data: { status: 403, refusals: ['outside_registration_window'] },
			})
		);
		await load(page);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe(refused);
		expect(document.querySelector('button').getAttribute('aria-disabled')).toBe('true');
	});

	test('a logged-in person\u2019s requests carry the REST nonce (S12 N3); a guest\u2019s carry none', async () => {
		globalThis.fetch.mockResolvedValue(respond(200, extraSupport));
		await load(
			form(radios('fee', ['basic', 'extra_support']) + branch(1, 0, text('help_note')), {
				triggers: ['fee'],
				nonce: 'n0nce',
			})
		);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(globalThis.fetch.mock.calls[0][1].headers['X-WP-Nonce']).toBe('n0nce');

		globalThis.fetch.mockClear();
		await load(page);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(globalThis.fetch.mock.calls[0][1].headers).not.toHaveProperty('X-WP-Nonce');
	});

	test('a chain or branch the server withheld from the page is no trouble', async () => {
		await load(
			form(radios('fee', ['basic', 'extra_support']) + branch(1, 0, text('help_note')), {
				triggers: ['fee'],
			})
		);
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe('');
		expect(branchOf('help_note').hidden).toBe(true);
	});

	test('a swap that replaces the focused control puts the focus on the same control in the new markup', async () => {
		await load(page);
		const pro = root().querySelector('input[value="pro_a"]');
		pro.focus();
		globalThis.fetch.mockResolvedValue(
			respond(200, {
				...extraSupport,
				parts: { modules: checkboxes('modules', ['ifi_a', 'pro_a', 'pro_b']) },
			})
		);

		choose(root(), 'fee', 'extra_support');
		pro.focus();
		await settle();

		const fresh = root().querySelector('input[value="pro_a"]');
		expect(fresh).not.toBe(pro);
		expect(document.activeElement).toBe(fresh);
	});

	test('that fails leaves the form as it is and shows the failure text, until a re-fetch succeeds', async () => {
		await load(page);

		const failures = [
			['basic', Promise.reject(new TypeError('offline'))],
			['extra_support', Promise.resolve(respond(500, { message: 'x' }))],
			['basic', Promise.resolve(respond(200))],
		];
		for (const [fee, failing] of failures) {
			failing.catch(() => {});
			globalThis.fetch.mockReturnValueOnce(failing);
			choose(root(), 'fee', fee);
			await settle();
			expect(alert().textContent).toBe(FAILURE);
			expect(branchOf('supporter').hidden).toBe(true);
		}

		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(alert().textContent).toBe('');
		expect(branchOf('supporter').hidden).toBe(false);
	});
});

// Owner ruling (2026-11-13): one request in flight per form; changes meanwhile coalesce into one follow-up.
describe('rapid changes of triggers', () => {
	/** A fetch whose answers the test releases one by one, counting the requests that run at once. */
	function gate() {
		const waiting = [];
		const state = { running: 0, most: 0 };
		globalThis.fetch = jest.fn(
			() =>
				new Promise((resolve) => {
					state.running++;
					state.most = Math.max(state.most, state.running);
					waiting.push(() => {
						state.running--;
						resolve(respond(200, extraSupport));
					});
				})
		);
		const release = async () => {
			waiting.shift()();
			await settle();
		};

		return { state, release, waiting };
	}

	test('never send two requests at once, and send at most one follow-up with the latest answers', async () => {
		await load(page);
		const { state, release, waiting } = gate();

		choose(root(), 'fee', 'extra_support');
		choose(root(), 'fee', 'basic');
		choose(root(), 'fee_reason', 'needs_help');
		choose(root(), 'fee', 'extra_support');
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(1);

		await release();
		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(sentBody(1).answers).toMatchObject({
			fee: 'extra_support',
			fee_reason: 'needs_help',
		});

		await release();
		expect(waiting).toHaveLength(0);
		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(state.most).toBe(1);
		expect(root().getAttribute('aria-busy')).toBeNull();
	});

	test('with no change while it runs, one request is all', async () => {
		await load(page);
		const { state, release } = gate();

		choose(root(), 'fee', 'extra_support');
		await settle();
		await release();

		expect(globalThis.fetch).toHaveBeenCalledTimes(1);
		expect(state.most).toBe(1);
	});

	test('a click on Küldés while a re-fetch runs waits for it and then sends what shows', async () => {
		await load(page);
		const { state, release } = gate();

		choose(root(), 'fee', 'extra_support');
		submit(root());
		submit(root());
		await settle();
		expect(globalThis.fetch).toHaveBeenCalledTimes(1);

		await release();
		expect(globalThis.fetch).toHaveBeenCalledTimes(2);
		expect(globalThis.fetch.mock.calls[1][0]).not.toBe(AVAILABILITY_URL);
		expect(sentBody(1).token).toBeDefined();
		expect(state.most).toBe(1);
	});
});

import { act } from 'react';
import { FAILURE, form, respond, settle, text } from './helpers';

// S74: the claim popup (`Blocks/form/claim.js`) on the form's store, and the notice after the choice.

// The first test loads `@wordpress/components` cold, which takes over Jest's 5 s under a full parallel run.
jest.setTimeout(20000);

const CLAIM_URL = 'http://example.test/wp-json/kalaka-portal/v1/forms/12/draft/claim';

const REFUSED_TEXT = 'A visszautasítás szövege a szerverről.';

const TEXTS = {
	title: 'Cím',
	intro: 'Bevezető',
	warning: 'Figyelmeztetés',
	merge: 'Egyesítés',
	mergeLine: 'Egyesítés sora: 2',
	keepDraft: 'Csak a mentett válaszok',
	keepDraftLine: 'Mentett sora',
	keepLink: 'Csak a bejövő válaszok',
	keepLinkLine: 'Bejövő sora',
	confirmTitle: 'Megerősítés',
	confirmText: 'Megerősítés szövege',
	confirmCancel: 'Mégse',
	confirm: 'Igen, törlöm a mentett válaszaimat',
	reload: 'Az oldal újratöltése',
};

const claimCalls = () =>
	globalThis.fetch.mock.calls.filter(([url]) => url === CLAIM_URL);

const button = (label) =>
	[...document.querySelectorAll('button')].find(
		(element) => element.textContent === label
	);

const dialogs = () => document.querySelectorAll('[role="dialog"]');

const alertLine = () => document.querySelector('.kalaka-claim__error');

let reload;

let popup = null;

/** The form page with a claim waiting, the form's script started, and the popup opened on its store. */
async function open(extra = {}, send = null) {
	reload = jest.fn();
	let claim;
	let actions;
	jest.isolateModules(() => {
		// Loaded before the page's markup: at load it finds no form waiting, and opens nothing itself.
		claim = require('../../src/Forms/Blocks/form/claim');
		document.body.innerHTML = form(text('name'), {
			draftUrl: 'http://example.test/wp-json/kalaka-portal/v1/forms/12/draft',
			draftClaimUrl: CLAIM_URL,
			draftClaimChanges: 2,
			draftClaimTexts: TEXTS,
			...extra,
		});
		require('../../src/Forms/Blocks/form/view');
		actions = require('@wordpress/interactivity').store('kalaka/form').actions;
	});
	while (document.querySelector('form [data-kalaka-send]').disabled) {
		await settle();
	}
	await act(async () => {
		popup = claim.start({
			send: send ?? ((choice, element) => actions.resolveDraftClaim(choice, element)),
			reload,
		});
		await settle();
	});

	return popup;
}

async function click(label) {
	await act(async () => {
		button(label).click();
		await settle();
	});
}

afterEach(async () => {
	await act(async () => popup?.unmount());
	popup = null;
	document.body.innerHTML = '';
});

beforeEach(() => {
	globalThis.fetch = jest.fn().mockResolvedValue(respond(200, { resolved: true }));
});

describe('the claim popup', () => {
	test('opens at page load with merge first and focused, its line with the count', async () => {
		await open();

		expect(dialogs()).toHaveLength(1);
		expect(dialogs()[0].getAttribute('aria-modal')).toBe('true');
		const title = document.getElementById(dialogs()[0].getAttribute('aria-labelledby'));
		expect(title.textContent).toBe('Cím');
		const labels = [...dialogs()[0].querySelectorAll('.kalaka-claim__choice button')].map(
			(element) => element.textContent
		);
		expect(labels).toEqual(['Egyesítés', 'Csak a mentett válaszok', 'Csak a bejövő válaszok']);
		expect(document.activeElement).toBe(button('Egyesítés'));
		expect(button('Egyesítés').classList.contains('is-primary')).toBe(true);
		expect(button('Csak a bejövő válaszok').classList.contains('is-destructive')).toBe(true);
		const line = document.getElementById(button('Egyesítés').getAttribute('aria-describedby'));
		expect(line.textContent).toBe('Egyesítés sora: 2');
		expect(claimCalls()).toHaveLength(0);
	});

	test('every button takes WordPress’s 40 px size, and a wrapping choice never shrinks below it', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_draft_claim_refused', message: REFUSED_TEXT })
		);
		await open();
		await click('Csak a bejövő válaszok');
		const buttons = [...document.querySelectorAll('[role="dialog"] button')];
		await click('Mégse');
		await click('Egyesítés');
		buttons.push(button('Az oldal újratöltése'));

		expect(buttons.map((element) => element.textContent)).toEqual([
			'Egyesítés',
			'Csak a mentett válaszok',
			'Csak a bejövő válaszok',
			'Mégse',
			'Igen, törlöm a mentett válaszaimat',
			'Az oldal újratöltése',
		]);
		for (const element of buttons) {
			expect(element.classList.contains('is-next-40px-default-size')).toBe(true);
		}
		// jsdom has no layout: the stylesheet's rule for the choices keeps the 40 px floor.
		const css = require('fs').readFileSync(
			require.resolve('../../src/Forms/Blocks/form/view.css'),
			'utf8'
		);
		const rule = css.match(/\.kalaka-claim__choice \.components-button \{([^}]*)\}/);
		expect(rule[1]).toMatch(/min-height: 40px;/);
	});

	test('cannot be dismissed: no close button, and Esc does nothing', async () => {
		await open();

		expect(dialogs()[0].querySelector('button[aria-label]')).toBeNull();
		await act(async () => {
			button('Egyesítés').dispatchEvent(
				new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
			);
			await settle();
		});

		expect(dialogs()).toHaveLength(1);
		expect(claimCalls()).toHaveLength(0);
	});

	test('merge sends the choice through the form, then reloads the page', async () => {
		await open();

		await click('Egyesítés');

		expect(claimCalls()).toHaveLength(1);
		expect(JSON.parse(claimCalls()[0][1].body)).toEqual({ choice: 'merge' });
		expect(reload).toHaveBeenCalledTimes(1);
	});

	test('keep_draft sends at once', async () => {
		await open();

		await click('Csak a mentett válaszok');

		expect(JSON.parse(claimCalls()[0][1].body)).toEqual({ choice: 'keep_draft' });
		expect(reload).toHaveBeenCalledTimes(1);
	});

	test('keep_link asks first: Mégse sends nothing and returns the focus, the confirmation sends', async () => {
		await open();

		await click('Csak a bejövő válaszok');
		expect(claimCalls()).toHaveLength(0);
		expect(dialogs()).toHaveLength(2);
		expect(dialogs()[1].getAttribute('aria-modal')).toBe('true');
		expect(document.activeElement).toBe(button('Mégse'));

		await click('Mégse');
		expect(dialogs()).toHaveLength(1);
		expect(claimCalls()).toHaveLength(0);
		expect(document.activeElement).toBe(button('Csak a bejövő válaszok'));

		await click('Csak a bejövő válaszok');
		await click('Igen, törlöm a mentett válaszaimat');

		expect(claimCalls()).toHaveLength(1);
		expect(JSON.parse(claimCalls()[0][1].body)).toEqual({ choice: 'keep_link' });
		expect(reload).toHaveBeenCalledTimes(1);
	});

	test('a refused claim shows the route’s text and a button that reloads the page', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_draft_claim_refused', message: REFUSED_TEXT })
		);
		await open();
		expect(button('Az oldal újratöltése')).toBeUndefined();

		await click('Egyesítés');

		expect(alertLine().getAttribute('role')).toBe('alert');
		expect(alertLine().textContent).toBe(REFUSED_TEXT);
		expect(reload).not.toHaveBeenCalled();
		expect(dialogs()).toHaveLength(1);
		expect(claimCalls()).toHaveLength(1);

		await click('Az oldal újratöltése');
		expect(reload).toHaveBeenCalledTimes(1);
	});

	test('after a refusal the focus moves to the reload button, scrolled into view', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_draft_claim_refused', message: REFUSED_TEXT })
		);
		const scrolled = jest.fn();
		window.HTMLElement.prototype.scrollIntoView = scrolled;
		try {
			await open();

			await click('Csak a mentett válaszok');

			expect(document.activeElement).toBe(button('Az oldal újratöltése'));
			expect(scrolled).toHaveBeenCalledTimes(1);
			expect(scrolled.mock.contexts[0]).toBe(button('Az oldal újratöltése'));
		} finally {
			delete window.HTMLElement.prototype.scrollIntoView;
		}
	});

	test('after a refusal the three choices are disabled, and the reload button is not', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(
			respond(409, { code: 'kalaka_draft_claim_refused', message: REFUSED_TEXT })
		);
		await open();

		await click('Csak a bejövő válaszok');
		await click('Igen, törlöm a mentett válaszaimat');

		for (const label of ['Egyesítés', 'Csak a mentett válaszok', 'Csak a bejövő válaszok']) {
			expect(button(label).disabled).toBe(true);
		}
		expect(button('Az oldal újratöltése').disabled).toBe(false);
		await click('Egyesítés');
		expect(claimCalls()).toHaveLength(1);
	});

	test('any other failure shows the form’s failure text, with no reload button', async () => {
		globalThis.fetch = jest.fn().mockResolvedValue(respond(500, { code: 'x', message: 'y' }));
		await open();

		await click('Csak a mentett válaszok');

		expect(alertLine().textContent).toBe(FAILURE);
		expect(button('Az oldal újratöltése')).toBeUndefined();
		expect(reload).not.toHaveBeenCalled();
		expect(document.activeElement).toBe(button('Csak a mentett válaszok'));
	});

	test('a send that throws leaves the choices usable, with the failure text', async () => {
		await open({}, () => Promise.reject(new Error('no store yet')));

		await click('Egyesítés');

		expect(alertLine().textContent).toBe(FAILURE);
		expect(button('Egyesítés').disabled).toBe(false);
		expect(button('Csak a mentett válaszok').disabled).toBe(false);
	});

	test('opens nothing without a claim route', async () => {
		expect(await open({ draftClaimUrl: undefined })).toBeNull();
		expect(dialogs()).toHaveLength(0);
	});
});

describe('the notice after the choice', () => {
	test('shows the server’s line once, above the form, in a live region', async () => {
		await open({ draftClaimUrl: undefined, draftClaimNotice: 'merge', draftClaimNoticeText: 'Kész.' });
		await settle();
		await new Promise((resolve) => setTimeout(resolve, 300));

		const notices = document.querySelectorAll('[data-kalaka-claim-notice]');
		expect(notices).toHaveLength(1);
		expect(notices[0].getAttribute('role')).toBe('status');
		expect(notices[0].textContent).toBe('Kész.');
		expect(notices[0].nextElementSibling).toBe(document.querySelector('form'));
	});

	test('is not there without it', async () => {
		await open({ draftClaimUrl: undefined });
		await new Promise((resolve) => setTimeout(resolve, 350));

		expect(document.querySelector('[data-kalaka-claim-notice]')).toBeNull();
	});
});

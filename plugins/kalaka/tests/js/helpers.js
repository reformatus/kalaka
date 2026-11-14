/** The markup of a form page as the server renders it (Blocks/form/render.php and the field blocks). */

/** The error element every field renders (`FieldMarkup::errorElement()`). */
const errorOf = (key) =>
	`<span id="kalaka-field-${key}-error" class="kalaka-field__error" hidden></span>`;

export function field(key, controls) {
	return `<div class="kalaka-field" data-kalaka-field="${key}">${controls}${errorOf(
		key
	)}</div>`;
}

export function text(key) {
	return field(key, `<input type="text" name="${key}" required>`);
}

export function area(key) {
	return field(key, `<textarea name="${key}"></textarea>`);
}

export function radios(key, options) {
	return field(
		key,
		`<fieldset>${options
			.map(
				(option) =>
					`<label><input type="radio" name="${key}" value="${option}">${option}</label>`
			)
			.join('')}</fieldset>`
	);
}

export function checkboxes(key, options) {
	return field(
		key,
		`<fieldset>${options
			.map(
				(option) =>
					`<label><input type="checkbox" name="${key}[]" value="${option}">${option}</label>`
			)
			.join('')}</fieldset>`
	);
}

export function consent(key) {
	return field(
		key,
		`<label><input type="checkbox" name="${key}" value="1"></label>`
	);
}

const attribute = (data) =>
	JSON.stringify(data).replace(/&/g, '&amp;').replace(/'/g, '&#039;');

/** A branch wrapper as the server renders it: its chain's number and its index, hidden unless it shows. */
export function branch(chain, index, inner, shows = false) {
	return `<div data-kalaka-chain="${chain}" data-kalaka-branch="${index}"${
		shows ? '' : ' hidden'
	}>${inner}</div>`;
}

export const FAILURE = 'Váratlan hiba történt. Próbáld újra később.';

export const AVAILABILITY_URL =
	'http://example.test/wp-json/kalaka-portal/v1/forms/12/availability';

/** The page's submit token, as the server renders it into the hidden field. */
export const TOKEN = 'AbCdEfGhIjKlMnOpQrSt_-';

export function form(inner, extra = {}, token = TOKEN) {
	const context = {
		formId: 12,
		version: 34,
		url: 'http://example.test/wp-json/kalaka-portal/v1/forms/12/submit',
		availabilityUrl: AVAILABILITY_URL,
		failure: FAILURE,
		triggers: [],
		...extra,
	};

	const navigation =
		Array.isArray(context.pages) && context.pages.length > 0
			? '<button type="button" data-kalaka-back data-wp-on--click="actions.back" hidden>Vissza</button><button type="button" data-kalaka-next data-wp-on--click="actions.next" hidden disabled>Tovább</button>'
			: '';

	return `<noscript><p>noscript</p></noscript><form novalidate data-wp-interactive="kalaka/form" data-wp-context='${attribute(
		context
	)}' data-wp-init="callbacks.start" data-wp-on--change="actions.update" data-wp-on--submit="actions.submit"><input type="hidden" data-kalaka-token value="${token}">${inner}<div role="alert" data-kalaka-alert></div>${navigation}<button type="submit" data-kalaka-send disabled>Küldés</button><div data-kalaka-spinner hidden></div><div role="status" class="kalaka-visually-hidden" data-kalaka-status data-kalaka-loading="Betöltés…"></div></form>`;
}

/** A page of a multi-page form as the server renders it (`PageMarkup`): hidden, with its number. */
export function page(number, inner, { title = '', terminal = false } = {}) {
	return `<div data-kalaka-page="${number}"${
		terminal ? ' data-kalaka-terminal' : ''
	} hidden>${
		title === '' ? '' : `<h2 data-kalaka-page-title>${title}</h2>`
	}${inner}</div>`;
}

/** Puts the markup on the page and starts the script as a page load does, in a fresh copy of it. */
export async function load(markup) {
	document.body.innerHTML = markup;
	jest.isolateModules(() => {
		require('../../src/Forms/Blocks/form/view');
	});
	// The script enables the button when it starts.
	const deadline = Date.now() + 5000;
	while (
		document.querySelector('form [data-kalaka-send]')?.disabled !== false &&
		Date.now() < deadline
	) {
		await settle();
	}
	await settle();
}

export async function settle() {
	await new Promise((resolve) => setTimeout(resolve, 30));
}

export function choose(root, name, value) {
	root.querySelector(`input[name="${name}"][value="${value}"]`).click();
}

export function type(root, name, value) {
	const input = root.querySelector(`[name="${name}"]`);
	input.value = value;
	input.dispatchEvent(new Event('input', { bubbles: true }));
	input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function submit(root) {
	root.dispatchEvent(
		new Event('submit', { bubbles: true, cancelable: true })
	);
}

export function respond(status, body) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => {
			if (body === undefined) {
				throw new SyntaxError('not JSON');
			}
			return body;
		},
	};
}

export function sentBody(call = 0) {
	return JSON.parse(globalThis.fetch.mock.calls[call][1].body);
}

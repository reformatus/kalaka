/**
 * The account step (registrations-and-identity.md, "Accounts on the registration form"; S15): what a
 * registration form begins with for someone not logged in when its event's account mode is mandatory or
 * optional. The server renders it before the form's body, which stays hidden until the person signs up or
 * skips. "Log in" is a plain link to WordPress's login page, which returns to the form's page, where no step
 * shows.
 *
 * Signing up asks for the form's contact email and display name, under the fields' own titles: the browser
 * checks them (`required`, `type="email"`), they are filled into those two fields of the form, which are
 * then locked (`readOnly`, never `disabled`, so they are still sent), and the choice is sent with the
 * submit. A field the server finds a problem with is unlocked, so the person can correct it there.
 *
 * The context's `account` is `{ skip, email, name }` (the two fields' keys), or null when no step shows.
 */

/**
 * @param {HTMLFormElement} form
 * @return {HTMLElement|null} The step, when the form has one.
 */
export function stepOf(form) {
	return form.querySelector('[data-kalaka-account-step]');
}

function bodyOf(form) {
	return form.querySelector('[data-kalaka-form-body]');
}

function signUpPart(form) {
	return form.querySelector('[data-kalaka-account-form]');
}

function fieldControl(form, key) {
	return (
		[...form.querySelectorAll('[data-kalaka-field] input')].find(
			(control) => control.name === key
		) ?? null
	);
}

/**
 * Whether the step still shows: the form has one and nothing has been chosen yet. While it shows, the form
 * sends nothing.
 *
 * @param {Object} started The form's state.
 * @return {boolean} Whether the step is pending.
 */
export function stepPending(started) {
	return (
		started.account !== null &&
		started.account !== undefined &&
		started.accountChoice === null
	);
}

/**
 * Enables the step's buttons when the script starts (the server renders them disabled, for a page without
 * JavaScript, which cannot send the form anyway).
 *
 * @param {HTMLFormElement} form
 */
export function startStep(form) {
	const step = stepOf(form);
	if (step === null) {
		return;
	}
	for (const button of step.querySelectorAll('button')) {
		button.disabled = false;
	}
}

/**
 * "Sign up": shows the step's two fields.
 *
 * @param {HTMLFormElement} form
 */
export function chooseSignUp(form) {
	const part = signUpPart(form);
	if (part === null) {
		return;
	}
	part.hidden = false;
	part.querySelector('[data-kalaka-account-email]')?.focus();
}

function finish(form, started, choice) {
	started.accountChoice = choice;
	const step = stepOf(form);
	if (step !== null) {
		step.hidden = true;
	}
	const body = bodyOf(form);
	if (body !== null) {
		body.hidden = false;
	}
}

/**
 * The sign-up's Tovább: when the browser accepts the two values, they are filled into the form's contact
 * email and display name, which are locked, and the form shows.
 *
 * @param {HTMLFormElement} form
 * @param {Object}          started The form's state.
 * @return {string[]} The keys filled in; none when a value was refused.
 */
export function signUpNext(form, started) {
	const part = signUpPart(form);
	if (part === null || part.hidden || !stepPending(started)) {
		return [];
	}
	const inputs = {
		[started.account.email]: part.querySelector(
			'[data-kalaka-account-email]'
		),
		[started.account.name]: part.querySelector('[data-kalaka-account-name]'),
	};
	for (const input of Object.values(inputs)) {
		if (input === null) {
			return [];
		}
		input.value = input.value.trim();
		if (!input.reportValidity()) {
			return [];
		}
	}
	const filled = [];
	for (const [key, input] of Object.entries(inputs)) {
		const control = fieldControl(form, key);
		if (control !== null) {
			control.value = input.value;
			control.readOnly = true;
			filled.push(key);
		}
	}
	finish(form, started, 'signup');

	return filled;
}

/**
 * "Continue without an account" (optional mode only): the form shows, and the registration lands with no
 * account.
 *
 * @param {HTMLFormElement} form
 * @param {Object}          started The form's state.
 */
export function skip(form, started) {
	if (!stepPending(started) || !started.account.skip) {
		return;
	}
	finish(form, started, 'skip');
}

/**
 * After a refused send: the locked fields with a problem are unlocked, so they can be corrected.
 *
 * @param {HTMLFormElement} form
 * @param {Object}          started The form's state.
 * @param {Object}          fields  The problems by field key.
 */
export function unlock(form, started, fields) {
	if (started.accountChoice !== 'signup') {
		return;
	}
	for (const key of [started.account.email, started.account.name]) {
		if (fields[key] !== undefined) {
			const control = fieldControl(form, key);
			if (control !== null) {
				control.readOnly = false;
			}
		}
	}
}

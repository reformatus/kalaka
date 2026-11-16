/* global __dirname */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * S15 (UX review 9): the registration page's script, `SignUpPage::FOCUS_SCRIPT`, read from the PHP source and
 * run on WordPress's registration markup.
 */
function script() {
	const source = readFileSync(
		join(__dirname, '../../src/Registrations/Account/SignUpPage.php'),
		'utf8'
	);
	const constant = source.match(/FOCUS_SCRIPT = ([\s\S]*?);\n/)[1];

	return [...constant.matchAll(/'([^']*)'/g)].map((part) => part[1]).join('');
}

function run(markup) {
	document.body.innerHTML = markup;
	new Function(script())();
	window.dispatchEvent(new Event('load'));

	return document.getElementById('user_email');
}

const field = '<form id="registerform"><input type="email" id="user_email"></form>';

test('with WordPress’s error, the e-mail field is invalid, described by the error and focused', () => {
	const email = run(`<div id="login_error">Hiba</div>${field}`);

	expect(email.getAttribute('aria-invalid')).toBe('true');
	expect(email.getAttribute('aria-describedby')).toBe('login_error');
	expect(document.activeElement).toBe(email);
});

test('without an error, the e-mail field is only focused', () => {
	const email = run(field);

	expect(email.hasAttribute('aria-invalid')).toBe(false);
	expect(email.hasAttribute('aria-describedby')).toBe(false);
	expect(document.activeElement).toBe(email);
});

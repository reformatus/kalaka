/**
 * Reads a field's controls: the answer the submit and re-fetch requests send (events-and-forms.md, "Forms
 * implementation").
 */

function controlsOf(wrapper, key) {
	return [...wrapper.querySelectorAll('input, select, textarea')].filter(
		(control) => control.name === key || control.name === key + '[]'
	);
}

/**
 * The answer of the field whose wrapper is given: a list of the ticked values for `<key>[]` checkboxes
 * (`[]` when none), `true` or `false` for a single checkbox, the chosen value for radios (nothing sent when
 * none is chosen), the string as typed for anything else. Never `null`.
 *
 * @param {Element} wrapper The element with `data-kalaka-field`.
 * @param {string}  key     The field's key.
 * @return {{present: boolean, value?: (string|boolean|string[])}} The answer, or that there is none.
 */
export function readAnswer(wrapper, key) {
	const controls = controlsOf(wrapper, key);
	if (controls.length === 0) {
		return { present: false };
	}

	if (controls.some((control) => control.name === key + '[]')) {
		return {
			present: true,
			value: controls
				.filter((control) => control.checked)
				.map((control) => control.value),
		};
	}

	const first = controls[0];
	if (first.type === 'radio') {
		const chosen = controls.find((control) => control.checked);

		return chosen === undefined
			? { present: false }
			: { present: true, value: chosen.value };
	}
	if (first.type === 'checkbox') {
		return { present: true, value: first.checked };
	}

	return { present: true, value: first.value };
}

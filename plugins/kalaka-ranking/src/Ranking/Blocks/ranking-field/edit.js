/**
 * The ranking field in the editor: its title, its set's options as a static list of checkboxes, and its
 * settings (required, the fewest and the most options a ranking holds, and the assignment set it names,
 * S44-1). The options are the set's (configured outside the editor until S72/S48); the canvas lists them
 * all, whatever their conditions. Labels shown here are proposed text, not yet approved by the owner.
 *
 * Like every core field block, it is built from core's field-editor pieces (`window.kalakaFieldEditor`, set by
 * the form editor's script, which `block.json` loads first): the one keeper of keys, so a copied ranking gets a
 * fresh key; the title; the settings; the participant-field and key panels; and the canvas marker of a field
 * the server would refuse.
 */
import apiFetch from '@wordpress/api-fetch';
import { useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { createElement as el, useEffect, useState } from '@wordpress/element';

/** Core's field-editor pieces, read when the editor renders (`Blocks/form/index.js` in core). */
const core = () => window.kalakaFieldEditor;

/** A number setting: a whole number of at least 1, or the default when cleared. */
function Count({ label, value, fallback, onChange }) {
	return el(TextControl, {
		type: 'number',
		min: 1,
		step: 1,
		label,
		value: String(value ?? fallback),
		onChange: (text) => {
			const number = Number.parseInt(text, 10);
			onChange(Number.isInteger(number) && number >= 1 ? number : fallback);
		},
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	});
}

/**
 * [UNAPPROVED N8, N9] The texts of the set picker (S44-1), proposed and not yet approved by the owner.
 */
export const TEXT = {
	panel: 'Lehetőségek',
	select: 'Beosztás',
	choose: 'Válassz beosztást',
	loading: 'A beosztások betöltése…',
	failed: 'A beosztásokat nem sikerült betölteni.',
	none: 'Ennek az eseménynek még nincs beosztása.',
	noSet: 'Válassz beosztást az oldalsávban.',
};

/** The form's event, as the form editor knows it (`kalaka/event-keys`, core's form editor); 0 until known. */
function useEventId() {
	return useSelect((select) => select('kalaka/event-keys')?.getEventId?.() ?? 0, []);
}

/**
 * The sets of the form's event (`GET kalaka-admin/v1/events/{id}/assignment-sets`): `loading`, `ready` with
 * the sets, or `failed`; `noEvent` while the form has no event. A later event's read wins over an earlier
 * one still under way.
 */
function useSets(eventId) {
	const [sets, setSets] = useState({ state: 'loading', list: [] });
	useEffect(() => {
		if (eventId === 0) {
			// The form has no event yet: there is nothing to read, and nothing to say.
			setSets({ state: 'noEvent', list: [] });
			return undefined;
		}
		let current = true;
		apiFetch({ path: `/kalaka-admin/v1/events/${eventId}/assignment-sets` }).then(
			(list) => current && setSets({ state: 'ready', list: Array.isArray(list) ? list : [] }),
			() => current && setSets({ state: 'failed', list: [] })
		);
		return () => {
			current = false;
		};
	}, [eventId]);
	return sets;
}

/** The set picker: the event's sets by name; choosing one names it in the block's `set`. */
function SetPicker({ sets, value, setAttributes }) {
	let help = null;
	if (sets.state === 'loading') {
		help = TEXT.loading;
	} else if (sets.state === 'failed') {
		help = TEXT.failed;
	} else if (sets.state === 'ready' && sets.list.length === 0) {
		help = TEXT.none;
	}
	return el(
		PanelBody,
		{ title: TEXT.panel },
		el(SelectControl, {
			label: TEXT.select,
			value: value === undefined ? '' : String(value),
			options: [
				{ label: TEXT.choose, value: '' },
				...sets.list.map((set) => ({ label: set.name, value: String(set.id) })),
			],
			onChange: (chosen) => setAttributes({ set: chosen === '' ? undefined : Number(chosen) }),
			help,
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		})
	);
}

export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const { FieldInspector, FieldTitle, preview, REQUIRED, useFieldCanvas, useStableKey } = core();
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const sets = useSets(useEventId());
	const chosen = sets.list.find((set) => set.id === attributes.set);
	const options = Array.isArray(chosen?.options) ? chosen.options : [];
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(
			FieldInspector,
			{
				name,
				clientId,
				attributes,
				setAttributes,
				toggles: [REQUIRED],
				settings: [
					el(Count, {
						key: 'min',
						label: 'Legkevesebb választás',
						value: attributes.min,
						fallback: 1,
						onChange: (min) => setAttributes({ min }),
					}),
					el(Count, {
						key: 'max',
						label: 'Legtöbb választás',
						value: attributes.max,
						fallback: 3,
						onChange: (max) => setAttributes({ max }),
					}),
				],
			},
			el(SetPicker, { sets, value: attributes.set, setAttributes })
		),
		el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit }),
		chosen === undefined
			? el('p', { className: 'kalaka-editor-control' }, TEXT.noSet)
			: el(
					'ul',
					null,
					options.map((option) =>
						el(
							'li',
							{ key: option.value },
							el(
								'label',
								null,
								preview('input', { type: 'checkbox' }),
								' ',
								option.label
							)
						)
					)
				)
	);
}

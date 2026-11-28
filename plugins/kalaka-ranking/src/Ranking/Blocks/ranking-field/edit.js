/**
 * The ranking field in the editor: its title, its options as a static list of checkboxes, and its settings
 * (required, the fewest and the most options a ranking holds, and where the options come from). The author
 * picks the source (the owner's ruling, 2026-11-18): the block's own options, edited by core's options editor
 * (the choice's own: a label each; the form editor derives a key from it on save, shown in the key panel); or an
 * assignment set (S44-1), whose options are
 * configured outside the editor until S72/S48 and which the canvas lists all, whatever their conditions. One
 * attribute decides: `set`, absent for own options, a number for a set (0 until one is chosen). Switching
 * keeps what the other source held. The texts of the source choice are the owner's (2026-11-18).
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
import { createElement as el, Fragment, useEffect, useState } from '@wordpress/element';

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
 * The texts of the set picker (S44-1) and of the source choice (`source`, `own`, `fromSet`), approved by
 * the owner (2026-11-18).
 */
export const TEXT = {
	panel: 'Lehetőségek',
	source: 'Lehetőségek forrása',
	own: 'Saját lehetőségek',
	fromSet: 'Beosztás',
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

/**
 * The block's option conditions that still name an option of the chosen set: a set's own options are the only ones
 * a condition can name, so switching or clearing the set drops the rest (the save check would refuse them).
 */
export function keptConditions(conditions, sets, chosen) {
	if (conditions === null || typeof conditions !== 'object' || Array.isArray(conditions)) {
		return undefined;
	}
	const set = sets.list.find((candidate) => candidate.id === chosen);
	const keys = new Set((Array.isArray(set?.options) ? set.options : []).map((option) => option.value));
	const kept = Object.fromEntries(Object.entries(conditions).filter(([key]) => keys.has(key)));
	return Object.keys(kept).length > 0 ? kept : undefined;
}

/**
 * The set picker: the event's sets by name; choosing one names it in the block's `set`, and keeps only the option
 * conditions whose option the new set has.
 */
export function SetPicker({ sets, value, setAttributes, conditions }) {
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
			value: value > 0 ? String(value) : '',
			options: [
				{ label: TEXT.choose, value: '' },
				...sets.list.map((set) => ({ label: set.name, value: String(set.id) })),
			],
			// Clearing the choice keeps the set mode, with no set chosen yet.
			onChange: (chosen) => {
				const set = chosen === '' ? 0 : Number(chosen);
				setAttributes({ set, optionConditions: keptConditions(conditions, sets, set) });
			},
			help,
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		})
	);
}

/**
 * The options' source: the own options, or a set. `set` absent is own options, so a block saved before sets
 * existed reads as that, with no migration. Switching changes only `set`: the other source's options stay.
 * The own options are edited by core's options editor (`window.kalakaFieldEditor.Options`, the choice's own).
 */
export function Source({ sets, attributes, setAttributes, commit }) {
	const bySet = Number.isInteger(attributes.set);
	const options = Array.isArray(attributes.options) ? attributes.options : [];
	const { Options } = core();
	return el(
		Fragment,
		null,
		el(
			PanelBody,
			{ title: TEXT.source },
			el(SelectControl, {
				label: TEXT.source,
				hideLabelFromVision: true,
				value: bySet ? 'set' : 'own',
				options: [
					{ label: TEXT.own, value: 'own' },
					{ label: TEXT.fromSet, value: 'set' },
				],
				onChange: (mode) => setAttributes({ set: mode === 'set' ? 0 : undefined }),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			})
		),
		bySet
			? el(SetPicker, { sets, value: attributes.set, setAttributes, conditions: attributes.optionConditions })
			: el(Options, { options, setAttributes, commit })
	);
}

export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const { FieldInspector, FieldTitle, preview, REQUIRED, useFieldCanvas, useStableKey } = core();
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const sets = useSets(useEventId());
	const bySet = Number.isInteger(attributes.set);
	const chosen = bySet ? sets.list.find((set) => set.id === attributes.set) : undefined;
	const own = Array.isArray(attributes.options) ? attributes.options : [];
	const options = bySet ? (Array.isArray(chosen?.options) ? chosen.options : []) : own;
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
			el(Source, { sets, attributes, setAttributes, commit })
		),
		el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit }),
		bySet && chosen === undefined
			? el('p', { className: 'kalaka-editor-control' }, TEXT.noSet)
			: el(
					'ul',
					null,
					options.map((option, index) =>
						el(
							'li',
							{ key: bySet ? option.value : index },
							el(
								'label',
								null,
								preview('input', { type: 'checkbox' }),
								' ',
								(option?.label ?? '') !== ''
									? option.label
									: el('span', { className: 'kalaka-editor-control' }, `${index + 1}. lehetőség`)
							)
						)
					)
				)
	);
}

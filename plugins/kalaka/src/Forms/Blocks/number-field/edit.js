/**
 * The number field in the editor: its title, a static preview of its control, and its settings: required,
 * the smallest value, the step, the largest value, the number of decimals, and the prefix and suffix.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { TextControl } from '@wordpress/components';
import { createElement as el } from '@wordpress/element';
import { useFieldCanvas } from '../form/participant-ui';
import { FieldInspector, FieldTitle, preview, REQUIRED, useStableKey } from '../form/field';

/** One setting of the block's attributes: a number control (so `1 000` cannot be typed) unless `type` says text. */
function Text({ label, attribute, attributes, setAttributes, type = 'number', ...props }) {
	return el(TextControl, {
		label,
		type,
		...(type === 'number' ? { step: 'any' } : {}),
		value: attributes[attribute] ?? '',
		onChange: (value) => setAttributes({ [attribute]: value }),
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
		...props,
	});
}

/** The settings: the three bounds, the number of decimals, and the affixes. */
function Settings({ attributes, setAttributes }) {
	const decimals = Number.isInteger(attributes.decimals) ? attributes.decimals : 0;
	return [
		el(Text, { key: 'min', label: 'Legkisebb érték', attribute: 'min', attributes, setAttributes }),
		el(Text, { key: 'step', label: 'Lépésköz', attribute: 'step', attributes, setAttributes }),
		el(Text, { key: 'max', label: 'Legnagyobb érték', attribute: 'max', attributes, setAttributes }),
		el(TextControl, {
			key: 'decimals',
			label: 'Tizedesjegyek száma',
			type: 'number',
			min: 0,
			max: 8,
			step: 1,
			value: String(decimals),
			onChange: (text) => {
				const value = Number.parseInt(text, 10);
				setAttributes({ decimals: Number.isInteger(value) ? Math.min(8, Math.max(0, value)) : 0 });
			},
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		}),
		el(Text, { key: 'prefix', label: 'Előtag', attribute: 'prefix', type: 'text', attributes, setAttributes }),
		el(Text, { key: 'suffix', label: 'Utótag', attribute: 'suffix', type: 'text', attributes, setAttributes }),
	];
}

/** The number field in the editor: its title, a static number control and its settings. */
export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const required = attributes.required === true;
	const prefix = typeof attributes.prefix === 'string' ? attributes.prefix : '';
	const suffix = typeof attributes.suffix === 'string' ? attributes.suffix : '';
	const classes =
		'kalaka-number' +
		(prefix === '' ? '' : ' kalaka-number--prefix') +
		(suffix === '' ? '' : ' kalaka-number--suffix');
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(
			FieldInspector,
			{ name, clientId, attributes, setAttributes, toggles: [REQUIRED], settings: el(Settings, { attributes, setAttributes }) }
		),
		el(FieldTitle, { name, attributes, setAttributes, required, onBlur: commit }),
		el(
			'span',
			{ className: classes },
			prefix === ''
				? null
				: el('span', { className: 'kalaka-number__affix kalaka-number__prefix' }, prefix),
			preview('input', { type: 'number' }),
			suffix === ''
				? null
				: el('span', { className: 'kalaka-number__affix kalaka-number__suffix' }, suffix)
		)
	);
}

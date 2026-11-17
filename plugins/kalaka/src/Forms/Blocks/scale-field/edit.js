import { useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { createElement as el, useEffect, useState } from '@wordpress/element';
import { useFieldCanvas } from '../form/participant-ui';
import { FieldInspector, FieldTitle, preview, REQUIRED, useStableKey } from '../form/field';
import { pointsOf, rangeChange } from './range';

/**
 * One bound of the range: the text is kept as typed (empty, a lone minus sign) and applied when the control
 * loses focus, so a bound typed digit by digit does not move the other one on the way; text that is not an
 * integer goes back to the bound as it is.
 */
function Bound({ which, label, range, setAttributes }) {
	const [text, setText] = useState(String(range[which]));
	useEffect(() => setText(String(range[which])), [range[which]]);
	return el(TextControl, {
		label,
		type: 'number',
		step: 1,
		value: text,
		onChange: setText,
		onBlur: () => {
			const next = rangeChange(range, which, text);
			if (next === null) {
				setText(String(range[which]));
			} else {
				setAttributes(next);
				setText(String(next[which]));
			}
		},
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	});
}

/**
 * The settings: the first and last point, and a label per point (a list from the first point on). The texts,
 * and the block's title „Skála” in `block.json`, are proposed, not approved: [UNAPPROVED T1] [UNAPPROVED T2]
 * [UNAPPROVED T3] [UNAPPROVED T4] [UNAPPROVED T5] [UNAPPROVED T8]
 */
function Settings({ range, labels, setAttributes }) {
	const number = (which, label) => el(Bound, { key: which, which, label, range, setAttributes });
	return [
		el(
			PanelBody,
			{ key: 'range', title: 'Tartomány' },
			number('min', 'Legkisebb érték'),
			number('max', 'Legnagyobb érték')
		),
		el(
			PanelBody,
			{ key: 'labels', title: 'Feliratok' },
			pointsOf(range).map((point, index) =>
				el(TextControl, {
					key: point,
					label: `Felirat (${point})`,
					value: labels[index] ?? '',
					onChange: (text) => {
						const next = pointsOf(range).map((unused, i) => labels[i] ?? '');
						next[index] = text;
						setAttributes({ pointLabels: next });
					},
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				})
			)
		),
	];
}

/** The scale field in the editor: its title, one static radio button per point and its settings. [UNAPPROVED N6] */
export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const range = {
		min: Number.isInteger(attributes.min) ? attributes.min : 1,
		max: Number.isInteger(attributes.max) ? attributes.max : 5,
	};
	const labels = Array.isArray(attributes.pointLabels) ? attributes.pointLabels : [];
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(
			FieldInspector,
			{ name, clientId, attributes, setAttributes, toggles: [REQUIRED] },
			el(Settings, { range, labels, setAttributes })
		),
		el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit }),
		pointsOf(range).map((point, index) =>
			el(
				'label',
				{ key: point },
				preview('input', { type: 'radio' }),
				' ',
				typeof labels[index] === 'string' && labels[index].trim() !== '' ? labels[index] : String(point),
				' '
			)
		)
	);
}

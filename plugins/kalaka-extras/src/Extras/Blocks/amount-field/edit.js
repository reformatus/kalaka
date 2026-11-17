/**
 * The amount field in the editor: its title, a static preview of its control, and its settings (required,
 * and the smallest, the step and the largest amount, each optional). Labels shown here are proposed text,
 * not yet approved by the owner.
 */
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { createElement as el } from '@wordpress/element';

/** A limit: a whole number of at least 0, or none (null) when cleared. */
function Limit({ label, value, onChange }) {
	return el(TextControl, {
		type: 'number',
		min: 0,
		step: 1,
		label,
		value: Number.isInteger(value) ? String(value) : '',
		onChange: (text) => {
			const number = Number.parseInt(text, 10);
			onChange(Number.isInteger(number) && number >= 0 ? number : null);
		},
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	});
}

export default function Edit({ name, attributes, setAttributes }) {
	return el(
		'div',
		useBlockProps(),
		el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: 'Beállítások' },
				el(ToggleControl, {
					label: 'Kötelező',
					checked: attributes.required === true,
					onChange: (required) => setAttributes({ required }),
					__nextHasNoMarginBottom: true,
				}),
				el(Limit, { label: 'Legkisebb összeg', value: attributes.min, onChange: (min) => setAttributes({ min }) }),
				el(Limit, { label: 'Lépésköz', value: attributes.step, onChange: (step) => setAttributes({ step }) }),
				el(Limit, { label: 'Legnagyobb összeg', value: attributes.max, onChange: (max) => setAttributes({ max }) })
			)
		),
		el(
			'div',
			null,
			el(RichText, {
				tagName: 'span',
				value: attributes.label,
				onChange: (label) => setAttributes({ label }),
				placeholder: getBlockType(name)?.title,
				disableLineBreaks: true,
			}),
			attributes.required === true &&
				el('span', { className: 'kalaka-field__required', 'aria-hidden': 'true' }, '*')
		),
		el('input', { type: 'text', disabled: true, tabIndex: -1, 'aria-hidden': 'true' })
	);
}

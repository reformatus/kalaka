/**
 * The ranking field in the editor: its title, its options as a static list of checkboxes, and its settings
 * (required, the fewest and the most options a ranking holds, the options). An option has a label and a key
 * (`value`): the form editor derives a key missing on save from the label, as for a choice's options, and a
 * key typed here is kept, so the keys can match the ones another site sends (S45-1, S73). Labels shown here
 * are proposed text, not yet approved by the owner.
 */
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { Button, Flex, FlexBlock, PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { createElement as el } from '@wordpress/element';

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

/** The options editor: a label and a key per option, with add, remove and reorder. */
function Options({ options, setAttributes }) {
	const change = (next) => setAttributes({ options: next });
	const edit = (index, patch) => change(options.map((o, i) => (i === index ? { ...o, ...patch } : o)));
	const move = (from, to) => {
		const next = [...options];
		next.splice(to, 0, next.splice(from, 1)[0]);
		change(next);
	};
	return el(
		PanelBody,
		{ title: 'Lehetőségek' },
		options.map((option, index) => {
			const name = `${index + 1}. lehetőség`;
			const label = option?.label ? `${name} („${option.label}”)` : name;
			return el(
				Flex,
				{ key: index, align: 'flex-end' },
				el(
					FlexBlock,
					null,
					el(TextControl, {
						label: name,
						hideLabelFromVision: true,
						value: option?.label ?? '',
						onChange: (text) => edit(index, { label: text }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: 'Azonosító',
						value: option?.value ?? '',
						onChange: (text) => edit(index, { value: text }),
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					})
				),
				el(Button, {
					icon: 'arrow-up-alt2',
					label: `${label} feljebb mozgatása`,
					size: 'small',
					disabled: index === 0,
					accessibleWhenDisabled: true,
					onClick: () => move(index, index - 1),
				}),
				el(Button, {
					icon: 'arrow-down-alt2',
					label: `${label} lejjebb mozgatása`,
					size: 'small',
					disabled: index === options.length - 1,
					accessibleWhenDisabled: true,
					onClick: () => move(index, index + 1),
				}),
				el(Button, {
					icon: 'trash',
					label: `${label} törlése`,
					size: 'small',
					onClick: () => change(options.filter((o, i) => i !== index)),
				})
			);
		}),
		el(Button, { variant: 'secondary', onClick: () => change([...options, { label: '' }]) }, 'Hozzáadás')
	);
}

export default function Edit({ name, attributes, setAttributes }) {
	const options = Array.isArray(attributes.options) ? attributes.options : [];
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
				el(Count, {
					label: 'Legkevesebb választás',
					value: attributes.min,
					fallback: 1,
					onChange: (min) => setAttributes({ min }),
				}),
				el(Count, {
					label: 'Legtöbb választás',
					value: attributes.max,
					fallback: 3,
					onChange: (max) => setAttributes({ max }),
				})
			),
			el(Options, { options, setAttributes })
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
		el(
			'ul',
			null,
			options.map((option, index) =>
				el(
					'li',
					{ key: index },
					el(
						'label',
						null,
						el('input', { type: 'checkbox', disabled: true, tabIndex: -1, 'aria-hidden': 'true' }),
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

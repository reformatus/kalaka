import { useBlockProps } from '@wordpress/block-editor';
import { Button, Flex, FlexBlock, PanelBody, TextControl } from '@wordpress/components';
import { createElement as el, useEffect, useRef, useState } from '@wordpress/element';
import { useFieldCanvas } from '../form/participant-ui';
import { FieldInspector, FieldTitle, preview, REQUIRED, useStableKey } from '../form/field';

/**
 * The options editor: a list of labels with add, remove and reorder. An option's value is set once from
 * its label (when the label first loses focus, or the block is deselected) and never changes after;
 * it is shown only in the key panel. Adding an option, by the button or Enter in a label, moves the focus
 * to the new label.
 */
function Options({ options, setAttributes, commit }) {
	const inputs = useRef([]);
	const [focus, setFocus] = useState(null);
	useEffect(() => {
		if (focus !== null) {
			inputs.current[focus]?.focus();
			setFocus(null);
		}
	}, [focus, options.length]);

	const change = (next) => setAttributes({ options: next });
	const move = (from, to) => {
		const next = [...options];
		next.splice(to, 0, next.splice(from, 1)[0]);
		change(next);
	};
	const add = (at) => {
		change([...options.slice(0, at), { label: '' }, ...options.slice(at)]);
		setFocus(at);
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
						ref: (input) => {
							inputs.current[index] = input;
						},
						label: name,
						hideLabelFromVision: true,
						value: option?.label ?? '',
						onChange: (text) => change(options.map((o, i) => (i === index ? { ...o, label: text } : o))),
						onBlur: commit,
						onKeyDown: (event) => {
							if (event.key === 'Enter' && !event.nativeEvent?.isComposing) {
								event.preventDefault();
								// An empty option gets its label first; Enter adds no second empty one.
								if ((option?.label ?? '').trim() !== '') {
									add(index + 1);
								}
							}
						},
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
					onClick: () => {
						change(options.filter((o, i) => i !== index));
						// The focus goes to the option before, or after when it was the first.
						setFocus(options.length > 1 ? Math.max(0, index - 1) : null);
					},
				})
			);
		}),
		el(Button, { variant: 'secondary', onClick: () => add(options.length) }, 'Hozzáadás')
	);
}

/** The choice field in the editor: its title, its options as static radio buttons or checkboxes, and its settings. */
export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const options = Array.isArray(attributes.options) ? attributes.options : [];
	const type = attributes.multiple === true ? 'checkbox' : 'radio';
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(
			FieldInspector,
			{ name, clientId, attributes, setAttributes, toggles: [REQUIRED, ['multiple', 'Több válasz']] },
			el(Options, { options, setAttributes, commit })
		),
		el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit }),
		options.map((option, index) =>
			el(
				'div',
				{ key: index },
				el(
					'label',
					null,
					preview('input', { type }),
					' ',
					(option?.label ?? '') !== ''
						? option.label
						: // An option without a label yet: a placeholder, as control text (never on the front end).
							el('span', { className: 'kalaka-editor-control' }, `${index + 1}. lehetőség`)
				)
			)
		)
	);
}

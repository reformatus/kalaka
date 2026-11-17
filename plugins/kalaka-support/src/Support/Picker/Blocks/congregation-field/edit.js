/**
 * The congregation field in the editor: its title, a static preview of its search box, and the required
 * setting. The field has no other settings: every live root of the directory is offered (TODO(S54) limiting
 * a field to some churches). Labels shown here are proposed text, not yet approved by the owner.
 */
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { createElement as el } from '@wordpress/element';

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
				})
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
		el('input', { type: 'search', disabled: true, tabIndex: -1, 'aria-hidden': 'true' })
	);
}

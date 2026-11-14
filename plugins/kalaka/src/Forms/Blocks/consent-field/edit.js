import { useBlockProps } from '@wordpress/block-editor';
import { createElement as el } from '@wordpress/element';
import { useFieldCanvas } from '../form/participant-ui';
import { FieldInspector, FieldTitle, preview, REQUIRED, useStableKey } from '../form/field';

/** The consent field in the editor: a static checkbox before its title, and its settings. */
export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(FieldInspector, { name, clientId, attributes, setAttributes, toggles: [REQUIRED] }),
		el(
			'div',
			{ style: { display: 'flex', gap: '0.5em', alignItems: 'baseline' } },
			preview('input', { type: 'checkbox' }),
			el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit })
		)
	);
}

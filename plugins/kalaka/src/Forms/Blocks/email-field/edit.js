import { useBlockProps } from '@wordpress/block-editor';
import { createElement as el } from '@wordpress/element';
import { useFieldCanvas } from '../form/participant-ui';
import { FieldInspector, FieldTitle, preview, REQUIRED, useStableKey } from '../form/field';

/** The e-mail field in the editor: its title, a static preview of its control and its settings. */
export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	const required = attributes.required === true || attributes.contactEmail === true;
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(FieldInspector, { name, clientId, attributes, setAttributes, toggles: [REQUIRED, [
				'contactEmail',
				'Kapcsolattartási e-mail-cím',
				'Erre a címre küldjük a jelentkezéssel kapcsolatos leveleket.',
			]] }),
		el(FieldTitle, { name, attributes, setAttributes, required, onBlur: commit }),
		preview('input', { type: 'email' })
	);
}

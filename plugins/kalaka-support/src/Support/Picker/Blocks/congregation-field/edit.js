/**
 * The congregation field in the editor: its title, a static preview of its search box, and the required
 * setting. The field has no other settings: every live root of the directory is offered (TODO(S54) limiting
 * a field to some churches). Labels shown here are proposed text, not yet approved by the owner.
 *
 * Like every core field block, it is built from core's field-editor pieces
 * (`window.kalakaFieldEditor`, set by the form editor's script, which `block.json` loads first): the one keeper
 * of keys, so a copied field gets a fresh key; the title; the settings; the participant-field and key panels;
 * and the canvas marker of a field the server would refuse.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { createElement as el } from '@wordpress/element';

/** Core's field-editor pieces, read when the editor renders (`Blocks/form/index.js` in core). */
const core = () => window.kalakaFieldEditor;

export default function Edit({ name, attributes, setAttributes, clientId, isSelected }) {
	const { FieldInspector, FieldTitle, preview, REQUIRED, useFieldCanvas, useStableKey } = core();
	const commit = useStableKey(clientId, isSelected);
	// A marker and a red side rule when the server would refuse its participant field.
	const canvas = useFieldCanvas(name, clientId, attributes);
	return el(
		'div',
		useBlockProps(canvas.props),
		canvas.marker,
		el(FieldInspector, { name, clientId, attributes, setAttributes, toggles: [REQUIRED] }),
		el(FieldTitle, { name, attributes, setAttributes, required: attributes.required === true, onBlur: commit }),
		preview('input', { type: 'search' })
	);
}

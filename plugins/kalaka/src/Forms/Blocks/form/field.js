/**
 * The shared editor pieces of the field blocks (events-and-forms.md, "Forms implementation" and "Fields"):
 * the stable key, the title with its required marker, the settings toggles and the hidden key panel.
 * Labels shown here are proposed text, not yet approved by the owner.
 */
import { InspectorControls, RichText, store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useDispatch, useRegistry } from '@wordpress/data';
import { createElement as el, useCallback, useEffect } from '@wordpress/element';
import { KeyNotice } from './event-keys';
import { createKeeper, missing } from './keys';
import { arrived, useTextsWatch } from './participant-prompts';
import { KeyPanel, ParticipantPanel } from './participant-ui';

/** One keeper for every field block of the editor: this module is bundled once, in the form's editor script. */
const keeper = createKeeper();

/**
 * Sets the block's key, and its options' values, once: when the block mounts with a title, when its title
 * or an option label loses focus, or when the block is deselected. A copy gets fresh ones when it mounts.
 * Also the participant-field questions (`participant-prompts.js`): a block that arrives with another form's
 * field's key, and its label or option labels changed while the block was selected. Returns the commit callback for blur handlers.
 */
export function useStableKey(clientId, isSelected) {
	const registry = useRegistry();
	const { updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent } = useDispatch(blockEditorStore);

	const apply = useCallback(
		(patch) => {
			if (patch) {
				// The key belongs to the change that created the block, not to an undo step of its own.
				__unstableMarkNextChangeAsNotPersistent();
				updateBlockAttributes(clientId, patch);
			}
		},
		[clientId, updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent]
	);

	const commit = useCallback(
		() => apply(missing(registry.select(blockEditorStore), clientId)),
		[apply, registry, clientId]
	);

	useEffect(() => {
		const first = !keeper.has(clientId);
		apply(keeper.mount(registry.select(blockEditorStore), clientId));
		// After a copy's fresh key: a block that keeps another form's field's key is asked about.
		if (first) {
			arrived(registry.select(blockEditorStore), clientId);
		}
		// Only when the block mounts.
	}, [clientId]);

	useEffect(() => {
		if (!isSelected) {
			commit();
		}
	}, [isSelected, commit]);

	useTextsWatch(clientId, isSelected);

	return commit;
}

/** The required marker after a title, as the front end renders it. */
export const RequiredMarker = ({ required }) =>
	required ? el('span', { className: 'kalaka-field__required', 'aria-hidden': 'true' }, '*') : null;

/**
 * The editable title (the `label` attribute), followed by the required marker, and the notice of a field
 * left without a key because the event's keys failed to load.
 */
export const FieldTitle = ({ name, attributes, setAttributes, required, onBlur }) =>
	el(
		'div',
		{ onBlur },
		el(RichText, {
			tagName: 'span',
			value: attributes.label,
			onChange: (label) => setAttributes({ label }),
			placeholder: getBlockType(name)?.title,
			// A title is one line: Enter adds no line break.
			disableLineBreaks: true,
		}),
		el(RequiredMarker, { required }),
		el(KeyNotice, { attributes })
	);

/** The settings panel: one toggle per boolean attribute, as `[attribute, label, help?]`. */
export const Toggles = ({ attributes, setAttributes, toggles }) =>
	el(
		PanelBody,
		{ title: 'Beállítások' },
		toggles.map(([attribute, label, help]) =>
			el(ToggleControl, {
				key: attribute,
				label,
				help,
				checked: attributes[attribute] === true,
				onChange: (checked) => setAttributes({ [attribute]: checked }),
				__nextHasNoMarginBottom: true,
			})
		)
	);

/**
 * The inspector of a field block: its toggles, anything the block adds, then the participant field and the
 * key panel (`participant-ui.js`).
 */
export const FieldInspector = ({ name, clientId, attributes, setAttributes, toggles, children }) =>
	el(
		InspectorControls,
		null,
		el(Toggles, { attributes, setAttributes, toggles }),
		children,
		el(ParticipantPanel, { name, clientId, attributes, setAttributes }),
		el(KeyPanel, { name, clientId, attributes, setAttributes })
	);

/** The toggle every field block has. */
export const REQUIRED = ['required', 'Kötelező'];

/** A static, inert preview of a control: it shows the field's shape and takes no input. */
export const preview = (tag, props = {}) => el(tag, { ...props, disabled: true, tabIndex: -1, 'aria-hidden': 'true' });

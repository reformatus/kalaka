/**
 * The editor of a form page (`kalaka/form-page`, events-and-forms.md, "Forms implementation"): the page's
 * blocks under a label bar, as a condition block's, and in the inspector its title, its condition
 * (hand-written JSON, kept as a condition block's) and whether the form ends there without a submit. The
 * page title shows above its blocks as it does on the form. The server checks the page rules (the first
 * page unconditional, a terminal page conditional and without fields); the editor only parses the JSON.
 */
import {
	InnerBlocks,
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { createElement as el } from '@wordpress/element';
import { ConditionText } from '../form/branch';
import { summary } from '../form/branch-condition';

export default function Edit({ name, clientId, attributes, setAttributes }) {
	const { label, terminal } = attributes;
	const blockProps = useBlockProps({ className: 'kalaka-editor-branch kalaka-editor-page' });
	const hasBlocks = useSelect((select) => select(blockEditorStore).getBlockCount(clientId) > 0, [clientId]);
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'kalaka-editor-branch__blocks' },
		{ renderAppender: hasBlocks ? undefined : InnerBlocks.ButtonBlockAppender }
	);
	const text = summary(attributes.condition);
	return el(
		'div',
		blockProps,
		el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: 'Beállítások' },
				el(TextControl, {
					label: 'Cím',
					value: label,
					onChange: (value) => setAttributes({ label: value }),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				}),
				el(ConditionText, { clientId, attributes, setAttributes, label: 'Feltétel (JSON)' }),
				el(ToggleControl, {
					label: 'Itt véget ér az űrlap, beküldés nélkül',
					checked: terminal,
					onChange: (value) => setAttributes({ terminal: value }),
					__nextHasNoMarginBottom: true,
				})
			)
		),
		el(
			'div',
			{ className: 'kalaka-editor-control kalaka-editor-branch__label' },
			el('span', null, getBlockType(name)?.title),
			text !== '' && el('span', { className: 'kalaka-editor-branch__summary' }, ' · ' + text)
		),
		label !== '' && el('h2', null, label),
		el('div', innerBlocksProps)
	);
}

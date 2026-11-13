/**
 * The editor of the after-submit section (`kalaka/after-submit`, events-and-forms.md, "Forms
 * implementation"): its blocks under a label bar with the block's title, as a condition block's. It never
 * shows in the form; the server renders it after a submit.
 */
import { InnerBlocks, store as blockEditorStore, useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { useSelect } from '@wordpress/data';
import { createElement as el } from '@wordpress/element';

export default function Edit({ name, clientId }) {
	const blockProps = useBlockProps({ className: 'kalaka-editor-branch kalaka-editor-after-submit' });
	const hasBlocks = useSelect((select) => select(blockEditorStore).getBlockCount(clientId) > 0, [clientId]);
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'kalaka-editor-branch__blocks' },
		{ renderAppender: hasBlocks ? undefined : InnerBlocks.ButtonBlockAppender }
	);
	return el(
		'div',
		blockProps,
		el('div', { className: 'kalaka-editor-control kalaka-editor-branch__label' }, el('span', null, getBlockType(name)?.title)),
		el('div', innerBlocksProps)
	);
}

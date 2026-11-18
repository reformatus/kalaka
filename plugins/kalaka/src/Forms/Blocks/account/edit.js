/**
 * The editor of the account block (`kalaka/account`, owner ruling 2026-11-18): a bar with the block's title.
 * Its content is the server's, for the visitor (`Engine\AccountMarkup`), so the editor shows none of it.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { createElement as el } from '@wordpress/element';

export default function Edit({ name }) {
	const blockProps = useBlockProps({ className: 'kalaka-editor-branch kalaka-editor-account' });
	return el(
		'div',
		blockProps,
		el(
			'div',
			{ className: 'kalaka-editor-control kalaka-editor-branch__label' },
			el('span', null, getBlockType(name)?.title)
		)
	);
}

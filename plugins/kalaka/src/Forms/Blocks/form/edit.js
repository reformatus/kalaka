import { useBlockProps } from '@wordpress/block-editor';
import { SelectControl } from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import { createElement as el } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * The embed block in a page's editor: a picker of the forms (`kalaka_form` posts). Forms are read through
 * REST, which needs full access, so the picker is empty for anyone else. The form itself renders only
 * on the page.
 */
export default function Edit({ attributes, setAttributes }) {
	const { records } = useEntityRecords('postType', 'kalaka_form', {
		per_page: -1,
		status: 'any',
		orderby: 'title',
		order: 'asc',
		_fields: 'id,title',
	});
	const options = [{ value: '', label: '– Válassz –' }].concat(
		(records ?? []).map((form) => ({ value: String(form.id), label: decodeEntities(form.title?.rendered ?? '') || '#' + form.id }))
	);
	return el(
		'div',
		useBlockProps(),
		el(SelectControl, {
			label: 'Űrlap',
			value: attributes.formId ? String(attributes.formId) : '',
			options,
			onChange: (value) => setAttributes({ formId: value === '' ? undefined : Number(value) }),
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		})
	);
}

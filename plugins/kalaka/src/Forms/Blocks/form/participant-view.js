/**
 * The form's event's participant fields, in a sidebar of the form's editor: every field with its key, type,
 * source, the forms that hold it and its options, retired fields and options apart. As saved: the form's
 * unsaved changes are not in it.
 */
import { PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { createElement as el, Fragment } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { plainText } from './keys';
import { SOURCES } from './participant';
import { eventFields, formContext } from './participant-store';

const NAME = 'kalaka-participant-fields';
const TITLE = 'Résztvevői mezők';
const NONE = [];

const Option = ({ option }) =>
	option.retired
		? el('li', null, el('del', null, option.label), ' ', el('span', { className: 'kalaka-pf-muted' }, '(visszavonva)'))
		: el('li', null, option.label);

const Field = ({ field }) =>
	el(
		'li',
		{ className: 'kalaka-pf-item' },
		el('strong', null, plainText(field.title)),
		field.retired && el('span', { className: 'kalaka-pf-muted' }, ' (visszavonva)'),
		el(
			'div',
			{ className: 'kalaka-pf-muted' },
			el('code', null, field.key),
			` · ${field.type_label || field.type} · ${SOURCES[field.source] ?? field.source}`
		),
		el(
			'div',
			{ className: 'kalaka-pf-muted' },
			'Űrlapok: ' + ((field.forms ?? []).length > 0 ? field.forms.map((form) => form.title).join(', ') : '–')
		),
		field.options.length > 0 &&
			el(
				'ul',
				{ className: 'kalaka-pf-options' },
				field.options.map((option) => el(Option, { key: option.value, option }))
			)
	);

const FieldList = ({ fields }) =>
	el(
		'ul',
		{ className: 'kalaka-pf-list' },
		fields.map((field) => el(Field, { key: field.key, field }))
	);

function ParticipantFields() {
	const { inForm, eventId, fields } = useSelect(
		(select) => ({ ...formContext(select), fields: eventFields(select) ?? NONE }),
		[]
	);
	if (!inForm || !eventId) {
		return null;
	}
	return el(
		Fragment,
		null,
		el(PluginSidebarMoreMenuItem, { target: NAME, icon: 'list-view' }, TITLE),
		el(
			PluginSidebar,
			{ name: NAME, title: TITLE, icon: 'list-view' },
			el(
				PanelBody,
				null,
				el('p', { className: 'kalaka-pf-muted' }, 'Az esemény összes résztvevői mezője, a mentett állapot szerint.'),
				el(FieldList, { fields: fields.filter((field) => !field.retired) })
			),
			el(
				PanelBody,
				{ title: 'Visszavont mezők', initialOpen: false },
				el(FieldList, { fields: fields.filter((field) => field.retired) })
			)
		)
	);
}

registerPlugin(NAME, { render: ParticipantFields });

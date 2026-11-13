/**
 * The participant-field UI of a field block (owner rulings 2026-11-11 and 2026-11-13): the inspector's
 * "Résztvevői mező" panel (whether the field is new or joins one of the event's fields, the join picker,
 * back to new), the key panel's edit, and in the canvas a marker and a red side rule on a field whose
 * participant field the server would refuse; the problem itself is listed beside the form (`problems.js`).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { Button, ComboboxControl, Flex, Icon, Notice, PanelBody, TextControl, VisuallyHidden } from '@wordpress/components';
import { useRegistry } from '@wordpress/data';
import { createElement as el, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { marker } from './branch';
import { freshKey, plainText } from './keys';
import { draftText, joinable, othersText, problemOf, retiredOfType, SOURCES, statusText } from './participant';
import { askTexts } from './participant-prompts';
import { useParticipant } from './participant-store';

/** The fields this block may join, as a searchable list (title · key · source), retired ones last. */
const Picker = ({ fields, retired, onPick }) =>
	el(ComboboxControl, {
		label: 'Meglévő mező',
		value: null,
		options: fields
			.map((field) => ({
				value: field.key,
				label: `${plainText(field.title)} · ${field.key} · ${SOURCES[field.source] ?? field.source}`,
			}))
			.concat(
				retired.map((field) => ({ value: field.key, label: `${plainText(field.title)} · ${field.key} · Visszavonva` }))
			),
		onChange: (key) => key && onPick(fields.concat(retired).find((field) => field.key === key)),
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	});

/** The problem of a verdict, as a warning notice in the inspector. */
const ProblemNotice = ({ text, id }) =>
	text
		? el(
				'div',
				{ id },
				el(Notice, { status: 'warning', isDismissible: false, politeness: 'assertive' }, text)
		  )
		: null;

/**
 * The "Résztvevői mező" panel: what the block's key is among the event's participant fields, joining one
 * of the same type, and going back to a new field. Nothing without an event.
 */
export function ParticipantPanel({ name, clientId, attributes, setAttributes }) {
	const registry = useRegistry();
	const [picking, setPicking] = useState(false);
	const joinButton = useRef(null);
	const focusJoin = () => window.requestAnimationFrame(() => joinButton.current?.focus());
	const { eventId, fields, type, formKeys, verdictOf } = useParticipant(name, clientId);
	if (!eventId) {
		return null;
	}
	const result = verdictOf(attributes.key);
	// A field without a key yet gets a new one when its title is typed.
	const status = statusText(result.state === 'invalid' ? { state: 'new' } : result);
	const candidates = joinable(fields, type, attributes.key).filter((field) => !formKeys.has(field.key));
	const retired = retiredOfType(fields, type, attributes.key).filter((field) => !formKeys.has(field.key));

	const join = (field) => {
		const patch = { key: field.key };
		if (!plainText(attributes.label)) {
			patch.label = field.title;
		}
		// A choice without options starts from the field's; the form's own option labels are never replaced.
		const options = Array.isArray(attributes.options) ? attributes.options : [];
		if (type === 'kalaka/choice-field' && options.length === 0) {
			patch.options = field.options.filter((option) => !option.retired).map(({ value, label }) => ({ value, label }));
		}
		setAttributes(patch);
		setPicking(false);
		// Focus first, so a question that follows returns the focus here when it closes.
		joinButton.current?.focus();
		askTexts(clientId);
	};
	const backToNew = () => {
		setAttributes({ key: freshKey(registry.select(blockEditorStore), clientId) });
		focusJoin();
	};

	return el(
		PanelBody,
		{ title: 'Résztvevői mező' },
		fields === null
			? el('p', null, '…')
			: el(
					Fragment,
					null,
					status && el('p', { className: 'kalaka-pf-status' }, status),
					result.state === 'join' && el('p', { className: 'kalaka-pf-muted' }, othersText(result.others)),
					el(ProblemNotice, { text: problemOf(result) }),
					el(
						Flex,
						{ justify: 'flex-start', wrap: true },
						el(
							Button,
							{
								ref: joinButton,
								variant: 'secondary',
								onClick: () => setPicking(!picking),
								'aria-expanded': picking,
							},
							'Csatlakozás meglévő mezőhöz'
						),
						['join', 'rejoin', 'clash', 'readOnly'].includes(result.state) &&
							el(Button, { variant: 'tertiary', onClick: backToNew }, 'Új résztvevői mező létrehozása')
					),
					picking &&
						el(
							'div',
							{ className: 'kalaka-pf-picker' },
							candidates.length === 0 && retired.length === 0
								? el('p', null, 'Az eseménynek nincs más ilyen típusú résztvevői mezője.')
								: el(Picker, { fields: candidates, retired, onPick: join })
						)
			  )
	);
}

/**
 * The key (and a choice's option values), hidden unless asked (#49): a closed panel. "Szerkesztés" opens the
 * key for typing; what saving it would do shows as it is typed, and "Kész" sets it. A key of another type or a
 * system field's key can be set (the server refuses the save); a malformed or repeated one cannot.
 */
export function KeyPanel({ name, clientId, attributes, setAttributes }) {
	const [draft, setDraft] = useState(null);
	const editButton = useRef(null);
	const wasEditing = useRef(false);
	const changed = useRef(false);
	useEffect(() => {
		if (draft === null && wasEditing.current) {
			editButton.current?.focus();
			// After the focus is back, so a question that follows returns it here when it closes.
			if (changed.current) {
				changed.current = false;
				askTexts(clientId);
			}
		}
		wasEditing.current = draft !== null;
	}, [draft]);
	const problemId = `kalaka-pf-key-problem-${clientId}`;
	const { verdictOf } = useParticipant(name, clientId);
	const options = Array.isArray(attributes.options) ? attributes.options : [];
	const result = draft === null ? null : verdictOf(draft);
	const problem = result && problemOf(result);
	const blocked = result !== null && ['invalid', 'duplicate'].includes(result.state);
	const done = () => {
		if (draft !== attributes.key) {
			setAttributes({ key: draft });
			changed.current = true;
		}
		setDraft(null);
	};
	return el(
		PanelBody,
		{ title: 'Azonosító', initialOpen: false },
		draft === null
			? el(
					'p',
					null,
					el('code', null, attributes.key ?? '–'),
					' ',
					el(
						Button,
						{ ref: editButton, variant: 'link', onClick: () => setDraft(attributes.key ?? '') },
						'Szerkesztés'
					)
			  )
			: el(
					Fragment,
					null,
					el(TextControl, {
						label: 'Azonosító',
						value: draft,
						onChange: setDraft,
						help: problem ? undefined : draftText(result),
						autoFocus: true,
						'aria-invalid': problem ? true : undefined,
						'aria-describedby': problem ? problemId : undefined,
						onKeyDown: (event) => {
							if (event.key === 'Enter' && !blocked) {
								event.preventDefault();
								done();
							} else if (event.key === 'Escape') {
								event.preventDefault();
								event.stopPropagation();
								setDraft(null);
							}
						},
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					}),
					el(ProblemNotice, { text: problem, id: problemId }),
					el(
						Flex,
						{ justify: 'flex-start' },
						el(
							Button,
							{ variant: 'primary', disabled: blocked, accessibleWhenDisabled: true, onClick: done },
							'Kész'
						),
						el(Button, { variant: 'tertiary', onClick: () => setDraft(null) }, 'Mégse')
					)
			  ),
		options.length > 0 &&
			el(
				'ul',
				null,
				options.map((option, index) =>
					el('li', { key: index }, (option?.label ?? '') + ': ', el('code', null, option?.value ?? '–'))
				)
			)
	);
}

/**
 * The canvas side of a field block whose participant field the server would refuse: `props` for its wrapper
 * (`has-kalaka-problem`, the red side rule) and `marker` above its title, with the problem for screen readers.
 */
export function useFieldCanvas(name, clientId, attributes) {
	const { eventId, verdictOf } = useParticipant(name, clientId);
	const problem = eventId ? problemOf(verdictOf(attributes.key)) : null;
	return {
		props: problem ? { className: 'has-kalaka-problem' } : {},
		marker: problem
			? el(
					'div',
					{ className: 'kalaka-editor-control kalaka-editor-field__bar' },
					el(Icon, { icon: marker, className: 'kalaka-editor-branch__marker', size: 16 }),
					// The marker is decorative; the problem is read out here instead.
					el(VisuallyHidden, null, problem)
			  )
			: null,
	};
}

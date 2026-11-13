/**
 * The two questions the form editor asks about a participant field (owner rulings 2026-11-13), one at a time:
 * - a field block that arrives (pasted or inserted) holding the key of one of the event's participant fields
 *   that the form did not hold: join that field or make a new one (a fresh key, as a copy gets). Joining a
 *   field of another type, or one no form writes, is impossible: the block gets a fresh key and a snackbar
 *   says so. A key is never dropped silently and never joined implicitly;
 * - a field block whose label or option labels were changed (asked when the block loses selection) or that has
 *   just joined a field, when they differ from the field's title or option labels: whether the field's texts
 *   change too (`renameParticipantField`). A form's text never changes the field's by itself; what the author
 *   declines is remembered for that block and text. One question per block lists every difference; with more
 *   than one, each is a checkbox.
 * The decisions are `arrival` and `differences` (`participant.js`).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { Button, CheckboxControl, Flex, Modal, Notice } from '@wordpress/components';
import { createReduxStore, dispatch, register, select, useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { createElement as el, useEffect, useRef, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { registerPlugin } from '@wordpress/plugins';
import { fieldsOf, STORE as EVENT_KEYS_STORE } from './event-keys';
import { copied, isKey, plainText } from './keys';
import { arrival, declining, differences, fieldType, MAX_TEXT, renaming, tooLong } from './participant';
import { blockLabel, eventFields, formContext, formKeysOf, renameParticipantField, verdictOf } from './participant-store';

export const STORE = 'kalaka/participant-prompts';

let asked = 0;

register(
	createReduxStore(STORE, {
		reducer: (state = { queue: [], declined: {} }, action) => {
			switch (action.type) {
				case 'ASK':
					return state.queue.some((item) => item.kind === action.item.kind && item.clientId === action.item.clientId)
						? state
						: { ...state, queue: [...state.queue, action.item] };
				case 'DONE':
					return { ...state, queue: state.queue.filter((item) => item.id !== action.id) };
				case 'DECLINE':
					return { ...state, declined: { ...state.declined, [action.clientId]: action.declined } };
				default:
					return state;
			}
		},
		actions: {
			/** `{ kind: 'arrival', clientId, key }` or `{ kind: 'texts', clientId }`. */
			ask: (item) => ({ type: 'ASK', item: { ...item, id: ++asked } }),
			done: (id) => ({ type: 'DONE', id }),
			/** What the author declined for a block (`declining`): not asked again until the text changes. */
			decline: (clientId, declined) => ({ type: 'DECLINE', clientId, declined }),
		},
		selectors: {
			getCurrent: (state) => state.queue[0] ?? null,
			getDeclined: (state, clientId) => state.declined[clientId] ?? null,
		},
	})
);

/** The field keys of the form as last saved; parsed once per saved content. */
let saved = { content: null, keys: new Set() };
const savedKeys = () => {
	const content = select('core/editor').getCurrentPostAttribute('content');
	if (typeof content !== 'string') {
		return new Set();
	}
	if (content !== saved.content) {
		saved = { content, keys: new Set(fieldsOf(parse(content)).map((block) => block.attributes?.key)) };
	}
	return saved.keys;
};

/**
 * A field block mounted for the first time: one that holds a key the form did not hold when last saved has
 * arrived (pasted or inserted); whether it is asked about is decided once the event's fields are known.
 */
export function arrived(store, clientId) {
	const key = store.getBlockAttributes(clientId)?.key;
	if (isKey(key) && !savedKeys().has(key)) {
		dispatch(STORE).ask({ kind: 'arrival', clientId, key });
	}
}

/** A block's texts as one string: its label and its option labels. */
const textsOf = (attributes) =>
	[attributes?.label, ...(Array.isArray(attributes?.options) ? attributes.options.map((option) => option?.label) : [])]
		.map(plainText)
		.join('\n');

/** After a join, or when a field block loses selection after its texts changed: whether its field's change too. */
export const askTexts = (clientId) => dispatch(STORE).ask({ kind: 'texts', clientId });

export function useTextsWatch(clientId, isSelected) {
	const registry = useRegistry();
	const before = useRef(null);
	useEffect(() => {
		const texts = textsOf(registry.select(blockEditorStore).getBlockAttributes(clientId));
		if (isSelected) {
			before.current = texts;
			return;
		}
		if (before.current !== null && before.current !== texts) {
			askTexts(clientId);
		}
		before.current = null;
	}, [isSelected]);
}

/** The rename's refusal as the organiser reads it. */
const refusalText = (error) =>
	({
		400: 'A résztvevői mezőt nem lehet módosítani: valamelyik szöveg üres vagy hibás.',
		403: 'A résztvevői mezőt nem lehet módosítani: ehhez nincs jogosultságod.',
		404: 'A résztvevői mezőt nem lehet módosítani: nem található.',
		422: 'A résztvevői mezőt nem lehet módosítani: a szövegeit a rendszer vagy egy bővítmény adja.',
	})[error?.data?.status] ?? 'A résztvevői mezőt nem sikerült módosítani.';

/** The buttons of a question: the safe choice first, so it takes the focus. */
const Choices = ({ children }) => el(Flex, { justify: 'flex-end' }, children);

/** Join the field the arrived block's key is, or make a new one. Answered only by a choice. */
function ArrivalQuestion({ label, field, onNew, onJoin }) {
	return el(
		Modal,
		{
			title: 'Csatlakozzon a mező a meglévő résztvevői mezőhöz?',
			focusOnMount: 'firstContentElement',
			isDismissible: false,
			shouldCloseOnEsc: false,
			shouldCloseOnClickOutside: false,
			onRequestClose: () => {},
			aria: { describedby: 'kalaka-pf-question' },
		},
		el('p', { id: 'kalaka-pf-question' }, `A beillesztett „${label}” mező azonosítóját már használja az esemény egyik résztvevői mezője: „${plainText(field.title)}”.`),
		el('p', null, 'Csatlakozáskor ez az űrlap is ebbe a résztvevői mezőbe írja a válaszokat, egyébként új résztvevői mező jön létre.'),
		el(
			Choices,
			null,
			el(Button, { variant: 'secondary', onClick: onNew }, 'Új résztvevői mező létrehozása'),
			el(Button, { variant: 'primary', onClick: onJoin }, 'Csatlakozás')
		)
	);
}

/** A difference as one line: the field's text and the block's. */
const differenceText = ({ kind, from, to }) =>
	`${kind === 'title' ? 'Cím' : 'Lehetőség'}: „${plainText(from)}” → „${plainText(to)}”`;

/**
 * Whether the block's texts become its field's too. Only the title differs: the question names both. More
 * than one difference: each is a checkbox, all ticked; "Igen" carries the ticked ones over, and the others
 * count as declined.
 */
function TextsQuestion({ eventId, field, found, onDone, onDecline }) {
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState(null);
	const [chosen, setChosen] = useState(() => found.map(() => true));
	const no = useRef(null);
	useEffect(() => no.current?.focus(), []);
	const onlyTitle = found.length === 1 && found[0].kind === 'title';
	const picked = found.filter((item, index) => chosen[index]);
	const rename = () => {
		if (picked.some((item) => tooLong(item.to))) {
			setError(`A résztvevői mezőt nem lehet módosítani: egy szöveg legfeljebb ${MAX_TEXT} karakter lehet.`);
			return;
		}
		setBusy(true);
		setError(null);
		renameParticipantField(eventId, field.key, renaming(picked)).then(
			() => onDone(found.filter((item, index) => !chosen[index])),
			(failure) => {
				setBusy(false);
				setError(refusalText(failure));
			}
		);
	};
	return el(
		Modal,
		{
			title: onlyTitle ? 'Módosuljon a résztvevői mező címe is?' : 'Módosuljanak a résztvevői mező szövegei is?',
			focusOnMount: false,
			onRequestClose: () => !busy && onDecline(),
			aria: { describedby: 'kalaka-pf-question' },
		},
		onlyTitle
			? el(
					'p',
					{ id: 'kalaka-pf-question' },
					`A mező kérdése ezen az űrlapon: „${plainText(found[0].to)}”. A résztvevői mező címe: „${plainText(found[0].from)}”.`
			  )
			: el('p', { id: 'kalaka-pf-question' }, 'Ezen az űrlapon más a szöveg, mint a résztvevői mezőben:'),
		!onlyTitle &&
			(found.length === 1
				? el('p', null, differenceText(found[0]))
				: found.map((item, index) =>
						el(CheckboxControl, {
							key: item.kind + (item.value ?? ''),
							label: differenceText(item),
							checked: chosen[index],
							onChange: (checked) => setChosen(chosen.map((was, at) => (at === index ? checked : was))),
							__nextHasNoMarginBottom: true,
						})
				  )),
		el(
			'p',
			null,
			onlyTitle
				? 'A résztvevői mező címe a mezők listájában, a levelekben és a nézetekben jelenik meg. A többi űrlapon a kérdés szövege nem változik.'
				: 'A résztvevői mező címe és lehetőségei a mezők listájában, a levelekben és a nézetekben jelennek meg. A többi űrlapon a kérdés és a lehetőségek szövege nem változik.'
		),
		error && el(Notice, { status: 'error', isDismissible: false }, error),
		el(
			Choices,
			null,
			el(Button, { ref: no, variant: 'tertiary', onClick: onDecline, disabled: busy }, 'Nem'),
			el(
				Button,
				{
					variant: 'primary',
					onClick: rename,
					isBusy: busy,
					disabled: busy || picked.length === 0,
					accessibleWhenDisabled: true,
				},
				'Igen'
			)
		)
	);
}

/** The current question, or nothing; a question that no longer applies is passed over. */
function Prompts() {
	const registry = useRegistry();
	const { done, decline } = useDispatch(STORE);
	const { createNotice } = useDispatch(noticesStore);
	const { updateBlockAttributes, __unstableMarkNextChangeAsNotPersistent } = useDispatch(blockEditorStore);
	const state = useSelect((selectStore) => {
		const item = selectStore(STORE).getCurrent();
		if (!item) {
			return null;
		}
		const { eventId } = formContext(selectStore);
		const store = selectStore(blockEditorStore);
		const attributes = store.getBlockAttributes(item.clientId);
		const fields = eventFields(selectStore);
		return {
			item,
			eventId,
			attributes,
			fields,
			failed: selectStore(EVENT_KEYS_STORE).getState() === 'failed',
			name: store.getBlockName(item.clientId),
			// A string, so the selector's result stays equal while nothing changes.
			formKeys: [...formKeysOf(store, item.clientId)].sort().join(' '),
			result: attributes && fields ? verdictOf(selectStore, item.clientId) : null,
			declined: selectStore(STORE).getDeclined(item.clientId),
		};
	}, []);

	const item = state?.item ?? null;
	let question = null;
	if (state && state.eventId && state.attributes && state.fields) {
		if (item.kind === 'arrival' && state.attributes.key === item.key) {
			question = arrival({
				key: item.key,
				type: fieldType(state.name),
				fields: state.fields,
				formKeys: new Set(state.formKeys === '' ? [] : state.formKeys.split(' ')),
			});
		} else if (item.kind === 'texts') {
			const found = differences({
				label: state.attributes.label,
				options: state.attributes.options,
				result: state.result,
				declined: state.declined,
			});
			question = found.length > 0 ? { kind: 'texts', field: state.result.field, found } : null;
		}
	}
	// Waiting: the event's fields are still being read. Otherwise a question that does not apply is skipped.
	// A failed read waits no longer: the question is skipped, as the canvas already shows the key problem.
	const waiting = state !== null && Boolean(state.eventId) && Boolean(state.attributes) && !state.fields && !state.failed;
	const label = item ? blockLabel(registry.select(blockEditorStore), item.clientId) : '';

	// As a copy's fresh key: part of the change that brought the block, not an undo step of its own.
	const renew = () => {
		__unstableMarkNextChangeAsNotPersistent();
		updateBlockAttributes(item.clientId, copied(registry.select(blockEditorStore), item.clientId));
	};

	useEffect(() => {
		if (!item || waiting) {
			return;
		}
		if (question === null) {
			done(item.id);
		} else if (question.kind === 'fresh') {
			renew();
			createNotice(
				'info',
				// Not joinable: a field of another type, or one no form writes.
				question.field.form_writable
					? `A beillesztett „${label}” mező új résztvevői mező lett: a meglévő „${plainText(question.field.title)}” mező más típusú.`
					: `A beillesztett „${label}” mező új résztvevői mező lett: a meglévő „${plainText(question.field.title)}” mezőt nem űrlap tölti ki.`,
				{ type: 'snackbar' }
			);
			done(item.id);
		}
	}, [item?.id, waiting, question?.kind]);

	if (!item || !question || question.kind === 'fresh') {
		return null;
	}
	if (question.kind === 'ask') {
		return el(ArrivalQuestion, {
			key: item.id,
			label,
			field: question.field,
			onNew: () => {
				renew();
				done(item.id);
			},
			onJoin: () => {
				done(item.id);
				askTexts(item.clientId);
			},
		});
	}
	const declineAll = (items) => decline(item.clientId, declining(state.declined, items));
	return el(TextsQuestion, {
		key: item.id,
		eventId: state.eventId,
		field: question.field,
		found: question.found,
		onDone: (unchosen) => {
			declineAll(unchosen);
			done(item.id);
		},
		onDecline: () => {
			declineAll(question.found);
			done(item.id);
		},
	});
}

registerPlugin('kalaka-participant-prompts', { render: Prompts });

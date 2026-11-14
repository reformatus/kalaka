/**
 * The form's problems beside the form: a panel of the document sidebar listing every condition block the
 * server would refuse and every field block whose participant field it would refuse, each naming the block;
 * a click selects it. In the canvas such a block only has a marker and a red side rule. Only in a form's
 * editor (a `kalaka_form` post). One summary of the problems is announced to screen readers when their
 * number changes (after a pause, not on every keystroke).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { speak } from '@wordpress/a11y';
import { createElement as el, useEffect, useMemo, useRef } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { formProblems, problemRow, problemText, summary } from './branch-condition';
import { fieldProblems } from './participant-store';

/** Announces the number of problems once it has stayed the same for a second. */
function useProblemSummary(inForm, count) {
	const spoken = useRef(0);
	useEffect(() => {
		if (!inForm || count === spoken.current) {
			return undefined;
		}
		const timer = setTimeout(() => {
			spoken.current = count;
			speak(count === 0 ? 'Nincs javítandó.' : `${count} javítandó az űrlapon`);
		}, 1000);
		return () => clearTimeout(timer);
	}, [inForm, count]);
}

function FormProblems() {
	// The selector builds new arrays on every call; it answers a JSON string, so useSelect sees an equal
	// value while nothing changed (no re-render, no "returns different values" warning).
	const snapshot = useSelect((select) => {
		const store = select(blockEditorStore);
		const inForm = select('core/editor').getCurrentPostType() === 'kalaka_form';
		return JSON.stringify({
			inForm,
			problems: formProblems(store, inForm).map(({ clientId, problems }) => {
				const title = getBlockType(store.getBlockName(clientId))?.title ?? '';
				const text = summary(store.getBlockAttributes(clientId)?.condition);
				return {
					clientId,
					// As the label bar writes it: "Ha · key = value".
					label: text === '' ? title : title + ' · ' + text,
					texts: problems.map(problemText),
				};
			}),
			// The participant-field problems of the field blocks: "{title}: {problem}".
			fields: fieldProblems(select),
		});
	}, []);
	const { inForm, problems, fields } = useMemo(() => JSON.parse(snapshot), [snapshot]);
	const { selectBlock } = useDispatch(blockEditorStore);
	useProblemSummary(inForm, problems.reduce((sum, { texts }) => sum + texts.length, 0) + fields.length);
	if (!inForm) {
		return null;
	}
	return el(
		PluginDocumentSettingPanel,
		{ name: 'kalaka-form-problems', title: 'Javítandó az űrlapon', initialOpen: true },
		problems.length === 0 && fields.length === 0
			? el('p', null, 'Nincs javítandó.')
			: el(
					'ul',
					{ 'aria-live': 'polite' },
					problems
						.flatMap(({ clientId, label, texts }) => texts.map((text) => ({ clientId, label, text })))
						.concat(fields)
						.map(({ clientId, label, text }) =>
							el(
								'li',
								{ key: clientId + text },
								el(Button, { variant: 'link', onClick: () => selectBlock(clientId) }, problemRow(label, text))
							)
						)
			  )
	);
}

registerPlugin('kalaka-form-problems', { render: FormProblems });

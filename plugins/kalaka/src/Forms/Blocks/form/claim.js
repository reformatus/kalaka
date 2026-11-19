/**
 * The claim popup (S74, owner ruling 2026-11-17): when a prefilled link's answers meet the person's draft
 * and change it (the form's context carries `draftClaimUrl`), a WordPress Modal opens at page load and asks
 * the person's choice. It cannot be dismissed: no close button, no Esc, no click outside.
 *
 * - Merge comes first, filled, with the initial focus; its line carries the number of fields the link
 *   changes. Then keep_draft, then keep_link, which is red and asks a second time in its own Modal (the
 *   focus starts on „Mégse”; Esc or „Mégse” returns it to the red button).
 * - The choice goes only through the form's store action `actions.resolveDraftClaim(choice, form)`, which
 *   stops the autosave first. When the route takes it, the page reloads, and that load shows the one-line
 *   notice (`view.js`).
 * - A refused claim (`kalaka_draft_claim_refused`) shows the route's text in the Modal's alert line and a
 *   button that reloads the page, which takes the focus and is scrolled into view (on a phone the line and
 *   the button sit low in the sheet). The choices are disabled then: they would only be refused again. Any
 *   other failure shows the form's failure text, and the focus returns to the button that was chosen.
 * - Every button takes WordPress's 40 px size.
 *
 * A classic script on `wp-element` and `wp-components` (the extra entry of `webpack.config.js`), enqueued by
 * `render.php` only while a claim waits. Its texts come from the server (`DraftClaimTexts`).
 */
import {
	createElement as el,
	createRoot,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';

const REFUSED = 'kalaka_draft_claim_refused';

/** The choice sent through the form's store; the view module registers the store before anyone clicks. */
async function sendThroughStore(choice, form) {
	const { store } = await import(
		/* webpackIgnore: true */ '@wordpress/interactivity'
	);

	return store('kalaka/form').actions.resolveDraftClaim(choice, form);
}

/**
 * WordPress's Modal hides the rest of the page from screen readers (`aria-hidden`) but does not mark its
 * dialog `aria-modal`; this ref, on the popup's own content inside the Modal, adds it.
 */
function markModal(content) {
	content?.closest('[role="dialog"]')?.setAttribute('aria-modal', 'true');
}

function focusLater(ref) {
	setTimeout(() => ref.current?.focus(), 0);
}

/**
 * The popup.
 *
 * @param {Object}   props
 * @param {Object}   props.texts   The server's texts (`draftClaimTexts`).
 * @param {string}   props.failure The form's failure text.
 * @param {string}   props.colour  The form's error colour, or ''.
 * @param {Function} props.send    (choice) => Promise<{ok, body}>.
 * @param {Function} props.reload  Reloads the page.
 */
export function ClaimPopup({ texts, failure, colour, send, reload }) {
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [refused, setRefused] = useState(false);
	const [confirming, setConfirming] = useState(false);
	const refs = {
		merge: useRef(null),
		keep_draft: useRef(null),
		keep_link: useRef(null),
	};
	const cancelRef = useRef(null);
	const reloadRef = useRef(null);

	useEffect(() => {
		refs.merge.current?.focus();
	}, []);
	useEffect(() => {
		if (refused) {
			reloadRef.current?.focus();
			reloadRef.current?.scrollIntoView?.({ block: 'nearest' });
		}
	}, [refused]);
	useEffect(() => {
		if (confirming) {
			cancelRef.current?.focus();
		}
	}, [confirming]);

	async function choose(choice) {
		setBusy(true);
		setError('');
		let answer;
		try {
			answer = await send(choice);
		} catch {
			answer = { ok: false, body: null };
		}
		if (answer?.ok) {
			reload();
			return;
		}
		const isRefused = answer?.body?.code === REFUSED;
		setBusy(false);
		setError(
			isRefused && typeof answer.body.message === 'string'
				? answer.body.message
				: failure
		);
		if (isRefused) {
			setRefused(true);
		} else {
			focusLater(refs[choice]);
		}
	}

	function cancel() {
		setConfirming(false);
		focusLater(refs.keep_link);
	}

	const choice = (name, label, line, props) =>
		el(
			'div',
			{ className: 'kalaka-claim__choice' },
			el(
				Button,
				{
					ref: refs[name],
					disabled: busy || refused,
					'aria-describedby': 'kalaka-claim-' + name,
					__next40pxDefaultSize: true,
					...props,
				},
				label
			),
			el(
				'p',
				{ id: 'kalaka-claim-' + name, className: 'kalaka-claim__line' },
				line
			)
		);

	return el(
		'div',
		null,
		el(
			Modal,
			{
				title: texts.title,
				onRequestClose: () => {},
				isDismissible: false,
				shouldCloseOnEsc: false,
				shouldCloseOnClickOutside: false,
				focusOnMount: false,
				aria: { describedby: 'kalaka-claim-intro kalaka-claim-warning' },
				className: 'kalaka-claim-modal',
			},
			el(
				'div',
				{
					ref: markModal,
					className: 'kalaka-claim',
					style: colour ? { '--kalaka-error-color': colour } : undefined,
				},
				el('p', { id: 'kalaka-claim-intro' }, texts.intro),
				el(
					'p',
					{ id: 'kalaka-claim-warning', className: 'kalaka-claim__warning' },
					texts.warning
				),
				el(
					'div',
					{ className: 'kalaka-claim__choices' },
					choice('merge', texts.merge, texts.mergeLine, {
						variant: 'primary',
						onClick: () => choose('merge'),
					}),
					choice('keep_draft', texts.keepDraft, texts.keepDraftLine, {
						variant: 'secondary',
						onClick: () => choose('keep_draft'),
					}),
					choice('keep_link', texts.keepLink, texts.keepLinkLine, {
						variant: 'secondary',
						isDestructive: true,
						onClick: () => setConfirming(true),
					})
				),
				el('p', { className: 'kalaka-claim__error', role: 'alert' }, error),
				refused &&
					el(
						Button,
						{
							ref: reloadRef,
							variant: 'secondary',
							__next40pxDefaultSize: true,
							onClick: () => reload(),
						},
						texts.reload
					)
			)
		),
		confirming &&
			el(
				Modal,
				{
					title: texts.confirmTitle,
					onRequestClose: cancel,
					isDismissible: false,
					shouldCloseOnClickOutside: false,
					focusOnMount: false,
					aria: { describedby: 'kalaka-claim-confirm' },
					className: 'kalaka-claim-modal',
					size: 'small',
				},
				el(
					'div',
					{ ref: markModal, className: 'kalaka-claim' },
					el('p', { id: 'kalaka-claim-confirm' }, texts.confirmText),
					el(
						'div',
						{ className: 'kalaka-claim__confirm' },
						el(
							Button,
							{
								ref: cancelRef,
								variant: 'tertiary',
								disabled: busy,
								__next40pxDefaultSize: true,
								onClick: cancel,
							},
							texts.confirmCancel
						),
						el(
							Button,
							{
								variant: 'primary',
								isDestructive: true,
								disabled: busy,
								__next40pxDefaultSize: true,
								onClick: () => {
									setConfirming(false);
									choose('keep_link');
								},
							},
							texts.confirm
						)
					)
				)
			)
	);
}

/**
 * Opens the popup for the page's form whose link's answers wait for the person's choice; nothing without
 * one.
 *
 * @param {Object}   [options]
 * @param {Function} [options.send]   (choice, form) => Promise<{ok, body}>; the form's store by default.
 * @param {Function} [options.reload] Reloads the page; `location.reload()` by default.
 * @return {Object|null} The popup's React root, or null.
 */
export function start({
	send = sendThroughStore,
	reload = () => window.location.reload(),
} = {}) {
	for (const form of document.querySelectorAll('form[data-wp-context]')) {
		let context;
		try {
			context = JSON.parse(form.getAttribute('data-wp-context'));
		} catch {
			continue;
		}
		if (!context?.draftClaimUrl || !context.draftClaimTexts) {
			continue;
		}
		const host = document.createElement('div');
		document.body.appendChild(host);
		const root = createRoot(host);
		root.render(
			el(ClaimPopup, {
				texts: context.draftClaimTexts,
				failure: context.failure,
				colour: form.style.getPropertyValue('--kalaka-error-color').trim(),
				send: (choice) => send(choice, form),
				reload,
			})
		);

		return root;
	}

	return null;
}

start();

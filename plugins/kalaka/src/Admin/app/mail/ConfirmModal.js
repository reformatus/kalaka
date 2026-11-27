/**
 * The confirmation before a clear: a short text, the act's button and „Mégsem”. While the act runs the dialog does
 * not close and a second press is ignored; a refusal shows the server's text and keeps the dialog. Focus opens on
 * „Mégsem”, as
 * the approved republish dialog does, so Enter never confirms a deletion by itself. [UNAPPROVED N282]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import { problemText } from './format';
import { COMMON } from './texts';

export default function ConfirmModal( { title, text, label, onConfirm, onClose } ) {
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const running = useRef( false );
	const cancel = useRef();
	useEffect( () => {
		const timer = setTimeout( () => cancel.current && cancel.current.focus(), 60 );
		return () => clearTimeout( timer );
	}, [] );
	const go = async () => {
		if ( running.current ) {
			return;
		}
		running.current = true;
		setBusy( true );
		setFailed( null );
		try {
			await onConfirm();
			onClose();
		} catch ( error ) {
			running.current = false;
			setBusy( false );
			setFailed( problemText( error, COMMON.actFail ) );
		}
	};
	return (
		<Modal
			title={ title }
			onRequestClose={ () => ! running.current && onClose() }
			size="medium"
			shouldCloseOnClickOutside={ false }
			focusOnMount={ false }
		>
			<p>{ text }</p>
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ failed }
				</Notice>
			) }
			<div className="km-actions">
				<Button variant="primary" isDestructive onClick={ go } isBusy={ busy } aria-disabled={ busy }>
					{ label }
				</Button>
				<Button variant="tertiary" ref={ cancel } onClick={ onClose } disabled={ busy }>
					{ COMMON.cancel }
				</Button>
			</div>
		</Modal>
	);
}

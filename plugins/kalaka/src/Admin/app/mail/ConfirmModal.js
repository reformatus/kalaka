/**
 * The confirmation before a clear: a short text, the act's button and Mégse. While the act runs the dialog does not
 * close and a second press is ignored; a refusal shows the server's text and keeps the dialog. [UNAPPROVED N282]
 */
import { useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice } from '@wordpress/components';
import { problemText } from './format';
import { COMMON } from './texts';

export default function ConfirmModal( { title, text, label, onConfirm, onClose } ) {
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const running = useRef( false );
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
		<Modal title={ title } onRequestClose={ () => ! running.current && onClose() } size="medium" shouldCloseOnClickOutside={ false }>
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
				<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
					{ COMMON.cancel }
				</Button>
			</div>
		</Modal>
	);
}

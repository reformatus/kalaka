/**
 * The „Levélsablonok” tab: the event's bodies (subject, status in words, the mail that uses it, the last change)
 * with the core editor's edit link, and a new body: a title in a dialog, a draft made through core's own route
 * (`POST /wp/v2/kalaka_mail`, the event in its meta), then the core post editor. The bodies are edited nowhere
 * else. [UNAPPROVED N277] [UNAPPROVED N278]
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import * as api from './api';
import ListTable from './ListTable';
import { bodyStatus, moment, problemText } from './format';
import { BODIES, COMMON } from './texts';

function NewBody( { config, onClose } ) {
	const [ title, setTitle ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ failed, setFailed ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const sending = useRef( false );
	const field = useRef();
	// Focus lands on the subject field, on opening and after an empty submit. [UNAPPROVED N278]
	const focusField = () => {
		const input = field.current && field.current.querySelector( 'input' );
		if ( input ) {
			input.focus();
		}
	};
	useEffect( () => {
		const timer = setTimeout( focusField, 60 );
		return () => clearTimeout( timer );
	}, [] );
	const submit = async ( event ) => {
		event.preventDefault();
		if ( sending.current ) {
			return;
		}
		setFailed( null );
		if ( title.trim() === '' ) {
			setError( BODIES.titleEmpty );
			setTimeout( focusField, 50 );
			return;
		}
		setError( '' );
		sending.current = true;
		setBusy( true );
		try {
			const post = await api.createBody( config.event, title.trim() );
			window.location.assign( `${ config.adminUrl }post.php?post=${ post.id }&action=edit` );
		} catch ( e ) {
			sending.current = false;
			setBusy( false );
			setFailed( problemText( e, COMMON.actFail ) );
		}
	};
	return (
		<Modal
			title={ BODIES.new }
			onRequestClose={ () => ! sending.current && onClose() }
			size="medium"
			shouldCloseOnClickOutside={ false }
			focusOnMount={ false }
		>
			<form ref={ field } onSubmit={ submit } noValidate>
				<TextControl
					label={ BODIES.titleField }
					value={ title }
					onChange={ setTitle }
					help={ error ? <span className="km-err">{ error }</span> : BODIES.hint }
					aria-invalid={ error ? 'true' : undefined }
					__nextHasNoMarginBottom
				/>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				<div className="km-actions">
					<Button variant="primary" type="submit" isBusy={ busy } aria-disabled={ busy }>
						{ BODIES.create }
					</Button>
					<Button variant="tertiary" onClick={ onClose } disabled={ busy }>
						{ COMMON.cancel }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export default function Bodies( { config, data } ) {
	const [ creating, setCreating ] = useState( false );
	const labels = Object.fromEntries( data.mails.map( ( mail ) => [ mail.mail, mail.label ] ) );
	const fields = useMemo(
		() => [
			{
				id: 'subject',
				label: BODIES.subject,
				getValue: ( { item } ) => item.subject,
				render: ( { item } ) => <a href={ item.edit_url } aria-label={ `${ item.subject } – ${ BODIES.edit }` }>{ item.subject }</a>,
				enableHiding: false,
			},
			{ id: 'status', label: BODIES.status, getValue: ( { item } ) => bodyStatus( item.status ), enableHiding: false },
			{
				id: 'used_by',
				label: BODIES.usedBy,
				getValue: ( { item } ) => ( item.used_by ? labels[ item.used_by ] || item.used_by : COMMON.none ),
				enableHiding: false,
			},
			{
				id: 'modified',
				label: BODIES.modified,
				getValue: ( { item } ) => item.modified,
				render: ( { item } ) => moment( item.modified, config.timezone ),
				enableHiding: false,
			},
		],
		[ data ]
	);
	return (
		<div className="km-section">
			<div className="km-toolbar">
				<Button variant="primary" onClick={ () => setCreating( true ) }>
					{ BODIES.new }
				</Button>
			</div>
			<ListTable
				data={ data.bodies }
				fields={ fields }
				getId={ ( item ) => String( item.id ) }
				empty={ <p className="km-empty">{ BODIES.none }</p> }
			/>
			{ creating && <NewBody config={ config } onClose={ () => setCreating( false ) } /> }
		</div>
	);
}

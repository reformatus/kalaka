/**
 * The event page's „Levelek” tab: for each action mail whether it is on, which body it sends and whether it is wrapped
 * in the layout, and below them the event's layout HTML. Everything shown comes from the event's mail read: a switch
 * is offered only as the read's `can` says, and a refused one shows the read's refusal text (the approved texts of
 * `UpdateActionMail`). Each change is saved at once and the page re-reads; a refused act shows the server's text under
 * the mail. [UNAPPROVED N273] [UNAPPROVED N274] [UNAPPROVED N275] [UNAPPROVED N276]
 */
import { Fragment, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextareaControl, ToggleControl } from '@wordpress/components';
import * as api from './api';
import { bodyStatus, problemText } from './format';
import { COMMON, LAYOUT, MAILS } from './texts';

function MailCard( { config, mail, bodies, onChanged, say } ) {
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const act = async ( change ) => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setFailed( null );
		try {
			await api.updateMail( config.event, mail.mail, change );
			say( COMMON.saved );
		} catch ( error ) {
			setFailed( problemText( error, COMMON.actFail ) );
		}
		// Either way the page reads again, so what it shows is what the server holds.
		await onChanged();
		setBusy( false );
	};
	const refusal = mail.enabled ? mail.refusals.switch_off : mail.refusals.switch_on;
	const allowed = mail.enabled ? mail.can.switch_off : mail.can.switch_on;
	return (
		<Card className="km-mail" size="small">
			<CardHeader>
				<strong>{ mail.label }</strong>
			</CardHeader>
			<CardBody>
				<ToggleControl
					label={ MAILS.enabled }
					checked={ mail.enabled }
					disabled={ busy || ! allowed }
					help={ refusal ? refusal.message : undefined }
					onChange={ ( on ) => act( { enabled: on } ) }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ MAILS.body }
					value={ mail.body_id === null ? '' : String( mail.body_id ) }
					disabled={ busy }
					options={ [
						{ value: '', label: MAILS.noBody },
						...bodies.map( ( body ) => ( {
							value: String( body.id ),
							label: body.status === 'publish' ? body.subject : `${ body.subject } (${ bodyStatus( body.status ) })`,
						} ) ),
					] }
					onChange={ ( value ) => act( { body_id: value === '' ? null : parseInt( value, 10 ) } ) }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ MAILS.layout }
					checked={ mail.layout }
					disabled={ busy }
					onChange={ ( on ) => act( { layout: on } ) }
					__nextHasNoMarginBottom
				/>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
			</CardBody>
		</Card>
	);
}

function LayoutEditor( { config, html, onChanged, say } ) {
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( null );
	const value = draft === null ? html : draft;
	const save = async () => {
		if ( busy ) {
			return;
		}
		setBusy( true );
		setFailed( null );
		try {
			await api.saveLayout( config.event, value );
			setDraft( null );
			say( COMMON.saved );
			await onChanged();
		} catch ( error ) {
			setFailed( problemText( error, COMMON.actFail ) );
		}
		setBusy( false );
	};
	return (
		<Card className="km-layout" size="small">
			<CardHeader>
				<strong>{ LAYOUT.title }</strong>
			</CardHeader>
			<CardBody>
				<TextareaControl
					className="km-code"
					label={ LAYOUT.field }
					help={ LAYOUT.help }
					value={ value }
					rows={ 8 }
					onChange={ setDraft }
					__nextHasNoMarginBottom
				/>
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ failed }
					</Notice>
				) }
				<Button variant="primary" onClick={ save } isBusy={ busy } disabled={ draft === null || draft === html } accessibleWhenDisabled>
					{ COMMON.save }
				</Button>
			</CardBody>
		</Card>
	);
}

export default function ActionMails( { config, data, onChanged, say } ) {
	return (
		<Fragment>
			{ data.held && (
				<Notice status="info" isDismissible={ false }>
					{ MAILS.held }
				</Notice>
			) }
			<div className="km-mails">
				{ data.mails.map( ( mail ) => (
					<MailCard
						key={ mail.mail }
						config={ config }
						mail={ mail }
						bodies={ data.bodies }
						onChanged={ onChanged }
						say={ say }
					/>
				) ) }
			</div>
			<LayoutEditor config={ config } html={ data.layout_html } onChanged={ onChanged } say={ say } />
		</Fragment>
	);
}

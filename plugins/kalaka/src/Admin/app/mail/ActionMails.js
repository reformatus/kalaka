/**
 * The event page's „Levelek” tab: for each action mail whether it is on, which body it sends and whether it is wrapped
 * in the layout, and below them the event's layout HTML. Everything shown comes from the event's mail read: a switch
 * is offered only as the read's `can` says, and a refused one shows the read's refusal text (the approved texts of
 * `UpdateActionMail`). Each change is saved at once and the page re-reads; a refused act shows the server's text under
 * the mail. [UNAPPROVED N273] [UNAPPROVED N274] [UNAPPROVED N275] [UNAPPROVED N276]
 *
 * Fix pass 1: a card's controls are one group named by the mail; a switch the read does not allow stays in the tab
 * order (`aria-disabled`, described by the refusal) instead of being `disabled`; the body is chosen in the select and
 * saved by its own „Mentés”, not on every arrow key (N274 changed); a typed layout and a chosen but unsaved body
 * are held by the page, so they survive a change of tab.
 *
 * Fix pass 2: the „Mentés” row has its own, always reserved room (spacing and card height: N274 changed), an unsaved
 * choice says so beside it (T293) and the page asks before it is left with one (`EventPage`, N299).
 */
import { Fragment, useId, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextareaControl, ToggleControl } from '@wordpress/components';
import * as api from './api';
import { bodyLabel, problemText } from './format';
import { COMMON, LAYOUT, MAILS } from './texts';

function MailCard( { config, mail, bodies, onChanged, say, drafts } ) {
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
	// A standing refusal is a state, not an error: the switch looks off-limits but can be reached, and the refusal's
	// text is its description. [UNAPPROVED N273]
	const standing = ! busy && ! allowed;
	const titleId = useId();
	const current = mail.body_id === null ? '' : String( mail.body_id );
	const chosen = drafts.get( 'body:' + mail.mail, current );
	return (
		<Card className="km-mail" size="small">
			<CardHeader>
				<strong id={ titleId }>{ mail.label }</strong>
			</CardHeader>
			<CardBody role="group" aria-labelledby={ titleId }>
				<ToggleControl
					className={ standing ? 'km-standing' : undefined }
					label={ MAILS.enabled }
					checked={ mail.enabled }
					disabled={ busy }
					aria-disabled={ standing ? 'true' : undefined }
					help={ refusal ? refusal.message : undefined }
					onChange={ ( on ) => ( standing ? undefined : act( { enabled: on } ) ) }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ MAILS.body }
					value={ chosen }
					disabled={ busy }
					options={ [
						{ value: '', label: MAILS.noBody },
						...bodies.map( ( body ) => ( { value: String( body.id ), label: bodyLabel( body, bodies ) } ) ),
					] }
					onChange={ ( value ) => drafts.set( 'body:' + mail.mail, value ) }
					__nextHasNoMarginBottom
				/>
				<div className="km-choose">
					{ chosen !== current && (
						<Fragment>
							<span className="km-unsaved">{ MAILS.unsaved }</span>
							<Button
								variant="secondary"
								size="compact"
								isBusy={ busy }
								disabled={ busy }
								accessibleWhenDisabled
								onClick={ async () => {
									await act( { body_id: chosen === '' ? null : parseInt( chosen, 10 ) } );
									drafts.clear( 'body:' + mail.mail );
								} }
							>
								{ MAILS.saveBody }
							</Button>
						</Fragment>
					) }
				</div>
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

function LayoutEditor( { config, html, onChanged, say, drafts } ) {
	const draft = drafts.get( 'layout', null );
	const setDraft = ( value ) => drafts.set( 'layout', value );
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
			drafts.clear( 'layout' );
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

export default function ActionMails( { config, data, onChanged, say, drafts } ) {
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
						drafts={ drafts }
					/>
				) ) }
			</div>
			<LayoutEditor config={ config } html={ data.layout_html } onChanged={ onChanged } say={ say } drafts={ drafts } />
		</Fragment>
	);
}

/**
 * The event's „Levelek” page: three tabs in core's TabPanel, the action mails with the layout, the bodies and the past
 * manual batches (the failed deliveries are on „Levelezés”: the owner's ruling puts the queue lists there). One read (`GET /events/{id}/mail`) serves the first two. A saved change is a
 * snackbar. The tab is kept in the address (`&tab=`). [UNAPPROVED N272]
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { SnackbarList, TabPanel } from '@wordpress/components';
import ActionMails from './ActionMails';
import Batches from './Batches';
import Bodies from './Bodies';
import useDrafts, { hasUnsaved } from './drafts';
import useRead from './useRead';
import { COMMON, TABS } from './texts';
import { SNACKBAR_MS, withNotice, withoutNotice } from '../grid/snack';

const KEYS = [ 'mails', 'bodies', 'batches' ];

export default function EventPage( { config } ) {
	const read = useRead( config.rest, `/events/${ config.event }/mail` );
	const drafts = useDrafts();
	const [ notices, setNotices ] = useState( [] );
	const timers = useRef( [] );
	const say = ( text ) => {
		const id = String( Date.now() ) + Math.random();
		setNotices( ( list ) => withNotice( list, id, text, 'success' ) );
		timers.current.push( setTimeout( () => setNotices( ( list ) => withoutNotice( list, id ) ), SNACKBAR_MS ) );
	};
	const asked = new URLSearchParams( window.location.search ).get( 'tab' );
	const initial = KEYS.includes( asked ) ? asked : 'mails';

	// Leaving the page (a reload, another admin page, closing the tab) with a choice or a layout not saved: the browser
	// asks, in its own words. A change of tab inside the page keeps the entries and asks nothing.
	const unsaved = hasUnsaved( drafts.values, read.data );
	useEffect( () => {
		if ( ! unsaved ) {
			return undefined;
		}
		const ask = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', ask );
		return () => window.removeEventListener( 'beforeunload', ask );
	}, [ unsaved ] );

	if ( read.loading && ! read.data ) {
		return <p>{ COMMON.loading }</p>;
	}
	if ( ! read.data ) {
		return (
			<p className="km-fail" role="alert">
				{ COMMON.loadFail }
			</p>
		);
	}
	const data = read.data;
	return (
		<div className="km-page">
			<TabPanel
				className="km-tabs"
				initialTabName={ initial }
				onSelect={ ( name ) => {
					const url = new URL( window.location.href );
					url.searchParams.set( 'tab', name );
					window.history.replaceState( null, '', url.toString() );
				} }
				tabs={ KEYS.map( ( name ) => ( { name, title: TABS[ name ] } ) ) }
			>
				{ ( tab ) => {
					if ( tab.name === 'mails' ) {
						return <ActionMails config={ config } data={ data } onChanged={ read.reload } say={ say } drafts={ drafts } />;
					}
					if ( tab.name === 'bodies' ) {
						return <Bodies config={ config } data={ data } />;
					}
					return <Batches config={ config } />;
				} }
			</TabPanel>
			<div className="km-snacks">
				<SnackbarList
					notices={ notices }
					onRemove={ ( id ) => setNotices( ( list ) => withoutNotice( list, id ) ) }
				/>
			</div>
		</div>
	);
}

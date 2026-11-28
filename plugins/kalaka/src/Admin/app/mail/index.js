/**
 * The mail pages' app (prototype), mounted on `#kalaka-mail` with `window.kalakaMail` (`Admin\Mail\MailPages`): the
 * event's „Levelek” page or the site's „Levelezés” page, by `kind`. DataViews is bundled, as for the grid.
 */
import { createRoot } from '@wordpress/element';
import 'kalaka-dataviews-style';
import './mail.css';
import './dialog.css';
import EventPage from './EventPage';
import SenderPage from './SenderPage';

const root = document.getElementById( 'kalaka-mail' );
const config = window.kalakaMail;
if ( root && config ) {
	createRoot( root ).render( config.kind === 'sender' ? <SenderPage config={ config } /> : <EventPage config={ config } /> );
}

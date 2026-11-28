/**
 * The mail pages' app (prototype), mounted on `#kalaka-mail` with `window.kalakaMail` (`Admin\Mail\MailPages`): the
 * site's „Levelezés” page. The event's „Levelek” page is PHP's (`Admin\Mail\EventPage`); its dynamic parts are
 * `event-page.js`. DataViews is bundled, as for the grid.
 */
import { createRoot } from '@wordpress/element';
import 'kalaka-dataviews-style';
import './mail.css';
import './dialog.css';
import SenderPage from './SenderPage';

const root = document.getElementById( 'kalaka-mail' );
const config = window.kalakaMail;
if ( root && config && config.kind === 'sender' ) {
	createRoot( root ).render( <SenderPage config={ config } /> );
}

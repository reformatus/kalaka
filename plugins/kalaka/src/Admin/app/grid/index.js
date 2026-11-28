/**
 * The grid page's app (S37), mounted on `#kalaka-grid` with `window.kalakaGrid` (`Admin\Grid\GridPage`).
 * DataViews is bundled: WordPress does not ship it to plugins.
 */
import { createRoot } from '@wordpress/element';
import 'kalaka-dataviews-style';
import './grid.css';
import '../mail/dialog.css';
import Page from './Page';

const root = document.getElementById( 'kalaka-grid' );
if ( root && window.kalakaGrid ) {
	createRoot( root ).render( <Page config={ window.kalakaGrid } /> );
}

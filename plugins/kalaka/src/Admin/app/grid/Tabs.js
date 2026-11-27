/**
 * The view tabs, in core's TabPanel like the mail page's. A tab switch goes through `onSelect` (no page load); the
 * table stays below, so the panel itself has no content. There is no „Több ▾”: many tabs scroll sideways in one row
 * (grid.css, owner ruling 9.2). [UNAPPROVED N918] [UNAPPROVED N919 reboxed: the tab row is one sideways-scrolling row]
 */
import { TabPanel } from '@wordpress/components';

export const tabUrl = ( id ) => {
	const url = new URL( window.location.href );
	url.searchParams.set( 'view', String( id ) );
	return url.toString();
};

export default function Tabs( { tabs, current, onSelect } ) {
	return (
		<TabPanel
			className="kg-tabs"
			initialTabName={ current }
			onSelect={ onSelect }
			tabs={ tabs.map( ( tab ) => ( { name: tab.key, title: tab.name } ) ) }
		>
			{ () => null }
		</TabPanel>
	);
}

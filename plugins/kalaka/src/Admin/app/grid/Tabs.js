/**
 * The view tabs (`nav-tab`), with the ones that do not fit in „Több ▾”. The active tab always stays
 * visible: when it would overflow, it takes the last visible place.
 */
import { useCallback, useLayoutEffect, useRef, useState } from '@wordpress/element';
import { Dropdown, MenuItem, NavigableMenu } from '@wordpress/components';
import { GRID } from './texts';

export const tabUrl = ( id ) => {
	const url = new URL( window.location.href );
	url.searchParams.set( 'view', String( id ) );
	return url.toString();
};

export default function Tabs( { tabs, current, onSelect } ) {
	const wrap = useRef();
	const measure = useRef();
	const [ fit, setFit ] = useState( tabs.length );

	const recompute = useCallback( () => {
		if ( ! wrap.current || ! measure.current ) {
			return;
		}
		const available = wrap.current.clientWidth;
		const widths = Array.from( measure.current.children ).map(
			( el ) => el.getBoundingClientRect().width + 6
		);
		const moreWidth = widths.pop(); // the last measured child is the „Több” toggle
		let used = 0;
		let n = 0;
		for ( let i = 0; i < widths.length; i++ ) {
			const needMore = i < widths.length - 1 ? moreWidth : 0;
			if ( used + widths[ i ] + needMore > available && n > 0 ) {
				break;
			}
			used += widths[ i ];
			n++;
		}
		setFit( Math.max( 1, n ) );
	}, [] );

	useLayoutEffect( () => {
		recompute();
		const observer = new window.ResizeObserver( recompute );
		observer.observe( wrap.current );
		return () => observer.disconnect();
	}, [ recompute, tabs ] );

	let visible = tabs.slice( 0, fit );
	let extra = tabs.slice( fit );
	const activeIndex = tabs.findIndex( ( t ) => t.key === current );
	if ( activeIndex >= fit && fit > 0 ) {
		visible = tabs.slice( 0, fit - 1 ).concat( tabs[ activeIndex ] );
		extra = tabs.filter( ( t ) => ! visible.includes( t ) );
	}

	const link = ( tab, measuring = false ) => (
		<a
			key={ tab.key }
			href={ tabUrl( tab.key ) }
			className={ 'nav-tab' + ( tab.key === current ? ' nav-tab-active' : '' ) }
			aria-current={ tab.key === current ? 'page' : undefined }
			tabIndex={ measuring ? -1 : undefined }
			onClick={
				measuring
					? undefined
					: ( event ) => {
							event.preventDefault();
							onSelect( tab.key );
					  }
			}
		>
			{ tab.name }
		</a>
	);

	return (
		<div className="kg-tabs-wrap" ref={ wrap }>
			<div className="kg-tabs-measure" ref={ measure } aria-hidden="true">
				{ tabs.map( ( t ) => link( t, true ) ) }
				<span className="nav-tab">{ GRID.more + ' ▾' }</span>
			</div>
			<nav className="nav-tab-wrapper kg-tabs" aria-label={ GRID.tabsLabel }>
				{ visible.map( ( t ) => link( t ) ) }
				{ extra.length > 0 && (
					<Dropdown
						className="kg-more"
						popoverProps={ { placement: 'bottom-start' } }
						renderToggle={ ( { isOpen, onToggle } ) => (
							<button
								type="button"
								className="nav-tab kg-more-toggle"
								aria-expanded={ isOpen }
								aria-haspopup="menu"
								title={ GRID.moreLabel }
								onClick={ onToggle }
							>
								{ GRID.more + ' ▾' }
							</button>
						) }
						renderContent={ ( { onClose } ) => (
							<NavigableMenu role="menu" aria-label={ GRID.moreLabel }>
								{ extra.map( ( t ) => (
									<MenuItem
										key={ t.key }
										onClick={ () => {
											onClose();
											onSelect( t.key );
										} }
									>
										{ t.name }
									</MenuItem>
								) ) }
							</NavigableMenu>
						) }
					/>
				) }
			</nav>
		</div>
	);
}

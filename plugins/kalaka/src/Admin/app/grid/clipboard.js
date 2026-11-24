/**
 * Puts an HTML table and its plain text on the clipboard (S37). In a secure context (https, localhost)
 * through the Clipboard API; on plain http, where it is missing, through a selected hidden table and
 * `execCommand( 'copy' )`, with a copy listener that sets both flavours exactly. Throws unless one of them
 * really succeeded.
 *
 * @param {string} text the tab-separated text
 * @param {string} html the HTML table
 */
export async function writeClipboard( text, html ) {
	const { ClipboardItem } = window;
	if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.write && ClipboardItem ) {
		await navigator.clipboard.write( [
			new ClipboardItem( {
				'text/html': new window.Blob( [ html ], { type: 'text/html' } ),
				'text/plain': new window.Blob( [ text ], { type: 'text/plain' } ),
			} ),
		] );
		return;
	}
	const box = document.createElement( 'div' );
	box.setAttribute( 'aria-hidden', 'true' );
	box.style.cssText = 'position:fixed;top:-10000px;left:0;opacity:0;pointer-events:none;';
	box.innerHTML = html;
	document.body.appendChild( box );
	const active = document.activeElement;
	const selection = window.getSelection();
	const range = document.createRange();
	range.selectNodeContents( box );
	selection.removeAllRanges();
	selection.addRange( range );
	const onCopy = ( event ) => {
		event.clipboardData.setData( 'text/html', html );
		event.clipboardData.setData( 'text/plain', text );
		event.preventDefault();
	};
	document.addEventListener( 'copy', onCopy );
	let ok = false;
	try {
		ok = document.execCommand( 'copy' );
	} finally {
		document.removeEventListener( 'copy', onCopy );
		selection.removeAllRanges();
		box.remove();
		if ( active && active.focus ) {
			active.focus();
		}
	}
	if ( ! ok ) {
		throw new Error( 'copy failed' );
	}
}

/**
 * A read of the mail routes as a hook: `{ data, error, loading, reload }`. `reload()` reads again without blanking
 * the screen (the old data stays until the new arrives), so an act's result is seen in place.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { read } from './api';

export default function useRead( rest, path ) {
	const [ state, setState ] = useState( { data: null, error: null, loading: true } );
	const counter = useRef( 0 );
	const load = useCallback( () => {
		const mine = ++counter.current;
		return read( rest, path ).then(
			( data ) => {
				if ( mine === counter.current ) {
					setState( { data, error: null, loading: false } );
				}
				return data;
			},
			( error ) => {
				if ( mine === counter.current ) {
					setState( ( old ) => ( { data: old.data, error, loading: false } ) );
				}
				return null;
			}
		);
	}, [ rest, path ] );
	useEffect( () => {
		load();
		return () => {
			counter.current++;
		};
	}, [ load ] );
	return { ...state, reload: load };
}

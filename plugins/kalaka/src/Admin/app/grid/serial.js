/**
 * One task at a time, in call order. A task that fails, however it fails, never stops the ones after it.
 *
 * @return {Function} ( task: () => Promise|any ) => Promise that settles when the task has run
 */
export function serial() {
	let last = Promise.resolve();
	return ( task ) => {
		last = last.then( task ).catch( () => {} );
		return last;
	};
}

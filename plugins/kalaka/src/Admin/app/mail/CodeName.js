/**
 * A registration in the mail screens' lists: the code in its own mark, and the name beside it when the read gives
 * one („00A1 · Kiss Anna”); without a name, the code alone.
 */
export default function CodeName( { code, name } ) {
	return (
		<>
			<span className="km-code-text">{ code }</span>
			{ name ? ` · ${ name }` : '' }
		</>
	);
}

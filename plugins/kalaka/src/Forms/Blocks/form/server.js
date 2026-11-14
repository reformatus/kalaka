/**
 * What the server tells the form editor, so the editor copies none of it (`Kalaka\Forms\Module::editorData()`,
 * printed before the editor script as `window.kalakaFormEditor`):
 * - `fieldTypes`: the registered field-type block names (`Contributions::fieldTypes()`, extension field
 *   types included), each with the value type of its answer (`valueType()`);
 * - `eventTypes`: the event's keys a form's condition may read (`Api\EventValues::TYPES`);
 * - `afterSubmitTypes`: what the after-submit section's conditions may read (`Api\AfterSubmit::TYPES`);
 * - `hidden`: the gated blocks to hide in this editor (`Engine\BlockGate::hiddenHere()`; none in a form's).
 * - `dateReference`: the bounds of a date reference's moves (`Condition\DateReference::MAX_YEARS`,
 *   `MAX_DAYS`).
 *
 * Read when used, never at import, so the rules stay testable on their own (Jest's setup provides it).
 */

const data = () => globalThis.kalakaFormEditor ?? {};

const map = (value) => (value !== null && typeof value === 'object' ? value : {});

/** Field block name → the value type of its answer ('text' | 'date' | 'choice' | 'number'). */
export const fieldTypes = () => map(data().fieldTypes);

/** Event key → value type. */
export const eventTypes = () => map(data().eventTypes);

/** After-submit key → value type. */
export const afterSubmitTypes = () => map(data().afterSubmitTypes);

/** `{ maxYears, maxDays }`: how far a date reference may move, either way. */
export const dateReferenceBounds = () => {
	const bounds = map(data().dateReference);

	return {
		maxYears: Number.isInteger(bounds.maxYears) ? bounds.maxYears : 0,
		maxDays: Number.isInteger(bounds.maxDays) ? bounds.maxDays : 0,
	};
};

/** The gated block names to hide in this editor; none when the server sent none. */
export const hiddenBlocks = () => {
	const names = data().hidden;
	return Array.isArray(names) ? names.filter((name) => typeof name === 'string') : [];
};

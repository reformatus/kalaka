/**
 * What the server tells the form editor, so the editor copies none of it (`Kalaka\Forms\Module::editorData()`,
 * printed before the editor script as `window.kalakaFormEditor`):
 * - `fieldTypes`: the registered field-type block names (`Contributions::fieldTypes()`, extension field
 *   types included), each with the value type of its answer (`valueType()`);
 * - `pathFieldTypes`: the field-type block names whose value is a path (`Api\PathFieldType`), which a
 *   condition never compares with `equals`;
 * - `eventTypes`: the keys a form's condition may read besides its fields, for the edited form's event and
 *   placement (`EventFormHost::typesOf()`: the event's keys, the places left, the choice windows, the custom
 *   points, and for an additional form the registration's status and stored values); the event keys alone
 *   (`Api\EventValues::FORM_TYPES`) when the editor holds no saved form of an event;
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

/** The field block names whose value is a path: `equals` is refused on them. */
export const pathFieldTypes = () => {
	const names = data().pathFieldTypes;
	return Array.isArray(names) ? names.filter((name) => typeof name === 'string') : [];
};

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

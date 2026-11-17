/**
 * The scale field's range in the editor, apart from its components so it can be tested without them.
 */

/**
 * The scale's bounds, as `ScaleFieldType` checks them: 2 to 11 points, from a first point the editor keeps
 * within ±100.
 */
const MIN_POINTS = 2;
const MAX_POINTS = 11;
const LOWEST = -100;
const HIGHEST = 100;

const clamp = (value, low, high) => Math.min(Math.max(value, low), high);

/**
 * The settings a change of `min` or `max` makes: the changed one as typed (within the editor's bounds), the
 * other moved only as far as it must for 2 to 11 points. Null when the text is not an integer.
 *
 * @param {{min: number, max: number}} range  The scale's current first and last point.
 * @param {'min'|'max'}                which  The setting changed.
 * @param {string}                     text   The control's text.
 * @return {{min: number, max: number}|null} The new settings.
 */
export function rangeChange(range, which, text) {
	if (!/^-?[0-9]+$/.test(text.trim())) {
		return null;
	}
	const value = Number.parseInt(text, 10);
	if (which === 'min') {
		const min = clamp(value, LOWEST, HIGHEST - MIN_POINTS + 1);
		return { min, max: clamp(range.max, min + MIN_POINTS - 1, min + MAX_POINTS - 1) };
	}
	const max = clamp(value, LOWEST + MIN_POINTS - 1, HIGHEST);
	return { min: clamp(range.min, max - MAX_POINTS + 1, max - MIN_POINTS + 1), max };
}

/** The scale's points, from its first to its last. */
export const pointsOf = ({ min, max }) => Array.from({ length: max - min + 1 }, (unused, index) => min + index);

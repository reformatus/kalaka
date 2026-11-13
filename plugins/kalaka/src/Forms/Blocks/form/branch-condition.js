/**
 * The condition of `kalaka/if` and `kalaka/else-if` as hand-written JSON, checked in the editor by the same
 * rules as the server's `Condition\ConditionParser` and `Condition\FormScope`, so a mistake shows as a
 * notice instead of making the public form unusable. The server stays the judge; nothing here evaluates
 * a condition. No WordPress imports, so it is tested alone. The notice texts are proposed text. The type
 * lists and the date reference's bounds come from the server (`server.js`).
 */

import {
  afterSubmitTypes,
  dateReferenceBounds,
  eventTypes,
  fieldTypes,
} from "./server";

/** The text of a condition for the textarea; empty when there is none. */
export const conditionText = (condition) =>
  condition && typeof condition === "object"
    ? JSON.stringify(condition, null, 2)
    : "";

/**
 * The condition the text stands for: `{ condition }` (undefined for an empty text), or null when the
 * text is not a JSON object.
 */
export const parseCondition = (text) => {
  if (text.trim() === "") {
    return { condition: undefined };
  }
  try {
    const value = JSON.parse(text);
    return value && typeof value === "object" && !Array.isArray(value)
      ? { condition: value }
      : null;
  } catch {
    return null;
  }
};

export const BRANCH_IF = "kalaka/if";
export const BRANCH_ELSE_IF = "kalaka/else-if";
export const BRANCH_ELSE = "kalaka/else";

export const AFTER_SUBMIT = "kalaka/after-submit";

/** A key whose type the editor cannot know (a condition outside a form): any operator and value pass. */
const ANY = "any";

const MATCH = ["all", "any"];
const OPERATORS = [
  "equals",
  "lessThan",
  "lessOrEqual",
  "greaterThan",
  "greaterOrEqual",
  "contains",
  "empty",
];

const isObject = (value) =>
  value !== null && typeof value === "object" && !Array.isArray(value);
const onlyKeys = (value, allowed) =>
  Object.keys(value).every((key) => allowed.includes(key));

const allowedFor = (operator, type) =>
  type === ANY || type === "number" || type === "date"
    ? operator !== "contains"
    : ["equals", "contains", "empty"].includes(operator);

const wellFormed = (type, value) => {
  if (type === ANY) {
    return true;
  }
  if (type === "number") {
    return /^-?[0-9]+(\.[0-9]+)?$/.test(value);
  }
  if (type === "date") {
    const parts = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value);
    if (!parts) {
      return false;
    }
    const day = new Date(0);
    day.setUTCFullYear(+parts[1], +parts[2] - 1, +parts[3]);
    return (
      day.getUTCFullYear() === +parts[1] &&
      day.getUTCMonth() === +parts[2] - 1 &&
      day.getUTCDate() === +parts[3]
    );
  }
  return type === "choice" ? value !== "" : true;
};

/** The problems a check reports; each has its notice text (proposed). */
export const PROBLEMS = {
  json: "A feltétel nem érvényes JSON-objektum.",
  empty: "Hiányzik a feltétel.",
  shape: "A feltétel szerkezete hibás.",
  key: (key) =>
    `A feltétel olyan azonosítóra hivatkozik, amely nem szerepel előtte az űrlapon: ${key}`,
  value: (key) => `A feltétel értéke nem érvényes ehhez a mezőhöz: ${key}`,
  orphan: "Ez a blokk csak „Ha” vagy „Különben ha” blokk után állhat.",
};

const fail = (problem, key) => {
  throw { problem, key };
};

/**
 * Checks a condition against a scope (`typeOf(key)` → 'text' | 'date' | 'choice' | 'number' | null for a
 * key it may not read; `choices(key)` → the option values of a choice field, or null). Returns null, or
 * `{ problem, key }` with the first problem.
 */
export const checkCondition = (condition, scope) => {
  if (condition === undefined || condition === null) {
    return { problem: "empty" };
  }
  const typeOf = (key) => {
    const type = scope.typeOf(key);
    return type ?? fail("key", key);
  };
  const reference = (data, key) => {
    if (!onlyKeys(data, ["key", "years", "days"])) {
      fail("value", key);
    }
    const years = data.years ?? 0;
    const days = data.days ?? 0;
    if (
      typeof data.key !== "string" ||
      !Number.isInteger(years) ||
      !Number.isInteger(days)
    ) {
      fail("value", key);
    }
    const { maxYears, maxDays } = dateReferenceBounds();
    if (Math.abs(years) > maxYears || Math.abs(days) > maxDays) {
      fail("value", key);
    }
    if (![ANY, "date"].includes(typeOf(data.key))) {
      fail("value", key);
    }
  };
  const comparison = (data) => {
    if (!onlyKeys(data, ["key", "operator", "value", "not"])) {
      fail("shape");
    }
    const { key, operator, not = false } = data;
    if (
      typeof key !== "string" ||
      !OPERATORS.includes(operator) ||
      typeof not !== "boolean"
    ) {
      fail("shape");
    }
    const type = typeOf(key);
    if (!allowedFor(operator, type)) {
      fail("value", key);
    }
    if (operator === "empty") {
      if ("value" in data) {
        fail("value", key);
      }
      return;
    }
    if ((type === "date" || type === ANY) && isObject(data.value)) {
      reference(data.value, key);
      return;
    }
    if (typeof data.value !== "string" || !wellFormed(type, data.value)) {
      fail("value", key);
    }
    const choices = type === "choice" ? scope.choices(key) : null;
    if (choices && !choices.includes(data.value)) {
      fail("value", key);
    }
  };
  const group = (data, top) => {
    if (
      !isObject(data) ||
      !onlyKeys(data, top ? ["match", "items"] : ["match", "items", "not"])
    ) {
      fail("shape");
    }
    const { match, items, not = false } = data;
    if (
      !MATCH.includes(match) ||
      !Array.isArray(items) ||
      items.length === 0 ||
      typeof not !== "boolean"
    ) {
      fail("shape");
    }
    for (const item of items) {
      if (!isObject(item)) {
        fail("shape");
      }
      const isComparison = "operator" in item;
      const isGroup = "items" in item;
      if (isComparison === isGroup || (isGroup && !top)) {
        fail("shape");
      }
      if (isComparison) {
        comparison(item);
      } else {
        group(item, false);
      }
    }
  };
  try {
    group(condition, true);
    return null;
  } catch (error) {
    if (error && typeof error.problem === "string") {
      return error;
    }
    throw error;
  }
};

/** The notice text of a problem. */
export const problemText = ({ problem, key }) =>
  typeof PROBLEMS[problem] === "function"
    ? PROBLEMS[problem](key)
    : PROBLEMS[problem];

/**
 * A row of the problems panel, "{block}: {problem}". The problem texts are sentences (a notice shows them
 * alone), so after the colon their first letter is made lower-case, as Hungarian writes it; a text that
 * starts with an acronym (two capitals) stays as it is.
 */
export const problemRow = (label, text) => {
  const [first = "", second = ""] = [...text];
  const acronym =
    second !== second.toLocaleLowerCase("hu") &&
    second === second.toLocaleUpperCase("hu");
  return `${label}: ${acronym ? text : first.toLocaleLowerCase("hu") + text.slice(first.length)}`;
};

/**
 * The scope of a condition block, as the server builds it for where the block stands:
 * - inside `kalaka/after-submit`, the declared after-submit keys (`DeclaredScope(AfterSubmit::TYPES)`), no fields;
 * - elsewhere in a form, `FormScope`: the event's keys and the fields before the chain the block belongs
 *   to, in document order (never one inside the chain);
 * - outside a form (`inForm` false), a scope the editor cannot know: its fact source declares it on the
 *   server, so every key passes here and only the shape is checked.
 * `store` is the block editor's selectors.
 */
export const scopeOf = (store, clientId, inForm = true) => {
  if (store.getBlockParentsByBlockName(clientId, AFTER_SUBMIT).length > 0) {
    return {
      typeOf: (key) => afterSubmitTypes()[key] ?? null,
      choices: () => null,
    };
  }
  if (!inForm) {
    return { typeOf: () => ANY, choices: () => null };
  }
  let head = clientId;
  while (store.getBlockName(head) !== BRANCH_IF) {
    const previous = store.getPreviousBlockClientId(head);
    if (
      !previous ||
      ![BRANCH_IF, BRANCH_ELSE_IF].includes(store.getBlockName(previous))
    ) {
      break;
    }
    head = previous;
  }
  const fields = {};
  for (const id of store.getClientIdsWithDescendants()) {
    if (id === head) {
      break;
    }
    const type = fieldTypes()[store.getBlockName(id)];
    const attributes = store.getBlockAttributes(id) ?? {};
    if (type && typeof attributes.key === "string") {
      fields[attributes.key] = {
        type,
        choices: Array.isArray(attributes.options)
          ? attributes.options.map((o) => o?.value)
          : null,
      };
    }
  }
  const events = eventTypes();
  return {
    typeOf: (key) => events[key] ?? fields[key]?.type ?? null,
    choices: (key) => (events[key] ? null : (fields[key]?.choices ?? null)),
  };
};

/** Whether a `kalaka/else-if` or `kalaka/else` stands directly after a `kalaka/if` or `kalaka/else-if`. */
export const followsBranch = (store, clientId) => {
  const previous = store.getPreviousBlockClientId(clientId);
  return (
    !!previous &&
    [BRANCH_IF, BRANCH_ELSE_IF].includes(store.getBlockName(previous))
  );
};

const SYMBOLS = {
  equals: "=",
  lessThan: "<",
  lessOrEqual: "≤",
  greaterThan: ">",
  greaterOrEqual: "≥",
  contains: "∋",
  empty: "= ∅",
};

/** A short summary of a condition for the list view and the breadcrumb: `key = value, …`; '' when none. */
export const summary = (condition) => {
  if (!isObject(condition) || !Array.isArray(condition.items)) {
    return "";
  }
  const part = (item) => {
    if (!isObject(item)) {
      return "";
    }
    if (Array.isArray(item.items)) {
      return "(" + summary(item) + ")";
    }
    const value = isObject(item.value) ? item.value.key : (item.value ?? "");
    return [
      item.not ? "¬" : "",
      item.key,
      SYMBOLS[item.operator] ?? item.operator,
      value,
    ]
      .join(" ")
      .trim();
  };
  return condition.items
    .map(part)
    .join(condition.match === "any" ? " | " : " & ");
};

/**
 * What is wrong with a condition block, as problems (`{ problem, key }`); none when it is fine. `inForm`:
 * whether the editor holds a form (see `scopeOf`).
 */
export const problemsOf = (store, clientId, inForm = true) => {
  const name = store.getBlockName(clientId);
  const problems = [];
  if (name !== BRANCH_IF && !followsBranch(store, clientId)) {
    problems.push({ problem: "orphan" });
  }
  if (name !== BRANCH_ELSE) {
    const problem = checkCondition(
      store.getBlockAttributes(clientId)?.condition,
      scopeOf(store, clientId, inForm),
    );
    if (problem) {
      problems.push(problem);
    }
  }
  return problems;
};

/** Every condition block with problems, in document order: `[{ clientId, problems }]`. */
export const formProblems = (store, inForm = true) =>
  store
    .getClientIdsWithDescendants()
    .filter((id) =>
      [BRANCH_IF, BRANCH_ELSE_IF, BRANCH_ELSE].includes(store.getBlockName(id)),
    )
    .map((clientId) => ({
      clientId,
      problems: problemsOf(store, clientId, inForm),
    }))
    .filter(({ problems }) => problems.length > 0);

import {
  checkCondition,
  followsBranch,
  formProblems,
  parseCondition,
  problemRow,
  problemText,
  scopeOf,
  summary,
} from "../../src/Forms/Blocks/form/branch-condition";

/** Siblings in document order (a flat form): the selectors the condition checks read. */
const editor = (blocks) => ({
  getClientIdsWithDescendants: () => blocks.map((b) => b.clientId),
  getBlockName: (id) => blocks.find((b) => b.clientId === id)?.name ?? null,
  getBlockAttributes: (id) =>
    blocks.find((b) => b.clientId === id)?.attributes ?? null,
  getPreviousBlockClientId: (id) => {
    const index = blocks.findIndex((b) => b.clientId === id);
    return index > 0 ? blocks[index - 1].clientId : null;
  },
  // A block's `parent` names its ancestor block (one level is enough here).
  getBlockParentsByBlockName: (id, name) => {
    const parent = blocks.find((b) => b.clientId === id)?.parent;
    return parent && blocks.find((b) => b.clientId === parent)?.name === name
      ? [parent]
      : [];
  },
});

const form = editor([
  { clientId: "name", name: "kalaka/name-field", attributes: { key: "nev" } },
  {
    clientId: "born",
    name: "kalaka/date-field",
    attributes: { key: "szuletes" },
  },
  {
    clientId: "stay",
    name: "kalaka/choice-field",
    attributes: {
      key: "szallas",
      options: [{ value: "igen" }, { value: "nem" }],
    },
  },
  { clientId: "if", name: "kalaka/if", attributes: {} },
  { clientId: "elseif", name: "kalaka/else-if", attributes: {} },
  { clientId: "else", name: "kalaka/else", attributes: {} },
  { clientId: "p", name: "core/paragraph", attributes: {} },
  { clientId: "orphan", name: "kalaka/else", attributes: {} },
  {
    clientId: "later",
    name: "kalaka/text-field",
    attributes: { key: "kesobbi" },
  },
]);

const equals = (key, value) => ({
  match: "all",
  items: [{ key, operator: "equals", value }],
});

describe("condition JSON", () => {
  test("an object is the condition, an empty text none, anything else is refused", () => {
    expect(parseCondition('{"match":"all","items":[]}')).toEqual({
      condition: { match: "all", items: [] },
    });
    expect(parseCondition("  ")).toEqual({ condition: undefined });
    expect(parseCondition('{"match":')).toBeNull();
    expect(parseCondition("[1]")).toBeNull();
    expect(parseCondition("3")).toBeNull();
  });
});

describe("condition checks, as ConditionParser and FormScope", () => {
  const scope = scopeOf(form, "elseif");

  test("a well-formed condition over earlier fields and event keys passes", () => {
    expect(checkCondition(equals("szallas", "igen"), scope)).toBeNull();
    expect(
      checkCondition(
        {
          match: "any",
          items: [
            { key: "nev", operator: "contains", value: "a", not: true },
            {
              match: "all",
              not: true,
              items: [{ key: "szuletes", operator: "empty" }],
            },
            {
              key: "szuletes",
              operator: "lessThan",
              value: { key: "event.start", years: -14 },
            },
            {
              key: "event.end",
              operator: "greaterOrEqual",
              value: "2027-01-31",
            },
          ],
        },
        scope,
      ),
    ).toBeNull();
  });

  test.each([
    ["no condition", undefined, "empty"],
    [
      "an unknown match",
      { match: "some", items: [{ key: "nev", operator: "empty" }] },
      "shape",
    ],
    ["no items", { match: "all", items: [] }, "shape"],
    [
      "an extra top-level key",
      { match: "all", items: [{ key: "nev", operator: "empty" }], not: true },
      "shape",
    ],
    [
      "an unknown operator",
      { match: "all", items: [{ key: "nev", operator: "is", value: "x" }] },
      "shape",
    ],
    [
      "a group nested twice",
      {
        match: "all",
        items: [{ match: "all", items: [{ match: "all", items: [] }] }],
      },
      "shape",
    ],
    ["a later field", equals("kesobbi", "x"), "key"],
    ["an unknown key", equals("event.place", "x"), "key"],
    ["an option the field lacks", equals("szallas", "talan"), "value"],
    [
      "lessThan on text",
      {
        match: "all",
        items: [{ key: "nev", operator: "lessThan", value: "b" }],
      },
      "value",
    ],
    [
      "contains on a date",
      {
        match: "all",
        items: [{ key: "szuletes", operator: "contains", value: "2010" }],
      },
      "value",
    ],
    ["a date that does not exist", equals("szuletes", "2026-02-30"), "value"],
    [
      "empty with a value",
      { match: "all", items: [{ key: "nev", operator: "empty", value: "" }] },
      "value",
    ],
    [
      "a reference to a text",
      {
        match: "all",
        items: [{ key: "szuletes", operator: "equals", value: { key: "nev" } }],
      },
      "value",
    ],
  ])("%s is refused", (label, condition, problem) => {
    expect(checkCondition(condition, scope)?.problem).toBe(problem);
  });

  test("every problem has a notice text, the key named where there is one", () => {
    expect(problemText({ problem: "key", key: "kesobbi" })).toBe(
      "A feltétel olyan azonosítóra hivatkozik, amely nem szerepel előtte az űrlapon: kesobbi",
    );
    expect(problemText({ problem: "value", key: "szallas" })).toBe(
      "A feltétel értéke nem érvényes ehhez a mezőhöz: szallas",
    );
    expect(problemText({ problem: "orphan" })).toBe(
      "Ez a blokk csak „Ha” vagy „Különben ha” blokk után állhat.",
    );
    expect(problemText({ problem: "shape" })).toBe(
      "A feltétel szerkezete hibás.",
    );
    expect(problemText({ problem: "empty" })).toBe("Hiányzik a feltétel.");
  });
});

describe("chains", () => {
  test("an else-if or else must directly follow an if or else-if", () => {
    expect(followsBranch(form, "elseif")).toBe(true);
    expect(followsBranch(form, "else")).toBe(true);
    expect(followsBranch(form, "orphan")).toBe(false);
    expect(followsBranch(form, "name")).toBe(false);
  });

  test("a summary names the comparisons", () => {
    expect(summary(equals("szallas", "igen"))).toBe("szallas = igen");
    expect(
      summary({
        match: "any",
        items: [
          { key: "nev", operator: "empty", not: true },
          {
            key: "szuletes",
            operator: "lessThan",
            value: { key: "event.start" },
          },
        ],
      }),
    ).toBe("¬ nev = ∅ | szuletes < event.start");
    expect(summary(undefined)).toBe("");
  });
});

describe("dates as the server reads them", () => {
  test("a year below 100 is a valid date, as checkdate() accepts it", () => {
    const scope = { typeOf: () => "date", choices: () => null };
    expect(checkCondition(equals("nap", "0050-01-01"), scope)).toBeNull();
    expect(checkCondition(equals("nap", "0050-02-30"), scope)).toEqual({
      problem: "value",
      key: "nap",
    });
  });
});

describe("the scope of where a condition stands", () => {
  const withSection = editor([
    { clientId: "name", name: "kalaka/name-field", attributes: { key: "nev" } },
    { clientId: "section", name: "kalaka/after-submit", attributes: {} },
    { clientId: "after", name: "kalaka/if", attributes: {}, parent: "section" },
    { clientId: "main", name: "kalaka/if", attributes: {} },
  ]);
  const status = equals("registration.status", "waitlisted");

  test("inside the after-submit section: the event and registration keys, no fields (AfterSubmit::TYPES)", () => {
    const scope = scopeOf(withSection, "after");
    expect(checkCondition(status, scope)).toBeNull();
    expect(
      checkCondition(equals("registration.code", "AB12"), scope),
    ).toBeNull();
    expect(checkCondition(equals("event.name", "Tábor"), scope)).toBeNull();
    expect(
      checkCondition(
        { match: "all", items: [{ key: "nev", operator: "empty" }] },
        scope,
      ),
    ).toEqual({
      problem: "key",
      key: "nev",
    });
  });

  test("in the form itself the registration keys are not readable (FormScope)", () => {
    expect(checkCondition(status, scopeOf(withSection, "main"))).toEqual({
      problem: "key",
      key: "registration.status",
    });
  });

  test("outside a form every key passes and only the shape is checked", () => {
    const scope = scopeOf(withSection, "main", false);
    expect(checkCondition(status, scope)).toBeNull();
    expect(
      checkCondition(
        {
          match: "all",
          items: [{ key: "x", operator: "lessThan", value: { key: "y" } }],
        },
        scope,
      ),
    ).toBeNull();
    expect(
      checkCondition(
        { match: "all", items: [{ key: "x", operator: "nope", value: "1" }] },
        scope,
      ),
    ).toEqual({
      problem: "shape",
    });
  });

  test("the form\u2019s problems follow the same scopes", () => {
    const filled = editor([
      { clientId: "section", name: "kalaka/after-submit", attributes: {} },
      {
        clientId: "after",
        name: "kalaka/if",
        attributes: { condition: status },
        parent: "section",
      },
    ]);
    expect(formProblems(filled)).toEqual([]);
  });
});

describe("the form\u2019s problems", () => {
  test("every condition block with a problem, in document order", () => {
    expect(formProblems(form)).toEqual([
      { clientId: "if", problems: [{ problem: "empty" }] },
      { clientId: "elseif", problems: [{ problem: "empty" }] },
      { clientId: "orphan", problems: [{ problem: "orphan" }] },
    ]);
  });
});

describe("a row of the problems panel", () => {
  test("starts the problem after the colon with a lower-case letter, as Hungarian writes it", () => {
    expect(problemRow("Ha · kor ≥ 18", "Hiányzik a feltétel.")).toBe(
      "Ha · kor ≥ 18: hiányzik a feltétel.",
    );
    expect(
      problemRow(
        "Különben ha",
        "Ez a blokk csak „Ha” vagy „Különben ha” blokk után állhat.",
      ),
    ).toBe(
      "Különben ha: ez a blokk csak „Ha” vagy „Különben ha” blokk után állhat.",
    );
    expect(
      problemRow(
        "Név",
        "Ezt a résztvevői mezőt nem űrlap tölti ki, ezért űrlap nem csatlakozhat hozzá: „Név”.",
      ),
    ).toBe(
      "Név: ezt a résztvevői mezőt nem űrlap tölti ki, ezért űrlap nem csatlakozhat hozzá: „Név”.",
    );
    expect(problemRow("Ha", "Új szöveg")).toBe("Ha: új szöveg");
  });

  test("leaves a text that starts with an acronym as it is", () => {
    expect(problemRow("Ha", "JSON-hiba.")).toBe("Ha: JSON-hiba.");
  });
});

describe("what the server tells the editor (server.js)", () => {
  const reference = (years, days) => ({
    match: "all",
    items: [
      {
        key: "szuletes",
        operator: "lessThan",
        value: { key: "event.start", years, days },
      },
    ],
  });

  test.each([
    [200, 0],
    [-200, 0],
    [0, 3660],
    [0, -3660],
  ])(
    "a date reference within DateReference's bounds passes (%i years, %i days)",
    (years, days) => {
      expect(
        checkCondition(reference(years, days), scopeOf(form, "elseif")),
      ).toBeNull();
    },
  );

  test.each([
    [201, 0],
    [-201, 0],
    [0, 3661],
    [0, -3661],
  ])(
    "a date reference beyond DateReference's bounds is a value problem (%i years, %i days)",
    (years, days) => {
      expect(
        checkCondition(reference(years, days), scopeOf(form, "elseif")),
      ).toEqual({ problem: "value", key: "szuletes" });
    },
  );

  test("an extension field type the server names is a field of the scope, with its value type", () => {
    const before = globalThis.kalakaFormEditor;
    globalThis.kalakaFormEditor = {
      ...before,
      fieldTypes: { ...before.fieldTypes, "acme/rating-field": "number" },
    };
    try {
      const scope = scopeOf(
        editor([
          {
            clientId: "rating",
            name: "acme/rating-field",
            attributes: { key: "ertekeles" },
          },
          { clientId: "if", name: "kalaka/if", attributes: {} },
        ]),
        "if",
      );
      expect(
        checkCondition(
          {
            match: "all",
            items: [{ key: "ertekeles", operator: "greaterThan", value: "3" }],
          },
          scope,
        ),
      ).toBeNull();
    } finally {
      globalThis.kalakaFormEditor = before;
    }
  });
});

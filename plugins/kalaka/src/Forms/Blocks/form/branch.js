/**
 * The shared editor piece of the condition blocks (events-and-forms.md, "Conditions"): the branch's
 * blocks, under the block's title, and for `kalaka/if` and `kalaka/else-if` the condition as hand-written
 * JSON in the inspector (pickers come later). A branch the server would refuse (an else without its if, a
 * missing or malformed condition) is marked in the canvas and listed beside the form (`problems.js`), since
 * it would make the public form unusable.
 */
import {
  InnerBlocks,
  InspectorControls,
  store as blockEditorStore,
  useBlockProps,
  useInnerBlocksProps,
} from "@wordpress/block-editor";
import { getBlockType } from "@wordpress/blocks";
import {
  Icon,
  Notice,
  PanelBody,
  Path,
  SVG,
  TextareaControl,
  VisuallyHidden,
} from "@wordpress/components";
import { useSelect } from "@wordpress/data";
import {
  createElement as el,
  Fragment,
  useEffect,
  useState,
} from "@wordpress/element";
import {
  conditionText,
  parseCondition,
  problemsOf,
  problemText,
  PROBLEMS,
  summary,
} from "./branch-condition";

/**
 * The textarea's text per block, kept while the block is deselected (the inspector unmounts), with the
 * condition it was last in step with: when the attribute changes otherwise (undo, redo), the text follows.
 */
const drafts = new Map();
const signature = (condition) => JSON.stringify(condition ?? null);

/**
 * The condition as hand-written JSON: a textarea and, while its text is not a JSON object, the notice. Also
 * the page block's (`form-page/edit.js`), with its own label.
 */
export function ConditionText({ clientId, attributes, setAttributes, label }) {
  const [text, setText] = useState(
    () => drafts.get(clientId)?.text ?? conditionText(attributes.condition),
  );
  const current = signature(attributes.condition);

  useEffect(() => {
    const draft = drafts.get(clientId);
    if (!draft || draft.condition !== current) {
      const next = conditionText(attributes.condition);
      drafts.set(clientId, { text: next, condition: current });
      setText(next);
    }
  }, [clientId, current]);

  const change = (next) => {
    setText(next);
    const parsed = parseCondition(next);
    const condition = parsed ? signature(parsed.condition) : current;
    drafts.set(clientId, { text: next, condition });
    if (parsed !== null) {
      setAttributes({ condition: parsed.condition });
    }
  };
  return el(
    Fragment,
    null,
    el(TextareaControl, {
      label,
      className: "kalaka-editor-condition-json",
      value: text,
      onChange: change,
      rows: 10,
      __nextHasNoMarginBottom: true,
    }),
    parseCondition(text) === null &&
      el(Notice, { status: "error", isDismissible: false }, PROBLEMS.json),
  );
}

const ConditionPanel = (props) =>
  el(
    PanelBody,
    { title: "Feltétel" },
    el(ConditionText, { ...props, label: "JSON" }),
  );

/** What is wrong with the branch, as notice texts (none when it is fine). */
function useProblems(clientId, condition) {
  return useSelect(
    (select) =>
      problemsOf(
        select(blockEditorStore),
        clientId,
        select("core/editor")?.getCurrentPostType?.() === "kalaka_form",
      ).map(problemText),
    [clientId, condition],
  );
}

/** The marker of a block with problems: an exclamation mark in a circle (the editor's own icon shape). */
export const marker = el(
  SVG,
  { xmlns: "http://www.w3.org/2000/svg", viewBox: "0 0 24 24" },
  el(Path, {
    d: "M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 14.5a6.5 6.5 0 1 1 0-13 6.5 6.5 0 0 1 0 13ZM11.25 7.5h1.5V14h-1.5V7.5Zm0 8h1.5V17h-1.5v-1.5Z",
  }),
);

/**
 * The editor of a condition block; `withCondition` for `kalaka/if` and `kalaka/else-if`. Everything but the
 * branch's own blocks is control text (`kalaka-editor-control`): the label bar with the block's title and
 * a summary of its condition, and a marker when it has problems, which the document panel lists
 * (`problems.js`); the block also gets a red side rule (`has-kalaka-problem`, editor.css).
 */
export const branchEdit = (withCondition) =>
  function Edit({ name, clientId, attributes, setAttributes }) {
    const problems = useProblems(clientId, attributes.condition);
    const blockProps = useBlockProps({
      className:
        "kalaka-editor-branch" +
        (problems.length > 0 ? " has-kalaka-problem" : ""),
    });
    const hasBlocks = useSelect(
      (select) => select(blockEditorStore).getBlockCount(clientId) > 0,
      [clientId],
    );
    // The "+" button only while the branch is empty; then the editor's own appender, as core's Group does.
    const innerBlocksProps = useInnerBlocksProps(
      { className: "kalaka-editor-branch__blocks" },
      {
        renderAppender: hasBlocks ? undefined : InnerBlocks.ButtonBlockAppender,
      },
    );
    const text = summary(attributes.condition);
    return el(
      "div",
      blockProps,
      withCondition &&
        el(
          InspectorControls,
          null,
          el(ConditionPanel, { clientId, attributes, setAttributes }),
        ),
      el(
        "div",
        { className: "kalaka-editor-control kalaka-editor-branch__label" },
        el("span", null, getBlockType(name)?.title),
        text !== "" &&
          el(
            "span",
            { className: "kalaka-editor-branch__summary" },
            " · " + text,
          ),
        problems.length > 0 &&
          el(Icon, {
            icon: marker,
            className: "kalaka-editor-branch__marker",
            size: 16,
          }),
        // The marker is decorative; its problems are read out here instead.
        problems.length > 0 &&
          el(VisuallyHidden, null, " " + problems.join(" ")),
      ),
      el("div", innerBlocksProps),
    );
  };

/** A condition, page or after-submit block saves only its blocks; the server renders it. */
export const branchSave = () => el(InnerBlocks.Content);

/** The label in the list view, breadcrumb and toolbar: the title, and a short summary of the condition. */
export const branchLabel = (title) => (attributes) => {
  const text = summary(attributes.condition);
  return text === "" ? title : `${title}: ${text}`;
};

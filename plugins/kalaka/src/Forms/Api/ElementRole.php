<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * The element roles of participant-side markup that a theme adapter can add classes to
 * (modules-and-extensions.md, "The extension surface"). A closed list; core names a role and applies
 * the adapter's classes to the element that plays it.
 */
enum ElementRole: string
{
    /** [UNAPPROVED N12] The form's own wrapper, around all its fields (rendered by the form block and the form page). */
    case Form = 'form';
    /** The wrapper of one field (`div`, or `p` when the adapter chooses it for a single-control field). */
    case Field = 'field';
    /** A field's title: the `label` of a single control, the `legend` of a choice, the `label` of a consent. */
    case Label = 'label';
    /** A text-like control: `input` (text, email, tel, date) or `textarea`. */
    case TextControl = 'text_control';
    /** The `fieldset` of a choice. */
    case ChoiceGroup = 'choice_group';
    /** The `label` around one option of a choice. */
    case Option = 'option';
    /** A radio button or checkbox: an option of a choice, or the consent's checkbox. */
    case ChoiceControl = 'choice_control';
    /** The required marker after a required field's title. */
    case RequiredMarker = 'required_marker';
    /** The element that holds a field's error message. */
    case ErrorMessage = 'error_message';
    /** The form's submit button (rendered by the form front end). */
    case SubmitButton = 'submit_button';
}

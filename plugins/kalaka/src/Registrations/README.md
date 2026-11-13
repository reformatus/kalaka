# Registrations

Registrations, field values, submissions, presets, statuses, waitlist, reference codes and derived facts.

At the module root: `Registration` and `RegistrationRepository` (the only writer of `kalaka_registrations`, keyed by event ID and reference code), `RegistrationList` (the registrations of an event with their computed sorszám), `ReferenceCode` (the validity rule), `ReferenceCodeGenerator` and `ReferenceCodeQuery` (issuing a code), `SecretLink` (the secret of a registration's link), `SubmitRegistration` (the use case `kalaka-portal/submit-registration`: capacity, the "full" latch, the waitlist, the reference code, repeated submits) and `RegistrationCount` (the places an event's registrations take). `FieldValue/` holds `FieldValue` and `FieldValueRepository` (the only writer of `kalaka_field_values`, one row per answered field), `Submission/` holds `Submission`, `SubmissionRepository` (the only writer of `kalaka_submissions`, append-only) and `FormSubmissionQuery`.

`Api/` holds `RegistrationKey`, `RegistrationStatus`, `Registrations` (`lock()`: reads a registration `FOR UPDATE`, so the run holds it), and the submit seam: `RegistrationSubmit` (`submit(SubmitRequest, Actor): SubmitResult`: checks the answers before the transaction, then runs the use case), `SubmitRequest`, `SubmitResult`, `SubmitToken` (the form page's token against repeated submits) and `SubmitTokenReused` (a token spent on other answers) and `FormSubmissions` (whether a form has submissions, for Forms' delete and event guards).

Tables, created by the schema in `Module.php`: `kalaka_registrations`, `kalaka_field_values`, `kalaka_submissions`.

Entry points: none yet; the form page (S12) calls `Api\RegistrationSubmit`.

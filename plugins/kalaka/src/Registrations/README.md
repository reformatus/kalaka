# Registrations

Registrations, field values, submissions, presets, statuses, waitlist, reference codes and derived facts.

At the module root: `Registration` and `RegistrationRepository` (the only writer of `kalaka_registrations`, keyed by event ID and reference code), `RegistrationList` (the registrations of an event with their computed sorszám), `ReferenceCode` (the validity rule), `ReferenceCodeGenerator` and `ReferenceCodeQuery` (issuing a code), `SecretLink` (the secret of a registration's link). `FieldValue/` holds `FieldValue` and `FieldValueRepository` (the only writer of `kalaka_field_values`, one row per answered field), `Submission/` holds `Submission` and `SubmissionRepository` (the only writer of `kalaka_submissions`, append-only).

`Api/` holds `RegistrationKey`, `RegistrationStatus` and `Registrations` (`lock()`: reads a registration `FOR UPDATE`, so the run holds it).

Tables, created by the schema in `Module.php`: `kalaka_registrations`, `kalaka_field_values`, `kalaka_submissions`.

Entry points: none yet.

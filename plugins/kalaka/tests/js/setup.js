// Jest setup (see transform.cjs).
// jsdom has no navigation timing, which the Interactivity API reads when it starts: report that the
// document has loaded, so that it hydrates as soon as a test loads it.
performance.getEntriesByType = () => [{ domContentLoadedEventStart: 1 }];

// What the server prints before the form editor's script (`Kalaka\Forms\Module::editorData()`, read by
// `server.js`), as `FieldTypeRegistryTest` checks the server gives it on a site with only the core field types.
globalThis.kalakaFormEditor = require('../fixtures/form-editor.json');

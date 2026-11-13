// Jest setup (see transform.cjs).
// jsdom has no navigation timing, which the Interactivity API reads when it starts: report that the
// document has loaded, so that it hydrates as soon as a test loads it.
performance.getEntriesByType = () => [{ domContentLoadedEventStart: 1 }];

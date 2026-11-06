# Support

The organization directory, the congregation picker and the support offers: one extension plugin, `kalaka-support` (module `support`). So far it holds the directory: a site-wide tree of typed nodes with an external ID, aliases and an active flag, imported from CSV, deactivated and never deleted.

Table, created by the schema in `Module.php`: `kalaka_support_nodes`; only `NodeRepository` writes it.

`Directory/` holds `Node` (the stored node), `NodeRepository`, the admin use case `SaveDirectoryNode` (`kalaka/save-directory-node`; needs full access), `NodeList` (the route's list), `NodeLookup` (the import's reads of stored nodes) and `NodeTreeQuery` (`ancestors()` and `descendants()`, one `WITH RECURSIVE` statement each).

Entry points: `Directory/Rest/NodesController` (`GET /kalaka-support/v1/nodes`, public, live nodes only) and `Directory/Cli/DirectoryCommand` (`wp kalaka-support import <file>`, which runs the use case per node through `Directory/Cli/DirectoryImport`; the file has one column per level, headed by the level's node type, then optional `id`, `aliases` and `active`).

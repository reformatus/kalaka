# Kaláka

Kaláka is a WordPress plugin: an integrated registration system for events and camps. This is the core plugin; features beyond the core are separate extension plugins in the same repository.

## Layout

- The layout is domain-first. Each module has a folder under `src/`.
- `Module.php` is the module's entry point and the only place where it hooks into WordPress. `Module.php` and `README.md` are the two files to open first.
- The module's main noun and its use cases sit at the module root.
- Every other noun gets its own folder, with its own record, repository, list and use cases. A use case lives with the noun it changes; if it changes several, with the main one.
- The same rules apply recursively inside noun folders, including noun-only WordPress entry-point folders.
- The only folders that are not nouns are:
  - `Api/`: the PHP contract for other modules and extensions. This is not the REST API.
  - WordPress entry points, named as WordPress names them and created only when needed: `Rest/`, `Cli/`, `Blocks/<block-name>/` (`block.json`, `render.php`, `edit.js`, `view.js`, as `create-block` lays them out).
- Repositories are named `<Entity>Repository`.
- Extension plugins have the same shape.
- Layers are not folders. deptrac checks the `Api/` boundary: a module uses other modules only through their `Api/`.

## WordPress concept to file

| WordPress concept | File |
|---|---|
| Plugin header | `kalaka.php` |
| Uninstall | `uninstall.php` |
| Boot on `plugins_loaded` and on activation; core modules and their boot order | `kalaka.php` -> `src/Foundation/Boot.php` |
| Extension registration action `kalaka_register_modules` | `src/Foundation/Boot.php` |
| Schema (`dbDelta`) | `src/Foundation/SchemaUpgrader.php`, option `kalaka_schema_<id>` |
| REST routes | `<Module>/Rest/` (none yet) |
| WP-CLI | `<Module>/Cli/` (none yet) |
| Blocks | `<Module>/Blocks/<name>/`, built into `build/` (none yet) |

## Modules

| Module | Namespace | Entry points |
|---|---|---|
| Foundation | `Kalaka\Foundation` | `plugins_loaded` boot, `kalaka_register_modules` |
| Events | `Kalaka\Events` | none yet |
| Forms | `Kalaka\Forms` | none yet |
| Registrations | `Kalaka\Registrations` | none yet |
| Pricing | `Kalaka\Pricing` | none yet |
| Ledger | `Kalaka\Ledger` | none yet |
| Payments | `Kalaka\Payments` | none yet |
| Mail | `Kalaka\Mail` | none yet |
| Portal | `Kalaka\Portal` | none yet |
| Admin | `Kalaka\Admin` | none yet |

## Extension plugin signature

An extension plugin has the header line `Requires Plugins: kalaka` and registers its module on the `kalaka_register_modules` action:

```php
add_action('kalaka_register_modules', static function (Kalaka\Foundation\Api\Module\Modules $modules): void {
    $modules->add(new Kalaka\WooCommerce\Module());
});
```

The module's `requiresApi()` declares the supported core API range, for example `'>=0.1 <0.2'`. Only `>=` and `<` are used.

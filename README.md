# Kaláka

Event and camp registration for WordPress, replacing a Google Sheets /
Forms / Apps Script system used by two organizations (REFISZ and IVK).

**Current phase:** implementation started: the core plugin skeleton.

## Repository map

| Path | What it is |
|---|---|
| `plugins/kalaka/` | The core plugin; its `README.md` describes the layout |
| `docs/` | Published documentation (GitHub Pages) |
| `docs/architecture/` | Architecture pages: the authoritative record of decisions |
| `AGENTS.md` | Instructions for AI agents: the binding rule and a topic map into the architecture pages |
| `.pipeline/roles/` | Role prompts for the AI development pipeline |
| `.wp-env.json`, `package.json` | Local WordPress + WooCommerce dev environment (`npm run env start`) |
| `internal/` | Git-ignored: working drafts, spikes, tools, source material. Never pushed |

## Ground rules

- Today's behavior is the default reference; every deviation is decided explicitly.
- UI language: Hungarian only, hard-coded, no translation layer.
- Architecture changes are pull requests that edit `docs/architecture/`.
- Source sheets and exports contain personal data of minors: they live only
  in `internal/` and are never committed.

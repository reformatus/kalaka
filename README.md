# Kaláka (wp-campmanager)

*Product name Kaláka, code prefix `kalaka_` (D-040). Other plugin, module and
code names are placeholders.*

Event and camp registration for WordPress, replacing a Google Sheets /
Forms / Apps Script system used by two organizations (REFISZ and IVK).

**Current phase:** core architecture and foundation decided (open points marked in the decision log); implementation not started.

## Repository map

| Path | What it is |
|---|---|
| `docs/` | Published documentation (GitHub Pages): overview, core architecture, decision log |
| `docs/decisions.md` | Decision log: the authoritative record |
| `docs/architecture.md` | Core architecture |
| `.wp-env.json`, `package.json` | Local WordPress + WooCommerce dev environment (`npm run env start`) |
| `internal/` | Git-ignored: working drafts, spikes, tools, source material. Never pushed |

## Ground rules

- Today's behavior is the default reference; every deviation is decided explicitly (D-024).
- UI language: Hungarian only, hard-coded, no translation layer (D-023).
- Source sheets and exports contain personal data of minors: they live only
  in `internal/` and are never committed.

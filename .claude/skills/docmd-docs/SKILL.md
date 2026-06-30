---
name: docmd-docs
description: >-
  Build, extend, and maintain the public documentation site for laravel-iam-client, which lives in
  docs-site/ and is generated with docmd (https://docs.docmd.io). Use this skill whenever you work inside
  docs-site/ — adding or editing a page under docs-site/docs/**, touching navigation or plugins in
  docmd.config.json, changing the brand/CSS, or keeping the docs in sync with a feature change in src/. It
  covers the directory layout, the dev/build/check commands, the docmd container syntax (callouts, tabs,
  steps, grids, collapsible, mermaid, KaTeX), Lucide icons, the semantic-search setup, the footer/branding,
  the Cloudflare Pages deploy model, and the accuracy + page-structure standards for this package.
---

# docmd docs for laravel-iam-client

The published docs site is in `docs-site/` and deploys to
`https://doc.laravel-iam-client.padosoft.com` (Cloudflare Pages, Git integration). Brand teal `#0d9488`.

## Golden rule: accuracy first

Document only what the code in `src/` actually does. The real surface of this package is the
**authorization decision client** — there is **no** OIDC login, JWT/JWKS verification, introspection or
webhook receiver in `src/` (the composer description mentions some aspirationally; do not document them).
Cite real symbols: `IamClient`, `Iam` facade, `DecisionRequest`, `IamDecision`, `Decider` +
`LocalDecider`/`HttpDecider`/`CachingDecider`, `IamGateAdapter`, `IamCan`/`IamAuthenticate` middleware,
`IamClientServiceProvider`, and the real config keys. The decision endpoint is the SLASH form
`POST {base}/decisions/check`; the server wraps responses in `{ "data": {...} }` (HttpDecider unwraps it).

## Layout

```
docs-site/
  docmd.config.json          # title, url, navigation (the ONLY sidebar source), theme, plugins
  package.json               # scripts: dev / build / check
  package-lock.json          # lockfile v3 (cross-platform, Linux natives) — verify with `npm ci`, do not regenerate
  .node-version              # 20
  .gitignore                 # ignores _site/, node_modules/, .docmd-search/* (keeps config.json)
  .docmd-search/config.json  # pinned embedding model (committed) — skips the interactive wizard in CI
  assets/favicon.svg, custom.css   # brand #0d9488
  scripts/check-no-raw-html.mjs    # CI guard
  docs/**                    # pages; route = path (docs/guides/foo.md => /guides/foo); docs/index.md => /
  _site/                     # build output (git-ignored)
```

Route rule: `docs/a/b.md` becomes `/a/b`. Every page MUST be listed in `navigation[]` in
`docmd.config.json` or it will not appear in the sidebar (no auto-generation). Icons are Lucide names in
kebab-case (https://lucide.dev).

## Commands (run inside docs-site/)

```bash
npm ci          # install from the committed lockfile (preferred over npm install)
npm run check   # guard: fails on raw HTML/MDX tags or ::: button
npm run build   # generates _site/ (semantic index, sitemap, llms.txt, robots.txt)
npm run dev     # local preview
```

Completion = `npm run check` and `npm run build` both green, `_site/index.html` exists, 0 `:::` visible in
the built HTML, mermaid + KaTeX render, semantic index generated.

## Container syntax (Markdown + docmd `:::` — NEVER MDX/JSX; the guard rejects raw tags)

| Need | Syntax |
|---|---|
| Callout | `::: callout info "Title" icon:compass` ... `:::` (types: info, tip, warning, danger, success) |
| Tabs | `::: tabs` then `== tab "Label" icon:cloud` blocks, close `:::` |
| Steps | `::: steps` then a numbered list `1. **Title**` with body indented **3 spaces**, close `:::` |
| Collapsible | `::: collapsible "Title"` ... `:::` (prefix `open` to start expanded) |
| Cards | `::: grids` > `::: grid` > `::: card "Title" icon:server` > body > `[Open](/path)` > `:::` |
| Diagram | a mermaid fenced block |
| Math | KaTeX inline `$...$`, block `$$...$$` (only outside code fences) |

Gotchas: `::: button` is NOT supported (use a markdown link inside a card); Steps bodies need 3-space
re-indent so nested fences/callouts stay in the item; never use Card/Note-style tags.

## Plugins (all enabled in docmd.config.json)

search (semantic), git (repo + editLink + lastUpdated), seo, sitemap, mermaid, math, llms (fullContext),
analytics (disabled). sitemap/seo/llms need the root `url`. git needs full history in CI (`fetch-depth: 0`).

## Semantic search

`plugins.search.semantic: true` uses `docmd-search`: embeddings computed at build time via ONNX, only
quantized Int8 vectors shipped to the browser (100% client-side). The model is pinned in
`.docmd-search/config.json` (`Xenova/all-MiniLM-L6-v2`) so the first build does not launch the interactive
wizard. Functional test:
`(sleep 34; echo "a paraphrased query") | node node_modules/docmd-search/dist/bin/docmd-search.js docs`.

## Branding / footer

Footer credits Lorenzo Padovani / Padosoft / GitHub / MIT, plus Project and Ecosystem link columns.
Brand color `#0d9488` set in `assets/custom.css`.

## Deploy (Cloudflare Pages — the user does this, do NOT add deploy CI)

Production branch `main`, root `docs-site`, build `npm run build`, output `_site`, Node via `.node-version`
(20). The committed lockfile (v3, Linux natives) lets `npm ci` resolve onnxruntime/sharp on CF.

## Page structure standard (deep pages)

Sidebar groups: Get Started, Guides, Concepts & Theory, Architecture, Best Practices, Operations, Reference.
A deep page follows: motivation, theory (KaTeX where apt), design + a mermaid diagram, data/contract, an ADR
in a `::: collapsible` (Problem -> Decision -> Consequences), a worked end-to-end example, then gotchas in
`::: callout warning`. Write for juniors, keep depth for seniors. Cross-link related pages.

# AGENTS.md — laravel-iam-client

Guida sintetica per agenti AI (Claude Code, Copilot, ecc.) che lavorano su questo repo. È la porta
d'ingresso: leggi questi file **prima** di scrivere codice.

## Leggi in quest'ordine
1. **`LESSON.md`** — trappole già risolte (toolchain, PHPStan max, sicurezza, fail-closed). Non re-imparare a tue spese.
2. **`RULES.md`** — processo single-repo, gate, invarianti di prodotto.
3. **`CLAUDE.md`** — cos'è il package, architettura reale di `src/`, invarianti specifiche.
4. **Skill** `.claude/skills/laravel-iam-package-workflow/SKILL.md` — il workflow passo-passo.

## In una riga
Questo è il **consumer SDK** di Laravel IAM: traduce `Gate`/`$user->can()`/middleware in query verso il
PDP centrale (`mode=local` in-process o `mode=http` Admin API). **Sempre fail-closed**: ogni errore → deny.

## Loop advisory (NON negoziabile)
- Review advisory: `copilot -p "/review <diff vs origin/main> — focus: sicurezza, fail-closed, invarianti IAM"`.
- ⚠️ **MAI `copilot --autopilot --yolo`**: edita/commita/pusha in autonomia e ha già pushato codice
  regredito (M1). Advisory only — i fix li applichi tu, mantenendo il controllo.

## Commit & PR
- Commit terminano con: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- Corpo PR termina con: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`

## Gate
`Pint` + `PHPStan max (larastan)` + `Pest`, in locale con **PHP 8.5 (Herd)**. Test negativi obbligatori
(deny su transport error, step-up non soddisfatto, alias-collision). Aggiorna `LESSON.md` ad ogni
scoperta/fix e tieni README + doc-site (`docs/`) coerenti col codice.

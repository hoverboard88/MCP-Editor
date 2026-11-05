# MCP Editor

WordPress plugin that registers an AI Drafter role and content abilities for MCP clients via the MCP Adapter plugin.

## Git

- **Never commit.** Make changes and leave them uncommitted; the user reviews and commits. Don't push either.
- This repo uses **git flow** (`git flow`, git-flow-next) for versioning. `main` is releases, `dev` is integration.
- All work goes in a `feature/*` or `bugfix/*` branch started from `dev` (`git flow feature start <name>` / `git flow bugfix start <name>`). Never work directly on `main` or `dev`. If you're on one of those when asked to change code, start the appropriate branch first.
- Version bumps happen on `release/*` or `hotfix/*` branches, not in feature work.

## Code

- Follow WordPress Coding Standards. Run `composer lint` (or `vendor/bin/phpcs`) before saying work is done; `composer lint:fix` auto-fixes. CI runs the same check on PRs.
- `plugin-update-checker/` is a bundled third-party library: don't edit it.
- Whenever `hb_ai_drafter_capabilities()` in `includes/roles.php` changes, bump `HB_AI_DRAFTER_ROLE_VERSION`, or existing sites never get the new capabilities.

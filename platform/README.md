# TeamSayo platform (Laravel)

The production backend for teamsayo.com/app: accounts, workspaces, companies, teams, members,
roles and permissions, PayPal subscriptions, audit log, notifications and the TeamSayo app data API.

- PHP 8.3, Laravel 13, MySQL/MariaDB.
- The TeamSayo single-page app (`../public/index.html`) is copied to `resources/app/teamsayo.html`
  at build time and served at `/w/{workspace}` with `resources/app/server.js`, which connects it to the API.
- Tests run in GitHub Actions against MySQL (`.github/workflows/platform.yml`).
- Deployment is over SSH with release folders, shared `.env`/storage and automatic migrations.

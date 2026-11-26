# TeamSayo

From decision to done — workforce performance, accountability and team cadence for groups of companies.

## Structure
- `public/` — everything deployed to the web server (currently the single-file prototype `index.html`).
- `.github/workflows/deploy.yml` — deploys `public/` to cPanel over FTPS on every push.

## Deployment
| Branch | Environment | Site |
|---|---|---|
| `develop` | staging | staging.teamsayo.com → `public_html/staging` |
| `main` | production (requires approval) | app.teamsayo.com → `public_html/app` |

Each GitHub environment (`staging`, `production`) needs three secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
Use a cPanel FTP account whose home directory is the target folder, so a deployment can only write there.

Never commit passwords or API keys.

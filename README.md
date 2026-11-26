# TeamSayo

From decision to done — workforce performance, accountability and team cadence for groups of companies.

## Structure
- `public/` — everything deployed to the web server (currently the single-file prototype `index.html`).
- `.github/workflows/deploy.yml` — deploys `public/` to cPanel over FTPS on every push.

## Deployment
| Branch | Environment | Site | Server folder |
|---|---|---|---|
| `develop` | staging (not indexed by search engines) | teamsayo.com/staging/ | `public_html/staging/` |
| `main` | production (requires approval) | teamsayo.com/app/ | `public_html/app/` |

Each GitHub environment (`staging`, `production`) needs:
- secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`
- variable `SERVER_DIR` (e.g. `public_html/staging/` or `public_html/app/`) when using the main cPanel FTP login.
  Leave it unset if the FTP account is already limited to the target folder.

Never commit passwords or API keys.

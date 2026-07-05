# RYSEN-MONITOR CI setup

## Python tests

Workflow: `.github/workflows/ci.yml` job `python-tests`

Installs `requirements.txt` (Twisted, dmr_utils3, mysqlclient, etc.) plus pytest, then runs `python -m pytest -q`.

## Dashboard sync check (optional job)

Job `dashboard-sync` verifies the System-X-Installer bundle matches this repo's `html/`.

GitHub Actions cannot checkout a second private repository with the default `GITHUB_TOKEN`. Add a one-time secret:

1. GitHub → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**
2. Name: `INSTALLER_REPO_TOKEN`
3. Value: fine-grained personal access token with **Read** access to `System-X-Installer`

If the secret is missing, the `dashboard-sync` job is **skipped** (python tests still run). Add the secret when you want RYSEN-MONITOR pushes to fail early if the installer bundle is stale.

## Local parity

```bash
python -m pytest -q
```

From System-X-Installer (sibling checkout):

```bash
bash scripts/release-dashboard.sh
```

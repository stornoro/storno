# Self-hoster data assets — operator notes

This directory holds large reference files served from `https://get.storno.ro/data/`
to self-hosted Storno instances. Files here are **not committed to git** (`.gitignore`
should keep them out — they are several hundred MB to over 1 GB).

On the production webserver these live at `/storage/www/storno/deploy/data/` and
are exposed by `deploy/nginx/get.storno.ro.conf` (only `*.sqlite` and `*.sha256`
extensions are served).

## company_registry.sqlite

Romanian ONRC company registry as a prebuilt SQLite + FTS5 index. Lets self-hosters
search clients by name when adding new partners (the same UX as on storno.ro).

### How self-hosters get it

`deploy/install.sh` runs `php bin/console app:download-company-registry` after the
backend container starts. The command downloads from `https://get.storno.ro/data/company_registry.sqlite`
by default and verifies the `.sha256` companion file before atomically swapping it
into `var/data/`.

Self-hosters can re-run anytime to pick up a refreshed snapshot:

```bash
docker compose exec backend php bin/console app:download-company-registry
```

A custom mirror can be set via `--url=…` or the `COMPANY_REGISTRY_URL` env var.

### How we refresh the hosted file (quarterly)

1. Download the latest CSVs from the ONRC public registry:
   - https://portal.onrc.ro/ONRCPortalWeb/ONRCPortal.portal (or the SFTP mirror, if you have access)
2. Run the import locally (~20 min, ~1 GB output):
   ```bash
   cd backend
   php bin/console app:import-company-registry /path/to/onrc-csvs/
   # → produces backend/var/data/company_registry.sqlite
   ```
3. Generate a checksum:
   ```bash
   shasum -a 256 backend/var/data/company_registry.sqlite \
     | awk '{print $1}' > backend/var/data/company_registry.sqlite.sha256
   ```
4. Upload both to the production webserver:
   ```bash
   scp backend/var/data/company_registry.sqlite \
       backend/var/data/company_registry.sqlite.sha256 \
       prod:/storage/www/storno/deploy/data/
   ```
5. Verify:
   ```bash
   curl -sI https://get.storno.ro/data/company_registry.sqlite | head -1
   curl -s https://get.storno.ro/data/company_registry.sqlite.sha256
   ```

Self-hosters who already have the previous version will see `--force`-able no-op
when the checksum matches; otherwise the install command pulls the new file.

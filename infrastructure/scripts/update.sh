#!/bin/sh
# Aggiorna il server: scarica il codice da GitHub (se la cartella è un clone), ricompila il frontend e riavvia.
# Senza .git il codice è stato copiato dal PC e va ricopiato prima di lanciare questo script.
# Le migrazioni del database le applica il worker quando riparte.
set -eu
cd "$(dirname "$0")/../.."

if [ -d .git ]; then git pull --ff-only; fi
docker run --rm -v "$PWD/frontend:/app" -w /app node:20-alpine sh -c "npm ci --no-audit --no-fund && npx ng build"
docker compose up -d --build
docker compose ps

# Marketing OS — starter MVP

Starter locale per il progetto Marketing OS: API PHP 8.3, MySQL 8.4 e Nginx in Docker. Include registrazione, login, logout, utente corrente e CRUD iniziale dei progetti, con isolamento per organizzazione.

## Requisiti

- Docker Desktop avviato (Windows/macOS/Linux)
- Docker Compose v2
- Porta locale `8088` libera

## Avvio rapido

1. Copia `.env.example` in `.env`.
2. Modifica `DB_PASSWORD`, `DB_ROOT_PASSWORD` e `APP_KEY` con valori casuali. Per esempio, genera una chiave con `openssl rand -hex 32` se OpenSSL è disponibile.
3. Avvia da questa cartella:

   ```bash
   docker compose up --build -d
   ```

4. Verifica:

   ```bash
   curl http://localhost:8088/api/v1/health
   ```

5. Registra il primo utente con `POST http://localhost:8088/api/v1/auth/register` usando il JSON descritto in `docs/API.md`.

## Esempi con curl

```bash
curl -X POST http://localhost:8088/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Mario Rossi","email":"mario@example.com","password":"Password-Locale-123","organization_name":"Demo Srl"}'
```

Usa il token restituito:

```bash
curl http://localhost:8088/api/v1/projects \
  -H "Authorization: Bearer INCOLLA_IL_TOKEN"
```

Crea un progetto:

```bash
curl -X POST http://localhost:8088/api/v1/projects \
  -H "Authorization: Bearer INCOLLA_IL_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Campagna autunno","objective":"Generare richieste di preventivo","target_audience":"PMI in Abruzzo","budget_monthly":300}'
```

## Comandi utili

```bash
docker compose logs -f app nginx db
docker compose down
# ATTENZIONE: elimina anche il volume del database e tutti i dati locali
docker compose down -v
```

## Importante

- Il file `.env` non deve essere committato.
- Il volume MySQL conserva i dati tra riavvii.
- Lo schema SQL viene eseguito automaticamente solo al primo avvio di un volume vuoto. Se modifichi `schema.sql` dopo l'avvio, non verrà riapplicato automaticamente.
- Questo progetto è un prototipo locale e non va esposto pubblicamente senza hardening, test e revisione di sicurezza. Dettagli in `docs/ARCHITECTURE.md`.
- L'endpoint di registrazione è aperto per facilitare il test locale; prima di una beta pubblica servono rate limiting e verifica email.

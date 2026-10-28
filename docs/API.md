# Marketing OS — API v1

Base URL locale: `http://localhost:8088/api/v1`

Tutti i body sono JSON. Gli endpoint autenticati richiedono `Authorization: Bearer <access_token>`. Le risposte seguono `{ "data": ... }` oppure `{ "error": "...", "message": "..." }`.

## Health

`GET /health` — controlla API e connessione database.

## Registrazione

`POST /auth/register`

```json
{
  "name": "Mario Rossi",
  "email": "mario@example.com",
  "password": "almeno-12-caratteri",
  "organization_name": "Esempio Srl"
}
```

Crea un'organizzazione e il suo primo utente con ruolo `owner`. In produzione la registrazione pubblica va protetta da rate limit, verifica email e controlli anti-abuso.

## Login

`POST /auth/login`

```json
{ "email": "mario@example.com", "password": "almeno-12-caratteri" }
```

Il token viene mostrato una sola volta; nel database viene salvato soltanto il suo SHA-256. Scadenza configurabile con `TOKEN_TTL_HOURS` (1–168 ore).

## Utente corrente

`GET /me` — autenticato.

## Logout

`POST /auth/logout` — revoca il token corrente.

## Progetti

`GET /projects` — elenca al massimo 100 progetti dell'organizzazione dell'utente autenticato.

`POST /projects` — crea un progetto nell'organizzazione dell'utente.

```json
{
  "name": "Campagna autunno",
  "objective": "Generare richieste di preventivo",
  "target_audience": "PMI in Abruzzo",
  "budget_monthly": 300
}
```

Il server assegna `organization_id` e `created_by` dall'identità autenticata: il client non può sceglierli. Se viene fornito `brand_profile_id`, il server verifica che il brand appartenga alla stessa organizzazione.

## Codici di stato principali

- `200`: richiesta riuscita
- `201`: risorsa creata
- `400`: JSON non valido
- `401`: autenticazione assente/non valida
- `404`: endpoint inesistente
- `409`: email già registrata
- `422`: validazione fallita
- `500`: errore interno

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

`GET /projects/{id}` — dettaglio. `PATCH /projects/{id}` — modifica `name`, `objective`, `target_audience`, `budget_monthly`, `status` (`draft`, `active`, `paused`, `completed`, `archived`), `brand_profile_id`.

## Ruoli

| Ruolo | Può fare |
|---|---|
| `owner` | tutto; è l'unico che può aggiungere amministratori |
| `admin` | gestire team e brand, approvare o rifiutare contenuti |
| `editor` | creare e modificare brand, file, progetti e contenuti, generare con l'AI, inviare in revisione |
| `viewer` | solo lettura (ad esempio il cliente) |

Un'operazione non consentita risponde `403`. Una risorsa di un'altra organizzazione risponde sempre `404`, come se non esistesse.

## Team

`GET /users` — membri dell'organizzazione.

`POST /users` (owner, admin) — `{ "name", "email", "password", "role": "admin" | "editor" | "viewer" }`.

## Brand kit

`GET /brands`, `GET /brands/{id}`, `POST /brands` (editor+), `PATCH /brands/{id}` (editor+), `DELETE /brands/{id}` (owner, admin).

Campi: `name` (obbligatorio), `website_url`, `industry`, `audience`, `tone_of_voice`, `brand_guidelines`, `primary_color` e `secondary_color` (formato `#RRGGBB`), `font_family`, `logo_asset_id` (un'immagine della libreria dell'organizzazione), `products`, `keywords`, `words_to_avoid`.

## Libreria file

`POST /assets` (editor+) — `multipart/form-data` con il campo `file`. Formati: PNG, JPG, WEBP, GIF, MP4, MOV, WEBM, PDF; massimo `UPLOAD_MAX_MB` (default 20). Il tipo viene verificato dal contenuto del file.

`GET /assets` — elenco. `GET /assets/{id}/file` — scarica il file (richiede il token). `DELETE /assets/{id}` (editor+).

## Contenuti e revisione

`GET /projects/{id}/content` — contenuti del progetto.

`POST /projects/{id}/content` (editor+) — `{ "channel", "content_type", "title", "body" }`. Canali: `instagram`, `facebook`, `linkedin`, `blog`, `email`, `website`, `other`.

`PATCH /content/{id}` (editor+) — modifica `title`, `body`, `channel`, `content_type`. Solo bozze o contenuti rifiutati; dopo la modifica tornano in bozza.

Flusso: `draft` → `POST /content/{id}/submit` (editor+) → `in_review` → `POST /content/{id}/approve` (owner, admin, nota facoltativa `{ "note" }`) → `approved`, oppure `POST /content/{id}/reject` (owner, admin, nota obbligatoria) → `rejected`. Un passaggio non valido risponde `409`.

## Generazione con l'AI

`POST /projects/{id}/generate` (editor+) — avvia la generazione in background e risponde `202` con il lavoro:

```json
{ "channel": "instagram", "content_type": "post", "brief": "Lancio torte autunnali", "variants": 3 }
```

`GET /jobs/{id}` — stato del lavoro: `queued`, `running`, `done` (in `result.content_ids` gli id delle bozze create) o `failed` (motivo in `error`). Le bozze generate hanno `ai_generated: 1` e stato `draft`.

Il worker usa Claude se è impostata `ANTHROPIC_API_KEY`; altrimenti crea bozze di esempio, così il flusso si può provare senza costi.

## Dal sito alla campagna

`POST /onboarding/analyze` (editor+) — `{ "url": "pasticceriarossi.it" }`. Avvia un lavoro `analyze_site`: il server legge la home e fino a 3 pagine interne (chi siamo, prodotti, servizi…), estrae colori dal CSS e un'immagine da usare come logo, poi l'AI compila il brand kit. A lavoro finito `result` contiene `brand_id`, `site_colors` e `logo_found`.

La lettura dei siti è protetta da SSRF: solo http(s) sulle porte 80 e 443, solo IP pubblici (ricontrollati a ogni redirect), al massimo 3 redirect, limiti di tempo e dimensione. Un indirizzo non ammesso fa fallire il lavoro con un messaggio esplicito.

`POST /brands/{id}/campaigns` (editor+) — crea una campagna per il cliente e ne avvia la pianificazione. Risponde `202` con `{ project, job }`.

```json
{ "goal": "Far conoscere le torte autunnali", "weeks": 4, "posts_per_week": 3, "channels": ["instagram", "facebook"], "start_date": "2026-11-02" }
```

Durata 1–4 settimane, 1–5 post a settimana, massimo 16 post; canali tra `instagram`, `facebook`, `linkedin`, `blog`, `email`. Il lavoro salva la strategia in `project.strategy` e crea i post in bozza con data (`scheduled_at`) e grafica (`visual`).

Ogni contenuto può avere `visual`: `{ "headline", "subheadline", "badge", "layout": "bold" | "light" | "photo", "asset_id" }`. Si modifica con `PATCH /content/{id}`; `asset_id` deve essere un'immagine della libreria dell'organizzazione.

## Dashboard, calendario e approvazioni

`GET /dashboard` — numeri principali (clienti, campagne attive, post del mese, contenuti da approvare, lavori AI del mese) e i post della settimana corrente.

`GET /calendar?from=AAAA-MM-GG&to=AAAA-MM-GG` — post programmati nell'intervallo (massimo 62 giorni), con nome e colore del cliente.

`POST /content/{id}/schedule` (editor+) — `{ "scheduled_at": "2026-11-05 18:30" }` oppure `null`. Non si applica ai contenuti pubblicati.

`GET /approvals` — contenuti `in_review` di tutta l'organizzazione, con nome della campagna e cliente.

## Limiti di accesso

- Login: 5 tentativi falliti per email e IP, 30 per IP, in 15 minuti.
- Registrazione: `RATE_LIMIT_REGISTER_PER_HOUR` per IP (default 10).

Oltre il limite la risposta è `429` con l'intestazione `Retry-After`.

## Codici di stato principali

- `200`: richiesta riuscita
- `201`: risorsa creata
- `202`: lavoro avviato in background
- `400`: JSON non valido
- `401`: autenticazione assente/non valida
- `403`: ruolo non autorizzato
- `404`: risorsa o endpoint inesistente (anche se appartiene a un'altra organizzazione)
- `409`: email già registrata o passaggio di stato non valido
- `422`: validazione fallita
- `429`: troppi tentativi
- `500`: errore interno

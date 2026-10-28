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
| `viewer` | lettura e caricamento della propria foto sui post non ancora approvati (ad esempio il cliente) |

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

`POST /brands/{id}/analyze` (editor+) — rilegge il sito del cliente (o `{ "url" }` indicato) e aggiorna il brand kit: il nome resta, i campi trovati sostituiscono i precedenti, logo e foto mancanti vengono importati.

`POST /brands/{id}/goal-suggestions` (editor+) — lavoro `suggest_goals`; a lavoro finito `result.goals` contiene 4 obiettivi `{ title, goal }`.

`POST /content/{id}/rewrite` (editor+) — `{ "instruction": "Più breve" }`. Lavoro `rewrite_content`: l'AI riscrive testo e grafica (la foto scelta resta). Solo bozze o contenuti rifiutati.

Stili grafici (`visual.layout`): `bold` colore pieno, `light` chiaro, `photo` foto a tutta pagina, `split` foto e colore, `offer` promessa in grande, `quote` citazione. Nelle campagne le foto del cliente vengono assegnate a rotazione ai post `photo` e `split`.

`POST /content/{id}/photo` (tutti i ruoli, cliente `viewer` compreso) — `multipart/form-data` con il campo `file` (PNG, JPG, WEBP o GIF). Salva la foto nella libreria del cliente della campagna e la mette nella grafica del post; se lo stile non prevede foto passa a `photo`. Vale per bozze, contenuti rifiutati e in revisione: un post in revisione resta in revisione, gli altri tornano in bozza. È l'unica modifica consentita al ruolo `viewer`.

`POST /projects/{id}/generate` accetta anche `asset_id`: un'immagine della libreria da usare nella grafica di tutte le varianti (stili `photo` e `split` alternati).

## Video Studio

`POST /content/{id}/video` (editor+) — `{ "format": "story" | "square", "music_asset_id": 12 }` (musica facoltativa). Avvia il lavoro `render_video` e risponde `202`. Il video si costruisce dal post senza AI e senza costi: apertura con logo e titolo della grafica, una scena per ogni frase chiave (con zoom lento sulla foto del post o del cliente e la frase come sottotitolo), chiusura con invito all'azione e sito. Montaggio con FFmpeg nel worker, testi con il font del brand (scaricato da Google Fonts) o Inter.

A lavoro finito il post ha `video_asset_id` (MP4 H.264 1080×1920 o 1080×1080, audio AAC) e `result` contiene `asset_id`, `scenes` e `seconds` (tempo di montaggio). Un post pubblicato non si modifica.

La libreria accetta anche musica (`kind: "audio"`): MP3, M4A, WAV, OGG. Usa solo brani di cui hai i diritti (ad esempio con licenza libera per uso commerciale).

Prova del montaggio senza database: `docker compose exec worker php tests/video_render_check.php story /tmp`.

## Pubblicazione

La pubblicazione sui social avviene fuori dall'app con il kit di pubblicazione (grafica, testo, link ai profili, newsletter HTML). L'app tiene traccia di cosa è uscito.

- `POST /content/{id}/publish` (editor+) — `{ "url": "https://..." }` facoltativo. Solo da `approved`; il contenuto passa a `published` con `published_at` e `published_url`.
- `POST /content/{id}/unpublish` (editor+) — riporta un contenuto pubblicato ad `approved`.
- `GET /dashboard` include `to_publish` (approvati con data di oggi o passata, non ancora pubblicati) e `published_this_month`.

Il brand kit ha `instagram_url`, `facebook_url` e `linkedin_url`; l'analisi del sito li compila con i profili collegati dal sito.

## Eliminazioni

- `DELETE /content/{id}` (editor+) — un post, se non pubblicato.
- `DELETE /projects/{id}` (owner, admin) — una campagna con tutti i suoi contenuti.
- `DELETE /brands/{id}` (owner, admin) — un cliente; con `?with_campaigns=1` anche le sue campagne e i loro contenuti. Foto e file restano nella libreria.

## Libreria file per cliente

`POST /assets` accetta il campo facoltativo `brand_profile_id` per collegare il file a un cliente. `GET /assets?brand_id={id}` restituisce solo i file di quel cliente.

## Dashboard, calendario e approvazioni

`GET /dashboard` — numeri principali (clienti, campagne attive, post del mese, contenuti da approvare, lavori AI del mese) e i post della settimana corrente.

`GET /calendar?from=AAAA-MM-GG&to=AAAA-MM-GG` — post programmati nell'intervallo (massimo 62 giorni), con nome e colore del cliente.

`POST /content/{id}/schedule` (editor+) — `{ "scheduled_at": "2026-11-05 18:30" }` oppure `null`. Non si applica ai contenuti pubblicati.

`GET /approvals` — contenuti `in_review` di tutta l'organizzazione, con nome della campagna e cliente.

## Pannello Admin della piattaforma

Riservato agli amministratori della piattaforma (`is_platform_admin` in `/me`), che vedono tutte le organizzazioni. Lo si diventa solo da riga di comando:

```bash
docker compose exec app php bin/make-admin.php email@esempio.it
docker compose exec app php bin/make-admin.php email@esempio.it --revoca
```

- `GET /admin/overview` — numeri della piattaforma (organizzazioni, utenti attivi, clienti, campagne, contenuti, lavori AI e video del mese, lavori falliti, spazio file).
- `GET /admin/organizations?q=&status=active|suspended` — elenco con conteggi per organizzazione; `q` cerca nel nome o nell'email del titolare.
- `GET /admin/organizations/{id}` — utenti con ultimo accesso, clienti, ultimi lavori, attività recente, note interne.
- `PATCH /admin/organizations/{id}` — `{ "admin_notes" }`, note visibili solo agli amministratori.
- `POST /admin/organizations/{id}/suspend` — `{ "reason" }` obbligatorio. Chiude subito tutte le sessioni dei suoi utenti; login e chiamate rispondono `403` con `error: account_suspended`. Non si può sospendere la propria organizzazione.
- `POST /admin/organizations/{id}/reactivate`.

Le azioni dell'amministratore finiscono nel registro attività dell'organizzazione interessata.

## Sponsorizzate

Registro della spesa pubblicitaria per cliente. Lettura per tutti i ruoli, scrittura per owner, admin ed editor.

- `GET /ad-spend?brand_id=&from=AAAA-MM-GG&to=AAAA-MM-GG` — righe con cliente, campagna collegata e stato della fattura (`invoice_status`).
- `GET /ad-spend/summary?year=2026&brand_id=` — totali dell'anno (speso, budget, impression, copertura, clic, risultati, CPC, CTR, costo per risultato), spesa per mese e per cliente. Una riga conta nel mese in cui finisce il suo periodo.
- `POST /ad-spend` — `{ "brand_profile_id", "campaign_name", "period_start", "period_end", "spent", "platform": "meta|google|tiktok|linkedin|other", "budget"?, "impressions"?, "reach"?, "clicks"?, "results"?, "project_id"?, "notes"? }`.
- `PATCH /ad-spend/{id}`, `DELETE /ad-spend/{id}` — `409` se la riga è in una fattura già emessa o pagata.
- `POST /brands/{id}/ad-spend/import` — multipart con `file` (CSV di Gestione inserzioni di Meta, in italiano o inglese, max 2 MB), `platform`, `project_id` facoltativo e `preview=1` per vedere cosa verrebbe importato senza salvare. Riconosce separatore (`;` `,` tab), codifica e formato dei numeri; ignora la riga dei totali. Reimportare lo stesso periodo aggiorna le righe invece di duplicarle; quelle già fatturate restano invariate (`counts.locked`). Solo importi in euro.

## Pagamento delle sponsorizzate Meta

Per ogni cliente si indica chi paga le inserzioni e si segue una guida a passaggi (nella scheda del cliente). I dati di pagamento non passano mai dall'app: la carta si inserisce solo su Meta.

- `GET /brands/{id}/ads-setup` — `{ ads_payer: "client"|"agency"|null, meta_ad_account_id, meta_business_id, steps, total_steps }`. Tutti i ruoli.
- `PATCH /brands/{id}/ads-setup` — owner, admin, editor. `ads_payer`, `meta_ad_account_id` e `meta_business_id` (solo cifre; il prefisso `act_` viene tolto), `steps` (passaggi completati). Passaggi ammessi: con `client` `client_portfolio`, `client_ad_account`, `client_payment`, `partner_access`, `people_assigned`; con `agency` `agency_ad_account`, `agency_payment`, `page_access`, `people_assigned`, `billing_setup`. Cambiando chi paga, i passaggi ripartono da zero.

Con `ads_payer: "client"` le bozze di fattura non rifatturano la spesa pubblicitaria (il cliente paga Meta direttamente): resta la commissione calcolata sulla spesa del registro.

## Link tracciati e clic

Un link breve `/r/{codice}` (fuori da `/api/v1`, pubblico) conta i clic e reindirizza (302) alla destinazione con i parametri UTM, così le visite compaiono anche in Google Analytics. L'indirizzo pubblico si basa su `APP_URL`: in produzione deve essere il dominio raggiungibile da internet.

Non vengono contate le anteprime automatiche (Facebook, WhatsApp, LinkedIn, Google e altri bot) né le richieste `HEAD`. Non si salva l'IP: per i visitatori unici si usa un'impronta che cambia ogni giorno. La provenienza (`instagram`, `facebook`, `linkedin`, `google`, `x`, `email`, `direct`, `other`) si ricava dal browser interno delle app o dal referrer.

- `GET /links?brand_id=&content_id=` — link con `short_url`, `destination_url`, `clicks`, `unique_clicks`, `last_click_at`. Tutti i ruoli.
- `GET /links/summary?days=30&brand_id=&link_id=` — clic per giorno, per provenienza e per dispositivo (7–365 giorni).
- `POST /links` — owner, admin, editor. `{ "content_item_id"?, "brand_profile_id"?, "target_url"?, "label"?, "utm_source"?, "utm_medium"?, "utm_campaign"?, "utm_content"? }`. Con `content_item_id` cliente e UTM si ricavano dal post (canale, campagna, `post-{id}`), la destinazione predefinita è il sito del cliente e, se il post ha già un link, si restituisce quello. Se la destinazione contiene già parametri `utm_` restano quelli.
- `DELETE /links/{id}` — dopo l'eliminazione l'indirizzo breve risponde 404.

L'elenco dei contenuti di una campagna (`GET /projects/{id}/content`) include `tracked_link` con indirizzo breve e clic.

## Report mensile per il cliente

I numeri si ricalcolano sempre dai dati del mese (`period` = `AAAA-MM`): post pubblicati, clic sui link del cliente (per giorno, provenienza e dispositivo), sponsorizzate con fine periodo nel mese, confronto con il mese prima e post già programmati per il mese dopo. Si salvano solo il commento dell'agenzia e il link pubblico.

- `GET /reports?period=` — stato del report per ogni cliente: `has_summary`, `shared`, `views`, `last_viewed_at`. Tutti i ruoli.
- `GET /brands/{id}/report?period=` — il report completo, con `share` (`token`, `shared_at`, `views`, `last_viewed_at`). Tutti i ruoli.
- `PATCH /brands/{id}/report` — owner, admin, editor. `{ "period", "summary"?, "next_steps"? }` (massimo 5000 caratteri ciascuno).
- `POST /brands/{id}/report/share` — owner, admin, editor. `{ "period" }`. Crea il link pubblico; se esiste già restituisce lo stesso.
- `POST /brands/{id}/report/unshare` — owner, admin, editor. `{ "period" }`. Disattiva il link e azzera le aperture; una nuova condivisione crea un link diverso.

Pubblici, senza token (il frontend li apre da `/report/{token}`):

- `GET /public/reports/{token}` — il report senza `share` né id interni; ogni richiesta conta come apertura. 404 se il link è disattivato o l'organizzazione è sospesa.
- `GET /public/reports/{token}/logo` — il logo del cliente.

Il PDF si ottiene dal browser con "Scarica PDF" (stampa → Salva come PDF): il foglio di stile di stampa mostra solo il report, in formato A4.

## Potenziali clienti e audit gratuito

L'agenzia inserisce il sito di un'attività che vuole come cliente; il worker lo legge (stessa protezione SSRF dell'analisi dei siti), esegue 8 controlli automatici (HTTPS, titolo e descrizione per Google, social, contatti, testi, blog, identità visiva) da cui nasce il punteggio, e l'AI scrive punti di forza, problemi con la soluzione, 3 idee di post, servizi da proporre e chiusura.

- `GET /prospects` — elenco con `score`, `status` (`new`, `sent`, `won`, `lost`), `analyzing`, aperture. Tutti i ruoli.
- `POST /prospects` — owner, admin, editor. `{ "url" }`. Risponde `202` e avvia l'audit (`job_id`).
- `GET /prospects/{id}` — dettaglio con `audit`.
- `PATCH /prospects/{id}` — `status`, `name`, `monthly_fee` (canone proposto), `agency_note` (chiusura personale, sostituisce quella dell'AI).
- `POST /prospects/{id}/reanalyze`, `DELETE /prospects/{id}`.
- `POST /prospects/{id}/share` — link pubblico; lo stato passa da `new` a `sent`. `409` se l'audit non è pronto. `POST /prospects/{id}/unshare` lo disattiva.
- `POST /prospects/{id}/convert` — crea il cliente dallo stesso sito (brand kit, logo, foto) e segna il potenziale cliente come `won`; risponde con il lavoro, il cui risultato contiene `brand_id`.
- `GET /public/audits/{token}` — pubblico, senza token: l'audit con canone proposto e nome dell'agenzia; ogni richiesta conta come apertura. Il frontend lo apre da `/audit/{token}`.

## Stile appreso e altri formati

Quando un post scritto dall'AI viene corretto e poi approvato, la coppia testo dell'AI / versione approvata diventa un esempio di stile del cliente; quando qualcuno chiede modifiche, la nota diventa una regola. Gli ultimi 6 esempi vengono passati all'AI in ogni generazione, riscrittura e trasformazione per quel cliente (si tengono gli ultimi 30).

- `GET /brands/{id}/style-examples` — `kind` (`edit` o `feedback`), `before_text`, `after_text`. Tutti i ruoli.
- `DELETE /style-examples/{id}` — owner, admin, editor: l'AI smette di seguire quell'esempio.
- `POST /content/{id}/repurpose` — owner, admin, editor. `{ "formats": [...] }` da 1 a 5 tra `instagram_post`, `carosello`, `reel`, `facebook`, `linkedin`, `newsletter`, `blog`. Crea nuove bozze nella stessa campagna; il risultato del lavoro contiene `content_ids`.

## Ore e redditività

- `GET /time-entries?period=AAAA-MM&brand_id=` — ore segnate nel mese. Tutti i ruoli.
- `POST /time-entries` — owner, admin, editor. `{ "brand_id", "minutes" (1-1440), "worked_on"?, "note"? }`.
- `DELETE /time-entries/{id}` — le proprie ore; owner e admin anche quelle degli altri.
- `GET /profitability?period=` — solo owner e admin. Per cliente: ore, ricavo (voci della fattura del mese escluse le sponsorizzate rifatturate; senza fattura, stima da canone e commissione: `revenue_estimated`), costo (ore × costo orario), margine, margine % e guadagno orario. Ordinati dal meno redditizio.
- `PATCH /profitability/settings` — solo owner e admin. `{ "hourly_cost" }` costo orario medio dell'agenzia.

## Recensioni, ricorrenze, avvisi

- `POST /brands/{id}/review-replies/suggest` — owner, admin, editor. `{ "platform" (google, facebook, tripadvisor, trustpilot, other), "rating" (1-5), "author"?, "review_text" }`. Lavoro in background con 3 risposte in `result.replies`.
- `GET /review-replies?brand_id=`, `POST /review-replies` (gli stessi campi più `brand_id` e `reply`), `DELETE /review-replies/{id}`.
- `GET /occasions?from=AAAA-MM-GG&to=AAAA-MM-GG` — festività italiane, giornate mondiali e date commerciali con un'idea per i contenuti (date mobili come Pasqua e Black Friday calcolate per anno). Massimo un anno.
- `GET /alerts` — avvisi calcolati al momento: richieste dal portale, post in approvazione da più di 3 giorni, modifiche chieste dal cliente, sponsorizzate oltre il budget, campagne attive senza post nei prossimi 7 giorni, report condivisi e non aperti, fatture scadute (solo owner e admin). Ognuno ha `severity`, `link` e `query` per il frontend.

## Portale del cliente

Un link senza account per cliente. Dal portale il cliente approva o chiede modifiche ai post in revisione (la nota è firmata con il nome che indica), vede i post in programma e quelli pubblicati, apre i report condivisi e manda richieste. Le note interne per il revisore non arrivano mai al cliente.

- `GET /brands/{id}/portal` — `token` e richieste del cliente. `POST /brands/{id}/portal/enable` e `/disable` (owner, admin, editor); riattivandolo il link cambia.
- `PATCH /client-requests/{id}` — `{ "status": "open" | "done" }`.

Pubblici, senza token (il frontend li apre da `/portal/{token}`), con un limite di 60 azioni ogni 10 minuti per IP:

- `GET /public/portal/{token}` — cliente, agenzia, `pending`, `upcoming`, `published`, `reports`, `requests`.
- `POST /public/portal/{token}/content/{id}/approve` — `{ "name"?, "note"? }`; `POST .../reject` — `{ "name"?, "note" }`. Solo post di quel cliente in revisione (`409` altrimenti); nell'app risultano con `reviewed_via: "portal"`.
- `POST /public/portal/{token}/requests` — `{ "name"?, "message" }`.
- `GET /public/portal/{token}/assets/{id}` — logo e foto del cliente o usate nei suoi post.

## Concorrenti

Fino a 5 per cliente. Il worker legge il sito (protezione SSRF) e l'AI riassume offerte, messaggi, tono e come distinguersi; i concorrenti già analizzati si rianalizzano da soli ogni 7 giorni.

- `GET /brands/{id}/competitors` — con `analysis`, `last_checked_at` e `ad_library_url` (Libreria inserzioni di Meta, che in UE mostra tutte le inserzioni attive). `analysis` contiene anche `strengths` (`[{ strength, counter, post_idea }]`: cosa fa bene, come fare meglio e il brief per un post), `checks` e `score` (gli stessi controlli dell'audit), `colors` e `fonts` del sito.
- `POST /brands/{id}/competitors` — owner, admin, editor. `{ "url", "name"? }`, risponde `202`.
- `POST /competitors/{id}/refresh`, `DELETE /competitors/{id}`.

### Confronto e proposta di stile

- `GET /brands/{id}/competition` — `client` (punteggio, controlli, colori e font del sito del cliente; `null` se non letto), `competitors`, `rows` (per ogni controllo l'esito del cliente e di ogni concorrente), `wins`, `losses`, `market`, `gaps`, `proposal` (`primary_color`, `secondary_color`, `font_family`, `photo_style`, `tone_of_voice`, `formats`, `rationale`), `applied_at`, `analyzing`, `job_error`.
- `POST /brands/{id}/competition` — owner, admin, editor. Avvia il confronto (`202`); serve almeno un concorrente analizzato. Colori e font proposti sono sempre validi: se l'AI ne dà di non validi, si usano quelli più lontani dai concorrenti.
- `POST /brands/{id}/competition/apply` — `{ "parts": ["colors", "font", "tone"] }`: copia nel brand kit le parti scelte, ricordando i valori di prima.
- `POST /brands/{id}/competition/undo` — rimette i valori di prima dell'applicazione.

## Fatturazione

Solo owner e admin. Una fattura al mese per cliente, con stati `to_invoice` → `invoiced` → `paid`. La fattura elettronica si emette nel gestionale o dal commercialista: qui si preparano gli importi, si registra il numero e si segue il pagamento.

- `GET /billing/clients`, `PATCH /billing/clients/{id}` — `monthly_fee`, `ad_fee_percent` (commissione sulle sponsorizzate), `billing_name`, `vat_number`, `tax_code`, `sdi_code`, `pec`, `billing_address`, `billing_email`. Le bozze aperte del cliente si ricalcolano subito.
- `GET /invoices?period=AAAA-MM` — fatture del mese, totali per stato e sponsorizzate non ancora in fattura.
- `POST /invoices/prepare` — `{ "period" }`. Crea o ricalcola la bozza di ogni cliente con un canone o con sponsorizzate non fatturate fino alla fine del mese: canone, una voce per ogni sponsorizzata, commissione. Le fatture emesse non cambiano.
- `GET /invoices/{id}` — voci e dati di fatturazione del cliente.
- `PATCH /invoices/{id}` — `vat_rate` (default 22), `notes`, `due_on`; solo bozze.
- `POST /invoices/{id}/lines` — `{ "description", "amount" }` voce libera (negativa per uno sconto); `DELETE /invoices/{id}/lines/{lineId}`.
- `POST /invoices/{id}/issue` — `{ "number", "issued_on"?, "due_on"? }`; `409` se il numero è già usato nello stesso anno.
- `POST /invoices/{id}/paid` — `{ "paid_on"? }`. `POST /invoices/{id}/reopen` torna indietro di uno stato. `DELETE /invoices/{id}` elimina una bozza e rimette le sue spese da fatturare.
- `GET /invoices/export?period=AAAA-MM` — CSV per il commercialista (una riga per voce, separatore `;`, virgola decimale, apribile in Excel).

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

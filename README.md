# Marketing OS — starter MVP

Piattaforma per gestire il marketing dei clienti: API PHP 8.3, MySQL 8.4, Nginx e un worker in Docker, frontend Angular 17.

- **Dal sito alla campagna**: inserisci il sito di un cliente e l'AI ne ricava brand kit, colori, carattere e logo (scelto tra intestazione, link alla home e dati schema.org, scartando bollini di partner e pagamenti; gli SVG diventano PNG); poi genera strategia e calendario di post con grafiche nei colori del brand.
- **Analisi da CEO**: per ogni cliente, cosa c'è sul sito (telefoni, email, WhatsApp, indirizzo, partita IVA, orari, valutazioni, social, piattaforma, Analytics e Pixel di Meta, modi di contattare o comprare, velocità) con 15 controlli, e una diagnosi strategica dell'AI: giudizio franco, come guadagna l'attività, proposta di valore riscritta, pagella per area, SWOT, priorità ordinate per impatto e sforzo, cose da fare questa settimana, nuove entrate, obiettivi a 90 giorni e domande da fare al titolare. Parte da sola per ogni cliente nuovo.
- **Anteprime realistiche** dei post (Instagram, Facebook, LinkedIn, blog, email) con grafica esportabile in PNG.
- **Calendario** mensile con trascinamento, **approvazioni** con note di revisione, **dashboard** della settimana.
- **Sponsorizzate**: spesa, clic e risultati per cliente, inseriti a mano o importati dal CSV di Gestione inserzioni di Meta, con grafici per mese e per cliente. Nella scheda del cliente, una guida a passaggi per collegare l'account pubblicitario Meta e scegliere chi paga (il cliente con la sua carta o l'agenzia, che rifattura).
- **Link e clic**: link brevi da mettere nei post che contano quante persone cliccano, da quale social e da quale dispositivo, e portano al sito con i parametri UTM.
- **Report clienti**: ogni mese un report con i colori e il logo del cliente (post pubblicati, clic, sponsorizzate, confronto con il mese prima e il commento dell'agenzia), da scaricare in PDF o da mandare con un link che il cliente apre senza account; si vede quando lo apre.
- **Fatturazione**: ogni mese una fattura per cliente con canone, sponsorizzate da rifatturare e commissione; stati da fatturare → fatturata → pagata ed export CSV per il commercialista.
- **Potenziali clienti**: inserisci il sito di un'attività e ottieni un audit gratuito con punteggio, problemi da risolvere, idee di post e servizi da proporre; aggiungi il canone, mandalo con un link (vedi quando lo aprono) e, se accettano, trasformali in clienti con un clic.
- **L'AI impara lo stile di ogni cliente**: le correzioni approvate e le richieste di modifica diventano esempi che l'AI segue nei testi successivi.
- **Un contenuto, tanti formati**: da un post a carosello, copione per Reel, newsletter, articolo e post per gli altri social.
- **Portale del cliente**: un link senza account dove il cliente approva i post o chiede modifiche, vede cosa uscirà, apre i report e manda richieste.
- **Ore e redditività**: timer e ore per cliente, costo orario dell'agenzia, margine e guadagno orario reale di ogni cliente.
- **Recensioni**: l'AI propone tre risposte nel tono del cliente a recensioni di Google, Facebook, TripAdvisor.
- **Concorrenti**: fino a 5 per cliente, rianalizzati ogni settimana, con spunti per distinguersi e il link alle loro inserzioni attive. Per ognuno, cosa fa bene e come fare meglio, con un clic per far scrivere all'AI il post del cliente.
- **Confronto e proposta di stile**: il sito del cliente accanto a quelli dei concorrenti (punteggio, colori, caratteri, controlli), dove vince e dove perde, e uno stile che lo distingue (colori, carattere, foto, tono, formati) con l'anteprima prima/dopo; si applica al brand kit con un clic e si può annullare.
- **Ricorrenze e avvisi**: festività e date commerciali nel calendario; avvisi su approvazioni ferme, budget superati, settimane senza post, report non aperti, fatture scadute.
- Ricerca rapida con Ctrl+K, tema scuro, app installabile sul telefono.
- Team con ruoli, libreria file, isolamento tra organizzazioni.

## Generazione con l'AI

Senza chiave API il worker crea bozze di esempio, così puoi provare tutto senza costi. Per contenuti reali:

1. Crea una chiave su https://console.anthropic.com e impostala in `.env`:

   ```
   ANTHROPIC_API_KEY=sk-ant-...
   ```

2. Riavvia il worker:

   ```bash
   docker compose up -d worker
   ```

`ANTHROPIC_MODEL` (default `claude-opus-5-5`) e `ANTHROPIC_EFFORT` (default `medium`) regolano qualità e costo.

## Test

```bash
docker compose exec app php tests/isolation.php
```

Verifica isolamento tra organizzazioni, ruoli, revisione, campagne, calendario, sponsorizzate, fatturazione, link tracciati, report pubblici, audit dei potenziali clienti, stile appreso, altri formati, ore e redditività, recensioni, portale del cliente, concorrenti, analisi del sito (logo, contatti, strumenti, diagnosi), protezione SSRF della lettura dei siti e limite ai tentativi di login. Usa il generatore di esempio, quindi non consuma crediti AI.

Le modifiche al database vanno in `backend/database/migrations/` (file numerati): il worker le applica all'avvio.

Il worker è un processo sempre attivo: dopo aver modificato il codice PHP riavvialo con `docker compose restart worker`.

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

## Frontend (Angular 17)

Con i container avviati, da questa cartella:

```bash
cd frontend
npm install
npx ng serve
```

Apri http://localhost:4200: progetti, brand kit, libreria file, team e generazione dei contenuti. L'indirizzo delle API è in `frontend/src/app/core/models.ts` (`API_URL`); l'origine `http://localhost:4200` deve coincidere con `CORS_ORIGIN` nel file `.env`.

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

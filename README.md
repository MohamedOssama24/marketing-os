# Marketing OS — starter MVP

Piattaforma per gestire il marketing dei clienti: API PHP 8.3, MySQL 8.4, Nginx e un worker in Docker, frontend Angular 17.

- **Dal sito alla campagna**: inserisci il sito di un cliente e l'AI ne ricava brand kit, colori, carattere e logo (scelto tra intestazione, link alla home e dati schema.org, scartando bollini di partner e pagamenti; gli SVG diventano PNG); poi genera strategia e calendario di post con grafiche nei colori del brand.
- **Analisi da CEO**: per ogni cliente, cosa c'è sul sito (telefoni, email, WhatsApp, indirizzo, partita IVA, orari, valutazioni, social, piattaforma, Analytics e Pixel di Meta, modi di contattare o comprare, velocità) con 15 controlli, e una diagnosi strategica dell'AI: giudizio franco, come guadagna l'attività, proposta di valore riscritta, pagella per area, SWOT, priorità ordinate per impatto e sforzo, cose da fare questa settimana, nuove entrate, obiettivi a 90 giorni e domande da fare al titolare. Parte da sola per ogni cliente nuovo.
- **Anteprime realistiche** dei post (Instagram, Facebook, LinkedIn, blog, email) con grafica esportabile in PNG.
- **Calendario** mensile con trascinamento, **approvazioni** con note di revisione, **dashboard** della settimana.
- **Sponsorizzate**: spesa, clic e risultati per cliente, inseriti a mano o importati dal CSV di Gestione inserzioni di Meta, con grafici per mese e per cliente. Nella scheda del cliente, una guida a passaggi per collegare l'account pubblicitario Meta e scegliere chi paga (il cliente con la sua carta o l'agenzia, che rifattura).
- **Musica delle sponsorizzate**: nel passaggio «Musica» si sceglie tra la libreria musicale di Meta (gratis e già autorizzata), una traccia del cliente o nessuna musica. Con Meta l'app propone l'atmosfera adatta al cliente in base a settore e tono (8 atmosfere, con le parole da cercare nella libreria e il ritmo) e indica se lasciar scegliere il brano a Meta. Con una traccia propria va confermata la licenza per uso commerciale e i nomi da brano famoso vengono segnalati; partendo da un post, l'app monta il video verticale con quella musica.
- **Sponsorizzate a norma Meta**: «Nuova sponsorizzata» guida in 5 passaggi (obiettivo, pubblico, budget, testi, musica), anche partendo da un post approvato, e controlla mentre scrivi le regole di Meta: attributi personali, prima e dopo, alcol solo per maggiorenni, gioco d'azzardo vietato in Italia, categorie speciali da dichiarare, politica non ammessa nell'UE, lunghezza dei testi, budget minimo. L'AI può rileggere inserzione e pagina di destinazione come un revisore di Meta e proporre i testi corretti. Alla fine c'è la scheda da copiare in Gestione inserzioni, nell'ordine dei campi di Meta e con i link UTM.
- **Regole Meta sempre aggiornate**: ogni giorno il worker rilegge 18 pagine ufficiali di Meta sulle inserzioni; se cambiano, l'agenzia riceve un avviso con le frasi aggiunte e tolte e un riepilogo dell'AI su cosa fare. Pannello in Sponsorizzate → Regole Meta.
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
- **Sponsorizzate pubblicate da qui**: collegato Facebook, la sponsorizzata si pubblica su Facebook e Instagram senza aprire Gestione inserzioni, si attiva con la conferma del budget e spesa e risultati arrivano da soli in Sponsorizzate e in fattura (vedi sotto).
- **Inserzioni dei concorrenti**: dall'archivio inserzioni di Meta, cosa sponsorizzano, a chi (età, sesso, paesi), in quali città e zone, da quanto tempo (quelle attive da più di 30 giorni probabilmente funzionano), offerte e pagine di destinazione. La spesa è una stima (Meta non la pubblica), messa accanto alla spesa reale del cliente. Avviso quando un concorrente lancia nuove inserzioni, con il riepilogo dell'AI e una sponsorizzata del cliente pronta per la procedura guidata. Richiede `META_AD_LIBRARY_TOKEN`; con `META_AD_LIBRARY_SAMPLE=true` mostra dati di esempio.
- **Confronto e proposta di stile**: il sito del cliente accanto a quelli dei concorrenti (punteggio, colori, caratteri, controlli), dove vince e dove perde, e uno stile che lo distingue (colori, carattere, foto, tono, formati) con l'anteprima prima/dopo; si applica al brand kit con un clic e si può annullare.
- **Ricorrenze e avvisi**: festività e date commerciali nel calendario; avvisi su approvazioni ferme, budget superati, settimane senza post, report non aperti, fatture scadute.
- **Italiano e inglese**: pulsante IT/EN nel menu (e `?lang=en` nei link, per esempio il portale per un cliente straniero). Per ogni cliente si sceglie anche la lingua in cui l'AI scrive post e sponsorizzate.
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

### In alternativa: Google Gemini (piano gratuito)

Gemini ha un piano gratuito con un limite di richieste al minuto e al giorno. Sul piano gratuito Google può usare i dati inviati per migliorare i suoi prodotti: valuta se va bene per i dati dei tuoi clienti.

1. Crea una chiave su https://aistudio.google.com/apikey e impostala in `.env`:

   ```
   GEMINI_API_KEY=...
   ```

2. Riavvia app e worker: `docker compose up -d app worker`.

Con `AI_PROVIDER=auto` si usa Claude se c'è `ANTHROPIC_API_KEY`, altrimenti Gemini se c'è `GEMINI_API_KEY`; per scegliere a mano usa `AI_PROVIDER=claude`, `gemini`, `groq` o `fake`. `GEMINI_MODEL` (default `gemini-flash-latest`) sceglie il modello. Istruzioni e formati delle risposte sono gli stessi di Claude.

### Riserva, limiti giornalieri e cache

Per restare nelle quote gratuite:

- **Provider di riserva**: con `AI_FALLBACK_PROVIDER=groq` e una chiave `GROQ_API_KEY` (gratuita su https://console.groq.com/keys), quando Gemini ha finito la quota o è sovraccarico la stessa richiesta passa a Groq. `GROQ_MODEL` (default `openai/gpt-oss-120b`) deve supportare `json_schema` in modalità strict. Sul piano gratuito Groq ha pochi token al minuto: le richieste più lunghe (diagnosi del sito, calendari interi) possono non starci.
- **Limiti giornalieri**: `AI_DAILY_LIMIT` (in totale, perché la quota è quella della chiave) e `AI_DAILY_LIMIT_PER_CLIENT`; `0` = nessun limite. Contano anche i lavori automatici (rianalisi dei concorrenti, riepiloghi delle regole Meta). Oltre il limite, i lavori chiesti da una persona si fermano mostrando il motivo, quelli automatici passano al giorno dopo. Il giorno è quello UTC.
- **Cache**: le risposte restano salvate 30 giorni. I lavori automatici le riusano se le istruzioni sono identiche (per esempio il sito di un concorrente non è cambiato); quando una persona chiede di generare, l'AI scrive sempre un testo nuovo.

## Sponsorizzate pubblicate direttamente su Meta

Con il cliente collegato a Meta, la sponsorizzata preparata con la procedura guidata si pubblica da Marketing OS su Facebook e Instagram:

1. Nella scheda del cliente, **Sponsorizzate su Meta → Collega Facebook e Instagram**: chi gestisce l'account pubblicitario accede a Facebook e sceglie account pubblicitario e pagina (l'Instagram collegato alla pagina viene preso da solo). Il codice di accesso resta cifrato sul server (con `APP_KEY`) e non arriva mai al browser.
2. Nella sponsorizzata, **Pubblica su Meta in pausa**: il worker crea campagna, gruppo di inserzioni, immagine, creatività e inserzione, tutto in pausa. Se un passaggio fallisce, riprovando si riparte da lì senza doppioni.
3. **Attiva su Meta**: solo titolare o amministratore, confermando budget e date. Budget e data di fine si possono cambiare anche dopo; testi, pubblico e immagine no (si crea una nuova sponsorizzata).
4. Ogni ora il worker rilegge stato (revisione, rifiuto con il motivo) e risultati; la spesa arriva in Sponsorizzate e nelle fatture, una riga per mese.

Si pubblicano da qui gli obiettivi Notorietà, Traffico e Interazione; Contatti, Vendite e Promozione dell'app richiedono modulo, Pixel o app configurati su Meta e restano con la scheda per Gestione inserzioni. `META_ADS_MAX_DAILY_BUDGET` (default 100 €) è il tetto al budget giornaliero.

Per collegare Meta davvero:

1. Su https://developers.facebook.com crea un'app di tipo **Business**, con i prodotti **Marketing API** e **Facebook Login for Business**.
2. Tra gli URI di reindirizzamento OAuth validi aggiungi `APP_URL/api/v1/meta/callback` (serve un indirizzo HTTPS raggiungibile da Facebook).
3. Metti in `.env` `META_APP_ID` e `META_APP_SECRET` e riavvia con `docker compose up -d app worker`.

Finché l'app è in modalità sviluppo funziona solo per chi ha un ruolo nell'app (tu e i tuoi account pubblicitari): basta per provare. Per i clienti esterni servono la verifica dell'azienda e la revisione dell'app da parte di Meta per i permessi `ads_management`, `ads_read`, `business_management`, `pages_show_list`, `pages_read_engagement`, `pages_manage_ads` e `instagram_basic`. Con `META_ADS_SAMPLE=true` (e senza `META_APP_ID`) collegamento, pubblicazione e risultati usano dati di esempio, segnalati come tali.

## Test

```bash
docker compose exec app php tests/isolation.php
docker compose exec app php tests/meta_rules_check.php
docker compose exec app php tests/ai_usage_check.php
docker compose exec app php tests/meta_marketing_check.php
```

`meta_marketing_check.php` richiede `META_ADS_SAMPLE=true` senza `META_APP_ID`.

I test vanno eseguiti con il generatore di esempio: se nel `.env` c'è una chiave AI, metti prima `AI_PROVIDER=fake` e riavvia con `docker compose up -d app worker` (poi rimetti `auto`), altrimenti il worker elabora i lavori del test con l'AI vera e consuma crediti o quota.

Verifica isolamento tra organizzazioni, ruoli, revisione, campagne, calendario, sponsorizzate, fatturazione, link tracciati, report pubblici, audit dei potenziali clienti, stile appreso, altri formati, ore e redditività, recensioni, portale del cliente, concorrenti, analisi del sito (logo, contatti, strumenti, diagnosi), sponsorizzate con le regole di Meta e avvisi sui cambiamenti, protezione SSRF della lettura dei siti e limite ai tentativi di login. Usa il generatore di esempio, quindi non consuma crediti AI.

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

Traduzioni: i componenti sono scritti in italiano e `src/app/i18n/en.json` contiene le frasi in inglese. Dopo aver aggiunto o cambiato testi esegui `node scripts/i18n-extract.mjs`: le frasi nuove compaiono con valore vuoto da tradurre (`--check` fallisce se ne mancano).

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
- Per metterlo online gratis (Oracle Cloud + DuckDNS, HTTPS con Caddy, backup notturno) segui `docs/DEPLOY-ORACLE.md`.
- L'endpoint di registrazione è aperto per facilitare il test locale; prima di una beta pubblica servono rate limiting e verifica email.

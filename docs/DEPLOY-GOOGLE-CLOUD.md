# Mettere Marketing OS online gratis (Google Cloud + DuckDNS)

Costo: **zero per 90 giorni**. Google Cloud regala **300 $ di credito** ai nuovi account: il server consigliato costa
circa 35 $ al mese, quindi il credito basta per tutto il periodo di prova. Indirizzo gratuito di DuckDNS e certificato
HTTPS gratuito preso in automatico da Caddy. Tempo: circa 45 minuti la prima volta.

> **Niente addebiti a sorpresa**: durante la prova Google non addebita nulla. Quando finiscono i 90 giorni o il credito,
> il server si ferma, a meno che tu non scelga di passare a un account a pagamento.

Alla fine l'app sarà su `https://tuonome.duckdns.org`, con riavvio automatico, backup notturno e un comando per gli
aggiornamenti. Non serve installare niente sul PC: il server si usa dal browser.

---

## 1. Crea l'account Google Cloud

1. Vai su <https://cloud.google.com/free> → **Inizia gratuitamente** e accedi con un account Google.
2. Scegli **Italia**, accetta i termini e inserisci la carta: serve **solo a verificare l'identità**.
3. Si apre la console con un progetto già pronto (*My First Project*): va bene quello.

## 2. Crea il server

Menu ☰ → **Compute Engine → Istanze VM** (la prima volta premi **Abilita** e aspetta un minuto) → **Crea istanza**:

| Campo | Valore |
|---|---|
| Nome | `marketing-os` |
| Regione | `europe-west8 (Milano)` (zona qualsiasi) |
| Configurazione macchina | **E2** → tipo `e2-medium` (2 vCPU, 4 GB) |
| Sistema operativo e spazio di archiviazione | **Cambia** → *Ubuntu* → **Ubuntu 24.04 LTS** (x86/64), disco **30 GB** → Seleziona |
| Networking → Firewall | spunta **Consenti traffico HTTP** e **Consenti traffico HTTPS** |

Premi **Crea**. Dopo un minuto compare il pallino verde e l'**IP esterno**.

## 3. Rendi fisso l'indirizzo IP

Senza questo passo l'IP può cambiare quando il server si riavvia.

Menu ☰ → **Rete VPC → Indirizzi IP** → sulla riga dell'IP esterno del server, i tre puntini → **Promuovi a indirizzo IP
statico** → nome `marketing-os` → **Prenota**. Copia l'indirizzo.

## 4. Crea l'indirizzo gratuito su DuckDNS

1. Vai su <https://www.duckdns.org> e accedi (con Google o GitHub).
2. In **sub domain** scrivi il nome che vuoi (es. `marketingos`) → **add domain**.
3. Nel campo **current ip** incolla l'IP del punto 3 → **update ip**.

Il tuo indirizzo è `marketingos.duckdns.org`.

## 5. Porta il codice sul server

1. Sul PC prepara l'archivio (PowerShell, nella cartella `marketing-os`; il file `.env` con le tue chiavi **non** viene
   incluso):

   ```powershell
   git archive --format=tar.gz -o marketing-os.tar.gz HEAD
   ```

2. Nella pagina **Istanze VM** premi **SSH** sulla riga del server: si apre un terminale nel browser.
3. In quella finestra: **Carica file** (in alto a destra) → scegli `marketing-os.tar.gz`.
4. Nel terminale:

   ```bash
   mkdir -p marketing-os && tar xzf marketing-os.tar.gz -C marketing-os
   ```

## 6. Installa con un solo comando

Sempre nel terminale del browser (sostituisci il tuo indirizzo):

```bash
cd ~/marketing-os && sh infrastructure/scripts/install.sh tuonome.duckdns.org
```

Lo script installa Docker, crea il file `.env` con chiavi e password nuove, compila il sito, avvia tutto, attiva il
backup notturno e aspetta il certificato HTTPS. La prima volta ci vogliono **10-15 minuti**. Alla fine stampa
l'indirizzo e il **codice invito**: copialo.

## 7. Aggiungi le chiavi AI e Meta

Chiudi la finestra del terminale e riaprila con **SSH**: serve una volta sola, per usare Docker senza `sudo`. Poi:

```bash
cd ~/marketing-os && nano .env
```

Compila `GEMINI_API_KEY` (o `GROQ_API_KEY`) e `META_APP_ID`, `META_APP_SECRET`, `META_LOGIN_CONFIG_ID`.
Salva con **Ctrl+O**, Invio, esci con **Ctrl+X**, poi:

```bash
docker compose up -d
```

Il file `.env` resta solo sul server: non va mai su GitHub.

## 8. Primo accesso e amministratore

1. Apri `https://tuonome.duckdns.org` → **Registrati**, con il codice invito del punto 6.
2. Nel terminale, diventa amministratore della piattaforma:

   ```bash
   docker compose exec app php bin/make-admin.php tua-email@esempio.it
   ```

## 9. Aggiorna Meta for Developers

Su <https://developers.facebook.com> → la tua app:

1. **Impostazioni app → Di base → Domini app**: aggiungi `tuonome.duckdns.org`.
2. **Facebook Login for Business → Impostazioni → URI di reindirizzamento OAuth validi**: aggiungi
   `https://tuonome.duckdns.org/api/v1/meta/callback`.
3. Nell'app, nella scheda di ogni cliente: **Collega Facebook e Instagram**.

## 9b. Prova gratuita e abbonamenti (Stripe)

Ogni nuova agenzia ha **10 giorni di prova**. Poi l'app si blocca finché non si abbona (15 €/mese o 120 €/anno).
Le organizzazioni che esistevano già prima di questa funzione hanno accesso gratuito.
Dal pannello **Admin**, nella scheda di un'organizzazione, puoi dare **+10 giorni di prova** o **regalare l'accesso**.

Per incassare i pagamenti:

1. Crea l'account su <https://dashboard.stripe.com/register>. Resta in **modalità test** finché non hai provato tutto.
2. **Sviluppatori → Chiavi API**: copia la **chiave segreta** (`sk_test_…`) in `.env` → `STRIPE_SECRET_KEY=`.
3. **Sviluppatori → Webhook → Aggiungi endpoint**:
   - URL: `https://tuonome.duckdns.org/api/v1/stripe/webhook`
   - eventi: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`
   - copia il **segreto di firma** (`whsec_…`) in `.env` → `STRIPE_WEBHOOK_SECRET=`.
4. **Impostazioni → Fatturazione → Portale clienti**: premi **Salva** (serve per «Gestisci abbonamento»: carta, fatture, disdetta).
5. Sul server: `docker compose up -d && docker compose restart nginx`.
6. Prova con la carta di test `4242 4242 4242 4242` (data futura qualsiasi, CVC qualsiasi).

Per incassare davvero: completa l'attivazione dell'account su Stripe e passa alla modalità live.
Poi rifai i punti 2 e 3 con le chiavi live (`sk_live_…`), che sono diverse da quelle di test.

## 9c. Email di promemoria (prova e abbonamento in scadenza)

L'app manda un'email al titolare 3 giorni prima della fine della prova, l'ultimo giorno, quando è scaduta e 3 giorni
prima della fine di un abbonamento disdetto. Serve un servizio SMTP; il più semplice e gratuito è Brevo (300 email al giorno):

1. Crea l'account su <https://www.brevo.com> e verifica l'indirizzo mittente (**Mittenti, domini e IP dedicati → Mittenti**).
2. **SMTP e API → SMTP**: genera una **chiave SMTP**.
3. Nel `.env` del server (mai in chat né su GitHub):
   `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=587`, `MAIL_USERNAME=` (il login SMTP mostrato da Brevo),
   `MAIL_PASSWORD=` (la chiave SMTP), `MAIL_FROM=` (l'indirizzo verificato).
4. `docker compose up -d`: il worker controlla le scadenze ogni ora (`docker compose logs worker` mostra gli invii).

## 10. Aggiornare dopo una modifica

Rifai il punto 5 (archivio, **Carica file**, `tar xzf …`: sovrascrive il codice ma non tocca `.env` né i dati), poi:

```bash
cd ~/marketing-os && sh infrastructure/scripts/update.sh
```

---

## Controllare il credito

Menu ☰ → **Fatturazione**: vedi il credito rimasto e i giorni di prova. Per spegnere tutto quando hai finito di
provare: **Istanze VM** → seleziona il server → **Elimina**, e in **Indirizzi IP** → **Rilascia** l'IP statico.

## Comandi utili (nel terminale del server, dentro `~/marketing-os`)

| Cosa | Comando |
|---|---|
| Stato dei container | `docker compose ps` |
| Log dell'API / del worker / di Caddy | `docker compose logs -f app` · `worker` · `caddy` |
| Riavviare tutto | `docker compose restart` |
| Backup subito | `sh infrastructure/scripts/backup.sh` |

Backup, ripristino e trasferimento dei dati dal PC funzionano come in `docs/DEPLOY-ORACLE.md` (punti 12 e
"Portare i dati dal PC").

## Se qualcosa non va

| Problema | Cosa controllare |
|---|---|
| Il sito non si apre | punto 2: *Consenti traffico HTTP/HTTPS* spuntati? Poi `docker compose logs caddy` |
| Errore sul certificato | DuckDNS punta all'IP giusto? (`ping tuonome.duckdns.org`) |
| Pagina bianca o 404 | il frontend è compilato? (`ls frontend/dist/frontend/browser`) |
| Errori dell'API | `docker compose logs app worker` |
| Facebook non torna all'app | l'URI del punto 9 deve essere identico, con `https://` |

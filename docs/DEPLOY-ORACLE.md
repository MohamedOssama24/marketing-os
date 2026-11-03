# Mettere Marketing OS online gratis (Oracle Cloud + DuckDNS)

Costo: **zero**. Server gratuito di Oracle Cloud ("Always Free"), indirizzo gratuito di DuckDNS e certificato HTTPS
gratuito preso in automatico da Caddy. Tempo: circa un'ora la prima volta.

Alla fine l'app sarà su `https://tuonome.duckdns.org`, con riavvio automatico, backup notturno e un comando per gli aggiornamenti.

---

## 1. Crea l'account Oracle Cloud

1. Vai su <https://www.oracle.com/cloud/free/> → **Start for free**.
2. Chiede una carta di credito **solo per verificare l'identità**: finché usi le risorse "Always Free" non addebita nulla.
3. **Home region**: sceglila con attenzione, **non si può cambiare dopo**. Consigliate: *Italy Northwest (Milan)*,
   *Germany Central (Frankfurt)* o *Netherlands Northwest (Amsterdam)*. I server gratuiti si creano solo nella home region.

> Oracle può riprendersi un server gratuito se resta quasi inutilizzato per 7 giorni (CPU, rete e memoria molto basse).
> Con l'app in uso non succede; per evitarlo del tutto puoi passare l'account a "Pay As You Go": resta gratis finché
> stai nei limiti Always Free.

## 2. Crea il server

Menu ☰ → **Compute → Instances → Create instance**:

| Campo | Valore |
|---|---|
| Name | `marketing-os` |
| Image | **Canonical Ubuntu 24.04** (Change image → Ubuntu) |
| Shape | Change shape → **Ampere** → `VM.Standard.A1.Flex`, **2 OCPU e 12 GB** di memoria (sta nei limiti gratuiti) |
| Networking | lascia la rete proposta, con **Assign a public IPv4 address** attivo |
| SSH keys | **Generate a key pair for me** → scarica la **chiave privata** (file `.key`) e conservala |
| Boot volume | lascia il valore proposto (circa 47 GB, gratis fino a 200 GB) |

Premi **Create**. Dopo un paio di minuti lo stato diventa *Running*: copia il **Public IP address**.

> Se compare **"Out of capacity"**: riprova più tardi, prova un altro *Availability domain* nella stessa pagina,
> oppure scegli 1 OCPU e 6 GB (bastano anche quelli).

## 3. Apri le porte 80 e 443 su Oracle

Nella pagina del server → **Subnet** (link sotto *Primary VNIC*) → **Security Lists** → *Default Security List* →
**Add Ingress Rules**, due regole:

| Source CIDR | IP Protocol | Destination port |
|---|---|---|
| `0.0.0.0/0` | TCP | `80` |
| `0.0.0.0/0` | TCP | `443` |

(La porta 22 per SSH è già aperta.)

## 4. Crea l'indirizzo gratuito su DuckDNS

1. Vai su <https://www.duckdns.org> e accedi (con Google o GitHub).
2. In **sub domain** scrivi il nome che vuoi (es. `marketingos`) → **add domain**.
3. Nel campo **current ip** incolla il **Public IP** di Oracle → **update ip**.

Il tuo indirizzo è `marketingos.duckdns.org`. L'IP di Oracle resta lo stesso finché non elimini il server, quindi non serve altro.

## 5. Entra nel server

Da PowerShell sul tuo PC (sostituisci percorso della chiave e IP):

```powershell
ssh -i C:\Users\mohamed\Downloads\ssh-key.key ubuntu@IP-DEL-SERVER
```

Se Windows dice che la chiave ha permessi troppo aperti, una volta sola:

```powershell
icacls C:\Users\mohamed\Downloads\ssh-key.key /inheritance:r /grant:r "$($env:USERNAME):(R)"
```

Tutti i comandi da qui in poi si incollano **nel server** (dopo `ubuntu@marketing-os:~$`).

## Modo veloce: installazione con un solo comando

I passi da 6 a 9 e il 12 li fa tutti lo script `install.sh`: firewall, Docker, `.env` con chiavi nuove, frontend,
avvio e backup notturno. Con il codice già sul server (punto 7):

```bash
cd ~/marketing-os && sh infrastructure/scripts/install.sh tuonome.duckdns.org
```

Alla fine stampa l'indirizzo e il codice invito. Le chiavi AI e Meta si aggiungono dopo con `nano .env` e
`docker compose up -d`. Se preferisci fare tutto a mano, segui i punti qui sotto.

## 6. Prepara il server

```bash
# Aggiornamenti e firewall interno di Ubuntu (sulle immagini Oracle blocca tutto tranne SSH)
sudo apt update && sudo apt -y upgrade
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
sudo netfilter-persistent save

# Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
exit
```

Rientra con lo stesso comando `ssh` del punto 5 (serve per usare Docker senza `sudo`) e controlla:

```bash
docker run --rm hello-world
```

## 7. Scarica il progetto

```bash
git clone https://github.com/MohamedOssama24/marketing-os.git
cd marketing-os
```

Se il repository è **privato**, Git chiede nome utente e password: come password usa un *token* creato su GitHub →
Settings → Developer settings → **Fine-grained tokens**, con accesso in sola lettura (*Contents: Read*) a questo repository.

In alternativa, senza token, copia il codice dal PC (PowerShell, nella cartella `marketing-os`):

```powershell
git archive --format=tar.gz -o marketing-os.tar.gz HEAD
scp -i C:\Users\mohamed\Downloads\ssh-key.key marketing-os.tar.gz ubuntu@IP-DEL-SERVER:
ssh -i C:\Users\mohamed\Downloads\ssh-key.key ubuntu@IP-DEL-SERVER "mkdir -p marketing-os && tar xzf marketing-os.tar.gz -C marketing-os"
```

Per gli aggiornamenti si ripetono questi tre comandi e poi `update.sh` (punto 13).

## 8. Configura il file `.env`

```bash
cp .env.production.example .env
# Genera i valori segreti e copiali (uno per riga: APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD, codice invito)
openssl rand -hex 32; openssl rand -hex 24; openssl rand -hex 24; openssl rand -hex 8
nano .env
```

In `nano` sostituisci ogni `CAMBIA-…`:

- `DOMAIN`, `APP_URL`, `CORS_ORIGIN`, `FRONTEND_URL` → il tuo indirizzo DuckDNS (in `DOMAIN` senza `https://`);
- `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` → i valori generati sopra;
- `REGISTRATION_INVITE_CODE` → l'ultimo valore generato (servirà per registrarti);
- chiavi AI (`GEMINI_API_KEY`, `GROQ_API_KEY`…) e Meta (`META_APP_ID`, `META_APP_SECRET`, `META_LOGIN_CONFIG_ID`).

Salva con **Ctrl+O**, Invio, ed esci con **Ctrl+X**. Il file `.env` resta solo sul server: non va mai su GitHub.

## 9. Avvia

```bash
chmod +x infrastructure/scripts/*.sh
# Compila il frontend (dentro un container Node, non serve installarlo)
docker run --rm -v "$PWD/frontend:/app" -w /app node:20-alpine sh -c "npm ci --no-audit --no-fund && npx ng build"
# Avvia database, API, worker, nginx e Caddy
docker compose up -d --build
docker compose ps
```

La prima volta ci vogliono alcuni minuti. Poi apri `https://tuonome.duckdns.org`: Caddy prende il certificato HTTPS
al primo accesso (se la pagina non risponde subito, aspetta un minuto). Verifica anche:

```bash
curl https://tuonome.duckdns.org/api/v1/health
```

## 10. Primo accesso e amministratore

1. Sul sito → **Registrati**, con il **codice invito** messo in `REGISTRATION_INVITE_CODE`.
2. Diventa amministratore della piattaforma:

```bash
docker compose exec app php bin/make-admin.php tua-email@esempio.it
```

## 11. Aggiorna Meta for Developers

Su <https://developers.facebook.com> → la tua app:

1. **Impostazioni app → Di base → Domini app**: aggiungi `tuonome.duckdns.org`.
2. **Facebook Login for Business → Impostazioni → URI di reindirizzamento OAuth validi**: aggiungi
   `https://tuonome.duckdns.org/api/v1/meta/callback`.
3. Nell'app, nella scheda di ogni cliente: **Collega Facebook e Instagram**.

Non serve più nessun tunnel: l'indirizzo non cambia.

## 12. Backup notturno

```bash
crontab -e
```

Aggiungi in fondo questa riga, salva ed esci:

```
0 3 * * * /home/ubuntu/marketing-os/infrastructure/scripts/backup.sh >> /home/ubuntu/backup-marketing-os/backup.log 2>&1
```

Ogni notte alle 3 salva database e file caricati in `/home/ubuntu/backup-marketing-os` (tiene 14 giorni).
Per provarlo subito: `./infrastructure/scripts/backup.sh`. Ogni tanto scarica una copia sul PC:

```powershell
scp -i C:\Users\mohamed\Downloads\ssh-key.key -r ubuntu@IP-DEL-SERVER:backup-marketing-os C:\Users\mohamed\Desktop\
```

Ripristino del database da un backup:

```bash
gunzip -c ~/backup-marketing-os/db-AAAA-MM-GG.sql.gz | docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 13. Aggiornare dopo un push su GitHub

```bash
cd ~/marketing-os && ./infrastructure/scripts/update.sh
```

Scarica il codice nuovo, ricompila il frontend, riavvia i container; le migrazioni del database partono da sole.

---

## Comandi utili

| Cosa | Comando |
|---|---|
| Stato dei container | `docker compose ps` |
| Log dell'API / del worker / di Caddy | `docker compose logs -f app` · `worker` · `caddy` |
| Riavviare tutto | `docker compose restart` |
| Spazio su disco | `df -h` |

## Portare i dati dal PC (facoltativo)

Se vuoi ritrovare sul server i clienti creati in locale, sul PC (cartella `marketing-os`, PowerShell):

```powershell
docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > dati.sql
scp -i C:\Users\mohamed\Downloads\ssh-key.key dati.sql ubuntu@IP-DEL-SERVER:
```

Sul server, **prima di registrarti** (punto 10):

```bash
docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < ~/dati.sql
docker compose restart worker
```

Gli utenti e le password restano quelli del PC. I collegamenti a Meta sono cifrati con l'`APP_KEY` del PC:
con un'`APP_KEY` nuova vanno semplicemente ricollegati (**Collega Facebook e Instagram**). Le immagini caricate
non sono nel database: si ricaricano, oppure si copia il volume dei file caricati.

## Se qualcosa non va

| Problema | Cosa controllare |
|---|---|
| Il sito non si apre | punto 3 (porte su Oracle) e punto 6 (`iptables`), poi `docker compose logs caddy` |
| Errore sul certificato | DuckDNS punta all'IP giusto? (`ping tuonome.duckdns.org`) |
| Pagina bianca o 404 | il frontend è compilato? (`ls frontend/dist/frontend/browser`) |
| Errori dell'API | `docker compose logs app worker` |
| Facebook non torna all'app | l'URI del punto 11 deve essere identico, con `https://` |

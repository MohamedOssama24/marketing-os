# Roadmap e criteri di accettazione

## Milestone 0 — ambiente locale (1–2 ore)
- [ ] Copiare `.env.example` in `.env` e impostare password casuali.
- [ ] `docker compose up --build -d` completa senza errori.
- [ ] `GET /api/v1/health` restituisce `status=ok`.

## Milestone 1 — identità e tenant (4–8 ore)
- [ ] Registrazione crea un'organizzazione e un utente owner.
- [ ] Login restituisce token casuale e il DB contiene solo l'hash.
- [ ] Logout revoca il token.
- [ ] Test con due organizzazioni: il token A non può leggere o modificare i dati di B.
- [ ] Aggiungere rate limit, verifica email e recupero password prima della beta pubblica.

## Milestone 2 — progetti e brand (6–10 ore)
- [ ] Creazione e lista progetti.
- [ ] Campi validati lato server.
- [ ] Brand collegabile solo se appartiene alla stessa organizzazione.
- [ ] CRUD brand con test cross-tenant.

## Milestone 3 — contenuti (8–12 ore)
- [ ] Creazione/modifica contenuti per canale.
- [ ] Stati draft, in_review, approved, scheduled, published, rejected.
- [ ] Storico modifiche e approvazione.

## Milestone 4 — AI assistita (6–12 ore)
- [ ] Interfaccia provider separata dalla logica applicativa.
- [ ] Brief strutturato e output JSON validato.
- [ ] Costi e utilizzi registrati.
- [ ] Contenuto generato sempre in bozza, mai pubblicato senza approvazione.

Le stime sono indicative e non includono deploy, QA approfondito o integrazioni di terze parti.

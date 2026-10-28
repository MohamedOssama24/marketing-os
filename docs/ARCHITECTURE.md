# Architettura iniziale

## Decisioni

- Monolite modulare PHP 8.3 + PDO per ridurre complessità e costi nell'MVP.
- Angular resta un frontend separato e consuma API REST versionate.
- MySQL 8.4 con InnoDB e UTF-8.
- Nginx espone solo `public/`; PHP-FPM non è esposto all'host.
- Token Bearer casuali; il database contiene solo hash SHA-256 del token. Le password usano `password_hash()`.
- Tenant isolation applicata in ogni query delle risorse cliente usando `organization_id` ricavato dall'utente autenticato.
- AI, WordPress/WooCommerce, pagamenti e scheduler si aggiungeranno come moduli successivi.

## Limiti noti del prototipo

Questo è uno starter locale, non un sistema pronto per produzione. Prima di esporlo su Internet bisogna aggiungere almeno: rate limiting distribuito per login/registrazione, verifica email e recupero password, migrazioni versionate, policy CORS per ambiente, logging strutturato e redazione dei dati sensibili, test automatici, backup e restore verificati, HTTPS, gestione delle chiavi/segreti, aggiornamenti dipendenze e revisione di sicurezza. `APP_DEBUG` deve essere `false` in ambienti non locali.

## Evoluzione consigliata

1. Test e hardening dell'autenticazione.
2. CRUD brand e contenuti, con workflow di approvazione.
3. Adapter AI e tracciamento dei costi.
4. Import dati WordPress/WooCommerce tramite plugin OAuth/REST con privilegi minimi.
5. Report e metriche.
6. Billing SaaS.

Valutare Laravel quando si avvia la fase di produzione e crescono ruoli, code, notifiche e integrazioni; evitare una migrazione durante il primo prototipo senza una necessità concreta.

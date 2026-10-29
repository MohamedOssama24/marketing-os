-- Musica delle sponsorizzate: libreria di Meta (con l'atmosfera consigliata), traccia della libreria del cliente o nessuna.
ALTER TABLE ad_plans ADD COLUMN music JSON NULL AFTER url;

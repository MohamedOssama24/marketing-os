// Service worker minimo: rende l'app installabile. Non mette in cache niente, così dati e API sono sempre aggiornati.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});

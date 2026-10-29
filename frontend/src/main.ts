import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { AppComponent } from './app/app.component';
import { LANG, startTranslation } from './app/i18n/i18n';

// In inglese le traduzioni si caricano (a parte, solo per chi le usa) prima di disegnare l'app.
(LANG === 'en' ? import('./app/i18n/en.json').then((m) => startTranslation(m.default)) : Promise.resolve())
  .then(() => bootstrapApplication(AppComponent, appConfig))
  .catch((err) => console.error(err));

// App installabile sul telefono: il service worker serve solo a renderla installabile, non mette in cache le API.
if ('serviceWorker' in navigator && location.protocol === 'https:') {
  navigator.serviceWorker.register('/sw.js').catch(() => undefined);
}

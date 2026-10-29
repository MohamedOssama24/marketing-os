import { ApplicationConfig, LOCALE_ID } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { registerLocaleData } from '@angular/common';
import localeIt from '@angular/common/locales/it';
import localeEnGb from '@angular/common/locales/en-GB';

import { routes } from './app.routes';
import { authInterceptor } from './core/auth.interceptor';
import { LOCALE } from './i18n/i18n';

registerLocaleData(localeIt);
registerLocaleData(localeEnGb);

export const appConfig: ApplicationConfig = {
  providers: [
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
    { provide: LOCALE_ID, useValue: LOCALE },
  ],
};

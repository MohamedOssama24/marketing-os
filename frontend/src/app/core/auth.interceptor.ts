import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { API_URL } from './models';
import { AuthService } from './auth.service';

/** Aggiunge il token Bearer alle chiamate verso le API e chiude la sessione se il token non è più valido. */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const isApi = req.url.startsWith(API_URL);
  const isAuthCall = req.url.includes('/auth/login') || req.url.includes('/auth/register');

  if (isApi && auth.token && !isAuthCall) {
    req = req.clone({ setHeaders: { Authorization: `Bearer ${auth.token}` } });
  }

  return next(req).pipe(
    catchError((err: HttpErrorResponse) => {
      if (isApi && !isAuthCall && err.status === 401) {
        auth.clearSession();
        router.navigate(['/login']);
      }
      // Organizzazione sospesa dall'amministratore mentre la sessione era aperta.
      if (isApi && !isAuthCall && err.status === 403 && err.error?.error === 'account_suspended') {
        auth.clearSession();
        router.navigate(['/login'], { queryParams: { sospeso: 1 } });
      }
      // Prova scaduta durante l'uso: si aggiorna lo stato e si va alla pagina dell'abbonamento.
      if (isApi && !isAuthCall && err.status === 402 && err.error?.error === 'subscription_required') {
        auth.reloadMe().subscribe(() => router.navigate(['/subscription']));
      }
      return throwError(() => err);
    }),
  );
};

import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, catchError, of, tap } from 'rxjs';
import { API_URL, AuthResponse, User } from './models';

const TOKEN_KEY = 'mos_token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private router = inject(Router);

  private readonly tokenSignal = signal<string | null>(this.readToken());
  readonly user = signal<User | null>(null);
  readonly isLoggedIn = computed(() => this.tokenSignal() !== null);
  /** Può creare e modificare brand, file, progetti e contenuti (owner, admin, editor). */
  readonly canEdit = computed(() => ['owner', 'admin', 'editor'].includes(this.user()?.role ?? ''));
  /** Può approvare contenuti e gestire il team (owner, admin). */
  readonly canManage = computed(() => ['owner', 'admin'].includes(this.user()?.role ?? ''));

  get token(): string | null {
    return this.tokenSignal();
  }

  login(email: string, password: string): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>(`${API_URL}/auth/login`, { email, password })
      .pipe(tap((res) => this.storeSession(res)));
  }

  register(payload: { name: string; email: string; password: string; organization_name: string }): Observable<AuthResponse> {
    return this.http
      .post<AuthResponse>(`${API_URL}/auth/register`, payload)
      .pipe(tap((res) => this.storeSession(res)));
  }

  /** Carica l'utente corrente se c'è un token salvato ma l'utente non è ancora in memoria. */
  loadMe(): Observable<unknown> {
    if (!this.token || this.user()) return of(null);
    return this.http.get<{ data: { user: User } }>(`${API_URL}/me`).pipe(
      tap((res) => this.user.set(res.data.user)),
      catchError(() => of(null)),
    );
  }

  logout(): void {
    if (this.token) {
      // La revoca lato server è best-effort: la sessione locale viene chiusa comunque.
      this.http.post(`${API_URL}/auth/logout`, {}).subscribe({ error: () => {} });
    }
    this.clearSession();
    this.router.navigate(['/login']);
  }

  clearSession(): void {
    this.tokenSignal.set(null);
    this.user.set(null);
    try { localStorage.removeItem(TOKEN_KEY); } catch {}
  }

  private storeSession(res: AuthResponse): void {
    this.tokenSignal.set(res.data.token.access_token);
    this.user.set(res.data.user);
    try { localStorage.setItem(TOKEN_KEY, res.data.token.access_token); } catch {}
  }

  private readToken(): string | null {
    try { return localStorage.getItem(TOKEN_KEY); } catch { return null; }
  }
}

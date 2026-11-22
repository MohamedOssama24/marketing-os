import { Component, HostListener, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Alert, ROLE_LABELS } from '../core/models';
import { CommandPaletteComponent } from './command-palette.component';
import { LangSwitchComponent } from './lang-switch.component';
import { currentTheme, toggleTheme } from './theme';

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [DatePipe, RouterOutlet, RouterLink, RouterLinkActive, CommandPaletteComponent, LangSwitchComponent],
  template: `
    <div class="shell">
      <aside class="sidebar">
        <div class="brand"><span class="brand-mark"><i class="ti ti-sparkles" aria-hidden="true"></i></span>Marketing <span>OS</span></div>
        <div class="sidebar-tools">
          <button class="search-btn" (click)="palette.show()" title="Ricerca rapida (Ctrl+K)">
            <i class="ti ti-search" aria-hidden="true"></i><span>Cerca…</span><kbd>{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
          </button>
          <button class="icon-btn bell" (click)="alertsOpen.set(!alertsOpen())" [attr.aria-expanded]="alertsOpen()" aria-label="Avvisi" title="Avvisi">
            <i class="ti ti-bell" aria-hidden="true"></i>
            @if (alerts().length) { <span class="bell-count">{{ alerts().length > 9 ? '9+' : alerts().length }}</span> }
          </button>
        </div>
        @if (alertsOpen()) {
          <div class="alerts-popover" role="dialog" aria-label="Avvisi">
            <div class="alerts-popover-head"><strong>Avvisi</strong><button class="icon-btn" (click)="alertsOpen.set(false)" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button></div>
            @for (a of alerts(); track a.key) {
              <a [class]="'alert-row ' + a.severity" [routerLink]="a.link" [queryParams]="a.query" (click)="alertsOpen.set(false)">
                <span class="alert-icon"><i class="ti" [class]="'ti ' + a.icon" aria-hidden="true"></i></span>
                <span class="grow"><strong>{{ a.title }}</strong><small class="muted">{{ a.detail }}</small></span>
              </a>
            } @empty {
              <p class="muted small alerts-empty"><i class="ti ti-circle-check" aria-hidden="true"></i> Tutto sotto controllo.</p>
            }
          </div>
        }
        <nav>
          @for (item of nav; track item.path) {
            <a [routerLink]="item.path" routerLinkActive="active"><i class="ti" [class]="'ti ' + item.icon" aria-hidden="true"></i>{{ item.label }}</a>
          }
          @if (auth.canManage()) {
            <a routerLink="/billing" routerLinkActive="active"><i class="ti ti-file-invoice" aria-hidden="true"></i>Fatturazione</a>
            @if (auth.user()?.subscription?.state !== 'free') {
              <a routerLink="/subscription"><i class="ti ti-credit-card" aria-hidden="true"></i>Abbonamento</a>
            }
          }
          @if (auth.isPlatformAdmin()) {
            <a routerLink="/admin" routerLinkActive="active" class="nav-admin"><i class="ti ti-shield-lock" aria-hidden="true"></i>Admin</a>
          }
        </nav>
        <div class="sidebar-user">
          @if (auth.user(); as user) {
            <span class="user-avatar">{{ user.name.charAt(0) }}</span>
            <div class="user-info">
              <strong>{{ user.name }}</strong>
              <small class="muted">{{ roleLabels[user.role] }}</small>
            </div>
          }
          <app-lang-switch />
          <button class="icon-btn" (click)="switchTheme()" [attr.aria-label]="theme() === 'dark' ? 'Tema chiaro' : 'Tema scuro'" [title]="theme() === 'dark' ? 'Tema chiaro' : 'Tema scuro'">
            <i class="ti" [class]="theme() === 'dark' ? 'ti ti-sun' : 'ti ti-moon'" aria-hidden="true"></i>
          </button>
          <button class="icon-btn" (click)="auth.logout()" aria-label="Esci" title="Esci"><i class="ti ti-logout" aria-hidden="true"></i></button>
        </div>
      </aside>
      <main class="shell-main">
        @if (auth.user()?.subscription; as s) {
          @if (s.state === 'trial') {
            <div class="notice" [class.warning]="(s.trial_days_left ?? 0) <= 3">
              <i class="ti ti-hourglass" aria-hidden="true"></i>
              <span class="grow">Prova gratuita: {{ (s.trial_days_left ?? 0) <= 1 ? 'oggi è l\\'ultimo giorno' : 'mancano ' + s.trial_days_left + ' giorni' }}.</span>
              <a class="btn primary small" routerLink="/subscription">Abbonati</a>
            </div>
          }
        }
        <router-outlet />
      </main>
    </div>
    <app-command-palette />
    @if (reminder(); as r) {
      <div class="modal-backdrop" (click)="dismissReminder()">
        <div class="modal reminder-modal" role="dialog" aria-modal="true" aria-labelledby="reminder-title" (click)="$event.stopPropagation()">
          <button class="icon-btn reminder-close" (click)="dismissReminder()" aria-label="Chiudi" title="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button>
          <span class="reminder-icon"><i class="ti ti-hourglass" aria-hidden="true"></i></span>
          <h2 id="reminder-title">{{ r.title }}</h2>
          <p>
            @if (r.kind === 'trial') {
              La prova gratuita termina il <strong>{{ r.endsAt | date: 'd MMMM, HH:mm' }}</strong>. Dopo, l'app si blocca finché non ti abboni.
            } @else {
              Hai disdetto l'abbonamento: l'accesso termina il <strong>{{ r.endsAt | date: 'd MMMM, HH:mm' }}</strong>.
            }
          </p>
          <p class="muted small">I tuoi clienti, le campagne e i contenuti restano salvati.</p>
          <div class="reminder-actions">
            <button class="btn ghost" (click)="dismissReminder()">Più tardi</button>
            <a class="btn primary" routerLink="/subscription" (click)="dismissReminder()">{{ r.kind === 'trial' ? 'Abbonati ora' : 'Riattiva l\\'abbonamento' }}</a>
          </div>
        </div>
      </div>
    }
  `,
})
export class ShellComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private router = inject(Router);
  @ViewChild(CommandPaletteComponent, { static: true }) palette!: CommandPaletteComponent;
  readonly roleLabels = ROLE_LABELS;
  readonly isMac = /Mac|iPhone|iPad/.test(navigator.platform);
  alerts = signal<Alert[]>([]);
  alertsOpen = signal(false);
  theme = signal(currentTheme());
  readonly nav = [
    { path: '/dashboard', label: 'Dashboard', icon: 'ti-layout-dashboard' },
    { path: '/clients', label: 'Clienti', icon: 'ti-building-store' },
    { path: '/prospects', label: 'Potenziali clienti', icon: 'ti-target-arrow' },
    { path: '/campaigns', label: 'Campagne', icon: 'ti-speakerphone' },
    { path: '/calendar', label: 'Calendario', icon: 'ti-calendar' },
    { path: '/approvals', label: 'Approvazioni', icon: 'ti-checks' },
    { path: '/assets', label: 'Libreria', icon: 'ti-photo' },
    { path: '/ads', label: 'Sponsorizzate', icon: 'ti-coin-euro' },
    { path: '/links', label: 'Link e clic', icon: 'ti-click' },
    { path: '/reports', label: 'Report clienti', icon: 'ti-report-analytics' },
    { path: '/reviews', label: 'Recensioni', icon: 'ti-star' },
    { path: '/profitability', label: 'Ore e redditività', icon: 'ti-clock-dollar' },
    { path: '/team', label: 'Team', icon: 'ti-users' },
  ];

  /** Promemoria al centro dello schermo quando la prova o l'abbonamento disdetto stanno per finire (solo per chi può abbonarsi). */
  private reminderDismissed = signal(readDismissed());
  readonly reminder = computed(() => {
    const s = this.auth.user()?.subscription;
    if (!s || !this.auth.canManage()) return null;
    let r: { kind: 'trial' | 'canceled'; title: string; endsAt: string } | null = null;
    if (s.state === 'trial' && s.trial_ends_at && (s.trial_days_left ?? 99) <= 3) {
      const days = s.trial_days_left ?? 0;
      r = { kind: 'trial', endsAt: s.trial_ends_at, title: days <= 1 ? 'Oggi è l\'ultimo giorno di prova' : `La prova finisce tra ${days} giorni` };
    } else if (s.state === 'active' && s.cancel_at_period_end && s.current_period_end
      && new Date(s.current_period_end).getTime() - Date.now() <= 7 * 86400000) {
      r = { kind: 'canceled', endsAt: s.current_period_end, title: 'Il tuo abbonamento sta per scadere' };
    }
    // Chiuso una volta, non riappare fino al prossimo accesso (o se la scadenza cambia).
    return r && this.reminderDismissed() !== r.kind + r.endsAt ? r : null;
  });

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.reminder()) this.dismissReminder();
  }

  dismissReminder(): void {
    const r = this.reminder();
    if (!r) return;
    this.reminderDismissed.set(r.kind + r.endsAt);
    try { sessionStorage.setItem(REMINDER_KEY, r.kind + r.endsAt); } catch { /* archiviazione non disponibile */ }
  }

  ngOnInit(): void {
    this.auth.loadMe().subscribe();
    this.loadAlerts();
    // Gli avvisi si aggiornano a ogni cambio di pagina (approvazioni fatte, richieste lette…).
    this.router.events.pipe(filter((e) => e instanceof NavigationEnd)).subscribe(() => this.loadAlerts());
  }

  switchTheme(): void {
    this.theme.set(toggleTheme());
  }

  private loadAlerts(): void {
    this.api.alerts().subscribe({ next: (list) => this.alerts.set(list), error: () => undefined });
  }
}

const REMINDER_KEY = 'mos-subscription-reminder';

function readDismissed(): string | null {
  try { return sessionStorage.getItem(REMINDER_KEY); } catch { return null; }
}

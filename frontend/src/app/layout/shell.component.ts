import { Component, OnInit, ViewChild, inject, signal } from '@angular/core';
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
  imports: [RouterOutlet, RouterLink, RouterLinkActive, CommandPaletteComponent, LangSwitchComponent],
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
        <router-outlet />
      </main>
    </div>
    <app-command-palette />
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

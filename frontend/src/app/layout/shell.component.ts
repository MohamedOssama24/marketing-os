import { Component, OnInit, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';
import { ROLE_LABELS } from '../core/models';

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <div class="shell">
      <aside class="sidebar">
        <div class="brand"><span class="brand-mark"><i class="ti ti-sparkles" aria-hidden="true"></i></span>Marketing <span>OS</span></div>
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
          <button class="icon-btn" (click)="auth.logout()" aria-label="Esci" title="Esci"><i class="ti ti-logout" aria-hidden="true"></i></button>
        </div>
      </aside>
      <main class="shell-main">
        <router-outlet />
      </main>
    </div>
  `,
})
export class ShellComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly roleLabels = ROLE_LABELS;
  readonly nav = [
    { path: '/dashboard', label: 'Dashboard', icon: 'ti-layout-dashboard' },
    { path: '/clients', label: 'Clienti', icon: 'ti-building-store' },
    { path: '/campaigns', label: 'Campagne', icon: 'ti-speakerphone' },
    { path: '/calendar', label: 'Calendario', icon: 'ti-calendar' },
    { path: '/approvals', label: 'Approvazioni', icon: 'ti-checks' },
    { path: '/assets', label: 'Libreria', icon: 'ti-photo' },
    { path: '/ads', label: 'Sponsorizzate', icon: 'ti-coin-euro' },
    { path: '/team', label: 'Team', icon: 'ti-users' },
  ];

  ngOnInit(): void {
    this.auth.loadMe().subscribe();
  }
}

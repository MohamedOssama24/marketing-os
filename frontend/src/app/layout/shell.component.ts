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
        <div class="brand">Marketing <span>OS</span></div>
        <nav>
          <a routerLink="/projects" routerLinkActive="active">Progetti</a>
          <a routerLink="/brands" routerLinkActive="active">Brand kit</a>
          <a routerLink="/assets" routerLinkActive="active">Libreria file</a>
          <a routerLink="/team" routerLinkActive="active">Team</a>
        </nav>
        <div class="sidebar-user">
          @if (auth.user(); as user) {
            <strong>{{ user.name }}</strong>
            <small class="muted">{{ roleLabels[user.role] }}</small>
          }
          <button class="btn ghost small" (click)="auth.logout()">Esci</button>
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

  ngOnInit(): void {
    this.auth.loadMe().subscribe();
  }
}

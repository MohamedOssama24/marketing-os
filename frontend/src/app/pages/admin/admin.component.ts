import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AdminOrganization, AdminOverview, apiErrorMessage } from '../../core/models';

/** Date salvate in UTC dal database ("2026-10-28 13:00:00") in formato ISO, per mostrarle nell'ora locale. */
export function utc(value: string | null): string | null {
  return value ? value.replace(' ', 'T') + 'Z' : null;
}

export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 KB';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  if (bytes < 1024 ** 3) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

/** Pannello della piattaforma: numeri complessivi ed elenco di tutte le organizzazioni. */
@Component({
  selector: 'app-admin',
  standalone: true,
  imports: [DatePipe, FormsModule],
  template: `
    <div class="page-head">
      <div>
        <h1><i class="ti ti-shield-lock admin-icon" aria-hidden="true"></i> Admin piattaforma</h1>
        <p class="muted">Tutte le organizzazioni che usano Marketing OS, con uso e stato degli account.</p>
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (overview(); as o) {
      <div class="metrics admin-metrics">
        <div class="metric"><span class="metric-icon"><i class="ti ti-building" aria-hidden="true"></i></span><span class="metric-label">Organizzazioni</span><strong>{{ o.organizations }}<small class="muted"> · {{ o.suspended }} sospese</small></strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-user-plus" aria-hidden="true"></i></span><span class="metric-label">Nuove negli ultimi 30 giorni</span><strong>{{ o.new_last_30_days }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-users" aria-hidden="true"></i></span><span class="metric-label">Utenti · attivi in 7 giorni</span><strong>{{ o.users }}<small class="muted"> · {{ o.active_users_7_days }}</small></strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-building-store" aria-hidden="true"></i></span><span class="metric-label">Clienti gestiti</span><strong>{{ o.clients }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-speakerphone" aria-hidden="true"></i></span><span class="metric-label">Campagne · contenuti</span><strong>{{ o.campaigns }}<small class="muted"> · {{ o.content }}</small></strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-send" aria-hidden="true"></i></span><span class="metric-label">Pubblicati nel mese</span><strong>{{ o.published_this_month }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-sparkles" aria-hidden="true"></i></span><span class="metric-label">Lavori AI · video nel mese</span><strong>{{ o.ai_jobs_this_month }}<small class="muted"> · {{ o.videos_this_month }}</small></strong></div>
        <div class="metric" [class.warn]="o.failed_jobs_7_days > 0"><span class="metric-icon"><i class="ti ti-alert-triangle" aria-hidden="true"></i></span><span class="metric-label">Lavori falliti in 7 giorni</span><strong>{{ o.failed_jobs_7_days }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-database" aria-hidden="true"></i></span><span class="metric-label">Spazio file</span><strong>{{ bytes(o.storage_bytes) }}</strong></div>
      </div>
    }

    <div class="section-head">
      <h2>Organizzazioni</h2>
      <div class="head-controls">
        <div class="input-icon admin-search">
          <i class="ti ti-search" aria-hidden="true"></i>
          <input [(ngModel)]="query" (ngModelChange)="search$.next()" placeholder="Nome o email del titolare" aria-label="Cerca organizzazione" />
        </div>
        <select [(ngModel)]="status" (ngModelChange)="load()" aria-label="Filtra per stato">
          <option value="">Tutte</option>
          <option value="active">Attive</option>
          <option value="suspended">Sospese</option>
        </select>
      </div>
    </div>

    <div class="card table-card">
      <table class="admin-table">
        <thead>
          <tr><th>Organizzazione</th><th>Stato</th><th>Utenti</th><th>Clienti</th><th>Campagne</th><th>Contenuti</th><th>AI / mese</th><th>Spazio</th><th>Ultimo accesso</th><th>Creata</th></tr>
        </thead>
        <tbody>
          @for (org of organizations(); track org.id) {
            <tr class="clickable" (click)="open(org)" (keydown.enter)="open(org)" tabindex="0">
              <td><strong>{{ org.name }}</strong><br /><small class="muted">{{ org.owner_email ?? '—' }}</small></td>
              <td><span class="badge" [attr.data-status]="org.status === 'active' ? 'approved' : 'rejected'">{{ org.status === 'active' ? 'Attiva' : 'Sospesa' }}</span></td>
              <td>{{ org.users_count }}</td>
              <td>{{ org.clients_count }}</td>
              <td>{{ org.campaigns_count }}</td>
              <td>{{ org.content_count }} <small class="muted">· {{ org.published_count }} pubbl.</small></td>
              <td>{{ org.jobs_this_month }}</td>
              <td>{{ bytes(org.storage_bytes) }}</td>
              <td class="muted">{{ org.last_login_at ? (utc(org.last_login_at) | date: 'd MMM, HH:mm') : 'Mai' }}</td>
              <td class="muted">{{ org.created_at | date: 'd MMM yyyy' }}</td>
            </tr>
          } @empty {
            <tr><td colspan="10" class="muted center">{{ loading() ? 'Caricamento…' : 'Nessuna organizzazione trovata.' }}</td></tr>
          }
        </tbody>
      </table>
    </div>
  `,
})
export class AdminComponent implements OnInit {
  private api = inject(ApiService);
  private router = inject(Router);

  overview = signal<AdminOverview | null>(null);
  organizations = signal<AdminOrganization[]>([]);
  loading = signal(true);
  error = signal<string | null>(null);
  query = '';
  status = '';
  readonly search$ = new Subject<void>();
  readonly bytes = formatBytes;
  readonly utc = utc;

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.load());
  }

  ngOnInit(): void {
    this.api.adminOverview().subscribe({ next: (o) => this.overview.set(o), error: (err) => this.error.set(apiErrorMessage(err)) });
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.adminOrganizations(this.query.trim(), this.status).subscribe({
      next: (list) => {
        this.organizations.set(list);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  open(org: AdminOrganization): void {
    this.router.navigate(['/admin/organizations', org.id]);
  }
}

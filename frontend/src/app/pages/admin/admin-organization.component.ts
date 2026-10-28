import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Observable } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { AdminOrganizationDetail, ROLE_LABELS, apiErrorMessage } from '../../core/models';
import { formatBytes, utc } from './admin.component';

const JOB_LABELS: Record<string, string | undefined> = {
  analyze_site: 'Analisi sito',
  generate_campaign: 'Campagna',
  generate_content: 'Varianti',
  suggest_goals: 'Obiettivi',
  rewrite_content: 'Riscrittura',
  render_video: 'Video',
};

/** Scheda di un'organizzazione nel pannello Admin: utenti, clienti, lavori, attività, sospensione e note. */
@Component({
  selector: 'app-admin-organization',
  standalone: true,
  imports: [DatePipe, FormsModule, RouterLink],
  template: `
    <a routerLink="/admin" class="back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Admin piattaforma</a>
    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (detail(); as d) {
      <div class="page-head">
        <div>
          <h1>{{ d.organization.name }}</h1>
          <p class="muted">
            Titolare: {{ d.organization.owner_email ?? '—' }} · creata il {{ d.organization.created_at | date: 'd MMM yyyy' }}
            · {{ bytes(d.organization.storage_bytes) }} di file
          </p>
        </div>
        <div class="head-controls">
          <span class="badge big" [attr.data-status]="d.organization.status === 'active' ? 'approved' : 'rejected'">{{ d.organization.status === 'active' ? 'Attiva' : 'Sospesa' }}</span>
          @if (d.organization.status === 'active') {
            @if (d.organization.id !== auth.user()?.organization_id) {
              <button class="btn ghost small" (click)="suspending.set(true)"><i class="ti ti-ban" aria-hidden="true"></i> Sospendi</button>
            }
          } @else {
            <button class="btn success small" (click)="run(api.adminReactivate(d.organization.id))" [disabled]="busy()"><i class="ti ti-player-play" aria-hidden="true"></i> Riattiva</button>
          }
        </div>
      </div>

      @if (d.organization.status === 'suspended') {
        <div class="card danger-zone">
          <h2><i class="ti ti-ban" aria-hidden="true"></i> Account sospeso</h2>
          <p>Dal {{ utc(d.organization.suspended_at) | date: 'd MMM yyyy, HH:mm' }} · Motivo: <strong>{{ d.organization.suspended_reason }}</strong></p>
          <p class="muted small">Gli utenti non possono accedere. I dati restano intatti e tornano disponibili con la riattivazione.</p>
        </div>
      }

      @if (suspending()) {
        <div class="card danger-zone">
          <h2><i class="ti ti-alert-triangle" aria-hidden="true"></i> Sospendere {{ d.organization.name }}?</h2>
          <p class="muted">Tutti i suoi utenti vengono disconnessi subito e non possono più accedere finché non la riattivi. Nessun dato viene eliminato.</p>
          <label>Motivo (visibile solo agli amministratori)<textarea rows="2" [(ngModel)]="reason" maxlength="500" placeholder="Pagamento non ricevuto"></textarea></label>
          <div class="actions">
            <button class="btn ghost small" (click)="suspending.set(false)">Annulla</button>
            <button class="btn danger small" (click)="suspend(d.organization.id)" [disabled]="!reason.trim() || busy()">Sospendi l'organizzazione</button>
          </div>
        </div>
      }

      <div class="metrics admin-metrics">
        <div class="metric"><span class="metric-icon"><i class="ti ti-users" aria-hidden="true"></i></span><span class="metric-label">Utenti</span><strong>{{ d.organization.users_count }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-building-store" aria-hidden="true"></i></span><span class="metric-label">Clienti</span><strong>{{ d.organization.clients_count }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-speakerphone" aria-hidden="true"></i></span><span class="metric-label">Campagne</span><strong>{{ d.organization.campaigns_count }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-send" aria-hidden="true"></i></span><span class="metric-label">Contenuti · pubblicati</span><strong>{{ d.organization.content_count }}<small class="muted"> · {{ d.organization.published_count }}</small></strong></div>
      </div>

      <div class="two-col">
        <div class="side-col">
          <section class="card table-card">
            <h2 class="table-title">Utenti</h2>
            <table>
              <thead><tr><th>Nome</th><th>Ruolo</th><th>Ultimo accesso</th></tr></thead>
              <tbody>
                @for (u of d.users; track u.id) {
                  <tr>
                    <td><strong>{{ u.name }}</strong> @if (u.is_platform_admin) { <span class="tag ai">Admin</span> }<br /><small class="muted">{{ u.email }}</small></td>
                    <td><span class="tag">{{ roleLabels[u.role] }}</span></td>
                    <td class="muted">{{ u.last_login_at ? (utc(u.last_login_at) | date: 'd MMM, HH:mm') : 'Mai' }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </section>

          <section class="card table-card">
            <h2 class="table-title">Ultimi lavori</h2>
            <table>
              <thead><tr><th>Tipo</th><th>Stato</th><th>Quando</th></tr></thead>
              <tbody>
                @for (j of d.recent_jobs; track j.id) {
                  <tr>
                    <td>{{ jobLabels[j.type] ?? j.type }}@if (j.error) { <br /><small class="error-text">{{ j.error }}</small> }</td>
                    <td><span class="badge" [attr.data-status]="j.status === 'done' ? 'approved' : j.status === 'failed' ? 'rejected' : 'in_review'">{{ jobStatus(j.status) }}</span></td>
                    <td class="muted">{{ j.created_at | date: 'd MMM, HH:mm' }}</td>
                  </tr>
                } @empty {
                  <tr><td colspan="3" class="muted">Nessun lavoro.</td></tr>
                }
              </tbody>
            </table>
          </section>
        </div>

        <div class="side-col">
          <section class="card">
            <h2>Note interne</h2>
            <p class="muted small">Visibili solo agli amministratori della piattaforma.</p>
            <textarea rows="4" [(ngModel)]="notes" maxlength="5000" placeholder="Accordi, pagamenti, contatti…"></textarea>
            <div class="actions">
              @if (notesSaved()) { <span class="muted small"><i class="ti ti-check" aria-hidden="true"></i> Salvate</span> }
              <button class="btn ghost small" (click)="saveNotes(d.organization.id)" [disabled]="busy()">Salva note</button>
            </div>
          </section>

          <section class="card">
            <h2>Clienti</h2>
            @for (c of d.clients; track c.id) {
              <div class="campaign-row">
                <span class="swatch" [style.background]="c.primary_color ?? '#5B4BDB'"></span>
                <span class="grow"><strong>{{ c.name }}</strong><small class="muted">{{ c.website_url ?? 'Nessun sito' }}</small></span>
                <span class="muted small">{{ c.campaigns_count }} campagne</span>
              </div>
            } @empty {
              <p class="muted small">Nessun cliente.</p>
            }
          </section>

          <section class="card">
            <h2>Attività recente</h2>
            <ul class="activity">
              @for (a of d.activity; track $index) {
                <li><span>{{ a.user_name ?? 'Sistema' }} · {{ a.action }}</span><small class="muted">{{ a.created_at | date: 'd MMM, HH:mm' }}</small></li>
              } @empty {
                <li class="muted small">Nessuna attività registrata.</li>
              }
            </ul>
          </section>
        </div>
      </div>
    } @else if (!error()) {
      <p class="muted">Caricamento…</p>
    }
  `,
})
export class AdminOrganizationComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly api = inject(ApiService);
  private route = inject(ActivatedRoute);

  readonly roleLabels = ROLE_LABELS;
  readonly jobLabels = JOB_LABELS;
  readonly bytes = formatBytes;
  readonly utc = utc;
  detail = signal<AdminOrganizationDetail | null>(null);
  error = signal<string | null>(null);
  busy = signal(false);
  suspending = signal(false);
  notesSaved = signal(false);
  reason = '';
  notes = '';

  ngOnInit(): void {
    this.run(this.api.adminOrganization(Number(this.route.snapshot.paramMap.get('id'))));
  }

  suspend(id: number): void {
    this.run(this.api.adminSuspend(id, this.reason.trim()), () => {
      this.suspending.set(false);
      this.reason = '';
    });
  }

  saveNotes(id: number): void {
    this.run(this.api.adminNotes(id, this.notes), () => {
      this.notesSaved.set(true);
      setTimeout(() => this.notesSaved.set(false), 2000);
    });
  }

  jobStatus(status: string): string {
    return ({ queued: 'In coda', running: 'In corso', done: 'Completato', failed: 'Fallito' } as Record<string, string>)[status] ?? status;
  }

  run(request: Observable<AdminOrganizationDetail>, onDone?: () => void): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (d) => {
        this.detail.set(d);
        this.notes = d.organization.admin_notes ?? '';
        this.busy.set(false);
        onDone?.();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err, 'Organizzazione non trovata.'));
      },
    });
  }
}

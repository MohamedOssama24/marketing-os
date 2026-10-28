import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { PROSPECT_STATUS_LABELS, Prospect, ProspectStatus, apiErrorMessage } from '../../core/models';

/** Potenziali clienti: l'audit gratuito del loro sito è il primo passo per acquisirli. */
@Component({
  selector: 'app-prospects',
  standalone: true,
  imports: [DatePipe, FormsModule, RouterLink],
  template: `
    <div class="page-head">
      <div>
        <h1>Potenziali clienti</h1>
        <p class="muted">Un audit gratuito del loro sito, con idee di post e la tua proposta: il modo più rapido per aprire una trattativa.</p>
      </div>
    </div>

    @if (auth.canEdit()) {
      <form class="analyzer" (ngSubmit)="create()">
        <div class="analyzer-head">
          <span class="analyzer-icon"><i class="ti ti-file-search" aria-hidden="true"></i></span>
          <div>
            <h2>Prepara un audit gratuito</h2>
            <p class="muted small">Incolla il sito di un'attività che vuoi come cliente: in un minuto hai controlli, punti deboli, idee di post e servizi da proporre.</p>
          </div>
        </div>
        <div class="analyzer-form">
          <div class="input-icon"><i class="ti ti-world" aria-hidden="true"></i><input [(ngModel)]="url" name="url" placeholder="www.ristorante-da-mario.it" aria-label="Sito del potenziale cliente" /></div>
          <button class="btn primary" [disabled]="!url.trim() || busy()">@if (busy()) { <span class="spinner small"></span> } Analizza il sito</button>
        </div>
      </form>
    }

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="section-head">
      <div class="filters">
        @for (f of filters; track f.key) {
          <button class="chip" [class.active]="filter() === f.key" (click)="filter.set(f.key)">{{ f.label }} <span class="muted">{{ count(f.key) }}</span></button>
        }
      </div>
    </div>

    <div class="prospect-list">
      @for (p of visible(); track p.id) {
        <a class="card prospect-row link-card" [routerLink]="['/prospects', p.id]">
          <span class="score-badge" [class]="'score-badge ' + scoreClass(p.score)">
            @if (p.analyzing) { <span class="spinner small"></span> } @else { {{ p.score ?? '—' }} }
          </span>
          <span class="grow">
            <strong class="ellipsis">{{ p.name || host(p.url) }}</strong>
            <small class="muted ellipsis">{{ host(p.url) }} · {{ p.created_at.replace(' ', 'T') | date: 'd MMM y' }}</small>
          </span>
          @if (p.job_status === 'failed') { <span class="tag danger-tag">Sito non leggibile</span> }
          @if (p.share_token) {
            <span class="muted small"><i class="ti ti-eye" aria-hidden="true"></i> {{ p.views ? (p.views === 1 ? 'aperto 1 volta' : 'aperto ' + p.views + ' volte') : 'non ancora aperto' }}</span>
          }
          <span [class]="'tag status-' + p.status">{{ statusLabels[p.status] }}</span>
          <i class="ti ti-chevron-right muted" aria-hidden="true"></i>
        </a>
      } @empty {
        <div class="card empty">
          <i class="ti ti-target-arrow empty-icon" aria-hidden="true"></i>
          <strong>{{ loading() ? 'Caricamento…' : 'Nessun potenziale cliente' }}</strong>
          @if (!loading()) { <p class="muted">Inserisci il sito di un'attività qui sopra per preparare il primo audit.</p> }
        </div>
      }
    </div>
  `,
})
export class ProspectsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  readonly statusLabels = PROSPECT_STATUS_LABELS;
  readonly filters: { key: ProspectStatus | 'all'; label: string }[] = [
    { key: 'all', label: 'Tutti' }, { key: 'new', label: 'Da contattare' }, { key: 'sent', label: 'Audit inviato' }, { key: 'won', label: 'Acquisiti' }, { key: 'lost', label: 'Persi' },
  ];
  prospects = signal<Prospect[]>([]);
  filter = signal<ProspectStatus | 'all'>('all');
  loading = signal(true);
  busy = signal(false);
  error = signal<string | null>(null);
  url = '';

  readonly visible = computed(() => this.filter() === 'all' ? this.prospects() : this.prospects().filter((p) => p.status === this.filter()));

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.prospects().subscribe({
      next: (list) => {
        this.prospects.set(list);
        this.loading.set(false);
        // Gli audit in corso si aggiornano da soli quando finiscono.
        list.filter((p) => p.analyzing && p.job_id).forEach((p) => this.follow(p.job_id!));
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  create(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.createProspect(this.url.trim()).subscribe({
      next: (p) => this.router.navigate(['/prospects', p.id]),
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.busy.set(false);
      },
    });
  }

  count(key: ProspectStatus | 'all'): number {
    return key === 'all' ? this.prospects().length : this.prospects().filter((p) => p.status === key).length;
  }

  scoreClass(score: number | null | undefined): string {
    return score == null ? 'none' : score >= 75 ? 'good' : score >= 50 ? 'mid' : 'low';
  }

  host(url: string): string {
    try { return new URL(url).host.replace(/^www\./, ''); } catch { return url; }
  }

  private follow(jobId: number): void {
    this.api.waitForJob(jobId).pipe(switchMap(() => this.api.prospects()), takeUntilDestroyed(this.destroyRef)).subscribe((list) => this.prospects.set(list));
  }
}

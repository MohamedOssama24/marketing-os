import { Component, DestroyRef, Input, OnChanges, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Competitor, apiErrorMessage } from '../core/models';

/** Nella scheda cliente: i concorrenti seguiti, con l'analisi del loro sito rifatta ogni settimana. */
@Component({
  selector: 'app-competitors-panel',
  standalone: true,
  imports: [DatePipe, FormsModule],
  template: `
    <section class="card growth-card competitors">
      <div class="strategy-head">
        <h2><i class="ti ti-binoculars" aria-hidden="true"></i> Concorrenti</h2>
      </div>
      <p class="muted small">Indica fino a 5 concorrenti: l'AI legge il loro sito ogni settimana e ti dice cosa promettono e dove il cliente può distinguersi.</p>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (auth.canEdit() && list().length < 5) {
        <form class="rewrite-custom" (ngSubmit)="add()">
          <input [(ngModel)]="url" name="url" placeholder="Sito del concorrente, es. www.concorrente.it" aria-label="Sito del concorrente" />
          <button class="btn primary small" [disabled]="!url.trim() || busy()">Aggiungi</button>
        </form>
      }

      <div class="competitor-grid">
        @for (c of list(); track c.id) {
          <article class="competitor">
            <header>
              <strong class="ellipsis">{{ c.name }}</strong>
              <a class="icon-btn" [href]="c.url" target="_blank" rel="noopener" title="Apri il sito" aria-label="Apri il sito"><i class="ti ti-external-link" aria-hidden="true"></i></a>
              @if (auth.canEdit()) {
                <button class="icon-btn" (click)="refresh(c)" [disabled]="c.analyzing" title="Analizza di nuovo" aria-label="Analizza di nuovo"><i class="ti ti-refresh" aria-hidden="true"></i></button>
                <button class="icon-btn danger" (click)="remove(c)" title="Smetti di seguire" aria-label="Smetti di seguire"><i class="ti ti-trash" aria-hidden="true"></i></button>
              }
            </header>
            @if (c.analyzing) {
              <p class="rewrite-progress"><span class="spinner small"></span> Leggo il sito…</p>
            } @else {
              @if (c.analysis; as a) {
                <p class="small">{{ a.summary }}</p>
                @if (a.offers.length) { <p class="kit-label">In vetrina</p><div class="pillars">@for (o of a.offers; track $index) { <span class="tag">{{ o }}</span> }</div> }
                @if (a.messages.length) { <p class="kit-label">Cosa promette</p><ul class="small">@for (m of a.messages; track $index) { <li>{{ m }}</li> }</ul> }
                @if (a.tone) { <p class="kit-label">Tono</p><p class="small">{{ a.tone }}</p> }
                @if (a.opportunities.length) {
                  <div class="opportunities"><p class="kit-label"><i class="ti ti-bulb" aria-hidden="true"></i> Come distinguersi</p><ul class="small">@for (o of a.opportunities; track $index) { <li>{{ o }}</li> }</ul></div>
                }
              } @else if (c.job_status === 'failed') {
                <p class="alert small">{{ c.job_error || 'Sito non leggibile.' }}</p>
            }
            }
            <footer class="muted small">
              <a [href]="c.ad_library_url" target="_blank" rel="noopener"><i class="ti ti-brand-meta" aria-hidden="true"></i> Le sue inserzioni attive</a>
              @if (c.last_checked_at) { <span>Aggiornato il {{ c.last_checked_at.replace(' ', 'T') | date: 'd MMM' }}</span> }
            </footer>
          </article>
        }
      </div>
    </section>
  `,
})
export class CompetitorsPanelComponent implements OnChanges {
  @Input({ required: true }) brandId!: number;
  private api = inject(ApiService);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);

  list = signal<Competitor[]>([]);
  busy = signal(false);
  error = signal<string | null>(null);
  url = '';

  ngOnChanges(): void {
    this.api.competitors(this.brandId).subscribe({
      next: (list) => {
        this.list.set(list);
        list.filter((c) => c.analyzing).forEach((c) => this.follow(c));
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  add(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.addCompetitor(this.brandId, this.url.trim()).subscribe({
      next: (c) => {
        this.list.update((list) => [...list, c]);
        this.url = '';
        this.busy.set(false);
        this.follow(c);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.busy.set(false);
      },
    });
  }

  refresh(c: Competitor): void {
    this.api.refreshCompetitor(c.id).subscribe({ next: (fresh) => { this.replace(fresh); this.follow(fresh); }, error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  remove(c: Competitor): void {
    if (!confirm(`Smettere di seguire ${c.name}?`)) return;
    this.api.deleteCompetitor(c.id).subscribe({ next: () => this.list.update((list) => list.filter((x) => x.id !== c.id)), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  /** Aspetta la fine dell'analisi e ricarica il concorrente. */
  private follow(c: Competitor): void {
    if (!c.job_id) return;
    this.api.waitForJob(c.job_id).pipe(switchMap(() => this.api.competitors(this.brandId)), takeUntilDestroyed(this.destroyRef)).subscribe((list) => {
      const fresh = list.find((x) => x.id === c.id);
      if (fresh) this.replace(fresh);
    });
  }

  private replace(c: Competitor): void {
    this.list.update((list) => list.map((x) => (x.id === c.id ? c : x)));
  }
}

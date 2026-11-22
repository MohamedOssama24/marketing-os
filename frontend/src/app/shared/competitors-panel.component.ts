import { Component, DestroyRef, EventEmitter, Input, OnChanges, Output, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Competitor, CompetitorStrength, Project, apiErrorMessage } from '../core/models';

/** Nella scheda cliente: i concorrenti seguiti, con l'analisi del loro sito rifatta ogni settimana e i loro punti forti da battere. */
@Component({
  selector: 'app-competitors-panel',
  standalone: true,
  imports: [DatePipe, FormsModule, RouterLink],
  template: `
    <section class="card growth-card competitors">
      <div class="strategy-head">
        <h2><i class="ti ti-binoculars" aria-hidden="true"></i> Concorrenti</h2>
      </div>
      <p class="muted small">Indica fino a 5 concorrenti: l'AI legge il loro sito ogni settimana e ti dice cosa promettono, cosa fanno bene e dove il cliente può distinguersi.</p>
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
                @if (a.score !== undefined) {
                  <div class="competitor-look">
                    <span [class]="'score-badge small ' + scoreClass(a.score)" title="Punteggio del sito su 100">{{ a.score }}</span>
                    <span class="swatches">@for (color of a.colors ?? []; track color) { <span class="swatch" [style.background]="color" [title]="color"></span> }</span>
                    @for (f of a.fonts ?? []; track f) { <span class="tag">{{ f }}</span> }
                  </div>
                }
                <p class="small">{{ a.summary }}</p>
                @if (a.offers.length) { <p class="kit-label">In vetrina</p><div class="pillars">@for (o of a.offers; track $index) { <span class="tag">{{ o }}</span> }</div> }
                @if (a.messages.length) { <p class="kit-label">Cosa promette</p><ul class="small">@for (m of a.messages; track $index) { <li>{{ m }}</li> }</ul> }
                @if (a.tone) { <p class="kit-label">Tono</p><p class="small">{{ a.tone }}</p> }
                @if (a.strengths?.length) {
                  <p class="kit-label"><i class="ti ti-trophy" aria-hidden="true"></i> Cosa fa bene e come fare meglio</p>
                  @for (s of a.strengths; track $index) {
                    <div class="strength">
                      <strong class="small">{{ s.strength }}</strong>
                      <p class="small"><i class="ti ti-arrow-right" aria-hidden="true"></i> {{ s.counter }}</p>
                      @if (auth.canEdit()) {
                        @if (created()?.key === c.id + '-' + $index) {
                          <p class="small success-text"><i class="ti ti-check" aria-hidden="true"></i> Sto scrivendo il post: <a [routerLink]="['/campaigns', created()!.projectId]">apri la campagna</a></p>
                        } @else if (!campaigns.length) {
                          <p class="muted small">Crea una campagna per il cliente per trasformarlo in un post.</p>
                        } @else if (picking() === c.id + '-' + $index) {
                          <select (change)="createPost(c.id + '-' + $index, s, +$any($event.target).value)" aria-label="Campagna in cui creare il post">
                            <option value="" selected disabled>In quale campagna?</option>
                            @for (p of campaigns; track p.id) { <option [value]="p.id">{{ p.name }}</option> }
                          </select>
                        } @else {
                          <button class="link-btn small" (click)="campaigns.length === 1 ? createPost(c.id + '-' + $index, s, campaigns[0].id) : picking.set(c.id + '-' + $index)" [disabled]="busy()">
                            <i class="ti ti-sparkles" aria-hidden="true"></i> Crea il post per fare meglio
                          </button>
                        }
                      }
                    </div>
                  }
                }
                @if (a.opportunities.length) {
                  <div class="opportunities"><p class="kit-label"><i class="ti ti-bulb" aria-hidden="true"></i> Come distinguersi</p><ul class="small">@for (o of a.opportunities; track $index) { <li>{{ o }}</li> }</ul></div>
                }
                @if (c.job_status === 'failed') {
                  <p class="muted small"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Ultimo aggiornamento non riuscito: vedi l'analisi precedente.</p>
                }
              } @else if (c.job_status === 'failed') {
                <div class="alert small">
                  <p>{{ c.job_error || 'Sito non leggibile.' }}</p>
                  @if (auth.canEdit()) { <button class="btn ghost small" (click)="refresh(c)"><i class="ti ti-refresh" aria-hidden="true"></i> Riprova</button> }
                </div>
              }
            }
            @if (c.ads.preview.length) {
              <div class="ads-preview">
                <p class="kit-label"><i class="ti ti-speakerphone" aria-hidden="true"></i> Le sue inserzioni · {{ c.ads.active }} {{ c.ads.active === 1 ? 'attiva' : 'attive' }}</p>
                @for (ad of c.ads.preview; track ad.archive_id) {
                  <a class="ad-mini" [href]="ad.url" target="_blank" rel="noopener" title="Apri l'inserzione nell'archivio di Meta">
                    @if (ad.title) { <strong class="small ellipsis">{{ ad.title }}</strong> }
                    <span class="small clamp">{{ ad.text || 'Inserzione senza testo (solo immagine o video).' }}</span>
                    <span class="muted small">{{ platformsLabel(ad.platforms) }}{{ ad.platforms.length ? ' · ' : '' }}attiva da {{ ad.days }} {{ ad.days === 1 ? 'giorno' : 'giorni' }}</span>
                  </a>
                }
              </div>
            }
            <footer class="muted small">
              @if (c.ads.connected) {
                <button class="link-btn small" (click)="showAllAds()"><i class="ti ti-brand-meta" aria-hidden="true"></i> {{ c.ads.active > 3 ? 'Vedi tutte le ' + c.ads.active + ' inserzioni' : 'Vedi tutte le inserzioni' }}</button>
              } @else {
                <a [href]="c.ad_library_url" target="_blank" rel="noopener"><i class="ti ti-brand-meta" aria-hidden="true"></i> Le sue inserzioni attive</a>
              }
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
  /** Campagne del cliente in cui creare i post nati dai punti forti dei concorrenti. */
  @Input() campaigns: Project[] = [];
  /** Un'analisi è finita o un concorrente è stato tolto: il confronto può cambiare. */
  @Output() changed = new EventEmitter<void>();
  private api = inject(ApiService);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);

  list = signal<Competitor[]>([]);
  busy = signal(false);
  error = signal<string | null>(null);
  picking = signal<string | null>(null);
  created = signal<{ key: string; projectId: number } | null>(null);
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

  /** Porta alla sezione con tutte le inserzioni dei concorrenti, più in basso nella scheda cliente. */
  showAllAds(): void {
    document.getElementById('competitor-ads')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  platformsLabel(platforms: string[]): string {
    const names: Record<string, string> = { facebook: 'Facebook', instagram: 'Instagram', messenger: 'Messenger', audience_network: 'Audience Network', threads: 'Threads' };
    return platforms.map((p) => names[p] ?? p).join(', ');
  }

  refresh(c: Competitor): void {
    this.api.refreshCompetitor(c.id).subscribe({ next: (fresh) => { this.replace(fresh); this.follow(fresh); }, error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  remove(c: Competitor): void {
    if (!confirm(`Smettere di seguire ${c.name}?`)) return;
    this.api.deleteCompetitor(c.id).subscribe({
      next: () => {
        this.list.update((list) => list.filter((x) => x.id !== c.id));
        this.changed.emit();
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  scoreClass(score: number): string {
    return score >= 75 ? 'good' : score >= 50 ? 'mid' : 'low';
  }

  /** Scrive con l'AI un post del cliente che fa meglio di ciò che il concorrente fa bene. */
  createPost(key: string, s: CompetitorStrength, projectId: number): void {
    if (!projectId) return;
    this.busy.set(true);
    this.error.set(null);
    const brief = `${s.post_idea}\nPunto di forza del cliente da far emergere: ${s.counter}\nNon citare concorrenti e non copiare i loro testi.`;
    this.api.generate(projectId, { channel: 'instagram', content_type: 'post', brief: brief.slice(0, 2000), variants: 2 }).subscribe({
      next: () => {
        this.busy.set(false);
        this.picking.set(null);
        this.created.set({ key, projectId });
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  /** Aspetta la fine dell'analisi e ricarica il concorrente. */
  private follow(c: Competitor): void {
    if (!c.job_id) return;
    this.api.waitForJob(c.job_id).pipe(switchMap(() => this.api.competitors(this.brandId)), takeUntilDestroyed(this.destroyRef)).subscribe((list) => {
      const fresh = list.find((x) => x.id === c.id);
      if (fresh) this.replace(fresh);
      this.changed.emit();
    });
  }

  private replace(c: Competitor): void {
    this.list.update((list) => list.map((x) => (x.id === c.id ? c : x)));
  }
}

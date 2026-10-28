import { Component, DestroyRef, EventEmitter, Input, OnChanges, Output, SimpleChanges, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Brand, CompetitionStudy, StylePart, Visual, apiErrorMessage } from '../core/models';
import { PostVisualComponent } from './post-visual.component';

/** Nella scheda cliente: il sito del cliente accanto a quelli dei concorrenti e uno stile che lo distingue, da applicare al brand kit. */
@Component({
  selector: 'app-competition-study',
  standalone: true,
  imports: [DatePipe, FormsModule, PostVisualComponent],
  template: `
    <section class="card growth-card competition">
      <div class="strategy-head">
        <h2><i class="ti ti-chess-knight" aria-hidden="true"></i> Confronto e proposta di stile</h2>
        @if (auth.canEdit() && study(); as s) {
          <button class="btn primary small" (click)="start()" [disabled]="busy() || s.analyzing">
            <i class="ti ti-sparkles" aria-hidden="true"></i> {{ s.proposal ? 'Rifai il confronto' : 'Fai il confronto' }}
          </button>
        }
      </div>
      <p class="muted small">Mette il sito del cliente accanto a quelli dei concorrenti, mostra dove vince e dove perde e propone colori, carattere, foto e tono che lo fanno riconoscere.</p>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (study(); as s) {
        @if (s.analyzing) {
          <p class="rewrite-progress"><span class="spinner small"></span> Confronto il cliente con i concorrenti e preparo la proposta…</p>
        } @else if (s.job_error) {
          <p class="alert small">{{ s.job_error }}</p>
        }

        @if (s.competitors.length) {
          <div class="compare-wrap">
            <table class="compare-table">
              <thead>
                <tr><th></th><th class="is-client">{{ brand.name }}</th>@for (c of s.competitors; track c.id) { <th>{{ c.name }}</th> }</tr>
              </thead>
              <tbody>
                <tr class="score-row">
                  <td>Punteggio del sito</td>
                  <td class="is-client">@if (s.client) { <span [class]="'score-badge small ' + scoreClass(s.client.score)">{{ s.client.score }}</span> } @else { – }</td>
                  @for (c of s.competitors; track c.id) { <td><span [class]="'score-badge small ' + scoreClass(c.score)">{{ c.score }}</span></td> }
                </tr>
                <tr>
                  <td>Colori</td>
                  <td class="is-client"><span class="swatches">@for (color of clientColors(); track color) { <span class="swatch small" [style.background]="color" [title]="color"></span> }</span></td>
                  @for (c of s.competitors; track c.id) { <td><span class="swatches">@for (color of c.colors; track color) { <span class="swatch small" [style.background]="color" [title]="color"></span> }</span></td> }
                </tr>
                <tr>
                  <td>Carattere</td>
                  <td class="is-client">{{ brand.font_family || s.client?.fonts?.[0] || '–' }}</td>
                  @for (c of s.competitors; track c.id) { <td>{{ c.fonts[0] || '–' }}</td> }
                </tr>
                @for (row of s.rows; track row.key) {
                  <tr>
                    <td>{{ row.label }}</td>
                    <td class="is-client" [class]="'is-client ' + markClass(row.client)">{{ markLabel(row.client) }}</td>
                    @for (ok of row.competitors; track $index) { <td [class]="markClass(ok)">{{ markLabel(ok) }}</td> }
                  </tr>
                }
              </tbody>
            </table>
          </div>
          @if (!s.client && s.proposal) {
            <p class="muted small"><i class="ti ti-info-circle" aria-hidden="true"></i> Il sito del cliente non è stato letto: aggiungi o correggi l'indirizzo nel brand kit e rifai il confronto.</p>
          }
          @if (s.client) {
            <div class="win-lose">
              <div class="wins">
                <p class="kit-label"><i class="ti ti-thumb-up" aria-hidden="true"></i> Dove vinci</p>
                @if (s.wins.length) { <ul class="small">@for (w of s.wins; track w) { <li>{{ w }}</li> }</ul> } @else { <p class="small muted">Ancora nessun vantaggio netto sui concorrenti.</p> }
              </div>
              <div class="losses">
                <p class="kit-label"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Dove perdi</p>
                @if (s.losses.length) { <ul class="small">@for (l of s.losses; track l) { <li>{{ l }}</li> }</ul> } @else { <p class="small muted">Nessun punto in cui i concorrenti fanno meglio.</p> }
              </div>
            </div>
          }
        } @else if (!s.analyzing) {
          <p class="muted small">Aggiungi almeno un concorrente qui sopra e aspetta la sua analisi, poi fai il confronto. Se i concorrenti erano già analizzati, premi <i class="ti ti-refresh" aria-hidden="true"></i> su ciascuno per aggiungere punteggio, colori e caratteri.</p>
        }

        @if (s.proposal; as p) {
          @if (s.market) { <p class="kit-label">Com'è il mercato</p><p class="small">{{ s.market }}</p> }
          @if (s.gaps.length) { <p class="kit-label">Spazi che nessuno occupa</p><div class="pillars">@for (g of s.gaps; track g) { <span class="tag">{{ g }}</span> }</div> }

          <div class="style-proposal">
            <div class="style-specs">
              <p class="kit-label"><i class="ti ti-palette" aria-hidden="true"></i> Lo stile proposto</p>
              <div class="swatches">
                <span class="swatch-label"><span class="swatch" [style.background]="p.primary_color"></span> {{ p.primary_color }}</span>
                <span class="swatch-label"><span class="swatch" [style.background]="p.secondary_color"></span> {{ p.secondary_color }}</span>
              </div>
              <p class="font-sample" [style.font-family]="p.font_family + ', sans-serif'">Aa · {{ p.font_family }}</p>
              @if (p.photo_style) { <p class="small"><strong>Foto:</strong> {{ p.photo_style }}</p> }
              @if (p.tone_of_voice) { <p class="small"><strong>Tono:</strong> {{ p.tone_of_voice }}</p> }
              @if (p.formats.length) { <p class="small"><strong>Formati su cui puntare:</strong></p><ul class="small">@for (f of p.formats; track f) { <li>{{ f }}</li> }</ul> }
              @if (p.rationale) { <div class="opportunities"><p class="small">{{ p.rationale }}</p></div> }
            </div>
            <div class="style-preview">
              <figure><figcaption class="kit-label">Oggi</figcaption><app-post-visual [visual]="sample()" [brand]="brand" /></figure>
              <figure><figcaption class="kit-label">Con il nuovo stile</figcaption><app-post-visual [visual]="sample()" [brand]="proposedBrand()" /></figure>
            </div>
          </div>

          @if (auth.canEdit()) {
            <div class="style-apply">
              <span class="small muted">Applica al brand kit:</span>
              <label class="check-row"><input type="checkbox" [(ngModel)]="parts.colors" /> Colori</label>
              <label class="check-row"><input type="checkbox" [(ngModel)]="parts.font" /> Carattere</label>
              <label class="check-row"><input type="checkbox" [(ngModel)]="parts.tone" [disabled]="!p.tone_of_voice" /> Tono di voce</label>
              <button class="btn primary small" (click)="apply()" [disabled]="busy() || !chosen().length"><i class="ti ti-wand" aria-hidden="true"></i> Applica</button>
              @if (s.applied_at) {
                <span class="small muted">Applicato il {{ s.applied_at.replace(' ', 'T') | date: 'd MMM, HH:mm' }}</span>
                <button class="link-btn small" (click)="undo()" [disabled]="busy()"><i class="ti ti-arrow-back-up" aria-hidden="true"></i> Torna allo stile di prima</button>
              }
            </div>
          }
          @if (s.updated_at) { <p class="muted small">Confronto del {{ s.updated_at.replace(' ', 'T') | date: 'd MMM yyyy' }}</p> }
        }
      } @else {
        <p class="muted small">Caricamento…</p>
      }
    </section>
  `,
})
export class CompetitionStudyComponent implements OnChanges {
  @Input({ required: true }) brand!: Brand;
  /** Il brand kit è cambiato (stile applicato o annullato): la scheda va ricaricata. */
  @Output() brandChanged = new EventEmitter<void>();
  private api = inject(ApiService);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);

  study = signal<CompetitionStudy | null>(null);
  busy = signal(false);
  error = signal<string | null>(null);
  parts: Record<StylePart, boolean> = { colors: true, font: true, tone: false };
  private brandSignal = signal<Brand | null>(null);

  readonly sample = computed<Visual>(() => ({
    headline: this.brandSignal()?.name ?? '',
    subheadline: 'Così appare un post nel feed',
    badge: 'Novità',
    layout: 'bold',
    asset_id: null,
  }));

  readonly proposedBrand = computed<Brand | null>(() => {
    const brand = this.brandSignal();
    const p = this.study()?.proposal;
    return brand && p ? { ...brand, primary_color: p.primary_color, secondary_color: p.secondary_color, font_family: p.font_family } : brand;
  });

  ngOnChanges(changes: SimpleChanges): void {
    this.brandSignal.set(this.brand);
    if (changes['brand']?.previousValue?.id !== this.brand.id) this.reload();
  }

  /** Ricarica il confronto, per esempio quando un concorrente è stato rianalizzato. */
  reload(): void {
    this.api.competition(this.brand.id).subscribe({
      next: (s) => {
        this.study.set(s);
        if (s.analyzing) this.follow(s);
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  clientColors(): string[] {
    const own = [this.brand.primary_color, this.brand.secondary_color].filter((c): c is string => !!c);
    return own.length ? own : this.study()?.client?.colors ?? [];
  }

  chosen(): StylePart[] {
    return (Object.keys(this.parts) as StylePart[]).filter((k) => this.parts[k]);
  }

  scoreClass(score: number): string {
    return score >= 75 ? 'good' : score >= 50 ? 'mid' : 'low';
  }

  markLabel(ok: boolean | null): string {
    return ok === null ? '–' : ok ? '✓' : '✗';
  }

  markClass(ok: boolean | null): string {
    return ok === null ? '' : ok ? 'mark-ok' : 'mark-no';
  }

  start(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.startCompetition(this.brand.id).subscribe({
      next: (s) => {
        this.busy.set(false);
        this.study.set(s);
        this.follow(s);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  apply(): void {
    this.update(this.api.applyStyle(this.brand.id, this.chosen()));
  }

  undo(): void {
    if (!confirm('Rimettere nel brand kit i colori, il carattere e il tono di prima?')) return;
    this.update(this.api.undoStyle(this.brand.id));
  }

  private update(request: ReturnType<ApiService['undoStyle']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (s) => {
        this.busy.set(false);
        this.study.set(s);
        this.brandChanged.emit();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  private follow(s: CompetitionStudy): void {
    if (!s.job_id) return;
    this.api.waitForJob(s.job_id).pipe(switchMap(() => this.api.competition(this.brand.id)), takeUntilDestroyed(this.destroyRef)).subscribe((fresh) => this.study.set(fresh));
  }
}

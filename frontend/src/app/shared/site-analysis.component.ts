import { Component, DestroyRef, EventEmitter, Input, OnChanges, Output, SimpleChanges, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe, DecimalPipe } from '@angular/common';
import { switchMap } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Brand, SiteAnalysisState, SiteDiagnosis, SiteProfile, apiErrorMessage } from '../core/models';

const FEATURES: Record<string, string> = {
  ecommerce: 'Vendita online', booking: 'Prenotazione online', contact_form: 'Modulo di contatto', newsletter: 'Iscrizione alla newsletter',
  whatsapp: 'WhatsApp', reviews: 'Recensioni', video: 'Video',
};
const SOCIALS: Record<string, string> = {
  instagram_url: 'Instagram', facebook_url: 'Facebook', linkedin_url: 'LinkedIn', tiktok: 'TikTok', youtube: 'YouTube', x: 'X', pinterest: 'Pinterest',
};

/** Nella scheda cliente: cosa c'è sul sito (contatti, strumenti, controlli) e la diagnosi strategica "da amministratore delegato". */
@Component({
  selector: 'app-site-analysis',
  standalone: true,
  imports: [DatePipe, DecimalPipe],
  template: `
    <section class="card growth-card site-analysis">
      <div class="strategy-head">
        <h2><i class="ti ti-building-skyscraper" aria-hidden="true"></i> Analisi del sito e strategia</h2>
        @if (auth.canEdit() && state(); as s) {
          <button class="btn primary small" (click)="start()" [disabled]="busy() || s.analyzing || !brand.website_url">
            <i class="ti ti-brain" aria-hidden="true"></i> {{ s.analysis ? 'Rifai l\\'analisi' : 'Analizza come un CEO' }}
          </button>
        }
      </div>
      <p class="muted small">Legge il sito come farebbe un amministratore delegato: come guadagna l'attività, perché un cliente dovrebbe sceglierla, cosa frena le vendite e da dove partire.</p>
      @if (!brand.website_url) { <p class="muted small"><i class="ti ti-info-circle" aria-hidden="true"></i> Aggiungi l'indirizzo del sito nel brand kit per avviare l'analisi.</p> }
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (state(); as s) {
        @if (s.analyzing) {
          <p class="rewrite-progress"><span class="spinner small"></span> Leggo il sito e ragiono sulla strategia… può richiedere un paio di minuti.</p>
        } @else if (s.job_error) {
          <p class="alert small">{{ s.job_error }}</p>
        }

        @if (s.analysis; as a) {
          <div class="ceo-verdict">
            <p class="kit-label">Il giudizio</p>
            <p>{{ a.verdict }}</p>
          </div>

          <div class="ceo-columns">
            <div>
              @if (a.business_model) { <p class="kit-label">Come guadagna</p><p class="small">{{ a.business_model }}</p> }
              @if (a.target) { <p class="kit-label">Clienti ideali</p><p class="small">{{ a.target }}</p> }
            </div>
            <div>
              @if (a.value_proposition) { <p class="kit-label">Cosa promette oggi</p><p class="small">{{ a.value_proposition }}</p> }
              @if (a.sharper_proposition) { <p class="kit-label">Come dovrebbe dirlo</p><blockquote class="sharper">{{ a.sharper_proposition }}</blockquote> }
            </div>
          </div>

          @if (a.scorecard.length) {
            <div class="scorecard">
              <p class="kit-label">Pagella <span class="scorecard-avg">media {{ average(a) | number: '1.1-1' }}/10</span></p>
              @for (row of a.scorecard; track row.area) {
                <div class="score-line">
                  <span class="score-area">{{ row.area }}</span>
                  <span class="score-bar" role="img" [attr.aria-label]="row.area + ': ' + row.score + ' su 10'"><span [class]="barClass(row.score)" [style.width.%]="row.score * 10"></span></span>
                  <strong class="score-value">{{ row.score }}</strong>
                  @if (row.note) { <span class="score-note">{{ row.note }}</span> }
                </div>
              }
            </div>
          }

          <div class="swot">
            <div class="swot-s"><p class="kit-label"><i class="ti ti-trophy" aria-hidden="true"></i> Punti di forza</p><ul class="small">@for (x of a.swot.strengths; track x) { <li>{{ x }}</li> }</ul></div>
            <div class="swot-w"><p class="kit-label"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Debolezze</p><ul class="small">@for (x of a.swot.weaknesses; track x) { <li>{{ x }}</li> }</ul></div>
            <div class="swot-o"><p class="kit-label"><i class="ti ti-bulb" aria-hidden="true"></i> Opportunità</p><ul class="small">@for (x of a.swot.opportunities; track x) { <li>{{ x }}</li> }</ul></div>
            <div class="swot-t"><p class="kit-label"><i class="ti ti-shield-exclamation" aria-hidden="true"></i> Minacce</p><ul class="small">@for (x of a.swot.threats; track x) { <li>{{ x }}</li> }</ul></div>
          </div>

          @if (a.priorities.length) {
            <p class="kit-label">Le priorità, in ordine</p>
            <ol class="priorities">
              @for (p of a.priorities; track $index) {
                <li>
                  <div class="priority-head">
                    <strong>{{ p.title }}</strong>
                    <span class="priority-tags">
                      <span [class]="'tag impact-' + p.impact">Impatto {{ p.impact }}</span>
                      <span class="tag">Sforzo {{ p.effort }}</span>
                      <span class="tag"><i class="ti ti-calendar" aria-hidden="true"></i> {{ p.when }}</span>
                    </span>
                  </div>
                  @if (p.why) { <p class="small">{{ p.why }}</p> }
                  @if (p.how) { <p class="small muted pre">{{ p.how }}</p> }
                  @if (p.kpi) { <p class="small priority-kpi"><i class="ti ti-target-arrow" aria-hidden="true"></i> Da misurare: {{ p.kpi }}</p> }
                </li>
              }
            </ol>
          }

          <div class="ceo-columns">
            @if (a.quick_wins.length) {
              <div class="quick-wins"><p class="kit-label"><i class="ti ti-bolt" aria-hidden="true"></i> Da fare questa settimana</p><ul class="small">@for (x of a.quick_wins; track x) { <li>{{ x }}</li> }</ul></div>
            }
            @if (a.revenue_ideas.length) {
              <div class="opportunities"><p class="kit-label"><i class="ti ti-coin-euro" aria-hidden="true"></i> Nuove entrate possibili</p><ul class="small">@for (x of a.revenue_ideas; track x) { <li>{{ x }}</li> }</ul></div>
            }
          </div>

          <div class="ceo-columns">
            @if (a.kpis.length) {
              <div>
                <p class="kit-label">Obiettivi a 90 giorni</p>
                <table class="kpi-table"><tbody>@for (k of a.kpis; track k.name) { <tr><td>{{ k.name }}</td><td>{{ k.target }}</td></tr> }</tbody></table>
              </div>
            }
            @if (a.questions.length) {
              <div><p class="kit-label">Da chiedere al titolare</p><ul class="small">@for (q of a.questions; track q) { <li>{{ q }}</li> }</ul></div>
            }
          </div>
          @if (s.analyzed_at) { <p class="muted small">Analisi del {{ s.analyzed_at.replace(' ', 'T') | date: 'd MMM yyyy, HH:mm' }}</p> }
        } @else if (!s.analyzing && brand.website_url) {
          <p class="muted small">Ancora nessuna diagnosi: premi «Analizza come un CEO».</p>
        }

        @if (s.profile; as p) {
          <details class="site-facts" [open]="!s.analysis">
            <summary>
              <span [class]="'score-badge small ' + scoreClass(p.score)">{{ p.score }}</span>
              Cosa abbiamo trovato sul sito
              @if (s.profile_at) { <span class="muted small">· letto il {{ s.profile_at.replace(' ', 'T') | date: 'd MMM yyyy' }}</span> }
            </summary>
            <div class="facts-grid">
              <div>
                <p class="kit-label">Contatti</p>
                @if (p.facts?.contacts; as c) {
                  <ul class="facts-list small">
                    @for (phone of c.phones; track phone) { <li><i class="ti ti-phone" aria-hidden="true"></i> <a [href]="'tel:' + phone">{{ phone }}</a></li> }
                    @for (mail of c.emails; track mail) { <li><i class="ti ti-mail" aria-hidden="true"></i> <a [href]="'mailto:' + mail">{{ mail }}</a></li> }
                    @if (c.whatsapp) { <li><i class="ti ti-brand-whatsapp" aria-hidden="true"></i> <a [href]="c.whatsapp" target="_blank" rel="noopener">WhatsApp</a></li> }
                    @if (c.address) { <li><i class="ti ti-map-pin" aria-hidden="true"></i> {{ c.address }}</li> }
                    @if (c.vat) { <li><i class="ti ti-receipt" aria-hidden="true"></i> P. IVA {{ c.vat }}</li> }
                    @if (!c.phones.length && !c.emails.length && !c.whatsapp && !c.address) { <li class="muted">Nessun contatto trovato sul sito.</li> }
                  </ul>
                } @else { <p class="small muted">Rileggi il sito per vedere i contatti.</p> }
              </div>
              <div>
                <p class="kit-label">L'attività</p>
                <ul class="facts-list small">
                  @if (p.facts?.name) { <li><i class="ti ti-building-store" aria-hidden="true"></i> {{ p.facts!.name }}@if (p.facts!.legal_name) { ({{ p.facts!.legal_name }}) }</li> }
                  @if (p.facts?.rating; as r) { <li><i class="ti ti-star" aria-hidden="true"></i> {{ r.value }} su 5 · {{ r.count }} recensioni</li> }
                  @if (p.facts?.opening_hours) { <li><i class="ti ti-clock" aria-hidden="true"></i> {{ p.facts!.opening_hours }}</li> }
                  @if (p.facts?.price_range) { <li><i class="ti ti-tag" aria-hidden="true"></i> Fascia di prezzo {{ p.facts!.price_range }}</li> }
                  @if (p.facts?.founded) { <li><i class="ti ti-calendar" aria-hidden="true"></i> Dal {{ p.facts!.founded }}</li> }
                  @for (link of socialLinks(p); track link.url) { <li><i class="ti ti-world" aria-hidden="true"></i> <a [href]="link.url" target="_blank" rel="noopener">{{ link.label }}</a></li> }
                  @if (!socialLinks(p).length) { <li class="muted">Nessun profilo social collegato dal sito.</li> }
                </ul>
              </div>
              @if (p.signals; as sig) {
                <div>
                  <p class="kit-label">Il sito</p>
                  <ul class="facts-list small">
                    <li><i class="ti ti-stack-2" aria-hidden="true"></i> {{ sig.platform || 'Piattaforma non riconosciuta' }}</li>
                    <li><i class="ti ti-device-mobile" aria-hidden="true"></i> {{ sig.mobile ? 'Adatto al telefono' : 'Non adatto al telefono' }} · risposta in {{ sig.seconds | number: '1.1-2' }} s · {{ sig.page_kb }} KB</li>
                    @if (sig.languages > 1) { <li><i class="ti ti-language" aria-hidden="true"></i> {{ sig.languages }} lingue</li> }
                    @if (sig.images_without_alt) { <li><i class="ti ti-photo-off" aria-hidden="true"></i> {{ sig.images_without_alt }} immagini su {{ sig.images }} senza descrizione</li> }
                    <li><i class="ti ti-cookie" aria-hidden="true"></i> Cookie: {{ sig.consent || 'gestione non riconosciuta' }}@if (sig.chat) { · chat {{ sig.chat }} }</li>
                  </ul>
                  <div class="pillars">
                    @for (tool of sig.tools; track tool) { <span class="tag tool-tag">{{ tool }}</span> }
                    @for (f of features(p); track f) { <span class="tag">{{ f }}</span> }
                  </div>
                </div>
              }
            </div>
            <ul class="audit-checks">
              @for (c of p.checks; track c.key) {
                <li [class.ok]="c.ok">
                  <i [class]="'ti ' + (c.ok ? 'ti-circle-check' : 'ti-alert-circle')" aria-hidden="true"></i>
                  <div><strong class="small">{{ c.label }}</strong><span>{{ c.detail }}</span></div>
                </li>
              }
            </ul>
          </details>
        }
      } @else {
        <p class="muted small">Caricamento…</p>
      }
    </section>
  `,
})
export class SiteAnalysisComponent implements OnChanges {
  @Input({ required: true }) brand!: Brand;
  /** L'analisi può aver aggiunto il logo al cliente: la scheda va ricaricata. */
  @Output() brandChanged = new EventEmitter<void>();
  private api = inject(ApiService);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);

  state = signal<SiteAnalysisState | null>(null);
  busy = signal(false);
  error = signal<string | null>(null);

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['brand']?.previousValue?.id !== this.brand.id) this.reload();
  }

  reload(): void {
    this.api.siteAnalysis(this.brand.id).subscribe({
      next: (s) => {
        this.state.set(s);
        if (s.analyzing) this.follow(s);
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  start(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.startSiteAnalysis(this.brand.id).subscribe({
      next: (s) => {
        this.busy.set(false);
        this.state.set(s);
        this.follow(s);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  average(a: SiteDiagnosis): number {
    return a.scorecard.reduce((sum, row) => sum + row.score, 0) / a.scorecard.length;
  }

  barClass(score: number): string {
    return score >= 7 ? 'good' : score >= 5 ? 'mid' : 'low';
  }

  scoreClass(score: number): string {
    return score >= 75 ? 'good' : score >= 50 ? 'mid' : 'low';
  }

  features(p: SiteProfile): string[] {
    const found = p.signals?.features ?? {};
    return Object.entries(found).filter(([, on]) => on).map(([key]) => FEATURES[key] ?? key);
  }

  socialLinks(p: SiteProfile): { label: string; url: string }[] {
    const all: Record<string, string | undefined> = { ...p.socials, ...(p.facts?.other_socials ?? {}) };
    return Object.entries(all).filter((e): e is [string, string] => !!e[1]).map(([key, url]) => ({ label: SOCIALS[key] ?? key, url }));
  }

  private follow(s: SiteAnalysisState): void {
    if (!s.job_id) return;
    this.api.waitForJob(s.job_id).pipe(switchMap(() => this.api.siteAnalysis(this.brand.id)), takeUntilDestroyed(this.destroyRef)).subscribe((fresh) => {
      this.state.set(fresh);
      if (fresh.analysis) this.brandChanged.emit();
    });
  }
}

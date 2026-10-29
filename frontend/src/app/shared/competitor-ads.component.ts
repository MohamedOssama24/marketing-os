import { Component, DestroyRef, Input, OnChanges, computed, inject, signal } from '@angular/core';
import { LOCALE } from '../i18n/i18n';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CurrencyPipe, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { AdDraft, CompetitorAds, CompetitorAdsView, SpendRange, apiErrorMessage } from '../core/models';

const PLATFORMS: Record<string, string> = { facebook: 'Facebook', instagram: 'Instagram', messenger: 'Messenger', audience_network: 'Audience Network', threads: 'Threads' };

/**
 * Nella scheda cliente: le inserzioni dei concorrenti dall'archivio di Meta, con chi raggiungono, in quali città,
 * quali funzionano, cosa offrono e quanto spendono (stima), accanto alla spesa reale del cliente.
 */
@Component({
  selector: 'app-competitor-ads',
  standalone: true,
  imports: [CurrencyPipe, DatePipe, DecimalPipe, FormsModule],
  template: `
    <section class="card growth-card competitor-ads" id="competitor-ads">
      <div class="strategy-head">
        <h2><i class="ti ti-speakerphone" aria-hidden="true"></i> Inserzioni dei concorrenti</h2>
        @if (view()?.source === 'sample') { <span class="tag status-sent" title="Collega l'archivio di Meta per i dati reali">Dati di esempio</span> }
      </div>
      <p class="muted small">
        Dall'archivio inserzioni di Meta: cosa sponsorizzano, a chi e dove. Meta non pubblica la spesa: la stimiamo dalle persone raggiunte.
      </p>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (view(); as v) {
        @if (v.source === null) {
          <div class="ads-setup">
            <p><strong>Collega l'archivio inserzioni di Meta</strong> (gratis) per vedere le inserzioni dei concorrenti:</p>
            <ol class="small">
              <li>Verifica la tua identità su <a href="https://www.facebook.com/ID" target="_blank" rel="noopener">facebook.com/ID</a> (serve un documento, una volta sola).</li>
              <li>Crea un'app su <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com</a> e accetta i termini dell'archivio inserzioni.</li>
              <li>Genera un codice di accesso e scrivilo in <code>META_AD_LIBRARY_TOKEN</code> nel file <code>.env</code>, poi riavvia il worker.</li>
            </ol>
            <p class="muted small">Nel frattempo puoi aprire l'archivio dalla scheda di ogni concorrente qui sopra.</p>
          </div>
        } @else if (!v.competitors.length) {
          <p class="muted small">Aggiungi un concorrente qui sopra: le sue inserzioni compaiono qui.</p>
        } @else {
          <div class="ads-compare">
            <p class="kit-label">Chi spende di più · ultimi 30 giorni</p>
            <div class="hbar-list">
              <div class="hbar static">
                <span class="hbar-head"><strong>{{ brandName }} <span class="muted">(spesa reale)</span></strong>
                  <span class="hbar-value">{{ v.client_spend_30d | currency: 'EUR' : 'symbol' : '1.0-0' }}</span></span>
                <span class="hbar-track"><span class="hbar-fill" [style.width.%]="(v.client_spend_30d / maxSpend()) * 100"></span></span>
              </div>
              @for (c of v.competitors; track c.id) {
                <div class="hbar static">
                  <span class="hbar-head"><strong>{{ c.name }} <span class="muted">(stima)</span></strong>
                    <span class="hbar-value">{{ range(c.insights.spend_30d) }}</span></span>
                  <span class="hbar-track">
                    @if (c.insights.spend_30d; as s) {
                      <span class="hbar-fill estimate" [style.width.%]="(s.high / maxSpend()) * 100"><span class="estimate-low" [style.width.%]="s.high ? (s.low / s.high) * 100 : 0"></span></span>
                    }
                  </span>
                </div>
              }
            </div>
            <p class="muted small">
              Stima = persone raggiunte × {{ v.frequency[0] | number: '1.1-1' }}–{{ v.frequency[1] | number: '1.1-1' }} visualizzazioni a testa × costo per 1.000 visualizzazioni
              @if (editingCpm()) {
                <input class="cpm-input" type="number" min="0.5" max="100" step="0.5" [(ngModel)]="cpm" aria-label="Costo per 1.000 visualizzazioni in euro" /> €
                <button class="link-btn small" (click)="saveCpm()" [disabled]="busy()">Salva</button>
                <button class="link-btn small" (click)="editingCpm.set(false)">Annulla</button>
              } @else {
                ({{ v.cpm | currency: 'EUR' }}@if (v.cpm === v.default_cpm) {, media in Italia}).
                @if (auth.canEdit()) { <button class="link-btn small" (click)="cpm = v.cpm; editingCpm.set(true)">Cambia</button> }
              }
              @if (!v.client_spend_30d) { <br />La spesa del cliente arriva dalle sponsorizzate registrate in Sponsorizzate. }
            </p>
          </div>

          @for (c of v.competitors; track c.id) {
            <article class="competitor-ads-card">
              <header>
                <strong class="ellipsis">{{ c.name }}</strong>
                @if (c.new_ads && c.new_at && isRecent(c.new_at)) { <span class="tag status-sent">{{ c.new_ads === 1 ? '1 nuova' : c.new_ads + ' nuove' }}</span> }
                <a class="icon-btn" [href]="c.library_url" target="_blank" rel="noopener" title="Apri nell'archivio di Meta" aria-label="Apri nell'archivio di Meta"><i class="ti ti-external-link" aria-hidden="true"></i></a>
                @if (auth.canEdit()) {
                  <button class="icon-btn" (click)="sync(c)" [disabled]="c.syncing" title="Rileggi le inserzioni" aria-label="Rileggi le inserzioni"><i class="ti ti-refresh" aria-hidden="true"></i></button>
                }
              </header>

              @if (c.syncing) {
                <p class="rewrite-progress"><span class="spinner small"></span> Leggo le inserzioni…</p>
              } @else if (c.error) {
                <p class="alert small">{{ c.error }}</p>
              }
              @if (auth.canEdit() && (c.error || pageFor() === c.id)) {
                <form class="rewrite-custom" (ngSubmit)="setPage(c)">
                  <input [(ngModel)]="pageInput" name="page" placeholder="Link della pagina nell'archivio inserzioni (view_all_page_id=…)" aria-label="Pagina del concorrente nell'archivio di Meta" />
                  <button class="btn primary small" [disabled]="!pageInput.trim() || busy()">Usa questa pagina</button>
                </form>
              }

              @if (c.insights.ads) {
                <div class="ads-kpis">
                  <div><span class="kpi-value">{{ c.insights.active }}</span><span class="muted small">attive ora</span></div>
                  <div><span class="kpi-value">{{ c.insights.new_30d }}</span><span class="muted small">nuove in 30 giorni</span></div>
                  <div><span class="kpi-value">{{ c.insights.reach | number }}</span><span class="muted small">persone raggiunte</span></div>
                  <div><span class="kpi-value">{{ range(c.insights.spend) }}</span><span class="muted small">spesa stimata, {{ v.window_days }} giorni</span></div>
                </div>

                @if (c.summary; as s) {
                  <div class="ads-summary">
                    <p class="small">{{ s.summary }}</p>
                    <dl class="small">
                      @if (s.offer) { <dt>Leva</dt><dd>{{ s.offer }}</dd> }
                      @if (s.audience) { <dt>A chi</dt><dd>{{ s.audience }}</dd> }
                      @if (s.tone) { <dt>Tono</dt><dd>{{ s.tone }}</dd> }
                    </dl>
                    @if (s.counter_ad.primary_text) {
                      <div class="strength">
                        <strong class="small"><i class="ti ti-sparkles" aria-hidden="true"></i> La sponsorizzata del cliente per fare meglio</strong>
                        <p class="small">«{{ s.counter_ad.headline }}» — {{ s.counter_ad.primary_text }}</p>
                        @if (auth.canEdit()) {
                          <button class="link-btn small" (click)="createAd(c)"><i class="ti ti-speakerphone" aria-hidden="true"></i> Crea una sponsorizzata migliore di questa</button>
                        }
                      </div>
                    }
                  </div>
                }

                <div class="ads-columns">
                  @if (c.insights.ages.length) {
                    <div>
                      <p class="kit-label">Chi raggiunge</p>
                      @if (c.insights.women_percent !== null) { <p class="small">Donne {{ c.insights.women_percent }}% · uomini {{ 100 - c.insights.women_percent }}%</p> }
                      <div class="age-bars">
                        @for (a of c.insights.ages; track a.age) {
                          <div class="age-row" [title]="a.age + ' anni: ' + a.percent + '%'">
                            <span class="small">{{ a.age }}</span>
                            <span class="hbar-track"><span class="hbar-fill" [style.width.%]="a.percent"></span></span>
                            <span class="small hbar-value">{{ a.percent }}%</span>
                          </div>
                        }
                      </div>
                    </div>
                  }
                  <div>
                    @if (c.insights.places.length) {
                      <p class="kit-label">Città e zone scelte</p>
                      <div class="pillars">@for (p of c.insights.places; track p.name) { <span class="tag" [title]="p.ads + ' inserzioni'"><i class="ti ti-map-pin" aria-hidden="true"></i> {{ p.name }} · {{ p.ads }}</span> }</div>
                      @if (c.insights.excluded_places.length) { <p class="muted small">Escluse: {{ c.insights.excluded_places.join(', ') }}</p> }
                    }
                    @if (c.insights.countries.length > 1) {
                      <p class="kit-label">Paesi</p>
                      <p class="small">@for (k of c.insights.countries; track k.country) { {{ k.country }} {{ k.percent }}%{{ $last ? '' : ' · ' }} }</p>
                    }
                    @if (c.insights.platforms.length) {
                      <p class="kit-label">Dove</p>
                      <div class="pillars">@for (p of c.insights.platforms; track p.platform) { <span class="tag">{{ platformLabel(p.platform) }} · {{ p.ads }}</span> }</div>
                    }
                    @if (c.insights.cadence.length) {
                      <p class="kit-label">Nuove inserzioni al mese</p>
                      <p class="small">@for (m of c.insights.cadence; track m.month) { {{ m.month + '-01' | date: 'MMM' }} {{ m.ads }}{{ $last ? '' : ' · ' }} }</p>
                    }
                  </div>
                </div>

                @if (c.insights.offers.length) {
                  <p class="kit-label"><i class="ti ti-discount" aria-hidden="true"></i> Offerte e promozioni</p>
                  <ul class="small">@for (o of c.insights.offers; track $index) { <li>{{ o }}</li> }</ul>
                }
                @if (c.insights.domains.length) {
                  <p class="kit-label">Pagine di destinazione</p>
                  <p class="small">@for (d of c.insights.domains; track d.domain) { {{ d.domain }} ({{ d.ads }}){{ $last ? '' : ' · ' }} }</p>
                }

                <p class="kit-label">Inserzioni recenti</p>
                <div class="ad-list">
                  @for (ad of c.ads; track ad.archive_id) {
                    <a class="ad-item" [href]="ad.url" target="_blank" rel="noopener" [class.ended]="!ad.active">
                      <span class="ad-item-head">
                        @if (ad.winner) { <span class="tag impact-alta" title="Attiva da più di 30 giorni: probabilmente funziona"><i class="ti ti-trophy" aria-hidden="true"></i> Funziona</span> }
                        <span class="tag">{{ ad.active ? 'Attiva da ' + ad.days + ' gg' : 'Durata ' + ad.days + ' gg' }}</span>
                        @if (ad.spend) { <span class="muted small">{{ range(ad.spend) }}</span> }
                      </span>
                      @if (ad.title) { <strong class="small">{{ ad.title }}</strong> }
                      <span class="small ad-text">{{ ad.text }}</span>
                      <span class="muted small">
                        {{ ad.started_on | date: 'd MMM' }}@if (ad.places.length) { · {{ ad.places.join(', ') }} }@if (ad.reach) { · {{ ad.reach | number }} persone }
                      </span>
                    </a>
                  }
                </div>
              } @else if (!c.syncing && !c.error && c.checked_at) {
                <p class="muted small">Nessuna inserzione negli ultimi {{ v.window_days }} giorni.</p>
              }
              <footer class="muted small">
                @if (c.checked_at) { <span>Letto il {{ c.checked_at.replace(' ', 'T') | date: 'd MMM, HH:mm' }}</span> }
                @if (auth.canEdit() && !c.error && pageFor() !== c.id) { <button class="link-btn small" (click)="pageInput = ''; pageFor.set(c.id)">Non è la pagina giusta?</button> }
              </footer>
            </article>
          }
        }
      } @else if (!error()) {
        <p class="muted small">Caricamento…</p>
      }
    </section>
  `,
})
export class CompetitorAdsComponent implements OnChanges {
  @Input({ required: true }) brandId!: number;
  @Input() brandName = 'Il cliente';
  private api = inject(ApiService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private destroyRef = inject(DestroyRef);
  readonly auth = inject(AuthService);

  view = signal<CompetitorAdsView | null>(null);
  error = signal<string | null>(null);
  busy = signal(false);
  editingCpm = signal(false);
  pageFor = signal<number | null>(null);
  pageInput = '';
  cpm = 0;

  /** Scala del confronto: la barra più lunga è la spesa più alta (stima massima per i concorrenti). */
  maxSpend = computed(() => {
    const v = this.view();
    return Math.max(1, v?.client_spend_30d ?? 0, ...(v?.competitors.map((c) => c.insights.spend_30d?.high ?? 0) ?? []));
  });

  ngOnChanges(): void {
    this.reload();
  }

  /** Ricarica la sezione (anche quando si aggiunge o si toglie un concorrente). */
  reload(): void {
    this.api.competitorAds(this.brandId).subscribe({
      next: (v) => {
        // Dall'avviso "nuove inserzioni di un concorrente" si arriva direttamente qui.
        if (!this.view() && this.route.snapshot.queryParamMap.get('tab') === 'competitors') {
          setTimeout(() => document.getElementById('competitor-ads')?.scrollIntoView(), 500);
        }
        this.view.set(v);
        v.competitors.filter((c) => c.syncing && c.job_id).forEach((c) => this.follow(c.job_id!));
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  sync(c: CompetitorAds): void {
    this.error.set(null);
    this.api.syncCompetitorAds(c.id).subscribe({ next: (r) => { this.reload(); this.follow(r.job_id); }, error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  setPage(c: CompetitorAds): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.syncCompetitorAds(c.id, this.pageInput.trim()).subscribe({
      next: (r) => { this.busy.set(false); this.pageFor.set(null); this.pageInput = ''; this.reload(); this.follow(r.job_id); },
      error: (err) => { this.busy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  saveCpm(): void {
    this.busy.set(true);
    this.api.updateCompetitorAdsCpm(this.cpm || null).subscribe({
      next: () => { this.busy.set(false); this.editingCpm.set(false); this.reload(); },
      error: (err) => { this.busy.set(false); this.error.set(apiErrorMessage(err)); },
    });
  }

  /** Apre la procedura guidata della sponsorizzata con i testi proposti dall'AI. */
  createAd(c: CompetitorAds): void {
    const s = c.summary!.counter_ad;
    const draft: AdDraft = { brand_id: this.brandId, primary_text: s.primary_text, headline: s.headline };
    this.router.navigate(['/ads'], { state: { adDraft: draft } });
  }

  range(s: SpendRange | null): string {
    if (!s) return '—';
    const eur = (n: number) => n.toLocaleString(LOCALE, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
    return s.low === s.high ? eur(s.low) : `${eur(s.low)}–${eur(s.high)}`;
  }

  platformLabel(p: string): string {
    return PLATFORMS[p] ?? p;
  }

  isRecent(date: string): boolean {
    return Date.now() - new Date(date.replace(' ', 'T')).getTime() < 7 * 86400000;
  }

  private follow(jobId: number): void {
    this.api.waitForJob(jobId).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ next: () => this.reload(), error: () => this.reload() });
  }
}

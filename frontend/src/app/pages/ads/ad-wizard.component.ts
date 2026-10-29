import { Component, EventEmitter, Input, OnDestroy, OnInit, Output, computed, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe, NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, Subscription, debounceTime, map, switchMap, timer, takeWhile } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import {
  AdCheck, AdIssue, AdObjective, AdPlan, AdPlanInput, AdVerdict, Brand, ContentItem, MetaRulebook, RuleSeverity, apiErrorMessage,
} from '../../core/models';
import { PostVisualComponent } from '../../shared/post-visual.component';
import { publishableText } from '../../shared/publishing';

const STEPS = ['Obiettivo', 'Pubblico', 'Budget', 'Testi', 'Pronta per Meta'];
const SEVERITY: Record<RuleSeverity, { icon: string; label: string }> = {
  block: { icon: 'ti-circle-x', label: 'Da correggere' },
  warn: { icon: 'ti-alert-triangle', label: 'Attenzione' },
  tip: { icon: 'ti-bulb', label: 'Consiglio' },
};
const VERDICT = {
  ok: { icon: 'ti-circle-check', text: 'Rispetta le regole di Meta controllate' },
  warning: { icon: 'ti-alert-triangle', text: 'Si può pubblicare, ma controlla gli avvisi' },
  blocked: { icon: 'ti-circle-x', text: 'Così Meta la respingerebbe: correggi i punti in rosso' },
};

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Procedura guidata per preparare una sponsorizzata su Meta: obiettivo, pubblico, budget e testi,
 * controllati con le regole di Meta mentre si scrive. Alla fine una scheda da copiare in Gestione inserzioni.
 */
@Component({
  selector: 'app-ad-wizard',
  standalone: true,
  imports: [CurrencyPipe, DatePipe, FormsModule, NgTemplateOutlet, PostVisualComponent],
  template: `
    <div class="modal-backdrop" (click)="closed.emit()">
      <div class="modal ad-wizard" (click)="$event.stopPropagation()" role="dialog" aria-label="Sponsorizzata su Meta">
        <div class="modal-head">
          <h2><i class="ti ti-brand-meta" aria-hidden="true"></i> {{ saved() ? saved()!.name : 'Nuova sponsorizzata' }}</h2>
          <span class="muted"></span>
          <button type="button" class="icon-btn" (click)="closed.emit()" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button>
        </div>

        <ol class="wizard-steps">
          @for (s of steps; track s; let i = $index) {
            <li [class.on]="step() === i" [class.done]="step() > i">
              <button type="button" (click)="goTo(i)" [disabled]="!canGoTo(i)"><span class="step-num">{{ i + 1 }}</span>{{ s }}</button>
            </li>
          }
        </ol>

        <div class="modal-body wizard-body">
          @if (error()) { <div class="alert">{{ error() }}</div> }
          @if (!rules()) { <p class="muted">Caricamento delle regole di Meta…</p> }
          @else {
            @switch (step()) {
              @case (0) {
                <div class="wizard-grid">
                  <div class="wizard-main">
                    <label>Cliente
                      <select [(ngModel)]="ad.brand_id" (ngModelChange)="changed()" [disabled]="!!saved()">
                        @for (b of brands; track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
                      </select>
                    </label>
                    @if (content()) {
                      <p class="hint small"><i class="ti ti-photo" aria-hidden="true"></i> Sponsorizzi il post «{{ content()!.title }}»: testo e grafica sono già pronti.</p>
                    }
                    <span class="field-label">Cosa vuoi ottenere?</span>
                    <div class="objective-grid">
                      @for (o of rules()!.objectives; track o.key) {
                        <button type="button" class="payer-option" [class.on]="ad.objective === o.key" (click)="setObjective(o.key)">
                          <strong><i class="ti" [class]="'ti ' + o.icon" aria-hidden="true"></i> {{ o.label }}</strong>
                          <span>{{ o.when }}</span>
                        </button>
                      }
                    </div>
                    <label>Di cosa parla l'inserzione?
                      <select [(ngModel)]="ad.special_category" (ngModelChange)="changed()">
                        @for (c of rules()!.special_categories; track c.key) { <option [ngValue]="c.key">{{ c.label }}</option> }
                      </select>
                    </label>
                    <p class="muted small">{{ categoryDetail() }}</p>
                  </div>
                </div>
              }
              @case (1) {
                <div class="wizard-main narrow-col">
                  <label class="toggle-row">
                    <input type="checkbox" [(ngModel)]="ad.audience.advantage" (ngModelChange)="changed()" />
                    <span><strong>Lascia che Meta trovi le persone giuste</strong> <span class="tag ai">Consigliato</span><br />
                      <small class="muted">Pubblico Advantage+: età e interessi diventano suggerimenti e Meta cerca da sola chi risponde meglio.</small></span>
                  </label>
                  <div class="form-grid">
                    <label>Dove
                      <input [(ngModel)]="ad.audience.location" (ngModelChange)="changed()" placeholder="Es. Pescara, Abruzzo, Italia" />
                    </label>
                    <label>Raggio intorno (km)
                      <input type="number" min="0" max="80" [(ngModel)]="ad.audience.radius_km" (ngModelChange)="changed()" />
                    </label>
                    <label>Età da
                      <input type="number" min="13" max="65" [(ngModel)]="ad.audience.age_min" (ngModelChange)="changed()" [disabled]="lockedAudience()" />
                    </label>
                    <label>Età fino a (65 = 65+)
                      <input type="number" min="13" max="65" [(ngModel)]="ad.audience.age_max" (ngModelChange)="changed()" [disabled]="lockedAudience()" />
                    </label>
                  </div>
                  <span class="field-label">Genere</span>
                  <div class="filters">
                    @for (g of genders; track g.key) {
                      <button type="button" class="chip" [class.active]="ad.audience.genders === g.key" (click)="ad.audience.genders = g.key; changed()" [disabled]="lockedAudience()">{{ g.label }}</button>
                    }
                  </div>
                  @if (lockedAudience()) { <p class="muted small">Con la categoria «{{ categoryLabel() }}» Meta non permette di scegliere età e genere.</p> }
                  <label>Interessi (separati da virgola)
                    <input [(ngModel)]="interests" (ngModelChange)="setInterests($event)" placeholder="Es. pizza, cucina italiana, cene fuori" />
                  </label>
                  <p class="muted small">Passioni e abitudini, non caratteristiche personali: Meta non permette di scegliere il pubblico per salute, religione, politica o orientamento.</p>
                </div>
              }
              @case (2) {
                <div class="wizard-main narrow-col">
                  <div class="form-grid">
                    <label>Budget al giorno (€)
                      <input type="number" min="1" step="1" [(ngModel)]="ad.daily_budget" (ngModelChange)="changed()" />
                    </label>
                    <span></span>
                    <label>Dal
                      <input type="date" [(ngModel)]="ad.start_date" (ngModelChange)="changed()" />
                    </label>
                    <label>Al (vuoto = finché non la fermi)
                      <input type="date" [(ngModel)]="endDate" (ngModelChange)="ad.end_date = $event || null; changed()" [min]="ad.start_date" />
                    </label>
                  </div>
                  <div class="budget-summary">
                    @if (days(); as d) {
                      <strong>{{ ad.daily_budget * d | currency: 'EUR' }}</strong>
                      <span class="muted">spesa massima in {{ d }} {{ d === 1 ? 'giorno' : 'giorni' }}</span>
                    } @else {
                      <strong>{{ ad.daily_budget * 30 | currency: 'EUR' }}</strong>
                      <span class="muted">circa al mese, finché non la fermi</span>
                    }
                  </div>
                  <p class="muted small">Meta accetta da {{ rules()!.budget.min_daily | currency: 'EUR' }} al giorno. Con almeno {{ rules()!.budget.suggested_daily | currency: 'EUR' : 'symbol' : '1.0-0' }} al giorno per 7 giorni
                    finisce prima la fase di apprendimento e i risultati costano meno.</p>
                </div>
              }
              @case (3) {
                <div class="wizard-grid">
                  <div class="wizard-main">
                    <label>Testo principale <span class="counter" [class.over]="ad.primary_text.length > rules()!.text_limits.primary_text">{{ ad.primary_text.length }}/{{ rules()!.text_limits.primary_text }}</span>
                      <textarea rows="5" [(ngModel)]="ad.primary_text" (ngModelChange)="changed()" placeholder="Cosa offre il cliente e perché sceglierlo, in 1-3 frasi."></textarea>
                    </label>
                    <div class="form-grid">
                      <label>Titolo <span class="counter" [class.over]="ad.headline.length > rules()!.text_limits.headline">{{ ad.headline.length }}/{{ rules()!.text_limits.headline }}</span>
                        <input [(ngModel)]="ad.headline" (ngModelChange)="changed()" />
                      </label>
                      <label>Descrizione <span class="counter" [class.over]="ad.description.length > rules()!.text_limits.description">{{ ad.description.length }}/{{ rules()!.text_limits.description }}</span>
                        <input [(ngModel)]="ad.description" (ngModelChange)="changed()" />
                      </label>
                      <label>Pulsante
                        <select [(ngModel)]="ad.cta" (ngModelChange)="changed()">
                          @for (c of ctaOptions(); track c.code) { <option [ngValue]="c.code">{{ c.label }}</option> }
                        </select>
                      </label>
                      <label>Link{{ objective()?.needs_url ? '' : ' (facoltativo)' }}
                        <input [(ngModel)]="ad.url" (ngModelChange)="changed()" placeholder="https://" />
                      </label>
                    </div>
                  </div>
                  <aside class="wizard-side">
                    <div class="ad-preview">
                      <div class="ad-preview-head">
                        <span class="avatar" [style.background]="brand()?.primary_color ?? '#5B4BDB'"></span>
                        <span><strong>{{ brand()?.name }}</strong><br /><small class="muted">Sponsorizzato</small></span>
                      </div>
                      <p class="ad-preview-text">{{ previewText() }}@if (ad.primary_text.length > rules()!.text_limits.primary_text) {<span class="muted">… Altro</span>}</p>
                      @if (content()?.visual) {
                        <app-post-visual [visual]="content()!.visual" [brand]="brand()" />
                      } @else {
                        <div class="ad-preview-media" [style.background]="brand()?.primary_color ?? '#5B4BDB'"><span>{{ ad.headline || 'La tua immagine' }}</span></div>
                      }
                      <div class="ad-preview-foot">
                        <span><small class="muted">{{ host() }}</small><br /><strong>{{ ad.headline || 'Titolo' }}</strong>@if (ad.description) {<br /><small class="muted">{{ ad.description }}</small>}</span>
                        <span class="btn ghost small">{{ ctaLabel(ad.cta) }}</span>
                      </div>
                    </div>
                  </aside>
                </div>
              }
              @case (4) {
                @if (saved(); as p) {
                  <div class="wizard-grid">
                    <div class="wizard-main">
                      <div class="sheet">
                        <div class="strategy-head"><h3>Scheda per Gestione inserzioni</h3>
                          <a class="btn primary small" [href]="p.ads_manager_url" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> Apri Gestione inserzioni</a></div>
                        <p class="muted small">Su Meta clicca «Crea» e compila i campi in quest'ordine: con <i class="ti ti-copy" aria-hidden="true"></i> copi ogni valore.
                          @if (!p.ads_manager_url.includes('act=')) { Inserisci l'ID dell'account pubblicitario nella scheda del cliente per aprire direttamente il suo account. }</p>
                        @for (group of sheet(); track group.title) {
                          <h4>{{ group.title }}</h4>
                          <dl class="sheet-rows">
                            @for (row of group.rows; track row.label) {
                              <div><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd>
                                <button type="button" class="icon-btn" (click)="copy(row.value, row.label)" [attr.aria-label]="'Copia ' + row.label" [title]="copied() === row.label ? 'Copiato' : 'Copia'">
                                  <i class="ti" [class]="copied() === row.label ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i></button></div>
                            }
                          </dl>
                        }
                        <p class="muted small">Il link ha i parametri UTM: in Analytics vedi le visite arrivate da questa sponsorizzata.</p>
                      </div>
                    </div>
                    <aside class="wizard-side">
                      @if (p.review_status === 'queued' || p.review_status === 'running') {
                        <div class="rewrite-progress"><span class="spinner"></span> L'AI sta controllando l'inserzione e la pagina di destinazione…</div>
                      } @else if (!p.review && auth.canEdit()) {
                        <button type="button" class="btn ghost full-width" (click)="review()" [disabled]="busy()"><i class="ti ti-sparkles" aria-hidden="true"></i> Controllo approfondito con l'AI</button>
                        <p class="muted small">L'AI rilegge testi e pagina di destinazione come un revisore di Meta e propone una versione corretta.</p>
                      }
                      @if (p.review && p.review_status !== 'queued' && p.review_status !== 'running') {
                        @if (p.review; as r) {
                        <div class="verdict" [attr.data-verdict]="r.verdict"><i class="ti" [class]="'ti ' + verdictInfo[r.verdict].icon" aria-hidden="true"></i>
                          <span><strong>Revisione AI:</strong> {{ r.summary }}</span></div>
                        @if (r.landing_ok === false) { <p class="muted small"><i class="ti ti-link-off" aria-hidden="true"></i> La pagina del link non si è aperta: controllala prima di pubblicare.</p> }
                        @for (i of r.issues; track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
                        @if (r.improved; as imp) {
                          <div class="improved">
                            <strong>Testi corretti dall'AI</strong>
                            <p>{{ imp.primary_text }}</p>
                            <small><strong>{{ imp.headline }}</strong>@if (imp.description) { · {{ imp.description }}}</small>
                            @if (auth.canEdit()) { <button type="button" class="btn ghost small" (click)="useImproved()" [disabled]="busy()">Usa questi testi</button> }
                          </div>
                        }
                        @if (r.audience_tip) { <p class="hint small"><i class="ti ti-users" aria-hidden="true"></i> {{ r.audience_tip }}</p> }
                        }
                      }
                      @if (p.review_error) { <div class="alert small">{{ p.review_error }}</div> }
                      @if (p.review && auth.canEdit()) { <button type="button" class="link-btn small" (click)="review()" [disabled]="busy()">Ripeti il controllo</button> }
                    </aside>
                  </div>
                }
              }
            }

            @if (step() < 4) {
              <section class="rule-check" aria-live="polite">
                <div class="verdict" [attr.data-verdict]="stepVerdict()">
                  <i class="ti" [class]="'ti ' + (checking() ? 'ti-loader-2' : verdictInfo[stepVerdict()].icon)" aria-hidden="true"></i>
                  <span><strong>Regole Meta:</strong> {{ checking() ? 'controllo…' : (stepVerdict() === 'ok' && visibleIssues().length ? 'nessun problema, solo consigli' : step() < 3 && stepVerdict() === 'ok' ? 'niente da segnalare in questo passaggio' : verdictInfo[stepVerdict()].text) }}</span>
                </div>
                @if (check()?.suggested_category && ad.special_category === 'none') {
                  <button type="button" class="btn ghost small" (click)="ad.special_category = check()!.suggested_category!; changed()">
                    Dichiara la categoria «{{ categoryLabel(check()!.suggested_category!) }}»</button>
                }
                @for (i of visibleIssues(); track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
              </section>
            } @else {
              @if (saved()?.checks; as c) {
                <section class="rule-check">
                  <div class="verdict" [attr.data-verdict]="c.verdict"><i class="ti" [class]="'ti ' + verdictInfo[c.verdict].icon" aria-hidden="true"></i>
                    <span><strong>Regole Meta:</strong> {{ verdictInfo[c.verdict].text }}</span></div>
                  @for (i of c.issues; track $index) { <ng-container *ngTemplateOutlet="issueTpl; context: { $implicit: i }" /> }
                </section>
              }
            }
          }
        </div>

        <div class="modal-foot">
          @if (step() > 0) { <button type="button" class="btn ghost" (click)="goTo(step() - 1)">Indietro</button> }
          <span class="grow"></span>
          @if (step() < 3) {
            <button type="button" class="btn primary" (click)="goTo(step() + 1)" [disabled]="!canGoTo(step() + 1)">Avanti</button>
          } @else if (step() === 3 && auth.canEdit()) {
            <button type="button" class="btn primary" (click)="save()" [disabled]="busy()"><i class="ti ti-check" aria-hidden="true"></i> {{ saved() ? 'Salva le modifiche' : 'Salva e prepara la scheda' }}</button>
          } @else if (step() === 4 && auth.canEdit()) {
            @if (saved()?.status === 'live') {
              <button type="button" class="btn ghost" (click)="setStatus('ended')" [disabled]="busy()">Segna come terminata</button>
            } @else if (saved()?.status !== 'ended') {
              <button type="button" class="btn success" (click)="setStatus('live')" [disabled]="busy()"><i class="ti ti-rocket" aria-hidden="true"></i> L'ho pubblicata su Meta</button>
            }
          }
        </div>
      </div>
    </div>

    <ng-template #issueTpl let-i>
      <div class="rule-issue" [attr.data-severity]="i.severity">
        <i class="ti" [class]="'ti ' + severityIcon(i.severity)" aria-hidden="true"></i>
        <div>
          <strong>{{ i.title }}</strong>@if (i.excerpt) { <q>{{ i.excerpt }}</q> }
          <p>{{ i.why }}</p>
          <p class="fix"><i class="ti ti-arrow-right" aria-hidden="true"></i> {{ i.fix }}</p>
          @if (i.source) { <a class="small" [href]="i.source" target="_blank" rel="noopener">Regola ufficiale di Meta</a> }
        </div>
      </div>
    </ng-template>
  `,
})
export class AdWizardComponent implements OnInit, OnDestroy {
  @Input({ required: true }) brands: Brand[] = [];
  @Input() plan: AdPlan | null = null;
  @Input() contentId: number | null = null;
  @Input() brandId: number | null = null;
  @Output() closed = new EventEmitter<void>();
  @Output() changedPlan = new EventEmitter<AdPlan>();

  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly steps = STEPS;
  readonly verdictInfo = VERDICT;
  readonly genders = [{ key: 'all' as const, label: 'Tutti' }, { key: 'female' as const, label: 'Donne' }, { key: 'male' as const, label: 'Uomini' }];

  rules = signal<MetaRulebook | null>(null);
  content = signal<ContentItem | null>(null);
  saved = signal<AdPlan | null>(null);
  check = signal<AdCheck | null>(null);
  checking = signal(false);
  step = signal(0);
  busy = signal(false);
  error = signal<string | null>(null);
  copied = signal<string | null>(null);
  /** Cambia a ogni modifica, per ricalcolare anteprima e riepiloghi che leggono il modulo. */
  private version = signal(0);

  ad: AdPlanInput = {
    brand_id: 0, content_id: null, name: '', objective: 'traffic', special_category: 'none',
    audience: { location: 'Italia', radius_km: 0, age_min: 18, age_max: 65, genders: 'all', interests: [], advantage: true },
    daily_budget: 5, start_date: isoDate(new Date()), end_date: null,
    primary_text: '', headline: '', description: '', cta: 'LEARN_MORE', url: '',
  };
  interests = '';
  endDate = '';

  private checks$ = new Subject<void>();
  private subs = new Subscription();

  objective = computed(() => (this.version(), this.rules()?.objectives.find((o) => o.key === this.ad.objective) ?? null));
  brand = computed(() => (this.version(), this.brands.find((b) => b.id === this.ad.brand_id) ?? null));
  lockedAudience = computed(() => (this.version(), ['financial', 'employment', 'housing'].includes(this.ad.special_category)));
  categoryDetail = computed(() => (this.version(), this.rules()?.special_categories.find((c) => c.key === this.ad.special_category)?.detail ?? ''));
  /** Pulsanti consigliati per l'obiettivo in cima, poi tutti gli altri. */
  ctaOptions = computed(() => {
    const all = this.rules()?.ctas ?? [];
    const first = this.objective()?.ctas ?? [];
    return [...first.map((code) => all.find((c) => c.code === code)!).filter(Boolean), ...all.filter((c) => !first.includes(c.code))];
  });
  days = computed(() => {
    this.version();
    if (!this.ad.end_date || !this.ad.start_date) return null;
    const d = Math.round((new Date(this.ad.end_date).getTime() - new Date(this.ad.start_date).getTime()) / 86400000) + 1;
    return d > 0 ? d : null;
  });
  previewText = computed(() => (this.version(), this.ad.primary_text.slice(0, this.rules()?.text_limits.primary_text ?? 125) || 'Il testo della sponsorizzata'));
  host = computed(() => {
    this.version();
    try { return new URL(this.ad.url).host.replace(/^www\./, '').toUpperCase(); } catch { return this.brand()?.website_url ? this.brand()!.website_url!.replace(/^https?:\/\/(www\.)?/, '').split('/')[0].toUpperCase() : ''; }
  });
  /** Nei primi passaggi si mostrano solo i problemi che riguardano quel passaggio, gli altri arrivano dopo. */
  visibleIssues = computed(() => {
    const issues = this.check()?.issues ?? [];
    const step = this.step();
    const byStep: Record<number, (i: AdIssue) => boolean> = {
      0: (i) => ['politics', 'gambling', 'tobacco', 'special_audience'].includes(i.key ?? ''),
      1: (i) => /age|audience|interests|teen/.test(i.key ?? ''),
      2: (i) => /budget|short_run/.test(i.key ?? ''),
    };
    return step === 3 ? issues : issues.filter(byStep[step] ?? (() => true));
  });
  stepVerdict = computed((): AdVerdict => {
    const severities = this.visibleIssues().map((i) => i.severity);
    return severities.includes('block') ? 'blocked' : severities.includes('warn') ? 'warning' : 'ok';
  });
  sheet = computed(() => {
    const p = this.saved();
    const r = this.rules();
    if (!p || !r) return [];
    const a = p.audience;
    const money = (n: number) => n.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
    const date = (d: string) => new Date(d).toLocaleDateString('it-IT');
    return [
      { title: '1. Campagna', rows: [
        { label: 'Obiettivo', value: r.objectives.find((o) => o.key === p.objective)?.label ?? p.objective },
        { label: 'Categoria speciale', value: this.categoryLabel(p.special_category) },
        { label: 'Nome della campagna', value: `${p.brand_name} · ${p.name}` },
      ] },
      { title: '2. Gruppo di inserzioni', rows: [
        { label: 'Budget giornaliero', value: money(p.daily_budget) },
        { label: 'Programmazione', value: `Dal ${date(p.start_date)}` + (p.end_date ? ` al ${date(p.end_date)}` : ', senza data di fine') },
        { label: 'Luoghi', value: a.location + (a.radius_km ? ` + ${a.radius_km} km` : '') },
        { label: 'Età', value: `${a.age_min} - ${a.age_max === 65 ? '65+' : a.age_max}` },
        { label: 'Genere', value: this.genders.find((g) => g.key === a.genders)?.label ?? 'Tutti' },
        { label: 'Pubblico', value: (a.advantage ? 'Pubblico Advantage+ attivo' : 'Pubblico manuale') + (a.interests.length ? ` · interessi: ${a.interests.join(', ')}` : '') },
        { label: 'Posizionamenti', value: 'Posizionamenti Advantage+ (consigliato)' },
      ] },
      { title: '3. Inserzione', rows: [
        { label: 'Testo principale', value: p.primary_text },
        ...(p.headline ? [{ label: 'Titolo', value: p.headline }] : []),
        ...(p.description ? [{ label: 'Descrizione', value: p.description }] : []),
        { label: 'Invito all\'azione', value: this.ctaLabel(p.cta) },
        ...(p.url ? [{ label: 'URL del sito web', value: this.utm(p.url, p) }] : []),
      ] },
    ];
  });

  ngOnInit(): void {
    this.subs.add(this.checks$.pipe(debounceTime(400), switchMap(() => { this.checking.set(true); return this.api.checkAd(this.ad); }))
      .subscribe({ next: (c) => { this.check.set(c); this.checking.set(false); }, error: () => this.checking.set(false) }));
    this.subs.add(this.api.metaRules().subscribe({ next: (r) => { this.rules.set(r); this.changed(); }, error: (e) => this.error.set(apiErrorMessage(e)) }));

    if (this.plan) {
      this.load(this.plan);
      this.step.set(4);
    } else {
      this.ad.brand_id = this.brandId ?? this.brands[0]?.id ?? 0;
      if (this.contentId) this.prefill(this.contentId);
      else this.ad.url = this.brand()?.website_url ?? '';
    }
  }

  ngOnDestroy(): void {
    this.subs.unsubscribe();
  }

  changed(): void {
    this.version.update((v) => v + 1);
    this.checks$.next();
  }

  canGoTo(i: number): boolean {
    if (i === 4) return !!this.saved();
    return i >= 0 && (i <= 3) && !!this.ad.brand_id;
  }

  goTo(i: number): void {
    if (this.canGoTo(i)) this.step.set(i);
  }

  setObjective(key: AdObjective): void {
    this.ad.objective = key;
    const ctas = this.rules()?.objectives.find((o) => o.key === key)?.ctas ?? [];
    if (!ctas.includes(this.ad.cta)) this.ad.cta = ctas[0] ?? 'LEARN_MORE';
    this.changed();
  }

  setInterests(text: string): void {
    this.ad.audience.interests = text.split(',').map((s) => s.trim()).filter(Boolean);
    this.changed();
  }

  severityIcon(severity: string): string {
    return SEVERITY[severity as RuleSeverity]?.icon ?? 'ti-info-circle';
  }

  categoryLabel(key = this.ad.special_category): string {
    return this.rules()?.special_categories.find((c) => c.key === key)?.label ?? key;
  }

  ctaLabel(code: string): string {
    return this.rules()?.ctas.find((c) => c.code === code)?.label ?? code;
  }

  save(): void {
    this.busy.set(true);
    this.error.set(null);
    const p = this.saved();
    const request = p ? this.api.updateAdPlan(p.id, this.ad) : this.api.createAdPlan(this.ad);
    request.subscribe({
      next: (plan) => { this.load(plan); this.step.set(4); this.busy.set(false); this.changedPlan.emit(plan); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  review(): void {
    const p = this.saved();
    if (!p) return;
    this.busy.set(true);
    this.api.reviewAdPlan(p.id).subscribe({
      next: (plan) => { this.saved.set(plan); this.busy.set(false); this.pollReview(plan.id); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  useImproved(): void {
    const imp = this.saved()?.review?.improved;
    if (!imp) return;
    this.ad.primary_text = imp.primary_text;
    this.ad.headline = imp.headline;
    this.ad.description = imp.description;
    this.save();
  }

  setStatus(status: 'live' | 'ended'): void {
    const p = this.saved();
    if (!p) return;
    this.busy.set(true);
    this.api.updateAdPlan(p.id, { status }).subscribe({
      next: (plan) => { this.saved.set(plan); this.busy.set(false); this.changedPlan.emit(plan); },
      error: (e) => { this.error.set(apiErrorMessage(e)); this.busy.set(false); },
    });
  }

  copy(value: string, label: string): void {
    navigator.clipboard?.writeText(value).then(() => {
      this.copied.set(label);
      setTimeout(() => this.copied() === label && this.copied.set(null), 1500);
    });
  }

  private pollReview(id: number): void {
    this.subs.add(timer(2000, 2000).pipe(
      switchMap(() => this.api.adPlan(id)),
      takeWhile((p) => p.review_status === 'queued' || p.review_status === 'running', true),
    ).subscribe((plan) => { this.saved.set(plan); this.changedPlan.emit(plan); }));
  }

  private load(plan: AdPlan): void {
    this.saved.set(plan);
    this.ad = {
      brand_id: plan.brand_profile_id, content_id: plan.content_item_id, name: plan.name, objective: plan.objective, special_category: plan.special_category,
      audience: { ...plan.audience, interests: [...plan.audience.interests] }, daily_budget: plan.daily_budget, start_date: plan.start_date, end_date: plan.end_date,
      primary_text: plan.primary_text, headline: plan.headline, description: plan.description, cta: plan.cta, url: plan.url ?? '',
    };
    this.interests = plan.audience.interests.join(', ');
    this.endDate = plan.end_date ?? '';
    if (plan.content_item_id) this.api.contentItem(plan.content_item_id).subscribe({ next: (c) => this.content.set(c), error: () => {} });
    this.changed();
  }

  /** Dal post: testo senza note per il revisore né hashtag, titolo della grafica e link del sito. */
  private prefill(contentId: number): void {
    this.ad.content_id = contentId;
    this.api.contentItem(contentId).pipe(
      switchMap((c) => this.api.project(c.project_id).pipe(map((p) => ({ c, brandId: p.brand_profile_id })))),
    ).subscribe({
      next: ({ c, brandId }) => {
        this.content.set(c);
        if (brandId) this.ad.brand_id = brandId;
        const text = publishableText(c.body).replace(/(\n\s*)?(#[\p{L}\d_]+\s*)+$/u, '').replace(/👉\s*/u, '').trim();
        this.ad.primary_text = text;
        this.ad.headline = (c.visual?.headline || c.title).slice(0, 40);
        this.ad.url = this.brands.find((b) => b.id === this.ad.brand_id)?.website_url ?? '';
        this.changed();
      },
      error: (e) => this.error.set(apiErrorMessage(e)),
    });
  }

  /** Aggiunge i parametri UTM, così le visite dalla sponsorizzata si riconoscono in Analytics. */
  private utm(url: string, p: AdPlan): string {
    try {
      const u = new URL(url);
      const slug = `${p.brand_name}-${p.name}`.toLowerCase().normalize('NFD').replace(/[^\x00-\x7f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      if (!u.searchParams.has('utm_source')) u.searchParams.set('utm_source', 'meta');
      if (!u.searchParams.has('utm_medium')) u.searchParams.set('utm_medium', 'paid_social');
      if (!u.searchParams.has('utm_campaign')) u.searchParams.set('utm_campaign', slug.slice(0, 60));
      return u.toString();
    } catch {
      return url;
    }
  }
}

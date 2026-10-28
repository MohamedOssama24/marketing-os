import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import {
  AdImportResult, AdPlatform, AdSpend, AdSpendInput, AdSummary, Brand, INVOICE_STATUS_LABELS, PLATFORM_LABELS, Project, apiErrorMessage,
} from '../../core/models';

const MONTH_INITIALS = ['G', 'F', 'M', 'A', 'M', 'G', 'L', 'A', 'S', 'O', 'N', 'D'];
const MONTH_NAMES = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

type Draft = Omit<AdSpendInput, 'spent'> & { id: number | null; spent: number | null; project_id: number | null };

/** Sponsorizzate: quanto si spende per ogni cliente, con clic e risultati, inserite a mano o importate da Meta. */
@Component({
  selector: 'app-ads',
  standalone: true,
  imports: [CurrencyPipe, DatePipe, DecimalPipe, FormsModule],
  template: `
    <div class="page-head">
      <div>
        <h1>Sponsorizzate</h1>
        <p class="muted">Budget speso, clic e risultati delle campagne a pagamento dei tuoi clienti.</p>
      </div>
      <div class="head-controls">
        <select [(ngModel)]="brandId" (ngModelChange)="load()" aria-label="Filtra per cliente">
          <option [ngValue]="null">Tutti i clienti</option>
          @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
        </select>
        <div class="month-nav year-nav">
          <button class="icon-btn" (click)="shiftYear(-1)" aria-label="Anno precedente"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
          <strong>{{ year() }}</strong>
          <button class="icon-btn" (click)="shiftYear(1)" aria-label="Anno successivo"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
        </div>
        @if (auth.canEdit()) {
          <button class="btn ghost" (click)="openImport()" [disabled]="!brands().length"><i class="ti ti-file-import" aria-hidden="true"></i> Importa da Meta</button>
          <button class="btn primary" (click)="openForm()" [disabled]="!brands().length"><i class="ti ti-plus" aria-hidden="true"></i> Aggiungi spesa</button>
        }
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (summary(); as s) {
      <div class="metrics ad-metrics">
        <div class="metric"><span class="metric-icon"><i class="ti ti-coin-euro" aria-hidden="true"></i></span>
          <span class="metric-label">Speso nel {{ s.year }}@if (s.totals.budget) { · budget {{ s.totals.budget | currency: 'EUR' : 'symbol' : '1.0-0' }}}</span>
          <strong>{{ s.totals.spent | currency: 'EUR' }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-click" aria-hidden="true"></i></span>
          <span class="metric-label">Clic sul link@if (s.totals.ctr !== null) { · CTR {{ s.totals.ctr | number: '1.2-2' }}%}</span>
          <strong>{{ s.totals.clicks | number }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-eye" aria-hidden="true"></i></span>
          <span class="metric-label">Impression · copertura {{ s.totals.reach | number }}</span>
          <strong>{{ s.totals.impressions | number }}</strong></div>
        <div class="metric"><span class="metric-icon"><i class="ti ti-target-arrow" aria-hidden="true"></i></span>
          <span class="metric-label">Costo per clic · per risultato {{ s.totals.cost_per_result === null ? '—' : (s.totals.cost_per_result | currency: 'EUR') }}</span>
          <strong>{{ s.totals.cpc === null ? '—' : (s.totals.cpc | currency: 'EUR') }}</strong></div>
      </div>

      <div class="two-col ad-charts">
        <section class="card">
          <h2>Spesa per mese</h2>
          <p class="muted small">Ogni spesa conta nel mese in cui finisce il periodo.</p>
          @if (s.totals.spent > 0) {
            <div class="bar-chart" role="img" [attr.aria-label]="'Spesa per mese nel ' + s.year">
              @for (m of s.months; track m.month) {
                <div class="bar-col" tabindex="0">
                  <div class="bar-track">
                    @if (m.month === peakMonth()) { <span class="bar-value">{{ m.spent | currency: 'EUR' : 'symbol' : '1.0-0' }}</span> }
                    <div class="bar" [style.height.%]="barHeight(m.spent)"></div>
                  </div>
                  <span class="bar-label">{{ initials[m.month - 1] }}</span>
                  <div class="bar-tip"><strong>{{ monthNames[m.month - 1] }}</strong>{{ m.spent | currency: 'EUR' }}<span>{{ m.clicks | number }} clic</span></div>
                </div>
              }
            </div>
          } @else {
            <p class="empty-chart muted">Nessuna spesa registrata nel {{ s.year }}.</p>
          }
        </section>

        <section class="card">
          <h2>Per cliente</h2>
          <p class="muted small">Speso e costo per clic nell'anno.</p>
          <div class="hbar-list">
            @for (c of s.clients; track c.id) {
              <button class="hbar" (click)="filterClient(c.id)" [title]="'Mostra solo ' + c.name">
                <span class="hbar-head"><span class="swatch small" [style.background]="c.primary_color ?? '#5B4BDB'"></span><strong>{{ c.name }}</strong>
                  <span class="hbar-value">{{ c.spent | currency: 'EUR' }}</span></span>
                <span class="hbar-track"><span class="hbar-fill" [style.width.%]="(c.spent / maxClient()) * 100"></span></span>
                <small class="muted">{{ c.clicks | number }} clic · CPC {{ c.cpc === null ? '—' : (c.cpc | currency: 'EUR') }} · {{ c.entries }} {{ c.entries === 1 ? 'riga' : 'righe' }}</small>
              </button>
            } @empty {
              <p class="empty-chart muted">I clienti compariranno qui con la prima spesa.</p>
            }
          </div>
        </section>
      </div>
    }

    <div class="section-head"><h2>Registro</h2></div>
    <div class="card table-card">
      <table class="ad-table">
        <thead><tr><th>Cliente e campagna</th><th>Periodo</th><th class="num">Speso</th><th class="num">Clic</th><th class="num">Impression</th><th>Fattura</th><th></th></tr></thead>
        <tbody>
          @for (r of rows(); track r.id) {
            <tr>
              <td><span class="swatch small" [style.background]="r.brand_color ?? '#5B4BDB'"></span> <strong>{{ r.campaign_name }}</strong><br />
                <small class="muted">{{ r.brand_name }} · {{ platformLabels[r.platform] }}@if (r.source === 'csv') { · da CSV }</small></td>
              <td class="muted">{{ r.period_start | date: 'd MMM' }} – {{ r.period_end | date: 'd MMM yyyy' }}</td>
              <td class="num"><strong>{{ r.spent | currency: 'EUR' }}</strong>@if (r.budget) {<br /><small class="muted">su {{ r.budget | currency: 'EUR' }}</small>}</td>
              <td class="num">{{ r.clicks === null ? '—' : (r.clicks | number) }}</td>
              <td class="num">{{ r.impressions === null ? '—' : (r.impressions | number) }}</td>
              <td>
                @if (r.invoice_status) {
                  <span class="badge" [attr.data-status]="statusBadge[r.invoice_status]">{{ statusLabels[r.invoice_status] }}</span>
                } @else { <span class="badge">Da fatturare</span> }
              </td>
              <td class="row-actions">
                @if (auth.canEdit() && r.invoice_status !== 'invoiced' && r.invoice_status !== 'paid') {
                  <button class="icon-btn" (click)="openForm(r)" aria-label="Modifica" title="Modifica"><i class="ti ti-pencil" aria-hidden="true"></i></button>
                  <button class="icon-btn" (click)="remove(r)" aria-label="Elimina" title="Elimina"><i class="ti ti-trash" aria-hidden="true"></i></button>
                }
              </td>
            </tr>
          } @empty {
            <tr><td colspan="7" class="muted center">{{ loading() ? 'Caricamento…' : 'Nessuna spesa nel ' + year() + '. Importa il report di Meta o aggiungila a mano.' }}</td></tr>
          }
        </tbody>
      </table>
    </div>

    @if (draft(); as d) {
      <div class="modal-backdrop" (click)="draft.set(null)">
        <form class="modal narrow" (click)="$event.stopPropagation()" (ngSubmit)="save(d)">
          <div class="modal-head"><h2>{{ d.id ? 'Modifica spesa' : 'Nuova spesa' }}</h2><span class="muted"></span>
            <button type="button" class="icon-btn" (click)="draft.set(null)" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button></div>
          <div class="modal-body form-grid">
            <label>Cliente
              <select [(ngModel)]="d.brand_profile_id" name="brand" (ngModelChange)="d.project_id = null" required>
                @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
              </select></label>
            <label>Piattaforma
              <select [(ngModel)]="d.platform" name="platform">
                @for (p of platforms; track p) { <option [value]="p">{{ platformLabels[p] }}</option> }
              </select></label>
            <label class="full">Nome della campagna<input [(ngModel)]="d.campaign_name" name="campaign" maxlength="190" required placeholder="Promo autunno" /></label>
            <label>Dal<input type="date" [(ngModel)]="d.period_start" name="start" required /></label>
            <label>Al<input type="date" [(ngModel)]="d.period_end" name="end" required /></label>
            <label>Speso (€)<input type="number" min="0" step="0.01" [(ngModel)]="d.spent" name="spent" required /></label>
            <label>Budget (€, facoltativo)<input type="number" min="0" step="0.01" [(ngModel)]="d.budget" name="budget" /></label>
            <label>Clic sul link<input type="number" min="0" [(ngModel)]="d.clicks" name="clicks" /></label>
            <label>Impression<input type="number" min="0" [(ngModel)]="d.impressions" name="impressions" /></label>
            <label>Copertura<input type="number" min="0" [(ngModel)]="d.reach" name="reach" /></label>
            <label>Risultati<input type="number" min="0" [(ngModel)]="d.results" name="results" /></label>
            <label class="full">Campagna di Marketing OS (facoltativa)
              <select [(ngModel)]="d.project_id" name="project">
                <option [ngValue]="null">Nessuna</option>
                @for (p of projectsOf(d.brand_profile_id); track p.id) { <option [ngValue]="p.id">{{ p.name }}</option> }
              </select></label>
            <label class="full">Note<input [(ngModel)]="d.notes" name="notes" maxlength="500" /></label>
          </div>
          @if (formError()) { <div class="alert modal-alert">{{ formError() }}</div> }
          <div class="modal-foot">
            <span class="grow"></span>
            <button type="button" class="btn ghost" (click)="draft.set(null)">Annulla</button>
            <button class="btn primary" [disabled]="busy()">Salva</button>
          </div>
        </form>
      </div>
    }

    @if (importing()) {
      <div class="modal-backdrop" (click)="closeImport()">
        <div class="modal narrow" (click)="$event.stopPropagation()">
          <div class="modal-head"><h2><i class="ti ti-file-import" aria-hidden="true"></i> Importa il report di Meta</h2><span class="muted"></span>
            <button class="icon-btn" (click)="closeImport()" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button></div>
          <div class="modal-body">
            <ol class="howto">
              <li>Apri <strong>Gestione inserzioni</strong> di Meta e scegli il periodo (ad esempio il mese scorso).</li>
              <li>Clicca <strong>Report → Esporta dati della tabella</strong> e scegli il formato <strong>.csv</strong>.</li>
              <li>Carica qui il file: servono le colonne <em>Nome della campagna</em>, <em>Inizio dei report</em> e <em>Importo speso</em>. Clic, impression e risultati vengono letti se presenti.</li>
            </ol>
            <div class="form-grid">
              <label>Cliente
                <select [(ngModel)]="importBrand" (ngModelChange)="importResult.set(null); importProject = null">
                  @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
                </select></label>
              <label>Piattaforma
                <select [(ngModel)]="importPlatform" (ngModelChange)="importResult.set(null)">
                  @for (p of platforms; track p) { <option [value]="p">{{ platformLabels[p] }}</option> }
                </select></label>
              <label class="full">Collega a una campagna (facoltativo)
                <select [(ngModel)]="importProject">
                  <option [ngValue]="null">Nessuna</option>
                  @for (p of projectsOf(importBrand); track p.id) { <option [ngValue]="p.id">{{ p.name }}</option> }
                </select></label>
              <label class="full">File CSV<input type="file" accept=".csv,text/csv" (change)="pickFile($event)" /></label>
            </div>
            @if (importResult(); as r) {
              <div class="import-summary" [class.done]="!r.preview">
                @if (r.preview) {
                  <strong>{{ r.rows.length }} righe trovate · {{ r.total_spent | currency: 'EUR' }}</strong>
                  <span>{{ r.counts.new }} nuove, {{ r.counts.update }} da aggiornare@if (r.counts.locked) {, {{ r.counts.locked }} già fatturate (restano come sono)}@if (r.skipped) {, {{ r.skipped }} ignorate (totali o senza spesa)}.</span>
                } @else {
                  <strong><i class="ti ti-check" aria-hidden="true"></i> Importazione completata</strong>
                  <span>{{ r.counts.new }} nuove righe, {{ r.counts.update }} aggiornate.</span>
                }
              </div>
              @if (r.errors.length) {
                <ul class="import-errors">@for (e of r.errors; track e.line) { <li>Riga {{ e.line }}: {{ e.message }}</li> }</ul>
              }
              @if (r.preview) {
                <div class="table-card preview-table">
                  <table>
                    <thead><tr><th>Campagna</th><th>Periodo</th><th class="num">Speso</th><th class="num">Clic</th><th></th></tr></thead>
                    <tbody>
                      @for (row of r.rows; track $index) {
                        <tr><td>{{ row.campaign_name }}</td><td class="muted">{{ row.period_start | date: 'd/M' }} – {{ row.period_end | date: 'd/M/yy' }}</td>
                          <td class="num">{{ row.spent | currency: 'EUR' }}</td><td class="num">{{ row.clicks ?? '—' }}</td>
                          <td><span class="tag">{{ actionLabels[row.action] }}</span></td></tr>
                      }
                    </tbody>
                  </table>
                </div>
              }
            }
          </div>
          @if (formError()) { <div class="alert modal-alert">{{ formError() }}</div> }
          <div class="modal-foot">
            <span class="grow"></span>
            @if (importResult()?.preview) {
              <button class="btn primary" (click)="runImport(false)" [disabled]="busy() || !importResult()!.rows.length">
                <i class="ti ti-check" aria-hidden="true"></i> Importa {{ importResult()!.counts.new + importResult()!.counts.update }} righe</button>
            } @else if (importResult()) {
              <button class="btn primary" (click)="closeImport()">Fatto</button>
            } @else {
              <button class="btn ghost" (click)="closeImport()">Annulla</button>
              <button class="btn primary" (click)="runImport(true)" [disabled]="busy() || !importFile || !importBrand">Controlla il file</button>
            }
          </div>
        </div>
      </div>
    }
  `,
})
export class AdsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  readonly platformLabels = PLATFORM_LABELS;
  readonly platforms = Object.keys(PLATFORM_LABELS) as AdPlatform[];
  readonly statusLabels = INVOICE_STATUS_LABELS;
  readonly statusBadge = { to_invoice: 'in_review', invoiced: 'scheduled', paid: 'approved' } as const;
  readonly actionLabels = { new: 'Nuova', update: 'Aggiorna', locked: 'Già fatturata' };
  readonly initials = MONTH_INITIALS;
  readonly monthNames = MONTH_NAMES;

  brands = signal<Brand[]>([]);
  projects = signal<Project[]>([]);
  rows = signal<AdSpend[]>([]);
  summary = signal<AdSummary | null>(null);
  year = signal(new Date().getFullYear());
  loading = signal(true);
  busy = signal(false);
  error = signal<string | null>(null);
  formError = signal<string | null>(null);
  draft = signal<Draft | null>(null);
  importing = signal(false);
  importResult = signal<AdImportResult | null>(null);
  brandId: number | null = null;
  importBrand: number | null = null;
  importProject: number | null = null;
  importPlatform: AdPlatform = 'meta';
  importFile: File | null = null;

  /** Scala del grafico: il mese più alto occupa tutta l'altezza. */
  readonly maxMonth = computed(() => Math.max(0, ...(this.summary()?.months.map((m) => m.spent) ?? [])));
  readonly peakMonth = computed(() => this.summary()?.months.find((m) => m.spent === this.maxMonth() && m.spent > 0)?.month ?? null);
  readonly maxClient = computed(() => Math.max(1, ...(this.summary()?.clients.map((c) => c.spent) ?? [])));

  ngOnInit(): void {
    forkJoin([this.api.brands(), this.api.projects()]).subscribe({
      next: ([brands, projects]) => {
        this.brands.set(brands);
        this.projects.set(projects);
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
    this.load();
  }

  load(): void {
    this.loading.set(true);
    forkJoin([this.api.adSpend(this.year(), this.brandId), this.api.adSummary(this.year(), this.brandId)]).subscribe({
      next: ([rows, summary]) => {
        this.rows.set(rows);
        this.summary.set(summary);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  shiftYear(delta: number): void {
    this.year.update((y) => y + delta);
    this.load();
  }

  filterClient(id: number): void {
    this.brandId = this.brandId === id ? null : id;
    this.load();
  }

  barHeight(spent: number): number {
    const max = this.maxMonth();
    return max > 0 && spent > 0 ? Math.max(2, (spent / max) * 100) : 0;
  }

  projectsOf(brandId: number | null): Project[] {
    return this.projects().filter((p) => p.brand_profile_id === brandId);
  }

  openForm(row?: AdSpend): void {
    this.formError.set(null);
    if (row) {
      this.draft.set({ ...row });
      return;
    }
    const today = new Date();
    const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const last = new Date(today.getFullYear(), today.getMonth(), 0);
    this.draft.set({
      id: null, brand_profile_id: this.brandId ?? this.brands()[0]?.id, project_id: null, platform: 'meta', campaign_name: '',
      period_start: isoDate(first), period_end: isoDate(last), spent: null, budget: null, impressions: null, reach: null, clicks: null, results: null, notes: null,
    });
  }

  save(d: Draft): void {
    this.busy.set(true);
    this.formError.set(null);
    const { id, ...body } = d;
    const request = id ? this.api.updateAdSpend(id, body as AdSpendInput) : this.api.createAdSpend(body as AdSpendInput);
    request.subscribe({
      next: () => {
        this.busy.set(false);
        this.draft.set(null);
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.formError.set(apiErrorMessage(err));
      },
    });
  }

  remove(row: AdSpend): void {
    if (!confirm(`Eliminare la spesa "${row.campaign_name}"?`)) return;
    this.api.deleteAdSpend(row.id).subscribe({ next: () => this.load(), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  openImport(): void {
    this.importBrand = this.brandId ?? this.brands()[0]?.id ?? null;
    this.importProject = null;
    this.importFile = null;
    this.importResult.set(null);
    this.formError.set(null);
    this.importing.set(true);
  }

  closeImport(): void {
    this.importing.set(false);
    if (this.importResult() && !this.importResult()!.preview) this.load();
  }

  pickFile(event: Event): void {
    this.importFile = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.importResult.set(null);
    this.formError.set(null);
  }

  runImport(preview: boolean): void {
    if (!this.importFile || !this.importBrand) return;
    this.busy.set(true);
    this.formError.set(null);
    this.api.importAdSpend(this.importBrand, this.importFile, this.importPlatform, preview, this.importProject).subscribe({
      next: (result) => {
        this.busy.set(false);
        this.importResult.set(result);
      },
      error: (err) => {
        this.busy.set(false);
        this.formError.set(apiErrorMessage(err));
      },
    });
  }
}

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

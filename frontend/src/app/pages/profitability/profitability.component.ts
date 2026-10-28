import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { CurrencyPipe, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Brand, Profitability, TimeEntry, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';

const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const TIMER_KEY = 'time-timer';

/** Ore lavorate per cliente e guadagno reale: chi rende e chi costa più di quanto paga. */
@Component({
  selector: 'app-profitability',
  standalone: true,
  imports: [AssetPreviewComponent, CurrencyPipe, DatePipe, DecimalPipe, FormsModule, RouterLink],
  template: `
    <div class="page-head">
      <div>
        <h1>Ore e redditività</h1>
        <p class="muted">Segna il tempo che dedichi a ogni cliente: scopri quanto guadagni davvero all'ora con ciascuno.</p>
      </div>
      <div class="head-controls">
        <select [(ngModel)]="period" (ngModelChange)="load()" aria-label="Mese">
          @for (m of months; track m.value) { <option [ngValue]="m.value">{{ m.label }}</option> }
        </select>
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (auth.canEdit()) {
      <section class="card time-form">
        <div class="timer" [class.running]="timerStart()">
          <button class="btn" [class.primary]="!timerStart()" [class.danger]="!!timerStart()" (click)="toggleTimer()" [disabled]="!brandId && !timerStart()">
            <i class="ti" [class]="timerStart() ? 'ti ti-player-stop' : 'ti ti-player-play'" aria-hidden="true"></i> {{ timerStart() ? 'Ferma' : 'Avvia timer' }}
          </button>
          <span class="timer-clock">{{ elapsedLabel() }}</span>
        </div>
        <label>Cliente
          <select [(ngModel)]="brandId" [disabled]="!!timerStart()">
            <option [ngValue]="null">— Scegli —</option>
            @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
          </select>
        </label>
        <label>Ore<input type="number" min="0" max="24" step="0.25" [(ngModel)]="hours" placeholder="1,5" /></label>
        <label>Giorno<input type="date" [(ngModel)]="day" /></label>
        <label class="grow">Attività<input [(ngModel)]="note" maxlength="300" placeholder="Es. Calendario di novembre, call con il cliente" /></label>
        <button class="btn primary" (click)="addEntry()" [disabled]="!brandId || !hours || busy()"><i class="ti ti-plus" aria-hidden="true"></i> Segna</button>
      </section>
    }

    @if (summary(); as s) {
      @if (auth.canManage()) {
        <div class="metrics">
          <div class="metric"><span class="metric-icon"><i class="ti ti-clock" aria-hidden="true"></i></span><strong>{{ hoursLabel(s.totals.minutes) }}</strong><span class="metric-label">Ore lavorate</span></div>
          <div class="metric"><span class="metric-icon"><i class="ti ti-coin-euro" aria-hidden="true"></i></span><strong>{{ s.totals.revenue | currency: 'EUR' : 'symbol' : '1.0-0' }}</strong><span class="metric-label">Ricavi dai clienti</span></div>
          <div class="metric"><span class="metric-icon"><i class="ti ti-receipt" aria-hidden="true"></i></span><strong>{{ s.totals.cost === null ? '—' : (s.totals.cost | currency: 'EUR' : 'symbol' : '1.0-0') }}</strong><span class="metric-label">Costo del tempo</span></div>
          <div class="metric"><span class="metric-icon"><i class="ti ti-trending-up" aria-hidden="true"></i></span><strong>{{ s.totals.effective_rate === null ? '—' : (s.totals.effective_rate | currency: 'EUR' : 'symbol' : '1.0-0') + '/h' }}</strong><span class="metric-label">Guadagno orario medio</span></div>
        </div>

        <section class="card profit-card">
          <div class="strategy-head">
            <h2><i class="ti ti-chart-bar" aria-hidden="true"></i> Clienti, dal meno al più redditizio</h2>
            <label class="hourly">Costo orario agenzia
              <span class="input-suffix"><input type="number" min="0" step="1" [(ngModel)]="hourlyCost" (change)="saveCost()" placeholder="35" /><span>€/h</span></span>
            </label>
          </div>
          @if (s.hourly_cost === null) {
            <p class="hint"><i class="ti ti-info-circle" aria-hidden="true"></i> Indica il costo orario dell'agenzia (stipendi, collaboratori e spese diviso le ore lavorate) per vedere costo e margine di ogni cliente.</p>
          }
          <div class="table-wrap">
            <table class="profit-table">
              <thead><tr><th>Cliente</th><th>Ore</th><th>Ricavo</th><th>Costo</th><th>Margine</th><th>€ all'ora</th></tr></thead>
              <tbody>
                @for (c of s.clients; track c.id) {
                  <tr>
                    <td>
                      <a class="profit-client" [routerLink]="['/clients', c.id]">
                        <span class="client-logo small-logo" [style.background]="c.logo_asset_id ? '#fff' : c.primary_color || 'var(--primary)'">
                          @if (c.logo_asset_id) { <app-asset-preview [assetId]="c.logo_asset_id" fit="contain" [alt]="c.name" /> } @else { {{ c.name.charAt(0) }} }
                        </span>
                        <span class="ellipsis">{{ c.name }}</span>
                      </a>
                    </td>
                    <td>{{ hoursLabel(c.minutes) }}</td>
                    <td>{{ c.revenue | currency: 'EUR' : 'symbol' : '1.0-0' }}@if (c.revenue_estimated) { <small class="muted" title="Nessuna fattura del mese: stimato da canone e commissione"> stimato</small> }</td>
                    <td>{{ c.cost === null ? '—' : (c.cost | currency: 'EUR' : 'symbol' : '1.0-0') }}</td>
                    <td>
                      @if (c.margin_percent !== null) {
                        <span class="margin" [class]="'margin ' + marginClass(c.margin_percent)">
                          <span class="margin-bar"><span [style.width.%]="barWidth(c.margin_percent)"></span></span>
                          {{ c.margin_percent | number: '1.0-0' }}%
                        </span>
                      } @else { — }
                    </td>
                    <td><strong>{{ c.effective_rate === null ? '—' : (c.effective_rate | currency: 'EUR' : 'symbol' : '1.0-0') }}</strong></td>
                  </tr>
                } @empty {
                  <tr><td colspan="6" class="muted center">Nessun dato per questo mese: imposta il canone dei clienti in Fatturazione e segna le ore.</td></tr>
                }
              </tbody>
            </table>
          </div>
          @if (worst(); as w) {
            <p class="profit-insight"><i class="ti ti-alert-triangle" aria-hidden="true"></i> <strong>{{ w.name }}</strong> ti costa più di quanto paga: rivedi il canone o il tempo che gli dedichi.</p>
          }
        </section>
      }
    }

    <section class="card">
      <h2>Ore segnate a {{ monthLabel() }}</h2>
      @for (e of entries(); track e.id) {
        <div class="time-row">
          <span class="time-day">{{ e.worked_on | date: 'EEE d' }}</span>
          <strong class="time-hours">{{ hoursLabel(e.minutes) }}</strong>
          <span class="grow ellipsis"><strong>{{ e.brand_name }}</strong>@if (e.note) { · {{ e.note }} }</span>
          <small class="muted">{{ e.user_name }}</small>
          @if (canDelete(e)) {
            <button class="icon-btn danger" (click)="removeEntry(e)" aria-label="Elimina" title="Elimina"><i class="ti ti-trash" aria-hidden="true"></i></button>
          }
        </div>
      } @empty {
        <p class="muted small">Nessuna ora segnata in questo mese.</p>
      }
    </section>
  `,
})
export class ProfitabilityComponent implements OnInit, OnDestroy {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  readonly months = lastMonths(13);
  period = this.months[0].value;
  brands = signal<Brand[]>([]);
  summary = signal<Profitability | null>(null);
  entries = signal<TimeEntry[]>([]);
  busy = signal(false);
  error = signal<string | null>(null);
  timerStart = signal<number | null>(null);
  now = signal(Date.now());
  brandId: number | null = null;
  hours: number | null = null;
  day = today();
  note = '';
  hourlyCost: number | null = null;
  private tick?: ReturnType<typeof setInterval>;

  readonly worst = computed(() => this.summary()?.clients.find((c) => c.margin !== null && c.margin < 0) ?? null);
  readonly elapsedLabel = computed(() => {
    const start = this.timerStart();
    if (!start) return '0:00:00';
    const s = Math.floor((this.now() - start) / 1000);
    return `${Math.floor(s / 3600)}:${String(Math.floor(s / 60) % 60).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  });

  ngOnInit(): void {
    this.api.brands().subscribe((b) => this.brands.set(b));
    // Il timer sopravvive al cambio di pagina e alla chiusura della scheda.
    try {
      const saved = JSON.parse(localStorage.getItem(TIMER_KEY) ?? 'null');
      if (saved?.start) {
        this.timerStart.set(saved.start);
        this.brandId = saved.brandId;
        this.startTick();
      }
    } catch { /* archiviazione non disponibile */ }
    this.load();
  }

  ngOnDestroy(): void {
    clearInterval(this.tick);
  }

  load(): void {
    this.error.set(null);
    this.api.timeEntries(this.period).subscribe({ next: (e) => this.entries.set(e), error: (err) => this.error.set(apiErrorMessage(err)) });
    if (this.auth.canManage()) {
      this.api.profitability(this.period).subscribe({
        next: (s) => {
          this.summary.set(s);
          this.hourlyCost = s.hourly_cost;
        },
        error: (err) => this.error.set(apiErrorMessage(err)),
      });
    }
  }

  addEntry(minutes = Math.round((this.hours ?? 0) * 60)): void {
    if (!this.brandId || minutes < 1) return;
    this.busy.set(true);
    this.api.addTimeEntry({ brand_id: this.brandId, minutes, worked_on: this.day, note: this.note.trim() || null }).subscribe({
      next: () => {
        this.busy.set(false);
        this.hours = null;
        this.note = '';
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  toggleTimer(): void {
    const start = this.timerStart();
    if (!start) {
      this.timerStart.set(Date.now());
      this.save({ start: Date.now(), brandId: this.brandId });
      this.startTick();
      return;
    }
    const minutes = Math.max(1, Math.round((Date.now() - start) / 60000));
    this.timerStart.set(null);
    clearInterval(this.tick);
    this.save(null);
    this.day = today();
    this.addEntry(minutes);
  }

  saveCost(): void {
    const value = this.hourlyCost === null || (this.hourlyCost as unknown) === '' ? null : Number(this.hourlyCost);
    this.api.updateHourlyCost(this.period, value).subscribe({ next: (s) => this.summary.set(s), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  removeEntry(e: TimeEntry): void {
    this.api.deleteTimeEntry(e.id).subscribe({ next: () => this.load(), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  canDelete(e: TimeEntry): boolean {
    return this.auth.canManage() || (this.auth.canEdit() && e.user_id === this.auth.user()?.id);
  }

  hoursLabel(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h}h ${String(m).padStart(2, '0')}m` : `${h}h`;
  }

  marginClass(percent: number): string {
    return percent < 0 ? 'bad' : percent < 30 ? 'low' : 'good';
  }

  barWidth(percent: number): number {
    return Math.min(100, Math.abs(percent));
  }

  monthLabel(): string {
    return this.months.find((m) => m.value === this.period)?.label.toLowerCase() ?? this.period;
  }

  private startTick(): void {
    clearInterval(this.tick);
    this.tick = setInterval(() => this.now.set(Date.now()), 1000);
  }

  private save(value: unknown): void {
    try {
      if (value) localStorage.setItem(TIMER_KEY, JSON.stringify(value));
      else localStorage.removeItem(TIMER_KEY);
    } catch { /* archiviazione non disponibile */ }
  }
}

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function lastMonths(count: number): { value: string; label: string }[] {
  const now = new Date();
  return Array.from({ length: count }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const label = `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
    return { value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, label: label.charAt(0).toUpperCase() + label.slice(1) };
  });
}

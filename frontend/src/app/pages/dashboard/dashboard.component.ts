import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Alert, CHANNEL_LABELS, CalendarItem, Dashboard, Occasion, apiErrorMessage } from '../../core/models';
import { SiteAnalyzerComponent } from '../../shared/site-analyzer.component';
import { DEFAULT_PRIMARY, mix, readableOnLight } from '../../shared/color';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [RouterLink, DatePipe, SiteAnalyzerComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>{{ greeting() }}, {{ firstName() }}</h1>
        @if (data(); as d) {
          <p class="muted">
            @if (d.pending_approval > 0) {
              {{ d.pending_approval }} {{ d.pending_approval === 1 ? 'contenuto aspetta' : 'contenuti aspettano' }} l'approvazione.
              <a routerLink="/approvals">Vai alle approvazioni</a>
            } @else { Nessun contenuto in attesa di approvazione. }
          </p>
        }
      </div>
    </div>

    <app-site-analyzer />

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (data(); as d) {
      <div class="metrics">
        <a class="metric" routerLink="/clients"><span class="metric-icon"><i class="ti ti-building-store" aria-hidden="true"></i></span><span class="metric-label">Clienti</span><strong>{{ d.clients }}</strong></a>
        <a class="metric" routerLink="/campaigns"><span class="metric-icon"><i class="ti ti-speakerphone" aria-hidden="true"></i></span><span class="metric-label">Campagne attive</span><strong>{{ d.active_campaigns }}</strong></a>
        <a class="metric" routerLink="/approvals"><span class="metric-icon"><i class="ti ti-checks" aria-hidden="true"></i></span><span class="metric-label">Da approvare</span><strong>{{ d.pending_approval }}</strong></a>
        <a class="metric" routerLink="/calendar"><span class="metric-icon"><i class="ti ti-send" aria-hidden="true"></i></span><span class="metric-label">Pubblicati nel mese</span><strong>{{ d.published_this_month }}<small class="muted"> / {{ d.scheduled_this_month }}</small></strong></a>
      </div>

      @if (alerts().length) {
        <div class="section-head">
          <h2><i class="ti ti-alert-circle" aria-hidden="true"></i> Da tenere d'occhio</h2>
          <span class="count-pill">{{ alerts().length }}</span>
        </div>
        <div class="card alert-list">
          @for (a of alerts().slice(0, 6); track a.key) {
            <a class="alert-row" [class]="'alert-row ' + a.severity" [routerLink]="a.link" [queryParams]="a.query">
              <span class="alert-icon"><i class="ti" [class]="'ti ' + a.icon" aria-hidden="true"></i></span>
              <span class="grow"><strong>{{ a.title }}</strong><small class="muted">{{ a.detail }}</small></span>
              <i class="ti ti-chevron-right muted" aria-hidden="true"></i>
            </a>
          }
        </div>
      }

      @if (d.to_publish.length) {
        <div class="section-head">
          <h2><i class="ti ti-bell-ringing" aria-hidden="true"></i> Da pubblicare</h2>
          <span class="count-pill">{{ d.to_publish.length }} pronti</span>
        </div>
        <div class="card due-list">
          @for (item of d.to_publish; track item.id) {
            <a class="due-row" [routerLink]="['/campaigns', item.project_id]">
              <span class="swatch" [style.background]="item.brand_color ?? '#5B4BDB'"></span>
              <span class="grow"><strong>{{ item.title }}</strong><small class="muted">{{ item.brand_name ?? item.project_name }} · {{ channelLabels[item.channel] }}</small></span>
              <span class="due-date" [class.late]="isLate(item)">{{ isLate(item) ? 'In ritardo · ' : 'Oggi · ' }}{{ item.scheduled_at | date: 'd MMM, HH:mm' }}</span>
              <i class="ti ti-chevron-right muted" aria-hidden="true"></i>
            </a>
          }
        </div>
      }

      <div class="section-head">
        <h2>Questa settimana</h2>
        <a routerLink="/calendar" class="small">Apri il calendario <i class="ti ti-arrow-right" aria-hidden="true"></i></a>
      </div>
      <div class="week">
        @for (day of days(); track day.date) {
          <div class="week-day" [class.today]="day.today">
            <p class="week-label">{{ day.date | date: 'EEE d' }}</p>
            @for (item of day.items; track item.id) {
              <a class="chip-post" [routerLink]="['/campaigns', item.project_id]" [style.background]="tint(item.brand_color)" [style.color]="ink(item.brand_color)" [title]="item.title">
                <strong>{{ item.brand_name ?? item.project_name }}</strong>
                <span>{{ channelLabels[item.channel] }} · {{ item.scheduled_at | date: 'HH:mm' }}</span>
              </a>
            } @empty {
              <span class="week-empty">—</span>
            }
          </div>
        }
      </div>

      @if (occasions().length) {
        <div class="section-head">
          <h2><i class="ti ti-confetti" aria-hidden="true"></i> Ricorrenze in arrivo</h2>
          <span class="muted small">Prepara i post con un paio di settimane di anticipo</span>
        </div>
        <div class="occasions">
          @for (o of occasions(); track o.date + o.name) {
            <div class="occasion">
              <span class="occasion-date"><strong>{{ o.date + 'T00:00:00' | date: 'd' }}</strong>{{ o.date + 'T00:00:00' | date: 'MMM' }}</span>
              <span class="grow"><strong>{{ o.name }}</strong><small class="muted">{{ o.idea }}</small><small class="occasion-when">{{ daysTo(o.date) }}</small></span>
            </div>
          }
        </div>
      }
    }
  `,
})
export class DashboardComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  readonly channelLabels = CHANNEL_LABELS;

  data = signal<Dashboard | null>(null);
  alerts = signal<Alert[]>([]);
  occasions = signal<Occasion[]>([]);
  error = signal<string | null>(null);

  firstName = computed(() => (this.auth.user()?.name ?? '').split(' ')[0]);
  greeting = computed(() => {
    const h = new Date().getHours();
    return h < 13 ? 'Buongiorno' : h < 18 ? 'Buon pomeriggio' : 'Buonasera';
  });

  days = computed(() => {
    const d = this.data();
    if (!d) return [];
    const start = new Date(d.week_start + 'T00:00:00');
    const todayKey = this.key(new Date());
    return Array.from({ length: 7 }, (_, i) => {
      const date = new Date(start);
      date.setDate(start.getDate() + i);
      const key = this.key(date);
      return { date, today: key === todayKey, items: d.week.filter((item: CalendarItem) => item.scheduled_at.startsWith(key)) };
    });
  });

  ngOnInit(): void {
    this.api.dashboard().subscribe({ next: (d) => this.data.set(d), error: (err) => this.error.set(apiErrorMessage(err)) });
    this.api.alerts().subscribe({ next: (list) => this.alerts.set(list) });
    const from = new Date();
    const to = new Date();
    to.setDate(to.getDate() + 45);
    this.api.occasions(this.key(from), this.key(to)).subscribe({ next: (list) => this.occasions.set(list.slice(0, 6)) });
  }

  daysTo(date: string): string {
    const days = Math.round((new Date(date + 'T00:00:00').getTime() - new Date(this.key(new Date()) + 'T00:00:00').getTime()) / 86400000);
    return days === 0 ? 'Oggi' : days === 1 ? 'Domani' : `Tra ${days} giorni`;
  }

  /** Programmato prima di oggi e non ancora pubblicato. */
  isLate(item: CalendarItem): boolean {
    return item.scheduled_at.slice(0, 10) < this.key(new Date());
  }

  tint(color: string | null): string {
    return mix(color ?? DEFAULT_PRIMARY, '#FFFFFF', 0.85);
  }

  ink(color: string | null): string {
    return readableOnLight(color ?? DEFAULT_PRIMARY);
  }

  private key(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }
}

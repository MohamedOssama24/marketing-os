import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { CHANNEL_LABELS, CONTENT_STATUS_LABELS, CalendarItem, Occasion, apiErrorMessage } from '../../core/models';
import { CHANNEL_ICONS } from '../../shared/post-card.component';
import { DEFAULT_PRIMARY, mix, readableOnLight } from '../../shared/color';

function dayKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

@Component({
  selector: 'app-calendar',
  standalone: true,
  imports: [DatePipe, RouterLink],
  template: `
    <div class="page-head">
      <div>
        <h1>Calendario</h1>
        <p class="muted">Tutte le pubblicazioni dei tuoi clienti. @if (auth.canEdit()) { Trascina un post su un altro giorno per spostarlo. }</p>
      </div>
      <div class="month-nav">
        <button class="icon-btn" (click)="shift(-1)" aria-label="Mese precedente"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
        <strong>{{ month() | date: 'MMMM yyyy' }}</strong>
        <button class="icon-btn" (click)="shift(1)" aria-label="Mese successivo"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
        <button class="btn ghost small" (click)="today()">Oggi</button>
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (legend().length) {
      <div class="legend">
        @for (b of legend(); track b.name) { <span><span class="swatch small" [style.background]="b.color"></span>{{ b.name }}</span> }
      </div>
    }

    <div class="calendar">
      @for (d of weekdays; track d) { <div class="cal-head">{{ d }}</div> }
      @for (day of grid(); track day.key) {
        <div class="cal-day" [class.other]="!day.inMonth" [class.today]="day.key === todayKey" [class.drop]="dropKey() === day.key"
             (dragover)="allowDrop($event, day.key)" (dragleave)="dropKey.set(null)" (drop)="drop($event, day.key)">
          <span class="cal-num">{{ day.date.getDate() }}</span>
          @for (o of day.occasions; track o.name) {
            <span class="chip-occasion" [title]="o.name + ' — ' + o.idea"><i class="ti ti-confetti" aria-hidden="true"></i> {{ o.name }}</span>
          }
          @for (item of day.items; track item.id) {
            <a class="chip-post" [routerLink]="['/campaigns', item.project_id]" [draggable]="auth.canEdit() && item.status !== 'published'"
               (dragstart)="dragStart($event, item)" [style.background]="tint(item.brand_color)" [style.color]="ink(item.brand_color)"
               [title]="item.title + ' — ' + statusLabels[item.status]">
              <span class="chip-line"><i class="ti" [class]="'ti ' + icons[item.channel]" aria-hidden="true"></i> {{ item.scheduled_at | date: 'HH:mm' }}
                <span class="status-dot" [attr.data-status]="item.status"></span></span>
              <strong>{{ item.brand_name ?? item.project_name }}</strong>
            </a>
          }
        </div>
      }
    </div>
  `,
})
export class CalendarComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  readonly weekdays = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
  readonly icons = CHANNEL_ICONS;
  readonly channelLabels = CHANNEL_LABELS;
  readonly statusLabels = CONTENT_STATUS_LABELS;
  readonly todayKey = dayKey(new Date());

  month = signal(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
  items = signal<CalendarItem[]>([]);
  /** Festività e ricorrenze utili per i contenuti. */
  occasions = signal<Occasion[]>([]);
  error = signal<string | null>(null);
  dropKey = signal<string | null>(null);
  private dragged: CalendarItem | null = null;

  /** 6 settimane a partire dal lunedì che precede il primo del mese. */
  private gridStart = computed(() => {
    const first = this.month();
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return start;
  });

  grid = computed(() => {
    const start = this.gridStart();
    const month = this.month().getMonth();
    return Array.from({ length: 42 }, (_, i) => {
      const date = new Date(start);
      date.setDate(start.getDate() + i);
      const key = dayKey(date);
      return {
        date, key, inMonth: date.getMonth() === month,
        items: this.items().filter((it) => it.scheduled_at.startsWith(key)),
        occasions: this.occasions().filter((o) => o.date === key),
      };
    });
  });

  legend = computed(() => {
    const seen = new Map<string, string>();
    for (const it of this.items()) seen.set(it.brand_name ?? it.project_name, it.brand_color ?? DEFAULT_PRIMARY);
    return [...seen].map(([name, color]) => ({ name, color }));
  });

  ngOnInit(): void {
    this.load();
  }

  shift(months: number): void {
    const m = this.month();
    this.month.set(new Date(m.getFullYear(), m.getMonth() + months, 1));
    this.load();
  }

  today(): void {
    this.month.set(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    this.load();
  }

  tint(color: string | null): string {
    return mix(color ?? DEFAULT_PRIMARY, '#FFFFFF', 0.85);
  }

  ink(color: string | null): string {
    return readableOnLight(color ?? DEFAULT_PRIMARY);
  }

  dragStart(event: DragEvent, item: CalendarItem): void {
    this.dragged = item;
    event.dataTransfer?.setData('text/plain', String(item.id));
  }

  allowDrop(event: DragEvent, key: string): void {
    if (!this.dragged) return;
    event.preventDefault();
    this.dropKey.set(key);
  }

  /** Sposta il post sul nuovo giorno mantenendo l'orario. */
  drop(event: DragEvent, key: string): void {
    event.preventDefault();
    this.dropKey.set(null);
    const item = this.dragged;
    this.dragged = null;
    if (!item || item.scheduled_at.startsWith(key)) return;
    const time = item.scheduled_at.slice(11, 16);
    const previous = item.scheduled_at;
    this.items.update((list) => list.map((it) => (it.id === item.id ? { ...it, scheduled_at: `${key} ${time}:00` } : it)));
    this.api.scheduleContent(item.id, `${key} ${time}`).subscribe({
      error: (err) => {
        this.items.update((list) => list.map((it) => (it.id === item.id ? { ...it, scheduled_at: previous } : it)));
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  private load(): void {
    const start = this.gridStart();
    const end = new Date(start);
    end.setDate(start.getDate() + 42);
    this.api.calendar(dayKey(start), dayKey(end)).subscribe({
      next: (list) => this.items.set(list),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
    this.api.occasions(dayKey(start), dayKey(end)).subscribe({ next: (list) => this.occasions.set(list) });
  }
}

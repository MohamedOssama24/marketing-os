import { Component, Input, OnChanges, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { CHANNEL_LABELS, StyleExample, apiErrorMessage } from '../core/models';

/** Nella scheda cliente: cosa ha imparato l'AI dalle correzioni e dalle richieste di modifica. */
@Component({
  selector: 'app-style-panel',
  standalone: true,
  imports: [DatePipe],
  template: `
    <section class="card growth-card">
      <div class="strategy-head">
        <h2><i class="ti ti-brain" aria-hidden="true"></i> Stile appreso</h2>
        @if (examples().length) { <span class="tag">{{ examples().length }} {{ examples().length === 1 ? 'esempio' : 'esempi' }}</span> }
      </div>
      <p class="muted small">Quando correggi un testo dell'AI e lo approvi, o chiedi modifiche, l'AI lo ricorda e i post successivi escono già nello stile giusto.</p>
      @if (error()) { <div class="alert">{{ error() }}</div> }
      @for (e of shown(); track e.id) {
        <div class="style-example">
          <div class="style-meta">
            <span class="tag">{{ e.kind === 'edit' ? 'Correzione' : 'Richiesta di modifica' }} · {{ channelLabels[e.channel] || e.channel }}</span>
            <small class="muted">{{ e.created_at.replace(' ', 'T') | date: 'd MMM' }}</small>
            @if (auth.canEdit()) {
              <button class="icon-btn" (click)="remove(e)" title="Dimentica questo esempio" aria-label="Dimentica questo esempio"><i class="ti ti-x" aria-hidden="true"></i></button>
            }
          </div>
          @if (e.kind === 'edit') {
            <div class="style-diff">
              <p class="before clamp">{{ e.before_text }}</p>
              <i class="ti ti-arrow-down" aria-hidden="true"></i>
              <p class="after clamp">{{ e.after_text }}</p>
            </div>
          } @else {
            <p class="style-feedback">“{{ e.after_text }}”</p>
          }
        </div>
      } @empty {
        <p class="muted small"><i class="ti ti-info-circle" aria-hidden="true"></i> Ancora niente: si riempie da solo man mano che approvi e correggi i post.</p>
      }
      @if (examples().length > 3) {
        <button class="link-btn" (click)="all.set(!all())">{{ all() ? 'Mostra meno' : 'Mostra tutti' }}</button>
      }
    </section>
  `,
})
export class StylePanelComponent implements OnChanges {
  @Input({ required: true }) brandId!: number;
  private api = inject(ApiService);
  readonly auth = inject(AuthService);
  readonly channelLabels = CHANNEL_LABELS as Record<string, string>;

  examples = signal<StyleExample[]>([]);
  all = signal(false);
  error = signal<string | null>(null);

  shown = () => (this.all() ? this.examples() : this.examples().slice(0, 3));

  ngOnChanges(): void {
    this.api.styleExamples(this.brandId).subscribe({ next: (list) => this.examples.set(list), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  remove(e: StyleExample): void {
    this.api.deleteStyleExample(e.id).subscribe({
      next: () => this.examples.update((list) => list.filter((x) => x.id !== e.id)),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }
}

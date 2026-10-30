import { Component, DestroyRef, OnDestroy, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, debounceTime, distinctUntilChanged, of, switchMap } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { ApiService } from '../core/api.service';
import { DomainSuggestions, apiErrorMessage } from '../core/models';

const STEPS = ['Leggo il sito', 'Estraggo colori e logo', 'Analizzo prodotti e pubblico', 'Scrivo il brand kit'];

/** "Dal sito alla campagna": incolli l'indirizzo del cliente e l'AI prepara il suo brand kit. */
@Component({
  selector: 'app-site-analyzer',
  standalone: true,
  imports: [FormsModule],
  template: `
    <section class="analyzer">
      <div class="analyzer-head">
        <span class="analyzer-icon"><i class="ti ti-sparkles" aria-hidden="true"></i></span>
        <div>
          <h2>Dal sito alla campagna</h2>
          <p class="muted">Inserisci il sito di un cliente: l'AI ne ricava brand kit, colori e logo in meno di un minuto.</p>
        </div>
      </div>
      @if (!running()) {
        <form class="analyzer-form" (ngSubmit)="start()">
          <div class="input-icon">
            <i class="ti ti-world" aria-hidden="true"></i>
            <input [(ngModel)]="url" (ngModelChange)="typed($event)" name="url" placeholder="pasticceriarossi.it" aria-label="Sito del cliente" [disabled]="!auth.canEdit()"
                   autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="site-suggestions" [attr.aria-expanded]="open()"
                   (keydown.arrowDown)="move(1, $event)" (keydown.arrowUp)="move(-1, $event)" (keydown.enter)="enter($event)"
                   (keydown.escape)="open.set(false)" (blur)="open.set(false)" (focus)="open.set(options().length > 0)" />
            @if (open()) {
              <div class="site-suggestions" id="site-suggestions" role="listbox">
                @for (o of options(); track $index) {
                  @if ($index === 0 || options()[$index - 1].kind !== o.kind) {
                    <p class="site-suggestions-group">{{ o.kind === 'client' ? 'Già tra i tuoi clienti' : 'Siti esistenti' }}</p>
                  }
                  <button type="button" class="site-suggestion" role="option" [class.active]="$index === active()" [attr.aria-selected]="$index === active()"
                          (mousedown)="$event.preventDefault()" (mouseenter)="active.set($index)" (click)="choose(o)">
                    <i class="ti" [class]="o.kind === 'client' ? 'ti ti-user' : 'ti ti-world'" aria-hidden="true"></i>
                    <span class="grow ellipsis">{{ o.label }}</span>
                    @if (o.hint) { <small class="muted ellipsis">{{ o.hint }}</small> }
                  </button>
                }
              </div>
            }
          </div>
          <button class="btn primary" type="submit" [disabled]="!url.trim() || !auth.canEdit()">Analizza <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
        </form>
        @if (error()) { <div class="alert">{{ error() }}</div> }
      } @else {
        <ol class="steps">
          @for (step of steps; track step; let i = $index) {
            <li [class.done]="i < current()" [class.active]="i === current()">
              <span class="step-dot">
                @if (i < current()) { <i class="ti ti-check" aria-hidden="true"></i> } @else if (i === current()) { <span class="spinner"></span> }
              </span>
              {{ step }}
            </li>
          }
        </ol>
      }
    </section>
  `,
})
export class SiteAnalyzerComponent implements OnDestroy {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  readonly steps = STEPS;
  url = '';
  running = signal(false);
  current = signal(0);
  error = signal<string | null>(null);
  private ticker?: ReturnType<typeof setInterval>;

  // Suggerimenti mentre si scrive: dopo una breve pausa si chiedono al server clienti e domini esistenti.
  options = signal<Suggestion[]>([]);
  open = signal(false);
  active = signal(-1);
  private query$ = new Subject<string>();

  constructor() {
    this.query$
      .pipe(
        debounceTime(350),
        distinctUntilChanged(),
        switchMap((q) => (q.length < 3 ? of(null) : this.api.domainSuggestions(q))),
        takeUntilDestroyed(),
      )
      .subscribe({
        next: (s) => {
          const options = s ? toOptions(s) : [];
          this.options.set(options);
          this.active.set(-1);
          this.open.set(options.length > 0);
        },
        error: () => this.options.set([]),
      });
  }

  typed(value: string): void {
    this.error.set(null);
    this.query$.next(value.trim());
  }

  move(step: number, event: Event): void {
    if (!this.open() || !this.options().length) return;
    event.preventDefault();
    const n = this.options().length;
    this.active.update((i) => (i + step + n) % n);
  }

  /** Invio sceglie il suggerimento evidenziato; senza suggerimento evidenziato avvia l'analisi. */
  enter(event: Event): void {
    const option = this.open() ? this.options()[this.active()] : undefined;
    if (!option) return;
    event.preventDefault();
    this.choose(option);
  }

  choose(option: Suggestion): void {
    this.open.set(false);
    if (option.kind === 'client') {
      this.router.navigate(['/clients', option.id]);
      return;
    }
    this.url = option.label;
    this.options.set([]);
  }

  start(): void {
    this.open.set(false);
    this.error.set(null);
    this.running.set(true);
    this.current.set(0);
    // L'avanzamento dei passi è indicativo: l'ultimo resta attivo finché il lavoro non termina.
    this.ticker = setInterval(() => this.current.update((c) => Math.min(c + 1, STEPS.length - 1)), 4000);
    this.api
      .analyzeSite(this.url.trim())
      .pipe(switchMap((job) => this.api.waitForJob(job.id)), takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (job) => {
          this.stop();
          if (job.status === 'failed' || !job.result?.brand_id) {
            this.error.set(job.error ?? 'Analisi non riuscita.');
            return;
          }
          this.router.navigate(['/clients', job.result.brand_id], { queryParams: { nuovo: 1 } });
        },
        error: (err) => {
          this.stop();
          this.error.set(apiErrorMessage(err));
        },
      });
  }

  ngOnDestroy(): void {
    clearInterval(this.ticker);
  }

  private stop(): void {
    clearInterval(this.ticker);
    this.running.set(false);
  }
}

interface Suggestion {
  kind: 'client' | 'domain';
  label: string;
  hint?: string;
  id?: number;
}

function toOptions(s: DomainSuggestions): Suggestion[] {
  return [
    ...s.clients.map((c) => ({ kind: 'client' as const, label: c.name, hint: c.website_url?.replace(/^https?:\/\/(www\.)?/, '') ?? undefined, id: c.id })),
    ...s.domains.map((d) => ({ kind: 'domain' as const, label: d })),
  ];
}

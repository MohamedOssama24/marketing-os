import { Component, DestroyRef, OnDestroy, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { switchMap } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { ApiService } from '../core/api.service';
import { apiErrorMessage } from '../core/models';

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
            <input [(ngModel)]="url" name="url" placeholder="pasticceriarossi.it" aria-label="Sito del cliente" [disabled]="!auth.canEdit()" />
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

  start(): void {
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

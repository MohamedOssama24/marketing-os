import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { finalize, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { Brand, REVIEW_PLATFORMS, ReviewPlatform, ReviewReply, apiErrorMessage } from '../../core/models';

/** Risposte alle recensioni: incolli la recensione, l'AI propone tre risposte nel tono del cliente. */
@Component({
  selector: 'app-reviews',
  standalone: true,
  imports: [DatePipe, FormsModule],
  template: `
    <div class="page-head">
      <div>
        <h1>Recensioni</h1>
        <p class="muted">Rispondere a ogni recensione, anche a quelle negative, fa crescere la fiducia. Incolla la recensione: l'AI propone la risposta.</p>
      </div>
      <div class="head-controls">
        <select [(ngModel)]="brandId" (ngModelChange)="loadSaved()" aria-label="Cliente">
          @for (b of brands(); track b.id) { <option [ngValue]="b.id">{{ b.name }}</option> }
        </select>
      </div>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="two-col">
      <section class="card review-form">
        <h2><i class="ti ti-star" aria-hidden="true"></i> Nuova recensione</h2>
        <div class="quick-picks">
          @for (p of platforms; track p.key) {
            <button class="chip" [class.active]="platform === p.key" (click)="platform = p.key"><i class="ti" [class]="'ti ' + p.icon" aria-hidden="true"></i> {{ p.label }}</button>
          }
        </div>
        <div class="stars" role="radiogroup" aria-label="Stelle">
          @for (n of [1, 2, 3, 4, 5]; track n) {
            <button type="button" [class.on]="n <= rating" (click)="rating = n" role="radio" [attr.aria-checked]="n === rating" [attr.aria-label]="n + (n === 1 ? ' stella' : ' stelle')"><i class="ti ti-star-filled" aria-hidden="true"></i></button>
          }
        </div>
        <label>Autore<input [(ngModel)]="author" maxlength="120" placeholder="Es. Marco R." /></label>
        <label>Testo della recensione<textarea rows="5" [(ngModel)]="text" maxlength="4000" placeholder="Incolla qui la recensione"></textarea></label>
        @if (auth.canEdit()) {
          <button class="btn primary" (click)="suggest()" [disabled]="!brandId || !text.trim() || thinking()">
            @if (thinking()) { <span class="spinner small"></span> Scrivo le risposte… } @else { <i class="ti ti-sparkles" aria-hidden="true"></i> Proponi risposte }
          </button>
        }
      </section>

      <div class="side-col">
        @if (replies().length) {
          <section class="card review-replies">
            <h2>Scegli la risposta</h2>
            @for (r of replies(); track $index) {
              <div class="reply-option">
                <textarea rows="4" [(ngModel)]="drafts[$index]"></textarea>
                <div class="actions">
                  <button class="btn ghost small" (click)="copy(drafts[$index], $index)"><i class="ti" [class]="copied() === $index ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() === $index ? 'Copiata' : 'Copia' }}</button>
                  <button class="btn primary small" (click)="save(drafts[$index])" [disabled]="busy()"><i class="ti ti-device-floppy" aria-hidden="true"></i> Usa e salva</button>
                </div>
              </div>
            }
          </section>
        }

        <section class="card">
          <h2>Risposte salvate</h2>
          @for (r of saved(); track r.id) {
            <div class="saved-review">
              <div class="saved-review-head">
                <span class="tag">{{ platformLabel(r.platform) }}</span>
                <span class="stars-small">@for (n of [1, 2, 3, 4, 5]; track n) { <i class="ti ti-star-filled" [class.on]="n <= r.rating" aria-hidden="true"></i> }</span>
                <small class="muted">{{ r.author || 'Anonimo' }} · {{ r.created_at.replace(' ', 'T') | date: 'd MMM' }}</small>
                @if (auth.canEdit()) { <button class="icon-btn" (click)="remove(r)" aria-label="Elimina" title="Elimina"><i class="ti ti-x" aria-hidden="true"></i></button> }
              </div>
              <p class="muted small clamp">“{{ r.review_text }}”</p>
              <p class="pre small">{{ r.reply }}</p>
            </div>
          } @empty {
            <p class="muted small">Le risposte che salvi restano qui, per cliente: utili per mantenere lo stesso tono.</p>
          }
        </section>
      </div>
    </div>
  `,
})
export class ReviewsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private destroyRef = inject(DestroyRef);

  readonly platforms = REVIEW_PLATFORMS;
  brands = signal<Brand[]>([]);
  saved = signal<ReviewReply[]>([]);
  replies = signal<string[]>([]);
  thinking = signal(false);
  busy = signal(false);
  copied = signal<number | null>(null);
  error = signal<string | null>(null);
  brandId: number | null = null;
  platform: ReviewPlatform = 'google';
  rating = 5;
  author = '';
  text = '';
  drafts: string[] = [];

  ngOnInit(): void {
    this.api.brands().subscribe((list) => {
      this.brands.set(list);
      this.brandId = list[0]?.id ?? null;
      this.loadSaved();
    });
  }

  loadSaved(): void {
    if (!this.brandId) return;
    this.replies.set([]);
    this.api.reviewReplies(this.brandId).subscribe({ next: (list) => this.saved.set(list), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  suggest(): void {
    this.thinking.set(true);
    this.error.set(null);
    this.api
      .suggestReviewReplies(this.brandId!, this.review())
      .pipe(switchMap((job) => this.api.waitForJob(job.id)), finalize(() => this.thinking.set(false)), takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (job) => {
          if (job.status === 'failed') this.error.set(job.error ?? 'Risposte non disponibili.');
          this.replies.set(job.result?.replies ?? []);
          this.drafts = [...(job.result?.replies ?? [])];
        },
        error: (err) => this.error.set(apiErrorMessage(err)),
      });
  }

  save(reply: string): void {
    this.busy.set(true);
    this.api.saveReviewReply({ brand_id: this.brandId!, ...this.review(), reply: reply.trim() }).subscribe({
      next: () => {
        this.busy.set(false);
        this.copy(reply, -1);
        this.replies.set([]);
        this.text = '';
        this.author = '';
        this.rating = 5;
        this.loadSaved();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  remove(r: ReviewReply): void {
    this.api.deleteReviewReply(r.id).subscribe({ next: () => this.saved.update((list) => list.filter((x) => x.id !== r.id)) });
  }

  copy(text: string, index: number): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied.set(index);
      setTimeout(() => this.copied.set(null), 2000);
    });
  }

  platformLabel(key: ReviewPlatform): string {
    return this.platforms.find((p) => p.key === key)?.label ?? key;
  }

  private review() {
    return { platform: this.platform, rating: this.rating, author: this.author.trim() || null, review_text: this.text.trim() };
  }
}

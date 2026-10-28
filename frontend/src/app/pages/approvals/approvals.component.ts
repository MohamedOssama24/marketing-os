import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Asset, Brand, ContentItem, apiErrorMessage } from '../../core/models';
import { PostCardComponent } from '../../shared/post-card.component';

@Component({
  selector: 'app-approvals',
  standalone: true,
  imports: [RouterLink, PostCardComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>Approvazioni</h1>
        <p class="muted">
          @if (auth.canManage()) { Contenuti in attesa del tuo via libera, in ordine di pubblicazione. }
          @else { Contenuti in attesa di approvazione da parte di un amministratore. }
        </p>
      </div>
      @if (items().length) { <span class="count-pill">{{ items().length }} in attesa</span> }
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="feed">
      @for (c of items(); track c.id) {
        <div class="feed-item">
          <a class="feed-context small" [routerLink]="['/campaigns', c.project_id]"><i class="ti ti-speakerphone" aria-hidden="true"></i> {{ c.project_name }}</a>
          <app-post-card [item]="c" [brand]="brandOf(c.brand_profile_id)" [assets]="assets()" (changed)="changed($event)" />
        </div>
      } @empty {
        @if (loaded()) {
          <div class="card empty">
            <i class="ti ti-circle-check empty-icon" aria-hidden="true"></i>
            <h3>Tutto approvato</h3>
            <p class="muted">Quando un contenuto viene inviato in revisione compare qui.</p>
          </div>
        }
      }
    </div>
  `,
})
export class ApprovalsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  items = signal<ContentItem[]>([]);
  brands = signal<Brand[]>([]);
  assets = signal<Asset[]>([]);
  loaded = signal(false);
  error = signal<string | null>(null);

  ngOnInit(): void {
    this.api.brands().subscribe((b) => this.brands.set(b));
    this.api.assets().subscribe((a) => this.assets.set(a));
    this.api.approvals().subscribe({
      next: (list) => {
        this.items.set(list);
        this.loaded.set(true);
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  brandOf(id: number | null | undefined): Brand | null {
    return this.brands().find((b) => b.id === id) ?? null;
  }

  /** Un contenuto approvato o rifiutato esce dalla lista. */
  changed(updated: ContentItem): void {
    this.items.update((list) => (updated.status === 'in_review' ? list.map((c) => (c.id === updated.id ? { ...c, ...updated } : c)) : list.filter((c) => c.id !== updated.id)));
  }
}

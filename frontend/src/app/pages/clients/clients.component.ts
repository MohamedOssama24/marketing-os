import { Component, OnInit, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Brand, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';
import { SiteAnalyzerComponent } from '../../shared/site-analyzer.component';

@Component({
  selector: 'app-clients',
  standalone: true,
  imports: [RouterLink, AssetPreviewComponent, SiteAnalyzerComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>Clienti</h1>
        <p class="muted">Ogni cliente ha il suo brand kit: l'AI lo usa per scrivere e disegnare con la sua identità.</p>
      </div>
      @if (auth.canEdit()) {
        <a class="btn ghost" routerLink="/clients/new"><i class="ti ti-plus" aria-hidden="true"></i> Inserisci a mano</a>
      }
    </div>

    <app-site-analyzer />

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="grid">
      @for (b of brands(); track b.id) {
        <a class="card client-card link-card" [routerLink]="['/clients', b.id]">
          <div class="client-cover" [style.background]="b.primary_color ?? '#5B4BDB'">
            <span class="client-logo">
              @if (b.logo_asset_id) { <app-asset-preview [assetId]="b.logo_asset_id" fit="contain" [alt]="'Logo ' + b.name" /> } @else { {{ b.name.charAt(0) }} }
            </span>
          </div>
          <div class="client-body">
            <h3>{{ b.name }}</h3>
            <p class="muted small clamp">{{ b.summary ?? b.industry ?? 'Brand kit da completare' }}</p>
            <div class="swatches">
              @for (c of [b.primary_color, b.secondary_color]; track $index) {
                @if (c) { <span class="swatch" [style.background]="c" [title]="c"></span> }
              }
              @if (b.website_url) { <span class="muted small ellipsis"><i class="ti ti-world" aria-hidden="true"></i> {{ host(b.website_url) }}</span> }
            </div>
          </div>
        </a>
      } @empty {
        <div class="card empty">
          <i class="ti ti-building-store empty-icon" aria-hidden="true"></i>
          <h3>Aggiungi il tuo primo cliente</h3>
          <p class="muted">Incolla il suo sito qui sopra: in meno di un minuto avrai brand kit, colori e logo.</p>
        </div>
      }
    </div>
  `,
})
export class ClientsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  brands = signal<Brand[]>([]);
  error = signal<string | null>(null);

  ngOnInit(): void {
    this.api.brands().subscribe({ next: (b) => this.brands.set(b), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  host(url: string): string {
    try { return new URL(url).host.replace(/^www\./, ''); } catch { return url; }
  }
}

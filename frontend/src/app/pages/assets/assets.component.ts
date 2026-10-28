import { Component, OnInit, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { finalize } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { ApiService } from '../../core/api.service';
import { Asset, apiErrorMessage } from '../../core/models';
import { AssetPreviewComponent } from '../../shared/asset-preview.component';

@Component({
  selector: 'app-assets',
  standalone: true,
  imports: [DatePipe, AssetPreviewComponent],
  template: `
    <div class="page-head">
      <div>
        <h1>Libreria file</h1>
        <p class="muted">Loghi, foto prodotto e video dei clienti. Formati: PNG, JPG, WEBP, GIF, MP4, MOV, WEBM, PDF (max 20 MB).</p>
      </div>
      @if (auth.canEdit()) {
        <label class="btn primary upload-btn">
          {{ uploading() ? 'Caricamento…' : '+ Carica file' }}
          <input type="file" multiple hidden [disabled]="uploading()" (change)="upload($event)"
                 accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/quicktime,video/webm,application/pdf" />
        </label>
      }
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }

    <div class="asset-grid">
      @for (a of assets(); track a.id) {
        <figure class="card asset-card">
          <div class="asset-thumb"><app-asset-preview [assetId]="a.id" [kind]="a.kind" [alt]="a.original_name" /></div>
          <figcaption>
            <span class="asset-name" [title]="a.original_name">{{ a.original_name }}</span>
            <span class="muted small">{{ size(a.size_bytes) }} · {{ a.created_at | date: 'dd/MM/yyyy' }}</span>
          </figcaption>
          @if (auth.canEdit()) {
            <button class="btn ghost small" (click)="remove(a)">Elimina</button>
          }
        </figure>
      } @empty {
        <div class="card empty">
          <h3>Nessun file</h3>
          <p class="muted">Carica loghi e foto prodotto: serviranno per il brand kit e, nei prossimi sprint, per immagini e video.</p>
        </div>
      }
    </div>
  `,
})
export class AssetsComponent implements OnInit {
  readonly auth = inject(AuthService);
  private api = inject(ApiService);

  assets = signal<Asset[]>([]);
  uploading = signal(false);
  error = signal<string | null>(null);

  ngOnInit(): void {
    this.api.assets().subscribe({ next: (a) => this.assets.set(a), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  upload(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    input.value = '';
    if (files.length === 0) return;
    this.uploading.set(true);
    this.error.set(null);
    let pending = files.length;
    for (const file of files) {
      this.api
        .uploadAsset(file)
        .pipe(finalize(() => { if (--pending === 0) this.uploading.set(false); }))
        .subscribe({
          next: (asset) => this.assets.update((list) => [asset, ...list]),
          error: (err) => this.error.set(`${file.name}: ${apiErrorMessage(err)}`),
        });
    }
  }

  remove(asset: Asset): void {
    if (!confirm(`Eliminare "${asset.original_name}"?`)) return;
    this.api.deleteAsset(asset.id).subscribe({
      next: () => this.assets.update((list) => list.filter((a) => a.id !== asset.id)),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  size(bytes: number): string {
    return bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  }
}

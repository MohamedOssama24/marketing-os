import { Component, Input, OnChanges, OnDestroy, inject, signal } from '@angular/core';
import { ApiService } from '../core/api.service';
import { Asset } from '../core/models';

/** Anteprima di un file della libreria: il file è protetto da token, quindi viene scaricato come blob. */
@Component({
  selector: 'app-asset-preview',
  standalone: true,
  template: `
    @if (url()) {
      @if (kind === 'video') {
        <video [src]="url()" controls muted preload="metadata"></video>
      } @else {
        <img [src]="url()" [alt]="alt" />
      }
    } @else {
      <div class="asset-placeholder">{{ kind === 'document' ? 'PDF' : '…' }}</div>
    }
  `,
  styles: [`
    :host { display: block; width: 100%; height: 100%; }
    img, video { width: 100%; height: 100%; object-fit: cover; display: block; }
    .asset-placeholder { width: 100%; height: 100%; display: grid; place-items: center; color: var(--muted); font-weight: 600; background: #eef0f5; }
  `],
})
export class AssetPreviewComponent implements OnChanges, OnDestroy {
  @Input({ required: true }) assetId!: number;
  @Input() kind: Asset['kind'] = 'image';
  @Input() alt = '';

  private api = inject(ApiService);
  url = signal<string | null>(null);

  ngOnChanges(): void {
    this.revoke();
    if (this.kind === 'document') return;
    this.api.assetBlob(this.assetId).subscribe({
      next: (blob) => this.url.set(URL.createObjectURL(blob)),
      error: () => this.url.set(null),
    });
  }

  ngOnDestroy(): void {
    this.revoke();
  }

  private revoke(): void {
    const current = this.url();
    if (current) URL.revokeObjectURL(current);
    this.url.set(null);
  }
}

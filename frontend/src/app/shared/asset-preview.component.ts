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
        <video [src]="url()" controls playsinline preload="metadata" [class.natural]="fit === 'contain'"></video>
      } @else if (kind === 'audio') {
        <div class="asset-audio"><i class="ti ti-music" aria-hidden="true"></i><audio [src]="url()" controls preload="none"></audio></div>
      } @else {
        <img [src]="url()" [alt]="alt" [class.contain]="fit === 'contain'" />
      }
    } @else {
      <div class="asset-placeholder">{{ kind === 'document' ? 'PDF' : kind === 'audio' ? '♪' : '…' }}</div>
    }
  `,
  styles: [`
    :host { display: block; width: 100%; height: 100%; }
    img, video { width: 100%; height: 100%; object-fit: cover; display: block; }
    img.contain { object-fit: contain; padding: 8%; box-sizing: border-box; }
    video.natural { height: auto; background: #000; }
    .asset-audio { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 8px; box-sizing: border-box; background: #eef0f5; color: var(--muted);
      i { font-size: 32px; } audio { width: 100%; height: 32px; } }
    .asset-placeholder { width: 100%; height: 100%; display: grid; place-items: center; color: var(--muted); font-weight: 600; background: #eef0f5; }
  `],
})
export class AssetPreviewComponent implements OnChanges, OnDestroy {
  @Input({ required: true }) assetId!: number;
  @Input() kind: Asset['kind'] = 'image';
  @Input() alt = '';
  /** contain per i loghi (mai ritagliati), cover per le foto. */
  @Input() fit: 'cover' | 'contain' = 'cover';

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

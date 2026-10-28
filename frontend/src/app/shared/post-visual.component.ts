import { Component, ElementRef, EventEmitter, Input, Output, inject } from '@angular/core';
import { toPng } from 'html-to-image';
import { Brand, Visual } from '../core/models';
import { AssetPreviewComponent } from './asset-preview.component';
import { DEFAULT_PRIMARY, mix, readableOnLight, textOn } from './color';

const loadedFonts = new Set<string>();

/** Carica da Google Fonts il font del brand (solo nomi alfanumerici). */
function ensureFont(family: string | null | undefined): string | null {
  const name = (family ?? '').trim();
  if (!/^[A-Za-z0-9 ]{2,60}$/.test(name)) return null;
  if (!loadedFonts.has(name)) {
    loadedFonts.add(name);
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(name)}:wght@400;700;800&display=swap`;
    document.head.appendChild(link);
  }
  return name;
}

/**
 * Grafica di un post generata dal brand kit: colori, logo, font e testi della campagna.
 * Si adatta alla larghezza del contenitore e si può esportare come PNG.
 */
@Component({
  selector: 'app-post-visual',
  standalone: true,
  imports: [AssetPreviewComponent],
  template: `
    <div [class]="'pv layout-' + layout() + ' format-' + format" [style.background]="bg()" [style.color]="fg()" [style.font-family]="font()">
      @if (hasPhoto()) {
        <div class="pv-media">
          @if (visual?.asset_id) {
            <app-asset-preview [assetId]="visual!.asset_id!" />
          } @else {
            <button type="button" class="pv-media-empty" [class.clickable]="photoClickable" [disabled]="!photoClickable" [style.background]="tint()" [style.color]="ink()" (click)="photoRequested.emit()">
              <i class="ti ti-photo-plus" aria-hidden="true"></i><span>{{ photoClickable ? 'Carica una foto' : 'Foto da scegliere' }}</span>
            </button>
          }
        </div>
      }
      @if (layout() === 'offer') {
        <span class="pv-ring" [style.border-color]="accent()"></span>
        <span class="pv-ring pv-ring-2" [style.border-color]="accent()"></span>
      }

      <div class="pv-top">
        @if (brand?.logo_asset_id) {
          <span class="pv-logo"><app-asset-preview [assetId]="brand!.logo_asset_id!" fit="contain" [alt]="brand!.name" /></span>
        } @else if (brand) {
          <span class="pv-logo pv-logo-text" [style.color]="primary()">{{ brand.name.charAt(0) }}</span>
        }
        @if (visual?.badge) { <span class="pv-badge" [style.background]="badgeBg()" [style.color]="badgeFg()">{{ visual!.badge }}</span> }
      </div>

      <div class="pv-text" [style.background]="layout() === 'split' ? primary() : null" [style.color]="layout() === 'split' ? textOnPrimary() : null">
        @if (layout() === 'quote') { <span class="pv-quote-mark" [style.color]="primary()">“</span> }
        <p class="pv-headline">{{ visual?.headline || 'Titolo della grafica' }}</p>
        @if (visual?.subheadline) { <p class="pv-sub">{{ layout() === 'quote' ? '— ' : '' }}{{ visual!.subheadline }}</p> }
        @if (layout() === 'bold' || layout() === 'light') { <span class="pv-rule" [style.background]="accent()"></span> }
      </div>
      @if (brand && (layout() === 'bold' || layout() === 'light' || layout() === 'offer')) { <p class="pv-brand">{{ brand.name }}</p> }
    </div>
  `,
  styles: [`
    :host { display: block; container-type: inline-size; }
    .pv { position: relative; overflow: hidden; display: flex; flex-direction: column; padding: 7cqw; aspect-ratio: 1; }
    .format-portrait { aspect-ratio: 4 / 5; }
    .format-story { aspect-ratio: 9 / 16; }

    .pv-media { position: absolute; inset: 0; z-index: 0; }
    .pv-media-empty { width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2cqw; font: inherit; font-size: 4cqw; border: none; i { font-size: 12cqw; }
      &.clickable { cursor: pointer; &:hover span { text-decoration: underline; } } }
    .pv-top { position: relative; z-index: 2; display: flex; justify-content: space-between; align-items: flex-start; gap: 3cqw; }
    .pv-logo { width: 14cqw; height: 14cqw; border-radius: 3cqw; overflow: hidden; background: #fff; display: grid; place-items: center; flex-shrink: 0; box-shadow: 0 1cqw 3cqw rgba(0,0,0,0.12); }
    .pv-logo-text { font-weight: 800; font-size: 7cqw; }
    .pv-badge { font-size: 3.6cqw; font-weight: 700; padding: 1.4cqw 3.2cqw; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.04em; margin-left: auto; }
    .pv-text { position: relative; z-index: 2; margin-top: auto; }
    .pv-headline { margin: 0; font-size: 10cqw; line-height: 1.02; font-weight: 800; letter-spacing: -0.02em; overflow-wrap: anywhere; }
    .pv-sub { margin: 3cqw 0 0; font-size: 4.6cqw; line-height: 1.3; opacity: 0.9; }
    .pv-rule { display: block; width: 14cqw; height: 1.4cqw; border-radius: 1cqw; margin-top: 5cqw; }
    .pv-brand { position: relative; z-index: 2; margin: 4cqw 0 0; font-size: 3.4cqw; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; opacity: 0.75; }

    /* Foto a tutta pagina con fascia scura per il testo */
    .layout-photo .pv-text { background: rgba(0, 0, 0, 0.55); color: #fff; margin: auto -7cqw -7cqw; padding: 6cqw 7cqw 7cqw; }

    /* Foto sopra, colore del brand sotto */
    .layout-split .pv-media { bottom: 42%; }
    .layout-split .pv-text { margin: auto -7cqw -7cqw; padding: 6cqw 7cqw 7cqw; min-height: 42%; display: flex; flex-direction: column; justify-content: center; }
    .layout-split .pv-headline { font-size: 8.5cqw; }
    .format-story.layout-split .pv-media { bottom: 38%; }

    /* Offerta: promessa grande al centro e cerchi decorativi */
    .layout-offer { justify-content: space-between; }
    .layout-offer .pv-text { margin: auto 0; text-align: center; }
    .layout-offer .pv-headline { font-size: 15cqw; line-height: 0.95; }
    .layout-offer .pv-sub { font-size: 5cqw; }
    .layout-offer .pv-brand { text-align: center; }
    .pv-ring { position: absolute; z-index: 1; width: 90cqw; height: 90cqw; border-radius: 50%; border: 1.6cqw solid; opacity: 0.25; right: -38cqw; top: -30cqw; }
    .pv-ring-2 { width: 60cqw; height: 60cqw; left: -30cqw; right: auto; top: auto; bottom: -26cqw; }

    /* Citazione */
    .layout-quote .pv-text { margin: auto 0; }
    .pv-quote-mark { display: block; font-size: 30cqw; line-height: 0.6; font-weight: 800; font-family: Georgia, serif; height: 14cqw; }
    .layout-quote .pv-headline { font-size: 7.6cqw; line-height: 1.2; font-weight: 700; letter-spacing: -0.01em; }
    .layout-quote .pv-sub { font-size: 4.2cqw; font-weight: 600; margin-top: 5cqw; }
  `],
})
export class PostVisualComponent {
  @Input() visual: Visual | null = null;
  @Input() brand: Brand | null = null;
  /** square (1:1), portrait (4:5) o story (9:16) */
  @Input() format: 'square' | 'portrait' | 'story' = 'square';
  /** Il riquadro "foto mancante" diventa un pulsante per caricarne una. */
  @Input() photoClickable = false;
  @Output() photoRequested = new EventEmitter<void>();

  private host = inject(ElementRef<HTMLElement>);

  // Metodi semplici e non computed: durante la modifica il genitore cambia lo stesso oggetto visual,
  // e l'anteprima deve aggiornarsi a ogni battuta.
  layout(): string { return this.visual?.layout ?? 'bold'; }
  hasPhoto(): boolean { return this.layout() === 'photo' || this.layout() === 'split'; }
  primary(): string { return this.brand?.primary_color ?? DEFAULT_PRIMARY; }
  secondary(): string { return this.brand?.secondary_color ?? mix(this.primary(), '#FFFFFF', 0.6); }
  tint(): string { return mix(this.primary(), '#FFFFFF', 0.86); }
  ink(): string { return readableOnLight(this.primary()); }
  textOnPrimary(): string { return textOn(this.primary()); }
  font(): string | null { return ensureFont(this.brand?.font_family) ? `'${this.brand!.font_family}', system-ui, sans-serif` : null; }

  bg(): string {
    switch (this.layout()) {
      case 'light': case 'quote': return this.tint();
      case 'photo': case 'split': return mix(this.primary(), '#000000', 0.55);
      case 'offer': return mix(this.primary(), '#000000', 0.15);
      default: return this.primary();
    }
  }
  fg(): string { return ['light', 'quote'].includes(this.layout()) ? this.ink() : textOn(this.bg()); }
  accent(): string { return ['light', 'quote'].includes(this.layout()) ? this.primary() : this.secondary(); }
  badgeBg(): string { return ['bold', 'offer'].includes(this.layout()) ? this.secondary() : this.primary(); }
  badgeFg(): string { return textOn(this.badgeBg()); }

  /** Esporta la grafica come PNG largo 1080 pixel. */
  async exportPng(fileName: string): Promise<void> {
    const node = this.host.nativeElement.querySelector('.pv') as HTMLElement;
    const dataUrl = await toPng(node, { pixelRatio: 1080 / node.offsetWidth, cacheBust: true });
    const link = document.createElement('a');
    link.href = dataUrl;
    link.download = `${fileName}.png`;
    link.click();
  }
}

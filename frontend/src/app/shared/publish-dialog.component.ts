import { Component, EventEmitter, HostListener, Input, OnInit, Output, ViewChild, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { ApiService } from '../core/api.service';
import { Brand, CHANNEL_LABELS, ContentItem, TrackedLink, apiErrorMessage } from '../core/models';
import { PostVisualComponent } from './post-visual.component';
import { EmailService, UNSUBSCRIBE_PLACEHOLDERS, blobToDataUrl, buildNewsletterHtml, publishableText } from './publishing';

interface NetworkLink {
  key: string;
  label: string;
  icon: string;
  url: string;
  hint: string;
}

/** Kit di pubblicazione: grafica, testo e link per i social; newsletter HTML per l'email; stato "pubblicato". */
@Component({
  selector: 'app-publish-dialog',
  standalone: true,
  imports: [FormsModule, DatePipe, PostVisualComponent],
  template: `
    <div class="modal-backdrop" (click)="onBackdrop($event)">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="publish-title">
        <header class="modal-head">
          <h2 id="publish-title"><i class="ti ti-send" aria-hidden="true"></i> Pubblica</h2>
          <span class="muted small">{{ item.title }}</span>
          <button class="icon-btn" (click)="closed.emit()" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button>
        </header>

        <div class="publish-grid">
          <div class="publish-preview">
            <app-post-visual #visualRef [visual]="item.visual" [brand]="brand" [format]="format" />
            <p class="muted small center">{{ channelLabels[item.channel] }} · {{ item.content_type }}</p>
          </div>

          <div class="publish-main">
            <div class="filters">
              <button class="chip" [class.active]="tab() === 'social'" (click)="tab.set('social')"><i class="ti ti-share" aria-hidden="true"></i> Social</button>
              <button class="chip" [class.active]="tab() === 'email'" (click)="openEmail()"><i class="ti ti-mail" aria-hidden="true"></i> Email</button>
            </div>

            @if (tab() === 'social') {
              <section class="step">
                <span class="step-num">1</span>
                <div class="step-body">
                  <h3>{{ item.video_asset_id ? 'Scarica la grafica o il video' : 'Scarica la grafica' }}</h3>
                  <div class="actions start">
                    <button class="btn ghost small" (click)="downloadPng()"><i class="ti ti-download" aria-hidden="true"></i> Scarica PNG</button>
                    @if (item.video_asset_id) {
                      <button class="btn ghost small" (click)="downloadVideo()"><i class="ti ti-movie" aria-hidden="true"></i> Scarica video MP4</button>
                    }
                    @if (canShareFiles) {
                      <button class="btn ghost small" (click)="share()"><i class="ti ti-share" aria-hidden="true"></i> Condividi…</button>
                    }
                  </div>
                  @if (canShareFiles) { <p class="muted small">Dal telefono "Condividi" apre direttamente Instagram, Facebook o WhatsApp con {{ item.video_asset_id ? 'il video' : 'la grafica' }} (il testo viene copiato).</p> }
                </div>
              </section>
              <section class="step">
                <span class="step-num">2</span>
                <div class="step-body">
                  <h3>Link per contare i clic</h3>
                  @if (link(); as l) {
                    <div class="tracked-link">
                      <code>{{ l.short_url }}</code>
                      <button class="icon-btn" (click)="copy(l.short_url, 'link')" [attr.aria-label]="copied() === 'link' ? 'Copiato' : 'Copia link'" title="Copia link">
                        <i class="ti" [class]="copied() === 'link' ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i></button>
                      <span class="link-clicks"><strong>{{ l.clicks }}</strong> clic · {{ l.unique_clicks }} persone</span>
                    </div>
                    <p class="muted small ellipsis" [title]="l.destination_url">Porta a {{ l.target_url }} con i parametri UTM, così le visite si vedono anche in Google Analytics.</p>
                    @if (!caption.includes(l.short_url)) {
                      <div class="actions start"><button class="btn ghost small" (click)="addLinkToCaption(l.short_url)"><i class="ti ti-text-plus" aria-hidden="true"></i> Inserisci nel testo</button></div>
                    }
                    @if (item.channel === 'instagram') { <p class="muted small">Su Instagram i link nel testo non sono cliccabili: usalo nello sticker "Link" delle storie o nel link in bio.</p> }
                  } @else if (auth.canEdit()) {
                    <p class="muted small">Crea un link breve da mettere nel post: ogni clic viene contato e lo vedi qui e nella pagina Link e clic.</p>
                    <div class="rewrite-custom">
                      <input [(ngModel)]="linkTarget" placeholder="Pagina di destinazione, es. sito.it/offerta" aria-label="Pagina di destinazione" />
                      <button class="btn ghost small" (click)="createLink()" [disabled]="busy() || !linkTarget.trim()"><i class="ti ti-link-plus" aria-hidden="true"></i> Crea link</button>
                    </div>
                  } @else {
                    <p class="muted small">Nessun link tracciato per questo post.</p>
                  }
                </div>
              </section>
              <section class="step">
                <span class="step-num">3</span>
                <div class="step-body">
                  <h3>Copia il testo</h3>
                  <textarea rows="6" readonly [value]="caption"></textarea>
                  <div class="actions start">
                    <button class="btn ghost small" (click)="copy(caption, 'caption')"><i class="ti" [class]="copied() === 'caption' ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() === 'caption' ? 'Copiato' : 'Copia testo' }}</button>
                  </div>
                </div>
              </section>
              <section class="step">
                <span class="step-num">4</span>
                <div class="step-body">
                  <h3>Apri il social e pubblica</h3>
                  <div class="network-grid">
                    @for (n of networks; track n.key) {
                      <a class="network" [class.main]="n.key === item.channel" [href]="n.url" target="_blank" rel="noopener">
                        <i class="ti" [class]="'ti ' + n.icon" aria-hidden="true"></i>
                        <span><strong>{{ n.label }}</strong><small class="muted">{{ n.hint }}</small></span>
                      </a>
                    }
                  </div>
                </div>
              </section>
            } @else {
              <div class="edit-grid">
                <label>Oggetto<input [(ngModel)]="subject" (ngModelChange)="rebuildEmail()" maxlength="120" /></label>
                <label>Servizio email
                  <select [(ngModel)]="service" (ngModelChange)="rebuildEmail()">
                    @for (s of services; track s[0]) { <option [value]="s[0]">{{ s[1].label }}</option> }
                  </select>
                </label>
              </div>
              @if (emailHtml()) {
                <iframe class="email-preview" title="Anteprima newsletter" sandbox="" [srcdoc]="emailPreview()"></iframe>
              } @else {
                <p class="rewrite-progress"><span class="spinner"></span> Preparo la newsletter…</p>
              }
              <div class="actions start">
                <button class="btn primary small" (click)="downloadHtml()" [disabled]="!emailHtml()"><i class="ti ti-download" aria-hidden="true"></i> Scarica HTML</button>
                <button class="btn ghost small" (click)="copy(emailHtml(), 'html')" [disabled]="!emailHtml()"><i class="ti" [class]="copied() === 'html' ? 'ti ti-check' : 'ti ti-code'" aria-hidden="true"></i> {{ copied() === 'html' ? 'Copiato' : 'Copia HTML' }}</button>
                <a class="btn ghost small" [href]="mailto()"><i class="ti ti-mail-forward" aria-hidden="true"></i> Apri nel programma di posta</a>
              </div>
              <p class="muted small">Per inviarla alla lista dei contatti importa l'HTML come nuova campagna nel servizio email scelto: lì restano liste, consensi e disiscrizioni. Grafica e logo sono incorporati nel file; se il servizio non li mostra (Gmail, per esempio, blocca le immagini incorporate) caricali nella sua libreria e sostituiscili.</p>
            }
          </div>
        </div>

        <footer class="modal-foot">
          @if (item.status === 'published') {
            <span class="published-info">
              <i class="ti ti-circle-check" aria-hidden="true"></i>
              Pubblicato il {{ item.published_at | date: 'd MMM yyyy, HH:mm' }}
              @if (item.published_url) { · <a [href]="item.published_url" target="_blank" rel="noopener">vedi il post</a> }
            </span>
            @if (auth.canEdit()) { <button class="btn ghost small" (click)="unpublish()" [disabled]="busy()">Annulla pubblicazione</button> }
          } @else if (auth.canEdit()) {
            <input class="grow" [(ngModel)]="publishedUrl" placeholder="Link del post pubblicato (facoltativo)" aria-label="Link del post pubblicato" />
            <button class="btn success small" (click)="markPublished()" [disabled]="busy()"><i class="ti ti-check" aria-hidden="true"></i> Segna come pubblicato</button>
          }
          @if (error()) { <div class="alert small full-row">{{ error() }}</div> }
        </footer>
      </div>
    </div>
  `,
})
export class PublishDialogComponent implements OnInit {
  @Input({ required: true }) item!: ContentItem;
  @Input() brand: Brand | null = null;
  @Input() format: 'square' | 'portrait' | 'story' = 'square';
  @Output() closed = new EventEmitter<void>();
  @Output() changed = new EventEmitter<ContentItem>();
  @ViewChild('visualRef') visualRef?: PostVisualComponent;

  readonly auth = inject(AuthService);
  private api = inject(ApiService);
  private sanitizer = inject(DomSanitizer);
  readonly channelLabels = CHANNEL_LABELS;
  readonly services = Object.entries(UNSUBSCRIBE_PLACEHOLDERS) as [EmailService, { label: string }][];
  readonly canShareFiles = typeof navigator !== 'undefined' && !!navigator.canShare
    && navigator.canShare({ files: [new File([''], 'x.png', { type: 'image/png' })] });

  tab = signal<'social' | 'email'>('social');
  copied = signal<string | null>(null);
  busy = signal(false);
  error = signal<string | null>(null);
  emailHtml = signal('');
  emailPreview = signal<SafeHtml>('');
  caption = '';
  subject = '';
  service: EmailService = 'brevo';
  publishedUrl = '';
  link = signal<TrackedLink | null>(null);
  linkTarget = '';
  networks: NetworkLink[] = [];
  private imageDataUrl: string | null = null;
  private logoDataUrl: string | null = null;

  ngOnInit(): void {
    this.caption = publishableText(this.item.body);
    this.subject = this.item.title.slice(0, 120);
    this.publishedUrl = this.item.published_url ?? '';
    this.networks = this.buildNetworks();
    this.linkTarget = this.brand?.website_url ?? '';
    this.api.links({ contentId: this.item.id }).subscribe({ next: (links) => this.link.set(links[0] ?? null), error: () => undefined });
    if (this.item.channel === 'email') this.openEmail();
  }

  createLink(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.createLink({ content_item_id: this.item.id, target_url: this.linkTarget.trim() }).subscribe({
      next: (link) => {
        this.busy.set(false);
        this.link.set(link);
        this.addLinkToCaption(link.short_url);
        this.item = { ...this.item, tracked_link: { short_url: link.short_url, clicks: link.clicks, unique_clicks: link.unique_clicks } };
        this.changed.emit(this.item);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  addLinkToCaption(url: string): void {
    this.caption = `${this.caption.trimEnd()}\n\n${url}`;
    this.networks = this.buildNetworks();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.closed.emit();
  }

  onBackdrop(event: MouseEvent): void {
    if (event.target === event.currentTarget) this.closed.emit();
  }

  downloadPng(): void {
    this.visualRef?.exportPng(this.fileName()).catch(() => this.error.set('Impossibile esportare la grafica.'));
  }

  downloadVideo(): void {
    if (!this.item.video_asset_id) return;
    this.api.assetBlob(this.item.video_asset_id).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${this.fileName()}.mp4`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      },
      error: () => this.error.set('Impossibile scaricare il video.'),
    });
  }

  /**
   * Condivisione nativa (telefono): il video se c'è, altrimenti la grafica.
   * Il testo va negli appunti perché Instagram lo ignora.
   */
  async share(): Promise<void> {
    try {
      const file = this.item.video_asset_id
        ? new File([await firstValueFrom(this.api.assetBlob(this.item.video_asset_id))], `${this.fileName()}.mp4`, { type: 'video/mp4' })
        : new File([await (await fetch(await this.visualRef!.renderPng())).blob()], `${this.fileName()}.png`, { type: 'image/png' });
      await navigator.clipboard?.writeText(this.caption).catch(() => undefined);
      await navigator.share({ files: [file], text: this.caption });
    } catch (err) {
      if ((err as DOMException)?.name !== 'AbortError') this.error.set('Condivisione non riuscita: scarica la grafica e copia il testo.');
    }
  }

  copy(text: string, key: string): void {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied.set(key);
      setTimeout(() => this.copied.set(null), 1500);
    });
  }

  /** La newsletter si genera alla prima apertura della scheda: serve la grafica renderizzata e il logo. */
  async openEmail(): Promise<void> {
    this.tab.set('email');
    if (this.emailHtml()) return;
    // Logo e grafica sono indipendenti: se uno dei due non riesce, la newsletter si genera comunque con l'altro.
    if (this.brand?.logo_asset_id) {
      try {
        this.logoDataUrl = await blobToDataUrl(await firstValueFrom(this.api.assetBlob(this.brand.logo_asset_id)));
      } catch {
        this.logoDataUrl = null;
      }
    }
    try {
      // Aspetta che l'anteprima sia disegnata prima di convertirla in immagine.
      await new Promise((resolve) => setTimeout(resolve, 50));
      this.imageDataUrl = this.visualRef ? await this.visualRef.renderPng(1200) : null;
    } catch {
      this.imageDataUrl = null;
      this.error.set('Non è stato possibile inserire la grafica nella newsletter: scaricala dalla scheda Social e aggiungila nel servizio email.');
    }
    this.rebuildEmail();
  }

  rebuildEmail(): void {
    const html = buildNewsletterHtml({
      brand: this.brand, item: this.item, subject: this.subject || this.item.title,
      imageDataUrl: this.imageDataUrl, logoDataUrl: this.logoDataUrl, service: this.service,
    });
    this.emailHtml.set(html);
    // L'HTML è generato da noi con i testi già sottoposti a escape, e l'iframe è in sandbox senza script.
    this.emailPreview.set(this.sanitizer.bypassSecurityTrustHtml(html));
  }

  downloadHtml(): void {
    const url = URL.createObjectURL(new Blob([this.emailHtml()], { type: 'text/html' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `newsletter-${this.fileName()}.html`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  /** Email veloce dal programma di posta: solo testo (mailto non permette allegati). */
  mailto(): string {
    const site = this.brand?.website_url ? `\n\n${this.brand.website_url}` : '';
    const body = (this.caption + site).slice(0, 1800);
    return `mailto:?subject=${encodeURIComponent(this.subject)}&body=${encodeURIComponent(body)}`;
  }

  markPublished(): void {
    const url = this.publishedUrl.trim() || null;
    this.run(this.api.publishContent(this.item.id, url));
  }

  unpublish(): void {
    this.run(this.api.unpublishContent(this.item.id));
  }

  private run(request: ReturnType<ApiService['publishContent']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (updated) => {
        this.busy.set(false);
        // La risposta di pubblicazione non porta i clic del link: si tengono quelli già noti.
        this.item = { ...updated, tracked_link: this.item.tracked_link };
        this.changed.emit(this.item);
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  private fileName(): string {
    return `${this.brand?.name ?? 'post'}-${this.item.id}`.toLowerCase().replace(/[^a-z0-9]+/g, '-');
  }

  /** Link ai profili del cliente (o alla home del social), con il canale del post per primo. */
  private buildNetworks(): NetworkLink[] {
    const tweet = this.caption.length > 270 ? this.caption.slice(0, 267) + '…' : this.caption;
    const list: NetworkLink[] = [
      { key: 'instagram', label: 'Instagram', icon: 'ti-brand-instagram', url: this.brand?.instagram_url || 'https://www.instagram.com/', hint: this.brand?.instagram_url ? 'Profilo del cliente' : 'Crea il post con il pulsante +' },
      { key: 'facebook', label: 'Facebook', icon: 'ti-brand-facebook', url: this.brand?.facebook_url || 'https://www.facebook.com/', hint: this.brand?.facebook_url ? 'Pagina del cliente' : 'Pubblica dalla pagina' },
      { key: 'linkedin', label: 'LinkedIn', icon: 'ti-brand-linkedin', url: this.brand?.linkedin_url || 'https://www.linkedin.com/feed/', hint: this.brand?.linkedin_url ? 'Pagina del cliente' : 'Pubblica dal feed' },
      { key: 'x', label: 'X', icon: 'ti-brand-x', url: `https://x.com/intent/post?text=${encodeURIComponent(tweet)}`, hint: 'Testo già compilato' },
    ];
    return [...list.filter((n) => n.key === this.item.channel), ...list.filter((n) => n.key !== this.item.channel)];
  }
}

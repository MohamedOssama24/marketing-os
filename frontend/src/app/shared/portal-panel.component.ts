import { Component, Input, OnChanges, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { Observable } from 'rxjs';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { ClientRequest, PortalSettings, apiErrorMessage } from '../core/models';

/** Nella scheda cliente: link del portale e richieste arrivate dal cliente. */
@Component({
  selector: 'app-portal-panel',
  standalone: true,
  imports: [DatePipe],
  template: `
    <section class="card growth-card" id="portal">
      <div class="strategy-head">
        <h2><i class="ti ti-door-enter" aria-hidden="true"></i> Portale del cliente</h2>
        @if (openCount()) { <span class="count-pill">{{ openCount() }} {{ openCount() === 1 ? 'richiesta' : 'richieste' }}</span> }
      </div>
      <p class="muted small">Un link senza account dove il cliente approva i post (o chiede modifiche), vede cosa uscirà, apre i report e ti scrive.</p>
      @if (error()) { <div class="alert">{{ error() }}</div> }

      @if (settings(); as s) {
        @if (s.token) {
          <div class="share-box">
            <code class="ellipsis" [title]="url()">{{ url() }}</code>
            <button class="btn primary small" (click)="copy()"><i class="ti" [class]="copied() ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() ? 'Copiato' : 'Copia link' }}</button>
            <a class="btn ghost small" [href]="url()" target="_blank" rel="noopener" title="Apri come lo vede il cliente" aria-label="Apri come lo vede il cliente"><i class="ti ti-external-link" aria-hidden="true"></i></a>
          </div>
          @if (auth.canEdit()) { <button class="link-btn" (click)="run(api.disablePortal(brandId), 'Disattivare il portale? Il link smetterà di funzionare; riattivandolo sarà diverso.')" [disabled]="busy()">Disattiva il link</button> }
        } @else if (auth.canEdit()) {
          <button class="btn primary small" (click)="run(api.enablePortal(brandId))" [disabled]="busy()"><i class="ti ti-link" aria-hidden="true"></i> Attiva il portale</button>
        }

        @if (s.requests.length) {
          <div class="requests">
            @for (r of s.requests; track r.id) {
              <div class="request" [class.done]="r.status === 'done'">
                <div class="grow">
                  <p class="pre">{{ r.message }}</p>
                  <small class="muted">{{ r.name || 'Il cliente' }} · {{ r.created_at.replace(' ', 'T') | date: 'd MMM, HH:mm' }}</small>
                </div>
                @if (auth.canEdit()) {
                  <button class="icon-btn" (click)="toggle(r)" [title]="r.status === 'done' ? 'Riapri' : 'Segna come fatta'" [attr.aria-label]="r.status === 'done' ? 'Riapri' : 'Segna come fatta'">
                    <i class="ti" [class]="r.status === 'done' ? 'ti ti-arrow-back-up' : 'ti ti-check'" aria-hidden="true"></i>
                  </button>
                }
              </div>
            }
          </div>
        }
      }
    </section>
  `,
})
export class PortalPanelComponent implements OnChanges {
  @Input({ required: true }) brandId!: number;
  readonly api = inject(ApiService);
  readonly auth = inject(AuthService);

  settings = signal<PortalSettings | null>(null);
  busy = signal(false);
  copied = signal(false);
  error = signal<string | null>(null);
  readonly url = computed(() => (this.settings()?.token ? `${location.origin}/portal/${this.settings()!.token}` : ''));
  readonly openCount = computed(() => this.settings()?.requests.filter((r) => r.status === 'open').length ?? 0);

  ngOnChanges(): void {
    this.api.portal(this.brandId).subscribe({ next: (s) => this.settings.set(s), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  run(request: Observable<PortalSettings>, question?: string): void {
    if (question && !confirm(question)) return;
    this.busy.set(true);
    request.subscribe({
      next: (s) => { this.settings.set(s); this.busy.set(false); },
      error: (err) => { this.error.set(apiErrorMessage(err)); this.busy.set(false); },
    });
  }

  toggle(r: ClientRequest): void {
    const status = r.status === 'done' ? 'open' : 'done';
    this.api.updateClientRequest(r.id, status).subscribe({
      next: () => this.settings.update((s) => s && { ...s, requests: s.requests.map((x) => (x.id === r.id ? { ...x, status } : x)) }),
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  copy(): void {
    navigator.clipboard?.writeText(this.url()).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }
}

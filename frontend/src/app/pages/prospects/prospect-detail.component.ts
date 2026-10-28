import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Observable, switchMap } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { PROSPECT_STATUS_LABELS, Prospect, ProspectStatus, apiErrorMessage } from '../../core/models';
import { AuditViewComponent } from '../../shared/audit-view.component';

/** Audit di un potenziale cliente: proposta, link da mandare e passaggio a cliente. */
@Component({
  selector: 'app-prospect-detail',
  standalone: true,
  imports: [AuditViewComponent, DatePipe, FormsModule, RouterLink],
  template: `
    <a class="back no-print" routerLink="/prospects"><i class="ti ti-arrow-left" aria-hidden="true"></i> Potenziali clienti</a>
    @if (error()) { <div class="alert no-print">{{ error() }}</div> }

    @if (prospect(); as p) {
      <div class="page-head no-print">
        <div>
          <h1>{{ p.name || host(p.url) }}</h1>
          <p class="muted"><a [href]="p.url" target="_blank" rel="noopener">{{ p.url }}</a></p>
        </div>
        @if (auth.canEdit()) {
          <div class="head-controls">
            <select [ngModel]="p.status" (ngModelChange)="setStatus($event)" aria-label="Stato della trattativa">
              @for (s of statuses; track s) { <option [ngValue]="s">{{ statusLabels[s] }}</option> }
            </select>
            <button class="icon-btn" (click)="reanalyze()" [disabled]="busy() || p.analyzing" title="Rifai l'analisi" aria-label="Rifai l'analisi"><i class="ti ti-refresh" aria-hidden="true"></i></button>
            <button class="icon-btn danger" (click)="remove()" [disabled]="busy()" title="Elimina" aria-label="Elimina"><i class="ti ti-trash" aria-hidden="true"></i></button>
          </div>
        }
      </div>

      @if (p.analyzing) {
        <div class="card analyzing-card no-print">
          <span class="spinner"></span>
          <div><strong>Analisi del sito in corso…</strong><p class="muted small">Leggo le pagine, controllo titoli, contatti e social, poi l'AI prepara problemi, idee di post e servizi. Di solito meno di un minuto.</p></div>
        </div>
      } @else if (p.job_status === 'failed' && !p.audit) {
        <div class="card empty no-print">
          <i class="ti ti-world-off empty-icon" aria-hidden="true"></i>
          <strong>Non sono riuscito a leggere il sito</strong>
          <p class="muted">{{ p.job_error }}</p>
          @if (auth.canEdit()) { <button class="btn primary" (click)="reanalyze()">Riprova</button> }
        </div>
      }

      @if (p.audit; as audit) {
        <div class="two-col prospect-layout">
          <app-audit-view [audit]="audit" [name]="p.name" [agencyName]="agency()" [monthlyFee]="p.monthly_fee" [agencyNote]="p.agency_note" />

          <aside class="side-col no-print">
            @if (auth.canEdit()) {
              <section class="card proposal-card">
                <h2><i class="ti ti-file-dollar" aria-hidden="true"></i> La tua proposta</h2>
                <label>Canone mensile proposto (€)<input type="number" min="0" step="10" [(ngModel)]="fee" placeholder="Es. 450" /></label>
                <label>Messaggio di chiusura<textarea rows="5" [(ngModel)]="note" maxlength="3000" [placeholder]="audit.pitch || 'Due righe personali per invitarli a sentirvi.'"></textarea></label>
                <p class="muted small">Se lo lasci vuoto, l'audit chiude con il testo proposto dall'AI.</p>
                <button class="btn" (click)="saveProposal()" [disabled]="busy() || !dirty()"><i class="ti ti-device-floppy" aria-hidden="true"></i> {{ dirty() ? 'Salva proposta' : 'Salvata' }}</button>
              </section>
            }

            <section class="card proposal-card">
              <h2><i class="ti ti-send" aria-hidden="true"></i> Manda l'audit</h2>
              @if (p.share_token) {
                <div class="share-box">
                  <code class="ellipsis" [title]="shareUrl()">{{ shareUrl() }}</code>
                  <button class="btn primary" (click)="copy()"><i class="ti" [class]="copied() ? 'ti ti-check' : 'ti ti-copy'" aria-hidden="true"></i> {{ copied() ? 'Copiato' : 'Copia' }}</button>
                </div>
                <p class="muted small">
                  @if (p.views) {
                    <i class="ti ti-eye-check" aria-hidden="true"></i> Aperto {{ p.views === 1 ? 'una volta' : p.views + ' volte' }}, l'ultima il {{ p.last_viewed_at!.replace(' ', 'T') | date: 'd MMM, HH:mm' }}: è il momento giusto per chiamare.
                  } @else {
                    Non ancora aperto. Chi ha il link vede solo questo audit.
                  }
                </p>
                <div class="actions">
                  <a class="btn ghost small" [href]="shareUrl()" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i> Anteprima</a>
                  @if (auth.canEdit()) { <button class="link-btn" (click)="unshare()" [disabled]="busy()">Disattiva il link</button> }
                </div>
              } @else if (auth.canEdit()) {
                <p class="muted small">Un link che si apre senza account, con il tuo nome e la proposta. Vedrai quando lo apre.</p>
                <button class="btn primary" (click)="run(api.shareProspect(p.id))" [disabled]="busy()"><i class="ti ti-link" aria-hidden="true"></i> Crea il link</button>
              }
              <button class="btn ghost" (click)="print()"><i class="ti ti-file-type-pdf" aria-hidden="true"></i> Scarica PDF</button>
            </section>

            @if (auth.canEdit()) {
              <section class="card proposal-card">
                <h2><i class="ti ti-confetti" aria-hidden="true"></i> Ha accettato?</h2>
                @if (p.brand_profile_id) {
                  <p class="muted small">È già tra i tuoi clienti.</p>
                  <a class="btn primary" [routerLink]="['/clients', p.brand_profile_id]">Apri la scheda cliente</a>
                } @else if (converting()) {
                  <p class="rewrite-progress"><span class="spinner"></span> Creo il cliente con brand kit, logo e foto dal sito…</p>
                } @else {
                  <p class="muted small">Diventa cliente con un clic: brand kit, colori, logo e foto vengono letti dallo stesso sito.</p>
                  <button class="btn success" (click)="convert()" [disabled]="busy()"><i class="ti ti-user-plus" aria-hidden="true"></i> Trasforma in cliente</button>
                }
              </section>
            }
          </aside>
        </div>
      }
    } @else if (!error()) {
      <div class="card muted center">Caricamento…</div>
    }
  `,
})
export class ProspectDetailComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  readonly statusLabels = PROSPECT_STATUS_LABELS;
  readonly statuses: ProspectStatus[] = ['new', 'sent', 'won', 'lost'];
  prospect = signal<Prospect | null>(null);
  busy = signal(false);
  converting = signal(false);
  copied = signal(false);
  error = signal<string | null>(null);
  fee: number | null = null;
  note = '';

  readonly shareUrl = computed(() => {
    const token = this.prospect()?.share_token;
    return token ? `${location.origin}/audit/${token}` : '';
  });
  readonly agency = computed(() => this.auth.user()?.organization_name ?? '');

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    this.api.prospect(id).subscribe({ next: (p) => this.show(p), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  dirty(): boolean {
    const p = this.prospect();
    return !!p && ((this.fee ?? null) !== p.monthly_fee || this.note.trim() !== (p.agency_note ?? ''));
  }

  saveProposal(): void {
    const p = this.prospect()!;
    const fee = this.fee === null || (this.fee as unknown) === '' ? null : Number(this.fee);
    this.run(this.api.updateProspect(p.id, { monthly_fee: fee, agency_note: this.note.trim() || null }));
  }

  setStatus(status: ProspectStatus): void {
    this.run(this.api.updateProspect(this.prospect()!.id, { status }));
  }

  reanalyze(): void {
    this.run(this.api.reanalyzeProspect(this.prospect()!.id));
  }

  unshare(): void {
    if (confirm('Disattivare il link? Chi lo ha ricevuto non vedrà più l\'audit.')) this.run(this.api.unshareProspect(this.prospect()!.id));
  }

  remove(): void {
    if (!confirm('Eliminare questo potenziale cliente e il suo audit?')) return;
    this.api.deleteProspect(this.prospect()!.id).subscribe({ next: () => this.router.navigate(['/prospects']), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  convert(): void {
    const id = this.prospect()!.id;
    this.converting.set(true);
    this.error.set(null);
    this.api.convertProspect(id).pipe(switchMap((job) => this.api.waitForJob(job.id)), takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (job) => {
        this.converting.set(false);
        if (job.status === 'failed') this.error.set(job.error ?? 'Non è stato possibile creare il cliente.');
        else if (job.result?.brand_id) this.router.navigate(['/clients', job.result.brand_id]);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.converting.set(false);
      },
    });
  }

  copy(): void {
    navigator.clipboard?.writeText(this.shareUrl()).then(() => {
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    });
  }

  print(): void {
    window.print();
  }

  host(url: string): string {
    try { return new URL(url).host.replace(/^www\./, ''); } catch { return url; }
  }

  run(request: Observable<Prospect>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (p) => {
        this.show(p);
        this.busy.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.busy.set(false);
      },
    });
  }

  private show(p: Prospect): void {
    this.prospect.set(p);
    this.fee = p.monthly_fee;
    this.note = p.agency_note ?? '';
    if (p.analyzing && p.job_id) {
      this.api.waitForJob(p.job_id).pipe(switchMap(() => this.api.prospect(p.id)), takeUntilDestroyed(this.destroyRef)).subscribe((fresh) => this.show(fresh));
    }
  }
}

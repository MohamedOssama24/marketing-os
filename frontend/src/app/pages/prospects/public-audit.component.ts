import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Title } from '@angular/platform-browser';
import { ApiService } from '../../core/api.service';
import { PublicAudit } from '../../core/models';
import { AuditViewComponent } from '../../shared/audit-view.component';

/** L'audit come lo apre il potenziale cliente dal link ricevuto: nessun accesso, nessun menu dell'app. */
@Component({
  selector: 'app-public-audit',
  standalone: true,
  imports: [AuditViewComponent],
  template: `
    <div class="public-report">
      @if (data(); as d) {
        <div class="public-bar no-print">
          <span class="muted small">Analisi preparata da {{ d.agency.name }}</span>
          <button class="btn" (click)="print()"><i class="ti ti-file-type-pdf" aria-hidden="true"></i> Scarica PDF</button>
        </div>
        <app-audit-view [audit]="d.audit" [name]="d.name" [agencyName]="d.agency.name" [monthlyFee]="d.monthly_fee" [agencyNote]="d.agency_note" />
      } @else if (missing()) {
        <div class="card empty">
          <i class="ti ti-file-off empty-icon" aria-hidden="true"></i>
          <strong>Analisi non disponibile</strong>
          <p class="muted">Il link non è più attivo. Chiedi un nuovo link a chi te lo ha inviato.</p>
        </div>
      } @else {
        <div class="card muted center">Caricamento…</div>
      }
    </div>
  `,
})
export class PublicAuditComponent implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private title = inject(Title);

  data = signal<PublicAudit | null>(null);
  missing = signal(false);

  ngOnInit(): void {
    this.api.publicAudit(this.route.snapshot.paramMap.get('token') ?? '').subscribe({
      next: (d) => {
        this.data.set(d);
        this.title.setTitle(`${d.name || d.audit.name} · Analisi del sito`);
      },
      error: () => this.missing.set(true),
    });
  }

  print(): void {
    window.print();
  }
}

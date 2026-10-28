import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Title } from '@angular/platform-browser';
import { ApiService } from '../../core/api.service';
import { ClientReport } from '../../core/models';
import { ReportViewComponent } from '../../shared/report-view.component';

/** Il report come lo apre il cliente dal link ricevuto: nessun accesso, nessun menu dell'app. */
@Component({
  selector: 'app-public-report',
  standalone: true,
  imports: [ReportViewComponent],
  template: `
    <div class="public-report">
      @if (report(); as r) {
        <div class="public-bar no-print">
          <span class="muted small">Report di {{ r.period_label }} · {{ r.agency.name }}</span>
          <button class="btn" (click)="print()"><i class="ti ti-file-type-pdf" aria-hidden="true"></i> Scarica PDF</button>
        </div>
        <app-report-view [report]="r" [logoUrl]="r.client.has_logo ? logoUrl : null" />
      } @else if (missing()) {
        <div class="card empty">
          <i class="ti ti-file-off empty-icon" aria-hidden="true"></i>
          <strong>Report non disponibile</strong>
          <p class="muted">Il link non è più attivo. Chiedi un nuovo link a chi te lo ha inviato.</p>
        </div>
      } @else {
        <div class="card muted center">Caricamento del report…</div>
      }
    </div>
  `,
})
export class PublicReportComponent implements OnInit {
  private api = inject(ApiService);
  private route = inject(ActivatedRoute);
  private title = inject(Title);

  report = signal<ClientReport | null>(null);
  missing = signal(false);
  logoUrl = '';

  ngOnInit(): void {
    const token = this.route.snapshot.paramMap.get('token') ?? '';
    this.logoUrl = this.api.publicReportLogoUrl(token);
    this.api.publicReport(token).subscribe({
      next: (r) => {
        this.report.set(r);
        this.title.setTitle(`${r.client.name} · Report di ${r.period_label}`);
      },
      error: () => this.missing.set(true),
    });
  }

  print(): void {
    window.print();
  }
}

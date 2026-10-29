import { Component, OnInit, inject, signal } from '@angular/core';
import { LOCALE } from '../../i18n/i18n';
import { CurrencyPipe, DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { BillingClient, INVOICE_STATUS_LABELS, Invoice, InvoiceDetail, InvoiceMonth, InvoiceStatus, apiErrorMessage } from '../../core/models';

const STATUS_BADGE: Record<InvoiceStatus, string> = { to_invoice: 'in_review', invoiced: 'scheduled', paid: 'approved' };

/**
 * Fatturazione ai clienti: ogni mese una fattura per cliente con canone, sponsorizzate e commissione.
 * La fattura elettronica si emette nel gestionale; qui si prepara, si numera e si segue fino al pagamento.
 */
@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [CurrencyPipe, DatePipe, DecimalPipe, FormsModule],
  template: `
    <div class="page-head">
      <div>
        <h1>Fatturazione</h1>
        <p class="muted">Canoni, sponsorizzate da rifatturare e pagamenti dei clienti, mese per mese.</p>
      </div>
      <div class="head-controls">
        <div class="month-nav">
          <button class="icon-btn" (click)="shiftMonth(-1)" aria-label="Mese precedente"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
          <strong>{{ periodLabel() }}</strong>
          <button class="icon-btn" (click)="shiftMonth(1)" aria-label="Mese successivo"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
        </div>
      </div>
    </div>

    <div class="filters tabs">
      <button class="chip" [class.active]="tab() === 'invoices'" (click)="tab.set('invoices')"><i class="ti ti-file-invoice" aria-hidden="true"></i> Fatture</button>
      <button class="chip" [class.active]="tab() === 'clients'" (click)="openClientsTab()"><i class="ti ti-building-store" aria-hidden="true"></i> Canoni e dati dei clienti</button>
    </div>

    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (message()) { <div class="notice success"><i class="ti ti-circle-check" aria-hidden="true"></i>{{ message() }}</div> }

    @if (tab() === 'invoices') {
      @if (month(); as m) {
        <div class="metrics">
          <div class="metric"><span class="metric-icon"><i class="ti ti-file-dots" aria-hidden="true"></i></span><span class="metric-label">Da fatturare · {{ m.totals.to_invoice.count }}</span><strong>{{ m.totals.to_invoice.total | currency: 'EUR' }}</strong></div>
          <div class="metric"><span class="metric-icon"><i class="ti ti-send" aria-hidden="true"></i></span><span class="metric-label">Fatturate da incassare · {{ m.totals.invoiced.count }}</span><strong>{{ m.totals.invoiced.total | currency: 'EUR' }}</strong></div>
          <div class="metric"><span class="metric-icon"><i class="ti ti-circle-check" aria-hidden="true"></i></span><span class="metric-label">Pagate · {{ m.totals.paid.count }}</span><strong>{{ m.totals.paid.total | currency: 'EUR' }}</strong></div>
          <div class="metric" [class.warn]="m.unbilled_ad_spend.count > 0"><span class="metric-icon"><i class="ti ti-speakerphone" aria-hidden="true"></i></span><span class="metric-label">Sponsorizzate non in fattura</span>
            <strong>{{ m.unbilled_ad_spend.spent | currency: 'EUR' }}<small class="muted"> · {{ m.unbilled_ad_spend.count }} righe</small></strong></div>
        </div>

        <div class="section-head">
          <h2>Fatture di {{ periodLabel() }}</h2>
          <div class="actions">
            <button class="btn ghost" (click)="exportCsv()" [disabled]="!m.invoices.length"><i class="ti ti-download" aria-hidden="true"></i> Esporta CSV per il commercialista</button>
            <button class="btn primary" (click)="prepare()" [disabled]="busy()"><i class="ti ti-wand" aria-hidden="true"></i> Prepara le fatture del mese</button>
          </div>
        </div>
        <div class="card table-card">
          <table class="admin-table">
            <thead><tr><th>Cliente</th><th class="num">Sponsorizzate</th><th class="num">Imponibile</th><th class="num">IVA</th><th class="num">Totale</th><th>Stato</th></tr></thead>
            <tbody>
              @for (i of m.invoices; track i.id) {
                <tr class="clickable" tabindex="0" (click)="open(i)" (keydown.enter)="open(i)">
                  <td><span class="swatch small" [style.background]="i.brand_color ?? '#5B4BDB'"></span> <strong>{{ i.brand_name }}</strong><br />
                    <small class="muted">{{ i.lines_count }} voci@if (i.number) { · n. {{ i.number }} del {{ i.issued_on | date: 'd/M/yyyy' }}}</small></td>
                  <td class="num">{{ i.ad_spend_total ? (i.ad_spend_total | currency: 'EUR') : '—' }}</td>
                  <td class="num">{{ i.subtotal | currency: 'EUR' }}</td>
                  <td class="num muted">{{ i.vat_amount | currency: 'EUR' }}</td>
                  <td class="num"><strong>{{ i.total | currency: 'EUR' }}</strong></td>
                  <td><span class="badge" [attr.data-status]="badge[i.status]">{{ labels[i.status] }}</span>
                    @if (i.status === 'invoiced' && i.due_on) { <br /><small class="muted" [class.error-text]="isLate(i)">scade il {{ i.due_on | date: 'd/M' }}</small> }</td>
                </tr>
              } @empty {
                <tr><td colspan="6" class="muted center">{{ loading() ? 'Caricamento…' : 'Nessuna fattura. Imposta i canoni dei clienti o registra le sponsorizzate, poi clicca “Prepara le fatture del mese”.' }}</td></tr>
              }
            </tbody>
          </table>
        </div>
        <p class="muted small billing-note"><i class="ti ti-info-circle" aria-hidden="true"></i>
          Marketing OS prepara gli importi e segue lo stato dei pagamenti. La fattura elettronica va emessa nel tuo gestionale (o dal commercialista) con il CSV esportato; poi segnala qui come fatturata con il suo numero.</p>
      }
    } @else {
      <div class="card table-card">
        <table class="admin-table">
          <thead><tr><th>Cliente</th><th class="num">Canone mensile</th><th class="num">Commissione sulle sponsorizzate</th><th>Dati di fatturazione</th><th></th></tr></thead>
          <tbody>
            @for (c of clients(); track c.id) {
              <tr>
                <td><span class="swatch small" [style.background]="c.primary_color ?? '#5B4BDB'"></span> <strong>{{ c.name }}</strong></td>
                <td class="num">{{ c.monthly_fee ? (c.monthly_fee | currency: 'EUR') : '—' }}</td>
                <td class="num">{{ c.ad_fee_percent ? (c.ad_fee_percent | number: '1.0-2') + '%' : '—' }}</td>
                <td>
                  @if (c.billing_name || c.vat_number) {
                    {{ c.billing_name ?? c.name }}<br /><small class="muted">{{ c.vat_number ? 'P.IVA ' + c.vat_number : 'CF ' + (c.tax_code ?? '—') }} · SDI {{ c.sdi_code ?? (c.pec ? 'via PEC' : '—') }}</small>
                  } @else { <span class="muted small"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Da completare</span> }
                </td>
                <td class="row-actions"><button class="btn ghost small" (click)="editClient(c)"><i class="ti ti-pencil" aria-hidden="true"></i> Modifica</button></td>
              </tr>
            } @empty {
              <tr><td colspan="5" class="muted center">{{ loading() ? 'Caricamento…' : 'Nessun cliente.' }}</td></tr>
            }
          </tbody>
        </table>
      </div>
    }

    @if (clientDraft(); as c) {
      <div class="modal-backdrop" (click)="clientDraft.set(null)">
        <form class="modal narrow" (click)="$event.stopPropagation()" (ngSubmit)="saveClient(c)">
          <div class="modal-head"><h2>{{ c.name }}</h2><span class="muted">Canone e dati di fatturazione</span>
            <button type="button" class="icon-btn" (click)="clientDraft.set(null)" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button></div>
          <div class="modal-body form-grid">
            <label>Canone mensile (€, IVA esclusa)<input type="number" min="0" step="0.01" [(ngModel)]="c.monthly_fee" name="fee" placeholder="Es. 600" /></label>
            <label>Commissione sulle sponsorizzate (%)<input type="number" min="0" max="100" step="0.5" [(ngModel)]="c.ad_fee_percent" name="percent" /></label>
            <label class="full">Ragione sociale<input [(ngModel)]="c.billing_name" name="billing_name" maxlength="190" /></label>
            <label>Partita IVA<input [(ngModel)]="c.vat_number" name="vat" maxlength="30" /></label>
            <label>Codice fiscale<input [(ngModel)]="c.tax_code" name="tax" maxlength="30" /></label>
            <label>Codice destinatario SDI<input [(ngModel)]="c.sdi_code" name="sdi" maxlength="7" placeholder="7 caratteri" /></label>
            <label>PEC<input type="email" [(ngModel)]="c.pec" name="pec" maxlength="190" /></label>
            <label class="full">Indirizzo<input [(ngModel)]="c.billing_address" name="address" maxlength="500" placeholder="Via, CAP, città, provincia" /></label>
            <label class="full">Email per le fatture<input type="email" [(ngModel)]="c.billing_email" name="email" maxlength="190" /></label>
          </div>
          @if (formError()) { <div class="alert modal-alert">{{ formError() }}</div> }
          <div class="modal-foot"><span class="grow muted small">Le bozze aperte si aggiornano subito.</span>
            <button type="button" class="btn ghost" (click)="clientDraft.set(null)">Annulla</button>
            <button class="btn primary" [disabled]="busy()">Salva</button></div>
        </form>
      </div>
    }

    @if (detail(); as d) {
      <div class="modal-backdrop" (click)="closeDetail()">
        <div class="modal" (click)="$event.stopPropagation()">
          <div class="modal-head">
            <h2>{{ d.brand_name }}</h2>
            <span class="muted">{{ periodLabel() }}@if (d.number) { · fattura n. {{ d.number }} del {{ d.issued_on | date: 'd/M/yyyy' }}}</span>
            <span class="badge big" [attr.data-status]="badge[d.status]">{{ labels[d.status] }}</span>
            <button class="icon-btn" (click)="closeDetail()" aria-label="Chiudi"><i class="ti ti-x" aria-hidden="true"></i></button>
          </div>
          <div class="modal-body invoice-grid">
            <div class="invoice-main">
              <table class="invoice-lines">
                <tbody>
                  @for (l of d.lines; track l.id) {
                    <tr>
                      <td>@if (l.kind === 'other') { <span class="tag">Extra</span> }{{ l.description }}</td>
                      <td class="num">{{ l.amount | currency: 'EUR' }}</td>
                      <td class="row-actions">@if (d.status === 'to_invoice' && l.kind === 'other') {
                        <button class="icon-btn" (click)="run(api.deleteInvoiceLine(d.id, l.id))" aria-label="Togli voce"><i class="ti ti-x" aria-hidden="true"></i></button> }</td>
                    </tr>
                  }
                </tbody>
                <tfoot>
                  <tr><td>Imponibile</td><td class="num">{{ d.subtotal | currency: 'EUR' }}</td><td></td></tr>
                  <tr><td>IVA {{ d.vat_rate | number: '1.0-2' }}%</td><td class="num">{{ d.vat_amount | currency: 'EUR' }}</td><td></td></tr>
                  <tr class="grand"><td>Totale</td><td class="num">{{ d.total | currency: 'EUR' }}</td><td></td></tr>
                </tfoot>
              </table>
              @if (d.status === 'to_invoice') {
                <form class="add-line" (ngSubmit)="addLine(d)">
                  <input [(ngModel)]="lineDescription" name="desc" placeholder="Voce extra, es. Servizio fotografico" maxlength="300" aria-label="Descrizione" />
                  <input type="number" step="0.01" [(ngModel)]="lineAmount" name="amount" placeholder="€" aria-label="Importo" />
                  <button class="btn ghost small" [disabled]="!lineDescription.trim() || !lineAmount">Aggiungi voce</button>
                </form>
                <p class="muted small">Per uno sconto inserisci un importo negativo. Canone, sponsorizzate e commissione si ricalcolano da soli.</p>
              }
            </div>
            <aside class="invoice-side">
              <h3>Intestatario</h3>
              @if (d.client.billing_name || d.client.vat_number || d.client.tax_code) {
                <p class="small">{{ d.client.billing_name ?? d.client.name }}<br />
                  @if (d.client.vat_number) { P.IVA {{ d.client.vat_number }}<br /> }
                  @if (d.client.tax_code) { CF {{ d.client.tax_code }}<br /> }
                  @if (d.client.billing_address) { {{ d.client.billing_address }}<br /> }
                  SDI {{ d.client.sdi_code ?? '—' }}@if (d.client.pec) { · PEC {{ d.client.pec }} }</p>
              } @else {
                <p class="small error-text"><i class="ti ti-alert-triangle" aria-hidden="true"></i> Mancano i dati di fatturazione del cliente.</p>
              }
              <button class="link-btn" (click)="editClient(d.client)">Modifica dati e canone</button>
              @if (d.status === 'to_invoice') {
                <label>Aliquota IVA (%)<input type="number" min="0" max="100" step="1" [(ngModel)]="vatRate" (change)="run(api.updateInvoice(d.id, { vat_rate: vatRate }))" /></label>
                <label>Note<textarea rows="2" [(ngModel)]="notes" maxlength="1000" (change)="run(api.updateInvoice(d.id, { notes: notes }))"></textarea></label>
              } @else if (d.notes) {
                <p class="small muted">{{ d.notes }}</p>
              }
              @if (d.paid_on) { <p class="small"><i class="ti ti-circle-check" aria-hidden="true"></i> Pagata il {{ d.paid_on | date: 'd MMMM yyyy' }}</p> }
            </aside>
          </div>
          @if (formError()) { <div class="alert modal-alert">{{ formError() }}</div> }
          <div class="modal-foot">
            @switch (d.status) {
              @case ('to_invoice') {
                <button class="btn ghost small" (click)="removeDraft(d)" [disabled]="busy()"><i class="ti ti-trash" aria-hidden="true"></i> Elimina bozza</button>
                <span class="grow"></span>
                <label class="inline-field">Numero<input [(ngModel)]="issueNumber" placeholder="12/2026" maxlength="40" /></label>
                <label class="inline-field">Data<input type="date" [(ngModel)]="issueDate" /></label>
                <label class="inline-field">Scadenza<input type="date" [(ngModel)]="dueDate" /></label>
                <button class="btn primary" (click)="issue(d)" [disabled]="busy() || !issueNumber.trim() || !d.total"><i class="ti ti-send" aria-hidden="true"></i> Segna come fatturata</button>
              }
              @case ('invoiced') {
                <button class="btn ghost small" (click)="run(api.reopenInvoice(d.id))" [disabled]="busy()"><i class="ti ti-arrow-back-up" aria-hidden="true"></i> Riapri per correggerla</button>
                <span class="grow"></span>
                <label class="inline-field">Pagata il<input type="date" [(ngModel)]="paidDate" /></label>
                <button class="btn success" (click)="run(api.markInvoicePaid(d.id, paidDate))" [disabled]="busy()"><i class="ti ti-circle-check" aria-hidden="true"></i> Segna come pagata</button>
              }
              @case ('paid') {
                <button class="btn ghost small" (click)="run(api.reopenInvoice(d.id))" [disabled]="busy()"><i class="ti ti-arrow-back-up" aria-hidden="true"></i> Annulla il pagamento</button>
              }
            }
          </div>
        </div>
      </div>
    }
  `,
})
export class BillingComponent implements OnInit {
  readonly api = inject(ApiService);

  readonly labels = INVOICE_STATUS_LABELS;
  readonly badge = STATUS_BADGE;

  /** Si parte dal mese scorso: è quello da fatturare. */
  period = signal(previousMonth());
  tab = signal<'invoices' | 'clients'>('invoices');
  month = signal<InvoiceMonth | null>(null);
  clients = signal<BillingClient[]>([]);
  detail = signal<InvoiceDetail | null>(null);
  clientDraft = signal<BillingClient | null>(null);
  loading = signal(true);
  busy = signal(false);
  error = signal<string | null>(null);
  formError = signal<string | null>(null);
  message = signal<string | null>(null);
  lineDescription = '';
  lineAmount: number | null = null;
  vatRate = 22;
  notes = '';
  issueNumber = '';
  issueDate = today();
  dueDate = '';
  paidDate = today();

  ngOnInit(): void {
    this.load();
  }

  periodLabel(): string {
    const [y, m] = this.period().split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(LOCALE, { month: 'long', year: 'numeric' });
  }

  shiftMonth(delta: number): void {
    const [y, m] = this.period().split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    this.period.set(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
    this.message.set(null);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.api.invoices(this.period()).subscribe({
      next: (m) => {
        this.month.set(m);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(apiErrorMessage(err));
        this.loading.set(false);
      },
    });
  }

  openClientsTab(): void {
    this.tab.set('clients');
    this.loadClients();
  }

  loadClients(): void {
    this.api.billingClients().subscribe({ next: (c) => this.clients.set(c), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  prepare(): void {
    this.busy.set(true);
    this.error.set(null);
    this.api.prepareInvoices(this.period()).subscribe({
      next: (r) => {
        this.busy.set(false);
        const parts = [`${r.created} nuove`, `${r.updated} aggiornate`];
        if (r.skipped) parts.push(`${r.skipped} già emesse lasciate com'erano`);
        this.message.set(r.created + r.updated + r.skipped ? `Fatture preparate: ${parts.join(', ')}.` : 'Nessun cliente con canone o sponsorizzate da fatturare in questo mese.');
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(apiErrorMessage(err));
      },
    });
  }

  open(invoice: Invoice): void {
    this.formError.set(null);
    this.api.invoice(invoice.id).subscribe({ next: (d) => this.show(d), error: (err) => this.error.set(apiErrorMessage(err)) });
  }

  private show(d: InvoiceDetail): void {
    this.detail.set(d);
    this.vatRate = d.vat_rate;
    this.notes = d.notes ?? '';
    this.issueNumber = d.number ?? '';
    this.dueDate = d.due_on ?? '';
  }

  closeDetail(): void {
    this.detail.set(null);
    this.load();
  }

  /** Esegue un'azione sulla fattura aperta e mostra il risultato aggiornato. */
  run(request: Observable<InvoiceDetail>, after?: () => void): void {
    this.busy.set(true);
    this.formError.set(null);
    request.subscribe({
      next: (d) => {
        this.busy.set(false);
        this.show(d);
        after?.();
      },
      error: (err) => {
        this.busy.set(false);
        this.formError.set(apiErrorMessage(err));
      },
    });
  }

  addLine(d: InvoiceDetail): void {
    this.run(this.api.addInvoiceLine(d.id, this.lineDescription.trim(), Number(this.lineAmount)), () => {
      this.lineDescription = '';
      this.lineAmount = null;
    });
  }

  issue(d: InvoiceDetail): void {
    this.run(this.api.issueInvoice(d.id, { number: this.issueNumber.trim(), issued_on: this.issueDate, due_on: this.dueDate || null }));
  }

  removeDraft(d: InvoiceDetail): void {
    if (!confirm(`Eliminare la bozza di ${d.brand_name}? Le sponsorizzate tornano da fatturare.`)) return;
    this.api.deleteInvoice(d.id).subscribe({ next: () => this.closeDetail(), error: (err) => this.formError.set(apiErrorMessage(err)) });
  }

  editClient(c: BillingClient): void {
    this.formError.set(null);
    this.clientDraft.set({ ...c });
  }

  saveClient(c: BillingClient): void {
    this.busy.set(true);
    this.formError.set(null);
    const { id, name, primary_color, ...data } = c;
    this.api.updateBillingClient(id, data).subscribe({
      next: () => {
        this.busy.set(false);
        this.clientDraft.set(null);
        if (this.tab() === 'clients') this.loadClients();
        const open = this.detail();
        if (open) this.run(this.api.invoice(open.id));
        this.load();
      },
      error: (err) => {
        this.busy.set(false);
        this.formError.set(apiErrorMessage(err));
      },
    });
  }

  exportCsv(): void {
    this.api.invoicesCsv(this.period()).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `fatture-${this.period()}.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      },
      error: (err) => this.error.set(apiErrorMessage(err)),
    });
  }

  isLate(i: Invoice): boolean {
    return !!i.due_on && i.due_on < today();
  }
}

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function previousMonth(): string {
  const d = new Date();
  d.setDate(1);
  d.setMonth(d.getMonth() - 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, catchError, filter, map, shareReplay, switchMap, take, throwError, timer } from 'rxjs';
import {
  API_URL, Alert, ClientReport, ClientRequest, CompetitionStudy, Competitor, Occasion, StylePart, PortalData, PortalPost, PortalSettings, Profitability, Prospect, PublicAudit, ReviewInput, ReviewReply, StyleExample, TimeEntry, ReportStatus, AdImportResult, AdPlatform, AdsSetup, AdSpend, AdSpendInput, AdSummary, BillingClient, InvoiceDetail, InvoiceMonth, LinkSummary, TrackedLink, AdminOrganization, AdminOrganizationDetail, AdminOverview, Asset, Brand, BrandInput, CalendarItem, CampaignRequest, ContentItem, Dashboard, GenerateRequest, Job,
  NewProject, Project, Role, TeamMember, Visual,
} from './models';

type Data<T> = { data: T };

/** Chiamate alle API di Marketing OS (tutte le risposte sono nel formato { data }). */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private http = inject(HttpClient);

  private get<T>(path: string): Observable<T> {
    return this.http.get<Data<T>>(`${API_URL}${path}`).pipe(map((r) => r.data));
  }
  private post<T>(path: string, body: unknown = {}): Observable<T> {
    return this.http.post<Data<T>>(`${API_URL}${path}`, body).pipe(map((r) => r.data));
  }
  private patch<T>(path: string, body: unknown): Observable<T> {
    return this.http.patch<Data<T>>(`${API_URL}${path}`, body).pipe(map((r) => r.data));
  }
  private delete(path: string): Observable<unknown> {
    return this.http.delete(`${API_URL}${path}`);
  }

  // Progetti
  projects = () => this.get<Project[]>('/projects');
  project = (id: number) => this.get<Project>(`/projects/${id}`);
  createProject = (p: NewProject) => this.post<Project>('/projects', p);
  updateProject = (id: number, p: Partial<NewProject> & { status?: string }) => this.patch<Project>(`/projects/${id}`, p);

  // Dashboard, calendario, approvazioni
  dashboard = () => this.get<Dashboard>('/dashboard');
  calendar = (from: string, to: string) => this.get<CalendarItem[]>(`/calendar?from=${from}&to=${to}`);
  approvals = () => this.get<ContentItem[]>('/approvals');

  // Dal sito alla campagna
  analyzeSite = (url: string) => this.post<Job>('/onboarding/analyze', { url });
  createCampaign = (brandId: number, req: CampaignRequest) =>
    this.post<{ project: Project; job: Job }>(`/brands/${brandId}/campaigns`, req);
  reanalyzeBrand = (brandId: number, url?: string) => this.post<Job>(`/brands/${brandId}/analyze`, url ? { url } : {});
  suggestGoals = (brandId: number) => this.post<Job>(`/brands/${brandId}/goal-suggestions`);
  deleteProject = (id: number) => this.delete(`/projects/${id}`);

  /** Segue un lavoro in background ogni 2 secondi finché termina (al massimo 4 minuti). */
  waitForJob(id: number): Observable<Job> {
    return timer(800, 2000).pipe(
      take(120),
      switchMap(() => this.job(id)),
      filter((job) => job.status === 'done' || job.status === 'failed'),
      take(1),
    );
  }

  // Clienti (brand kit)
  brands = () => this.get<Brand[]>('/brands');
  brand = (id: number) => this.get<Brand>(`/brands/${id}`);
  createBrand = (b: BrandInput) => this.post<Brand>('/brands', b);
  updateBrand = (id: number, b: BrandInput) => this.patch<Brand>(`/brands/${id}`, b);
  /** Con withCampaigns elimina anche le campagne del cliente e i loro contenuti. */
  deleteBrand = (id: number, withCampaigns = false) => this.delete(`/brands/${id}${withCampaigns ? '?with_campaigns=1' : ''}`);

  // Libreria file
  assets = (brandId?: number) => this.get<Asset[]>(brandId ? `/assets?brand_id=${brandId}` : '/assets');
  deleteAsset = (id: number) => {
    this.blobCache.delete(id);
    return this.delete(`/assets/${id}`);
  };
  uploadAsset(file: File, brandId?: number): Observable<Asset> {
    const form = new FormData();
    form.append('file', file);
    if (brandId) form.append('brand_profile_id', String(brandId));
    return this.post<Asset>('/assets', form);
  }
  /**
   * Il file richiede il token, quindi si scarica come blob e si mostra con un object URL.
   * I file non cambiano mai: ogni id si scarica una volta sola anche se compare in molte anteprime.
   */
  assetBlob(id: number): Observable<Blob> {
    let blob = this.blobCache.get(id);
    if (!blob) {
      blob = this.http.get(`${API_URL}/assets/${id}/file`, { responseType: 'blob' }).pipe(
        catchError((err) => {
          this.blobCache.delete(id);
          return throwError(() => err);
        }),
        shareReplay(1),
      );
      this.blobCache.set(id, blob);
    }
    return blob;
  }
  private blobCache = new Map<number, Observable<Blob>>();

  /** Da chiamare all'uscita, così un altro utente sullo stesso browser non riceve file in cache. */
  clearCache(): void {
    this.blobCache.clear();
  }

  // Contenuti
  content = (projectId: number) => this.get<ContentItem[]>(`/projects/${projectId}/content`);
  contentItem = (id: number) => this.get<ContentItem>(`/content/${id}`);
  deleteContent = (id: number) => this.delete(`/content/${id}`);
  rewriteContent = (id: number, instruction: string) => this.post<Job>(`/content/${id}/rewrite`, { instruction });
  /** Video del post montato in background; il lavoro restituisce l'id del file MP4. */
  createVideo = (id: number, format: 'story' | 'square', musicAssetId: number | null) =>
    this.post<Job>(`/content/${id}/video`, { format, music_asset_id: musicAssetId });
  publishContent = (id: number, url?: string | null) => this.post<ContentItem>(`/content/${id}/publish`, url ? { url } : {});
  unpublishContent = (id: number) => this.post<ContentItem>(`/content/${id}/unpublish`);
  /** Carica una foto personalizzata nella grafica del post (finisce anche nella libreria del cliente). */
  uploadContentPhoto(id: number, file: File): Observable<ContentItem> {
    const form = new FormData();
    form.append('file', file);
    return this.post<ContentItem>(`/content/${id}/photo`, form);
  }
  createContent = (projectId: number, c: Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>) =>
    this.post<ContentItem>(`/projects/${projectId}/content`, c);
  updateContent = (id: number, c: Partial<Pick<ContentItem, 'channel' | 'content_type' | 'title' | 'body'>> & { visual?: Visual | null }) =>
    this.patch<ContentItem>(`/content/${id}`, c);
  scheduleContent = (id: number, scheduledAt: string | null) =>
    this.post<ContentItem>(`/content/${id}/schedule`, { scheduled_at: scheduledAt });
  submitContent = (id: number) => this.post<ContentItem>(`/content/${id}/submit`);
  approveContent = (id: number, note?: string) => this.post<ContentItem>(`/content/${id}/approve`, note ? { note } : {});
  rejectContent = (id: number, note: string) => this.post<ContentItem>(`/content/${id}/reject`, { note });
  generate = (projectId: number, req: GenerateRequest) => this.post<Job>(`/projects/${projectId}/generate`, req);
  job = (id: number) => this.get<Job>(`/jobs/${id}`);

  // Pannello Admin della piattaforma
  adminOverview = () => this.get<AdminOverview>('/admin/overview');
  adminOrganizations(q = '', status = ''): Observable<AdminOrganization[]> {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (status) params.set('status', status);
    return this.get<AdminOrganization[]>(`/admin/organizations${params.toString() ? '?' + params : ''}`);
  }
  adminOrganization = (id: number) => this.get<AdminOrganizationDetail>(`/admin/organizations/${id}`);
  adminSuspend = (id: number, reason: string) => this.post<AdminOrganizationDetail>(`/admin/organizations/${id}/suspend`, { reason });
  adminReactivate = (id: number) => this.post<AdminOrganizationDetail>(`/admin/organizations/${id}/reactivate`);
  adminNotes = (id: number, notes: string) => this.patch<AdminOrganizationDetail>(`/admin/organizations/${id}`, { admin_notes: notes });

  // Sponsorizzate
  /** Righe del registro che toccano l'anno indicato. */
  adSpend(year: number, brandId?: number | null): Observable<AdSpend[]> {
    return this.get<AdSpend[]>(`/ad-spend?from=${year}-01-01&to=${year}-12-31${brandId ? `&brand_id=${brandId}` : ''}`);
  }
  adSummary(year: number, brandId?: number | null): Observable<AdSummary> {
    return this.get<AdSummary>(`/ad-spend/summary?year=${year}${brandId ? `&brand_id=${brandId}` : ''}`);
  }
  createAdSpend = (row: AdSpendInput) => this.post<AdSpend>('/ad-spend', row);
  updateAdSpend = (id: number, row: Partial<AdSpendInput>) => this.patch<AdSpend>(`/ad-spend/${id}`, row);
  deleteAdSpend = (id: number) => this.delete(`/ad-spend/${id}`);
  /** CSV di Gestione inserzioni; con preview restituisce solo cosa verrebbe importato. */
  importAdSpend(brandId: number, file: File, platform: AdPlatform, preview: boolean, projectId?: number | null): Observable<AdImportResult> {
    const form = new FormData();
    form.append('file', file);
    form.append('platform', platform);
    if (preview) form.append('preview', '1');
    if (projectId) form.append('project_id', String(projectId));
    return this.post<AdImportResult>(`/brands/${brandId}/ad-spend/import`, form);
  }

  // Sponsorizzate Meta: chi paga, account pubblicitario, passaggi della guida
  adsSetup = (brandId: number) => this.get<AdsSetup>(`/brands/${brandId}/ads-setup`);
  updateAdsSetup = (brandId: number, data: Partial<Pick<AdsSetup, 'ads_payer' | 'meta_ad_account_id' | 'steps'>>) => this.patch<AdsSetup>(`/brands/${brandId}/ads-setup`, data);

  // Link tracciati
  links(filter: { brandId?: number | null; contentId?: number } = {}): Observable<TrackedLink[]> {
    const params = new URLSearchParams();
    if (filter.brandId) params.set('brand_id', String(filter.brandId));
    if (filter.contentId) params.set('content_id', String(filter.contentId));
    return this.get<TrackedLink[]>(`/links${params.toString() ? '?' + params : ''}`);
  }
  linkSummary(days: number, filter: { brandId?: number | null; linkId?: number | null } = {}): Observable<LinkSummary> {
    const params = new URLSearchParams({ days: String(days) });
    if (filter.brandId) params.set('brand_id', String(filter.brandId));
    if (filter.linkId) params.set('link_id', String(filter.linkId));
    return this.get<LinkSummary>(`/links/summary?${params}`);
  }
  /** Con content_item_id restituisce il link già esistente del post, se c'è. */
  createLink = (data: { target_url?: string | null; content_item_id?: number; brand_profile_id?: number | null; label?: string | null }) => this.post<TrackedLink>('/links', data);
  deleteLink = (id: number) => this.delete(`/links/${id}`);

  // Report mensili per i clienti
  reports = (period: string) => this.get<{ period: string; clients: ReportStatus[] }>(`/reports?period=${period}`);
  report = (brandId: number, period: string) => this.get<ClientReport>(`/brands/${brandId}/report?period=${period}`);
  updateReport = (brandId: number, data: { period: string; summary: string | null; next_steps: string | null }) => this.patch<ClientReport>(`/brands/${brandId}/report`, data);
  shareReport = (brandId: number, period: string) => this.post<ClientReport>(`/brands/${brandId}/report/share`, { period });
  unshareReport = (brandId: number, period: string) => this.post<ClientReport>(`/brands/${brandId}/report/unshare`, { period });
  /** Report aperto dal cliente con il link pubblico: nessun token. */
  publicReport = (token: string) => this.get<ClientReport>(`/public/reports/${token}`);
  publicReportLogoUrl = (token: string) => `${API_URL}/public/reports/${token}/logo`;

  // Fatturazione
  billingClients = () => this.get<BillingClient[]>('/billing/clients');
  updateBillingClient = (id: number, data: Partial<BillingClient>) => this.patch<BillingClient>(`/billing/clients/${id}`, data);
  invoices = (period: string) => this.get<InvoiceMonth>(`/invoices?period=${period}`);
  prepareInvoices = (period: string) => this.post<{ created: number; updated: number; skipped: number }>('/invoices/prepare', { period });
  invoice = (id: number) => this.get<InvoiceDetail>(`/invoices/${id}`);
  updateInvoice = (id: number, data: { vat_rate?: number; notes?: string | null; due_on?: string | null }) => this.patch<InvoiceDetail>(`/invoices/${id}`, data);
  addInvoiceLine = (id: number, description: string, amount: number) => this.post<InvoiceDetail>(`/invoices/${id}/lines`, { description, amount });
  deleteInvoiceLine = (id: number, lineId: number) => this.http.delete<Data<InvoiceDetail>>(`${API_URL}/invoices/${id}/lines/${lineId}`).pipe(map((r) => r.data));
  issueInvoice = (id: number, data: { number: string; issued_on?: string; due_on?: string | null }) => this.post<InvoiceDetail>(`/invoices/${id}/issue`, data);
  markInvoicePaid = (id: number, paidOn?: string) => this.post<InvoiceDetail>(`/invoices/${id}/paid`, paidOn ? { paid_on: paidOn } : {});
  reopenInvoice = (id: number) => this.post<InvoiceDetail>(`/invoices/${id}/reopen`);
  deleteInvoice = (id: number) => this.delete(`/invoices/${id}`);
  /** Il CSV richiede il token: si scarica come blob. */
  invoicesCsv = (period: string) => this.http.get(`${API_URL}/invoices/export?period=${period}`, { responseType: 'blob' });

  // Team
  team =() => this.get<TeamMember[]>('/users');
  addMember = (m: { name: string; email: string; password: string; role: Exclude<Role, 'owner'> }) => this.post<TeamMember>('/users', m);

  // Potenziali clienti e audit gratuito
  prospects = () => this.get<Prospect[]>('/prospects');
  prospect = (id: number) => this.get<Prospect>(`/prospects/${id}`);
  createProspect = (url: string) => this.post<Prospect>('/prospects', { url });
  updateProspect = (id: number, data: Partial<Pick<Prospect, 'status' | 'name' | 'monthly_fee' | 'agency_note'>>) => this.patch<Prospect>(`/prospects/${id}`, data);
  deleteProspect = (id: number) => this.delete(`/prospects/${id}`);
  reanalyzeProspect = (id: number) => this.post<Prospect>(`/prospects/${id}/reanalyze`);
  shareProspect = (id: number) => this.post<Prospect>(`/prospects/${id}/share`);
  unshareProspect = (id: number) => this.post<Prospect>(`/prospects/${id}/unshare`);
  convertProspect = (id: number) => this.post<Job>(`/prospects/${id}/convert`);
  publicAudit = (token: string) => this.get<PublicAudit>(`/public/audits/${token}`);

  // Stile appreso e altri formati
  styleExamples = (brandId: number) => this.get<StyleExample[]>(`/brands/${brandId}/style-examples`);
  deleteStyleExample = (id: number) => this.delete(`/style-examples/${id}`);
  repurposeContent = (id: number, formats: string[]) => this.post<Job>(`/content/${id}/repurpose`, { formats });

  // Ore e redditività
  timeEntries(period: string, brandId?: number | null): Observable<TimeEntry[]> {
    return this.get<TimeEntry[]>(`/time-entries?period=${period}${brandId ? `&brand_id=${brandId}` : ''}`);
  }
  addTimeEntry = (data: { brand_id: number; minutes: number; worked_on?: string; note?: string | null }) => this.post<{ id: number }>('/time-entries', data);
  deleteTimeEntry = (id: number) => this.delete(`/time-entries/${id}`);
  profitability = (period: string) => this.get<Profitability>(`/profitability?period=${period}`);
  updateHourlyCost = (period: string, hourlyCost: number | null) => this.patch<Profitability>(`/profitability/settings?period=${period}`, { hourly_cost: hourlyCost });

  // Recensioni, ricorrenze, avvisi
  reviewReplies = (brandId?: number | null) => this.get<ReviewReply[]>(`/review-replies${brandId ? `?brand_id=${brandId}` : ''}`);
  suggestReviewReplies = (brandId: number, review: ReviewInput) => this.post<Job>(`/brands/${brandId}/review-replies/suggest`, review);
  saveReviewReply = (data: ReviewInput & { brand_id: number; reply: string }) => this.post<{ id: number }>('/review-replies', data);
  deleteReviewReply = (id: number) => this.delete(`/review-replies/${id}`);
  occasions = (from: string, to: string) => this.get<Occasion[]>(`/occasions?from=${from}&to=${to}`);
  alerts = () => this.get<Alert[]>('/alerts');

  // Portale del cliente e concorrenti
  portal = (brandId: number) => this.get<PortalSettings>(`/brands/${brandId}/portal`);
  enablePortal = (brandId: number) => this.post<PortalSettings>(`/brands/${brandId}/portal/enable`);
  disablePortal = (brandId: number) => this.post<PortalSettings>(`/brands/${brandId}/portal/disable`);
  updateClientRequest = (id: number, status: ClientRequest['status']) => this.patch<{ id: number; status: string }>(`/client-requests/${id}`, { status });
  publicPortal = (token: string) => this.get<PortalData>(`/public/portal/${token}`);
  publicPortalReview = (token: string, contentId: number, action: 'approve' | 'reject', data: { name?: string | null; note?: string | null }) =>
    this.post<PortalPost>(`/public/portal/${token}/content/${contentId}/${action}`, data);
  publicPortalRequest = (token: string, data: { name?: string | null; message: string }) => this.post<{ id: number }>(`/public/portal/${token}/requests`, data);
  publicPortalAssetUrl = (token: string, assetId: number) => `${API_URL}/public/portal/${token}/assets/${assetId}`;
  competitors = (brandId: number) => this.get<Competitor[]>(`/brands/${brandId}/competitors`);
  addCompetitor = (brandId: number, url: string, name?: string | null) => this.post<Competitor>(`/brands/${brandId}/competitors`, { url, name });
  refreshCompetitor = (id: number) => this.post<Competitor>(`/competitors/${id}/refresh`);
  deleteCompetitor = (id: number) => this.delete(`/competitors/${id}`);
  competition = (brandId: number) => this.get<CompetitionStudy>(`/brands/${brandId}/competition`);
  startCompetition = (brandId: number) => this.post<CompetitionStudy>(`/brands/${brandId}/competition`);
  applyStyle = (brandId: number, parts: StylePart[]) => this.post<CompetitionStudy>(`/brands/${brandId}/competition/apply`, { parts });
  undoStyle = (brandId: number) => this.post<CompetitionStudy>(`/brands/${brandId}/competition/undo`);
}

// Stessa origine del frontend: in sviluppo ng serve inoltra /api a localhost:8088 (proxy.conf.json).
export const API_URL = '/api/v1';

export type Role = 'owner' | 'admin' | 'editor' | 'viewer';
export type ProjectStatus = 'draft' | 'active' | 'paused' | 'completed' | 'archived';
export type Channel = 'instagram' | 'facebook' | 'linkedin' | 'blog' | 'email' | 'website' | 'other';
export type ContentStatus = 'draft' | 'in_review' | 'approved' | 'scheduled' | 'published' | 'rejected';

export const ROLE_LABELS: Record<Role, string> = {
  owner: 'Titolare',
  admin: 'Amministratore',
  editor: 'Editor',
  viewer: 'Sola lettura',
};

export const CHANNEL_LABELS: Record<Channel, string> = {
  instagram: 'Instagram',
  facebook: 'Facebook',
  linkedin: 'LinkedIn',
  blog: 'Blog',
  email: 'Email',
  website: 'Sito web',
  other: 'Altro',
};

export const CONTENT_STATUS_LABELS: Record<ContentStatus, string> = {
  draft: 'Bozza',
  in_review: 'In revisione',
  approved: 'Approvato',
  scheduled: 'Programmato',
  published: 'Pubblicato',
  rejected: 'Da rivedere',
};

export interface User {
  id: number;
  organization_id: number;
  /** Nome dell'agenzia */
  organization_name?: string;
  name: string;
  email: string;
  role: Role;
  /** Amministratore della piattaforma: vede il pannello Admin con tutte le organizzazioni */
  is_platform_admin?: boolean;
  /** Prova gratuita o abbonamento dell'agenzia (arriva da /me) */
  subscription?: SubscriptionInfo;
}

export type SubscriptionPlan = 'monthly' | 'yearly';

/** Accesso dell'agenzia a Marketing OS: prova gratuita, abbonamento pagato, accesso regalato o scaduto. */
export interface SubscriptionInfo {
  state: 'trial' | 'active' | 'expired' | 'free';
  plan: 'trial' | SubscriptionPlan | 'free';
  has_access: boolean;
  trial_ends_at: string | null;
  trial_days_left: number | null;
  /** Stato su Stripe (active, past_due, canceled…) */
  status: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  can_manage_billing: boolean;
  /** Prezzi in centesimi */
  prices: { monthly: number; yearly: number; currency: string };
  payments_enabled: boolean;
}

export interface AdminOverview {
  organizations: number;
  suspended: number;
  new_last_30_days: number;
  users: number;
  active_users_7_days: number;
  clients: number;
  campaigns: number;
  content: number;
  published_this_month: number;
  ai_jobs_this_month: number;
  videos_this_month: number;
  failed_jobs_7_days: number;
  storage_bytes: number;
}

export interface AdminOrganization {
  id: number;
  name: string;
  slug: string;
  status: 'active' | 'suspended';
  suspended_at: string | null;
  suspended_reason: string | null;
  created_at: string;
  owner_email: string | null;
  users_count: number;
  last_login_at: string | null;
  clients_count: number;
  campaigns_count: number;
  content_count: number;
  published_count: number;
  jobs_this_month: number;
  storage_bytes: number;
  admin_notes?: string | null;
  plan: SubscriptionInfo['plan'];
  trial_ends_at: string | null;
  subscription: SubscriptionInfo;
}

export interface AdminOrganizationDetail {
  organization: AdminOrganization;
  users: { id: number; name: string; email: string; role: Role; is_platform_admin: boolean; last_login_at: string | null; created_at: string }[];
  clients: { id: number; name: string; website_url: string | null; primary_color: string | null; campaigns_count: number; created_at: string }[];
  recent_jobs: { id: number; type: string; status: string; error: string | null; created_at: string; finished_at: string | null }[];
  activity: { action: string; entity_type: string; entity_id: number | null; created_at: string; user_name: string | null }[];
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  role: Role;
  created_at: string;
}

export interface Token {
  access_token: string;
  token_type: 'Bearer';
  expires_at: string;
}

export interface AuthResponse {
  data: { user: User; token: Token };
}

export interface Strategy {
  summary: string;
  audience: string;
  positioning: string;
  key_messages: string[];
  content_pillars: string[];
  channel_plan: string;
}

export interface Project {
  id: number;
  name: string;
  objective: string;
  target_audience: string | null;
  budget_monthly: string | null;
  status: ProjectStatus;
  strategy: Strategy | null;
  brand_profile_id: number | null;
  created_at: string;
  updated_at: string;
  // Presenti solo nell'elenco
  content_total?: number;
  content_approved?: number;
  content_in_review?: number;
}

export interface NewProject {
  name: string;
  objective: string;
  target_audience?: string | null;
  budget_monthly?: number | null;
  brand_profile_id?: number | null;
}

export interface Brand {
  id: number;
  name: string;
  summary: string | null;
  website_url: string | null;
  instagram_url: string | null;
  facebook_url: string | null;
  linkedin_url: string | null;
  industry: string | null;
  audience: string | null;
  tone_of_voice: string | null;
  brand_guidelines: string | null;
  primary_color: string | null;
  secondary_color: string | null;
  font_family: string | null;
  logo_asset_id: number | null;
  products: string | null;
  keywords: string | null;
  words_to_avoid: string | null;
  /** Lingua in cui l'AI scrive post e sponsorizzate del cliente. */
  content_language: 'it' | 'en';
  created_at: string;
  updated_at: string;
}

export type BrandInput = Partial<Omit<Brand, 'id' | 'created_at' | 'updated_at'>>;

export interface Asset {
  id: number;
  brand_profile_id: number | null;
  kind: 'image' | 'video' | 'document' | 'audio';
  original_name: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
}

export type VisualLayout = 'bold' | 'light' | 'photo' | 'split' | 'offer' | 'quote';

export const LAYOUT_LABELS: Record<VisualLayout, string> = {
  bold: 'Colore pieno',
  light: 'Chiaro',
  photo: 'Foto',
  split: 'Foto e colore',
  offer: 'Offerta',
  quote: 'Citazione',
};

export interface GoalSuggestion {
  title: string;
  goal: string;
}

/** Grafica di un post: testi sovrapposti, layout e foto facoltativa dalla libreria. */
export interface Visual {
  headline: string;
  subheadline: string;
  badge: string;
  layout: VisualLayout;
  asset_id: number | null;
}

export interface ContentItem {
  id: number;
  project_id: number;
  channel: Channel;
  content_type: string;
  title: string;
  body: string;
  visual: Visual | null;
  /** Video MP4 del post, se creato con il Video Studio */
  video_asset_id: number | null;
  status: ContentStatus;
  ai_generated: number;
  job_id: number | null;
  review_note: string | null;
  reviewed_at: string | null;
  /** portal se approvato o rifiutato dal cliente nel portale */
  reviewed_via?: 'app' | 'portal' | null;
  scheduled_at: string | null;
  published_at: string | null;
  published_url: string | null;
  created_at: string;
  updated_at: string;
  // Presenti solo nell'elenco delle approvazioni
  project_name?: string;
  brand_profile_id?: number | null;
  /** Presente nell'elenco dei contenuti di una campagna */
  tracked_link?: { short_url: string; clicks: number; unique_clicks: number } | null;
}

export interface CalendarItem {
  id: number;
  project_id: number;
  channel: Channel;
  content_type: string;
  title: string;
  status: ContentStatus;
  scheduled_at: string;
  project_name: string;
  brand_id: number | null;
  brand_name: string | null;
  brand_color: string | null;
}

export interface Dashboard {
  clients: number;
  active_campaigns: number;
  scheduled_this_month: number;
  pending_approval: number;
  ai_runs_this_month: number;
  published_this_month: number;
  /** Approvati con data di oggi o passata, non ancora pubblicati */
  to_publish: CalendarItem[];
  week_start: string;
  week: CalendarItem[];
}

export type JobStatus = 'queued' | 'running' | 'done' | 'failed';

/** Suggerimenti mentre si scrive il sito del cliente: clienti già presenti e domini esistenti. */
export interface DomainSuggestions {
  clients: { id: number; name: string; website_url: string | null }[];
  domains: string[];
}

export interface Job {
  id: number;
  type: string;
  status: JobStatus;
  result: {
    content_ids?: number[]; content_id?: number; brand_id?: number; project_id?: number;
    logo_found?: boolean; site_colors?: string[]; photos_imported?: number; goals?: GoalSuggestion[]; replies?: string[];
  } | null;
  error: string | null;
  created_at: string;
}

export interface CampaignRequest {
  goal: string;
  weeks: number;
  posts_per_week: number;
  channels: Channel[];
  start_date: string;
  name?: string;
}

export interface GenerateRequest {
  channel: Channel;
  content_type: string;
  brief: string;
  variants: number;
  /** Foto da usare nella grafica delle varianti */
  asset_id?: number | null;
}

/** Estrae il messaggio d'errore restituito dalle API ({ error, message }). */
export function apiErrorMessage(err: unknown, fallback = 'Si è verificato un errore.'): string {
  const e = err as { status?: number; error?: { message?: string } };
  if (e?.status === 0) return 'Impossibile contattare il server. Verifica che Docker sia avviato.';
  return e?.error?.message ?? fallback;
}

// Sponsorizzate e fatturazione

export type AdPlatform = 'meta' | 'google' | 'tiktok' | 'linkedin' | 'other';
export const PLATFORM_LABELS: Record<AdPlatform, string> = { meta: 'Meta (Facebook e Instagram)', google: 'Google Ads', tiktok: 'TikTok', linkedin: 'LinkedIn', other: 'Altra piattaforma' };

export interface AdSpend {
  id: number;
  brand_profile_id: number;
  brand_name: string;
  brand_color: string | null;
  project_id: number | null;
  project_name: string | null;
  platform: AdPlatform;
  campaign_name: string;
  period_start: string;
  period_end: string;
  budget: number | null;
  spent: number;
  impressions: number | null;
  reach: number | null;
  clicks: number | null;
  results: number | null;
  source: 'manual' | 'csv';
  notes: string | null;
  invoice_id: number | null;
  invoice_status: InvoiceStatus | null;
  invoice_period: string | null;
}

export type AdSpendInput = Pick<AdSpend, 'brand_profile_id' | 'platform' | 'campaign_name' | 'period_start' | 'period_end' | 'spent' | 'budget' | 'impressions' | 'reach' | 'clicks' | 'results' | 'notes'> & { project_id?: number | null };

export interface AdMetrics {
  spent: number;
  budget: number;
  impressions: number;
  reach: number;
  clicks: number;
  results: number;
  cpc: number | null;
  ctr: number | null;
  cost_per_result: number | null;
}

export interface AdSummary {
  year: number;
  totals: AdMetrics;
  months: { month: number; spent: number; clicks: number; impressions: number }[];
  clients: (AdMetrics & { id: number; name: string; primary_color: string | null; entries: number })[];
}

export interface AdImportResult {
  preview: boolean;
  counts: { new: number; update: number; locked: number };
  skipped: number;
  errors: { line: number; message: string }[];
  total_spent: number;
  rows: { campaign_name: string; period_start: string; period_end: string; spent: number; clicks: number | null; impressions: number | null; action: 'new' | 'update' | 'locked' }[];
}

export type InvoiceStatus = 'to_invoice' | 'invoiced' | 'paid';
export const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = { to_invoice: 'Da fatturare', invoiced: 'Fatturata', paid: 'Pagata' };

export interface BillingClient {
  id: number;
  name: string;
  primary_color: string | null;
  monthly_fee: number | null;
  ad_fee_percent: number;
  billing_name: string | null;
  vat_number: string | null;
  tax_code: string | null;
  sdi_code: string | null;
  pec: string | null;
  billing_address: string | null;
  billing_email: string | null;
}

export interface Invoice {
  id: number;
  brand_profile_id: number;
  brand_name: string;
  brand_color: string | null;
  period: string;
  status: InvoiceStatus;
  number: string | null;
  issued_on: string | null;
  due_on: string | null;
  paid_on: string | null;
  vat_rate: number;
  subtotal: number;
  vat_amount: number;
  total: number;
  lines_count?: number;
  ad_spend_total?: number;
  notes?: string | null;
}

export interface InvoiceLine {
  id: number;
  kind: 'fee' | 'ad_spend' | 'ad_fee' | 'other';
  description: string;
  amount: number;
  ad_spend_id: number | null;
}

export interface InvoiceDetail extends Invoice {
  lines: InvoiceLine[];
  client: BillingClient;
}

export interface InvoiceMonth {
  period: string;
  invoices: Invoice[];
  totals: Record<InvoiceStatus, { count: number; total: number }>;
  unbilled_ad_spend: { count: number; spent: number };
}

// Link tracciati

export interface TrackedLink {
  id: number;
  brand_profile_id: number | null;
  brand_name: string | null;
  brand_color: string | null;
  content_item_id: number | null;
  content_title: string | null;
  content_channel: Channel | null;
  code: string;
  label: string;
  target_url: string;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  utm_content: string | null;
  clicks: number;
  unique_clicks: number;
  last_click_at: string | null;
  created_at: string;
  short_url: string;
  destination_url: string;
}

export interface LinkSummary {
  days: number;
  clicks: number;
  unique_clicks: number;
  daily: { day: string; clicks: number; unique_clicks: number }[];
  sources: { name: string; clicks: number }[];
  devices: { name: string; clicks: number }[];
}

export const CLICK_SOURCE_LABELS: Record<string, string | undefined> = {
  instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', google: 'Google', x: 'X', email: 'Email', direct: 'Diretto o app', other: 'Altri siti',
};

// Pagamento delle sponsorizzate Meta

export type AdsPayer = 'client' | 'agency';

export interface AdsSetup {
  brand_profile_id: number;
  ads_payer: AdsPayer | null;
  meta_ad_account_id: string | null;
  meta_business_id: string | null;
  steps: string[];
  total_steps: number;
}

// Report mensile per il cliente

export interface ClientReport {
  period: string;
  period_label: string;
  previous_label: string;
  generated_at: string;
  agency: { name: string };
  client: { id: number; name: string; website_url: string | null; primary_color: string | null; secondary_color: string | null; logo_asset_id: number | null; has_logo: boolean };
  summary: string | null;
  next_steps: string | null;
  content: {
    published: number;
    previous: number;
    planned_next_month: number;
    by_channel: { name: Channel; posts: number }[];
    posts: { id: number; title: string; channel: Channel; content_type: string; published_at: string; published_url: string | null; clicks: number }[];
  };
  clicks: {
    clicks: number;
    unique_clicks: number;
    previous: { clicks: number; unique_clicks: number };
    daily: { day: string; clicks: number }[];
    sources: { name: string; clicks: number }[];
    devices: { name: string; clicks: number }[];
    top_links: { label: string; clicks: number }[];
  };
  ads: {
    totals: AdMetrics;
    previous: { spent: number; clicks: number; results: number };
    campaigns: (AdMetrics & { platform: AdPlatform; campaign_name: string; period_start: string; period_end: string })[];
  };
  /** Solo nell'app dell'agenzia, non nel report pubblico */
  share?: { token: string | null; shared_at: string | null; views: number; last_viewed_at: string | null };
}

export interface ReportStatus {
  id: number;
  name: string;
  primary_color: string | null;
  logo_asset_id: number | null;
  has_summary: boolean;
  shared: boolean;
  views: number;
  last_viewed_at: string | null;
}

// Potenziali clienti e audit gratuito

export type ProspectStatus = 'new' | 'sent' | 'won' | 'lost';

export const PROSPECT_STATUS_LABELS: Record<ProspectStatus, string> = { new: 'Da contattare', sent: 'Audit inviato', won: 'Acquisito', lost: 'Perso' };

export interface SiteCheck {
  key: string;
  ok: boolean;
  label: string;
  detail: string;
}

export interface ProspectAudit {
  name: string;
  summary: string;
  score: number;
  checks: SiteCheck[];
  strengths: string[];
  issues: { title: string; detail: string; fix: string }[];
  post_ideas: { channel: Channel; title: string; body: string }[];
  services: { name: string; description: string }[];
  pitch: string;
  colors: string[];
  site_url: string;
  analyzed_at: string;
}

export interface Prospect {
  id: number;
  url: string;
  name: string | null;
  status: ProspectStatus;
  /** Solo nel dettaglio */
  audit?: ProspectAudit | null;
  /** Solo nell'elenco */
  score?: number | null;
  monthly_fee: number | null;
  agency_note: string | null;
  brand_profile_id: number | null;
  share_token: string | null;
  shared_at: string | null;
  views: number;
  last_viewed_at: string | null;
  created_at: string;
  job_id: number | null;
  job_status: JobStatus | null;
  job_error: string | null;
  analyzing: boolean;
}

/** L'audit come lo vede il potenziale cliente dal link. */
export interface PublicAudit {
  name: string | null;
  url: string;
  audit: ProspectAudit;
  monthly_fee: number | null;
  agency_note: string | null;
  agency: { name: string };
}

// Stile appreso, altri formati

export interface StyleExample {
  id: number;
  content_item_id: number | null;
  channel: Channel;
  kind: 'edit' | 'feedback';
  before_text: string | null;
  after_text: string;
  created_at: string;
}

export const REPURPOSE_FORMATS: { key: string; label: string; icon: string }[] = [
  { key: 'instagram_post', label: 'Post Instagram', icon: 'ti-brand-instagram' },
  { key: 'carosello', label: 'Carosello', icon: 'ti-carousel-horizontal' },
  { key: 'reel', label: 'Copione Reel', icon: 'ti-movie' },
  { key: 'facebook', label: 'Post Facebook', icon: 'ti-brand-facebook' },
  { key: 'linkedin', label: 'Post LinkedIn', icon: 'ti-brand-linkedin' },
  { key: 'newsletter', label: 'Newsletter', icon: 'ti-mail' },
  { key: 'blog', label: 'Articolo blog', icon: 'ti-article' },
];

// Ore e redditività

export interface TimeEntry {
  id: number;
  brand_profile_id: number;
  brand_name: string;
  user_id: number | null;
  user_name: string | null;
  worked_on: string;
  minutes: number;
  note: string | null;
  created_at: string;
}

export interface ProfitClient {
  id: number;
  name: string;
  primary_color: string | null;
  logo_asset_id: number | null;
  minutes: number;
  revenue: number;
  revenue_estimated: boolean;
  cost: number | null;
  margin: number | null;
  margin_percent: number | null;
  effective_rate: number | null;
}

export interface Profitability {
  period: string;
  hourly_cost: number | null;
  clients: ProfitClient[];
  totals: { minutes: number; revenue: number; cost: number | null; margin: number | null; effective_rate: number | null };
}

// Recensioni, ricorrenze, avvisi

export type ReviewPlatform = 'google' | 'facebook' | 'tripadvisor' | 'trustpilot' | 'other';

export const REVIEW_PLATFORMS: { key: ReviewPlatform; label: string; icon: string }[] = [
  { key: 'google', label: 'Google', icon: 'ti-brand-google' },
  { key: 'facebook', label: 'Facebook', icon: 'ti-brand-facebook' },
  { key: 'tripadvisor', label: 'TripAdvisor', icon: 'ti-plane' },
  { key: 'trustpilot', label: 'Trustpilot', icon: 'ti-star' },
  { key: 'other', label: 'Altro', icon: 'ti-message' },
];

export interface ReviewInput {
  platform: ReviewPlatform;
  rating: number;
  author: string | null;
  review_text: string;
}

export interface ReviewReply extends ReviewInput {
  id: number;
  brand_profile_id: number;
  brand_name: string;
  reply: string;
  created_at: string;
}

export interface Occasion {
  date: string;
  name: string;
  idea: string;
  sectors: string[];
}

export interface Alert {
  key: string;
  severity: 'danger' | 'warning' | 'info';
  icon: string;
  title: string;
  detail: string;
  link: string;
  query: Record<string, string | number>;
}

// Portale del cliente e concorrenti

export interface ClientRequest {
  id: number;
  name: string | null;
  message: string;
  status: 'open' | 'done';
  created_at: string;
}

export interface PortalSettings {
  token: string | null;
  requests: ClientRequest[];
}

export type PortalPost = Pick<ContentItem, 'id' | 'channel' | 'content_type' | 'title' | 'body' | 'visual' | 'status' | 'review_note' | 'scheduled_at' | 'published_at' | 'published_url'>;

export interface PortalData {
  client: { name: string; primary_color: string | null; secondary_color: string | null; font_family: string | null; logo_asset_id: number | null };
  agency: { name: string };
  pending: PortalPost[];
  upcoming: PortalPost[];
  published: PortalPost[];
  reports: { period: string; token: string }[];
  requests: ClientRequest[];
}

export interface Competitor {
  id: number;
  name: string;
  url: string;
  analysis: {
    summary: string; offers: string[]; messages: string[]; tone: string; opportunities: string[]; socials: Record<string, string>;
    /** Assenti nelle analisi fatte prima del confronto: arrivano alla prossima rilettura. */
    strengths?: CompetitorStrength[]; score?: number; colors?: string[]; fonts?: string[];
  } | null;
  last_checked_at: string | null;
  created_at: string;
  job_status: JobStatus | null;
  job_error: string | null;
  analyzing: boolean;
  ad_library_url: string;
  job_id: number | null;
  /** Le 3 inserzioni attive più recenti dall'archivio di Meta (connected: l'archivio è collegato). */
  ads: { connected: boolean; active: number; preview: CompetitorAdPreview[] };
}

export interface CompetitorAdPreview {
  archive_id: string;
  title: string;
  text: string;
  platforms: string[];
  days: number;
  url: string;
}

/** Cosa fa bene un concorrente, come fare meglio e lo spunto per un post del cliente. */
export interface CompetitorStrength {
  strength: string;
  counter: string;
  post_idea: string;
}

export interface StyleProposal {
  primary_color: string;
  secondary_color: string;
  font_family: string;
  photo_style: string;
  tone_of_voice: string;
  formats: string[];
  rationale: string;
}

export type StylePart = 'colors' | 'font' | 'tone';

/** Confronto del cliente con i concorrenti e proposta di stile. */
export interface CompetitionStudy {
  client: { score: number; colors: string[]; fonts: string[] } | null;
  competitors: { id: number; name: string; score: number; colors: string[]; fonts: string[] }[];
  /** Per ogni controllo: esito del cliente (null se il suo sito non è stato letto) e di ogni concorrente, nello stesso ordine. */
  rows: { key: string; label: string; client: boolean | null; competitors: (boolean | null)[] }[];
  wins: string[];
  losses: string[];
  market: string | null;
  gaps: string[];
  proposal: StyleProposal | null;
  previous_style: Partial<Record<'primary_color' | 'secondary_color' | 'font_family' | 'tone_of_voice', string | null>> | null;
  applied_at: string | null;
  updated_at: string | null;
  job_id: number | null;
  analyzing: boolean;
  job_error: string | null;
}

export interface SiteCheck {
  key: string;
  ok: boolean;
  label: string;
  detail: string;
}

/** Ciò che è stato letto dal sito del cliente: dati dell'attività, strumenti di marketing e controlli. */
export interface SiteProfile {
  url: string;
  facts: {
    name: string;
    legal_name: string;
    opening_hours: string;
    price_range: string;
    rating: { value: number; count: number } | null;
    founded: string;
    contacts: { phones: string[]; emails: string[]; whatsapp: string | null; address: string; vat: string };
    other_socials: Partial<Record<'tiktok' | 'youtube' | 'x' | 'pinterest', string>>;
  } | null;
  signals: {
    platform: string | null;
    tools: string[];
    consent: string | null;
    chat: string | null;
    features: Record<'ecommerce' | 'booking' | 'contact_form' | 'newsletter' | 'whatsapp' | 'reviews' | 'video', boolean>;
    mobile: boolean;
    seconds: number;
    page_kb: number;
    languages: number;
    h1: number;
    images: number;
    images_without_alt: number;
    structured_data: boolean;
    schema_types: string[];
    open_graph: boolean;
  } | null;
  socials: Partial<Record<'instagram_url' | 'facebook_url' | 'linkedin_url', string>>;
  colors: string[];
  fonts: string[];
  checks: SiteCheck[];
  score: number;
}

export type Impact = 'alta' | 'media' | 'bassa';
export type Effort = 'basso' | 'medio' | 'alto';

/** Diagnosi strategica dell'AI, ragionata come un amministratore delegato. */
export interface SiteDiagnosis {
  verdict: string;
  business_model: string;
  value_proposition: string;
  sharper_proposition: string;
  target: string;
  scorecard: { area: string; score: number; note: string }[];
  swot: { strengths: string[]; weaknesses: string[]; opportunities: string[]; threats: string[] };
  priorities: { title: string; why: string; how: string; impact: Impact; effort: Effort; when: string; kpi: string }[];
  quick_wins: string[];
  revenue_ideas: string[];
  kpis: { name: string; target: string }[];
  questions: string[];
}

export interface SiteAnalysisState {
  profile: SiteProfile | null;
  analysis: SiteDiagnosis | null;
  profile_at: string | null;
  analyzed_at: string | null;
  job_id: number | null;
  analyzing: boolean;
  job_error: string | null;
}

// Regole di Meta e sponsorizzate preparate con la procedura guidata

export type AdObjective = 'awareness' | 'traffic' | 'engagement' | 'leads' | 'app_promotion' | 'sales';
export type AdSpecialCategory = 'none' | 'financial' | 'employment' | 'housing' | 'social_issues';
export type AdPlanStatus = 'draft' | 'ready' | 'live' | 'ended';
export type RuleSeverity = 'block' | 'warn' | 'tip';
export type AdVerdict = 'ok' | 'warning' | 'blocked';

export interface MetaRule {
  key: string;
  severity: RuleSeverity;
  title: string;
  why: string;
  fix: string;
  source: string | null;
}

export interface MetaPolicySource {
  id: number;
  slug: string;
  title: string;
  topic: string;
  url: string;
  checked_at: string | null;
  changed_at: string | null;
  last_error: string | null;
}

export interface MetaPolicyChange {
  id: number;
  detected_at: string;
  slug: string;
  title: string;
  url: string;
  added: string[];
  removed: string[];
  summary: { summary: string; impact: 'alta' | 'media' | 'bassa'; actions: string[] } | null;
  unread: boolean;
}

export interface MetaMonitor {
  rules_version: string;
  last_check: string | null;
  unread: number;
  sources: MetaPolicySource[];
  changes: MetaPolicyChange[];
}

export interface MetaRulebook {
  version: string;
  objectives: { key: AdObjective; label: string; icon: string; when: string; ctas: string[]; needs_url: boolean }[];
  ctas: { code: string; label: string }[];
  special_categories: { key: AdSpecialCategory; label: string; detail: string }[];
  text_limits: { primary_text: number; headline: number; description: number };
  specs: { placement: string; ratio: string; size: string; note: string }[];
  budget: { min_daily: number; suggested_daily: number };
  music: {
    sources: { key: AdMusicSource; label: string; icon: string; detail: string }[];
    moods: MusicMood[];
    by_objective: Record<AdObjective, string>;
  };
  rules: MetaRule[];
  monitor: MetaMonitor;
}

/** Atmosfera musicale: parole da cercare nella libreria di Meta e radici di parole del settore per cui è consigliata. */
export interface MusicMood {
  key: string;
  label: string;
  icon: string;
  feel: string;
  search: string;
  bpm: string;
  match: string[];
}

export type AdMusicSource = 'meta' | 'own' | 'none';

export interface AdMusic {
  source: AdMusicSource;
  mood: string | null;
  /** Con la musica di Meta: lascia che Meta scelga il brano adatto all'inserzione. */
  meta_picks: boolean;
  asset_id: number | null;
  track_name: string | null;
  rights_confirmed: boolean;
}

export interface AdIssue {
  key?: string;
  severity: RuleSeverity;
  title: string;
  why: string;
  fix: string;
  excerpt: string;
  source?: string | null;
}

export interface AdCheck {
  verdict: AdVerdict;
  issues: AdIssue[];
  suggested_category: AdSpecialCategory | null;
  version: string;
}

export interface AdAudience {
  location: string;
  radius_km: number;
  age_min: number;
  age_max: number;
  genders: 'all' | 'female' | 'male';
  interests: string[];
  advantage: boolean;
}

export interface AdPlanInput {
  brand_id: number;
  content_id: number | null;
  name: string;
  objective: AdObjective;
  special_category: AdSpecialCategory;
  audience: AdAudience;
  daily_budget: number;
  start_date: string;
  end_date: string | null;
  primary_text: string;
  headline: string;
  description: string;
  cta: string;
  url: string;
  music: AdMusic;
  /** Immagine dell'inserzione pubblicata da qui; vuota = la foto del post sponsorizzato. */
  image_asset_id?: number | null;
}

export interface AdReview {
  verdict: AdVerdict;
  summary: string;
  issues: AdIssue[];
  improved: { primary_text: string; headline: string; description: string } | null;
  audience_tip: string;
  landing_ok: boolean | null;
}

export interface AdPlan extends Omit<AdPlanInput, 'brand_id' | 'content_id' | 'url'> {
  id: number;
  brand_profile_id: number;
  brand_name: string;
  brand_color: string | null;
  content_item_id: number | null;
  url: string | null;
  checks: AdCheck | null;
  review: AdReview | null;
  reviewed_at: string | null;
  review_status: 'queued' | 'running' | 'done' | 'failed' | null;
  review_error: string | null;
  status: AdPlanStatus;
  image_asset_id: number | null;
  meta: AdPlanMeta;
  ads_manager_url: string;
  created_at: string;
  updated_at: string;
}

/** Inserzioni dei concorrenti dall'archivio di Meta: la spesa è una stima, Meta non la pubblica. */
export interface CompetitorAdsView {
  source: 'meta' | 'sample' | null;
  cpm: number;
  default_cpm: number;
  frequency: [number, number];
  window_days: number;
  client_spend_30d: number;
  competitors: CompetitorAds[];
}

export interface SpendRange {
  low: number;
  high: number;
}

export interface CompetitorAds {
  id: number;
  name: string;
  meta_page_id: string | null;
  checked_at: string | null;
  error: string | null;
  syncing: boolean;
  job_id: number | null;
  summary: { summary: string; offer: string; audience: string; tone: string; counter_ad: { primary_text: string; headline: string }; created_at: string } | null;
  new_ads: number;
  new_at: string | null;
  library_url: string;
  insights: {
    ads: number; active: number; new_30d: number; reach: number; spend: SpendRange | null; spend_30d: SpendRange | null;
    ages: { age: string; percent: number }[]; top_age: string | null; women_percent: number | null;
    countries: { country: string; percent: number }[]; places: { name: string; ads: number }[]; excluded_places: string[];
    platforms: { platform: string; ads: number }[]; domains: { domain: string; ads: number }[];
    cadence: { month: string; ads: number }[]; winners: { archive_id: string; days: number }[]; offers: string[];
  };
  ads: CompetitorAd[];
}

export interface CompetitorAd {
  archive_id: string;
  title: string;
  text: string;
  caption: string;
  platforms: string[];
  started_on: string;
  stopped_on: string | null;
  active: boolean;
  days: number;
  reach: number | null;
  spend: SpendRange | null;
  places: string[];
  ages: string[] | null;
  gender: string | null;
  winner: boolean;
  url: string;
}

/** Testi di partenza per una nuova sponsorizzata (per esempio quella proposta contro un concorrente). */
export interface AdDraft {
  brand_id: number;
  primary_text: string;
  headline: string;
}

/** Collegamento diretto del cliente a Meta: il codice di accesso resta sul server. */
export interface MetaConnection {
  mode: 'meta' | 'sample' | null;
  connected: boolean;
  sample: boolean;
  user_name: string | null;
  expires_at: string | null;
  expired: boolean;
  error: string | null;
  ad_account: { id: string; name: string; currency: string | null } | null;
  page: { id: string; name: string } | null;
  instagram: { id: string; username: string | null } | null;
  ready: boolean;
  max_daily_budget: number;
  connected_at: string | null;
}

export interface MetaAssets {
  ad_accounts: { id: string; name: string; currency: string; active: boolean; business: string | null }[];
  pages: { id: string; name: string; instagram_id: string | null; instagram_username: string | null }[];
}

/** La sponsorizzata su Meta: ID creati, stato della revisione e della consegna, risultati letti da Meta. */
export interface AdPlanMeta {
  published: boolean;
  sample: boolean;
  campaign_id: string | null;
  adset_id: string | null;
  image_hash: string | null;
  creative_id: string | null;
  ad_id: string | null;
  status: string | null;
  feedback: string[];
  job_status: 'queued' | 'running' | 'done' | 'failed' | null;
  error: string | null;
  published_at: string | null;
  activated_at: string | null;
  synced_at: string | null;
  results: { spent: number; impressions: number; reach: number; clicks: number; results: number } | null;
}

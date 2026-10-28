export const API_URL = 'http://localhost:8088/api/v1';

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
  name: string;
  email: string;
  role: Role;
  /** Amministratore della piattaforma: vede il pannello Admin con tutte le organizzazioni */
  is_platform_admin?: boolean;
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

export interface Job {
  id: number;
  type: string;
  status: 'queued' | 'running' | 'done' | 'failed';
  result: {
    content_ids?: number[]; content_id?: number; brand_id?: number; project_id?: number;
    logo_found?: boolean; site_colors?: string[]; photos_imported?: number; goals?: GoalSuggestion[];
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

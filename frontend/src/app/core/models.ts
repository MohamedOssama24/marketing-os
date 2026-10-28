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
  kind: 'image' | 'video' | 'document';
  original_name: string;
  mime_type: string;
  size_bytes: number;
  created_at: string;
}

export type VisualLayout = 'bold' | 'light' | 'photo';

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
  status: ContentStatus;
  ai_generated: number;
  job_id: number | null;
  review_note: string | null;
  reviewed_at: string | null;
  scheduled_at: string | null;
  created_at: string;
  updated_at: string;
  // Presenti solo nell'elenco delle approvazioni
  project_name?: string;
  brand_profile_id?: number | null;
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
  week_start: string;
  week: CalendarItem[];
}

export interface Job {
  id: number;
  type: string;
  status: 'queued' | 'running' | 'done' | 'failed';
  result: { content_ids?: number[]; brand_id?: number; project_id?: number; logo_found?: boolean; site_colors?: string[] } | null;
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
}

/** Estrae il messaggio d'errore restituito dalle API ({ error, message }). */
export function apiErrorMessage(err: unknown, fallback = 'Si è verificato un errore.'): string {
  const e = err as { status?: number; error?: { message?: string } };
  if (e?.status === 0) return 'Impossibile contattare il server. Verifica che Docker sia avviato.';
  return e?.error?.message ?? fallback;
}

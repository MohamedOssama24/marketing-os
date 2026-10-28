export const API_URL = 'http://localhost:8088/api/v1';

export type Role = 'owner' | 'admin' | 'editor' | 'viewer';
export type ProjectStatus = 'draft' | 'active' | 'paused' | 'completed' | 'archived';

export interface User {
  id: number;
  organization_id: number;
  name: string;
  email: string;
  role: Role;
}

export interface Token {
  access_token: string;
  token_type: 'Bearer';
  expires_at: string;
}

export interface AuthResponse {
  data: { user: User; token: Token };
}

export interface Project {
  id: number;
  name: string;
  objective: string;
  target_audience: string | null;
  budget_monthly: string | null;
  status: ProjectStatus;
  brand_profile_id: number | null;
  created_at: string;
  updated_at: string;
}

export interface NewProject {
  name: string;
  objective: string;
  target_audience?: string | null;
  budget_monthly?: number | null;
}

/** Estrae il messaggio d'errore restituito dalle API ({ error, message }). */
export function apiErrorMessage(err: unknown, fallback = 'Si è verificato un errore.'): string {
  const e = err as { status?: number; error?: { message?: string } };
  if (e?.status === 0) return 'Impossibile contattare il server. Verifica che Docker sia avviato.';
  return e?.error?.message ?? fallback;
}

import { Platform } from 'react-native';
import { API_BASE_URL } from '../config';

export type Role = 'admin' | 'user';

export type User = {
  id: number;
  name: string;
  email: string;
  role: Role;
  created_at: string;
  last_login_at: string | null;
};

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public details: Record<string, unknown> = {},
    public retryAfter?: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Per-field validation messages from the server (HTTP 422 / 409). */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, value] of Object.entries(this.details)) {
      if (typeof value === 'string') out[key] = value;
    }
    return out;
  }
}

// Native apps send the session token as a Bearer header. The browser build never holds a
// token: it relies on an HttpOnly cookie set by the server.
let bearerToken: string | null = null;
let onUnauthorized: (() => void) | null = null;

export function setBearerToken(token: string | null) {
  bearerToken = token;
}
export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler;
}

type Options = { method?: 'GET' | 'POST'; body?: unknown; signal?: AbortSignal };

export async function apiRequest<T>(path: string, { method = 'GET', body, signal }: Options = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Client-Type': Platform.OS === 'web' ? 'web' : 'native',
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (bearerToken) headers.Authorization = `Bearer ${bearerToken}`;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);
  signal?.addEventListener('abort', () => controller.abort());

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });
  } catch {
    throw new ApiError(
      0,
      'network_error',
      'Cannot reach the server. Check your connection and make sure the API is running.',
    );
  } finally {
    clearTimeout(timer);
  }

  let data: any = null;
  try {
    data = await response.json();
  } catch {
    /* empty or non-JSON body */
  }

  if (!response.ok) {
    if (!data || typeof data.error !== 'object' || data.error === null) {
      // Not an answer from our API (for example a static host with no backend behind it).
      throw new ApiError(
        response.status,
        'bad_response',
        `The API did not answer correctly (HTTP ${response.status}). Check that the backend is running and the API address is correct.`,
      );
    }
    const error = data.error;
    const retry = Number(response.headers.get('Retry-After'));
    const apiError = new ApiError(
      response.status,
      typeof error.code === 'string' ? error.code : 'error',
      typeof error.message === 'string' ? error.message : 'Something went wrong. Please try again.',
      error.details && typeof error.details === 'object' ? error.details : {},
      Number.isFinite(retry) && retry > 0 ? retry : undefined,
    );
    // A protected request answered 401 means the session is gone (expired or revoked).
    if (response.status === 401 && apiError.code === 'unauthenticated') onUnauthorized?.();
    throw apiError;
  }
  if (data === null || typeof data !== 'object') {
    // e.g. the API address points at a web page instead of the PHP API.
    throw new ApiError(
      response.status,
      'bad_response',
      'The server sent an unexpected response. Check that the API address is correct.',
    );
  }
  return data as T;
}

/* --------------------------------------------------------------- typed endpoints */

export type LoginResponse = {
  message: string;
  challenge_id: string;
  expires_in: number;
  email_hint: string;
  delivery: 'email' | 'development';
  dev_otp?: string; // present only when the server runs in explicit development mode
};

export type VerifyResponse = {
  message: string;
  user: User;
  expires_at: string;
  session: 'cookie' | 'token';
  token?: string; // native clients only
};

export type AdminUsersResponse = {
  users: User[];
  total: number;
  summary: { admins: number; users: number; active_sessions: number };
};

export const api = {
  register: (name: string, email: string, password: string) =>
    apiRequest<{ message: string; user: User }>('/register', { method: 'POST', body: { name, email, password } }),
  login: (email: string, password: string) =>
    apiRequest<LoginResponse>('/login', { method: 'POST', body: { email, password } }),
  verifyOtp: (challengeId: string, otp: string) =>
    apiRequest<VerifyResponse>('/verify-otp', { method: 'POST', body: { challenge_id: challengeId, otp } }),
  resendOtp: (challengeId: string) =>
    apiRequest<LoginResponse>('/resend-otp', { method: 'POST', body: { challenge_id: challengeId } }),
  me: () => apiRequest<{ user: User }>('/me'),
  logout: () => apiRequest<{ message: string }>('/logout', { method: 'POST' }),
  adminUsers: () => apiRequest<AdminUsersResponse>('/admin/users'),
};

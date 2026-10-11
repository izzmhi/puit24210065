import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { api, ApiError, LoginResponse, setBearerToken, setUnauthorizedHandler, User } from './api';
import { clearToken, loadToken, saveToken } from './storage';

// Password was correct, waiting for the OTP code. Only kept in memory.
export type PendingChallenge = {
  challengeId: string;
  emailHint: string;
  delivery: 'email' | 'development';
  devOtp?: string;
};

type Status = 'loading' | 'signedOut' | 'signedIn';

type AuthContextValue = {
  status: Status;
  user: User | null;
  pending: PendingChallenge | null;
  flash: string | null;
  clearFlash: () => void;
  signIn: (email: string, password: string) => Promise<void>;
  verifyOtp: (code: string) => Promise<void>;
  resendOtp: () => Promise<void>;
  cancelPending: () => void;
  register: (name: string, email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

function toPending(r: LoginResponse): PendingChallenge {
  return { challengeId: r.challenge_id, emailHint: r.email_hint, delivery: r.delivery, devOtp: r.dev_otp };
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [user, setUser] = useState<User | null>(null);
  const [pending, setPending] = useState<PendingChallenge | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  const endSession = useCallback(async (message?: string) => {
    setBearerToken(null);
    await clearToken();
    setUser(null);
    setPending(null);
    setStatus('signedOut');
    if (message) setFlash(message);
  }, []);

  // When the app starts, check if there is already a valid session.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setBearerToken(await loadToken());
      try {
        const { user: me } = await api.me();
        if (!cancelled) {
          setUser(me);
          setStatus('signedIn');
        }
      } catch {
        if (!cancelled) await endSession();
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [endSession]);

  // If the server rejects our session while logged in, go back to the login page.
  const statusRef = useRef<Status>('loading');
  statusRef.current = status;
  useEffect(() => {
    setUnauthorizedHandler(() => {
      if (statusRef.current === 'signedIn') void endSession('Your session has ended. Please log in again.');
    });
    return () => setUnauthorizedHandler(null);
  }, [endSession]);

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      user,
      pending,
      flash,
      clearFlash: () => setFlash(null),

      async signIn(email, password) {
        setFlash(null);
        setPending(toPending(await api.login(email, password)));
      },

      async verifyOtp(code) {
        if (!pending) throw new ApiError(0, 'no_challenge', 'Please log in again.');
        const result = await api.verifyOtp(pending.challengeId, code.trim());
        if (result.token) {
          await saveToken(result.token); // phone only: the web uses an HttpOnly cookie
          setBearerToken(result.token);
        }
        setUser(result.user);
        setPending(null);
        setStatus('signedIn');
      },

      async resendOtp() {
        if (!pending) throw new ApiError(0, 'no_challenge', 'Please log in again.');
        setPending(toPending(await api.resendOtp(pending.challengeId)));
      },

      cancelPending() {
        setPending(null);
      },

      async register(name, email, password) {
        const result = await api.register(name, email, password);
        setFlash(`${result.message} Log in with ${email.trim().toLowerCase()}.`);
      },

      async signOut() {
        try {
          await api.logout(); // revokes the session on the server
        } catch {
          // forget the session locally even if the request fails
        }
        await endSession('You have been logged out.');
      },
    }),
    [status, user, pending, flash, endSession],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>');
  return ctx;
}

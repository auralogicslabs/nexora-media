import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type ToastType = 'success' | 'info' | 'warning' | 'error';
export interface ToastData { id: string; type: ToastType; title: string; message?: string }

interface AppState {
  sidebarCollapsed: boolean;
  onboardingComplete: boolean;
  toasts: ToastData[];
  // Global optimization run — lives in the store so it survives page
  // navigation (a page-scoped loop would die when you switch tabs).
  optimizing: boolean;
  optimizeDone: number;
  optimizeTotal: number;
  toggleSidebar: () => void;
  completeOnboarding: () => void;
  addToast: (type: ToastType, title: string, message?: string) => void;
  dismissToast: (id: string) => void;
  runOptimization: (total: number, onDone?: () => void) => Promise<void>;
  stopOptimization: () => void;
}

// Small REST helper that mirrors the app's ApiClient envelope handling but is
// usable from the store (which can't import the React api client cleanly).
async function storePost(path: string): Promise<any> {
  const c = (typeof window !== 'undefined' && (window as any).NexoraMedia) || {};
  const base = c.apiUrl ?? '/wp-json/nexora-media/v1/';
  const res = await fetch(base + path.replace(/^\//, ''), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce ?? '' },
  });
  let data: any = null;
  try { data = await res.json(); } catch { throw new Error(`HTTP ${res.status}`); }
  if (!res.ok) throw new Error(data?.message ? `${data.message} (HTTP ${res.status})` : `HTTP ${res.status}`);
  return data?.data ?? data;
}

const ctx = (typeof window !== 'undefined' && (window as any).NexoraMedia) || {};
const serverInstallId = String(ctx.installId ?? '');
const serverOnboarded = !!ctx.onboardingComplete;

const STORAGE_KEY = 'nexora-media-prefs';
const INSTALL_KEY = 'nexora-media-install-id';

if (typeof window !== 'undefined' && serverInstallId) {
  try {
    const cached = window.localStorage.getItem(INSTALL_KEY) ?? '';
    if (cached !== serverInstallId) {
      window.localStorage.removeItem(STORAGE_KEY);
      window.localStorage.setItem(INSTALL_KEY, serverInstallId);
    }
  } catch { /* localStorage blocked, no-op */ }
}

export const useAppStore = create<AppState>()(
  persist(
    (set, get) => ({
      sidebarCollapsed: false,
      onboardingComplete: serverOnboarded,
      toasts: [],
      optimizing: false,
      optimizeDone: 0,
      optimizeTotal: 0,
      toggleSidebar: () => set((s) => ({ sidebarCollapsed: !s.sidebarCollapsed })),
      completeOnboarding: () => {
        set({ onboardingComplete: true });
        try {
          const apiUrl = ctx.apiUrl ?? '/wp-json/nexora-media/v1/';
          fetch(apiUrl + 'onboarding/complete', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': ctx.nonce ?? '',
            },
          }).catch(() => undefined);
        } catch { /* ignore */ }
      },
      addToast: (type, title, message) =>
        set((s) => ({
          toasts: [
            ...s.toasts,
            { id: `${Date.now()}-${Math.random()}`, type, title, message },
          ],
        })),
      dismissToast: (id) =>
        set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),

      stopOptimization: () => set({ optimizing: false }),

      // Drives the optimization queue from the browser, one batch at a time,
      // until the server reports nothing pending. Lives in the store so it
      // keeps running as the user navigates between pages.
      runOptimization: async (total, onDone) => {
        if (get().optimizing) return;
        set({ optimizing: true, optimizeDone: 0, optimizeTotal: total });
        let done = 0;
        let consecutiveErrors = 0;
        let noProgress = 0;
        const maxIterations = total * 4 + 40;
        try {
          for (let i = 0; i < maxIterations; i++) {
            if (!get().optimizing) break; // user hit Stop
            let res: any;
            try {
              res = await storePost('queue/process');
              consecutiveErrors = 0;
            } catch (e: any) {
              consecutiveErrors++;
              if (consecutiveErrors >= 4) {
                get().addToast('error', 'Optimization stopped', e?.message || 'The server stopped responding.');
                break;
              }
              await new Promise((r) => setTimeout(r, 600));
              continue;
            }

            if (res?.paused) break;

            const pending = res?.pending ?? 0;
            done = Math.max(done, total - pending);
            set({ optimizeDone: done });
            onDone?.(); // let the caller refresh queries periodically

            if (pending <= 0) break;

            if ((res?.processed ?? 0) === 0) {
              noProgress++;
              if (noProgress >= 8) break;
            } else {
              noProgress = 0;
            }
          }
          if (done >= total) {
            get().addToast('success', 'Optimization complete', `${done} image(s) optimized.`);
          }
        } finally {
          set({ optimizing: false, optimizeDone: 0, optimizeTotal: 0 });
          onDone?.();
        }
      },
    }),
    {
      name: STORAGE_KEY,
      partialize: (s) => ({ sidebarCollapsed: s.sidebarCollapsed, onboardingComplete: s.onboardingComplete }),
    },
  ),
);

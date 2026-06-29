import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export type ToastType = 'success' | 'info' | 'warning' | 'error';
export interface ToastData { id: string; type: ToastType; title: string; message?: string }

interface AppState {
  sidebarCollapsed: boolean;
  onboardingComplete: boolean;
  toasts: ToastData[];
  toggleSidebar: () => void;
  completeOnboarding: () => void;
  addToast: (type: ToastType, title: string, message?: string) => void;
  dismissToast: (id: string) => void;
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
    (set) => ({
      sidebarCollapsed: false,
      onboardingComplete: serverOnboarded,
      toasts: [],
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
    }),
    {
      name: STORAGE_KEY,
      partialize: (s) => ({ sidebarCollapsed: s.sidebarCollapsed, onboardingComplete: s.onboardingComplete }),
    },
  ),
);

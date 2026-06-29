import React from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, AlertOctagon, Sparkles, RefreshCw, ArrowRight } from 'lucide-react';
import { NavLink } from 'react-router-dom';
import { api } from '../../lib/api';
import { useAppStore } from '../../lib/store';
import Spinner from './Spinner';

interface Recommendation {
  code: string;
  severity: 'high' | 'medium' | 'low' | string;
  message: string;
  fix: string;
}

interface Health {
  status: 'ok' | 'warning' | 'degraded' | 'stuck' | string;
  recent_failures: number;
  stale_lock: boolean;
  stale_lock_age: number;
  cron_healthy: boolean;
  cron_next_run: number | null;
  last_error: any | null;
  recommendations: Recommendation[];
}

/**
 * QueueHealthAlert
 *
 * Renders nothing when the queue is healthy. When the backend reports a
 * "stuck", "degraded", or "warning" status it surfaces an inline card with
 * recommendations and a one-click "Recover" button — so the user is never
 * left wondering why their queue isn't moving.
 */
export default function QueueHealthAlert({ health }: { health?: Health | null }) {
  const qc = useQueryClient();
  const { addToast } = useAppStore();

  const recover = useMutation({
    mutationFn: () => api.post<any>('queue/recover'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['library'] });
      addToast('success', 'Queue recovered', 'Stale lock cleared and per-image cooldowns reset.');
    },
    onError: (e: any) => addToast('error', 'Recovery failed', e?.message),
  });

  if (!health || health.status === 'ok') return null;

  const isStuck    = health.status === 'stuck';
  const isDegraded = health.status === 'degraded' || isStuck;

  const palette = isStuck
    ? { bg: '#FEF2F2', border: '#FECACA', text: '#7F1D1D', accent: '#DC2626', Icon: AlertOctagon }
    : isDegraded
      ? { bg: '#FFFBEB', border: '#FDE68A', text: '#78350F', accent: '#D97706', Icon: AlertTriangle }
      : { bg: '#F0F9FF', border: '#BAE6FD', text: '#075985', accent: '#0284C7', Icon: AlertTriangle };

  return (
    <div
      className="rounded-2xl p-4 flex items-start gap-3"
      style={{
        background: palette.bg,
        border: `1px solid ${palette.border}`,
        color: palette.text,
      }}
    >
      <div
        className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
        style={{ background: '#ffffff' }}
      >
        <palette.Icon className="w-5 h-5" style={{ color: palette.accent }} />
      </div>

      <div className="flex-1 min-w-0">
        <p className="text-sm font-bold">
          {isStuck     && 'Queue is stuck — recovery recommended'}
          {!isStuck && isDegraded && 'Queue is reporting failures'}
          {!isStuck && !isDegraded && 'Queue needs attention'}
        </p>

        <div className="mt-1.5 space-y-1.5">
          {health.recommendations.slice(0, 2).map((r) => (
            <div key={r.code} className="text-xs leading-relaxed">
              <p className="font-medium">{r.message}</p>
              <p className="opacity-80 mt-0.5 flex items-start gap-1">
                <Sparkles className="w-3 h-3 flex-shrink-0 mt-0.5" />
                <span>{r.fix}</span>
              </p>
            </div>
          ))}
        </div>

        <div className="mt-3 flex items-center gap-2 flex-wrap">
          {isStuck && (
            <button
              type="button"
              className="np-btn-primary text-xs"
              onClick={() => recover.mutate()}
              disabled={recover.isPending}
            >
              {recover.isPending ? <Spinner size="sm" /> : <RefreshCw className="w-3.5 h-3.5" />}
              Recover stuck queue
            </button>
          )}
          <NavLink to="/diagnostic" className="np-btn-secondary text-xs inline-flex">
            View diagnostic <ArrowRight className="w-3 h-3" />
          </NavLink>
        </div>
      </div>
    </div>
  );
}

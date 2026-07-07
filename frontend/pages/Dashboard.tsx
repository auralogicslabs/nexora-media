import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { NavLink } from 'react-router-dom';
import {
  Image as ImageIcon, Zap, ShieldCheck, Activity, Cpu, GalleryHorizontal,
  Sparkles, ArrowRight, Loader2, Play, Square, Network,
  CheckCircle2, AlertCircle,
} from 'lucide-react';
import { api } from '../lib/api';
import { useAppStore } from '../lib/store';
import PageHeader from '../components/ui/PageHeader';
import StatTile from '../components/ui/StatTile';
import Spinner from '../components/ui/Spinner';
import QueueHealthAlert from '../components/ui/QueueHealthAlert';
import { formatBytes } from '../lib/format';

export default function Dashboard() {
  const qc = useQueryClient();
  const { addToast } = useAppStore();

  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: () => api.get<any>('summary'),
    refetchInterval: (q) => (q.state.data?.queue?.running ? 2000 : 30_000),
  });

  const optimizing      = useAppStore((s) => s.optimizing);
  const optimizeDone    = useAppStore((s) => s.optimizeDone);
  const optimizeTotal   = useAppStore((s) => s.optimizeTotal);
  const runOptimization = useAppStore((s) => s.runOptimization);
  const stopOptimization = useAppStore((s) => s.stopOptimization);

  const startQueue = useMutation({
    mutationFn: () => api.post<any>('queue/start'),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      const queued = data?.queued ?? 0;
      if (queued > 0) {
        addToast('success', 'Optimization started', `Processing ${queued} image(s)…`);
        void runOptimization(queued, () => qc.invalidateQueries({ queryKey: ['summary'] }));
      } else {
        addToast('info', 'Nothing to optimize', 'All images are already optimized.');
      }
    },
    onError: (e: any) => addToast('error', 'Could not start', e?.message ?? 'Please try again.'),
  });

  const stopQueue = useMutation({
    mutationFn: () => api.post<any>('queue/stop'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      addToast('info', 'Optimization paused', 'No new images will be processed until you restart.');
    },
  });

  const s        = summary.data;
  const lib      = s?.library;
  const queue    = s?.queue;
  const engine   = s?.engine;
  const optimizedPct = lib?.total ? Math.round((lib.optimized / lib.total) * 100) : 0;

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="Overview"
        title="Dashboard"
        subtitle="Safe image optimization for WordPress — WebP variants, lazy load, frontend delivery"
        actions={
          (optimizing || queue?.running) ? (
            <button className="np-btn-secondary text-xs" onClick={() => { stopOptimization(); stopQueue.mutate(); }}>
              <Square className="w-3.5 h-3.5" /> Stop optimization
            </button>
          ) : (
            <button className="np-btn-primary text-xs" onClick={() => startQueue.mutate()} disabled={startQueue.isPending}>
              {startQueue.isPending ? <Spinner size="sm" /> : <Play className="w-3.5 h-3.5" />}
              Optimize all images
            </button>
          )
        }
      />

      <div className="p-6 space-y-5">
        {/* Health alert — only renders when queue needs attention */}
        <QueueHealthAlert health={s?.queue_health} />

        {/* Engine connected pill — quiet validation, only shown when active */}
        {s?.engine_bridge?.connected && (
          <div className="flex justify-end -mb-1">
            <span
              className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full"
              style={{
                background: '#ECFDF5',
                color: '#047857',
                boxShadow: 'inset 0 0 0 1px rgb(16 185 129 / 0.25)',
              }}
            >
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500" />
              Nexora Engine connected — image changes auto-sync to SSG
            </span>
          </div>
        )}

        {/* Live progress */}
        {(() => {
          const done    = optimizing ? optimizeDone  : (queue?.current_done ?? 0);
          const total   = optimizing ? optimizeTotal : (queue?.current_total ?? 0);
          const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
          if (!optimizing && !queue?.running) return null;
          return (
            <div
              className="rounded-2xl p-4"
              style={{
                background: 'linear-gradient(135deg, #F4FCEA 0%, #E5F8CC 100%)',
                border: '1px solid #CCEF9C',
              }}
            >
              <div className="flex items-center justify-between mb-2">
                <div className="flex items-center gap-2.5">
                  <Loader2 className="w-4 h-4 animate-spin text-lime-700" />
                  <span className="text-sm font-bold text-violet-900">Optimizing your library</span>
                  <span className="text-xs text-violet-700">{done}/{total} images</span>
                </div>
                <span className="text-sm font-bold text-lime-700">{percent}%</span>
              </div>
              <div className="h-2 rounded-full bg-white/60 overflow-hidden">
                <div className="h-full transition-all"
                  style={{
                    width: `${percent}%`,
                    background: 'linear-gradient(90deg, #4F8C10, #65B113)',
                  }} />
              </div>
              {!optimizing && queue?.current_label && (
                <p className="text-xs text-violet-700 mt-2 truncate">Working on: {queue.current_label}</p>
              )}
            </div>
          );
        })()}

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatTile
            icon={GalleryHorizontal}
            label="Library Images"
            value={lib?.total ?? '—'}
            accent="bg-violet-50 text-violet-700"
          />
          <StatTile
            icon={Zap}
            label="Optimized"
            value={lib?.optimized ?? '—'}
            accent="bg-lime-50 text-lime-700"
            suffix={lib ? `· ${optimizedPct}%` : undefined}
          />
          <StatTile
            icon={ShieldCheck}
            label="Delivered to visitors"
            value={lib ? formatBytes(lib.delivered_saved ?? 0) : '—'}
            accent="bg-emerald-50 text-emerald-700"
            suffix={lib?.delivered != null ? `· ${lib.delivered} of ${lib.optimized}` : undefined}
          />
          <StatTile
            icon={Cpu}
            label="Engine"
            value={engine?.name ?? '—'}
            accent="bg-amber-50 text-amber-700"
            suffix={engine?.webp_supported ? 'WebP ready' : 'WebP missing'}
          />
        </div>

        {/* Honest delivery note — only when some optimized images aren't served
            as WebP (delivery off globally/per-image, or a builder-safe skip).
            Keeps the report truthful: generated ≠ always delivered. */}
        {lib && lib.optimized > 0 && lib.delivered < lib.optimized && (
          <div
            className="rounded-xl px-4 py-3 flex items-start gap-2.5 text-xs leading-relaxed"
            style={{ background: '#FAF8F2', border: '1px solid #E8E2D4' }}
          >
            <ShieldCheck className="w-4 h-4 text-violet-700 flex-shrink-0 mt-0.5" />
            <p className="text-slate-700">
              <span className="font-bold text-slate-900">
                {lib.delivered} of {lib.optimized} optimized images are served as WebP
              </span>{' '}
              to visitors right now{' '}
              (<span className="font-semibold">{formatBytes(lib.delivered_saved ?? 0)}</span> delivered
              {lib.saved > lib.delivered_saved
                ? <>, of {formatBytes(lib.saved ?? 0)} generated</>
                : null}).
              The rest keep their original on purpose — logos, sliders, carousels and hero
              (LCP) images are left untouched to avoid breaking builders, or delivery is
              turned off for them.
            </p>
          </div>
        )}

        {/* Delivery health card + (only-if-installed) Engine bridge.
            When Engine isn't active we don't even hint at it — Media is its own product. */}
        <div className={`grid ${s?.engine_bridge?.connected ? 'md:grid-cols-2' : 'grid-cols-1'} gap-4`}>
          <DeliveryCard
            deliveryReady={!!s?.delivery_ready}
            webpEnabled={!!s?.webp_enabled}
            adaptiveEnabled={!!s?.adaptive_enabled}
            webpSupported={!!engine?.webp_supported}
          />
          {s?.engine_bridge?.connected && <BridgeCard engine={s.engine_bridge} />}
        </div>

        {/* Pipeline */}
        <PipelineCard summary={s} />
      </div>
    </div>
  );
}

function DeliveryCard({ deliveryReady, webpEnabled, adaptiveEnabled, webpSupported }: any) {
  return (
    <div className="np-card p-5">
      <div className="flex items-center gap-2.5 mb-3">
        <div className="w-9 h-9 rounded-xl bg-violet-100 flex items-center justify-center">
          <Activity className="w-4.5 h-4.5 text-violet-700" />
        </div>
        <div>
          <p className="np-section-label">Frontend Delivery</p>
          <h2 className="text-sm font-bold text-slate-900">
            {deliveryReady ? 'Public WebP delivery active' : 'Safe original delivery'}
          </h2>
        </div>
      </div>
      <p className="text-xs text-slate-600 leading-relaxed mb-3">
        {deliveryReady
          ? 'Eligible WordPress image URLs are replaced with optimized WebP for logged-out visitors. Editors and Elementor previews keep the original output.'
          : 'Enable WebP + Adaptive Delivery in Settings to start serving optimized output to public visitors.'}
      </p>
      <div className="space-y-1.5">
        <CheckRow ok={webpEnabled}     label="WebP generation enabled" />
        <CheckRow ok={adaptiveEnabled} label="Adaptive delivery enabled" />
        <CheckRow ok={webpSupported}   label="Server can encode WebP" />
      </div>
      {deliveryReady && (
        <div
          className="mt-3 rounded-lg px-3 py-2 flex items-start gap-2 text-[11px] leading-snug text-slate-600"
          style={{ background: '#FAF8F2', border: '1px solid #E8E2D4' }}
        >
          <ShieldCheck className="w-3.5 h-3.5 text-violet-700 flex-shrink-0 mt-px" />
          <span>
            Sliders, logos and hero (LCP) images keep their original on the frontend
            by design — this protects builder scripts and page-speed, so seeing a few
            original images there is expected, not a fault.
          </span>
        </div>
      )}
      <NavLink to="/delivery" className="np-btn-secondary text-xs mt-4 inline-flex">
        Manage delivery <ArrowRight className="w-3 h-3" />
      </NavLink>
    </div>
  );
}

function BridgeCard({ engine }: any) {
  const connected = !!engine?.connected;
  return (
    <div className="np-card p-5">
      <div className="flex items-center gap-2.5 mb-3">
        <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${
          connected ? 'bg-emerald-50' : 'bg-slate-100'
        }`}>
          <Network className={`w-4.5 h-4.5 ${connected ? 'text-emerald-600' : 'text-slate-500'}`} />
        </div>
        <div>
          <p className="np-section-label">Engine Bridge</p>
          <h2 className="text-sm font-bold text-slate-900">
            {connected ? 'Nexora Engine connected' : 'Standalone mode'}
          </h2>
        </div>
      </div>
      <p className="text-xs text-slate-600 leading-relaxed mb-3">
        {connected
          ? 'Image changes signal the SSG runtime — static mirrors stay in sync without manual rebuilds.'
          : 'Install Nexora Engine to enable static-site generation (SSG) and one-signal image cache invalidation.'}
      </p>
      <NavLink to="/bridge" className="np-btn-secondary text-xs inline-flex">
        View bridge status <ArrowRight className="w-3 h-3" />
      </NavLink>
    </div>
  );
}

function PipelineCard({ summary }: any) {
  const lib    = summary?.library;
  const queue  = summary?.queue;
  const deliv  = !!summary?.delivery_ready;
  const nodes = [
    {
      icon: GalleryHorizontal,
      label: 'Upload intake',
      detail: lib?.total != null ? `${lib.total} library images` : '—',
      state: 'ok',
    },
    {
      icon: Zap,
      label: 'Protected queue',
      detail: queue?.pending > 0 ? `${queue.pending} waiting` : 'Idle',
      state: queue?.pending > 0 ? 'warn' : 'ok',
    },
    {
      icon: ImageIcon,
      label: 'WebP variants',
      detail: lib ? `${lib.optimized} variants ready` : '—',
      state: lib?.optimized > 0 ? 'ok' : 'neutral',
    },
    {
      icon: Activity,
      label: 'Frontend delivery',
      detail: deliv ? 'Public visitors get WebP' : 'Safe mode',
      state: deliv ? 'ok' : 'neutral',
    },
  ];
  const stateBg: any = {
    ok:      'bg-lime-50    ring-1 ring-lime-200',
    warn:    'bg-amber-50   ring-1 ring-amber-200',
    neutral: 'bg-slate-50   ring-1 ring-slate-200',
  };
  const stateIcon: any = {
    ok:      'text-lime-700',
    warn:    'text-amber-700',
    neutral: 'text-slate-500',
  };
  return (
    <div className="np-card p-5">
      <div className="flex items-center gap-2.5 mb-4">
        <Sparkles className="w-4 h-4 text-violet-700" />
        <h2 className="text-sm font-bold text-slate-900">Optimization pipeline</h2>
      </div>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {nodes.map((n) => (
          <div key={n.label} className={`rounded-xl p-3 flex items-start gap-2.5 ${stateBg[n.state]}`}>
            <n.icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${stateIcon[n.state]}`} />
            <div className="min-w-0">
              <p className="text-xs font-bold text-slate-800">{n.label}</p>
              <p className="text-[11px] text-slate-600 mt-0.5 truncate">{n.detail}</p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function CheckRow({ ok, label }: { ok: boolean; label: string }) {
  return (
    <div className="flex items-center gap-2 text-xs">
      {ok ? <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" /> : <AlertCircle className="w-3.5 h-3.5 text-amber-600" />}
      <span className={ok ? 'text-slate-700' : 'text-slate-600'}>{label}</span>
    </div>
  );
}

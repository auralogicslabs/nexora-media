import React, { useState, useMemo, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Layers, CheckCircle2, AlertCircle, RefreshCw, ExternalLink,
  Zap, Wand2, Link2, Image as ImageIcon, Loader2, Play, Square,
  Search, ArrowUpDown, ArrowUp, ArrowDown, ShieldCheck,
} from 'lucide-react';
import { api, wpContext } from '../lib/api';
import { useAppStore } from '../lib/store';
import PageHeader from '../components/ui/PageHeader';
import StatTile from '../components/ui/StatTile';
import Spinner from '../components/ui/Spinner';
import QueueHealthAlert from '../components/ui/QueueHealthAlert';
import { formatBytes } from '../lib/format';

type Filter = 'all' | 'optimized' | 'needs' | 'original';
type SortKey = 'date' | 'size' | 'savings' | 'name';
type SortDir = 'asc' | 'desc';

const STATUS_META: Record<string, { bg: string; text: string; ring: string; dot: string; label: string }> = {
  optimized:           { bg: 'bg-lime-50',  text: 'text-lime-800',  ring: 'ring-lime-200',  dot: 'bg-lime-600',   label: 'Optimized' },
  'needs-optimization':{ bg: 'bg-amber-50', text: 'text-amber-700', ring: 'ring-amber-200', dot: 'bg-amber-500',  label: 'Needs work' },
  'original-delivery': { bg: 'bg-slate-100',text: 'text-slate-700', ring: 'ring-slate-200', dot: 'bg-slate-400',  label: 'Original delivery' },
};

export default function Library() {
  const qc = useQueryClient();
  const { addToast } = useAppStore();
  const ctx = wpContext();
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');
  const [sortKey, setSortKey] = useState<SortKey>('date');
  const [sortDir, setSortDir] = useState<SortDir>('desc');
  const [busyId, setBusyId] = useState<number | null>(null);

  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: () => api.get<any>('summary'),
    refetchInterval: (q) => (q.state.data?.queue?.running ? 1500 : 30_000),
  });
  const library = useQuery({
    queryKey: ['library', filter],
    queryFn: () => api.get<any>(`library?limit=300&filter=${filter}`),
  });

  const optimize = useMutation({
    mutationFn: (id: number) => api.post<any>(`attachment/${id}/optimize`),
    onMutate: (id) => setBusyId(id),
    onSettled: () => setBusyId(null),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['library'] });
      qc.invalidateQueries({ queryKey: ['summary'] });
      addToast('success', 'Image processed', data?.message ?? 'Optimization complete.');
    },
    onError: (e: any) => addToast('error', 'Optimization failed', e?.message),
  });
  const sync = useMutation({
    mutationFn: (id: number) => api.post<any>(`attachment/${id}/sync`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['library'] });
      addToast('success', 'Frontend sync ready', 'Public visitors can receive the optimized variant.');
    },
    onError: (e: any) => addToast('error', 'Sync failed', e?.message),
  });
  const toggleDelivery = useMutation({
    mutationFn: (id: number) => api.post<any>(`attachment/${id}/delivery`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['library'] });
      addToast('info', 'Delivery toggled', 'This image switched between original and optimized output.');
    },
  });
  // Browser-driven queue driver: after enqueuing, repeatedly ask the server to
  // process one batch until nothing is pending. This makes optimization work on
  // every host without relying on WP-Cron (which often never fires on LocalWP
  // and low-traffic sites).
  const drivingRef = useRef(false);
  // Driver-owned progress so the bar reflects the browser-driven run in real
  // time, independent of the 10s summary poll (which can't refetch while the
  // driver loop is busy awaiting batch calls).
  const [driveProgress, setDriveProgress] = useState<{ done: number; total: number } | null>(null);

  const driveQueue = async (total: number) => {
    if (drivingRef.current) return;
    drivingRef.current = true;
    setDriveProgress({ done: 0, total });
    let done = 0;
    let consecutiveErrors = 0;
    let noProgress = 0;
    try {
      // Safety cap: allow a generous number of iterations (one image can need a
      // couple of retries) but never loop forever.
      const maxIterations = total * 4 + 40;
      for (let i = 0; i < maxIterations; i++) {
        let res: any;
        try {
          res = await api.post<any>('queue/process');
          consecutiveErrors = 0;
        } catch (e: any) {
          // A single batch can fail (a very large image timing out, a transient
          // server hiccup). Don't kill the whole run — skip and keep going. Only
          // bail if the server errors several times in a row.
          consecutiveErrors++;
          if (consecutiveErrors >= 4) {
            addToast('error', 'Optimization stopped', e?.message || 'The server stopped responding.');
            break;
          }
          await new Promise((r) => setTimeout(r, 600));
          continue;
        }

        if (res?.paused) break;

        // Advance our own progress from the authoritative pending count.
        const pending = res?.pending ?? 0;
        done = Math.max(done, total - pending);
        setDriveProgress({ done, total });
        // Refresh the library rows periodically (not every tick — that's heavy).
        if (i % 3 === 0) qc.invalidateQueries({ queryKey: ['summary'] });

        if (pending <= 0) break;

        // Guard against a stuck image that neither processes nor drops out.
        if ((res?.processed ?? 0) === 0) {
          noProgress++;
          if (noProgress >= 8) {
            addToast('info', 'Optimization finished', 'Some images could not be optimized and were skipped.');
            break;
          }
        } else {
          noProgress = 0;
        }
      }
      addToast('success', 'Optimization complete', 'Your library has been optimized.');
    } finally {
      drivingRef.current = false;
      setDriveProgress(null);
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['library'] });
    }
  };

  const startQueue = useMutation({
    mutationFn: () => api.post<any>('queue/start'),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['library'] });
      const queued = data?.queued ?? 0;
      if (queued > 0) {
        addToast('success', 'Optimization started', `Processing ${queued} image(s)…`);
        void driveQueue(queued);
      } else {
        addToast('info', 'Nothing to optimize', 'All images are already optimized.');
      }
    },
    onError: (e: any) => addToast('error', 'Could not start', e?.message),
  });
  const stopQueue = useMutation({
    mutationFn: () => api.post<any>('queue/stop'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      addToast('info', 'Optimization paused', 'Queue cleared. Restart anytime.');
    },
  });

  const allItems = (library.data?.items ?? []) as any[];
  const lib   = summary.data?.library;
  const queue = summary.data?.queue;
  const running = !!queue?.running;

  // Filter by search + sort
  const items = useMemo(() => {
    const q = search.trim().toLowerCase();
    const filtered = q
      ? allItems.filter((i) =>
          (i.title ?? '').toLowerCase().includes(q) ||
          (i.filename ?? '').toLowerCase().includes(q))
      : allItems;
    const sorted = [...filtered].sort((a, b) => {
      let cmp = 0;
      if (sortKey === 'name')    cmp = (a.filename ?? '').localeCompare(b.filename ?? '');
      if (sortKey === 'size')    cmp = (a.source_bytes ?? 0) - (b.source_bytes ?? 0);
      if (sortKey === 'savings') cmp = (a.saved ?? 0) - (b.saved ?? 0);
      if (sortKey === 'date')    cmp = (a.id ?? 0) - (b.id ?? 0); // ID ~ recency
      return sortDir === 'asc' ? cmp : -cmp;
    });
    return sorted;
  }, [allItems, search, sortKey, sortDir]);

  const setSort = (k: SortKey) => {
    if (sortKey === k) {
      setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
    } else {
      setSortKey(k);
      setSortDir(k === 'name' ? 'asc' : 'desc');
    }
  };

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="Optimize"
        title="Media Library"
        subtitle="Optimize, sync delivery, and review per-image savings — one screen, full control"
        actions={
          running ? (
            <button className="np-btn-secondary text-xs" onClick={() => stopQueue.mutate()} disabled={stopQueue.isPending}>
              <Square className="w-3.5 h-3.5" /> Stop
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
        {/* Health alert — surfaces stale lock, repeated failures, cron problems */}
        <QueueHealthAlert health={summary.data?.queue_health} />

        {/* Live queue progress. Prefer the browser-driver's own counters (they
            update in real time); fall back to the polled queue status. */}
        {(() => {
          const showDrive = !!driveProgress;
          const done    = showDrive ? driveProgress!.done : (queue?.current_done ?? 0);
          const total   = showDrive ? driveProgress!.total : (queue?.current_total ?? 0);
          const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
          const visible = showDrive || running;
          if (!visible) return null;
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
              {!showDrive && queue?.current_label && (
                <p className="text-xs text-violet-700 mt-2 truncate">Working on: {queue.current_label}</p>
              )}
            </div>
          );
        })()}

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatTile icon={Layers}        label="Library total" value={lib?.total ?? '—'}     accent="bg-violet-50 text-violet-700" />
          <StatTile icon={CheckCircle2}  label="Optimized"     value={lib?.optimized ?? '—'} accent="bg-lime-50 text-lime-700"
            suffix={lib?.total ? `· ${Math.round((lib.optimized / lib.total) * 100)}%` : undefined} />
          <StatTile icon={AlertCircle}   label="Needs work"    value={lib ? Math.max(0, lib.total - lib.optimized) : '—'} accent="bg-amber-50 text-amber-700" />
          <StatTile icon={ShieldCheck}   label="Space saved"   value={lib ? formatBytes(lib.saved ?? 0) : '—'} accent="bg-emerald-50 text-emerald-700"
            suffix={lib?.saved_percent != null ? `· ${lib.saved_percent}%` : undefined} />
        </div>

        {/* Filter + search */}
        <div className="np-card p-4 flex flex-wrap items-center gap-3">
          <div
            className="flex rounded-xl overflow-hidden p-0.5"
            style={{ background: 'var(--np-border-soft)', border: '1px solid var(--np-border)' }}
          >
            {([
              { key: 'all',       label: 'All' },
              { key: 'needs',     label: 'Needs work' },
              { key: 'optimized', label: 'Optimized' },
              { key: 'original',  label: 'Original delivery' },
            ] as const).map((f) => (
              <button
                key={f.key}
                type="button"
                onClick={() => setFilter(f.key as Filter)}
                className={`px-3.5 py-1.5 text-xs font-bold transition-all rounded-lg ${
                  filter === f.key
                    ? 'bg-white text-violet-700 shadow-sm'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>

          <div className="relative flex-1 min-w-[200px] max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none z-10" />
            <input
              type="search"
              className="np-input !pl-9"
              placeholder="Search by filename…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>

          <div className="ml-auto text-xs text-slate-600">
            <strong className="text-slate-900">{items.length}</strong>
            {items.length !== allItems.length && (
              <span className="text-slate-500"> of {allItems.length}</span>
            )} image{items.length === 1 ? '' : 's'}
          </div>
        </div>

        {/* Table */}
        {library.isLoading ? (
          <div className="np-card p-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="h-12 my-2 rounded-lg np-skeleton" />
            ))}
          </div>
        ) : items.length === 0 ? (
          <div className="np-card p-12 text-center">
            <ImageIcon className="w-12 h-12 text-slate-400 mx-auto mb-3" />
            <h3 className="text-base font-bold text-slate-900 mb-1">
              {search ? 'No matches' : 'No images in this view'}
            </h3>
            <p className="text-sm text-slate-600">
              {search ? 'Try a different search term.' : 'Try a different filter, or upload images to your Media Library.'}
            </p>
          </div>
        ) : (
          <div className="np-card overflow-hidden">
            <div className="overflow-x-auto np-scrollbar">
              <table className="w-full text-sm">
                <thead className="np-table-head">
                  <tr>
                    <th style={{ width: '80px' }}></th>
                    <SortHeader  label="Image"   k="name"    sortKey={sortKey} sortDir={sortDir} onSort={setSort} />
                    <th>Status</th>
                    <SortHeader  label="Size"    k="size"    sortKey={sortKey} sortDir={sortDir} onSort={setSort} align="right" />
                    <SortHeader  label="Savings" k="savings" sortKey={sortKey} sortDir={sortDir} onSort={setSort} align="right" />
                    <th className="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((it) => (
                    <Row
                      key={it.id}
                      item={it}
                      busy={busyId === it.id}
                      onOptimize={() => optimize.mutate(it.id)}
                      onSync={() => sync.mutate(it.id)}
                      onToggle={() => toggleDelivery.mutate(it.id)}
                      adminUrl={ctx?.adminUrl ?? ''}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function SortHeader({
  label, k, sortKey, sortDir, onSort, align = 'left',
}: {
  label: string; k: SortKey; sortKey: SortKey; sortDir: SortDir;
  onSort: (k: SortKey) => void; align?: 'left' | 'right';
}) {
  const active = sortKey === k;
  return (
    <th className={align === 'right' ? 'text-right' : ''}>
      <button
        type="button"
        onClick={() => onSort(k)}
        className={`inline-flex items-center gap-1 hover:text-slate-900 transition-colors ${
          active ? 'text-slate-900' : ''
        }`}
      >
        {label}
        {active
          ? (sortDir === 'asc' ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />)
          : <ArrowUpDown className="w-3 h-3 opacity-40" />}
      </button>
    </th>
  );
}

function Row({ item, busy, onOptimize, onSync, onToggle, adminUrl }: any) {
  const meta = STATUS_META[item.status] ?? STATUS_META.optimized;
  const editLink = `${adminUrl}post.php?post=${item.id}&action=edit`;

  // Action priority: needs-optimization → Optimize. optimized + not synced → Sync.
  // optimized + already active or native → Toggle delivery.
  const primaryAction = (() => {
    if (item.status === 'needs-optimization') {
      return { label: 'Optimize', icon: Wand2, onClick: onOptimize, primary: true };
    }
    if (item.status === 'optimized' && item.sync_status !== 'active' && item.sync_status !== 'native') {
      return { label: 'Sync', icon: Link2, onClick: onSync, primary: true };
    }
    return {
      label: item.status === 'original-delivery' ? 'Use optimized' : 'Use original',
      icon: RefreshCw,
      onClick: onToggle,
      primary: false,
    };
  })();

  return (
    <tr className="border-b border-cream-200 last:border-0 hover:bg-cream-50/50 transition-colors">
      {/* Thumb */}
      <td className="px-4 py-2.5">
        {item.thumb ? (
          <img
            src={item.thumb} alt=""
            className="w-16 h-16 rounded-lg object-cover bg-cream-100 ring-1 ring-cream-200"
            loading="lazy"
          />
        ) : (
          <div className="w-16 h-16 rounded-lg bg-cream-100 ring-1 ring-cream-200 flex items-center justify-center">
            <ImageIcon className="w-6 h-6 text-slate-400" />
          </div>
        )}
      </td>

      {/* Name */}
      <td className="px-4 py-2.5 min-w-[200px] max-w-sm">
        <div className="min-w-0">
          <p className="text-sm font-bold text-slate-900 truncate">{item.title || item.filename}</p>
          <p className="text-[11px] text-slate-500 font-mono truncate">{item.filename}</p>
        </div>
      </td>

      {/* Status */}
      <td className="px-4 py-2.5">
        <span className={`inline-flex items-center gap-1.5 text-[11px] font-bold px-2 py-0.5 rounded-full ${meta.bg} ${meta.text} ring-1 ${meta.ring}`}>
          <span className={`w-1.5 h-1.5 rounded-full ${meta.dot}`} />
          {meta.label}
        </span>
      </td>

      {/* Size */}
      <td className="px-4 py-2.5 text-right whitespace-nowrap">
        <span className="text-xs text-slate-700 font-mono">{formatBytes(item.source_bytes ?? 0)}</span>
        {(item.best_bytes ?? 0) > 0 && (item.best_bytes !== item.source_bytes) && (
          <span className="text-[11px] text-slate-500 ml-1 font-mono">→ {formatBytes(item.best_bytes)}</span>
        )}
      </td>

      {/* Savings */}
      <td className="px-4 py-2.5 text-right whitespace-nowrap">
        {(item.saved ?? 0) > 0 ? (
          <span className="text-xs font-bold text-lime-700">−{formatBytes(item.saved)}</span>
        ) : (
          <span className="text-xs text-slate-400">—</span>
        )}
      </td>

      {/* Actions */}
      <td className="px-4 py-2.5 text-right whitespace-nowrap">
        <div className="inline-flex items-center gap-1.5">
          <button
            type="button"
            onClick={primaryAction.onClick}
            disabled={busy}
            className={primaryAction.primary ? 'np-btn-primary text-xs py-1 px-2.5' : 'np-btn-secondary text-xs py-1 px-2.5'}
          >
            {busy ? <Spinner size="sm" /> : <primaryAction.icon className="w-3 h-3" />}
            {primaryAction.label}
          </button>
          <a
            href={editLink}
            target="_blank" rel="noopener noreferrer"
            className="p-1.5 rounded-md text-slate-400 hover:text-violet-700 hover:bg-violet-50 transition-colors"
            title="Edit in Media Library"
          >
            <ExternalLink className="w-3.5 h-3.5" />
          </a>
        </div>
      </td>
    </tr>
  );
}

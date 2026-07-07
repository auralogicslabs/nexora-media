import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Cpu, Image as ImageIcon, Server, CheckCircle2, AlertCircle,
  Activity, HardDrive, Upload, Database, RefreshCw, AlertOctagon,
  Clock, Copy, Trash2,
} from 'lucide-react';
import { api } from '../lib/api';
import { useAppStore } from '../lib/store';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import QueueHealthAlert from '../components/ui/QueueHealthAlert';

export default function Diagnostic() {
  const qc = useQueryClient();
  const { addToast } = useAppStore();

  const { data, isLoading } = useQuery({
    queryKey: ['diagnostic'],
    queryFn: () => api.get<any>('diagnostic'),
  });
  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: () => api.get<any>('summary'),
    refetchInterval: 10_000,
  });
  const errorsQ = useQuery({
    queryKey: ['queue-errors'],
    queryFn: () => api.get<any>('queue/errors'),
    refetchInterval: 10_000,
  });

  const recover = useMutation({
    mutationFn: () => api.post('queue/recover'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['queue-errors'] });
      qc.invalidateQueries({ queryKey: ['diagnostic'] });
      addToast('success', 'Recovered', 'Stale lock cleared and per-image cooldowns reset.');
    },
    onError: (e: any) => addToast('error', 'Recovery failed', e?.message),
  });

  const erase = useMutation({
    mutationFn: () => api.post<any>('optimized/erase', { confirm: 'ERASE' }),
    onSuccess: (res: any) => {
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['library'] });
      qc.invalidateQueries({ queryKey: ['diagnostic'] });
      const files = res?.files_deleted ?? 0;
      const imgs = res?.images_reset ?? 0;
      addToast(
        'success',
        'Optimized files erased',
        `Deleted ${files} variant file${files === 1 ? '' : 's'} and reset ${imgs} image${imgs === 1 ? '' : 's'}. Originals were kept.`,
      );
    },
    onError: (e: any) => addToast('error', 'Erase failed', e?.message),
  });

  const confirmErase = () => {
    if (
      window.confirm(
        'Erase ALL optimized files?\n\n' +
        'This permanently deletes every generated WebP/AVIF variant and resets each image to "needs optimization". ' +
        'Your ORIGINAL images are never touched. You can re-optimize at any time.\n\n' +
        'Are you sure?',
      )
    ) {
      erase.mutate();
    }
  };

  const errors = (errorsQ.data?.errors ?? []) as any[];
  const health = summary.data?.queue_health;

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="System"
        title="Diagnostic"
        subtitle="Server capabilities, queue health, and recent errors"
        actions={
          <button
            type="button"
            className="np-btn-secondary text-xs"
            onClick={() => recover.mutate()}
            disabled={recover.isPending}
          >
            {recover.isPending ? <Spinner size="sm" /> : <RefreshCw className="w-3.5 h-3.5" />}
            Recover stuck queue
          </button>
        }
      />

      <div className="p-6 space-y-5">
        {/* Health alert — surfaces any queue issues prominently */}
        <QueueHealthAlert health={health} />

        {isLoading ? <Spinner size="lg" /> : data ? (
          <>
            {/* Server capabilities */}
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-3">
              <DiagCard icon={Cpu}       label="Image engine"   value={data.engine}      ok={data.engine !== 'None'} />
              <DiagCard icon={ImageIcon} label="WebP encoder"   value={data.webp ? 'Available' : 'Missing'} ok={data.webp} />
              <DiagCard icon={ImageIcon} label="AVIF encoder"   value={data.avif ? 'Available' : 'Missing'} ok={data.avif} neutral={!data.avif} />
              <DiagCard icon={Server}    label="PHP version"    value={data.php_version} ok />
              <DiagCard icon={Server}    label="WP version"     value={data.wp_version} ok />
              <DiagCard icon={Database}  label="Memory in use"  value={data.memory}     ok />
              <DiagCard icon={HardDrive} label="Memory limit"   value={data.memory_limit} ok />
              <DiagCard icon={Upload}    label="Upload limit"   value={data.upload_limit} ok />
              <DiagCard icon={Activity}  label="Plugin version" value={`v${data.plugin_version}`} ok />
            </div>

            {/* Queue health */}
            <div className="np-card p-5">
              <h2 className="text-sm font-bold text-slate-900 mb-3">Queue state</h2>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                <div>
                  <p className="text-2xl font-bold text-slate-900">{data.queue?.pending ?? 0}</p>
                  <p className="text-[11px] text-slate-600 mt-0.5">Pending</p>
                </div>
                <div>
                  <p className={`text-2xl font-bold ${data.queue?.paused ? 'text-amber-700' : 'text-emerald-700'}`}>
                    {data.queue?.paused ? 'Paused' : 'Active'}
                  </p>
                  <p className="text-[11px] text-slate-600 mt-0.5">State</p>
                </div>
                <div>
                  <p className={`text-2xl font-bold ${data.queue?.locked ? 'text-amber-700' : 'text-slate-900'}`}>
                    {data.queue?.locked ? 'Locked' : 'Unlocked'}
                  </p>
                  <p className="text-[11px] text-slate-600 mt-0.5">Worker lock</p>
                </div>
                <div>
                  <p className={`text-2xl font-bold ${
                    health?.cron_healthy === false ? 'text-amber-700' : 'text-slate-900'
                  }`}>
                    {health?.cron_healthy === false ? 'Issue' : 'OK'}
                  </p>
                  <p className="text-[11px] text-slate-600 mt-0.5">Cron health</p>
                </div>
              </div>
            </div>

            {/* Error log */}
            <div className="np-card p-5">
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-bold text-slate-900">Recent errors</h2>
                <span className="text-xs text-slate-500">{errors.length} entry{errors.length === 1 ? '' : 's'}</span>
              </div>
              {errors.length === 0 ? (
                <div className="text-center py-6">
                  <CheckCircle2 className="w-8 h-8 text-emerald-500 mx-auto mb-2" />
                  <p className="text-sm font-bold text-slate-900">No recent errors</p>
                  <p className="text-xs text-slate-600 mt-0.5">Optimization is running cleanly.</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {errors.map((e, i) => <ErrorRow key={i} entry={e} />)}
                </div>
              )}
            </div>

            {/* Image library backends */}
            <div className="np-card p-5">
              <h2 className="text-sm font-bold text-slate-900 mb-3">Image library backends</h2>
              <div className="grid md:grid-cols-2 gap-3">
                <BackendRow label="Imagick (preferred)" ok={data.imagick} />
                <BackendRow label="GD (fallback)"       ok={data.gd} />
              </div>
              {!data.imagick && !data.gd && (
                <p className="text-xs text-red-700 mt-3 leading-relaxed">
                  Neither Imagick nor GD is available. Ask your host to install an image library —
                  Nexora Media cannot generate variants without one.
                </p>
              )}
            </div>

            {/* Danger zone — erase all generated variants */}
            <div className="rounded-2xl bg-white ring-1 ring-red-200 overflow-hidden">
              <div className="px-5 py-4 border-b border-red-100 bg-red-50/60 flex items-center gap-2">
                <AlertOctagon className="w-4 h-4 text-red-600" />
                <h2 className="text-sm font-bold text-red-800">Danger zone</h2>
              </div>
              <div className="p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-bold text-slate-900">Erase all optimized files</p>
                  <p className="text-xs text-slate-500 mt-1 leading-relaxed">
                    Deletes every generated WebP/AVIF variant and resets each image to
                    “needs optimization”. Your original images are always kept — you can
                    re-optimize your whole library again at any time.
                  </p>
                </div>
                <button
                  type="button"
                  onClick={confirmErase}
                  disabled={erase.isPending}
                  className="np-btn-danger text-xs flex-shrink-0"
                >
                  {erase.isPending ? <Spinner size="sm" /> : <Trash2 className="w-3.5 h-3.5" />}
                  Erase optimized files
                </button>
              </div>
            </div>
          </>
        ) : null}
      </div>
    </div>
  );
}

function ErrorRow({ entry }: { entry: any }) {
  const { addToast } = useAppStore();
  const code = entry.code ?? 'unknown';
  const when = entry.time ? new Date(entry.time * 1000).toLocaleString() : '';
  const copy = () => {
    const payload = `[${code}] ${entry.message} · attachment ${entry.attachment_id ?? '?'} · ${entry.file ?? ''}`;
    navigator.clipboard?.writeText(payload);
    addToast('info', 'Copied', 'Error details copied to clipboard.');
  };
  return (
    <div className="flex items-start gap-3 p-3 rounded-lg bg-amber-50 ring-1 ring-amber-200">
      <AlertOctagon className="w-4 h-4 text-amber-700 flex-shrink-0 mt-0.5" />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <code className="text-[11px] font-mono font-bold text-amber-900 bg-amber-100 rounded px-1.5 py-0.5">{code}</code>
          {entry.attachment_id > 0 && (
            <span className="text-[11px] text-amber-700">Attachment #{entry.attachment_id}</span>
          )}
          <span className="text-[11px] text-amber-600 inline-flex items-center gap-1 ml-auto">
            <Clock className="w-3 h-3" /> {when}
          </span>
        </div>
        <p className="text-xs text-amber-900 mt-1 leading-relaxed break-words">{entry.message}</p>
        {entry.file && (
          <p className="text-[11px] font-mono text-amber-700 mt-1 truncate">{entry.file}</p>
        )}
      </div>
      <button
        type="button"
        onClick={copy}
        className="p-1.5 rounded-md text-amber-700 hover:bg-amber-100 transition-colors flex-shrink-0"
        title="Copy error details"
      >
        <Copy className="w-3.5 h-3.5" />
      </button>
    </div>
  );
}

function DiagCard({ icon: Icon, label, value, ok, neutral }: any) {
  const bg = ok ? 'bg-emerald-50 ring-1 ring-emerald-200'
    : neutral ? 'bg-slate-50 ring-1 ring-slate-200'
    : 'bg-amber-50 ring-1 ring-amber-200';
  return (
    <div className={`rounded-xl p-4 flex items-start gap-3 ${bg}`}>
      <Icon className={`w-4 h-4 mt-0.5 flex-shrink-0 ${ok ? 'text-emerald-600' : neutral ? 'text-slate-500' : 'text-amber-700'}`} />
      <div className="min-w-0">
        <p className="text-[10px] font-bold uppercase tracking-wide text-slate-600">{label}</p>
        <p className="text-sm font-bold text-slate-900 mt-0.5 truncate">{value}</p>
      </div>
    </div>
  );
}

function BackendRow({ label, ok }: any) {
  return (
    <div className={`rounded-lg p-3 flex items-center gap-2.5 ${ok ? 'bg-emerald-50' : 'bg-slate-50'}`}>
      {ok ? <CheckCircle2 className="w-4 h-4 text-emerald-600" /> : <AlertCircle className="w-4 h-4 text-slate-400" />}
      <span className="text-sm font-bold text-slate-900">{label}</span>
      <span className="ml-auto text-xs text-slate-600">{ok ? 'Available' : 'Missing'}</span>
    </div>
  );
}

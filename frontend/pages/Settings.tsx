import React, { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Save } from 'lucide-react';
import { api } from '../lib/api';
import { useAppStore } from '../lib/store';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';

export default function Settings() {
  const qc = useQueryClient();
  const { addToast } = useAppStore();
  const { data } = useQuery({ queryKey: ['settings'], queryFn: () => api.get<any>('settings') });
  const [form, setForm] = useState<any>(null);

  useEffect(() => {
    if (data && !form) setForm(data);
  }, [data]);

  const save = useMutation({
    mutationFn: () => api.post('settings', form ?? {}),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['summary'] });
      addToast('success', 'Saved', 'Settings updated.');
    },
    onError: (e: any) => addToast('error', 'Save failed', e?.message),
  });

  if (!form) {
    return (
      <div className="flex-1 overflow-y-auto np-scrollbar">
        <PageHeader eyebrow="System" title="Settings" subtitle="Encoding quality, sizes, and platform options" />
        <div className="p-6"><Spinner size="lg" /></div>
      </div>
    );
  }

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="System"
        title="Settings"
        subtitle="Encoding quality, responsive sizes, and platform options"
        actions={
          <button className="np-btn-primary text-xs" onClick={() => save.mutate()} disabled={save.isPending}>
            {save.isPending ? <Spinner size="sm" /> : <Save className="w-3.5 h-3.5" />}
            Save settings
          </button>
        }
      />

      <div className="p-6 space-y-5 max-w-2xl">
        <div className="np-card p-5 space-y-4">
          <h2 className="text-sm font-bold text-slate-900">Encoding</h2>
          <Field
            label="Image quality"
            help="1–100 — higher = larger files, better quality. 82 is a great default."
          >
            <input
              type="number" min={1} max={100}
              className="np-input max-w-[140px]"
              value={form.nxm_quality ?? 82}
              onChange={(e) => setForm({ ...form, nxm_quality: Number(e.target.value) })}
            />
          </Field>
          <Field label="Maximum image width" help="Source images larger than this are scaled down before encoding.">
            <input
              type="number" min={320} max={6000}
              className="np-input max-w-[160px]"
              value={form.nxm_max_width ?? 2560}
              onChange={(e) => setForm({ ...form, nxm_max_width: Number(e.target.value) })}
            />
          </Field>
          <Field
            label="Responsive widths"
            help="Comma-separated widths to generate as responsive variants. Default: 320,640,960,1600."
          >
            <input
              className="np-input font-mono text-xs"
              value={form.nxm_responsive_widths ?? '320,640,960,1600'}
              onChange={(e) => setForm({ ...form, nxm_responsive_widths: e.target.value })}
            />
          </Field>
        </div>

        <div className="np-card p-5 space-y-4">
          <h2 className="text-sm font-bold text-slate-900">Queue</h2>
          <Toggle
            label="Background queue"
            help="Process images in safe batches instead of on upload."
            checked={!!form.nxm_enable_queue}
            onChange={(v) => setForm({ ...form, nxm_enable_queue: v })}
          />
          <Toggle
            label="Auto-process via WP-Cron"
            help="Run the queue automatically. Off keeps everything manual + safer."
            checked={!!form.nxm_auto_process_queue}
            onChange={(v) => setForm({ ...form, nxm_auto_process_queue: v })}
          />
        </div>

        {/* Tiny attribution — no upsell, just identity */}
        <div className="text-center pt-4 pb-2">
          <p className="text-[11px] text-slate-500">
            Nexora Media · Built by{' '}
            <a
              href="https://auralogicslabs.com"
              target="_blank" rel="noopener noreferrer"
              className="font-semibold text-violet-700 hover:text-violet-900"
            >
              Auralogics Labs
            </a>
            {' '}· Free and GPL forever
          </p>
        </div>
      </div>
    </div>
  );
}

function Field({ label, help, children }: any) {
  return (
    <div>
      <label className="block text-xs font-bold text-slate-700 uppercase tracking-wide mb-1.5">{label}</label>
      {children}
      {help && <p className="text-[11px] text-slate-500 mt-1 leading-snug">{help}</p>}
    </div>
  );
}

function Toggle({ label, help, checked, onChange }: any) {
  return (
    <label className="flex items-start gap-3 cursor-pointer group">
      <input
        type="checkbox"
        className="mt-0.5 rounded border-cream-300 text-lime-600 focus:ring-violet-500"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
      />
      <div>
        <span className="text-sm font-bold text-slate-900 group-hover:text-violet-700 transition-colors">{label}</span>
        <p className="text-xs text-slate-600 mt-0.5 leading-relaxed">{help}</p>
      </div>
    </label>
  );
}

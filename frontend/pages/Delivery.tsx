import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  CheckCircle2, ShieldCheck, RefreshCw, AlertCircle,
  Sparkles, Network, Settings as Cog,
} from 'lucide-react';
import { api, wpContext } from '../lib/api';
import { useAppStore } from '../lib/store';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';

export default function Delivery() {
  const qc = useQueryClient();
  const { addToast } = useAppStore();
  const ctx = wpContext();
  const engineActive = !!ctx?.engineActive;

  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api.get<any>('settings') });
  const summary  = useQuery({ queryKey: ['summary'],  queryFn: () => api.get<any>('summary') });

  const save = useMutation({
    mutationFn: (patch: any) => api.post<any>('settings', patch),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['summary'] });
      addToast('success', 'Saved', 'Delivery settings updated.');
    },
    onError: (e: any) => addToast('error', 'Save failed', e?.message),
  });

  const purgeCache = useMutation({
    mutationFn: () => api.post<any>('cache/purge'),
    onSuccess: () => addToast('info', 'CSS cache marked for refresh', 'Existing files were preserved for safety.'),
  });

  const s         = settings.data ?? {};
  const supported = !!summary.data?.engine?.webp_supported;
  const ready     = !!summary.data?.delivery_ready;

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="Delivery"
        title="Frontend Delivery"
        subtitle="Control what visitors receive — WebP variants, lazy loading, and EXIF stripping"
      />

      <div className="p-6 space-y-5">
        {/* Status banner */}
        <div
          className="np-card p-4 flex items-start gap-3"
          style={ready
            ? { background: 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)', border: '1px solid #A7F3D0' }
            : { background: 'linear-gradient(135deg, #FAF8F2 0%, #F1ECDE 100%)', border: '1px solid #E8E2D4' }}
        >
          <div className="w-10 h-10 rounded-xl bg-white flex items-center justify-center flex-shrink-0">
            {ready
              ? <CheckCircle2 className="w-5 h-5 text-emerald-600" />
              : <ShieldCheck className="w-5 h-5 text-violet-700" />}
          </div>
          <div className="flex-1">
            <p className="text-sm font-bold text-slate-900">
              {ready ? 'Public WebP delivery is active' : 'Safe original delivery'}
            </p>
            <p className="text-xs text-slate-700 mt-0.5 leading-relaxed">
              {ready
                ? 'Logged-out visitors receive optimized WebP variants. Editors, Elementor previews, and admin requests always see the original.'
                : 'Enable WebP + Adaptive Delivery below to start serving optimized output. Editors always keep the original for safety.'}
            </p>
          </div>
        </div>

        {/* Safe delivery — the recommended path */}
        <div className="np-card p-5 space-y-4">
          <div className="flex items-center gap-2 mb-1">
            <ShieldCheck className="w-4 h-4 text-emerald-600" />
            <h2 className="text-sm font-bold text-slate-900">Safe delivery</h2>
            <span className="np-badge bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 text-[10px]">Recommended</span>
          </div>
          <p className="text-xs text-slate-600 leading-relaxed -mt-2 mb-2">
            Works flawlessly with WordPress core, themes, Elementor, Divi, Bricks, and any builder.
            Logged-in editors always see originals — nothing breaks while you edit.
          </p>

          <Toggle
            label="WebP generation"
            help="Build .webp variants alongside your original images."
            disabled={!supported}
            checked={!!s.nxm_enable_webp}
            onChange={(v) => save.mutate({ nxm_enable_webp: v })}
            note={!supported ? 'WebP not supported on this server' : undefined}
          />

          <Toggle
            label="Adaptive delivery"
            help="Swap WordPress image URLs for the generated WebP for logged-out visitors. Handles src, srcset, and content images automatically."
            checked={!!s.nxm_enable_adaptive}
            onChange={(v) => save.mutate({ nxm_enable_adaptive: v })}
          />

          <Toggle
            label="Lazy loading"
            help="Add loading=lazy + decoding=async for faster initial page paints. Respects hero images marked fetchpriority=high."
            checked={!!s.nxm_enable_lazyload}
            onChange={(v) => save.mutate({ nxm_enable_lazyload: v })}
          />

          <Toggle
            label="EXIF stripping"
            help="Remove camera and location metadata from generated variants."
            checked={!!s.nxm_strip_exif}
            onChange={(v) => save.mutate({ nxm_strip_exif: v })}
          />

          <Toggle
            label="AVIF generation"
            help="Build .avif variants when supported. Smaller files but slower to encode."
            badge="Experimental"
            checked={!!s.nxm_enable_avif}
            onChange={(v) => save.mutate({ nxm_enable_avif: v })}
          />
        </div>

        {/* Engine takeover notice — only when Engine is active */}
        {engineActive && (
          <div
            className="np-card p-4 flex items-start gap-3"
            style={{ background: 'linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%)', border: '1px solid #DDD6FE' }}
          >
            <div className="w-10 h-10 rounded-xl bg-white flex items-center justify-center flex-shrink-0">
              <Network className="w-5 h-5 text-violet-700" />
            </div>
            <div className="flex-1">
              <p className="text-sm font-bold text-violet-900">
                Nexora Engine is handling inline CSS rewriting
              </p>
              <p className="text-xs text-violet-800 mt-0.5 leading-relaxed">
                When Engine SSG is active, it rewrites image URLs inside builder backgrounds and
                inline styles during static rendering. Media stands down on the_content rewriting
                to avoid double-processing.
              </p>
            </div>
          </div>
        )}

        {/* Advanced — opt-in, with strong guardrails */}
        <div className="np-card p-5 space-y-4">
          <div className="flex items-center gap-2 mb-1">
            <Cog className="w-4 h-4 text-amber-700" />
            <h2 className="text-sm font-bold text-slate-900">Advanced delivery</h2>
            <span className="np-badge bg-amber-50 text-amber-700 ring-1 ring-amber-200 text-[10px]">Opt-in</span>
          </div>
          <p className="text-xs text-slate-600 leading-relaxed -mt-2 mb-2">
            For sites where Safe delivery isn't enough. These rewrite inline styles and stylesheets
            directly. {engineActive
              ? 'You probably don\'t need these — Nexora Engine already handles them during SSG.'
              : 'Test on a staging site first if you use a complex builder layout.'}
          </p>

          <Toggle
            label="CSS URL rewriting"
            help="Rewrite image URLs inside CSS files so background images load WebP. Adds a small cache layer in uploads."
            badge="Advanced"
            checked={!!s.nxm_enable_css_cache}
            onChange={(v) => save.mutate({ nxm_enable_css_cache: v })}
          />

          <Toggle
            label="Full DOM rewriting"
            help={'Aggressively rewrites inline style="background:url()" attributes during page render. Only needed if Adaptive Delivery + CSS rewriting aren\'t enough.'}
            badge="Experimental"
            checked={!!s.nxm_enable_dom_rewrite}
            onChange={(v) => save.mutate({ nxm_enable_dom_rewrite: v })}
          />

          <div className="pt-2 border-t border-cream-200">
            <button className="np-btn-secondary text-xs" onClick={() => purgeCache.mutate()} disabled={purgeCache.isPending}>
              {purgeCache.isPending ? <Spinner size="sm" /> : <RefreshCw className="w-3.5 h-3.5" />}
              Purge generated CSS cache
            </button>
            <p className="text-[11px] text-slate-500 mt-2 leading-snug">
              Existing CSS files are preserved during purge so live pages keep loading without interruption.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

function Toggle({ label, help, checked, onChange, disabled, note, badge }: any) {
  return (
    <label className={`flex items-start gap-3 ${disabled ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer group'}`}>
      <input
        type="checkbox"
        className="mt-0.5 rounded border-cream-300 text-lime-600 focus:ring-violet-500"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      <div className="flex-1 min-w-0">
        <span className="text-sm font-bold text-slate-900 group-hover:text-violet-700 transition-colors inline-flex items-center gap-2">
          {label}
          {badge && (
            <span className="np-badge bg-amber-50 text-amber-700 ring-1 ring-amber-200 text-[9px] font-bold uppercase">
              {badge}
            </span>
          )}
        </span>
        <p className="text-xs text-slate-600 mt-0.5 leading-relaxed">{help}</p>
        {note && (
          <p className="text-xs text-amber-700 mt-1 inline-flex items-center gap-1">
            <AlertCircle className="w-3 h-3" /> {note}
          </p>
        )}
      </div>
    </label>
  );
}

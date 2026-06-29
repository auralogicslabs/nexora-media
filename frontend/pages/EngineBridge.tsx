import React from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Network, CheckCircle2, ExternalLink, AlertCircle, Sparkles,
} from 'lucide-react';
import { api } from '../lib/api';
import PageHeader from '../components/ui/PageHeader';

export default function EngineBridge() {
  const summary = useQuery({ queryKey: ['summary'], queryFn: () => api.get<any>('summary') });
  const connected = !!summary.data?.engine_bridge?.connected;
  const changedAt = (summary.data?.engine_bridge?.changed_at ?? 0) as number;

  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="Delivery"
        title="Engine Bridge"
        subtitle="Static Site Generation (SSG) integration with Nexora Engine"
      />

      <div className="p-6 space-y-5">
        <div
          className="np-card p-6 flex items-start gap-4"
          style={connected
            ? { background: 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)', border: '1px solid #A7F3D0' }
            : { background: 'linear-gradient(135deg, #FAF8F2 0%, #F1ECDE 100%)', border: '1px solid #E8E2D4' }}
        >
          <div className="w-12 h-12 rounded-2xl bg-white flex items-center justify-center flex-shrink-0">
            <Network className={`w-6 h-6 ${connected ? 'text-emerald-600' : 'text-slate-500'}`} />
          </div>
          <div className="flex-1">
            <p className="text-[10px] font-bold uppercase tracking-[0.10em] text-violet-700 mb-1">
              {connected ? 'Connected' : 'Not connected'}
            </p>
            <h2 className="text-lg font-bold text-slate-900 leading-tight">
              {connected ? 'Nexora Engine is active' : 'Standalone optimization mode'}
            </h2>
            <p className="text-sm text-slate-700 mt-1.5 leading-relaxed max-w-2xl">
              {connected
                ? 'Every time an image variant is generated, Nexora Media signals the Engine SSG runtime so static mirrors stay in sync — no manual rebuilds, no stale images on cached pages.'
                : 'Install Nexora Engine to unlock static-site generation (SSG). Image changes will then trigger automatic mirror invalidation across the cache layer.'}
            </p>
            {connected && changedAt > 0 && (
              <p className="text-xs text-slate-600 mt-2">
                Last signal: {new Date(changedAt * 1000).toLocaleString()}
              </p>
            )}
          </div>
        </div>

        {/* What the bridge does */}
        <div className="np-card p-5">
          <div className="flex items-center gap-2.5 mb-4">
            <Sparkles className="w-4 h-4 text-violet-700" />
            <h2 className="text-sm font-bold text-slate-900">What the bridge does</h2>
          </div>
          <div className="grid md:grid-cols-2 gap-3">
            <BridgeCapability
              icon={CheckCircle2}
              ok={connected}
              title="Variant change signals"
              detail="Engine is notified the moment an image is optimized, so SSG can refresh mirrors with the new WebP variant."
            />
            <BridgeCapability
              icon={CheckCircle2}
              ok={connected}
              title="Delivery mode signals"
              detail="When you toggle WebP/Adaptive Delivery, Engine receives the update — no stale static markup."
            />
            <BridgeCapability
              icon={CheckCircle2}
              ok={connected}
              title="Safe purge integration"
              detail="Media plugin never force-purges Engine mirrors. Engine controls its own invalidation cycle."
            />
            <BridgeCapability
              icon={CheckCircle2}
              ok={connected}
              title="CSS cache awareness"
              detail="If you enable CSS URL rewriting, Engine knows when to rebuild static stylesheets too."
            />
          </div>
        </div>

        {/* Get Engine */}
        {!connected && (
          <div className="np-card p-6 text-center">
            <div
              className="w-16 h-16 rounded-3xl mx-auto mb-4 flex items-center justify-center"
              style={{ background: 'linear-gradient(135deg, #5F44BA 0%, #4A329C 100%)' }}
            >
              <Network className="w-8 h-8 text-white" />
            </div>
            <h3 className="text-lg font-bold text-slate-900 mb-1.5">Unlock static-site delivery</h3>
            <p className="text-sm text-slate-600 max-w-md mx-auto mb-4 leading-relaxed">
              Nexora Engine turns your WordPress site into a static-site-generated frontend.
              Combined with Media, your visitors get instant, optimized images served straight from cache.
            </p>
            <a
              href="https://auralogicslabs.com/nexora-engine"
              target="_blank" rel="noopener noreferrer"
              className="np-btn-primary inline-flex"
            >
              <ExternalLink className="w-4 h-4" /> Get Nexora Engine
            </a>
          </div>
        )}
      </div>
    </div>
  );
}

function BridgeCapability({ icon: Icon, ok, title, detail }: any) {
  return (
    <div className={`rounded-xl p-3.5 flex items-start gap-3 ${
      ok ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-slate-50 ring-1 ring-slate-200'
    }`}>
      <Icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${ok ? 'text-emerald-600' : 'text-slate-400'}`} />
      <div>
        <p className="text-sm font-bold text-slate-900">{title}</p>
        <p className="text-xs text-slate-600 mt-0.5 leading-relaxed">{detail}</p>
      </div>
    </div>
  );
}

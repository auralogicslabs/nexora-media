import React from 'react';
import {
  Cloud, Server, Globe, Sparkles, Clock, FlaskConical,
  Image as ImageIcon, Settings, Zap, ExternalLink,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';

type Status = 'shipping' | 'next' | 'researching';

interface Item {
  id: string;
  name: string;
  tag: string;
  status: Status;
  icon: React.FC<any>;
  iconBg: string;
  iconColor: string;
  detail: string;
}

// Honest roadmap. No upsells. No "Pro" gating for things we haven't built.
// Categories are user-value framed, not feature-list framed.
const ITEMS: Item[] = [
  {
    id: 'avif',
    name: 'AVIF generation',
    tag: 'Smaller files, same quality',
    status: 'next',
    icon: ImageIcon,
    iconBg: 'bg-violet-50',
    iconColor: 'text-violet-700',
    detail: 'Generate AVIF variants alongside WebP for browsers that support them — typically 20–30% smaller than WebP at equivalent quality.',
  },
  {
    id: 'cli',
    name: 'WP-CLI commands',
    tag: 'Bulk operations for power users',
    status: 'next',
    icon: Zap,
    iconBg: 'bg-lime-50',
    iconColor: 'text-lime-700',
    detail: 'Run optimization, diagnostics, and re-generation from the command line. Useful for staging-to-production workflows and CI pipelines.',
  },
  {
    id: 'cdn',
    name: 'CDN purge hooks',
    tag: 'Cache invalidation on optimization',
    status: 'researching',
    icon: Cloud,
    iconBg: 'bg-sky-50',
    iconColor: 'text-sky-700',
    detail: 'When a variant is generated or replaced, ping your CDN to purge the old asset. Cloudflare, BunnyCDN, KeyCDN, and Sucuri on the shortlist.',
  },
  {
    id: 'offload',
    name: 'Object storage offload',
    tag: 'S3-compatible variant hosting',
    status: 'researching',
    icon: Server,
    iconBg: 'bg-amber-50',
    iconColor: 'text-amber-700',
    detail: 'Move generated WebP variants to Cloudflare R2, AWS S3, or any S3-compatible storage to reduce server disk usage and improve global delivery.',
  },
  {
    id: 'webhook',
    name: 'Webhooks',
    tag: 'Notify external systems',
    status: 'researching',
    icon: Globe,
    iconBg: 'bg-emerald-50',
    iconColor: 'text-emerald-700',
    detail: 'Fire a webhook when an image is optimized, a variant is generated, or a queue run completes. For static-site builds, custom analytics, or audit logs.',
  },
  {
    id: 'auto-tune',
    name: 'Per-image auto-tune',
    tag: 'Quality based on content',
    status: 'researching',
    icon: Settings,
    iconBg: 'bg-rose-50',
    iconColor: 'text-rose-700',
    detail: 'Detect whether an image is a photo, illustration, or screenshot — and apply the optimal quality preset automatically rather than one global quality setting.',
  },
];

const STATUS_META: Record<Status, { label: string; bg: string; text: string; ring: string; Icon: any }> = {
  shipping:    { label: 'Available now', bg: 'bg-emerald-50', text: 'text-emerald-700', ring: 'ring-emerald-200', Icon: Sparkles },
  next:        { label: 'Up next',       bg: 'bg-amber-50',   text: 'text-amber-700',   ring: 'ring-amber-200',   Icon: Clock },
  researching: { label: 'Researching',   bg: 'bg-violet-50',  text: 'text-violet-700',  ring: 'ring-violet-200',  Icon: FlaskConical },
};

export default function Integrations() {
  return (
    <div className="flex-1 overflow-y-auto np-scrollbar">
      <PageHeader
        eyebrow="Platform"
        title="Roadmap"
        subtitle="What we're working on next — and what we're researching for later"
      />

      <div className="p-6 space-y-5">
        {/* Honest framing */}
        <div className="np-card p-5">
          <div className="flex items-start gap-3">
            <div className="w-9 h-9 rounded-xl bg-lime-50 flex items-center justify-center flex-shrink-0">
              <Sparkles className="w-4.5 h-4.5 text-lime-700" />
            </div>
            <div className="flex-1">
              <h2 className="text-sm font-bold text-slate-900 mb-1">Built in the open</h2>
              <p className="text-xs text-slate-600 leading-relaxed">
                Nexora Media is fully free and GPL. The features below are honest about where they are.
                No paywalls — when a feature ships, it ships to everyone. If you'd like to suggest something,{' '}
                <a
                  href="https://wordpress.org/support/plugin/nexora-media/"
                  target="_blank" rel="noopener noreferrer"
                  className="text-violet-700 font-bold hover:text-violet-900 inline-flex items-center gap-0.5"
                >
                  open a thread <ExternalLink className="w-3 h-3" />
                </a>.
              </p>
            </div>
          </div>
        </div>

        <div className="grid md:grid-cols-2 gap-4">
          {ITEMS.map((item) => (
            <Card key={item.id} item={item} />
          ))}
        </div>
      </div>
    </div>
  );
}

function Card({ item }: { item: Item }) {
  const Icon = item.icon;
  const status = STATUS_META[item.status];
  const StatusIcon = status.Icon;

  return (
    <div className="np-card p-5">
      <div className="flex items-start gap-3 mb-3">
        <div className={`w-11 h-11 rounded-2xl ${item.iconBg} flex items-center justify-center flex-shrink-0`}>
          <Icon className={`w-5 h-5 ${item.iconColor}`} />
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="text-sm font-bold text-slate-900">{item.name}</h3>
            <span className={`np-badge text-[10px] inline-flex items-center gap-1 ${status.bg} ${status.text} ring-1 ${status.ring}`}>
              <StatusIcon className="w-2.5 h-2.5" />
              {status.label}
            </span>
          </div>
          <p className="text-xs text-slate-600 mt-0.5">{item.tag}</p>
        </div>
      </div>
      <p className="text-xs text-slate-700 leading-relaxed">{item.detail}</p>
    </div>
  );
}

import React from 'react';
import { NavLink } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  LayoutDashboard, GalleryHorizontal, Network,
  Settings as SettingsIcon, Stethoscope, Plug2,
  ChevronLeft, ChevronRight,
  Cpu, Activity, Zap,
} from 'lucide-react';
import { useAppStore } from '../lib/store';
import { wpContext, api } from '../lib/api';

// ── Global scan progress bar — visible whenever the queue is running ──
function GlobalQueueBar() {
  const summary = useQuery({
    queryKey: ['summary'],
    queryFn: () => api.get<any>('summary'),
    refetchInterval: (q) => (q.state.data?.queue?.running ? 1500 : 8000),
  });
  const queue = summary.data?.queue;
  if (!queue?.running) return null;

  const pct = Math.max(2, Math.min(100, queue.percent ?? 0));
  return (
    <div
      className="fixed top-0 left-0 right-0 z-[9999] bg-white"
      style={{
        borderBottom: '1px solid var(--np-border)',
        boxShadow: '0 2px 12px rgb(132 204 44 / 0.14)',
      }}
    >
      <div
        className="h-[3px] transition-all duration-700 ease-out"
        style={{
          width: `${pct}%`,
          background: 'linear-gradient(90deg, #4F8C10, #65B113, #CCEF9C)',
        }}
      />
      <div className="flex items-center gap-3 px-4 py-1.5">
        <span className="relative flex h-2 w-2 flex-shrink-0">
          <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-lime-400 opacity-75" />
          <span className="relative inline-flex rounded-full h-2 w-2 bg-lime-500" />
        </span>
        <span className="text-xs font-semibold text-violet-800">
          Optimizing — {queue.current_done ?? 0} / {queue.current_total ?? 0} images
          {queue.current_label ? ` · ${queue.current_label}` : ''}
        </span>
        <span className="ml-auto text-xs font-bold text-violet-700 tabular-nums">{pct}%</span>
      </div>
    </div>
  );
}

interface NavItem {
  to: string;
  icon: React.FC<any>;
  label: string;
  badge?: string;
}

const NAV_GROUPS: { label: string; items: NavItem[] }[] = [
  { label: 'Overview', items: [{ to: '/', icon: LayoutDashboard, label: 'Dashboard' }] },
  {
    label: 'Optimize',
    items: [
      { to: '/library', icon: GalleryHorizontal, label: 'Media Library' },
    ],
  },
  {
    label: 'Delivery',
    items: [
      { to: '/delivery', icon: Activity, label: 'Frontend Delivery' },
    ],
  },
  {
    label: 'Platform',
    items: [{ to: '/integrations', icon: Plug2, label: 'Roadmap' }],
  },
  {
    label: 'System',
    items: [
      { to: '/diagnostic', icon: Stethoscope,  label: 'Diagnostic' },
      { to: '/settings',   icon: SettingsIcon, label: 'Settings' },
    ],
  },
];

function NavItemRow({ item, collapsed, isActive }: { item: NavItem; collapsed: boolean; isActive: boolean }) {
  const Icon = item.icon;
  return (
    <span
      className={`${collapsed ? 'justify-center px-0' : 'px-3'} np-nav-item
        ${isActive ? 'np-nav-item-active' : 'np-nav-item-inactive'}`}
      title={collapsed ? item.label : undefined}
    >
      <Icon
        className={`flex-shrink-0 transition-transform duration-150
          ${collapsed ? 'w-[18px] h-[18px]' : 'w-[17px] h-[17px]'}
          ${isActive ? 'scale-110' : ''}`}
        strokeWidth={isActive ? 2.5 : 2}
      />
      {!collapsed && <span className="flex-1 truncate">{item.label}</span>}
      {!collapsed && item.badge && (
        <span className="np-badge-pro text-[10px] px-1.5 py-0.5">{item.badge}</span>
      )}
    </span>
  );
}

// ── Nexora Family footer — discreet sibling-product surface ──
function NexoraFamilyFooter({
  pulseActive, engineActive, adminUrl,
}: {
  pulseActive: boolean; engineActive: boolean; adminUrl: string;
}) {
  const installed = [
    pulseActive && {
      id: 'pulse',
      name: 'Nexora Pulse',
      tag: 'SEO intelligence',
      url: adminUrl + 'admin.php?page=nexora-pulse',
      color: '#13716A',
    },
    engineActive && {
      id: 'engine',
      name: 'Nexora Engine',
      tag: 'Static delivery',
      url: adminUrl + 'admin.php?page=ncx-dashboard',
      color: '#5F44BA',
    },
  ].filter(Boolean) as Array<{ id: string; name: string; tag: string; url: string; color: string }>;

  return (
    <div className="px-3 pb-4" style={{ borderTop: '1px solid var(--np-border-dark)' }}>
      <p className="np-section-label-dark px-2 pt-3 mb-2">Nexora Family</p>

      {installed.length > 0 ? (
        <div className="space-y-1">
          {installed.map((p) => (
            <a
              key={p.id}
              href={p.url}
              className="flex items-center gap-2.5 px-2.5 py-1.5 rounded-lg transition-colors"
              style={{ color: 'var(--np-text-on-dark)' }}
              onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--np-bg-sidebar-hover)'; }}
              onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; }}
            >
              <span
                className="w-2 h-2 rounded-full flex-shrink-0"
                style={{ background: p.color, boxShadow: `0 0 0 2px ${p.color}33` }}
              />
              <div className="min-w-0 flex-1">
                <p className="text-[12px] font-semibold leading-tight truncate">{p.name}</p>
                <p className="text-[10px] leading-tight truncate" style={{ color: 'var(--np-text-on-dark-muted)' }}>
                  {p.tag} · Active
                </p>
              </div>
            </a>
          ))}
        </div>
      ) : (
        <a
          href="https://auralogicslabs.com"
          target="_blank" rel="noopener noreferrer"
          className="block px-2.5 py-2 rounded-lg transition-colors"
          style={{ color: 'var(--np-text-on-dark-muted)' }}
          onMouseEnter={(e) => {
            e.currentTarget.style.background = 'var(--np-bg-sidebar-hover)';
            (e.currentTarget as HTMLElement).style.color = 'var(--np-text-on-dark)';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.background = 'transparent';
            (e.currentTarget as HTMLElement).style.color = 'var(--np-text-on-dark-muted)';
          }}
        >
          <p className="text-[11px] leading-snug">
            Built by Auralogics Labs · <span style={{ color: 'var(--np-brand-primary)' }}>Explore the family →</span>
          </p>
        </a>
      )}
    </div>
  );
}

export default function Layout({ children }: { children: React.ReactNode }) {
  const { sidebarCollapsed, toggleSidebar } = useAppStore();
  const ctx = wpContext();
  const engineActive = !!ctx?.engineActive;
  const pulseActive  = !!ctx?.pulseActive;

  // Inject Engine Bridge into the Delivery group only when Engine is detected.
  // Keeps Media feeling self-contained when Engine isn't installed.
  const nav = NAV_GROUPS.map((group) => {
    if (group.label !== 'Delivery' || !engineActive) return group;
    return {
      ...group,
      items: [
        ...group.items,
        { to: '/bridge', icon: Network, label: 'Engine Bridge' },
      ],
    };
  });

  return (
    <div className="flex" style={{ background: 'var(--np-bg-page)', minHeight: 'var(--ncx-panel-h)' }}>
      <GlobalQueueBar />

      <aside
        className="flex-shrink-0 flex flex-col transition-all duration-200 ease-out np-scrollbar-dark"
        style={{
          width: sidebarCollapsed ? 'var(--np-sidebar-collapsed-w)' : 'var(--np-sidebar-w)',
          height: 'var(--ncx-panel-h)',
          position: 'sticky',
          top: 0,
          overflowY: 'auto',
          background: 'var(--np-bg-sidebar)',
          color: 'var(--np-text-on-dark)',
        }}
      >
        {/* Brand */}
        <div
          className={`flex items-center flex-shrink-0
            ${sidebarCollapsed ? 'justify-center py-5 px-0' : 'gap-3 px-5 py-5'}`}
          style={{ borderBottom: '1px solid var(--np-border-dark)' }}
        >
          {/* Nexora brand mark — the real brand icon on a white tile, matching
              the WP admin-menu icon, Nexora Pulse, and Nexora Engine. Using the
              actual asset (not a hand-traced SVG) keeps the mark crisp and
              identical across the family. */}
          <div
            className="w-9 h-9 flex items-center justify-center flex-shrink-0 overflow-hidden"
            style={{
              background: '#FFFFFF',
              boxShadow: '0 2px 10px rgb(46 31 98 / 0.45)',
              borderRadius: '8px',
            }}
          >
            <img
              src={`${ctx?.pluginUrl ?? ''}assets/img/nexora-icon.png`}
              alt="Nexora"
              width={26}
              height={26}
              className="w-[26px] h-[26px] object-contain"
            />
          </div>
          {!sidebarCollapsed && (
            <div className="min-w-0 flex-1">
              <div className="flex items-baseline gap-1.5">
                <span className="text-base font-bold tracking-tight text-white">Nexora</span>
                <span className="text-base font-bold np-text-gradient tracking-tight">Media</span>
              </div>
              <div className="flex items-center gap-1.5 mt-0.5">
                <span className="text-[10px] font-medium" style={{ color: 'var(--np-text-on-dark-muted)' }}>
                  by Auralogics
                </span>
                <span
                  className="np-badge text-[9px] px-1.5 py-px"
                  style={{
                    background: 'rgba(255,255,255,0.08)',
                    color: 'var(--np-text-on-dark-muted)',
                    boxShadow: 'inset 0 0 0 1px rgba(255,255,255,0.10)',
                  }}
                >
                  v{ctx?.version ?? ''}
                </span>
              </div>
            </div>
          )}
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-4 space-y-4 overflow-y-auto np-scrollbar-dark">
          {nav.map((group) => (
            <div key={group.label}>
              {!sidebarCollapsed && <p className="np-section-label-dark px-3 mb-1.5">{group.label}</p>}
              <div className="space-y-1">
                {group.items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.to === '/'} className="block">
                    {({ isActive }) => (
                      <NavItemRow item={item} collapsed={sidebarCollapsed} isActive={isActive} />
                    )}
                  </NavLink>
                ))}
              </div>
            </div>
          ))}
        </nav>

        {/* Footer */}
        <div
          className={`p-3 flex items-center flex-shrink-0 ${sidebarCollapsed ? 'justify-center' : ''}`}
          style={{ borderTop: '1px solid var(--np-border-dark)' }}
        >
          <button
            onClick={toggleSidebar}
            className={`inline-flex items-center justify-center p-2 rounded-xl transition-colors
              ${sidebarCollapsed ? '' : 'ml-auto'}`}
            style={{ color: 'var(--np-text-on-dark-muted)', background: 'transparent' }}
            onMouseEnter={(e) => {
              e.currentTarget.style.background = 'var(--np-bg-sidebar-hover)';
              e.currentTarget.style.color = 'var(--np-text-on-dark)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = 'transparent';
              e.currentTarget.style.color = 'var(--np-text-on-dark-muted)';
            }}
            title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {sidebarCollapsed ? <ChevronRight className="w-4 h-4" /> : <ChevronLeft className="w-4 h-4" />}
          </button>
        </div>

        {/* Nexora Family — quiet sibling-product surface.
            Only renders rows for plugins that are actually installed. If none, shows a single
            opt-in line so users can discover Auralogics products without ever feeling sold to. */}
        {!sidebarCollapsed && (
          <NexoraFamilyFooter pulseActive={pulseActive} engineActive={engineActive} adminUrl={ctx?.adminUrl ?? ''} />
        )}
      </aside>

      <main className="flex-1 flex flex-col min-w-0 np-animate-fade-in" style={{ minHeight: 'var(--ncx-panel-h)' }}>
        {children}
      </main>
    </div>
  );
}

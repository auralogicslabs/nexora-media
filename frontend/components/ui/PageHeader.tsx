import React from 'react';

interface Props {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
  eyebrow?: string;
}

export default function PageHeader({ title, subtitle, actions, eyebrow }: Props) {
  return (
    <div
      className="flex items-start justify-between px-7 pt-6 pb-5 flex-shrink-0"
      style={{
        borderBottom: '1px solid var(--np-border)',
        background: 'var(--np-bg-page)',
      }}
    >
      <div className="min-w-0">
        {eyebrow && (
          <p
            className="mb-2 inline-flex items-center gap-1.5 text-[10px] font-bold uppercase"
            style={{
              color: 'var(--np-brand-primary-hover)',
              letterSpacing: '0.10em',
            }}
          >
            <span
              className="inline-block w-1 h-1 rounded-full"
              style={{ background: 'var(--np-brand-primary-hover)' }}
            />
            {eyebrow}
          </p>
        )}
        <h1
          className="text-2xl font-bold leading-tight"
          style={{
            color: 'var(--np-text-primary)',
            letterSpacing: '-0.025em',
          }}
        >
          {title}
        </h1>
        {subtitle && (
          <p className="text-sm mt-1.5 leading-relaxed" style={{ color: 'var(--np-text-secondary)' }}>
            {subtitle}
          </p>
        )}
      </div>
      {actions && (
        <div className="flex items-center gap-2 ml-5 flex-shrink-0 mt-1">{actions}</div>
      )}
    </div>
  );
}

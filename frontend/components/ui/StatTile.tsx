import React from 'react';

export default function StatTile({
  icon: Icon, label, value, accent, suffix,
}: {
  icon: any; label: string; value: number | string; accent: string; suffix?: string;
}) {
  return (
    <div className="np-card-hover p-5">
      <div className="flex items-center gap-2.5 mb-3">
        <div className={`w-7 h-7 rounded-lg flex items-center justify-center ${accent}`}>
          <Icon className="w-3.5 h-3.5" />
        </div>
        <p className="text-[10px] font-bold uppercase tracking-[0.10em] text-slate-500">{label}</p>
      </div>
      <p className="text-3xl font-bold text-slate-900 leading-none tracking-tight">
        {value}
        {suffix && <span className="text-xs font-medium text-slate-500 ml-1.5">{suffix}</span>}
      </p>
    </div>
  );
}

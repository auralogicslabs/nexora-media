import React, { useEffect } from 'react';
import { CheckCircle2, AlertCircle, AlertTriangle, Info, X } from 'lucide-react';
import type { ToastData } from '../../lib/store';

const STYLES: Record<string, { bg: string; ring: string; text: string; Icon: any }> = {
  success: { bg: 'bg-emerald-50', ring: 'ring-emerald-200', text: 'text-emerald-700', Icon: CheckCircle2 },
  info:    { bg: 'bg-violet-50',  ring: 'ring-violet-200',  text: 'text-violet-700',  Icon: Info },
  warning: { bg: 'bg-amber-50',   ring: 'ring-amber-200',   text: 'text-amber-700',   Icon: AlertTriangle },
  error:   { bg: 'bg-red-50',     ring: 'ring-red-200',     text: 'text-red-700',     Icon: AlertCircle },
};

export function ToastContainer({ toasts, onDismiss }: { toasts: ToastData[]; onDismiss: (id: string) => void }) {
  return (
    <div className="fixed bottom-4 right-4 z-[10000] flex flex-col gap-2 max-w-sm">
      {toasts.map((t) => (
        <ToastItem key={t.id} toast={t} onDismiss={onDismiss} />
      ))}
    </div>
  );
}

function ToastItem({ toast, onDismiss }: { toast: ToastData; onDismiss: (id: string) => void }) {
  const s = STYLES[toast.type] ?? STYLES.info;
  const Icon = s.Icon;
  useEffect(() => {
    const t = setTimeout(() => onDismiss(toast.id), 5000);
    return () => clearTimeout(t);
  }, [toast.id, onDismiss]);
  return (
    <div className={`np-card-hover p-3 flex items-start gap-2.5 ring-1 ${s.ring} ${s.bg} np-animate-fade-in`}>
      <Icon className={`w-4 h-4 flex-shrink-0 mt-0.5 ${s.text}`} />
      <div className="flex-1 min-w-0">
        <p className={`text-sm font-bold ${s.text}`}>{toast.title}</p>
        {toast.message && <p className="text-xs text-slate-700 mt-0.5 leading-relaxed">{toast.message}</p>}
      </div>
      <button onClick={() => onDismiss(toast.id)} className="text-slate-400 hover:text-slate-700 transition-colors">
        <X className="w-3.5 h-3.5" />
      </button>
    </div>
  );
}

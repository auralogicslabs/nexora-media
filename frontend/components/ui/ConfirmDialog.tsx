import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Trash2, ShieldAlert, X } from 'lucide-react';
import Spinner from './Spinner';

type Tone = 'danger' | 'primary';

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  message: string;
  /** Optional bullet list of consequences shown in a subtle box. */
  details?: string[];
  /** When set, the user must type this exact string to enable the confirm button. */
  requireTyped?: string;
  confirmLabel?: string;
  tone?: Tone;
  /** Disables the confirm button + shows a spinner while the action runs. */
  busy?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Brand-aligned confirmation modal for destructive actions — replaces the
 * native window.confirm(). Self-contained: controlled via props, rendered
 * through a portal to <body> so it centers to the viewport, with ESC-to-cancel,
 * an optional typed-confirmation gate, and a focus-managed action button.
 */
export default function ConfirmDialog({
  open,
  title,
  message,
  details,
  requireTyped,
  confirmLabel = 'Confirm',
  tone = 'danger',
  busy = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const [typed, setTyped] = useState('');
  const confirmBtnRef = useRef<HTMLButtonElement>(null);

  // Reset the typed gate each time the dialog opens.
  useEffect(() => {
    if (open) {
      setTyped('');
      if (!requireTyped) confirmBtnRef.current?.focus();
    }
  }, [open, requireTyped]);

  // ESC cancels; Enter confirms when there's no typed gate.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.preventDefault(); if (!busy) onCancel(); }
      else if (e.key === 'Enter' && !requireTyped && !busy) { e.preventDefault(); onConfirm(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, busy, requireTyped, onConfirm, onCancel]);

  if (!open) return null;

  const gated  = !!requireTyped;
  const passed = !gated || typed === requireTyped;
  const isDanger = tone === 'danger';
  const Icon = isDanger ? Trash2 : ShieldAlert;

  return createPortal(
    <div
      className="fixed inset-0 z-[10000] flex items-center justify-center p-4"
      style={{ background: 'rgba(10,15,28,0.55)', backdropFilter: 'blur(2px)' }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="nxmedia-confirm-title"
      onClick={() => !busy && onCancel()}
    >
      <div
        className="w-full max-w-md relative rounded-2xl bg-white ring-1 ring-slate-200 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={() => !busy && onCancel()}
          aria-label="Cancel"
          className="absolute top-3 right-3 text-slate-400 hover:text-slate-700 transition-colors"
        >
          <X className="w-4 h-4" />
        </button>

        <div className="p-5">
          <div className="flex items-start gap-3 mb-3">
            <div
              className={`w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0 ${
                isDanger ? 'bg-red-50 ring-1 ring-red-200' : 'bg-slate-50 ring-1 ring-slate-200'
              }`}
            >
              <Icon className={`w-5 h-5 ${isDanger ? 'text-red-600' : 'text-slate-600'}`} strokeWidth={2.2} />
            </div>
            <div className="min-w-0 flex-1 pr-4">
              <h2 id="nxmedia-confirm-title" className="text-sm font-bold leading-tight text-slate-900">
                {title}
              </h2>
              <p className="text-xs mt-1 leading-snug text-slate-500">{message}</p>
            </div>
          </div>

          {details && details.length > 0 && (
            <ul className="rounded-lg p-3 mb-3 space-y-1.5 text-[11px] leading-snug bg-slate-50 ring-1 ring-slate-200 text-slate-700">
              {details.map((d) => (
                <li key={d} className="flex gap-2">
                  <span className="text-slate-400">•</span>
                  <span>{d}</span>
                </li>
              ))}
            </ul>
          )}

          {gated && (
            <label className="block mb-3">
              <span className="block text-[11px] font-semibold mb-1 text-slate-700">
                Type <code className="font-mono text-red-600">{requireTyped}</code> to confirm
              </span>
              <input
                type="text"
                value={typed}
                onChange={(e) => setTyped(e.target.value)}
                autoFocus
                className="np-input w-full text-xs"
                placeholder={requireTyped}
                spellCheck={false}
                autoComplete="off"
              />
            </label>
          )}

          <div className="flex items-center justify-end gap-2">
            <button type="button" onClick={onCancel} disabled={busy} className="np-btn-secondary text-xs">
              Cancel
            </button>
            <button
              ref={confirmBtnRef}
              type="button"
              onClick={() => passed && !busy && onConfirm()}
              disabled={!passed || busy}
              className={`${isDanger ? 'np-btn-danger' : 'np-btn-primary'} text-xs`}
              style={{ opacity: passed && !busy ? 1 : 0.55 }}
            >
              {busy ? <Spinner size="sm" /> : <Icon className="w-3.5 h-3.5" />}
              {confirmLabel}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}

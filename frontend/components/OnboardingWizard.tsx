import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
  Image as ImageIcon, Zap, ShieldCheck, Sparkles, Play,
  Check, X, ArrowRight,
} from 'lucide-react';
import { api } from '../lib/api';
import { useAppStore } from '../lib/store';
import Spinner from './ui/Spinner';

interface Step {
  id: string;
  icon: React.FC<any>;
  iconBg: string;
  iconColor: string;
  title: string;
  description: string;
  action?: string;
  skip?: boolean;
}

const STEPS: Step[] = [
  {
    id: 'welcome',
    icon: Sparkles,
    iconBg: 'bg-violet-50',
    iconColor: 'text-violet-700',
    title: 'Welcome to Nexora Media',
    description: 'Safe WebP delivery, background optimization, and SSG-aware image variants for WordPress. Setup takes under a minute.',
  },
  {
    id: 'safe',
    icon: ShieldCheck,
    iconBg: 'bg-emerald-50',
    iconColor: 'text-emerald-700',
    title: 'Safe by default',
    description: 'Editors, Elementor previews, and admin requests always see the original image. Only logged-out visitors receive optimized output — so nothing breaks while you build.',
  },
  {
    id: 'recommend',
    icon: Zap,
    iconBg: 'bg-lime-50',
    iconColor: 'text-lime-700',
    title: 'Apply recommended settings',
    description: 'Turn on WebP generation, adaptive delivery, lazy loading, and EXIF stripping. You can change any of these later in Delivery + Settings.',
    action: 'Apply safe defaults',
  },
  {
    id: 'optimize',
    icon: Play,
    iconBg: 'bg-violet-50',
    iconColor: 'text-violet-700',
    title: 'Optimize your library',
    description: 'Queue your existing images for background optimization. Smaller images go first so you see savings fast.',
    action: 'Start optimization',
    skip: true,
  },
];

function StepAction({ step, onComplete }: { step: Step; onComplete: () => void }) {
  const { addToast } = useAppStore();

  const applyRecommended = useMutation({
    mutationFn: () => api.post('wizard/complete', { apply_recommended: true }),
    onSuccess: () => {
      addToast('success', 'Settings applied', 'Safe defaults are now active.');
      onComplete();
    },
    onError: () => addToast('error', 'Could not apply', 'Try again from Settings.'),
  });

  const startQueue = useMutation({
    mutationFn: () => api.post('queue/start'),
    onSuccess: () => {
      addToast('success', 'Optimization started', 'Your library is processing in the background.');
      onComplete();
    },
    onError: () => addToast('error', 'Could not start', 'Try again from Optimization.'),
  });

  if (step.id === 'recommend') {
    return (
      <button className="np-btn-primary flex-1 justify-center" onClick={() => applyRecommended.mutate()} disabled={applyRecommended.isPending}>
        {applyRecommended.isPending ? <Spinner size="sm" /> : <Sparkles className="w-4 h-4" />}
        {step.action}
      </button>
    );
  }

  if (step.id === 'optimize') {
    return (
      <button className="np-btn-primary flex-1 justify-center" onClick={() => startQueue.mutate()} disabled={startQueue.isPending}>
        {startQueue.isPending ? <Spinner size="sm" /> : <Play className="w-4 h-4" />}
        {step.action}
      </button>
    );
  }

  return null;
}

export default function OnboardingWizard() {
  const { completeOnboarding } = useAppStore();
  const [stepIdx, setStepIdx] = useState(0);
  const step = STEPS[stepIdx];
  const isLast = stepIdx === STEPS.length - 1;

  const next = () => {
    if (isLast) completeOnboarding();
    else setStepIdx((i) => i + 1);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 backdrop-blur-sm p-4">
      <div
        className="bg-white rounded-3xl shadow-2xl w-full max-w-xl overflow-hidden np-animate-scale-in"
        style={{ boxShadow: '0 30px 80px rgb(15 23 42 / 0.35), 0 6px 20px rgb(15 23 42 / 0.18)' }}
      >
        <div className="flex items-center justify-between px-8 pt-6 pb-0">
          <div className="flex items-center gap-2.5">
            <div
              className="w-9 h-9 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{
                background: 'linear-gradient(135deg, #5F44BA 0%, #4A329C 100%)',
                boxShadow: '0 2px 10px rgb(74 50 156 / 0.45)',
              }}
            >
              <ImageIcon className="w-5 h-5 text-white" strokeWidth={2.2} />
            </div>
            <div className="flex items-baseline gap-1.5">
              <span className="text-base font-bold text-slate-900">Nexora</span>
              <span className="text-base font-bold np-text-gradient">Media</span>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <span className="text-xs font-semibold text-slate-600">{stepIdx + 1} of {STEPS.length}</span>
            <button
              onClick={completeOnboarding}
              className="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-cream-100 transition-colors"
              title="Skip setup"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>

        <div className="flex items-center gap-1.5 px-8 pt-5">
          {STEPS.map((s, i) => (
            <div
              key={s.id}
              className="h-1.5 flex-1 rounded-full transition-all duration-300"
              style={{
                background: i <= stepIdx
                  ? 'linear-gradient(90deg, #4F8C10, #65B113)'
                  : 'var(--np-border-soft)',
              }}
            />
          ))}
        </div>

        <div className="px-10 pt-8 pb-7">
          <div className={`w-16 h-16 rounded-2xl flex items-center justify-center mb-6 ${step.iconBg} ring-1 ring-current/10`}>
            <step.icon className={`w-8 h-8 ${step.iconColor}`} strokeWidth={2} />
          </div>
          <h2 className="text-2xl font-bold text-slate-900 mb-3 leading-tight tracking-tight">{step.title}</h2>
          <p className="text-sm text-slate-600 leading-relaxed">{step.description}</p>
        </div>

        <div className="px-10 pb-7 flex items-center gap-3">
          {step.id === 'welcome' ? (
            <button className="np-btn-primary flex-1 justify-center" onClick={next}>
              <Sparkles className="w-4 h-4" /> Get started
            </button>
          ) : (
            <>
              {step.action && <StepAction step={step} onComplete={next} />}
              {step.skip && (
                <button className="np-btn-secondary" onClick={next}>Skip for now</button>
              )}
              {!step.action && (
                <button className="np-btn-primary flex-1 justify-center" onClick={next}>
                  Next <ArrowRight className="w-4 h-4" />
                </button>
              )}
            </>
          )}

          {isLast && !step.action && (
            <button className="np-btn-primary flex-1 justify-center" onClick={completeOnboarding}>
              <Check className="w-4 h-4" /> Done
            </button>
          )}
        </div>

        <div className="border-t border-cream-200 px-10 py-3 flex justify-center bg-cream-50">
          <button onClick={completeOnboarding} className="text-xs text-slate-500 hover:text-slate-800 font-medium transition-colors">
            Skip setup — configure later
          </button>
        </div>
      </div>
    </div>
  );
}

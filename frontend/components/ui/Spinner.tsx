import React from 'react';
import { Loader2 } from 'lucide-react';

export default function Spinner({ size = 'md' }: { size?: 'sm' | 'md' | 'lg' }) {
  const cls = size === 'sm' ? 'w-3.5 h-3.5' : size === 'lg' ? 'w-6 h-6' : 'w-4 h-4';
  return <Loader2 className={`${cls} animate-spin text-violet-600`} />;
}

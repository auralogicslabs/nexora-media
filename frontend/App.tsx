import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import Layout from './components/Layout';
import Dashboard    from './pages/Dashboard';
import Library      from './pages/Library';
import Delivery     from './pages/Delivery';
import EngineBridge from './pages/EngineBridge';
import Integrations from './pages/Integrations';
import Settings     from './pages/Settings';
import Diagnostic   from './pages/Diagnostic';
import { ToastContainer } from './components/ui/Toast';
import OnboardingWizard from './components/OnboardingWizard';
import { useAppStore } from './lib/store';

export default function App() {
  const { toasts, dismissToast, onboardingComplete } = useAppStore();

  return (
    <>
      {!onboardingComplete && <OnboardingWizard />}
      <Layout>
        <Routes>
          <Route path="/"             element={<Dashboard />} />
          <Route path="/library"      element={<Library />} />
          {/* Legacy /optimization route redirects to Library — single workhorse page now. */}
          <Route path="/optimization" element={<Navigate to="/library" replace />} />
          <Route path="/delivery"     element={<Delivery />} />
          <Route path="/bridge"       element={<EngineBridge />} />
          <Route path="/integrations" element={<Integrations />} />
          <Route path="/settings"     element={<Settings />} />
          <Route path="/diagnostic"   element={<Diagnostic />} />
          <Route path="*"             element={<Navigate to="/" replace />} />
        </Routes>
      </Layout>
      <ToastContainer toasts={toasts} onDismiss={dismissToast} />
    </>
  );
}

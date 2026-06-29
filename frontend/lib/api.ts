declare const NexoraMedia: {
  apiUrl: string;
  nonce: string;
  adminUrl: string;
  siteUrl: string;
  pluginUrl: string;
  version: string;
  installId: string;
  onboardingComplete: boolean;
  engineActive: boolean;
  pulseActive: boolean;
  user: { id: number; name: string; email: string };
};

class ApiClient {
  private baseUrl: string;
  private nonce: string;

  constructor() {
    this.baseUrl = (window as any).NexoraMedia?.apiUrl ?? '/wp-json/nexora-media/v1/';
    this.nonce   = (window as any).NexoraMedia?.nonce ?? '';
  }

  private async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const url = this.baseUrl + path.replace(/^\//, '');
    const res = await fetch(url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': this.nonce,
        ...options.headers,
      },
    });
    const data = await res.json();
    if (!res.ok) {
      throw new Error(data?.message ?? `API error ${res.status}`);
    }
    // Match REST envelope { success: true, data: ... } the controllers return.
    return (data?.data ?? data) as T;
  }

  get<T>(path: string)       { return this.request<T>(path); }
  post<T>(path: string, body?: unknown) {
    return this.request<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
  }
  patch<T>(path: string, body?: unknown) {
    return this.request<T>(path, { method: 'PATCH', body: body ? JSON.stringify(body) : undefined });
  }
  delete<T>(path: string)    { return this.request<T>(path, { method: 'DELETE' }); }
}

export const api = new ApiClient();

export function wpContext() {
  return (window as any).NexoraMedia ?? {};
}

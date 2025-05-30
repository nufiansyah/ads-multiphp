export const BACKEND_URL: string = process.env.NEXT_PUBLIC_BACKEND_URL || '';

export interface VastParams {
  width: number;
  height: number;
  sid: string;
  ua: string;
  uip: string;
}

/**
 * Fetch VAST XML from backend
 */
export async function fetchVast(params: VastParams): Promise<string> {
  const search = new URLSearchParams({
    width: params.width.toString(),
    height: params.height.toString(),
    sid: params.sid,
    ua: encodeURIComponent(params.ua),
    uip: params.uip,
  });
  const res = await fetch(`${BACKEND_URL}/?${search.toString()}`);
  if (!res.ok) {
    throw new Error(`VAST request failed: ${res.status}`);
  }
  return res.text();
}

// Placeholder types
export interface Publisher {
  name: string;
  id: number;
  timeout: number;
  currency: string;
  dsp: string;
}

/**
 * Fetch list of publishers
 */
export async function getPublishers(): Promise<Publisher[]> {
  const res = await fetch(`${BACKEND_URL}/publishers`);
  if (!res.ok) throw new Error(`Fetch publishers failed: ${res.status}`);
  return res.json();
}

/**
 * Add a new publisher
 */
export async function addPublisher(pub: Publisher): Promise<void> {
  const formBody = Object
    .entries(pub)
    .map(([k,v]) =>
      encodeURIComponent(k) + '=' + encodeURIComponent(v)
    )
    .join('&');

  const res = await fetch(`${BACKEND_URL}/publishers`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formBody,
  });
  if (!res.ok) throw new Error(`Add publisher failed: ${res.status}`);
}

/**
 * Fetch demand sources
 */
export async function getDemandSources(): Promise<unknown[]> {
  const res = await fetch(`${BACKEND_URL}/demand-sources`);
  if (!res.ok) throw new Error(`Fetch demand sources failed: ${res.status}`);
  return res.json();
}

/**
 * Fetch analytics data
 */
export async function getAnalytics(): Promise<unknown> {
  const res = await fetch(`${BACKEND_URL}/analytics`);
  if (!res.ok) throw new Error(`Fetch analytics failed: ${res.status}`);
  return res.json();
}
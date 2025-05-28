import type { NextPage } from 'next';
import React, { useState, useEffect, FormEvent } from 'react';
import { Publisher, getPublishers, addPublisher } from '../service/api';

const Publishers: NextPage = () => {
  const [existing, setExisting] = useState<Publisher[]>([]);
  const [form, setForm] = useState<Publisher>({
    name: '',
    id: 0,
    timeout: 0,
    currency: 'USD',
    dsp: 'Select DSP',
  });
  const dsps = ['Select DSP', 'DSP One', 'DSP Two', 'DSP Three'];

  // Load existing publishers on mount
  useEffect(() => {
    getPublishers()
      .then(setExisting)
      .catch(console.error);
  }, []);

  function handleChange<K extends keyof Publisher>(key: K, value: Publisher[K]) {
    setForm(prev => ({ ...prev, [key]: value }));
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    try {
      await addPublisher(form);
      const updated = await getPublishers();
      setExisting(updated);
      // Reset form
      setForm({ name: '', id: 0, timeout: 0, currency: 'USD', dsp: 'Select DSP' });
    } catch (err) {
      console.error(err);
    }
  }

  return (
    <div className="max-w-3xl mx-auto">
      <h1 className="text-2xl font-bold mb-4">Publisher Manager</h1>
      <form onSubmit={handleSubmit} className="bg-white p-6 rounded shadow space-y-4">
        <div className="flex space-x-4">
          <input
            type="text"
            placeholder="Publisher Name"
            value={form.name}
            onChange={e => handleChange('name', e.target.value)}
            className="flex-1 border border-gray-300 rounded p-2"
            required
          />
          <input
            type="number"
            placeholder="ID"
            value={form.id}
            onChange={e => handleChange('id', Number(e.target.value))}
            className="w-24 border border-gray-300 rounded p-2"
            required
          />
        </div>
        <div className="flex space-x-4">
          <input
            type="number"
            placeholder="Timeout (ms)"
            value={form.timeout}
            onChange={e => handleChange('timeout', Number(e.target.value))}
            className="flex-1 border border-gray-300 rounded p-2"
            required
          />
          <select
            value={form.currency}
            onChange={e => handleChange('currency', e.target.value)}
            className="w-32 border border-gray-300 rounded p-2"
          >
            <option value="USD">USD</option>
            <option value="EUR">EUR</option>
            <option value="GBP">GBP</option>
          </select>
        </div>
        <div>
          <label className="block mb-1">Connect to DSP:</label>
          <select
            value={form.dsp}
            onChange={e => handleChange('dsp', e.target.value)}
            className="w-full border border-gray-300 rounded p-2"
          >
            {dsps.map(opt => (
              <option key={opt} value={opt}>{opt}</option>
            ))}
          </select>
        </div>
        <button
          type="submit"
          className="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600"
        >
          Add Publisher
        </button>
      </form>

      <h2 className="text-xl font-semibold mt-8">Existing Publishers</h2>
      <ul className="mt-2 space-y-2">
        {existing.map((pub, idx) => (
          <li key={idx} className="bg-gray-50 p-3 rounded border">
            <strong>{pub.name}</strong> (ID: {pub.id}) — {pub.timeout}ms — {pub.currency} — {pub.dsp}
          </li>
        ))}
      </ul>
    </div>
  );
};

export default Publishers;
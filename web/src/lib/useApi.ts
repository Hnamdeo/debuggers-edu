import { useCallback, useEffect, useState } from 'react'
import { ApiError } from './api'

/** Load data for a screen; reload() after a change. */
export function useApi<T>(load: () => Promise<T>, deps: unknown[]): { data: T | null; error: string | null; loading: boolean; reload: () => void } {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [tick, setTick] = useState(0)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(load, deps)
  useEffect(() => {
    let alive = true
    setLoading(true)
    run().then(d => { if (alive) { setData(d); setError(null) } })
      .catch(e => { if (alive) setError(e instanceof ApiError ? e.message : 'Something went wrong.') })
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [run, tick])
  return { data, error, loading, reload: () => setTick(n => n + 1) }
}

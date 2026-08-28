/** Formats a future timestamp as a compact presentation-only countdown. */
export function countdown(target) {
  const time = new Date(target).getTime();
  if (!Number.isFinite(time)) return "—";
  const d = Math.max(0, time - Date.now());
  const days = Math.floor(d / 86400000);
  const h = Math.floor((d % 86400000) / 3600000);
  const m = Math.floor((d % 3600000) / 60000);
  const s = Math.floor((d % 60000) / 1000);
  return `${days ? `${days}d ` : ""}${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

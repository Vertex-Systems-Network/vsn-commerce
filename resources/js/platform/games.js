import { useCallback, useEffect, useMemo, useState } from "react";
import { apiGet, apiPost } from "./api";

/** Handles use laravel games for the VSN Ecommerce interface. */
export function useLaravelGames() {
  const [games, setGames] = useState([]);
  const [entries, setEntries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const refresh = useCallback(/** Inline callback for this operation. */ async () => {
    const gameRows = await apiGet("/games");
    setGames(Array.isArray(gameRows) ? gameRows : []);
    let entryRows = [];
    try {
      const mine = await apiGet("/games/me/entries");
      entryRows = Array.isArray(mine) ? mine : [];
      setEntries(entryRows);
    } catch {
      setEntries([]);
    }
    setError("");
    return { games: gameRows, entries: entryRows };
  }, []);

  useEffect(/** Inline callback for this operation. */ () => {
    let live = true;
    apiGet("/games")
      .then(/** Inline callback for this operation. */ async (gameRows) => {
        if (!live) return;
        setGames(Array.isArray(gameRows) ? gameRows : []);
        try {
          const mine = await apiGet("/games/me/entries");
          if (live) setEntries(Array.isArray(mine) ? mine : []);
        } catch {
          if (live) setEntries([]);
        }
      })
      .catch(/** Inline callback for this operation. */ (err) => live && setError(err.message || "Games unavailable."))
      .finally(/** Inline callback for this operation. */ () => live && setLoading(false));
    return /** Inline callback for this operation. */ () => { live = false; };
  }, []);

  const join = useCallback(/** Inline callback for this operation. */ async (gameId, quantity = 1) => {
    const idempotencyKey = `game-${gameId}-${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`}`;
    const result = await apiPost(`/games/${gameId}/entries`, {
      entries: quantity,
      idempotencyKey,
      acceptRules: true,
    });
    await refresh();
    return result;
  }, [refresh]);

  const joinedGames = useMemo(/** Inline callback for this operation. */ () => entries.map(/** Inline callback for this operation. */ (entry) => ({
    ...entry,
    name: entry.game?.product?.name,
    image: entry.game?.product?.image,
    announcementAt: entry.game?.announcementAt,
    status: entry.game?.status,
    entryCoins: entry.game?.entryCoins,
    entries: entry.quantity,
  })), [entries]);

  return { games, entries, joinedGames, loading, error, refresh, join };
}

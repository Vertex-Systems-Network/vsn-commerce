# Scheduled AI Development Plan

This file governs scheduled ChatGPT development runs only. Interactive/chat development keeps using this repository's existing agent, AI, governance, roadmap, Issue and PR contracts. This schedule plan supplements them and must not weaken them.

## Bootstrap and authority
Every scheduled run must reconcile exact default-branch HEAD, repository instructions, durable AI state if present, accepted open Issues, open PRs, reviews, CI/runners and the repository's existing development plan/roadmap before choosing work. GitHub live evidence outranks stale schedule state or spreadsheet data. Continue the existing plan from the last safe checkpoint; never invent a parallel roadmap.

## Per-repository Google Sheet
Use the connected Google Drive app and one dedicated Sheet named `<repo> — Scheduled AI Development Report`. Locate/reuse it or create it on the first run. Append one row per run: timestamp, repo, main SHA, milestone, Issue, PR, PR head, CI/runner, security, action, result/blocker, next safe action. Never overwrite history. The Sheet is an audit ledger, not development authority. If Drive is unavailable, preserve GitHub truth/checkpoint and resume reporting later without fabrication.

## Continuous development
Keep advancing accepted authorized work according to the existing development plan. Continue accepted Issue/PR work before unrelated new work. Pending CI/runners are handoff boundaries, not completion. No busy-wait/repeated unchanged polling. Inspect/fix failures safely; never bypass required checks, reviews, security, authorization or external-evidence gates. Never fabricate evidence or use no-op/status-only commits as progress.

## Single-writer lease and handoff
Before mutation, revalidate live GitHub state and prior scheduled work. Use durable run identity/start, exact base/head, active Issue/PR and next-safe-action checkpoint metadata where repository state supports it. If a predecessor is stale/safely supersedable, resume from its last durable checkpoint after revalidation. If it may still be mutating and the platform cannot safely terminate it, do not create a competing writer: fail closed, checkpoint handoff and resume next run. Never assume a new schedule invocation can forcibly kill another process.

## PR safety and resume
Use the existing PR workflow. Before merge verify exact head, required checks, review/thread requirements, mergeability and main divergence. Merge only when existing policy and granted authority allow, then reconcile resulting main. A bounded run may end for completed iteration, pending dependency or safe handoff; the scheduled program resumes next run until documented project completion criteria are genuinely satisfied or the owner cancels it.

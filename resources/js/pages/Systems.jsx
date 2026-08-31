import { useCallback, useEffect, useState } from "react";
import SEO from "../components/SEO";
import { Badge, Button, Card, Field, SectionHeader, Status } from "../components/Toolkit";
import { apiGet, apiPost, apiPut } from "../platform/api";
import { useAuth } from "../platform/auth";
import { moneyFromMinor } from "../platform/cart";
import { FaMoneyBillWave, FaShieldAlt, FaStore, FaTag, FaUndo } from "react-icons/fa";

// P1-C compatibility surface: keep the existing named route-module contract while
// delegating unchanged Systems business state to the Laravel/API-authoritative implementation.
export {
	Orders,
	Checkout,
	Tracking,
	Wallet,
	Notifications,
	Messages,
	Settings,
	Gifts,
	AdminControl,
	ReturnsCenter,
	SavedAlerts,
	SellerQuality,
} from "./SystemsServer";

/** Renders the capability-composed admin operations route without cross-domain authority leaks. */
export function OperationsCenter() {
	const { hasPermission } = useAuth();
	const canViewFinance = hasPermission("finance.view");
	const canManageFinance = hasPermission("finance.manage");
	const canManageOperations = hasPermission("operations.manage");
	const [systemOps, setSystemOps] = useState(null);
	const [summary, setSummary] = useState(null);
	const [payouts, setPayouts] = useState([]);
	const [batches, setBatches] = useState([]);
	const [refs, setRefs] = useState({});
	const [operationsError, setOperationsError] = useState("");
	const [financeError, setFinanceError] = useState("");
	const [msg, setMsg] = useState("");
	const [busy, setBusy] = useState("");
	const [incidentDrafts, setIncidentDrafts] = useState({});
	const [operationsLoading, setOperationsLoading] = useState(true);
	const [financeLoading, setFinanceLoading] = useState(false);

	const loadOperations = useCallback(async () => {
		setOperationsLoading(true);
		try {
			setSystemOps(await apiGet("/admin/system/operations"));
			setOperationsError("");
		} catch (error) {
			setOperationsError(error.message || "Marketplace operations could not be loaded.");
		} finally {
			setOperationsLoading(false);
		}
	}, []);

	const loadFinance = useCallback(async () => {
		if (!canViewFinance) {
			setSummary(null);
			setPayouts([]);
			setBatches([]);
			setFinanceError("");
			setFinanceLoading(false);
			return;
		}
		setFinanceLoading(true);
		try {
			const [finance, payoutRows, batchRows] = await Promise.all([
				apiGet("/admin/finance"),
				apiGet("/admin/finance/payouts"),
				apiGet("/admin/finance/payout-batches"),
			]);
			setSummary(finance);
			setPayouts(Array.isArray(payoutRows) ? payoutRows : []);
			setBatches(Array.isArray(batchRows) ? batchRows : []);
			setFinanceError("");
		} catch (error) {
			setFinanceError(error.message || "Finance data could not be loaded.");
		} finally {
			setFinanceLoading(false);
		}
	}, [canViewFinance]);

	useEffect(() => {
		loadOperations();
	}, [loadOperations]);

	useEffect(() => {
		loadFinance();
	}, [loadFinance]);

	const incidentText = (id) => incidentDrafts[id] || "";
	const setIncidentText = (id, value) => setIncidentDrafts((current) => ({ ...current, [id]: value }));
	const incidentAction = async (incident, type) => {
		if (!canManageOperations) return;
		const message = incidentText(incident.id).trim();
		if (!message) {
			setMsg("Add an operator note before changing incident state.");
			return;
		}
		setBusy(`incident:${incident.id}:${type}`);
		setOperationsError("");
		try {
			if (type === "note") await apiPost(`/admin/system/operations/incidents/${incident.id}/notes`, { message });
			else if (type === "resolve") await apiPost(`/admin/system/operations/incidents/${incident.id}/resolve`, { summary: message });
			else await apiPut(`/admin/system/operations/incidents/${incident.id}/status`, { status: type, message });
			setIncidentText(incident.id, "");
			await loadOperations();
		} catch (error) {
			setOperationsError(error.message);
		} finally {
			setBusy("");
		}
	};

	const payoutAction = async (id, type, body = {}) => {
		if (!canManageFinance) return;
		setBusy(`${id}:${type}`);
		setFinanceError("");
		try {
			await apiPost(`/admin/finance/payouts/${id}/${type}`, body);
			await loadFinance();
		} catch (error) {
			setFinanceError(error.message);
		} finally {
			setBusy("");
		}
	};

	const reconcile = async () => {
		if (!canManageFinance) return;
		setBusy("reconcile");
		setFinanceError("");
		try {
			const result = await apiPost("/admin/finance/reconcile", {});
			setMsg(`Reconciliation ${result.status}: ${result.issuesCount} issue(s).`);
			await loadFinance();
		} catch (error) {
			setFinanceError(error.message);
		} finally {
			setBusy("");
		}
	};

	const createBatch = async () => {
		if (!canManageFinance) return;
		const ids = payouts.filter((payout) => payout.status === "approved" && !payout.batchId).map((payout) => payout.id);
		if (!ids.length) {
			setMsg("No approved unbatched payouts are waiting.");
			return;
		}
		setBusy("batch");
		setFinanceError("");
		try {
			const batch = await apiPost("/admin/finance/payout-batches", { payoutIds: ids });
			setMsg(`Payout batch ${batch.id} created with ${batch.payoutCount} payout(s).`);
			await loadFinance();
		} catch (error) {
			setFinanceError(error.message);
		} finally {
			setBusy("");
		}
	};

	const money = (value) => `Rs. ${moneyFromMinor(Number(value || 0)).toLocaleString()}`;
	const activeIncidents = (systemOps?.incidents || []).filter((incident) => incident.status !== "resolved");

	return <>
		<SEO title="Marketplace Operations | VSN Ecommerce Admin" description="Capability-scoped marketplace operations and finance controls." />
		<div className="simple-page">
			<div className="page-title"><h1>Marketplace operations</h1><p>Operational health, incident command and capability-scoped finance controls.</p></div>
			{operationsError && <Status>{operationsError}</Status>}
			{financeError && <Status>{financeError}</Status>}
			{msg && <p className="form-message">{msg}</p>}

			{operationsLoading && !systemOps ? <Card><p>Loading marketplace operations…</p></Card> : systemOps && <>
				<Card className="system-section">
					<SectionHeader title="Production health" sub="Database, Redis, storage, scheduler, queue workers and failed-job pressure" />
					<div className="finance-grid">{Object.entries(systemOps.health?.checks || {}).map(([name, check]) => <div key={name}><small>{name.replaceAll("_", " ")}</small><strong>{check.ok ? "Healthy" : "Needs attention"}</strong><span>{check.latencyMs != null ? `${check.latencyMs} ms` : check.ageSeconds != null ? `${check.ageSeconds}s since heartbeat` : ""}</span></div>)}</div>
					<div className="finance-grid" style={{ marginTop: 16 }}><div><small>Failed jobs</small><strong>{systemOps.health?.failedJobs ?? 0}</strong></div><div><small>Release</small><strong>{systemOps.health?.app?.version || "unknown"}</strong></div><div><small>Recent backups</small><strong>{systemOps.backups?.filter((backup) => backup.status === "completed").length || 0}</strong></div><div><small>Launch blockers</small><strong>{systemOps.launchGate?.blockersCount ?? "—"}</strong><span>{systemOps.launchGate?.ready ? "Automated gates pass" : "Launch gate needs attention"}</span></div></div>
				</Card>
				<div className="ops-grid system-section">
					<Card>
						<SectionHeader title="Release operations" sub="Audited deployment evidence and production configuration" />
						<div className="finance-grid"><div><small>Configuration blockers</small><strong>{systemOps.configuration?.blockersCount ?? "—"}</strong></div><div><small>Deployment records</small><strong>{systemOps.deployments?.length || 0}</strong></div><div><small>Open SEV1/SEV2</small><strong>{activeIncidents.filter((incident) => ["sev1", "sev2"].includes(incident.severity)).length}</strong></div></div>
						<div className="simple-list">{(systemOps.deployments || []).slice(0, 6).map((deployment) => <div key={deployment.id}><FaShieldAlt /><span><b>{deployment.release} · {deployment.status}</b><small>{deployment.phase} · {deployment.previousRelease ? `from ${deployment.previousRelease} · ` : ""}{deployment.backupId ? `backup ${deployment.backupId}` : "backup evidence pending"}</small></span><Badge tone={deployment.status === "completed" ? "success" : deployment.status === "failed" ? "danger" : "primary"}>{deployment.status}</Badge></div>)}</div>
					</Card>
					<Card>
						<SectionHeader title="Incident command" sub="Append-only operator timeline; unresolved SEV1/SEV2 blocks launch" />
						{activeIncidents.length ? activeIncidents.slice(0, 5).map((incident) => <div className="incident-ops-card" key={incident.id}><div><Badge tone={["sev1", "sev2"].includes(incident.severity) ? "danger" : "warning"}>{incident.severity}</Badge> <b>{incident.title}</b><small>{incident.status} · {incident.type} · {incident.startedAt ? new Date(incident.startedAt).toLocaleString() : ""}</small></div><div className="simple-list">{(incident.events || []).slice(0, 3).map((event) => <div key={event.id}><span><b>{event.type}</b><small>{event.message} · {event.occurredAt ? new Date(event.occurredAt).toLocaleString() : ""}</small></span></div>)}</div>{canManageOperations && <><Field label="Operator update" value={incidentText(incident.id)} onChange={(event) => setIncidentText(incident.id, event.target.value)} placeholder="What changed, evidence, next action or resolution" /><div className="button-row"><Button disabled={!!busy} onClick={() => incidentAction(incident, "note")}>Add note</Button><Button disabled={!!busy} onClick={() => incidentAction(incident, "investigating")}>Investigating</Button><Button disabled={!!busy} onClick={() => incidentAction(incident, "monitoring")}>Monitoring</Button><Button disabled={!!busy} onClick={() => incidentAction(incident, "resolve")}>Resolve</Button></div></>}</div>) : <p>No active incidents.</p>}
					</Card>
				</div>
			</>}

			{canViewFinance && <>
				{financeLoading && !summary ? <Card className="system-section"><p>Loading finance ledger…</p></Card> : summary && <>
					<div className="metric-grid"><Card><FaMoneyBillWave /><small>Seller payable</small><strong>{money(summary.ledger?.sellerPayableMinor)}</strong><span>Net outstanding liability</span></Card><Card><FaStore /><small>Platform commission</small><strong>{money(summary.ledger?.platformCommissionRevenueMinor)}</strong><span>Net commission revenue</span></Card><Card><FaTag /><small>Coupon subsidy</small><strong>{money(summary.ledger?.reviewCouponSubsidyExpenseMinor)}</strong><span>Platform-funded review rewards</span></Card><Card><FaUndo /><small>Seller recovery</small><strong>{money(summary.ledger?.sellerRecoveryReceivableMinor)}</strong><span>Refunds after seller payout</span></Card></div>
					<div className="ops-grid"><Card><h2>Cash & receivables</h2><div className="finance-grid"><div><small>Payment clearing</small><strong>{money(summary.ledger?.paymentClearingMinor)}</strong></div><div><small>COD receivable</small><strong>{money(summary.ledger?.codReceivableMinor)}</strong></div></div></Card><Card><h2>Operational liabilities</h2><div className="finance-grid"><div><small>VSN Coins</small><strong>{money(summary.operationalLiabilities?.vsnCoinLiabilityMinor)}</strong></div><div><small>Affiliate pending</small><strong>{money(summary.operationalLiabilities?.affiliatePendingLiabilityMinor)}</strong></div><div><small>Game prizes</small><strong>{money(summary.operationalLiabilities?.gamePrizeLiabilityMinor)}</strong></div></div></Card></div>
					<Card className="system-section"><SectionHeader title="Seller payout queue" sub="Finance approval and confirmed payout settlement" /><div className="simple-list">{payouts.length ? payouts.map((payout) => <div key={payout.id}><FaMoneyBillWave /><span><b>{payout.vendor || "Vendor"} · {payout.id}</b><small>{payout.status} · requested by {payout.requestedBy || "seller"}</small></span><strong>{money(payout.amountMinor)}</strong>{canManageFinance && payout.status === "requested" && <><Button variant="secondary" disabled={!!busy} onClick={() => payoutAction(payout.id, "review", { approve: false, note: "Rejected by finance" })}>Reject</Button><Button disabled={!!busy} onClick={() => payoutAction(payout.id, "review", { approve: true })}>Approve</Button></>}{canManageFinance && ["approved", "processing"].includes(payout.status) && <><input placeholder="Bank/provider reference" value={refs[payout.id] || ""} onChange={(event) => setRefs({ ...refs, [payout.id]: event.target.value })} /><Button disabled={!!busy || !(refs[payout.id] || "").trim()} onClick={() => payoutAction(payout.id, "paid", { providerReference: refs[payout.id].trim() })}>Mark paid</Button><Button variant="secondary" disabled={!!busy} onClick={() => payoutAction(payout.id, "cancel", { note: "Cancelled by finance" })}>Cancel</Button></>}</div>) : <p>No seller payouts queued.</p>}</div>{canManageFinance && <div style={{ marginTop: 16, display: "flex", gap: 8, flexWrap: "wrap" }}><Button variant="secondary" disabled={!!busy || !payouts.some((payout) => payout.status === "approved" && !payout.batchId)} onClick={createBatch}>{busy === "batch" ? "Creating batch…" : "Batch approved payouts"}</Button></div>}</Card>
					<Card className="system-section"><SectionHeader title="Payout batches" sub="Approved seller payouts grouped for bank/provider processing" /><div className="simple-list">{batches.length ? batches.map((batch) => <div key={batch.id}><FaMoneyBillWave /><span><b>{batch.id}</b><small>{batch.status} · {batch.payoutCount} payouts · {batch.providerBatchReference || "provider batch reference pending"}</small></span><strong>{money(batch.totalMinor)}</strong></div>) : <p>No payout batches yet.</p>}</div></Card>
					<Card><h2>Ledger reconciliation</h2><p>Backfill missing order journals, reconcile settlement states and verify every journal remains debit/credit balanced.</p>{canManageFinance && <Button disabled={!!busy} onClick={reconcile}>{busy === "reconcile" ? "Reconciling…" : "Run reconciliation"}</Button>}</Card>
				</>}
			</>}
		</div>
	</>;
}

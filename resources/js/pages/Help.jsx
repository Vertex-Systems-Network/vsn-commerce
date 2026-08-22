import { useState } from "react";
import { Link } from "react-router-dom";
import SEO from "../components/SEO";
import { Card, Field, Select, Button } from "../components/Toolkit";
import {
	FaSearch,
	FaShippingFast,
	FaUndo,
	FaCreditCard,
	FaUserShield,
	FaGamepad,
} from "react-icons/fa";
const topics = [
	["Orders & delivery", FaShippingFast],
	["Returns & refunds", FaUndo],
	["Payments", FaCreditCard],
	["Account & verification", FaUserShield],
	["Game Win", FaGamepad],
];
/** Handles help for the VSN Ecommerce interface. */
export default function Help() {
	const [open, setOpen] = useState(null);
	const [q, setQ] = useState("");
	const [sent, setSent] = useState(false);
	return (
		<>
			<SEO title="Help Center | VSN Ecommerce" />
			<div className="help-page">
				<div className="help-hero">
					<span>HELP CENTER</span>
					<h1>How can we help?</h1>
					<div className="help-search">
						<FaSearch />
						<input
							value={q}
							onChange={/** Inline callback for this operation. */ (e) => setQ(e.target.value)}
							placeholder="Search orders, refunds, verification, games..."
						/>
					</div>
				</div>
				<div className="help-topic-grid">
					{topics.map(/** Inline callback for this operation. */ ([t, I]) => (
						<Card key={t}>
							<I />
							<h3>{t}</h3>
							<p>Guides, policies and common troubleshooting.</p>
							<button>Browse articles</button>
						</Card>
					))}
				</div>
				<div className="help-quick-actions">
					<Link to="/tracking">Track an order</Link>
					<Link to="/returns">Start return / dispute</Link>
					<Link to="/profile">Verify account</Link>
					<Link to="/saved-alerts">Manage price alerts</Link>
				</div>
				<div className="help-columns">
					<Card>
						<h2>Popular questions</h2>
						{[
							"Where is my order?",
							"How do refunds work?",
							"How do I verify my CNIC?",
							"How are Game Win winners announced?",
							"How do coins work?",
						]
							.filter(/** Inline callback for this operation. */ (x) => x.toLowerCase().includes(q.toLowerCase()))
							.map(/** Inline callback for this operation. */ (x, i) => (
								<div
									key={x}
									className={`faq-item ${open === i ? "active" : ""}`}
								>
									<button
										className="faq-title"
										onClick={/** Inline callback for this operation. */ () => setOpen(open === i ? null : i)}
									>
										<span>{x}</span>
										<span>{open === i ? "−" : "+"}</span>
									</button>

									<div className="faq-content">
										<p>
											This flow is backed by the VSN Ecommerce Laravel API and your current marketplace data.
										</p>
									</div>
								</div>
							))}
					</Card>
					<Card>
						<h2>Contact support</h2>
						{sent ? (
							<div className="success-box">
								Support request created. Ticket #VSN-10482
							</div>
						) : (
							<div className="support-form">
								<Select label="Topic">
									<option>Order issue</option>
									<option>Payment & refund</option>
									<option>Verification</option>
									<option>Game Win</option>
								</Select>
								<Field label="Order ID (optional)" placeholder="VSN-ORDER-..." />
								<label className="ui-field">
									<span>Message</span>
									<textarea
										rows="5"
										placeholder="Describe the issue"
									></textarea>
								</label>
								<Button onClick={/** Inline callback for this operation. */ () => setSent(true)}>Submit request</Button>
							</div>
						)}
					</Card>
				</div>
			</div>
		</>
	);
}

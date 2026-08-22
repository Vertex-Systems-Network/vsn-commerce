import { Link } from "react-router-dom";
import { FaLock } from "react-icons/fa";
import { defaultHomeFor, useAuth } from "../platform/auth";
/** Handles access denied for the VSN Ecommerce interface. */
export default function AccessDenied(){const {user}=useAuth();return <main className="route-state"><div className="route-state-card"><FaLock size={30}/><h1>Access denied</h1><p>Your account does not have permission to open this area.</p><Link className="ui-button" to={defaultHomeFor(user)}>Go to my dashboard</Link></div></main>}

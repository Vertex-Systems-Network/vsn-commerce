import {useEffect,useState} from "react";
import {apiGet} from "../platform/api";
import {Card,Status} from "../components/Toolkit";

/** Handles admin access for the VSN Ecommerce interface. */
export default function AdminAccess(){
  const [data,setData]=useState(null),[error,setError]=useState("");
  useEffect(/** Inline callback for this operation. */ ()=>{apiGet("/admin/rbac").then(setData).catch(/** Inline callback for this operation. */ e=>setError(e.message));},[]);
  return <div className="simple-page"><div className="page-title"><h1>Access Control</h1><p>Effective role-to-permission matrix used by both API authorization and the React admin navigation.</p></div>
    {error&&<Status>{error}</Status>}
    <Card><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Role</th><th>Effective permissions</th></tr></thead><tbody>{(data?.roles||[]).map(/** Inline callback for this operation. */ row=><tr key={row.role}><td><b>{row.role.replaceAll("_"," ")}</b></td><td><div className="permission-chip-list">{(row.permissions||[]).map(/** Inline callback for this operation. */ p=><code key={p}>{p}</code>)}</div></td></tr>)}</tbody></table></div></Card>
    {data?.notes&&<Card><h2>Safety notes</h2>{Object.entries(data.notes).map(/** Inline callback for this operation. */ ([k,v])=><p key={k}><b>{k.replaceAll("_"," ")}:</b> {v}</p>)}</Card>}
  </div>;
}

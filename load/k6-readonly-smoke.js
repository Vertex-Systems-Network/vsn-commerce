import http from 'k6/http';
import { check, sleep } from 'k6';
export const options={stages:[{duration:'30s',target:20},{duration:'60s',target:50},{duration:'30s',target:0}],thresholds:{http_req_failed:['rate<0.01'],http_req_duration:['p(95)<750']}};
const base=__ENV.BASE_URL||'http://127.0.0.1:8000';
/** Documents the k6 load-test entrypoint. */
export default function(){
 const live=http.get(`${base}/api/v1/health`);check(live,{'liveness 200':/** Inline callback for this operation. */ r=>r.status===200});
 const products=http.get(`${base}/api/v1/products`);check(products,{'catalog available':/** Inline callback for this operation. */ r=>r.status===200});
 const search=http.get(`${base}/api/v1/search/suggestions?q=phone`);check(search,{'search available':/** Inline callback for this operation. */ r=>r.status===200||r.status===429});
 sleep(1);
}

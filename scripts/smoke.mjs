if(!process.env.DEMO_PASSWORD)throw new Error('Set DEMO_PASSWORD for the demo test account.');
import assert from 'node:assert/strict';
const base=(process.env.ATLAS_URL||'http://127.0.0.1:3100').replace(/\/$/,'');
const jar=new Map();let token='';let assertions=0;
async function request(path,method='GET',body,expected=200,csrf=true){
  const response=await fetch(`${base}/api/${path}`,{method,headers:{Accept:'application/json','Content-Type':'application/json',Cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; '),...(csrf&&token?{'X-CSRF-TOKEN':token}:{})},body:body===undefined?undefined:JSON.stringify(body)});
  for(const cookie of response.headers.getSetCookie()){const part=cookie.split(';')[0],at=part.indexOf('=');jar.set(part.slice(0,at),part.slice(at+1));}
  const text=await response.text();assert.equal(response.status,expected,`${method} ${path}: ${text.slice(0,300)}`);assertions++;
  return text?JSON.parse(text):undefined;
}
await request('me','GET',undefined,401);
token=(await request('csrf')).token;
await request('login','POST',{email:'admin@atlas.example',password:process.env.DEMO_PASSWORD},419,false);
await request('login','POST',{email:'admin@atlas.example',password:process.env.DEMO_PASSWORD});
token=(await request('csrf')).token;
const suffix=Date.now();
const customer=await request('customers','POST',{name:`Smoke ${suffix}`,email:`smoke-${suffix}@client.example`,address:'Adresse fictive de test'},201);
const products=[];
for(let i=0;i<2;i++){
 const p=await request('products','POST',{reference:`SM-${suffix}-${i}`,name:`Article smoke ${i}`,category:'Tests automatiques',price_cents:i?550:999,vat_bps:i?2000:550,alert_threshold:2},201);
 await request(`products/${p.id}/stock`,'POST',{quantity:5,reason:'Stock initial de test'});products.push(p);
}
const quote=await request('quotes','POST',{customer_id:customer.id,items:products.map(p=>({product_id:p.id,quantity:2}))},201);
assert.equal(quote.total_cents,3428);assertions++;
const order=await request(`quotes/${quote.id}/convert`,'POST',undefined,201);
await request(`quotes/${quote.id}/convert`,'POST',undefined,409);
await request(`orders/${order.id}/validate`,'POST');
await request(`orders/${order.id}/validate`,'POST',undefined,409);
const stock=await request(`products?search=SM-${suffix}`);
assert(stock.data.every(p=>p.stock===3));assertions++;
const invoice=await request('invoices','POST',{order_id:order.id},201);
const issued=await request(`invoices/${invoice.id}`,'PATCH',{status:'issued'});
assert.match(issued.number,/^FAC-\d{4}-\d{5,}$/);assertions++;
const pdf=await fetch(`${base}/api/invoices/${invoice.id}/pdf`,{headers:{Cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; ')}});
assert.equal(pdf.status,200);assert.equal(Buffer.from(await pdf.arrayBuffer()).subarray(0,5).toString(),'%PDF-');assertions+=2;
await request(`invoices/${invoice.id}`,'PATCH',{status:'paid'});
await request(`invoices/${invoice.id}`,'PATCH',{status:'cancelled'},409);
const dashboard=await request('dashboard');assert(dashboard.revenue_cents>=3098);assertions++;
await request('logout','POST',undefined,204);await request('me','GET',undefined,401);
console.log(`PASS: ${assertions} HTTP checks; client → two-line quote → order → stock → numbered PDF → paid → logout.`);
console.log('Fictitious smoke records retained for inspection. Use only against a disposable/demo environment.');

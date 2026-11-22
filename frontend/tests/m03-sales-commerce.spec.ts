import { test, expect } from "@playwright/test";

if (!process.env.DEMO_PASSWORD) throw new Error("Set DEMO_PASSWORD before running browser tests.");

test("M03 : devis révisé, commande réservée, livraison, retour et avoir", async ({ page }) => {
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact:true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact:true }).fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button", {name:"Se connecter",exact:true}).click();
  await expect(page.getByRole("button",{name:"Devis",exact:true})).toBeVisible();
  const csrf=(await (await page.request.get("/api/csrf")).json()).token;
  let i=0;
  async function post(path:string,data:Record<string,unknown>,extra:Record<string,string>={}){
    const res=await page.request.post("/api/"+path,{
      headers:{"X-CSRF-TOKEN":csrf,"Idempotency-Key":`m03-e2e-${Date.now()}-${i++}`,...extra},
      data
    });
    expect(res.ok(),`${path} returned ${res.status()}: ${await res.text()}`).toBeTruthy();
    return res.json();
  }
  const nonce=Date.now().toString(36);
  const customer=await post("customers",{name:"Client M03 "+nonce,email:`m03-${nonce}@example.test`});
  async function product(part:string){
    const item=await post("products",{reference:`M03-${nonce}-${part}`,name:"Article test "+part,
      category:"Recette M03",price_cents:5000,vat_bps:2000,alert_threshold:1});
    await post(`products/${item.id}/stock`,{quantity:7,reason:"Stock initial recette M03"});
    return item;
  }
  const a=await product("A"), b=await product("B");
  const quote=await post("quotes",{customer_id:customer.id,items:[
    {product_id:a.id,quantity:1},{product_id:b.id,quantity:1}
  ]});
  const rev=await post(`sales-v5/quotes/${quote.id}/revisions`,{
    discount_bps:0,valid_until:new Date(Date.now()+14*86400000).toISOString().slice(0,10)
  });
  expect(rev.total_cents).toBe(12000);
  await post(`sales-v5/revisions/${rev.id}/send`,{});
  await post(`sales-v5/revisions/${rev.id}/accept`,{});
  const order=await post(`sales-v5/revisions/${rev.id}/convert`,{});
  expect(order.fulfillment_mode).toBe("reservation_v2");
  const reservation=await post(`sales-v5/orders/${order.id}/reserve`,{});
  expect(reservation.status).toBe("reserved");
  const before=(await (await page.request.get(`/api/products?search=M03-${nonce}-A`)).json()).data[0];
  expect(before.stock).toBe(7);
  const delivery=await post(`sales-v5/orders/${order.id}/ship`,{
    recipient:"Client M03",carrier:"Transport de test",
    lines:order.items.map((x:{id:number})=>({order_item_id:x.id,quantity:1}))
  });
  const after=(await (await page.request.get(`/api/products?search=M03-${nonce}-A`)).json()).data[0];
  expect(after.stock).toBe(6);
  const issued=await post(`sales-v5/deliveries/${delivery.id}/acknowledge`,{
    proof_reference:"POD-"+nonce
  });
  expect(issued.status).toBe("received");
  const invoice=await post("invoices",{order_id:order.id});
  const issuedInvoice=await page.request.patch(`/api/invoices/${invoice.id}`,{
    headers:{"X-CSRF-TOKEN":csrf},data:{status:"issued"}
  });
  expect(issuedInvoice.ok()).toBeTruthy();
  const deliveryDetails=await (await page.request.get(`/api/sales-v5/deliveries/${delivery.id}`)).json();
  const ret=await post("sales-v5/returns",{
    delivery_id:delivery.id,reason:"Contrôle non conforme à réception",
    lines:[{delivery_line_id:deliveryDetails.lines[0].id,quantity:1}]
  });
  const inspected=await post(`sales-v5/returns/${ret.id}/inspect`,{
    decision:"defective",credit_eligible:true
  });
  expect(inspected.status).toBe("inspected");
  const note=await post(`sales-v5/returns/${ret.id}/credit`,{});
  expect(note.total_cents).toBe(6000);
  await page.getByRole("button",{name:"Bons de livraison",exact:true}).click();
  await expect(page.getByRole("heading",{name:"Bons de livraison"})).toBeVisible();
  await page.getByRole("button",{name:"Retours clients",exact:true}).click();
  await expect(page.getByRole("heading",{name:"Retours clients et contrôle qualité"})).toBeVisible();
});

test("M03 : contrats, tarifs et droits de navigation", async ({ page }) => {
  await page.goto("/");
  await page.getByLabel("Adresse e-mail",{exact:true}).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe",{exact:true}).fill(process.env.DEMO_PASSWORD!);
  await page.getByRole("button",{name:"Se connecter",exact:true}).click();
  const csrf=(await (await page.request.get("/api/csrf")).json()).token;
  let i=0;
  async function post(path:string,data:Record<string,unknown>) {
    const r=await page.request.post("/api/"+path,{
      headers:{"X-CSRF-TOKEN":csrf,"Idempotency-Key":`m03-contract-${Date.now()}-${i++}`},data
    });
    expect(r.ok(),`${path}: ${await r.text()}`).toBeTruthy();
    return r.json();
  }
  const stamp=Date.now().toString(36);
  const customer=await post("customers",{name:"Contrat M03 "+stamp,email:`m03-contract-${stamp}@example.test`});
  const product=await post("products",{reference:"M03-PRICE-"+stamp,name:"Service tarifé M03",
    category:"Test M03",price_cents:5000,vat_bps:2000,alert_threshold:1});
  const start=new Date().toISOString().slice(0,10);
  const end=new Date(Date.now()+180*86400000).toISOString().slice(0,10);
  const contract=await post("sales-v5/contracts",{customer_id:customer.id,title:"Contrat M03 "+stamp,
    currency:"EUR",effective_on:start,expires_on:end,terms:"Conditions métier fictives"});
  await post(`sales-v5/contracts/${contract.id}/publish`,{});
  await post(`sales-v5/contracts/${contract.id}/amend`,{
    effective_on:start,expires_on:end,terms:"Avenant fictif M03"
  });
  const details=await (await page.request.get(`/api/sales-v5/contracts/${contract.id}`)).json();
  expect(details.version_no).toBe(2);
  expect(details.versions).toHaveLength(2);
  await post(`sales-v5/contracts/${contract.id}/terminate`,{});
  const price=await post("sales-v5/pricing",{product_id:product.id,title:"Prix M03 "+stamp,
    currency:"EUR",price_cents:4800,vat_bps:2000,discount_bps:0,priority:10,
    starts_on:start,ends_on:end});
  await post(`sales-v5/pricing/${price.id}/publish`,{});
  await page.getByRole("button",{name:"Contrats commerciaux",exact:true}).click();
  await expect(page.getByRole("heading",{name:"Contrats commerciaux"})).toBeVisible();
  await page.getByRole("button",{name:"Tarifs et remises",exact:true}).click();
  await expect(page.getByRole("heading",{name:"Tarifs et remises"})).toBeVisible();
});

export type Role = {
  id: number;
  name: "admin" | "commercial" | "stock" | "reader" | "support_n2" | "support_n3";
};
export type User = {
  id: number;
  name: string;
  email: string;
  role_id: number;
  role: Role;
  active_company_id?: number;
  companies?: { id: number; name: string; code: string }[];
};
export type Contact = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  address: string | null;
  orders?: Sale[];
};
export type Product = {
  id: number;
  reference: string;
  name: string;
  category: string;
  price_cents: number;
  vat_bps: number;
  stock: number;
  alert_threshold: number;
};
export type Line = {
  id?: number;
  product_id: number;
  reference: string;
  name: string;
  quantity: number;
  price_cents: number;
  vat_bps: number;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
};
export type Sale = {
  id: number;
  number: string;
  customer_id: number;
  customer: Contact;
  status: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes?: string;
  created_at: string;
  items: Line[];
};
export type Invoice = {
  id: number;
  number: string | null;
  order_id: number;
  status: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  created_at: string;
  issued_at: string | null;
  order: Sale;
  snapshot: { customer: Contact; items: Line[]; order_number: string };
};
export type Movement = {
  id: number;
  product: Product;
  quantity: number;
  balance_after: number;
  reason: string;
  created_at: string;
  user: User;
};
export type Audit = {
  id: number;
  action: string;
  entity_type: string;
  entity_id: number | null;
  created_at: string;
  user: User | null;
};
export type Dashboard = {
  from: string;
  to: string;
  revenue_cents: number;
  outstanding_cents: number;
  orders_count: number;
  low_stock_count: number;
  low_stock: Product[];
  daily_revenue: { day: string; revenue_cents: number }[];
  top_products: {
    product_id: number;
    name: string;
    quantity: number;
    revenue_cents: number;
  }[];
  recent_orders: Sale[];
};
export type Page<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

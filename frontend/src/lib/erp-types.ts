
import type {
  Contact,
  Product,
  Sale,
  Invoice,
  Movement,
  Audit,
  User,
} from "@/lib/types";

export type Row =
  Contact &
  Product &
  Sale &
  Invoice &
  Movement &
  Audit &
  User;

export type Editor = {
  kind: string;
  row?: Partial<Row>;
};

// Shared navigation and detail contracts; API entity shapes stay in types.ts.
export type View =
  | "dashboard" | "customers" | "suppliers" | "products"
  | "stock-movements" | "quotes" | "orders" | "invoices"
  | "users" | "audit-logs";

export type DetailState =
  | { kind: "customers"; row: Contact }
  | { kind: "quotes" | "orders"; row: Sale }
  | { kind: "invoices"; row: Invoice };

export type DocumentAction = (
  path: string,
  method?: string,
  data?: unknown,
) => Promise<unknown>;

export type DetailProps = DetailState & {
  can: boolean;
  busy: boolean;
  action: DocumentAction;
};

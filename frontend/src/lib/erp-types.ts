
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

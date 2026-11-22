import type { ReactNode } from "react";

export type DataTableFrameProps = {
  label: string;
  children: ReactNode;
  busy?: boolean;
  className?: string;
};

/**
 * P06: keyboard-accessible scroll region around existing semantic tables.
 * Data fetching, ordering, filtering and authorization stay in each screen.
 */
export default function DataTableFrame({
  label,
  children,
  busy = false,
  className = "",
}: DataTableFrameProps) {
  return (
    <div
      className={["table-scroll", "atlas-table-frame", busy ? "is-loading" : "", className]
        .filter(Boolean)
        .join(" ")}
      role="region"
      aria-label={label}
      aria-busy={busy}
      tabIndex={0}
    >
      {children}
    </div>
  );
}

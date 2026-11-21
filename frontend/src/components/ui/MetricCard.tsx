import type { LucideIcon } from "lucide-react";

export type MetricCardProps = {
  label: string;
  value: string | number;
  hint?: string;
  icon: LucideIcon;
  onClick?: () => void;
  className?: string;
};

/** Shared, accessible metric surface. Interactive metrics are real buttons. */
export default function MetricCard({
  label,
  value,
  hint,
  icon: Icon,
  onClick,
  className = "",
}: MetricCardProps) {
  const classes = ["atlas-role-stat", onClick ? "atlas-role-stat-link" : "", className]
    .filter(Boolean)
    .join(" ");
  const contents = (
    <>
      <span className="atlas-role-stat-top">
        <span>{label}</span>
        <Icon size={17} aria-hidden="true" />
      </span>
      <strong>{value}</strong>
      {hint && <small>{hint}</small>}
    </>
  );

  return onClick ? (
    <button className={classes} type="button" onClick={onClick}>
      {contents}
    </button>
  ) : (
    <article className={classes}>
      {contents}
    </article>
  );
}

import type { ReactNode } from "react";

export type FormFieldProps = {
  label: string;
  children: ReactNode;
  hint?: string;
  className?: string;
};

/**
 * Keeps native label/control association: children should be one native
 * input, select or textarea. Existing HTML validation stays on the control.
 */
export default function FormField({
  label,
  children,
  hint,
  className = "",
}: FormFieldProps) {
  return (
    <label className={["atlas-form-field", className].filter(Boolean).join(" ")}>
      <span className="atlas-form-field-title">{label}</span>
      {children}
      {hint && <small className="atlas-form-field-hint">{hint}</small>}
    </label>
  );
}

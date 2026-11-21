"use client";

import { AlertCircle, LoaderCircle, PackageOpen, RotateCcw } from "lucide-react";
import type { ReactNode } from "react";

export type FeedbackStateProps = {
  kind: "loading" | "error" | "empty";
  children: ReactNode;
  onRetry?: () => void;
};

/** Explicit API feedback: an unavailable metric is never rendered as zero. */
export default function FeedbackState({ kind, children, onRetry }: FeedbackStateProps) {
  const Icon = kind === "loading"
    ? LoaderCircle
    : kind === "error"
      ? AlertCircle
      : PackageOpen;
  return (
    <div
      className={`atlas-feedback atlas-feedback-${kind}`}
      role={kind === "error" ? "alert" : "status"}
      aria-live={kind === "error" ? "assertive" : "polite"}
    >
      <Icon size={19} aria-hidden="true" />
      <span>{children}</span>
      {kind === "error" && onRetry && (
        <button className="button secondary" type="button" onClick={onRetry}>
          <RotateCcw size={15} aria-hidden="true" />
          Réessayer
        </button>
      )}
    </div>
  );
}

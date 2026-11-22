"use client";

import { useState } from "react";
import { ArrowDown, ArrowUp, RotateCcw, SlidersHorizontal } from "lucide-react";
import {
  dashboardWidgets,
  type DashboardLayout,
  type DashboardWidgetId,
} from "@/components/dashboard/useDashboardLayout";

type Props = {
  layout: DashboardLayout;
  onToggle: (id: DashboardWidgetId) => void;
  onMove: (id: DashboardWidgetId, direction: -1 | 1) => void;
  onReset: () => void;
  storageAvailable: boolean;
};

/**
 * Dashboard Builder v1: predictable keyboard/button reordering instead of
 * inaccessible drag-only controls. Choices never alter backend permissions.
 */
export default function DashboardBuilder({
  layout,
  onToggle,
  onMove,
  onReset,
  storageAvailable,
}: Props) {
  const [open, setOpen] = useState(false);
  const groups = [
    { id: "kpis", label: "Indicateurs clés" },
    { id: "panels", label: "Graphiques et listes" },
  ] as const;

  return (
    <div className="atlas-builder">
      <button
        className="button secondary atlas-builder-trigger"
        type="button"
        aria-controls="atlas-dashboard-settings"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <SlidersHorizontal size={16} aria-hidden="true" />
        Personnaliser le tableau de bord
      </button>
      {open && (
        <section
          id="atlas-dashboard-settings"
          className="atlas-builder-settings"
          aria-label="Personnalisation du tableau de bord"
        >
          <div className="atlas-builder-head">
            <div>
              <strong>Votre tableau de bord</strong>
              <p>Choisissez les éléments visibles et leur ordre, sans modifier les données.</p>
            </div>
            <button className="button secondary" type="button" onClick={onReset}>
              <RotateCcw size={15} aria-hidden="true" />
              Réinitialiser
            </button>
          </div>
          <div className="atlas-builder-groups">
            {groups.map((group) => {
              const widgets = layout.filter((item) =>
                dashboardWidgets.find((widget) => widget.id === item.id)?.group === group.id
              );
              return (
                <div className="atlas-builder-group" key={group.id}>
                  <h3>{group.label}</h3>
                  <ul>
                    {widgets.map((item, i) => {
                      const name = dashboardWidgets.find((widget) => widget.id === item.id)?.label ?? item.id;
                      return (
                        <li className="atlas-builder-item" key={item.id}>
                          <label>
                            <input
                              type="checkbox"
                              checked={item.enabled}
                              onChange={() => onToggle(item.id)}
                            />
                            <span>{name}</span>
                          </label>
                          <div className="atlas-builder-reorder">
                            <button
                              type="button"
                              className="icon-button"
                              aria-label={`Monter ${name}`}
                              title={`Monter ${name}`}
                              disabled={i === 0}
                              onClick={() => onMove(item.id, -1)}
                            >
                              <ArrowUp size={16} aria-hidden="true" />
                            </button>
                            <button
                              type="button"
                              className="icon-button"
                              aria-label={`Descendre ${name}`}
                              title={`Descendre ${name}`}
                              disabled={i === widgets.length - 1}
                              onClick={() => onMove(item.id, 1)}
                            >
                              <ArrowDown size={16} aria-hidden="true" />
                            </button>
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              );
            })}
          </div>
          <p className="atlas-builder-note" role="status">
            {storageAvailable
              ? "Préférences enregistrées sur cet appareil, pour ce compte et cette société."
              : "Stockage local indisponible : les modifications restent actives jusqu’au rechargement."}
          </p>
        </section>
      )}
    </div>
  );
}

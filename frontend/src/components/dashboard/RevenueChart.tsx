

import type { Dashboard } from "@/lib/types";
import { euro } from "@/lib/format";
import Empty from "@/components/ui/Empty";

export default function RevenueChart({
  data,
}: {
  data: Dashboard["daily_revenue"];
}) {
  if (!data.length) {
    return (
      <Empty text="Aucune facture émise sur cette période." />
    );
  }

  const max = Math.max(
    ...data.map((d) => Number(d.revenue_cents)),
    100
  );

  const width = 640;
  const height = 190;
  const left = 60;
  const bottom = 165;
  const plot = 560;
  const bar = Math.min(46, (plot / data.length) * 0.55);

  return (
    <div className="chart">
      <svg
        viewBox={`0 0 ${width} ${height}`}
        role="img"
        aria-label="Chiffre d’affaires HT par jour"
      >
        <title>
          Chiffre d’affaires HT par jour d’émission
        </title>

        {[0, 0.25, 0.5, 0.75, 1].map((f) => (
          <g key={f}>
            <line
              x1={left}
              x2={635}
              y1={bottom - f * 140}
              y2={bottom - f * 140}
              stroke="#edf0ed"
              strokeDasharray="3 4"
            />
            <text
              x={left - 10}
              y={bottom - f * 140 + 4}
              textAnchor="end"
              fill="#89948c"
              fontSize="10"
            >
              {Math.round((max * f) / 100)} €
            </text>
          </g>
        ))}

        {data.map((d, i) => {
          const x =
            left + ((i + 0.5) * plot) / data.length;

          const barHeight =
            (Number(d.revenue_cents) / max) * 140;

          return (
            <g key={d.day}>
              <rect
                x={x - bar / 2}
                y={bottom - barHeight}
                width={bar}
                height={Math.max(1, barHeight)}
                rx="5"
                fill={
                  i === data.length - 1
                    ? "#0c7568"
                    : "#b5d6c9"
                }
              >
                <title>
                  {d.day} : {euro(d.revenue_cents)}
                </title>
              </rect>

              <text
                x={x}
                y={185}
                textAnchor="middle"
                fill="#89948c"
                fontSize="10"
              >
                {d.day.slice(8, 10)}/
                {d.day.slice(5, 7)}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

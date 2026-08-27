"use client";

/**
 * The trend channel of a stat tile. Deliberately axis-free and label-free —
 * it shows shape, not values; the tile's own number carries magnitude and the
 * chart below carries the detail.
 */
export function Sparkline({
  values,
  width = 96,
  height = 24,
}: {
  values: number[];
  width?: number;
  height?: number;
}) {
  const points = values.filter((v) => Number.isFinite(v));
  if (points.length < 2) {
    return <div style={{ width, height }} aria-hidden="true" />;
  }

  const min = Math.min(...points);
  const max = Math.max(...points);
  const span = max - min || 1;
  const pad = 3;
  const innerH = height - pad * 2;

  const x = (i: number) => (i / (points.length - 1)) * width;
  const y = (v: number) => pad + innerH - ((v - min) / span) * innerH;

  const path = points.map((v, i) => `${i === 0 ? "M" : "L"}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(" ");
  const lastX = x(points.length - 1);
  const lastY = y(points[points.length - 1]);

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      aria-label="Trend over the selected period"
      style={{ display: "block", overflow: "visible" }}
    >
      <path
        d={path}
        fill="none"
        stroke="var(--series-1)"
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
        opacity={0.55}
      />
      {/* The current value gets the accent; the run-up recedes. */}
      <circle
        cx={lastX}
        cy={lastY}
        r={3}
        fill="var(--series-1)"
        stroke="var(--surface)"
        strokeWidth={2}
      />
    </svg>
  );
}

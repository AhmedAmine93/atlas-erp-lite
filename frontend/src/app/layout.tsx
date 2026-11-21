import type { Metadata } from "next";
import "@fontsource-variable/dm-sans";
import "@fontsource-variable/manrope";
import "./globals.css";
import "./atlas-theme.css";
import "./role-dashboard.css";
export const metadata: Metadata = {
  title: "ATLAS ONE — Espace de travail",
  description: "ERP de démonstration : clients, stocks, ventes et facturation.",
};
export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="fr">
      <body>{children}</body>
    </html>
  );
}

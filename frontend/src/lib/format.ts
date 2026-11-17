
export const euro = (n: number) =>
  new Intl.NumberFormat("fr-FR", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 2,
  }).format(Number(n) / 100);

export const date = (s: string) =>
  new Date(s).toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });

export const labels: Record<string, string> = {
  draft: "Brouillon",
  converted: "Converti",
  validated: "Validée",
  issued: "Émise",
  paid: "Payée",
  cancelled: "Annulée",
  admin: "Administrateur",
  commercial: "Commercial",
  stock: "Stock",
  reader: "Lecture seule",
};

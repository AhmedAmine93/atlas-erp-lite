import {
  LayoutDashboard,
  Users,
  ShoppingBag,
  Truck,
  Package,
  Wallet,
  Boxes,
  FileText,
  AlertTriangle,
  ShieldCheck,
  TrendingUp,
  SlidersHorizontal,
  Search,
  History,
  type LucideIcon,
} from "lucide-react";

/**
 * Catalogue d'ATLAS ERP. Seules les entrées ready sont navigables.
 * Les entrées futures restent désactivées jusqu'à leur implémentation.
 * Les permissions d'écriture et d'accès API sont toujours contrôlées côté Laravel.
 */
export type NavigationItem = {
  id: string;
  label: string;
  ready?: boolean;
};

export type NavigationSection = {
  id: string;
  label: string;
  icon: LucideIcon;
  adminOnly?: boolean;
  items: NavigationItem[];
};

export const navigationSections: NavigationSection[] = [
  {
    id: "pilotage",
    label: "Pilotage & Direction",
    icon: LayoutDashboard,
    items: [
      { id: "dashboard", label: "Vue d’ensemble", ready: true },
      { id: "direction-dashboard", label: "Tableau de bord direction", ready: true },
      { id: "sales-dashboard", label: "Pilotage commercial", ready: true },
      { id: "finance-dashboard", label: "Pilotage financier", ready: true },
      { id: "logistics-dashboard", label: "Pilotage logistique", ready: true },
      { id: "alerts-dashboard", label: "Alertes et objectifs", ready: true },
    ],
  },
  {
    id: "crm",
    label: "CRM & Relations clients",
    icon: Users,
    items: [
      { id: "customers", label: "Clients", ready: true },
      { id: "prospects", label: "Prospects" },
      { id: "crm-contacts", label: "Contacts" },
      { id: "opportunities", label: "Opportunités" },
      { id: "crm-activities", label: "Activités et relances" },
      { id: "customer-segments", label: "Segmentation clients" },
    ],
  },
  {
    id: "sales",
    label: "Ventes & Commerce",
    icon: ShoppingBag,
    items: [
      { id: "quotes", label: "Devis", ready: true },
      { id: "orders", label: "Commandes", ready: true },
      { id: "deliveries", label: "Bons de livraison" },
      { id: "sales-contracts", label: "Contrats commerciaux" },
      { id: "sales-returns", label: "Retours clients" },
      { id: "pricing", label: "Tarifs et remises" },
    ],
  },
  {
    id: "purchasing",
    label: "Achats & Approvisionnement",
    icon: Truck,
    items: [
      { id: "suppliers", label: "Fournisseurs", ready: true },
      { id: "purchase-requests", label: "Demandes d’achat", ready: true },
      { id: "supplier-quotes", label: "Consultations fournisseurs" },
      { id: "purchase-orders", label: "Commandes fournisseurs" },
      { id: "purchase-receipts", label: "Réceptions fournisseurs" },
      { id: "supplier-invoices", label: "Factures fournisseurs" },
    ],
  },
  {
    id: "inventory",
    label: "Stocks & Logistique",
    icon: Package,
    items: [
      { id: "products", label: "Produits & stocks", ready: true },
      { id: "stock-movements", label: "Mouvements", ready: true },
      { id: "warehouses", label: "Dépôts et emplacements" },
      { id: "stock-inventories", label: "Inventaires" },
      { id: "stock-transfers", label: "Transferts" },
      { id: "lots-serials", label: "Lots et numéros de série" },
      { id: "replenishment", label: "Réapprovisionnement" },
    ],
  },
  {
    id: "finance",
    label: "Finance & Facturation",
    icon: Wallet,
    items: [
      { id: "invoices", label: "Factures", ready: true },
      { id: "credit-notes", label: "Avoirs" },
      { id: "collections", label: "Encaissements" },
      { id: "payment-reminders", label: "Relances impayés" },
      { id: "expenses", label: "Dépenses" },
      { id: "budgets", label: "Budgets" },
      { id: "accounting-exports", label: "Exports comptables" },
    ],
  },
  {
    id: "maintenance",
    label: "GMAO & Maintenance",
    icon: Boxes,
    items: [
      { id: "maintenance-dashboard", label: "Pilotage maintenance" },
      { id: "maintenance-sites", label: "Sites et localisations", ready: true },
      { id: "maintenance-assets", label: "Parc d’équipements", ready: true },
      { id: "maintenance-requests", label: "Demandes d’intervention (DI)", ready: true },
      { id: "maintenance-workorders", label: "Bons de travail (BT)", ready: true },
      { id: "maintenance-planning", label: "Planning maintenance" },
      { id: "maintenance-preventive", label: "Maintenance préventive" },
      { id: "maintenance-spares", label: "Pièces et consommations" },
      { id: "maintenance-contracts", label: "Contrats et SLA" },
      { id: "maintenance-mobile", label: "Mobilité terrain" },
    ],
  },
  {
    id: "hotels",
    label: "Hôtellerie & PMS",
    icon: Boxes,
    items: [
      { id: "hotel-properties", label: "Établissements hôteliers", ready: true },
      { id: "hotel-rooms", label: "Chambres", ready: true },
      { id: "hotel-bookings", label: "Réservations", ready: true },
      { id: "hotel-availability", label: "Disponibilité locale", ready: true },
      { id: "hotel-checkin", label: "Arrivées et départs", ready: true },
      { id: "hotel-housekeeping", label: "Housekeeping", ready: true },
      { id: "hotel-minibar", label: "Mini-bar", ready: true },
      { id: "hotel-folios", label: "Folios et facturation" },
      { id: "hotel-channel", label: "Canaux et CRS/PMS" },
      { id: "hotel-night-audit", label: "Clôture de journée" },
    ],
  },
  {
    id: "human-resources",
    label: "Ressources humaines",
    icon: Users,
    items: [
      { id: "hr-employees", label: "Collaborateurs" },
      { id: "hr-teams", label: "Équipes" },
      { id: "hr-schedules", label: "Planning" },
      { id: "hr-leaves", label: "Congés et absences" },
      { id: "hr-timesheets", label: "Temps passé" },
      { id: "hr-skills", label: "Compétences et formations" },
    ],
  },
  {
    id: "projects",
    label: "Projets & Activités",
    icon: FileText,
    items: [
      { id: "projects-list", label: "Portefeuille projets" },
      { id: "project-tasks", label: "Tâches et jalons" },
      { id: "project-resources", label: "Ressources" },
      { id: "project-times", label: "Temps projet" },
      { id: "project-costs", label: "Budgets projets" },
    ],
  },
  {
    id: "itsm",
    label: "Support & ITSM",
    icon: AlertTriangle,
    items: [
      { id: "support-tickets", label: "Centre de tickets" },
      { id: "support-incidents", label: "Incidents", ready: true },
      { id: "support-problems", label: "Problèmes" },
      { id: "support-services", label: "Demandes de service" },
      { id: "support-changes", label: "Changements" },
      { id: "support-sla", label: "SLA et escalades" },
      { id: "support-knowledge", label: "Base de connaissances" },
    ],
  },
  {
    id: "quality",
    label: "Qualité & Conformité",
    icon: ShieldCheck,
    items: [
      { id: "quality-controls", label: "Contrôles qualité" },
      { id: "nonconformities", label: "Non-conformités" },
      { id: "corrective-actions", label: "Actions correctives" },
      { id: "quality-audits", label: "Audits qualité" },
      { id: "compliance-docs", label: "Documents et procédures" },
    ],
  },
  {
    id: "analytics",
    label: "BI & Reporting",
    icon: TrendingUp,
    items: [
      { id: "analytics-sales", label: "Rapports commerciaux" },
      { id: "analytics-finance", label: "Analyse financière" },
      { id: "analytics-stock", label: "Analyse des stocks" },
      { id: "analytics-suppliers", label: "Performance fournisseurs" },
      { id: "analytics-custom", label: "Rapports personnalisés" },
      { id: "analytics-exports", label: "Exports de données" },
    ],
  },
  {
    id: "data-platform",
    label: "Data & Ingestion",
    icon: TrendingUp,
    items: [
      { id: "data-sources", label: "Catalogue des sources", ready: true },
      { id: "data-batches", label: "Lots et ingestion CSV", ready: true },
      { id: "data-pipelines", label: "Pipelines ETL" },
      { id: "data-quality", label: "Qualité de données" },
      { id: "data-lineage", label: "Lineage" },
      { id: "data-reconciliation", label: "Réconciliations" },
      { id: "data-lakehouse", label: "Lakehouse" },
    ],
  },
  {
    id: "integrations",
    label: "Intégrations & Automatisation",
    icon: SlidersHorizontal,
    items: [
      { id: "integrations-connectors", label: "Connecteurs externes" },
      { id: "integrations-api", label: "API et webhooks" },
      { id: "integrations-imports", label: "Imports / exports" },
      { id: "integrations-jobs", label: "Tâches planifiées" },
      { id: "integrations-errors", label: "Erreurs de synchronisation" },
      { id: "integrations-iot", label: "Capteurs IoT" },
    ],
  },
  {
    id: "ai",
    label: "IA & Assistant ATLAS",
    icon: Search,
    items: [
      { id: "ai-assistant", label: "Assistant métier" },
      { id: "ai-search", label: "Recherche intelligente" },
      { id: "ai-anomalies", label: "Détection d’anomalies" },
      { id: "ai-forecasts", label: "Prévisions" },
      { id: "ai-suggestions", label: "Suggestions d’actions" },
    ],
  },
  {
    id: "administration",
    label: "Administration & Sécurité",
    icon: ShieldCheck,
    adminOnly: true,
    items: [
      { id: "users", label: "Utilisateurs", ready: true },
      { id: "audit-logs", label: "Journal d’activité", ready: true },
      { id: "admin-roles", label: "Rôles et permissions" },
      { id: "admin-companies", label: "Entreprises et établissements", ready: true },
      { id: "admin-settings", label: "Paramètres de l’entreprise" },
      { id: "admin-documents", label: "Numérotation des documents" },
      { id: "admin-security", label: "Sécurité et sessions" },
    ],
  },
  {
    id: "operations",
    label: "Exploitation & DevOps",
    icon: History,
    items: [
      { id: "ops-overview", label: "Supervision générale" },
      { id: "ops-logs", label: "Journaux techniques" },
      { id: "ops-health", label: "État des services" },
      { id: "ops-jobs", label: "Jobs et batchs" },
      { id: "ops-backups", label: "Sauvegardes" },
      { id: "ops-deployments", label: "Déploiements et CI/CD" },
    ],
  },
];

/** Vues existantes conservées à l'identique pour la compatibilité. */
export const nav = navigationSections.flatMap((section) =>
  section.items
    .filter((item) => item.ready)
    .map((item) => ({ id: item.id, label: item.label, icon: section.icon })),
);

export type View = (typeof nav)[number]["id"];

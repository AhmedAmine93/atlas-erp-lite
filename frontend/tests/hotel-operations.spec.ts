import { expect, test } from "@playwright/test";

test("M08: accès aux dix espaces PMS et à leurs états réels", async ({ page }) => {
  if (!process.env.DEMO_PASSWORD) throw new Error("DEMO_PASSWORD absent");
  await page.goto("/");
  await page.getByLabel("Adresse e-mail", { exact: true }).fill("admin@atlas.example");
  await page.getByLabel("Mot de passe", { exact: true }).fill(process.env.DEMO_PASSWORD);
  await page.getByRole("button", { name: "Se connecter", exact: true }).click();
  await expect(page.getByRole("combobox", { name: "Entreprise active" })).toBeVisible();

  for (const [label, route] of [
    ["Établissements hôteliers", "hotel-properties"],
    ["Chambres", "hotel-rooms"],
    ["Réservations", "hotel-bookings"],
    ["Disponibilité locale", "hotel-availability"],
    ["Arrivées et départs", "hotel-checkin"],
    ["Housekeeping", "hotel-housekeeping"],
    ["Mini-bar", "hotel-minibar"],
    ["Folios et facturation", "hotel-folios"],
    ["Canaux et CRS/PMS", "hotel-channel"],
    ["Clôture de journée", "hotel-night-audit"],
  ]) {
    const menu = page.getByRole("button", { name: label, exact: true });
    await expect(menu).toBeEnabled();
    await menu.click();
    await expect(page).toHaveURL(new RegExp("/workspace/" + route + "$"));
    await expect(page.locator(".atlas-domain")).toBeVisible();
    await expect(page.locator(".atlas-domain h2")).toBeVisible();
    await expect(page.locator(".atlas-domain-list")).toBeVisible();
  }

  await expect(page.getByText("Imports fictifs uniquement. Aucune synchronisation externe en direct.")).toHaveCount(0);
});

import { defineConfig, devices } from "@playwright/test";

/**
 * P066.06: cross-engine smoke intentionally separate from transactional
 * Chromium suite. No additional demo logins; no fixture mutations.
 */
export default defineConfig({
  testDir: "./tests",
  testMatch: "**/browser-compat.spec.ts",
  fullyParallel: false,
  workers: 1,
  timeout: 60000,
  outputDir: "test-results-compat",
  use: {
    baseURL: process.env.ATLAS_URL || "http://127.0.0.1:3100",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "firefox-compat", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit-compat", use: { ...devices["Desktop Safari"] } },
  ],
  reporter: [["list"], ["html", { outputFolder: "playwright-compat-report", open: "never" }]],
});

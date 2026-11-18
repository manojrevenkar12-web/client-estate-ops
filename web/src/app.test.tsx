import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { App } from "./app/App";

const estate = {
  as_of: "2026-07-06T00:00:00Z",
  clients: [
    { id: "c00", name: "Brauhaus Kessler", monthly_savings_eur: 210, apps: [
      { id: "c00-shop-00", name: "Brauhaus Kessler shop", stack: "laravel", provider: "aws", exposures: { critical: 1, high: 0, medium: 2, low: 0 }, open_jobs: 0, incidents: 1, frozen: false },
      { id: "c00-site-22", name: "Brauhaus Kessler site", stack: "nextjs", provider: "gcp", exposures: { critical: 0, high: 0, medium: 0, low: 0 }, open_jobs: 0, incidents: 0, frozen: true },
    ] },
  ],
};
const detail = {
  app: { id: "c00-shop-00", client: "c00", name: "Brauhaus Kessler shop", stack: "laravel", provider: "aws", url: "", frozen: false, freeze: [] },
  dependencies: [{ ecosystem: "packagist", name: "guzzlehttp/guzzle", version: "7.4.4" }],
  exposures: [{ advisory: "SIM-2026-0007", severity: "critical", package: "guzzlehttp/guzzle", version: "7.4.4", fixed_in: ["7.4.5"] }],
  jobs: [{ id: "j1", lane: "fast", status: "rolled back at canary", created_at: "2026-07-01T10:00:00Z", verdict: { verdict: "rollback", reason: "canary errors 2.1% vs 0.4%" },
    changes: [{ ecosystem: "packagist", name: "guzzlehttp/guzzle", from: "7.4.4", to: "7.4.5", bump: "patch", fixes: ["SIM-2026-0007"] }] }],
  incidents: [],
};
const routes: Record<string, unknown> = {
  "/api/estate": estate,
  "/api/apps/c00-shop-00": detail,
  "/api/findings": [],
  "/estimator/estimates": { status: "needs_answers", quote_hours: 640, estimate: { p50_hours: 520, p80_hours: 640, drivers: {}, similar: [{ id: "P-1", name: "Bakery relaunch", hours: 600 }] },
    questions: ["how many distinct page templates or screens"], risks: [], concept: "# Technical concept (draft for review)\n", features: {} },
};

function mount(down = false) {
  vi.stubGlobal("fetch", vi.fn(async (url: string) => {
    if (down) throw new TypeError("network");
    const body = routes[url];
    return { ok: body !== undefined, status: body ? 200 : 404, json: async () => body ?? { error: "not found" } };
  }));
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><App /></QueryClientProvider>);
}

afterEach(() => vi.unstubAllGlobals());

describe("estate desk", () => {
  it("lists apps worst first and opens one with its fix and its canary verdict", async () => {
    mount();
    expect(await screen.findByText(/1 critical, 0 high, 2 medium, 0 low advisories open/)).toBeInTheDocument();
    expect(screen.getByText("Open an app")).toBeInTheDocument();
    fireEvent.click(screen.getByText("Brauhaus Kessler shop"));
    expect(await screen.findByText("SIM-2026-0007")).toBeInTheDocument();
    expect(screen.getByText("7.4.5")).toBeInTheDocument();
    expect(screen.getByText(/Canary: canary errors 2.1% vs 0.4%/)).toBeInTheDocument();
  });

  it("filters to exposed apps and says so when none are", async () => {
    mount();
    await screen.findByText("Brauhaus Kessler shop");
    fireEvent.click(screen.getByLabelText(/Only apps with a critical or high/));
    expect(screen.queryByText("Brauhaus Kessler site")).not.toBeInTheDocument();
  });

  it("explains what to do when the API is down", async () => {
    mount(true);
    expect(await screen.findByText(/Start it with `make serve`/)).toBeInTheDocument();
  });

  it("shows an empty cost view and a draft that needs answers", async () => {
    mount();
    fireEvent.click(screen.getByRole("tab", { name: "Cloud cost" }));
    expect(await screen.findByText("No idle spend found")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "Estimates" }));
    fireEvent.click(screen.getByText("Use a sample brief"));
    fireEvent.click(screen.getByText("Draft the estimate"));
    expect(await screen.findByText("Ask the client before quoting")).toBeInTheDocument();
    expect(screen.getByText(/640 hours/)).toBeInTheDocument();
  });
});

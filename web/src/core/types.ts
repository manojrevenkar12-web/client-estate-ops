export type Severity = "critical" | "high" | "medium" | "low";
export type Exposures = Record<Severity, number>;
export interface AppSummary { id: string; name: string; stack: string; provider: string; exposures: Exposures; open_jobs: number; incidents: number; frozen: boolean }
export interface Client { id: string; name: string; apps: AppSummary[]; monthly_savings_eur: number }
export interface Estate { as_of: string; clients: Client[] }
export interface Exposure { advisory: string; severity: Severity; package: string; version: string; fixed_in: string[] }
export interface Change { ecosystem: string; name: string; from: string; to: string; bump: string; fixes: string[] }
export interface Job { id: string; lane: string; status: string; changes: Change[]; verdict: { verdict: string; reason: string } | null; created_at: string }
export interface Incident { id: string; opened_at: string; acted_at: string; cause: string; action: string }
export interface AppDetail {
  app: { id: string; client: string; name: string; stack: string; provider: string; url: string; frozen: boolean; freeze: { from: string; to: string }[] };
  dependencies: { ecosystem: string; name: string; version: string }[];
  exposures: Exposure[];
  jobs: Job[];
  incidents: Incident[];
}
export interface Finding { resource: string; client_id: string; provider: string; action: string; monthly_saving_eur: number; reason: string }
export interface Draft {
  status: "ready_for_review" | "needs_answers";
  quote_hours: number | null;
  estimate: { p50_hours: number; p80_hours: number; drivers: Record<string, number>; similar: { id: string; name: string; hours: number }[] } | null;
  questions: string[];
  risks: string[];
  concept: string;
  features: Record<string, unknown>;
}
export interface DesignResult {
  design: string; broken_deploys: number; broken_minutes_per_app_month: number; pages: number; noise_page_share: number;
  person_hours_total: number; majors_behind: number;
  exposure: Record<Severity, { p90_days: number | null; app_days: number; open_at_end: number }>;
}
export interface Report { seed: number; apps: number; days: number; proposed: string; designs: DesignResult[]; gate: { pass: boolean; checks: string[] } }

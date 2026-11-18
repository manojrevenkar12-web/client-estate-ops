import { useMutation, useQuery } from "@tanstack/react-query";
import type { AppDetail, Draft, Estate, Finding, Report } from "./types";

/** The console reads the estate API (/api, `make serve`) and asks the estimator (/estimator) for drafts. */
export class ApiError extends Error {}

async function call<T>(path: string, init?: RequestInit): Promise<T> {
  let res: Response;
  try {
    res = await fetch(path, init);
  } catch {
    throw new ApiError("The estate API could not be reached. Start it with `make serve`, then try again.");
  }
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError((body as { error?: string; detail?: string }).error ?? (body as { detail?: string }).detail ?? `The API answered ${res.status}.`);
  return body as T;
}

export const useEstate = () => useQuery({ queryKey: ["estate"], queryFn: () => call<Estate>("/api/estate") });
export const useApp = (id: string | null) =>
  useQuery({ queryKey: ["app", id], enabled: id !== null, queryFn: () => call<AppDetail>(`/api/apps/${id}`) });
export const useFindings = () => useQuery({ queryKey: ["findings"], queryFn: () => call<Finding[]>("/api/findings") });
export const useReport = () => useQuery({ queryKey: ["report"], queryFn: () => call<Report>("/api/report"), staleTime: Infinity });
export const useDraft = () =>
  useMutation({
    mutationFn: (brief: string) =>
      call<Draft>("/estimator/estimates", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ brief }) }),
  });

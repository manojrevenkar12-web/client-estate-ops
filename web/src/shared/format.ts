const day = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
const stamp = new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "UTC" });
export const date = (iso: string) => day.format(new Date(iso));
export const when = (iso: string) => stamp.format(new Date(iso));
export const euros = (n: number) => new Intl.NumberFormat("en-GB", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(n);
export const count = (n: number) => new Intl.NumberFormat("en-GB").format(Math.round(n));

export const laneLabel: Record<string, string> = {
  fast: "Security fix",
  routine: "Weekly updates",
  review: "Major version, reviewed",
  "patch-day": "Patch day",
  hotfix: "Hotfix",
};

export const actionLabel: Record<string, string> = {
  delete: "Delete",
  snapshot_and_delete: "Snapshot, then delete",
  schedule: "Stop nights and weekends",
  downsize: "One size down",
  keep: "Keep",
};

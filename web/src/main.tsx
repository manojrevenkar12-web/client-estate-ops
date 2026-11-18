import "@fontsource/instrument-sans/400.css";
import "@fontsource/instrument-sans/600.css";
import "./theme/tokens.css";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./app/App";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } })}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
);

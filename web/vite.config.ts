import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import tailwind from "@tailwindcss/vite";

export default defineConfig({
  base: "./",
  plugins: [react(), tailwind()],
  server: {
    proxy: {
      "/api": "http://localhost:8080",
      "/estimator": { target: "http://localhost:8090", rewrite: (p) => p.replace(/^\/estimator/, "") },
    },
  },
  test: { environment: "jsdom", setupFiles: ["./src/testing/setup.ts"], globals: true },
});

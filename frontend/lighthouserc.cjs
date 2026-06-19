/** @type {import('@lhci/types').LighthouseCI} */
module.exports = {
  ci: {
    collect: {
      startServerCommand: "next start --port 3000",
      url: [
        "http://localhost:3000",
        "http://localhost:3000/products",
        "http://localhost:3000/en",
      ],
      numberOfRuns: 2,
    },
    assert: {
      preset: "lighthouse:no-pwa",
      assertions: {
        "categories:performance": ["warn", { minScore: 0.8 }],
        "categories:accessibility": ["error", { minScore: 0.9 }],
        "categories:seo": ["error", { minScore: 0.9 }],
        "categories:best-practices": ["warn", { minScore: 0.9 }],
      },
    },
    upload: {
      target: "temporary-public-storage",
    },
  },
};

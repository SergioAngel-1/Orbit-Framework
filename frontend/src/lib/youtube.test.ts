import { describe, expect, it } from "vitest";
import { extractYoutubeId, youtubeEmbedUrl } from "./youtube";

describe("extractYoutubeId", () => {
  it("parsea watch, youtu.be, shorts y embed", () => {
    expect(extractYoutubeId("https://www.youtube.com/watch?v=dQw4w9WgXcQ")).toBe(
      "dQw4w9WgXcQ",
    );
    expect(extractYoutubeId("https://youtu.be/dQw4w9WgXcQ")).toBe("dQw4w9WgXcQ");
    expect(extractYoutubeId("https://www.youtube.com/shorts/dQw4w9WgXcQ")).toBe(
      "dQw4w9WgXcQ",
    );
    expect(extractYoutubeId("https://www.youtube.com/embed/dQw4w9WgXcQ")).toBe(
      "dQw4w9WgXcQ",
    );
  });

  it("rechaza entradas inválidas", () => {
    expect(extractYoutubeId("")).toBeNull();
    expect(extractYoutubeId("https://example.com/watch?v=dQw4w9WgXcQ")).toBeNull();
    expect(extractYoutubeId("https://www.youtube.com/watch?v=corto")).toBeNull();
  });
});

describe("youtubeEmbedUrl", () => {
  it("arma nocookie embed", () => {
    expect(youtubeEmbedUrl("https://youtu.be/dQw4w9WgXcQ")).toBe(
      "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    );
  });
});

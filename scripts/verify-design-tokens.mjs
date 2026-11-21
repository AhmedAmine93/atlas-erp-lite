import fs from "node:fs";
import assert from "node:assert/strict";

const data = JSON.parse(fs.readFileSync("design-system/tokens.v1.json", "utf8"));
const css = fs.readFileSync("frontend/src/app/design-tokens.css", "utf8");
assert.equal(data.version, 1);
const sections = css.match(/:root(?:\[data-atlas-theme="dark"\])?\s*\{[^}]+\}/g);
assert.equal(sections?.length, 2, "light and dark theme blocks required");
for (const [index, theme] of [data.light, data.dark].entries()) {
  const section = sections[index];
  for (const [name, value] of Object.entries(theme)) {
    const key = name.replace(/[A-Z]/g, match => "-" + match.toLowerCase());
    assert.ok(section.includes(`--atlas-color-${key}: ${value};`),
      `token missing: ${index === 0 ? "light" : "dark"}/${name}`);
  }
}
for (const [name,value] of Object.entries(data.space)) {
  assert.ok(css.includes(`--atlas-space-${name}: ${value}px;`));
}
for (const [name,value] of Object.entries(data.radius)) {
  assert.ok(css.includes(`--atlas-radius-${name}: ${value}px;`));
}
for (const [name,value] of Object.entries(data.font)) {
  assert.ok(css.includes(`--atlas-font-${name}: "${value}", Arial, sans-serif;`));
}
console.log("P065: semantic token contract valid for light and dark themes");

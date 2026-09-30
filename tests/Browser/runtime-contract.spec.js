const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

test('Inspecting publishes both CLI runtime entrypoints', async () => {
  const root = path.resolve(__dirname, '..', '..');

  expect(fs.existsSync(path.join(root, 'bin', 'inspecting'))).toBeTruthy();
  expect(fs.existsSync(path.join(root, 'bin', 'console'))).toBeTruthy();
});

import { execFileSync, spawnSync } from 'node:child_process';

const env = process.env;
const isPullRequest = env.GITHUB_EVENT_NAME === 'pull_request';
const base = isPullRequest
  ? `origin/${env.GITHUB_BASE_REF || 'main'}...HEAD`
  : env.GITHUB_ACTIONS === 'true'
    ? 'HEAD^...HEAD'
    : 'origin/main...HEAD';

let output = '';
try {
  output = execFileSync(
    'git',
    ['diff', '--name-only', '--diff-filter=ACMR', base, '--', 'resources/js'],
    { encoding: 'utf8' },
  );
} catch (error) {
  console.error(`Unable to resolve changed frontend files from ${base}.`);
  throw error;
}

const files = output
  .split(/\r?\n/u)
  .map((file) => file.trim())
  .filter((file) => /\.(?:js|jsx)$/u.test(file));

if (files.length === 0) {
  console.log(`No changed JS/JSX files to lint (${base}).`);
  process.exit(0);
}

console.log(`Linting ${files.length} changed JS/JSX file(s) from ${base}.`);
const executable = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const result = spawnSync(executable, ['eslint', ...files, '--max-warnings=0'], {
  stdio: 'inherit',
});

if (result.error) {
  throw result.error;
}

process.exit(result.status ?? 1);

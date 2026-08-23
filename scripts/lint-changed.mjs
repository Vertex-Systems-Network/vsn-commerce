import { execFileSync, spawnSync } from 'node:child_process';
import path from 'node:path';

const env = process.env;
const isPullRequest = env.GITHUB_EVENT_NAME === 'pull_request';
const base = isPullRequest
  ? `origin/${env.GITHUB_BASE_REF || 'main'}...HEAD`
  : env.GITHUB_ACTIONS === 'true'
    ? 'HEAD^...HEAD'
    : 'origin/main...HEAD';

/** Returns the added/modified line ranges for each frontend file in the current change. */
function changedRanges(diff) {
  const ranges = new Map();
  let file = null;

  for (const line of diff.split(/\r?\n/u)) {
    if (line.startsWith('+++ b/')) {
      file = line.slice(6);
      if (/\.(?:js|jsx)$/u.test(file) && !ranges.has(file)) ranges.set(file, []);
      continue;
    }

    if (!file || !ranges.has(file) || !line.startsWith('@@')) continue;
    const match = line.match(/\+(\d+)(?:,(\d+))?/u);
    if (!match) continue;
    const start = Number(match[1]);
    const count = match[2] === undefined ? 1 : Number(match[2]);
    if (count > 0) ranges.get(file).push([start, start + count - 1]);
  }

  return ranges;
}

let diff = '';
try {
  diff = execFileSync(
    'git',
    ['diff', '--unified=0', '--diff-filter=ACMR', base, '--', 'resources/js'],
    { encoding: 'utf8' },
  );
} catch (error) {
  console.error(`Unable to resolve changed frontend lines from ${base}.`);
  throw error;
}

const ranges = changedRanges(diff);
const files = [...ranges.keys()];

if (files.length === 0) {
  console.log(`No changed JS/JSX lines to lint (${base}).`);
  process.exit(0);
}

console.log(`Linting changed lines in ${files.length} JS/JSX file(s) from ${base}.`);
const executable = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const result = spawnSync(executable, ['eslint', ...files, '--format', 'json'], {
  cwd: process.cwd(),
  encoding: 'utf8',
  maxBuffer: 16 * 1024 * 1024,
});

if (result.error) throw result.error;

let reports;
try {
  reports = JSON.parse(result.stdout || '[]');
} catch (error) {
  process.stderr.write(result.stderr || '');
  process.stdout.write(result.stdout || '');
  throw new Error(`Unable to parse ESLint JSON output: ${error.message}`);
}

const blocking = [];
let baselineCount = 0;
const cwd = process.cwd();

for (const report of reports) {
  const relative = path.relative(cwd, report.filePath).split(path.sep).join('/');
  const fileRanges = ranges.get(relative) || [];

  for (const message of report.messages || []) {
    const start = Number(message.line || 0);
    const end = Number(message.endLine || start || 0);
    const touchesChangedLine = start === 0 || fileRanges.some(([from, to]) => end >= from && start <= to);

    if (touchesChangedLine) blocking.push({ file: relative, ...message });
    else baselineCount += 1;
  }
}

if (baselineCount > 0) {
  console.log(`Ignored ${baselineCount} pre-existing lint finding(s) on untouched lines; full lint remains available via npm run lint.`);
}

if (blocking.length === 0) {
  console.log('Changed-line React, hooks and accessibility lint passed.');
  process.exit(0);
}

for (const issue of blocking) {
  const level = issue.severity === 2 ? 'error' : 'warning';
  console.error(`${issue.file}:${issue.line}:${issue.column} ${level} ${issue.message} ${issue.ruleId || ''}`.trim());
}

console.error(`Changed-line lint failed with ${blocking.length} finding(s).`);
process.exit(1);

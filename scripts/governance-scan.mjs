import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, extname } from 'node:path';

const output = process.env.GOVERNANCE_SARIF || 'runtime-artifacts/governance.sarif';
const maxBytes = 2 * 1024 * 1024;
const binaryExtensions = new Set([
  '.7z', '.avi', '.bin', '.bmp', '.class', '.db', '.dll', '.doc', '.docx', '.eot', '.exe', '.gif',
  '.gz', '.ico', '.jar', '.jpeg', '.jpg', '.lockb', '.mov', '.mp3', '.mp4', '.pdf', '.png', '.rar',
  '.sqlite', '.sqlite3', '.tar', '.ttf', '.webm', '.webp', '.woff', '.woff2', '.xls', '.xlsx', '.zip',
]);

const rules = [
  {
    id: 'committed-private-key',
    name: 'CommittedPrivateKey',
    shortDescription: 'Private key material must not be committed.',
    securitySeverity: '9.8',
    pattern: /-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/g,
  },
  {
    id: 'github-access-token',
    name: 'GitHubAccessToken',
    shortDescription: 'GitHub access tokens must not be committed.',
    securitySeverity: '9.8',
    pattern: /\bgh[pousr]_[A-Za-z0-9_]{30,}\b/g,
  },
  {
    id: 'aws-access-key',
    name: 'AwsAccessKey',
    shortDescription: 'AWS access key IDs must not be committed.',
    securitySeverity: '9.0',
    pattern: /\bAKIA[0-9A-Z]{16}\b/g,
  },
];

const sarifRules = rules.map((rule) => ({
  id: rule.id,
  name: rule.name,
  shortDescription: { text: rule.shortDescription },
  properties: {
    tags: ['security', 'governance'],
    'security-severity': rule.securitySeverity,
  },
}));

sarifRules.push({
  id: 'unsafe-pull-request-target-checkout',
  name: 'UnsafePullRequestTargetCheckout',
  shortDescription: { text: 'pull_request_target workflows must not check out untrusted pull-request code.' },
  properties: {
    tags: ['security', 'governance', 'github-actions'],
    'security-severity': '9.0',
  },
});

const results = [];
const tracked = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' })
  .split('\0')
  .filter(Boolean);

function lineAt(text, index) {
  return text.slice(0, index).split('\n').length;
}

function addResult(ruleId, message, file, line) {
  results.push({
    ruleId,
    level: 'error',
    message: { text: message },
    locations: [{
      physicalLocation: {
        artifactLocation: { uri: file },
        region: { startLine: Math.max(1, line) },
      },
    }],
  });
}

for (const file of tracked) {
  if (binaryExtensions.has(extname(file).toLowerCase())) continue;
  if (file === '.env.example') continue;

  let buffer;
  try {
    buffer = readFileSync(file);
  } catch {
    continue;
  }
  if (buffer.length > maxBytes || buffer.includes(0)) continue;

  const text = buffer.toString('utf8');
  for (const rule of rules) {
    rule.pattern.lastIndex = 0;
    for (const match of text.matchAll(rule.pattern)) {
      addResult(rule.id, rule.shortDescription, file, lineAt(text, match.index ?? 0));
    }
  }

  if (file.startsWith('.github/workflows/') && /(?:^|\n)\s*pull_request_target\s*:/m.test(text)) {
    const checkout = /uses:\s*actions\/checkout@/m.exec(text);
    if (checkout) {
      addResult(
        'unsafe-pull-request-target-checkout',
        'This workflow combines pull_request_target with actions/checkout. Review it to ensure untrusted PR code cannot execute with privileged permissions.',
        file,
        lineAt(text, checkout.index),
      );
    }
  }
}

const sarif = {
  version: '2.1.0',
  $schema: 'https://json.schemastore.org/sarif-2.1.0.json',
  runs: [{
    tool: {
      driver: {
        name: 'governance',
        informationUri: 'https://github.com/Vertex-Systems-Network/vsn-commerce',
        rules: sarifRules,
      },
    },
    results,
  }],
};

mkdirSync(dirname(output), { recursive: true });
writeFileSync(output, `${JSON.stringify(sarif, null, 2)}\n`);
console.log(`Governance scan completed: ${results.length} blocking finding(s).`);

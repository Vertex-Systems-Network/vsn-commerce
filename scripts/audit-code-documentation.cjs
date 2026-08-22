'use strict';
const fs = require('fs');
const path = require('path');
const ts = require('/opt/nvm/versions/node/v22.16.0/lib/node_modules/typescript');

/** Recursively returns JavaScript and TypeScript source files under a directory. */
function walk(directory) {
  let files = [];
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) files = files.concat(walk(file));
    else if (/\.(js|jsx|ts|tsx)$/.test(entry.name)) files.push(file);
  }
  return files;
}

let total = 0;
const missing = [];
const sourceFiles = [...walk('resources/js'), ...walk('e2e'), ...walk('load'), ...walk('scripts'), ...['playwright.config.js','vite.config.js'].filter(fs.existsSync)];
for (const file of [...new Set(sourceFiles)]) {
  const text = fs.readFileSync(file, 'utf8');
  const kind = file.endsWith('.jsx') ? ts.ScriptKind.JSX : file.endsWith('.tsx') ? ts.ScriptKind.TSX : file.endsWith('.ts') ? ts.ScriptKind.TS : ts.ScriptKind.JS;
  const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, kind);

  /** Returns true when TypeScript reports a leading JSDoc comment for the node. */
  function hasLeadingDoc(node) {
    const ranges = ts.getLeadingCommentRanges(text, node.getFullStart()) || [];
    return ranges.some(/** Inline callback for this operation. */ (range) => text.slice(range.pos, range.end).startsWith('/**'));
  }

  /** Visits every JavaScript class/function declaration or expression and records documentation failures. */
  function visit(node) {
    let label = null;
    let documented = true;
    if (ts.isClassDeclaration(node)) {
      label = node.name?.text || '<anonymous class>';
      documented = hasLeadingDoc(node);
    } else if (ts.isClassExpression(node)) {
      label = node.name?.text || '<anonymous class expression>';
      const before = text.slice(Math.max(0, node.getStart(source) - 160), node.getStart(source));
      documented = /\/\*\*[^]*?\*\/\s*$/.test(before);
    } else if (ts.isFunctionDeclaration(node)) {
      label = node.name?.text || '<anonymous function>';
      documented = hasLeadingDoc(node);
    } else if (ts.isMethodDeclaration(node)) {
      label = node.name?.getText(source) || '<method>';
      documented = hasLeadingDoc(node);
    } else if (ts.isArrowFunction(node) || ts.isFunctionExpression(node)) {
      const parent = node.parent;
      label = ts.isVariableDeclaration(parent) && ts.isIdentifier(parent.name) ? parent.name.text : '<inline callback>';
      const before = text.slice(Math.max(0, node.getStart(source) - 160), node.getStart(source));
      documented = /\/\*\*[^]*?\*\/\s*$/.test(before);
    }
    if (label) {
      total++;
      if (!documented) {
        const line = source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;
        missing.push(`${file}:${line} ${label}`);
      }
    }
    ts.forEachChild(node, visit);
  }
  visit(source);
}

for (const failure of missing) console.log(`[FAIL] ${failure}`);
console.log(`JS documented declarations: ${total - missing.length}/${total}`);
console.log(`JS documentation failures: ${missing.length}`);
process.exit(missing.length === 0 ? 0 : 1);

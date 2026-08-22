import js from '@eslint/js';
import globals from 'globals';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';

export default [
  {
    ignores: [
      'node_modules/**',
      'public/build/**',
      'runtime-artifacts/**',
      'playwright-report/**',
    ],
  },
  {
    ...js.configs.recommended,
    files: ['resources/js/**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      parserOptions: {
        ecmaFeatures: {
          jsx: true,
        },
      },
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
    },
    plugins: {
      'jsx-a11y': jsxA11y,
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...js.configs.recommended.rules,
      ...jsxA11y.configs.recommended.rules,
      ...reactHooks.configs.flat.recommended.rules,
      'react-refresh/only-export-components': ['error', { allowConstantExport: true }],
    },
  },
];

import React from 'react';
import ReactDOM from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import App from './App';
import {CartProvider} from './platform/cart';
import {AuthProvider} from './platform/auth';
import {safeLocalRedirect} from './platform/navigation';
import {UXProvider} from './components/UXProvider';
import './styles.scss';
import './accessibility.scss';

const AUTH_REDIRECT_PATHS = new Set(['/auth', '/login', '/register', '/auth/callback']);
if (AUTH_REDIRECT_PATHS.has(window.location.pathname)) {
  const current = new URL(window.location.href);
  const requestedNext = current.searchParams.get('next');
  if (requestedNext !== null && safeLocalRedirect(requestedNext) === null) {
    current.searchParams.delete('next');
    window.history.replaceState(
      window.history.state,
      '',
      `${current.pathname}${current.search}${current.hash}`,
    );
  }
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter>
      <UXProvider>
      <AuthProvider>
        <CartProvider>
          <App/>
        </CartProvider>
      </AuthProvider>
      </UXProvider>
    </BrowserRouter>
  </React.StrictMode>
);
